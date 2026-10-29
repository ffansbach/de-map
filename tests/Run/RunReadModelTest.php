<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use DateTimeImmutable;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RunRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The queries the admin UI is built on.
 */
final class RunReadModelTest extends TestCase
{
    private RunRepository $repository;

    protected function setUp(): void
    {
        $this->repository = RunRepository::inMemory();
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    /**
     * Run 1: normal. Run 2: a bad day. Run 3: failed. Run 4: normal again.
     */
    private function history(): void
    {
        $r = $this->repository;
        $first = $r->start(self::at('2026-10-27T12:00:00Z'), '/out');
        $r->finish($first, RunFixtures::standard(), RunRepository::OK, self::at('2026-10-27T12:00:30Z'));

        $second = $r->start(self::at('2026-10-28T12:00:00Z'), '/out');
        $r->finish($second, RunFixtures::worse(), RunRepository::OK, self::at('2026-10-28T12:00:30Z'));
        $r->addEvents($second, [
            new DriftEvent(DriftEvent::NEW_TYPE, DriftEvent::WARNING, 'yanic', 'new type yanic'),
            new DriftEvent(DriftEvent::COMMUNITY_LOST, DriftEvent::WARNING, 'hop-town', 'hop-town lost'),
            new DriftEvent(DriftEvent::COMMUNITY_ADDED, DriftEvent::INFO, 'newcomer', 'newcomer added'),
        ], self::at('2026-10-28T12:00:30Z'));

        $third = $r->start(self::at('2026-10-29T08:00:00Z'), '/out');
        $r->fail($third, 'directory not available', self::at('2026-10-29T08:00:10Z'));
        $r->addEvents($third, [new DriftEvent(DriftEvent::RUN_FAILED, DriftEvent::ALERT, 'run', 'run failed')], self::at('2026-10-29T08:00:10Z'));

        $fourth = $r->start(self::at('2026-10-29T12:00:00Z'), '/out');
        $r->finish($fourth, RunFixtures::standard(), RunRepository::OK, self::at('2026-10-29T12:00:30Z'));
    }

    public function testReadOnlyNeedsAnExistingInitialisedHistory(): void
    {
        $file = sys_get_temp_dir() . '/ffmap_ro_' . bin2hex(random_bytes(6)) . '.sqlite';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('No history at');
            RunRepository::openReadOnly($file);
        } finally {
            $this->assertFileDoesNotExist($file, 'a reader must never create the file');
        }
    }

    public function testReadOnlyRefusesAFileThatIsNoHistory(): void
    {
        $file = sys_get_temp_dir() . '/ffmap_ro_' . bin2hex(random_bytes(6)) . '.sqlite';
        file_put_contents($file, '');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('empty or from an older version');
            RunRepository::openReadOnly($file);
        } finally {
            unlink($file);
        }
    }

    public function testReadOnlyReadsButCannotWrite(): void
    {
        $file = sys_get_temp_dir() . '/ffmap_ro_' . bin2hex(random_bytes(6)) . '.sqlite';

        try {
            $writer = RunRepository::open($file);
            $writer->finish($writer->start(self::at('2026-10-29T12:00:00Z'), '/out'), RunFixtures::standard(), RunRepository::OK, self::at('2026-10-29T12:00:30Z'));
            unset($writer);

            $reader = RunRepository::openReadOnly($file);
            $this->assertSame(1, $reader->runCount());
            $this->assertSame(40, $reader->latestRun()['nodes'] ?? null);

            $this->expectException(\PDOException::class);
            $reader->start(self::at('2026-10-30T12:00:00Z'), '/out');
        } finally {
            foreach (glob($file . '*') ?: [] as $path) {
                unlink($path);
            }
        }
    }

    public function testLatestRunAndPaging(): void
    {
        $this->assertNull($this->repository->latestRun());
        $this->history();

        $this->assertSame(4, $this->repository->runCount());
        $this->assertSame(4, $this->repository->latestRun()['id'] ?? null);
        $this->assertSame([4, 3], array_column($this->repository->runsPage(2, 0), 'id'));
        $this->assertSame([2, 1], array_column($this->repository->runsPage(2, 2), 'id'));
        $this->assertSame([], $this->repository->runsPage(2, 4));
    }

    public function testCommunityRowsCanBeFiltered(): void
    {
        $this->history();

        $all = $this->repository->communityRows(1);
        $this->assertCount(16, $all);
        $keys = array_column($all, 'key');
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'ordered by key');
        $this->assertSame(['nodelist', 'meshviewer'], array_column($all, 'declared_types', 'key')['nl-city']);

        $this->assertEqualsCanonicalizing(['dead-town', 'netmon-old', 'ff_fixed_dead'], array_column($this->repository->communityRows(1, 'no-data'), 'key'));
        $this->assertCount(2, $this->repository->communityRows(1, 'ok', 'fixed'));
        $this->assertSame([], $this->repository->communityRows(1, 'no-such-outcome'));
    }

    public function testCommunityDetailHasEverythingThatWasKept(): void
    {
        $this->history();

        $dead = $this->repository->communityDetail(1, 'dead-town');

        $this->assertNotNull($dead);
        $this->assertSame('no-data', $dead['outcome']);
        $this->assertSame(['ffmap'], $dead['declared_types']);
        $this->assertSame('https://dead.test/map/', $dead['attempts'][0]['url']);
        $this->assertSame(404, $dead['attempts'][0]['status']);
        $this->assertContains('no data found for https://dead.test/map/ (ffmap)', $dead['messages']);

        $this->assertSame(['https://nl.test/nodelist.json'], $this->repository->communityDetail(1, 'nl-city')['source_urls'] ?? null);
        $this->assertNull($this->repository->communityDetail(1, 'no-such-community'));
        $this->assertNull($this->repository->communityDetail(99, 'nl-city'));
    }

    public function testLastRunInWhichACommunityDelivered(): void
    {
        $this->history();
        $last = $this->repository->lastOkRuns();

        $this->assertSame(4, $last['hop-town']['run_id'], 'back in run 4');
        $this->assertSame(4, $last['nl-city']['run_id']);
        $this->assertArrayNotHasKey('dead-town', $last, 'never delivered');
        $this->assertSame('2026-10-29T12:00:00+00:00', $last['nl-city']['started_at']);
    }

    public function testLastOkIsTheEarlierRunWhileACommunityIsDry(): void
    {
        $r = $this->repository;
        $r->finish($r->start(self::at('2026-10-27T12:00:00Z'), '/out'), RunFixtures::standard(), RunRepository::OK, self::at('2026-10-27T12:00:30Z'));
        $r->finish($r->start(self::at('2026-10-28T12:00:00Z'), '/out'), RunFixtures::worse(), RunRepository::OK, self::at('2026-10-28T12:00:30Z'));

        $this->assertSame(1, $r->lastOkRuns()['hop-town']['run_id']);
        $this->assertSame(2, $r->lastOkRuns()['nl-city']['run_id']);
    }

    public function testOkRunIdsLeaveOutFailedRuns(): void
    {
        $this->history();

        $this->assertSame([1, 2, 4], $this->repository->okRunIds());
    }

    public function testEventsAcrossRunsWithFilters(): void
    {
        $this->history();

        $all = $this->repository->eventsPage(10, 0);
        $this->assertCount(4, $all);
        $this->assertSame('run-failed', $all[0]['kind'], 'newest first');
        $this->assertSame('2026-10-29T08:00:00+00:00', $all[0]['run_started_at']);

        $this->assertCount(3, $this->repository->eventsPage(10, 0, 'warning'));
        $this->assertCount(1, $this->repository->eventsPage(10, 0, 'alert'));
        $this->assertSame(['hop-town'], array_column($this->repository->eventsPage(10, 0, null, 'community-lost'), 'subject'));
        $this->assertCount(2, $this->repository->eventsPage(2, 0));
        $this->assertCount(2, $this->repository->eventsPage(2, 2));
        $this->assertSame(['community-added', 'community-lost', 'new-technical-type', 'run-failed'], $this->repository->eventKinds());
    }

    public function testFetchesCanBeLimitedToProblems(): void
    {
        $this->history();

        $all = $this->repository->fetchesFor(1);
        $problems = $this->repository->fetchesFor(1, onlyProblems: true);

        $this->assertGreaterThan(count($problems), count($all));
        $this->assertNotSame([], $problems);

        foreach ($problems as $fetch) {
            $this->assertTrue($fetch['status'] === null || $fetch['status'] >= 400);
        }

        $this->assertCount(3, $this->repository->fetchesFor(1, limit: 3));
    }
}
