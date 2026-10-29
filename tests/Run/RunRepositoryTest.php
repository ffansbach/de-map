<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use DateTimeImmutable;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RunRepository;
use PHPUnit\Framework\TestCase;

final class RunRepositoryTest extends TestCase
{
    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    private function finishedRun(RunRepository $repository, string $time = '2026-10-29T12:00:00Z', string $status = RunRepository::OK): int
    {
        $runId = $repository->start(self::at($time), '/out');
        $repository->finish($runId, RunFixtures::standard(), $status, self::at($time));

        return $runId;
    }

    public function testRunSummaryIsStored(): void
    {
        $repository = RunRepository::inMemory();
        $runId = $this->finishedRun($repository);

        $run = $repository->run($runId);

        $this->assertNotNull($run);
        $this->assertSame(RunRepository::OK, $run['status']);
        $this->assertSame(40, $run['nodes']);
        $this->assertSame(13, $run['communities']);
        $this->assertSame(10, $run['with_nodes'], 'communities whose source delivered nodes, also if another community kept them');
        $this->assertSame('/out', $run['out_dir']);
        $this->assertGreaterThan(0, $run['requests']);
        $this->assertNull($run['error']);
    }

    public function testRunInProgressIsMarkedAsSuch(): void
    {
        $repository = RunRepository::inMemory();
        $runId = $repository->start(self::at('2026-10-29T12:00:00Z'), '/out');

        $this->assertSame(RunRepository::RUNNING, $repository->run($runId)['status'] ?? null);
        $this->assertNull($repository->run(999));
    }

    public function testCommunitiesComeBackAsTheyWere(): void
    {
        $repository = RunRepository::inMemory();
        $communities = $repository->communities($this->finishedRun($repository));

        $this->assertCount(16, $communities);
        $nl = $communities['nl-city'];
        $this->assertSame(['ok', 'nodelist', true], [$nl->outcome, $nl->format, $nl->listed]);
        $this->assertSame(4, $nl->added);
        $this->assertSame(['nodelist', 'meshviewer'], $nl->declaredTypes);
        $this->assertSame(['https://nl.test/nodelist.json'], $nl->sourceUrls);

        $second = $communities['dup-b'];
        $this->assertSame([0, 4, 4], [$second->added, $second->duplicates, $second->accepted()]);

        $this->assertSame('no-data', $communities['dead-town']->outcome);
        $this->assertSame('skipped', $communities['skipped-town']->outcome);
        $this->assertFalse($communities['skipped-town']->listed);
    }

    public function testEveryFetchIsStored(): void
    {
        $repository = RunRepository::inMemory();
        $fetches = $repository->fetches($this->finishedRun($repository));
        $byUrl = array_column($fetches, null, 'url');

        $this->assertSame(200, $byUrl['https://nl.test/nodelist.json']['status']);
        $this->assertSame('nodelist', $byUrl['https://nl.test/nodelist.json']['format']);
        $this->assertSame(404, $byUrl['https://dead.test/map/nodes.json']['status']);
        $this->assertArrayHasKey('https://directory.test/directory.json', $byUrl);
    }

    public function testPreviousRunSkipsFailedAndRunningOnes(): void
    {
        $repository = RunRepository::inMemory();
        $good = $this->finishedRun($repository, '2026-10-27T12:00:00Z');
        $failed = $repository->start(self::at('2026-10-28T12:00:00Z'), '/out');
        $repository->fail($failed, 'directory not available', self::at('2026-10-28T12:01:00Z'));
        $current = $repository->start(self::at('2026-10-29T12:00:00Z'), '/out');

        $this->assertNull($repository->previousRun($good));
        $this->assertSame($good, $repository->previousRun($current));
        $this->assertSame('directory not available', $repository->run($failed)['error'] ?? null);
        $this->assertSame(RunRepository::FAILED, $repository->run($failed)['status'] ?? null);
    }

    public function testRefusedRunIsKeptButNotUsedAsComparison(): void
    {
        $repository = RunRepository::inMemory();
        $refused = $this->finishedRun($repository, '2026-10-28T12:00:00Z', RunRepository::REFUSED);
        $current = $repository->start(self::at('2026-10-29T12:00:00Z'), '/out');

        $this->assertNull($repository->previousRun($current));
        $this->assertSame(RunRepository::REFUSED, $repository->run($refused)['status'] ?? null);
    }

    public function testKnownTypesComeOnlyFromEarlierRuns(): void
    {
        $repository = RunRepository::inMemory();
        $first = $this->finishedRun($repository, '2026-10-28T12:00:00Z');
        $second = $this->finishedRun($repository, '2026-10-29T12:00:00Z');

        $this->assertSame([], $repository->knownTypes($first));
        $types = $repository->knownTypes($second);
        sort($types);
        $this->assertSame(['ffmap', 'hopglass', 'meshviewer', 'netmon', 'nodelist'], $types);
    }

    public function testEventsAreListedAlertsFirst(): void
    {
        $repository = RunRepository::inMemory();
        $runId = $this->finishedRun($repository);
        $repository->addEvents($runId, [
            new DriftEvent(DriftEvent::NEW_WARNING, DriftEvent::INFO, 'a', 'info one'),
            new DriftEvent(DriftEvent::RUN_NODES_DROPPED, DriftEvent::ALERT, 'run', 'alert one', ['before' => 100, 'after' => 50]),
            new DriftEvent(DriftEvent::NODES_DROPPED, DriftEvent::WARNING, 'b', 'warning one'),
        ], self::at('2026-10-29T12:00:00Z'));

        $events = $repository->events($runId);

        $this->assertSame(['alert', 'warning', 'info'], array_column($events, 'severity'));
        $this->assertSame('{"before":100,"after":50}', $events[0]['details']);
        $this->assertCount(2, $repository->recentEvents(10, 'warning'));
        $this->assertCount(1, $repository->recentEvents(10, 'alert'));
        $this->assertCount(3, $repository->recentEvents(10));
    }

    public function testCommunityHistory(): void
    {
        $repository = RunRepository::inMemory();
        $this->finishedRun($repository, '2026-10-28T12:00:00Z');
        $this->finishedRun($repository, '2026-10-29T12:00:00Z');

        $history = $repository->communityHistory('nl-city');

        $this->assertCount(2, $history);
        $this->assertSame('2026-10-29T12:00:00+00:00', $history[0]['started_at']);
        $this->assertSame('ok', $history[0]['outcome']);
        $this->assertSame([], $repository->communityHistory('no-such-community'));
    }

    public function testPruneForgetsOldRunsWithEverythingThatBelongsToThem(): void
    {
        $repository = RunRepository::inMemory();
        $old = $this->finishedRun($repository, '2026-07-01T12:00:00Z');
        $repository->addEvents($old, [new DriftEvent(DriftEvent::NEW_WARNING, DriftEvent::INFO, 'a', 'm')], self::at('2026-07-01T12:00:00Z'));
        $recent = $this->finishedRun($repository, '2026-10-28T12:00:00Z');

        $this->assertSame(1, $repository->prune(90, self::at('2026-10-29T12:00:00Z')));

        $this->assertNull($repository->run($old));
        $this->assertSame([], $repository->communities($old));
        $this->assertSame([], $repository->fetches($old));
        $this->assertSame([], $repository->events($old));
        $this->assertNotNull($repository->run($recent));
        $this->assertNotSame([], $repository->communities($recent));
    }

    public function testFileDatabaseKeepsItsContentAndCanBeOpenedAgain(): void
    {
        $directory = sys_get_temp_dir() . '/ffmap_runs_' . bin2hex(random_bytes(6));
        $file = $directory . '/nested/parser.sqlite';

        try {
            $first = RunRepository::open($file);
            $runId = $this->finishedRun($first);
            unset($first);

            $second = RunRepository::open($file);

            $this->assertSame(40, $second->run($runId)['nodes'] ?? null);
            $this->assertCount(16, $second->communities($runId));
        } finally {
            foreach (glob($directory . '/nested/*') ?: [] as $path) {
                unlink($path);
            }

            @rmdir($directory . '/nested');
            @rmdir($directory);
        }
    }
}
