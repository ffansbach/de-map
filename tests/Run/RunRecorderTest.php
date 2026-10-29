<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use DateTimeImmutable;
use FreifunkKarte\Run\DriftDetector;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RunListener;
use FreifunkKarte\Run\RunRecorder;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Run\RunSummary;
use FreifunkKarte\Tests\Legacy\StandardWorld;
use FreifunkKarte\Tests\Legacy\World;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class RunRecorderTest extends TestCase
{
    private RunRepository $repository;
    private TestHandler $logs;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $calls;

    protected function setUp(): void
    {
        $this->repository = RunRepository::inMemory();
        $this->logs = new TestHandler();
        $this->calls = new \ArrayObject();
    }

    private function recorder(?RunListener ...$listeners): RunRecorder
    {
        return new RunRecorder($this->repository, new DriftDetector(), new Logger('test', [$this->logs]), array_values(array_filter($listeners)));
    }

    private function listener(bool $throws = false): RunListener
    {
        return new class ($this->calls, $throws) implements RunListener {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private readonly \ArrayObject $calls, private readonly bool $throws)
            {
            }

            public function started(int $runId, DateTimeImmutable $at): void
            {
                $this->calls->append('started:' . $runId);

                if ($this->throws) {
                    throw new \RuntimeException('listener broke on start');
                }
            }

            public function finished(RunSummary $summary): void
            {
                $this->calls->append('finished:' . $summary->status . ':' . count($summary->events));

                if ($this->throws) {
                    throw new \RuntimeException('listener broke on finish');
                }
            }
        };
    }

    /**
     * The reference world after a bad day: hop-town's data is gone and a community uses a new type.
     */
    private function worseWorld(): World
    {
        return StandardWorld::build()
            ->route('https://hop.test/a/nodes.json', 'not json')
            ->route('https://hop.test/b/nodes.json', 'not json')
            ->community('newcomer', ['nodeMaps' => [['technicalType' => 'yanic', 'url' => 'https://newcomer.test/map/']]]);
    }

    public function testTheFirstRunIsStoredAndLoggedWithoutEvents(): void
    {
        $recorder = $this->recorder($this->listener());

        $runId = $recorder->begin('/out');
        $summary = $recorder->complete($runId, RunFixtures::standard());

        $this->assertSame(RunRepository::OK, $summary->status);
        $this->assertSame([], $summary->events);
        $this->assertNull($summary->worstSeverity());
        $this->assertSame(40, $this->repository->run($runId)['nodes'] ?? null);
        $this->assertSame(['started:1', 'finished:ok:0'], $this->calls->getArrayCopy());
        $this->assertTrue($this->logs->hasInfoThatContains('run started'));
        $this->assertTrue($this->logs->hasInfoThatContains('run finished'));
    }

    public function testASecondRunIsComparedWithTheFirst(): void
    {
        $recorder = $this->recorder($this->listener());
        $recorder->complete($recorder->begin('/out'), RunFixtures::standard());

        $second = $recorder->begin('/out');
        $summary = $recorder->complete($second, RunFixtures::standard($this->worseWorld()));

        $kinds = array_map(static fn (DriftEvent $e): string => $e->kind . ':' . $e->subject, $summary->events);
        $this->assertContains('new-technical-type:yanic', $kinds);
        $this->assertContains('community-lost:hop-town', $kinds);
        $this->assertContains('community-added:newcomer', $kinds);
        $this->assertSame(DriftEvent::WARNING, $summary->worstSeverity());

        // stored, logged at the level of their severity, and given to the listener
        $this->assertCount(count($summary->events), $this->repository->events($second));
        $this->assertTrue($this->logs->hasWarningThatContains('Technical type "yanic" has not been seen before'));
        $this->assertTrue($this->logs->hasInfoThatContains('New community'));
        $this->assertContains('finished:ok:' . count($summary->events), $this->calls->getArrayCopy());
    }

    public function testARefusedResultIsKeptInTheHistoryAndRaisesAnAlert(): void
    {
        $recorder = $this->recorder($this->listener());
        $runId = $recorder->begin('/out');

        $summary = $recorder->complete($runId, RunFixtures::standard(), refused: true);

        $this->assertSame(RunRepository::REFUSED, $summary->status);
        $this->assertSame(RunRepository::REFUSED, $this->repository->run($runId)['status'] ?? null);
        $this->assertSame('run-refused', $summary->events[0]->kind);
        $this->assertSame(DriftEvent::ALERT, $summary->worstSeverity());
        $this->assertTrue($this->logs->hasCriticalThatContains('implausible'));
    }

    public function testNotBeingAbleToDeliverTheResultIsAnAlertButTheRunIsStillOk(): void
    {
        $recorder = $this->recorder($this->listener());
        $runId = $recorder->begin('/out');

        $summary = $recorder->complete($runId, RunFixtures::standard(), deliveryError: 'FTP login failed');

        $this->assertSame(RunRepository::OK, $summary->status, 'the data was collected');
        $this->assertSame(['delivery-failed'], array_map(static fn (DriftEvent $e): string => $e->kind, $summary->events));
        $this->assertSame(DriftEvent::ALERT, $summary->worstSeverity());
        $this->assertSame(['delivery-failed'], array_column($this->repository->events($runId), 'kind'));
        $this->assertTrue($this->logs->hasCriticalThatContains('could not be delivered to the web server: FTP login failed'));
    }

    public function testAFailedRunIsRecordedWithTheError(): void
    {
        $recorder = $this->recorder($this->listener());
        $runId = $recorder->begin('/out');

        $summary = $recorder->fail($runId, new \RuntimeException('Community directory not available'));

        $this->assertSame(RunRepository::FAILED, $summary->status);
        $this->assertNull($summary->result);
        $this->assertSame('Community directory not available', $summary->error);
        $this->assertStringContainsString('Community directory not available', (string) ($this->repository->run($runId)['error'] ?? ''));
        $this->assertSame(['run-failed'], array_column($this->repository->events($runId), 'kind'));
        $this->assertTrue($this->logs->hasCriticalThatContains('run failed'));
        $this->assertContains('finished:failed:1', $this->calls->getArrayCopy());
    }

    public function testListenersThatFailDoNotBreakTheRun(): void
    {
        $recorder = $this->recorder($this->listener(throws: true), $this->listener());

        $runId = $recorder->begin('/out');
        $summary = $recorder->complete($runId, RunFixtures::standard());

        $this->assertSame(RunRepository::OK, $summary->status);
        $this->assertTrue($this->logs->hasErrorThatContains('run listener failed'));
        $this->assertContains('finished:ok:0', $this->calls->getArrayCopy(), 'the next listener still hears about it');
    }

    public function testSummaryHelpers(): void
    {
        $events = [
            new DriftEvent('a', DriftEvent::INFO, 's', 'i'),
            new DriftEvent('b', DriftEvent::WARNING, 's', 'w'),
        ];
        $summary = new RunSummary(1, RunRepository::OK, null, $events, null, new DateTimeImmutable(), 0);

        $this->assertSame(DriftEvent::WARNING, $summary->worstSeverity());
        $this->assertCount(1, $summary->eventsFrom(DriftEvent::WARNING));
        $this->assertCount(2, $summary->eventsFrom(DriftEvent::INFO));
        $this->assertSame([], $summary->eventsFrom(DriftEvent::ALERT));
    }
}
