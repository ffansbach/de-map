<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline\Assemble;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Community\CommunityInfo;
use FreifunkKarte\Parser\ParseResult;
use FreifunkKarte\Pipeline\Assemble\MapChooser;
use FreifunkKarte\Pipeline\CommunityBuild;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\Resolve\MapWalker;
use FreifunkKarte\Pipeline\Resolve\StageKind;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Pipeline\SourceEntry;
use FreifunkKarte\Source\SourceCache;
use FreifunkKarte\Tests\Pipeline\PipelineFixtures;
use PHPUnit\Framework\TestCase;

final class MapChooserTest extends TestCase
{
    use PipelineFixtures;

    private RunState $state;
    private SourceCache $cache;
    private MapChooser $chooser;

    protected function setUp(): void
    {
        $this->state = new RunState();
        $this->cache = new SourceCache();
        $this->chooser = new MapChooser(new MapWalker($this->state, $this->cache), $this->state, $this->cache);
    }

    /**
     * @param list<SourceEntry> $entries
     */
    private static function build(array $entries): CommunityBuild
    {
        return new CommunityBuild(new CommunityInfo('town', 'Town', ''), CommunityBuild::ORIGIN_DIRECTORY, null, true, CommunityReport::NO_DATA, $entries);
    }

    private function delivers(string $url, Format $format = Format::NodeInfo): void
    {
        $this->state->recordFetches([$url => self::fetched($url)]);
        $this->state->setParsed($url, self::nodes(['1'], $format));
    }

    public function testNoMapDeliversNothingIsChosen(): void
    {
        $choice = $this->chooser->choose(self::build([self::entry('town', 'https://a.example/nodes.json')]), self::now());

        $this->assertFalse($choice->nodelist);
        $this->assertSame([], $choice->resolutions);
        $this->assertSame([], $choice->unused);
    }

    public function testTheFirstMapThatDeliversWins(): void
    {
        $this->delivers('https://a.example/nodes.json');
        $this->delivers('https://b.example/nodes.json');
        $entries = [self::entry('town', 'https://a.example/nodes.json'), self::entry('town', 'https://b.example/nodes.json')];

        $choice = $this->chooser->choose(self::build($entries), self::now());

        $this->assertFalse($choice->nodelist);
        $this->assertSame(['https://a.example/nodes.json'], $choice->resolutions[0]->urls);
        $this->assertCount(1, $choice->resolutions);
        $this->assertSame(['https://b.example/nodes.json'], $choice->unused[0]->urls);
        $this->assertSame(StageKind::Declared, $choice->resolutions[0]->via);
    }

    public function testNodelistsTakePriorityOverEarlierMaps(): void
    {
        $this->delivers('https://a.example/nodes.json');
        $this->delivers('https://b.example/nodelist.json', Format::Nodelist);
        $entries = [self::entry('town', 'https://a.example/nodes.json'), self::entry('town', 'https://b.example/nodelist.json', 'nodelist')];

        $choice = $this->chooser->choose(self::build($entries), self::now());

        $this->assertTrue($choice->nodelist);
        $this->assertSame(['https://b.example/nodelist.json'], $choice->resolutions[0]->urls);
        $this->assertSame(['https://a.example/nodes.json'], $choice->unused[0]->urls);
    }

    public function testAllNodelistsAreUsed(): void
    {
        $this->delivers('https://a.example/nodelist.json', Format::Nodelist);
        $this->delivers('https://b.example/nodelist.json', Format::Nodelist);
        $entries = [self::entry('town', 'https://a.example/nodelist.json', 'nodelist'), self::entry('town', 'https://b.example/nodelist.json', 'nodelist')];

        $choice = $this->chooser->choose(self::build($entries), self::now());

        $this->assertCount(2, $choice->resolutions);
        $this->assertSame([], $choice->unused);
    }

    public function testDataFoundByProbingIsRememberedButADeclaredUrlIsNot(): void
    {
        $this->delivers('https://map.example/data/meshviewer.json');
        $this->state->recordFetches(['https://map.example/' => self::fetched('https://map.example/'), 'https://map.example/config.json' => self::fetched('https://map.example/config.json')]);
        $this->state->setParsed('https://map.example/', new ParseResult(Format::Html, parsed: false, dataPaths: ['data/']));
        $this->state->setParsed('https://map.example/config.json', new ParseResult(Format::Html, parsed: false));
        $this->state->recordFetches(['https://map.example/data/nodes.json' => self::fetched('https://map.example/data/nodes.json', 404)]);
        $this->delivers('https://direct.example/nodes.json');

        $this->chooser->choose(self::build([self::entry('town', 'https://map.example/')]), self::now());
        $this->chooser->choose(self::build([self::entry('town', 'https://direct.example/nodes.json')]), self::now());

        $this->assertSame(['https://map.example/data/meshviewer.json'], $this->cache->get('https://map.example/', self::now()));
        $this->assertNull($this->cache->get('https://direct.example/nodes.json', self::now()));
    }
}
