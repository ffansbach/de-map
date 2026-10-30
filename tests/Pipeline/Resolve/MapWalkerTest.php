<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline\Resolve;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Parser\ParseResult;
use FreifunkKarte\Pipeline\Resolve\MapWalker;
use FreifunkKarte\Pipeline\Resolve\StageKind;
use FreifunkKarte\Pipeline\Resolve\WalkStatus;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Source\SourceCache;
use FreifunkKarte\Tests\Pipeline\PipelineFixtures;
use PHPUnit\Framework\TestCase;

final class MapWalkerTest extends TestCase
{
    use PipelineFixtures;

    private const MAP = 'https://map.example/';

    private RunState $state;
    private SourceCache $cache;
    private MapWalker $walker;

    protected function setUp(): void
    {
        $this->state = new RunState();
        $this->cache = new SourceCache();
        $this->walker = new MapWalker($this->state, $this->cache);
    }

    private function answered(string $url, ParseResult $result): void
    {
        $this->state->recordFetches([$url => self::fetched($url)]);
        $this->state->setParsed($url, $result);
    }

    public function testStartsWithTheDeclaredUrl(): void
    {
        $walk = $this->walker->walk(self::entry('town', self::MAP), self::now());

        $this->assertSame(WalkStatus::Fetch, $walk->status);
        $this->assertSame([self::MAP], $walk->urls);
        $this->assertSame(StageKind::Declared, $walk->via);
    }

    public function testDeclaredUrlThatIsDataResolvesAtOnce(): void
    {
        $this->answered(self::MAP, self::nodes(['1']));

        $walk = $this->walker->walk(self::entry('town', self::MAP), self::now());

        $this->assertTrue($walk->isResolved());
        $this->assertSame([self::MAP], $walk->urls);
        $this->assertSame(StageKind::Declared, $walk->via);
    }

    public function testAPageLeadsToConfigThenToItsDataPaths(): void
    {
        $entry = self::entry('town', self::MAP);
        $this->answered(self::MAP, new ParseResult(Format::Html, parsed: false));

        $config = $this->walker->walk($entry, self::now());
        $this->assertSame(StageKind::Config, $config->via);
        $this->assertSame([self::MAP . 'config.json'], $config->urls);

        $this->answered(self::MAP . 'config.json', new ParseResult(Format::FfmapConfig, parsed: false, dataPaths: ['data/']));

        $dataPath = $this->walker->walk($entry, self::now());
        $this->assertTrue($dataPath->needsFetch());
        $this->assertSame(StageKind::DataPath, $dataPath->via);
        $this->assertSame([self::MAP . 'data/meshviewer.json', self::MAP . 'data/nodes.json'], $dataPath->urls);
    }

    public function testDataPathsInThePageItselfAreUsed(): void
    {
        $this->answered(self::MAP, new ParseResult(Format::Html, parsed: false, dataPaths: ['inline/']));
        $this->answered(self::MAP . 'config.json', new ParseResult(Format::Html, parsed: false));

        $walk = $this->walker->walk(self::entry('town', self::MAP), self::now());

        $this->assertSame(StageKind::DataPath, $walk->via);
        $this->assertSame([self::MAP . 'inline/meshviewer.json', self::MAP . 'inline/nodes.json'], $walk->urls);
    }

    public function testOneDataPathDirectoryUsesOnlyItsFirstFileButTwoDirectoriesBoth(): void
    {
        $entry = self::entry('town', self::MAP);
        $this->answered(self::MAP, new ParseResult(Format::Html, parsed: false));
        $this->answered(self::MAP . 'config.json', new ParseResult(Format::FfmapConfig, parsed: false, dataPaths: ['a/', 'b/']));

        foreach (['a/meshviewer.json', 'a/nodes.json', 'b/nodes.json'] as $file) {
            $this->answered(self::MAP . $file, self::nodes(['1']));
        }

        $this->answered(self::MAP . 'b/meshviewer.json', ParseResult::unusable(Format::Html, 'gone'));

        $walk = $this->walker->walk($entry, self::now());

        $this->assertTrue($walk->isResolved());
        $this->assertSame([self::MAP . 'a/meshviewer.json', self::MAP . 'b/nodes.json'], $walk->urls);
    }

    public function testCandidatesYieldOnlyTheFirstHit(): void
    {
        $entry = self::entry('town', self::MAP);

        foreach ([self::MAP, self::MAP . 'config.json'] as $url) {
            $this->answered($url, new ParseResult(Format::Html, parsed: false));
        }

        $walk = $this->walker->walk($entry, self::now());
        $this->assertSame(StageKind::Candidates, $walk->via, 'no data paths: straight to the usual places');

        foreach ($walk->urls as $url) {
            $this->answered($url, self::nodes(['1']));
        }

        $resolved = $this->walker->walk($entry, self::now());

        $this->assertTrue($resolved->isResolved());
        $this->assertSame([$walk->urls[0]], $resolved->urls);
    }

    public function testNothingFoundIsExhausted(): void
    {
        $entry = self::entry('town', self::MAP);
        $this->answered(self::MAP, new ParseResult(Format::Html, parsed: false));
        $this->answered(self::MAP . 'config.json', new ParseResult(Format::Html, parsed: false));

        for ($i = 0; $i < 4; $i++) {
            $walk = $this->walker->walk($entry, self::now());

            if ($walk->needsFetch()) {
                foreach ($walk->urls as $url) {
                    $this->answered($url, new ParseResult(Format::Html, parsed: false));
                }
            }
        }

        $this->assertSame(WalkStatus::Exhausted, $this->walker->walk($entry, self::now())->status);
        $this->assertSame([], $this->walker->walk($entry, self::now())->urls);
        $this->assertNull($this->walker->walk($entry, self::now())->via);
    }

    public function testAHostThatDoesNotAnswerIsNotProbed(): void
    {
        $this->state->recordFetches([self::MAP => self::fetched(self::MAP, null, 'timeout')]);

        $this->assertSame(WalkStatus::Exhausted, $this->walker->walk(self::entry('town', self::MAP), self::now())->status);
    }

    public function testCachedUrlsComeFirst(): void
    {
        $this->cache->remember(self::MAP, [self::MAP . 'old/nodes.json'], self::now());

        $walk = $this->walker->walk(self::entry('town', self::MAP), self::now());

        $this->assertSame(StageKind::Cache, $walk->via);
        $this->assertSame([self::MAP . 'old/nodes.json'], $walk->urls);
    }

    public function testCacheThatNoLongerDeliversIsForgottenAndTheWalkStartsOver(): void
    {
        $this->cache->remember(self::MAP, [self::MAP . 'old/nodes.json'], self::now());
        $this->answered(self::MAP . 'old/nodes.json', ParseResult::unusable(Format::Html, 'moved'));

        $walk = $this->walker->walk(self::entry('town', self::MAP), self::now());

        $this->assertNull($this->cache->get(self::MAP, self::now()));
        $this->assertSame(StageKind::Declared, $walk->via);
    }

    public function testStagesAreAllListedForTheReport(): void
    {
        $stages = $this->walker->stages(self::entry('town', self::MAP), self::now());

        $this->assertSame([StageKind::Declared, StageKind::Config, StageKind::DataPath, StageKind::Candidates], array_map(static fn ($s) => $s->kind, $stages));
        $this->assertSame([], $stages[2]->urls, 'no data paths known yet');
        $this->assertNotContains(self::MAP . 'config.json', $stages[3]->urls, 'config.json is not tried twice');
    }

    public function testATypeWithoutDiscoveryHasOnlyItsDeclaredUrl(): void
    {
        $stages = $this->walker->stages(self::entry('town', 'https://list.example/nodelist.json', 'nodelist'), self::now());

        $this->assertCount(1, $stages);
        $this->assertSame(StageKind::Declared, $stages[0]->kind);
    }
}
