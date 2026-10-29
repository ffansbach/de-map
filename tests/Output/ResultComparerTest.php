<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Output;

use FreifunkKarte\Output\ResultComparer;
use PHPUnit\Framework\TestCase;

final class ResultComparerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function router(string $id, string $community = 'a', string $status = 'online'): array
    {
        return ['id' => $id, 'lat' => '50.1', 'long' => '8.1', 'name' => 'n-' . $id, 'community' => $community, 'status' => $status, 'clients' => 1];
    }

    public function testIdenticalResults(): void
    {
        $routers = [self::router('1'), self::router('2')];
        $communities = ['a' => ['name' => 'A', 'url' => 'u', 'meta' => false]];

        $diff = (new ResultComparer())->compare($routers, $routers, $communities, $communities);

        $this->assertSame([2, 2, 2], [$diff['oldNodes'], $diff['newNodes'], $diff['common']]);
        $this->assertSame([], $diff['onlyOld']);
        $this->assertSame([], $diff['onlyNew']);
        $this->assertSame([], $diff['communitiesChanged']);
    }

    public function testNodesAreCountedPerCommunityAndSortedByAmount(): void
    {
        $old = [self::router('1', 'a'), self::router('2', 'b'), self::router('3', 'b')];
        $new = [self::router('1', 'a'), self::router('9', 'c')];

        $diff = (new ResultComparer())->compare($old, $new, [], []);

        $this->assertSame(['b' => 2], $diff['onlyOld']);
        $this->assertSame(['c' => 1], $diff['onlyNew']);
        $this->assertSame(1, $diff['common']);
        $this->assertSame(['b: n-2 (2)', 'b: n-3 (3)'], $diff['samplesOnlyOld']);
    }

    public function testNodeIdentityIsNameIdAndPositionNotTheCommunityOrState(): void
    {
        $diff = (new ResultComparer())->compare(
            [self::router('1', 'a', 'online')],
            [self::router('1', 'b', 'offline')],
            [],
            [],
        );

        $this->assertSame(1, $diff['common']);
    }

    public function testCommunityDifferences(): void
    {
        $diff = (new ResultComparer())->compare([], [], [
            'same' => ['name' => 'S', 'url' => 'u', 'meta' => false],
            'gone' => ['name' => 'G', 'url' => 'u', 'meta' => false],
            'changed' => ['name' => 'C', 'url' => 'u', 'meta' => false],
        ], [
            'same' => ['name' => 'S', 'url' => 'u', 'meta' => false],
            'changed' => ['name' => 'C', 'url' => 'u2', 'meta' => 'Meta'],
            'added' => ['name' => 'A', 'url' => 'u', 'meta' => false],
        ]);

        $this->assertSame(['gone'], $diff['communitiesOnlyOld']);
        $this->assertSame(['added'], $diff['communitiesOnlyNew']);
        $this->assertSame(['changed'], $diff['communitiesChanged']);
    }

    public function testSamplesAreLimitedToTen(): void
    {
        $many = array_map(static fn (int $i): array => self::router((string) $i), range(1, 25));

        $diff = (new ResultComparer())->compare($many, [], [], []);

        $this->assertCount(10, $diff['samplesOnlyOld']);
        $this->assertSame(['a' => 25], $diff['onlyOld']);
    }
}
