<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

use Closure;
use DateTimeImmutable;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\RunResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the history of a run: stores it, compares it with the one before, logs what is odd and
 * tells the listeners. The run itself does not depend on any of it.
 */
final class RunRecorder
{
    /**
     * @param list<RunListener> $listeners
     */
    public function __construct(
        private readonly RunRepository $repository,
        private readonly DriftDetector $detector,
        private readonly LoggerInterface $logger,
        private readonly array $listeners = [],
        private readonly ?Closure $clock = null,
    ) {
    }

    public function begin(string $outDir): int
    {
        $at = $this->now();
        $runId = $this->repository->start($at, $outDir);
        $this->logger->info('run started', ['run' => $runId, 'out' => $outDir]);
        $this->notify(static fn (RunListener $l) => $l->started($runId, $at));

        return $runId;
    }

    /**
     * @param bool $refused the result was too small to be written; it is kept in the history anyway
     * @param string|null $deliveryError why the result files could not be delivered to the web server, if they could not
     * @param list<string> $deliveryWarnings deliveries that failed without stopping the delivery (a second server, the statistics ping)
     */
    public function complete(int $runId, RunResult $result, bool $refused = false, ?string $deliveryError = null, array $deliveryWarnings = []): RunSummary
    {
        $finishedAt = $this->now();
        $this->repository->finish($runId, $result, $refused ? RunRepository::REFUSED : RunRepository::OK, $finishedAt);

        $previousId = $this->repository->previousRun($runId);
        $previousNodes = $previousId !== null ? ($this->repository->run($previousId)['nodes'] ?? null) : null;

        $events = $this->detector->detect(
            $result->communities,
            $previousId !== null ? $this->repository->communities($previousId) : [],
            $this->repository->knownTypes($runId),
            $result->nodes->count(),
            is_int($previousNodes) ? $previousNodes : null,
        );

        if ($refused) {
            array_unshift($events, new DriftEvent(
                DriftEvent::RUN_REFUSED,
                DriftEvent::ALERT,
                'run',
                sprintf('The result looks implausible (%d communities, %d nodes): the result files were not written', count($result->listedCommunities()), $result->nodes->count()),
            ));
        }

        if ($deliveryError !== null) {
            $events[] = new DriftEvent(
                DriftEvent::DELIVERY_FAILED,
                DriftEvent::ALERT,
                'run',
                'The result could not be delivered to the web server: ' . $deliveryError,
            );
        }

        foreach ($deliveryWarnings as $warning) {
            $events[] = new DriftEvent(DriftEvent::DELIVERY_PARTIAL, DriftEvent::WARNING, 'run', 'Part of the delivery failed: ' . $warning);
        }

        $this->repository->addEvents($runId, $events, $finishedAt);

        $summary = new RunSummary($runId, $refused ? RunRepository::REFUSED : RunRepository::OK, $result, $events, null, $finishedAt, memory_get_peak_usage(true));
        $this->log($summary);
        $this->notify(static fn (RunListener $l) => $l->finished($summary));

        return $summary;
    }

    public function fail(int $runId, Throwable $error): RunSummary
    {
        $finishedAt = $this->now();
        $event = new DriftEvent(DriftEvent::RUN_FAILED, DriftEvent::ALERT, 'run', 'The run failed: ' . $error->getMessage(), ['exception' => $error::class]);

        $this->repository->fail($runId, $error::class . ': ' . $error->getMessage(), $finishedAt);
        $this->repository->addEvents($runId, [$event], $finishedAt);

        $summary = new RunSummary($runId, RunRepository::FAILED, null, [$event], $error->getMessage(), $finishedAt, memory_get_peak_usage(true));
        $this->logger->critical('run failed', ['run' => $runId, 'error' => $error->getMessage(), 'exception' => $error::class]);
        $this->notify(static fn (RunListener $l) => $l->finished($summary));

        return $summary;
    }

    private function log(RunSummary $summary): void
    {
        $result = $summary->result;

        $this->logger->info('run finished', [
            'run' => $summary->runId,
            'status' => $summary->status,
            'nodes' => $result?->nodes->count(),
            'communities' => $result !== null ? count($result->listedCommunities()) : null,
            'with_nodes' => $result !== null ? count(array_filter($result->communities, static fn (CommunityReport $c): bool => $c->outcome === CommunityReport::OK)) : null,
            'requests' => $result?->requests,
            'seconds' => $result !== null ? round($result->seconds, 1) : null,
            'events' => count($summary->events),
        ]);

        foreach ($summary->events as $event) {
            $context = ['run' => $summary->runId, 'kind' => $event->kind, 'subject' => $event->subject] + $event->details;

            match ($event->severity) {
                DriftEvent::ALERT => $this->logger->critical($event->message, $context),
                DriftEvent::WARNING => $this->logger->warning($event->message, $context),
                default => $this->logger->info($event->message, $context),
            };
        }
    }

    /**
     * @param callable(RunListener): mixed $call
     */
    private function notify(callable $call): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $call($listener);
            } catch (Throwable $e) {
                $this->logger->error('run listener failed', ['listener' => $listener::class, 'error' => $e->getMessage()]);
            }
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
    }
}
