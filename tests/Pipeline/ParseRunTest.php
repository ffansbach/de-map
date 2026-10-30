<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline;

use DateTimeImmutable;
use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Community\MetaLink;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\ParseRun;
use FreifunkKarte\Pipeline\RunResult;
use FreifunkKarte\Source\ApiDocumentStore;
use FreifunkKarte\Source\LastGoodStore;
use FreifunkKarte\Source\SourceAssignment;
use FreifunkKarte\Source\SourceAssignments;
use FreifunkKarte\Source\SourceCache;
use FreifunkKarte\Tests\Support\StandardWorld;
use FreifunkKarte\Tests\Support\World;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ParseRunTest extends TestCase
{
    /** @var list<string> every URL requested, in order */
    private array $requests = [];

    /**
     * @param list<string> $skip
     * @param list<string> $unreachableHosts
     * @param array<string, list<Response>> $flaky answers served first for a URL, before the world answers
     */
    private function runWorld(World $world, ?SourceCache $cache = null, array $skip = [], ?string $only = null, array $unreachableHosts = [], bool $withFixed = true, array $flaky = [], ?LastGoodStore $lastGood = null, ?\Closure $clock = null, ?SourceAssignments $assignments = null, ?ApiDocumentStore $apiDocuments = null): RunResult
    {
        $this->requests = [];
        $inner = $world->httpHandler();
        $handler = function (RequestInterface $request, array $options) use ($inner, $unreachableHosts, &$flaky) {
            $this->requests[] = (string) $request->getUri();

            if (!empty($flaky[(string) $request->getUri()])) {
                return Create::promiseFor(array_shift($flaky[(string) $request->getUri()]));
            }

            if (in_array($request->getUri()->getHost(), $unreachableHosts, true)) {
                return Create::rejectionFor(new ConnectException('Could not resolve host', new Request('GET', $request->getUri()), null, ['errno' => 6]));
            }

            return $inner($request, $options);
        };

        $run = new ParseRun(
            new ParallelFetcher(new Client(['handler' => $handler]), 8, retryWithoutVerification: false),
            new SourceParser(),
            $cache ?? new SourceCache(),
            new MetaCommunityResolver(),
            $clock,
            World::DIRECTORY_URL,
            retryDelayMs: 0,
            lastGood: $lastGood,
            assignments: $assignments ?? new SourceAssignments(),
            apiDocuments: $apiDocuments,
        );

        return $run->run($withFixed ? $world->fixedCommunities() : [], $skip, $only);
    }

    private function report(RunResult $result, string $key): CommunityReport
    {
        foreach ($result->communities as $report) {
            if ($report->key === $key) {
                return $report;
            }
        }

        $this->fail('No report for ' . $key);
    }

    /**
     * @return list<string>
     */
    private function requestsTo(string $prefix): array
    {
        return array_values(array_filter($this->requests, static fn (string $url): bool => str_starts_with($url, $prefix)));
    }

    public function testReferenceWorldGivesTheRecordedResultPlusTheDeliberateDifferences(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);

        /** @var list<array<string, mixed>> $reference */
        $reference = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/golden/reference/routers.json'), true);
        $new = $result->nodes->toRouterEntries();

        // everything that was recorded is still there, unchanged
        foreach ($reference as $router) {
            $this->assertContains($router, $new, 'recorded node missing: ' . json_encode($router));
        }

        // and exactly these are new: hop-town's second dataPath, recently seen offline flat nodes
        $extra = array_filter($new, static fn (array $router): bool => !in_array($router, $reference, true));
        $perCommunity = array_count_values(array_column($extra, 'community'));
        ksort($perCommunity);

        $this->assertSame(['ff_fixed_flat' => 2, 'flat-town' => 2, 'hop-town' => 4], $perCommunity);

        foreach ($extra as $router) {
            $this->assertSame($router['community'] === 'hop-town' ? 'online' : 'offline', $router['status']);
        }
    }

    public function testListedCommunitiesAreTheRecordedOnesInTheRecordedOrder(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);

        /** @var array<string, array{name: string, url: string, meta: string|false}> $reference */
        $reference = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/golden/reference/communities.json'), true);

        $listed = [];

        foreach ($result->listedCommunities() as $community) {
            $listed[$community->key] = ['name' => $community->name, 'url' => $community->url, 'meta' => $community->meta ?? false];
        }

        $this->assertSame($reference, $listed);
    }

    public function testNoUrlIsRequestedTwice(): void
    {
        $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);

        $this->assertSame([], array_keys(array_filter(array_count_values($this->requests), static fn (int $n): bool => $n > 1)));
    }

    public function testNodelistCommunityNeverFetchesItsOtherMaps(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);

        $this->assertSame([], $this->requestsTo('https://nl.test/map'));
        $this->assertSame(CommunityReport::OK, $this->report($result, 'nl-city')->outcome);
        $this->assertSame('nodelist', $this->report($result, 'nl-city')->format);
    }

    public function testNodelistThatFailsLetsTheOtherMapsOfTheCommunityRun(): void
    {
        $world = (new World())
            ->community('twomaps', ['nodeMaps' => [
                ['technicalType' => 'nodelist', 'url' => 'https://twomaps.test/nodelist.json'],
                ['technicalType' => 'meshviewer', 'url' => 'https://twomaps.test/map/'],
            ]])
            ->route('https://twomaps.test/map/data/nodes.json', World::fixture('nodeinfo', 'tm'));

        $result = $this->runWorld($world);
        $report = $this->report($result, 'twomaps');

        $this->assertSame(CommunityReport::OK, $report->outcome);
        $this->assertSame(['https://twomaps.test/map/data/nodes.json'], $report->sourceUrls);
        $this->assertContains('https://twomaps.test/nodelist.json', $this->requests, 'the nodelist was tried first');
    }

    public function testHopglassUsesAllDataPaths(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);
        $hop = $this->report($result, 'hop-town');

        $this->assertSame(['https://hop.test/a/nodes.json', 'https://hop.test/b/nodes.json'], $hop->sourceUrls);
        $this->assertSame(8, $hop->added);
    }

    public function testMapSharedByTwoCommunitiesIsFetchedOnce(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);

        $this->assertCount(1, $this->requestsTo('https://dup.test/'));
        $this->assertSame(4, $this->report($result, 'dup-a')->added);
        $second = $this->report($result, 'dup-b');
        $this->assertSame([0, 4], [$second->added, $second->duplicates]);
        $this->assertContains('https://dup.test/data/nodes.json was already used by "dup-a", its nodes belong there', $second->messages);
    }

    public function testWarningsOfASharedSourceAreReportedOnlyForTheFirstCommunity(): void
    {
        $source = (string) json_encode(['version' => 2, 'nodes' => [
            ['nodeinfo' => ['node_id' => 'ok', 'hostname' => 'ok', 'location' => ['latitude' => 50.1, 'longitude' => 8.1]], 'flags' => ['online' => true]],
            ['nodeinfo' => ['hostname' => 'no id']],
        ]]);
        $maps = ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://shared.test/data/nodes.json']]];
        $world = (new World())->community('first', $maps)->community('second', $maps)->route('https://shared.test/data/nodes.json', $source);

        $result = $this->runWorld($world);

        $this->assertSame(['1 entries are not usable nodes (no id)'], $this->report($result, 'first')->warnings);
        $this->assertSame([], $this->report($result, 'second')->warnings);
    }

    private function oneNodeWorld(): World
    {
        $nodelist = (string) json_encode(['version' => '1.0.1', 'nodes' => [
            ['id' => 'x', 'name' => 'x', 'position' => ['lat' => 50.1, 'long' => 8.1], 'status' => ['online' => true]],
        ]]);

        return (new World())
            ->community('flaky', ['nodeMaps' => [['technicalType' => 'nodelist', 'url' => 'https://flaky.test/nodelist.json']]])
            ->route('https://flaky.test/nodelist.json', $nodelist);
    }

    public function testEmptyAnswerIsRetriedOnce(): void
    {
        $result = $this->runWorld($this->oneNodeWorld(), flaky: ['https://flaky.test/nodelist.json' => [new Response(200, [], '')]]);

        $this->assertSame(CommunityReport::OK, $this->report($result, 'flaky')->outcome);
        $this->assertCount(2, $this->requestsTo('https://flaky.test/'));
    }

    public function testServerErrorAndTooManyRequestsAreRetriedOnce(): void
    {
        foreach ([503, 429] as $status) {
            $result = $this->runWorld($this->oneNodeWorld(), flaky: ['https://flaky.test/nodelist.json' => [new Response($status)]]);

            $this->assertSame(CommunityReport::OK, $this->report($result, 'flaky')->outcome, (string) $status);
            $this->assertCount(2, $this->requestsTo('https://flaky.test/'), (string) $status);
        }
    }

    public function testAnswerThatStaysEmptyIsGivenUpAfterOneRetry(): void
    {
        $empty = new Response(200, [], '');
        $result = $this->runWorld($this->oneNodeWorld(), flaky: ['https://flaky.test/nodelist.json' => [$empty, $empty, $empty]]);
        $report = $this->report($result, 'flaky');

        $this->assertSame(CommunityReport::NO_DATA, $report->outcome);
        $this->assertCount(2, $this->requestsTo('https://flaky.test/'));
        $this->assertSame('empty', $report->attempts[0]->format);
    }

    public function testSourceThatHasTroubleShowsItsLastGoodNodesAndSaysSo(): void
    {
        $store = new LastGoodStore();
        $empty = new Response(200, [], '');
        $this->runWorld($this->oneNodeWorld(), lastGood: $store);

        $result = $this->runWorld($this->oneNodeWorld(), lastGood: $store, flaky: ['https://flaky.test/nodelist.json' => [$empty, $empty]]);
        $report = $this->report($result, 'flaky');

        $this->assertSame(CommunityReport::OK, $report->outcome);
        $this->assertSame(1, $result->nodes->count());
        $this->assertNotNull($report->staleSince);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('source answered with an empty body; showing the nodes saved at', $report->warnings[0]);
    }

    public function testServerErrorAlsoFallsBackToTheLastGoodNodes(): void
    {
        $store = new LastGoodStore();
        $this->runWorld($this->oneNodeWorld(), lastGood: $store);

        $result = $this->runWorld($this->oneNodeWorld(), lastGood: $store, flaky: ['https://flaky.test/nodelist.json' => [new Response(503), new Response(503)]]);

        $this->assertSame(1, $result->nodes->count());
        $this->assertStringContainsString('HTTP 503', $this->report($result, 'flaky')->warnings[0]);
    }

    public function testValidDataWithNoNodesIsBelievedAndNotReplacedByOldNodes(): void
    {
        $store = new LastGoodStore();
        $this->runWorld($this->oneNodeWorld(), lastGood: $store);
        $nowEmpty = (new World())
            ->community('flaky', ['nodeMaps' => [['technicalType' => 'nodelist', 'url' => 'https://flaky.test/nodelist.json']]])
            ->route('https://flaky.test/nodelist.json', '{"version":"1.0.1","nodes":[]}');

        $result = $this->runWorld($nowEmpty, lastGood: $store);

        $this->assertSame(0, $result->nodes->count());
        $this->assertNull($this->report($result, 'flaky')->staleSince);
    }

    public function testNotFoundIsNotReplacedByOldNodesEither(): void
    {
        $store = new LastGoodStore();
        $this->runWorld($this->oneNodeWorld(), lastGood: $store);
        $gone = (new World())->community('flaky', ['nodeMaps' => [['technicalType' => 'nodelist', 'url' => 'https://flaky.test/nodelist.json']]]);

        $this->assertSame(0, $this->runWorld($gone, lastGood: $store)->nodes->count());
    }

    public function testOldNodesAreNotShownAfterADay(): void
    {
        $store = new LastGoodStore();
        $this->runWorld($this->oneNodeWorld(), lastGood: $store);
        $empty = new Response(200, [], '');

        $result = $this->runWorld(
            $this->oneNodeWorld(),
            lastGood: $store,
            flaky: ['https://flaky.test/nodelist.json' => [$empty, $empty]],
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('+25 hours'),
        );

        $this->assertSame(0, $result->nodes->count());
    }

    public function testSourceThatDoesNotAnswerAtAllShowsItsLastGoodNodesWithoutAskingAgain(): void
    {
        $store = new LastGoodStore();
        $this->runWorld($this->oneNodeWorld(), lastGood: $store);

        $result = $this->runWorld($this->oneNodeWorld(), lastGood: $store, unreachableHosts: ['flaky.test']);
        $report = $this->report($result, 'flaky');

        $this->assertSame(CommunityReport::OK, $report->outcome);
        $this->assertSame(1, $result->nodes->count());
        $this->assertNotNull($report->staleSince);
        $this->assertStringContainsString('source answered with no answer (Could not resolve host); showing the nodes saved at', $report->warnings[0]);
        // a host that did not answer usually does not answer a second later either
        $this->assertCount(1, $this->requestsTo('https://flaky.test/'));
    }

    public function testApiFileThatDoesNotAnswerUsesTheCopyOfTheLastRun(): void
    {
        $api = new ApiDocumentStore();
        $this->runWorld($this->oneNodeWorld(), apiDocuments: $api);

        $result = $this->runWorld($this->oneNodeWorld(), unreachableHosts: ['api.test'], apiDocuments: $api);
        $report = $this->report($result, 'flaky');

        $this->assertSame(CommunityReport::OK, $report->outcome);
        $this->assertSame(1, $result->nodes->count());
        $this->assertSame('Freifunk flaky', $report->name);
        $this->assertNotNull($report->staleSince);
        $this->assertContainsEquals('API file not available (Could not resolve host); using the copy saved at ' . (new DateTimeImmutable())->format('Y-m-d H:i'), $report->messages);
    }

    public function testApiFileWithServerErrorOrHtmlInsteadOfJsonUsesTheCopy(): void
    {
        $api = new ApiDocumentStore();
        $this->runWorld($this->oneNodeWorld(), apiDocuments: $api);

        foreach ([new Response(503), new Response(429), new Response(200, [], '<html>maintenance</html>')] as $answer) {
            $result = $this->runWorld($this->oneNodeWorld(), flaky: ['https://api.test/flaky.json' => [$answer]], apiDocuments: $api);

            $this->assertSame(1, $result->nodes->count(), (string) $answer->getStatusCode());
            $this->assertNotNull($this->report($result, 'flaky')->staleSince);
        }
    }

    public function testApiFileThatIsGoneIsBelievedAndNotReplacedByTheCopy(): void
    {
        $api = new ApiDocumentStore();
        $this->runWorld($this->oneNodeWorld(), apiDocuments: $api);
        $removed = (new World())->communityWithoutApiFile('flaky');

        $result = $this->runWorld($removed, apiDocuments: $api);

        $this->assertSame(CommunityReport::API_UNAVAILABLE, $this->report($result, 'flaky')->outcome);
        $this->assertSame(0, $result->nodes->count());
    }

    public function testApiFileCopyIsNotUsedAfterADay(): void
    {
        $api = new ApiDocumentStore();
        $this->runWorld($this->oneNodeWorld(), apiDocuments: $api);

        $result = $this->runWorld(
            $this->oneNodeWorld(),
            unreachableHosts: ['api.test'],
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('+25 hours'),
            apiDocuments: $api,
        );

        $this->assertSame(CommunityReport::API_UNAVAILABLE, $this->report($result, 'flaky')->outcome);
        $this->assertNull($this->report($result, 'flaky')->staleSince);
    }

    public function testApiFileWithoutACopyIsReportedAsBefore(): void
    {
        $result = $this->runWorld($this->oneNodeWorld(), unreachableHosts: ['api.test'], apiDocuments: new ApiDocumentStore());

        $this->assertSame(CommunityReport::API_UNAVAILABLE, $this->report($result, 'flaky')->outcome);
    }

    public function testCommunityShownFromTwoCopiesSaysSinceWhenTheOlderOneWasSaved(): void
    {
        $api = new ApiDocumentStore();
        $store = new LastGoodStore();
        $this->runWorld($this->oneNodeWorld(), lastGood: $store, apiDocuments: $api);

        $result = $this->runWorld($this->oneNodeWorld(), lastGood: $store, unreachableHosts: ['api.test', 'flaky.test'], apiDocuments: $api);

        $this->assertSame(1, $result->nodes->count());
        $this->assertNotNull($this->report($result, 'flaky')->staleSince);
    }

    public function testNotFoundIsNotRetried(): void
    {
        $world = (new World())->community('gone', ['nodeMaps' => [['technicalType' => 'nodelist', 'url' => 'https://gone.test/nodelist.json']]]);

        $this->runWorld($world);

        $this->assertCount(1, $this->requestsTo('https://gone.test/'));
    }

    public function testAMapPageThatNamesItsDataFilesIsFollowed(): void
    {
        $world = (new World())
            ->community('rs', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://rs.test/']]])
            ->route('https://rs.test/', '<html><script>window.config={dataPath:["/data/a/","/data/b/"]}</script></html>')
            ->route('https://rs.test/data/a/meshviewer.json', World::fixture('meshviewer_flat', 'a'))
            ->route('https://rs.test/data/b/meshviewer.json', World::fixture('meshviewer_flat', 'b'));

        $result = $this->runWorld($world);
        $report = $this->report($result, 'rs');

        $this->assertSame(CommunityReport::OK, $report->outcome);
        $this->assertSame(['https://rs.test/data/a/meshviewer.json', 'https://rs.test/data/b/meshviewer.json'], $report->sourceUrls);
        $this->assertSame(12, $report->added);
    }

    public function testBothFileNamesOfOneDirectoryAreTheSameDataAndUsedOnce(): void
    {
        $world = (new World())
            ->community('rs', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://rs.test/']]])
            ->route('https://rs.test/', '<html>dataPath: ["/data/a/"]</html>')
            ->route('https://rs.test/data/a/meshviewer.json', World::fixture('meshviewer_flat', 'a'))
            ->route('https://rs.test/data/a/nodes.json', World::fixture('meshviewer_flat', 'a'));

        $report = $this->report($this->runWorld($world), 'rs');

        $this->assertSame(['https://rs.test/data/a/meshviewer.json'], $report->sourceUrls);
        $this->assertSame(6, $report->added);
        $this->assertSame(0, $report->duplicates);
    }

    public function testAConfigJsonWhoseDataPathHoldsMeshviewerJsonIsUnderstood(): void
    {
        $world = (new World())
            ->community('berlin', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://berlin.test']]])
            ->route('https://berlin.test/config.json', (string) json_encode(['dataPath' => ['https://berlin.test/']]))
            ->route('https://berlin.test/meshviewer.json', World::fixture('meshviewer_flat', 'be'));

        $report = $this->report($this->runWorld($world), 'berlin');

        $this->assertSame(CommunityReport::OK, $report->outcome);
        $this->assertSame(['https://berlin.test/meshviewer.json'], $report->sourceUrls);
    }

    public function testRelativeDataPathsAreResolvedAgainstThePage(): void
    {
        $world = (new World())
            ->community('rel', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://rel.test/map/']]])
            ->route('https://rel.test/map/', '<html>dataPath: ["data/x/"]</html>')
            ->route('https://rel.test/map/data/x/meshviewer.json', World::fixture('meshviewer_flat', 'rel'));

        $this->assertSame(['https://rel.test/map/data/x/meshviewer.json'], $this->report($this->runWorld($world), 'rel')->sourceUrls);
    }

    public function testWhatAPageToldUsIsRememberedAndNotAskedAgain(): void
    {
        $world = (new World())
            ->community('rs', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://rs.test/']]])
            ->route('https://rs.test/', '<html>dataPath: ["/data/a/", "/data/b/"]</html>')
            ->route('https://rs.test/data/a/meshviewer.json', World::fixture('meshviewer_flat', 'a'))
            ->route('https://rs.test/data/b/meshviewer.json', World::fixture('meshviewer_flat', 'b'));
        $cache = new SourceCache();

        $this->runWorld($world, $cache);
        $warm = $this->runWorld($world, $cache);

        $this->assertTrue($this->report($warm, 'rs')->viaCache);
        $this->assertSame(['https://rs.test/data/a/meshviewer.json', 'https://rs.test/data/b/meshviewer.json'], $this->requestsTo('https://rs.test/'), 'only the data files, not the page, not nodes.json');
    }

    /**
     * Several communities name one map page; the page names three data files, the broad one first.
     */
    private function sharedMapWorld(): World
    {
        $merge = static function (string ...$bodies): string {
            $nodes = [];

            foreach ($bodies as $body) {
                array_push($nodes, ...json_decode($body, true)['nodes']);
            }

            return (string) json_encode(['nodes' => $nodes]);
        };
        $siegburg = World::fixture('meshviewer_flat', 'sb');
        $lohmar = World::fixture('meshviewer_flat', 'lo');
        $extra = World::fixture('meshviewer_flat', 'extra');
        $map = ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://rs.test/']]];

        return (new World())
            ->community('lohmar', $map)->community('much', $map)->community('siegburg', $map)->community('wachtberg', $map)
            ->route('https://rs.test/', '<html>dataPath: ["/data/all/", "/data/siegburg1/d1/", "/data/lohmar1/d1/"]</html>')
            ->route('https://rs.test/data/all/meshviewer.json', $merge($siegburg, $lohmar, $extra))
            ->route('https://rs.test/data/siegburg1/d1/meshviewer.json', $siegburg)
            ->route('https://rs.test/data/lohmar1/d1/meshviewer.json', $lohmar);
    }

    private static function rhein(): SourceAssignments
    {
        return new SourceAssignments([
            new SourceAssignment('rs.test/data/siegburg', 'siegburg'),
            new SourceAssignment('rs.test/data/lohmar', 'lohmar'),
            new SourceAssignment('rs.test/data/all/', 'much', true),
        ]);
    }

    public function testWithoutRulesTheFirstCommunityOfASharedMapKeepsEverything(): void
    {
        $result = $this->runWorld($this->sharedMapWorld());

        $this->assertSame(['lohmar' => 18], array_count_values(array_column($result->nodes->toRouterEntries(), 'community')));
    }

    public function testSourcesOfASharedMapBelongToTheCommunitiesTheyAreAssignedTo(): void
    {
        $result = $this->runWorld($this->sharedMapWorld(), assignments: self::rhein());

        // the broad file is listed first on the page but only backfills what the specific ones did not have
        $this->assertSame(['siegburg' => 6, 'lohmar' => 6, 'much' => 6], array_count_values(array_column($result->nodes->toRouterEntries(), 'community')));

        $siegburg = $this->report($result, 'siegburg');
        $this->assertSame(['https://rs.test/data/siegburg1/d1/meshviewer.json'], $siegburg->sourceUrls);
        $this->assertSame([6, 0], [$siegburg->added, $siegburg->duplicates]);

        $much = $this->report($result, 'much');
        $this->assertSame(['https://rs.test/data/all/meshviewer.json'], $much->sourceUrls);
        $this->assertSame([6, 12], [$much->added, $much->duplicates], 'the 12 nodes the specific files already had');
        $this->assertTrue($this->hasMessage($much, 'https://rs.test/data/all/meshviewer.json (meshviewer, backfill):'), 'the report says which source was a backfill');
    }

    public function testACommunityWhoseMapHasDataThatBelongsToOthersIsNotReportedAsHavingNone(): void
    {
        $result = $this->runWorld($this->sharedMapWorld(), assignments: self::rhein());
        $wachtberg = $this->report($result, 'wachtberg');

        $this->assertSame(CommunityReport::OK, $wachtberg->outcome);
        $this->assertTrue($wachtberg->listed);
        $this->assertSame([], $wachtberg->sourceUrls);
        $this->assertSame([0, 0], [$wachtberg->added, $wachtberg->duplicates]);
        $this->assertContains('1 data file of this map is assigned to community "much"', $wachtberg->messages);
        $this->assertContains('1 data file of this map is assigned to community "siegburg"', $wachtberg->messages);
    }

    public function testEachDataFileIsFetchedOnce(): void
    {
        $this->runWorld($this->sharedMapWorld(), assignments: self::rhein());

        $files = array_filter($this->requests, static fn (string $url): bool => str_contains($url, '/meshviewer.json'));
        $this->assertSame(array_values(array_unique($files)), array_values($files));
    }

    public function testAnAssignmentToACommunityThatDoesNotExistKeepsTheNodesWhereTheyWereFound(): void
    {
        $assignments = new SourceAssignments([new SourceAssignment('rs.test/data/', 'ghost')]);

        $result = $this->runWorld($this->sharedMapWorld(), assignments: $assignments);

        $this->assertSame(18, $this->report($result, 'lohmar')->added);
        $this->assertTrue($this->hasMessage($this->report($result, 'lohmar'), 'is assigned to "ghost", which is not a listed community: kept here'));
    }

    public function testTheHttpAndTheHttpsAddressOfOneMapAreOneSource(): void
    {
        $world = $this->sharedMapWorld()
            ->community('secure', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'http://rs.test/']]])
            ->route('http://rs.test/', '<html>dataPath: ["/data/siegburg1/d1/"]</html>')
            ->route('http://rs.test/data/siegburg1/d1/meshviewer.json', World::fixture('meshviewer_flat', 'sb'));

        $result = $this->runWorld($world, assignments: self::rhein());

        $siegburg = $this->report($result, 'siegburg');
        $this->assertSame([6, 0], [$siegburg->added, $siegburg->duplicates], 'not added a second time under the other scheme');
        $this->assertCount(1, $siegburg->sourceUrls);
    }

    private function hasMessage(CommunityReport $report, string $needle): bool
    {
        foreach ($report->messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function testMapWithoutDataIsListedAndReportsWhatWasTried(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);
        $dead = $this->report($result, 'dead-town');

        $this->assertTrue($dead->listed);
        $this->assertSame(CommunityReport::NO_DATA, $dead->outcome);
        $this->assertSame([], $dead->sourceUrls);
        $this->assertSame([
            'https://dead.test/map/',
            'https://dead.test/map/config.json',
            'https://dead.test/map/nodes.json',
            'https://dead.test/map/data/nodes.json',
            'https://dead.test/map/json/nodes.json',
            'https://dead.test/map/meshviewer/data/meshviewer.json',
            'https://dead.test/map/data/meshviewer.json',
        ], array_map(static fn ($a): string => $a->url, $dead->attempts));
        $this->assertSame([404], array_values(array_unique(array_map(static fn ($a): ?int => $a->status, $dead->attempts))));
    }

    public function testCommunitiesThatCannotBeUsedAreReportedButNotListed(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);

        foreach (['skipped-town' => CommunityReport::SKIPPED, 'broken-api' => CommunityReport::API_UNAVAILABLE, 'no-maps' => CommunityReport::NO_NODEMAPS] as $key => $outcome) {
            $report = $this->report($result, $key);
            $this->assertFalse($report->listed, $key);
            $this->assertSame($outcome, $report->outcome, $key);
        }

        $this->assertSame([], $this->requestsTo('https://skipped.test/'));
        $this->assertNotContains('https://api.test/skipped-town.json', $this->requests, 'not even its API file is downloaded');
        $this->assertSame(['API file not available: HTTP 404'], $this->report($result, 'broken-api')->messages);
    }

    public function testRemovedMapTypeIsOnlyProbedAtItsDeclaredUrl(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);

        $this->assertSame(['https://netmon.test/'], $this->requestsTo('https://netmon.test/'));
        $this->assertTrue($this->report($result, 'netmon-old')->listed);
        $this->assertSame(['netmon'], $this->report($result, 'netmon-old')->declaredTypes);
    }

    public function testFixedCommunitiesComeLastAndUseTheirHomePage(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);
        $keys = array_map(static fn (CommunityReport $r): string => $r->key, $result->listedCommunities());

        $this->assertSame(['ff_fixed_flat', 'ff_fixed_nl', 'ff_fixed_dead'], array_slice($keys, -3));
        $this->assertSame('https://fixed.test/data/meshviewer.json', $this->report($result, 'ff_fixed_flat')->url);
        $this->assertSame('fixed', $this->report($result, 'ff_fixed_flat')->origin);
    }

    public function testNodelistCommunitiesAreProcessedBeforeTheOthers(): void
    {
        // the nodelist community is declared last but its nodes are added first
        $nodes = fn (string $prefix): string => World::fixture('nodeinfo', $prefix);
        $world = (new World())
            ->community('early', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://early.test/data/nodes.json']]])
            ->route('https://early.test/data/nodes.json', $nodes('same'))
            ->community('late', ['nodeMaps' => [['technicalType' => 'nodelist', 'url' => 'https://late.test/nodelist.json']]])
            ->route('https://late.test/nodelist.json', (string) json_encode(['version' => '1.0.1', 'nodes' => [
                ['id' => 'x', 'name' => 'x', 'position' => ['lat' => 50.1, 'long' => 8.1], 'status' => ['online' => true]],
            ]]));

        $result = $this->runWorld($world);
        $keys = array_map(static fn (CommunityReport $r): string => $r->key, $result->listedCommunities());

        $this->assertSame(['late', 'early'], $keys);
        $this->assertSame('late', $result->nodes->toRouterEntries()[0]['community']);
    }

    public function testWarmRunWithTheCacheNeedsFewerRequestsAndGivesTheSameNodes(): void
    {
        $cache = new SourceCache();
        $cold = $this->runWorld(StandardWorld::build(), $cache, StandardWorld::SKIP);
        $coldRequests = count($this->requests);
        $warm = $this->runWorld(StandardWorld::build(), $cache, StandardWorld::SKIP);

        $this->assertLessThan($coldRequests, count($this->requests));
        $this->assertSame($cold->nodes->toRouterEntries(), $warm->nodes->toRouterEntries());

        $mv = $this->report($warm, 'mv-city');
        $this->assertTrue($mv->viaCache);
        $this->assertSame(['https://mv.test/map/data/nodes.json'], $mv->sourceUrls);
        $this->assertSame([], $this->requestsTo('https://mv.test/map/nodes.json'));
        $this->assertSame([], $this->requestsTo('https://mv.test/map/config.json'));
    }

    public function testCachedUrlThatStopsWorkingFallsBackToDiscovery(): void
    {
        $cache = new SourceCache();
        $this->runWorld(StandardWorld::build(), $cache, StandardWorld::SKIP);

        // the map moves its data from data/nodes.json to json/nodes.json
        $moved = StandardWorld::build()
            ->route('https://mv.test/map/data/nodes.json', 'gone')
            ->route('https://mv.test/map/json/nodes.json', World::fixture('nodeinfo', 'mv'));
        $result = $this->runWorld($moved, $cache, StandardWorld::SKIP);
        $mv = $this->report($result, 'mv-city');

        $this->assertSame(CommunityReport::OK, $mv->outcome);
        $this->assertFalse($mv->viaCache);
        $this->assertSame(['https://mv.test/map/json/nodes.json'], $mv->sourceUrls);

        // and the next run goes straight there
        $this->runWorld($moved, $cache, StandardWorld::SKIP);
        $this->assertSame([], $this->requestsTo('https://mv.test/map/config.json'));
        $this->assertContains('https://mv.test/map/json/nodes.json', $this->requests);
    }

    public function testHostThatDoesNotAnswerIsNotProbedFurther(): void
    {
        $world = (new World())->community('down', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://down.test/map/']]]);

        $result = $this->runWorld($world, unreachableHosts: ['down.test']);

        $this->assertSame(['https://down.test/map/'], $this->requestsTo('https://down.test/'));
        $this->assertSame(CommunityReport::NO_DATA, $this->report($result, 'down')->outcome);
        $this->assertNull($this->report($result, 'down')->attempts[0]->status);
        $this->assertStringContainsString('Could not resolve host', (string) $this->report($result, 'down')->attempts[0]->error);
    }

    public function testMapWithoutTechnicalTypeIsRecordedButNotFetched(): void
    {
        $world = (new World())->community('typeless', ['nodeMaps' => [['url' => 'https://typeless.test/data/nodes.json'], ['technicalType' => 'MeshViewer', 'url' => 'https://typeless.test/map/']]]);

        $result = $this->runWorld($world);
        $report = $this->report($result, 'typeless');

        $this->assertSame(['(none)', 'meshviewer'], $report->declaredTypes);
        $this->assertSame([], $this->requestsTo('https://typeless.test/data/'));
        $this->assertContains('ignored map without technicalType: https://typeless.test/data/nodes.json', $report->messages);
    }

    public function testOnlyRestrictsTheRunToOneCommunity(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP, only: 'flat-town', withFixed: false);

        $this->assertCount(1, $result->communities);
        $this->assertSame(6, $result->nodes->count());
        $this->assertSame([], $this->requestsTo('https://nl.test/'));
    }

    public function testMetaCommunityLinksAreDerivedFromListedCommunities(): void
    {
        $maps = static fn (string $key): array => ['metacommunity' => 'Funk Nord', 'url' => "https://funknord.test/$key/", 'nodeMaps' => []];
        $world = (new World())->community('a', $maps('a'))->community('b', $maps('b'))->community('c', $maps('c'));

        $result = $this->runWorld($world);

        $this->assertArrayHasKey('Funk Nord', $result->metaLinks);
        $this->assertSame(['https://funknord.test/', MetaLink::SHARED_HOST, 3], [
            $result->metaLinks['Funk Nord']->url,
            $result->metaLinks['Funk Nord']->source,
            $result->metaLinks['Funk Nord']->members,
        ]);
    }

    public function testRunWithoutDirectoryFailsInsteadOfProducingAnEmptyResult(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Community directory not available');

        $this->runWorld(new World(), withFixed: false);
    }

    public function testEveryFetchedUrlIsRecordedWithWhatCameBack(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);
        $byUrl = [];

        foreach ($result->fetches as $fetch) {
            $byUrl[$fetch->url] = $fetch;
        }

        $this->assertCount(count($this->requests), $result->fetches);
        $this->assertSame(['nodelist', 7 + 1, 200], [$byUrl['https://nl.test/nodelist.json']->format, $byUrl['https://nl.test/nodelist.json']->nodes, $byUrl['https://nl.test/nodelist.json']->status]);
        $this->assertSame(404, $byUrl['https://dead.test/map/nodes.json']->status);
        $this->assertNull($byUrl['https://dead.test/map/nodes.json']->format);
        $this->assertSame('meshviewer_flat', $byUrl['https://flat.test/data/meshviewer.json']->format);
        $this->assertSame(200, $byUrl['https://api.test/nl-city.json']->status, 'API files are recorded, too');
        $this->assertContains('https://directory.test/directory.json', array_keys($byUrl));
    }

    public function testRequestsAndTimeAreReported(): void
    {
        $result = $this->runWorld(StandardWorld::build(), skip: StandardWorld::SKIP);

        $this->assertSame(count($this->requests), $result->requests);
        $this->assertGreaterThan(0.0, $result->seconds);
        $this->assertInstanceOf(DateTimeImmutable::class, $result->startedAt);
    }
}
