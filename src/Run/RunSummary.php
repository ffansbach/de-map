<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

use DateTimeImmutable;
use FreifunkKarte\Pipeline\RunResult;

/**
 * How a run ended, as the listeners (log, healthchecks.io, InfluxDB) see it.
 */
final readonly class RunSummary
{
    /**
     * @param string $status one of the RunRepository status constants
     * @param list<DriftEvent> $events
     */
    public function __construct(
        public int $runId,
        public string $status,
        public ?RunResult $result,
        public array $events,
        public ?string $error,
        public DateTimeImmutable $finishedAt,
        public int $peakMemoryBytes,
    ) {
    }

    public function worstSeverity(): ?string
    {
        $severities = array_map(static fn (DriftEvent $e): string => $e->severity, $this->events);

        return match (true) {
            in_array(DriftEvent::ALERT, $severities, true) => DriftEvent::ALERT,
            in_array(DriftEvent::WARNING, $severities, true) => DriftEvent::WARNING,
            $severities !== [] => DriftEvent::INFO,
            default => null,
        };
    }

    /**
     * @return list<DriftEvent> the events of at least the given severity
     */
    public function eventsFrom(string $minimum): array
    {
        $rank = [DriftEvent::INFO => 0, DriftEvent::WARNING => 1, DriftEvent::ALERT => 2];

        return array_values(array_filter($this->events, static fn (DriftEvent $e): bool => $rank[$e->severity] >= ($rank[$minimum] ?? 0)));
    }
}
