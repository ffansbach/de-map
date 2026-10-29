<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * Characterises what the legacy parser does with the reference world (see StandardWorld).
 *
 * These tests describe behaviour, not wishes. Where the behaviour is a bug, the test says
 * "LEGACY BUG" and names the change the rewrite is expected to bring; that test is to be
 * flipped, not deleted, when the bug is fixed.
 */
final class LegacyStandardWorldTest extends TestCase
{
    private static LegacyRunner $runner;
    private static LegacyRunResult $cold;
    private static LegacyRunResult $warm;

    public static function setUpBeforeClass(): void
    {
        $world = StandardWorld::build();
        self::$runner = new LegacyRunner();
        // the first cron run on a machine, then the next one with everything cached
        self::$cold = self::$runner->run($world->routes(), $world->fixedCommunities(), StandardWorld::SKIP);
        self::$warm = self::$runner->run($world->routes(), $world->fixedCommunities(), StandardWorld::SKIP);
    }

    public static function tearDownAfterClass(): void
    {
        self::$runner->cleanUp();
    }

    public function testGoldenResult(): void
    {
        Golden::assertMatches('routers.json', Golden::json(self::$cold->routers));
        Golden::assertMatches('communities.json', Golden::json(self::$cold->communities));
    }

    public function testNoPhpWarningsOnHealthyInput(): void
    {
        $this->assertSame([], self::$cold->warnings);
        $this->assertSame([], self::$warm->warnings);
    }

    public function testOnlyThreeResultFilesAreWritten(): void
    {
        // result_metacommunities.json is read by data.php but nothing ever writes it,
        // so the frontend always gets metaCommunities = false
        $this->assertSame(
            ['logfile.txt', 'result_communities.json', 'result_routers.json', 'result_statistics.json'],
            self::$runner->files(),
        );
    }

    public function testNodesPerCommunity(): void
    {
        $perCommunity = array_count_values(array_column(self::$cold->routers, 'community'));

        $this->assertSame([
            'nl-city' => 4, 'mv-city' => 4, 'flat-town' => 4, 'hop-town' => 4,
            'dup-a' => 4, 'overlap-a' => 4, 'ff_fixed_flat' => 4, 'ff_fixed_nl' => 4,
        ], $perCommunity);
    }

    public function testRouterEntryShape(): void
    {
        $router = self::$cold->routers[0];

        $this->assertSame(['id', 'lat', 'long', 'name', 'community', 'status', 'clients'], array_keys($router));
        // coordinates are strings in the output, clients an int
        $this->assertIsString($router['lat']);
        $this->assertIsString($router['long']);
        $this->assertIsInt($router['clients']);
    }

    public function testListedCommunities(): void
    {
        $this->assertSame(
            ['nl-city', 'mv-city', 'flat-town', 'hop-town', 'dead-town', 'dup-a', 'dup-b', 'overlap-a', 'overlap-b', 'netmon-old', 'ff_fixed_flat', 'ff_fixed_nl', 'ff_fixed_dead'],
            array_keys(self::$cold->communities),
        );
    }

    public function testCommunityEntryUsesHomePageAndMetaCommunity(): void
    {
        $this->assertSame(['name' => 'Freifunk flat-town', 'url' => 'https://flat.example/home', 'meta' => false], self::$cold->communities['flat-town']);
        $this->assertSame('Meta Nord', self::$cold->communities['mv-city']['meta']);
        $this->assertFalse(self::$cold->communities['nl-city']['meta']);
    }

    public function testCommunitiesWithoutUsableApiFileAreLeftOut(): void
    {
        foreach (['broken-api', 'no-maps', 'skipped-town'] as $community) {
            $this->assertArrayNotHasKey($community, self::$cold->communities);
        }

        $this->assertTrue(self::$cold->hasMessage('broken-api', 'got no data'));
        $this->assertTrue(self::$cold->hasMessage('no-maps', 'has no nodeMaps'));
    }

    public function testNodelistWinsOverOtherMapsOfTheSameCommunity(): void
    {
        $this->assertTrue(self::$cold->hasMessage('nl-city', 'skipping - we already have found a nodeList'));
        $this->assertSame([], array_filter(self::$cold->calls, static fn (string $url): bool => str_starts_with($url, 'https://nl.test/map')));
    }

    public function testMeshviewerPageIsProbedInFixedOrder(): void
    {
        $probes = array_values(array_filter(self::$cold->calls, static fn (string $url): bool => str_starts_with($url, 'https://mv.test/')));

        // LEGACY QUIRK: "//config.json" - the normalised map url ends in "/" and "/config.json" is appended
        $this->assertSame([
            'https://mv.test/map//config.json',
            'https://mv.test/map/nodes.json',
            'https://mv.test/map/data/nodes.json',
        ], $probes);
    }

    public function testMapWithoutAnyDataIsProbedEverywhereButStillListed(): void
    {
        $probes = array_values(array_filter(self::$cold->calls, static fn (string $url): bool => str_starts_with($url, 'https://dead.test/')));

        $this->assertSame([
            'https://dead.test/map//config.json',
            'https://dead.test/map/nodes.json',
            'https://dead.test/map/data/nodes.json',
            'https://dead.test/map/json/nodes.json',
            'https://dead.test/map/meshviewer/data/meshviewer.json',
            'https://dead.test/map/data/meshviewer.json',
            'https://dead.test/map/',
        ], $probes);
        $this->assertArrayHasKey('dead-town', self::$cold->communities);
        $this->assertSame([], self::$cold->idsOf('dead-town'));
        $this->assertTrue(self::$cold->hasMessage('dead-town', 'found no nodes.json'));
    }

    public function testNodeinfoCountsAreReported(): void
    {
        // 7 nodes: 4 online with location, 2 without location, 1 offline for days
        $this->assertTrue(self::$cold->hasMessage('mv-city', 'parsing done. 7 nodes found, 4 added, 2 skipped, 0 duplicates, 1 dead'));
    }

    public function testFlatMeshviewerCountsAreReported(): void
    {
        $this->assertTrue(self::$cold->hasMessage('flat-town', 'parsing done. 8 nodes found, 4 added, 2 skipped, 0 duplicates, 2 dead'));
    }

    public function testNodelistCountsAreReported(): void
    {
        $this->assertTrue(self::$cold->hasMessage('nl-city', 'parsing done. 8 nodes found, 4 added, 2 skipped, 0 duplicates, 2 dead'));
    }

    public function testHopglassWithTwoDataPathsOnlyUsesTheFirst(): void
    {
        // LEGACY BUG: array_merge() result is discarded in getFromFfmap(), so nodes of the second
        // dataPath are lost. Expected after the rewrite: nodes of both (8 instead of 4).
        $ids = self::$cold->idsOf('hop-town');

        $this->assertCount(4, $ids);
        $this->assertSame([], array_filter($ids, static fn (string $id): bool => !str_starts_with($id, 'hopa-')));
        $this->assertContains('https://hop.test/b/nodes.json', self::$cold->calls);
    }

    public function testSecondCommunityWithTheSameMapIsFetchedAgainAndOnlyDeduplicatedByNode(): void
    {
        // LEGACY BUG: the "already parsed" check compares the normalised directory url
        // (".../data/") with the stored ".json" url, so it never matches for direct .json maps.
        // Expected after the rewrite: one fetch, and a message that the map was already parsed.
        $calls = array_count_values(self::$cold->calls);

        $this->assertSame(2, $calls['https://dup.test/data/nodes.json']);
        $this->assertSame([], self::$cold->idsOf('dup-b'));
        $this->assertTrue(self::$cold->hasMessage('dup-b', '0 added, 2 skipped, 4 duplicates'));
        $this->assertFalse(self::$cold->hasMessage('dup-b', 'already parsed'));
    }

    public function testSameNodesFromTwoCommunitiesBelongToTheFirst(): void
    {
        $this->assertCount(4, self::$cold->idsOf('overlap-a'));
        $this->assertSame([], self::$cold->idsOf('overlap-b'));
        $this->assertTrue(self::$cold->hasMessage('overlap-b', '0 added, 2 skipped, 4 duplicates'));
    }

    public function testSkippedCommunityIsNeverParsed(): void
    {
        $this->assertTrue(self::$cold->hasMessage('skipped-town', 'Skipping this community'));
        $this->assertNotContains('https://skipped.test/data/nodes.json', self::$cold->calls);
        // but its API file is still downloaded by the nodelist pass
        $this->assertContains('https://api.test/skipped-town.json', self::$cold->calls);
    }

    public function testRemovedMapTypeIsOnlyReported(): void
    {
        $this->assertTrue(self::$cold->hasMessage('netmon-old', 'Parser for netmon has been removed'));
        $this->assertNotContains('https://netmon.test/', self::$cold->calls);
        $this->assertArrayHasKey('netmon-old', self::$cold->communities);
    }

    public function testFixedCommunities(): void
    {
        $this->assertCount(4, self::$cold->idsOf('ff_fixed_flat'));
        $this->assertCount(4, self::$cold->idsOf('ff_fixed_nl'));
        $this->assertSame([], self::$cold->idsOf('ff_fixed_dead'));
        // a dead fixed community is listed all the same
        $this->assertArrayHasKey('ff_fixed_dead', self::$cold->communities);
    }

    public function testApiFileThatFailedIsRequestedTwiceInOneRun(): void
    {
        // failed downloads are not cached: once in the nodelist pass, once in the main pass
        $this->assertSame(2, array_count_values(self::$cold->calls)['https://api.test/broken-api.json']);
    }

    public function testGoldenRequestsOfAColdRun(): void
    {
        Golden::assertMatches('calls_cold.txt', Golden::calls(self::$cold->calls));
    }

    public function testWarmRunGivesTheSameResult(): void
    {
        $this->assertSame(self::$cold->routers, self::$warm->routers);
        $this->assertSame(array_keys(self::$cold->communities), array_keys(self::$warm->communities));
    }

    public function testWarmRunDoesNotReloadApiFilesThatWorked(): void
    {
        $apiCalls = array_values(array_filter(self::$warm->calls, static fn (string $url): bool => str_starts_with($url, 'https://api.test/')));

        // only the broken one, and again twice (failed downloads are never cached)
        $this->assertSame(['https://api.test/broken-api.json', 'https://api.test/broken-api.json'], $apiCalls);
    }

    public function testWarmRunDownloadsMoreThanTheColdRun(): void
    {
        // LEGACY BUG: the nodeSource cache makes getFromFfmap() run twice per direct .json map
        // (see the "already parsed" bug above), and each run fetches the source twice because the
        // result of getNodesFromCachedFfmapUrl() is thrown away. A cached run is slower, not faster.
        // Expected after the rewrite: a warm run needs fewer requests than a cold one.
        $this->assertGreaterThan(count(self::$cold->calls), count(self::$warm->calls));

        // 2 runs of getFromFfmap() x 2 fetches each, against 1 fetch in the cold run
        $this->assertSame(1, array_count_values(self::$cold->calls)['https://flat.test/data/meshviewer.json']);
        $this->assertSame(4, array_count_values(self::$warm->calls)['https://flat.test/data/meshviewer.json']);
    }

    public function testGoldenRequestsOfAWarmRun(): void
    {
        Golden::assertMatches('calls_warm.txt', Golden::calls(self::$warm->calls));
    }
}
