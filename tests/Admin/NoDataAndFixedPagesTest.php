<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use DateTimeImmutable;
use FreifunkKarte\Admin\AdminApp;
use FreifunkKarte\Admin\AdminConfig;
use FreifunkKarte\Admin\Routes;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Tests\Legacy\World;
use FreifunkKarte\Tests\Run\RunFixtures;
use Psr\Http\Message\ResponseInterface;

final class NoDataAndFixedPagesTest extends AdminTestCase
{
    private RunRepository $repository;
    private AdminApp $app;

    protected function setUp(): void
    {
        $this->repository = RunRepository::inMemory();
        $this->app = Routes::app($this->repository, new AdminConfig());
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle(self::request($path));
    }

    private function record(World $world, string $time): void
    {
        $at = new DateTimeImmutable($time);
        $this->repository->finish($this->repository->start($at, '/out'), RunFixtures::standard($world), RunRepository::OK, $at);
    }

    private static function nodes(string $prefix): string
    {
        return World::fixture('nodeinfo', $prefix);
    }

    public function testWithoutAHistory(): void
    {
        $this->assertStringContainsString('No run has been recorded yet', $this->assertValidHtml($this->get('/no-data')));
        $this->assertStringContainsString('No run has been recorded yet', $this->assertValidHtml($this->get('/fixed')));
    }

    public function testMapsWithAPageButNoDataComeFirstAndPointToThePage(): void
    {
        $this->seedHistory($this->repository);

        $html = $this->assertValidHtml($this->get('/no-data'));

        $page = strpos($html, 'A page answers, but no data was found behind it (1)');
        $notFound = strpos($html, 'The server answers, but there is nothing at the known places (');
        $this->assertNotFalse($page);
        $this->assertNotFalse($notFound);
        $this->assertLessThan($notFound, $page, 'the actionable group is first');

        $this->assertStringContainsString('<a href="https://pagey.test/map/" rel="noopener noreferrer nofollow" target="_blank">https://pagey.test/map/</a>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function testAServerThatCarriesSeveralMapsWithoutDataIsNamedOnce(): void
    {
        $world = (new World())
            ->community('a', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://maps.shared.test/a/']]])
            ->community('b', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://maps.shared.test/b/']]])
            ->community('c', ['nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://other.test/c/']]])
            ->route('https://maps.shared.test/a/', '<html>a</html>')
            ->route('https://maps.shared.test/b/', '<html>b</html>')
            ->route('https://other.test/c/', '<html>c</html>');
        $this->record($world, '2026-10-29T12:00:00Z');

        $html = $this->assertValidHtml($this->get('/no-data'));

        $this->assertStringContainsString('A page answers, but no data was found behind it (3)', $html);
        $this->assertStringContainsString('<li>maps.shared.test: 2 communities</li>', $html);
        $this->assertStringNotContainsString('other.test: 1', $html, 'a server with a single map is no news');
    }

    public function testHowLongACommunityHasBeenDry(): void
    {
        $this->seedHistory($this->repository);

        $html = $this->assertValidHtml($this->get('/no-data'));

        // hop-town delivered in run 1, not in run 2; dead-town never
        $this->assertStringContainsString('1 run, last delivered 2026-10-27 13:00', $html);
        $this->assertStringContainsString('never (2 recorded runs)', $html);
    }

    public function testCommunitiesWithoutAnyMapAreListedSeparately(): void
    {
        $this->seedHistory($this->repository);

        $html = $this->assertValidHtml($this->get('/no-data'));

        $this->assertStringContainsString('<summary>API file not available (1)</summary>', $html);
        $this->assertStringContainsString('<summary>API file without any map (1)</summary>', $html);
        $this->assertStringContainsString('<a href="/communities/broken-api">broken-api</a>', $html);
        $this->assertStringContainsString('<a href="/communities/no-maps">Freifunk no-maps</a>', $html);
    }

    public function testWhenEverythingDeliversThereIsNothingToList(): void
    {
        $this->record((new World())->community('fine', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://fine.test/data/nodes.json']]])->route('https://fine.test/data/nodes.json', self::nodes('f')), '2026-10-29T12:00:00Z');

        $html = $this->assertValidHtml($this->get('/no-data'));

        $this->assertStringContainsString('Every map delivered data in this run.', $html);
    }

    public function testFixedCommunitiesAreSortedIntoDryRedundantAndOwn(): void
    {
        $world = (new World())
            ->community('dir', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://shared.test/data/nodes.json']]])
            ->route('https://shared.test/data/nodes.json', self::nodes('s'))
            ->fixed('ff_repeats', 'https://shared.test/data/nodes.json', 'Ffmap')
            ->fixed('ff_own', 'https://own.test/data/nodes.json', 'Ffmap')
            ->route('https://own.test/data/nodes.json', self::nodes('o'))
            ->fixed('ff_dry', 'https://dry.test/data/nodes.json', 'Ffmap');
        $this->record($world, '2026-10-28T12:00:00Z');
        $this->record($world, '2026-10-29T12:00:00Z');

        $html = $this->assertValidHtml($this->get('/fixed'));

        $this->assertStringContainsString('3 fixed communities - 1 deliver nodes of their own, 1 only repeat what the directory delivers, 1 deliver nothing.', $html);
        $dry = strpos($html, 'Deliver nothing (1)');
        $redundant = strpos($html, 'Covered by the directory today (1)');
        $own = strpos($html, 'Deliver nodes of their own (1)');
        $this->assertNotFalse($dry);
        $this->assertNotFalse($redundant);
        $this->assertNotFalse($own);
        $this->assertTrue($dry < $redundant && $redundant < $own, 'the ones to act on come first');

        // each community is in its own section
        $this->assertGreaterThan($dry, strpos($html, 'ff_dry'));
        $this->assertLessThan($redundant, strpos($html, 'ff_dry'));
        $this->assertGreaterThan($redundant, strpos($html, 'ff_repeats'));
        $this->assertLessThan($own, strpos($html, 'ff_repeats'));
        $this->assertGreaterThan($own, strpos($html, 'ff_own'));

        $this->assertStringContainsString('never (2 recorded runs)', $html);
    }

    public function testAFixedCommunityThatRanDryShowsWhenItLastDelivered(): void
    {
        $delivering = (new World())->fixed('ff_late', 'https://late.test/data/nodes.json', 'Ffmap')->route('https://late.test/data/nodes.json', self::nodes('l'))
            ->community('anchor', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://anchor.test/data/nodes.json']]])->route('https://anchor.test/data/nodes.json', self::nodes('a'));
        $dry = (new World())->fixed('ff_late', 'https://late.test/data/nodes.json', 'Ffmap')
            ->community('anchor', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://anchor.test/data/nodes.json']]])->route('https://anchor.test/data/nodes.json', self::nodes('a'));
        $this->record($delivering, '2026-10-27T12:00:00Z');
        $this->record($dry, '2026-10-28T12:00:00Z');
        $this->record($dry, '2026-10-29T12:00:00Z');

        $html = $this->assertValidHtml($this->get('/fixed'));

        $this->assertStringContainsString('2 runs, last delivered 2026-10-27 13:00', $html);
    }

    public function testNoFixedCommunitiesInTheLatestRun(): void
    {
        $this->record((new World())->community('only', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => 'https://only.test/data/nodes.json']]])->route('https://only.test/data/nodes.json', self::nodes('o')), '2026-10-29T12:00:00Z');

        $this->assertStringContainsString('The latest run had no fixed communities.', $this->assertValidHtml($this->get('/fixed')));
    }

    public function testEveryPageOfTheUiIsWellFormedOnARealisticHistory(): void
    {
        $this->seedHistory($this->repository);

        foreach (['/', '/runs', '/runs/1', '/runs/2', '/runs/3', '/communities', '/communities/nl-city', '/communities/hop-town', '/communities/pagey', '/no-data', '/fixed', '/events'] as $path) {
            $response = $this->get($path);

            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertValidHtml($response);
        }
    }
}
