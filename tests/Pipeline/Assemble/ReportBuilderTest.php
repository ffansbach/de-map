<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline\Assemble;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Community\CommunityInfo;
use FreifunkKarte\Parser\ParseResult;
use FreifunkKarte\Pipeline\Assemble\AddedSource;
use FreifunkKarte\Pipeline\Assemble\Choice;
use FreifunkKarte\Pipeline\Assemble\PlanStep;
use FreifunkKarte\Pipeline\Assemble\ReportBuilder;
use FreifunkKarte\Pipeline\Assemble\Resolution;
use FreifunkKarte\Pipeline\CommunityBuild;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\Resolve\MapWalker;
use FreifunkKarte\Pipeline\Resolve\StageKind;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Pipeline\SourceEntry;
use FreifunkKarte\Source\SourceCache;
use FreifunkKarte\Tests\Pipeline\PipelineFixtures;
use PHPUnit\Framework\TestCase;

final class ReportBuilderTest extends TestCase
{
    use PipelineFixtures;

    private const URL = 'https://map.example/nodes.json';

    private RunState $state;
    private ReportBuilder $reports;

    protected function setUp(): void
    {
        $this->state = new RunState();
        $this->reports = new ReportBuilder($this->state, new MapWalker($this->state, new SourceCache()));
    }

    private static function build(SourceEntry ...$entries): CommunityBuild
    {
        return new CommunityBuild(new CommunityInfo('town', 'Town', 'https://town.example', 'Region'), CommunityBuild::ORIGIN_DIRECTORY, 'https://api/town.json', true, CommunityReport::NO_DATA, array_values($entries), ['from the api'], ['meshviewer'], 99);
    }

    private static function source(string $url, ParseResult $result, int $added, int $duplicates = 0, StageKind $via = StageKind::Declared, bool $backfill = false, ?string $repeatOf = null): AddedSource
    {
        return new AddedSource(new PlanStep('town', $url, self::entry('town', $url), $via, $backfill, $repeatOf), $result, $added, $duplicates);
    }

    private static function none(): Choice
    {
        return new Choice(false, [], []);
    }

    public function testCommunityWithDataIsOkWithCountsAndFormat(): void
    {
        $result = new ParseResult(Format::NodeInfo, [self::node('1'), self::node('2')], found: 5, withoutLocation: 1, dropped: 1, invalid: 1);
        $this->state->recordFetches([self::URL => self::fetched(self::URL)]);
        $this->state->setParsed(self::URL, $result);
        $entry = self::entry('town', self::URL);

        $report = $this->reports->build(self::build($entry), self::none(), [self::source(self::URL, $result, 1, 1)], [], true, self::now());

        $this->assertSame(CommunityReport::OK, $report->outcome);
        $this->assertSame(['town', 'Town', 'https://town.example', 'Region', 'directory', 'https://api/town.json', true], [$report->key, $report->name, $report->url, $report->meta, $report->origin, $report->apiUrl, $report->listed]);
        $this->assertSame([self::URL], $report->sourceUrls);
        $this->assertSame('nodeinfo', $report->format);
        $this->assertSame([5, 1, 1, 1, 1, 1], [$report->found, $report->added, $report->duplicates, $report->withoutLocation, $report->dropped, $report->invalid]);
        $this->assertFalse($report->viaCache);
        $this->assertSame(99, $report->claimedNodes);
        $this->assertSame(['meshviewer'], $report->declaredTypes);
        $this->assertNull($report->staleSince);
        $this->assertSame('from the api', $report->messages[0]);
        $this->assertSame(self::URL . ' (meshviewer): ' . $result->summary(), $report->messages[1]);
        $this->assertCount(1, $report->attempts);
        $this->assertSame([self::URL, 200, 'nodeinfo', 5], [$report->attempts[0]->url, $report->attempts[0]->status, $report->attempts[0]->format, $report->attempts[0]->nodes]);
    }

    public function testCacheAndBackfillAreSaidInTheMessage(): void
    {
        $result = self::nodes(['1']);

        $report = $this->reports->build(self::build(), self::none(), [
            self::source('https://a/1', $result, 1, via: StageKind::Cache),
            self::source('https://a/2', $result, 0, backfill: true),
        ], [], false, self::now());

        $this->assertTrue($report->viaCache);
        $this->assertStringContainsString('https://a/1 (meshviewer, cached): ', $report->messages[1]);
        $this->assertStringContainsString('https://a/2 (meshviewer, backfill): ', $report->messages[2]);
    }

    public function testOddnessesOfASharedSourceAreReportedWhereItWasUsedFirst(): void
    {
        $odd = new ParseResult(Format::NodeInfo, [self::node('1')], 1, warnings: ['two nodes share an id']);

        $first = $this->reports->build(self::build(), self::none(), [self::source('https://a/1', $odd, 1)], [], false, self::now());
        $second = $this->reports->build(self::build(), self::none(), [self::source('https://a/1', $odd, 0, repeatOf: 'other')], [], false, self::now());

        $this->assertSame(['two nodes share an id'], $first->warnings);
        $this->assertContains('warning: two nodes share an id', $first->messages);
        $this->assertSame([], $second->warnings);
        $this->assertContains('https://a/1 was already used by "other", its nodes belong there', $second->messages);
    }

    public function testCommunityWithoutDataKeepsItsOutcomeAndListsWhatWasNotFound(): void
    {
        $build = self::build(self::entry('town', 'https://a/'), self::entry('town', 'https://b/', 'ffmap'));

        $report = $this->reports->build($build, self::none(), [], ['a note'], false, self::now());

        $this->assertSame(CommunityReport::NO_DATA, $report->outcome);
        $this->assertSame(['from the api', 'a note', 'no data found for https://a/ (meshviewer)', 'no data found for https://b/ (ffmap)'], $report->messages);
        $this->assertNull($report->format);
        $this->assertSame([], $report->sourceUrls);
    }

    public function testDataThatWentToOtherCommunitiesStillCountsAsDelivered(): void
    {
        $report = $this->reports->build(self::build(self::entry('town', 'https://a/')), self::none(), [], [], true, self::now());

        $this->assertSame(CommunityReport::OK, $report->outcome);
        $this->assertNotContains('no data found for https://a/ (meshviewer)', $report->messages);
    }

    public function testUnusedMapsGiveTheirReason(): void
    {
        $unused = new Resolution(self::entry('town', 'https://a/'), ['https://a/1', 'https://a/2'], StageKind::Declared);

        $withNodelist = $this->reports->build(self::build(), new Choice(true, [], [$unused]), [], [], true, self::now());
        $withMap = $this->reports->build(self::build(), new Choice(false, [], [$unused]), [], [], true, self::now());

        $this->assertContains('not used: https://a/1, https://a/2 (meshviewer) - the community has a nodelist, which takes priority', $withNodelist->messages);
        $this->assertContains('not used: https://a/1, https://a/2 (meshviewer) - another map of this community was used', $withMap->messages);
    }

    public function testStaleSinceIsTheOlderOfNodesAndApiFile(): void
    {
        $result = self::nodes(['1']);
        $this->state->markStale(self::URL, self::now()->modify('-2 hours'));
        $source = self::source(self::URL, $result, 1);

        $nodesOnly = $this->reports->build(self::build(), self::none(), [$source], [], false, self::now());
        $this->state->markApiStale('town', self::now()->modify('-5 hours'));
        $apiOlder = $this->reports->build(self::build(), self::none(), [$source], [], false, self::now());
        $this->state->markApiStale('town', self::now()->modify('-1 hour'));
        $nodesOlder = $this->reports->build(self::build(), self::none(), [$source], [], false, self::now());
        $apiOnly = $this->reports->build(self::build(), self::none(), [self::source('https://fresh/', $result, 1)], [], false, self::now());

        $this->assertSame(self::now()->modify('-2 hours')->format('c'), $nodesOnly->staleSince);
        $this->assertSame(self::now()->modify('-5 hours')->format('c'), $apiOlder->staleSince);
        $this->assertSame(self::now()->modify('-2 hours')->format('c'), $nodesOlder->staleSince);
        $this->assertSame(self::now()->modify('-1 hour')->format('c'), $apiOnly->staleSince);
    }

    public function testAttemptsListEachTriedUrlOnceWithItsAnswer(): void
    {
        $entry = self::entry('town', 'https://map.example/');
        $this->state->recordFetches([
            'https://map.example/' => self::fetched('https://map.example/'),
            'https://map.example/config.json' => self::fetched('https://map.example/config.json', null, 'timeout'),
        ]);
        $this->state->setParsed('https://map.example/', new ParseResult(Format::Html, parsed: false));

        $report = $this->reports->build(self::build($entry, $entry), self::none(), [], [], false, self::now());

        $this->assertSame(
            [['https://map.example/', 200, null, 'html'], ['https://map.example/config.json', null, 'timeout', null]],
            array_map(static fn ($a) => [$a->url, $a->status, $a->error, $a->format], $report->attempts),
        );
    }
}
