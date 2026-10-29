<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use DateTimeImmutable;
use FreifunkKarte\Admin\AdminApp;
use FreifunkKarte\Admin\AdminConfig;
use FreifunkKarte\Admin\Routes;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Tests\Legacy\StandardWorld;
use FreifunkKarte\Tests\Run\RunFixtures;
use Psr\Http\Message\ResponseInterface;

final class OverviewPagesTest extends AdminTestCase
{
    private RunRepository $repository;
    private AdminApp $app;

    protected function setUp(): void
    {
        $this->repository = RunRepository::inMemory();
        $this->app = Routes::app($this->repository, new AdminConfig());
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(string $path, array $query = []): ResponseInterface
    {
        return $this->app->handle(self::request($path, $query));
    }

    /**
     * Run 1: normal. Run 2: a bad day with a map that only has a page, and a community named like an attack.
     * Run 3: failed.
     */
    private function history(bool $withFailedRun = true): void
    {
        $r = $this->repository;
        $r->finish($r->start(self::at('2026-10-27T12:00:00Z'), '/srv/out'), RunFixtures::standard(), RunRepository::OK, self::at('2026-10-27T12:00:30Z'));

        $second = $r->start(self::at('2026-10-28T12:00:00Z'), '/srv/out');
        $world = StandardWorld::build()
            ->route('https://hop.test/a/nodes.json', 'not json')
            ->route('https://hop.test/b/nodes.json', 'not json')
            ->community('pagey', ['name' => '<img src=x onerror=alert(1)>', 'nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://pagey.test/map/']]])
            ->route('https://pagey.test/map/', '<html>a viewer without data</html>');
        $r->finish($second, RunFixtures::standard($world), RunRepository::OK, self::at('2026-10-28T12:00:30Z'));
        $r->addEvents($second, [
            new DriftEvent(DriftEvent::COMMUNITY_LOST, DriftEvent::WARNING, 'hop-town', '"Freifunk hop-town" delivered 8 nodes last time and nothing now'),
            new DriftEvent(DriftEvent::NEW_WARNING, DriftEvent::INFO, 'nl-city', 'a small note'),
            new DriftEvent(DriftEvent::RUN_NODES_DROPPED, DriftEvent::ALERT, 'run', 'The run found 32 nodes, the one before 40'),
        ], self::at('2026-10-28T12:00:30Z'));

        if ($withFailedRun) {
            $third = $r->start(self::at('2026-10-29T08:00:00Z'), '/srv/out');
            $r->fail($third, 'RuntimeException: Community directory not available', self::at('2026-10-29T08:00:10Z'));
            $r->addEvents($third, [new DriftEvent(DriftEvent::RUN_FAILED, DriftEvent::ALERT, 'run', 'The run failed: Community directory not available')], self::at('2026-10-29T08:00:10Z'));
        }
    }

    public function testEmptyHistory(): void
    {
        $this->assertStringContainsString('No run has been recorded yet', $this->assertValidHtml($this->get('/')));
        $this->assertStringContainsString('No run has been recorded yet', $this->assertValidHtml($this->get('/runs')));
        $this->assertSame(404, $this->get('/runs/1')->getStatusCode());
    }

    public function testOverviewShowsTheLatestGoodRunItsEventsAndTheProblemOfALaterRun(): void
    {
        $this->history();

        $response = $this->get('/');
        $html = $this->assertValidHtml($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Latest successful run', $html);
        $this->assertStringContainsString('<a href="/runs/2">#2</a>', $html);
        // the failed run 3 is announced, the numbers are those of run 2
        $this->assertStringContainsString('The latest run <a href="/runs/3">#3</a>', $html);
        $this->assertStringContainsString('Community directory not available', $html);
        // events of run 2 without the info note
        $this->assertStringContainsString('delivered 8 nodes last time and nothing now', $html);
        $this->assertStringContainsString('The run found 32 nodes', $html);
        $this->assertStringNotContainsString('a small note', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Recent runs', $html);
        $this->assertStringContainsString('<a href="/no-data">maps without data</a>', $html);
    }

    public function testOverviewOfAHealthyHistoryHasNoProblemBanner(): void
    {
        $this->history(withFailedRun: false);

        $html = $this->assertValidHtml($this->get('/'));

        $this->assertStringNotContainsString('The latest run', $html);
    }

    public function testRunListWithPaging(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $id = $this->repository->start(self::at('2026-10-29T08:00:00Z'), '/out');
            $this->repository->fail($id, 'boom', self::at('2026-10-29T08:00:10Z'));
        }

        $first = $this->assertValidHtml($this->get('/runs'));
        $this->assertSame(50, substr_count($first, '<a href="/runs/'), 'fifty rows');
        $this->assertStringContainsString('older →', $first);
        $this->assertStringNotContainsString('← newer', $first);
        $this->assertStringContainsString('<a href="/runs/55">#55</a>', $first);

        $second = $this->assertValidHtml($this->get('/runs', ['page' => '2']));
        $this->assertSame(5, substr_count($second, '<a href="/runs/'));
        $this->assertStringContainsString('← newer', $second);
        $this->assertStringNotContainsString('older →', $second);
    }

    public function testNonsensePageNumbersFallBackToTheFirstPage(): void
    {
        $this->history();

        foreach (['abc', '-3', '0', '1; DROP TABLE runs'] as $page) {
            $this->assertSame(200, $this->get('/runs', ['page' => $page])->getStatusCode(), $page);
        }

        $this->assertSame(3, $this->repository->runCount());
    }

    public function testRunDetail(): void
    {
        $this->history();

        $html = $this->assertValidHtml($this->get('/runs/2'));

        $this->assertStringContainsString('Run #2', $html);
        $this->assertStringContainsString('/srv/out', $html);
        $this->assertStringContainsString('← run #1', $html);
        $this->assertStringContainsString('run #3 →', $html);
        // what changed since run 1
        $this->assertStringContainsString('Changes since run <a href="/runs/1">#1</a>', $html);
        $this->assertStringContainsString('<a href="/communities/hop-town">Freifunk hop-town</a>', $html);
        $this->assertStringContainsString('ok', $html);
        $this->assertStringContainsString('no-data', $html);
        // maps without data, with the reason
        $this->assertStringContainsString('Maps without data', $html);
        $this->assertStringContainsString('the server answers, but there is nothing at the known places', $html);
        $this->assertStringContainsString('a page answers, but no data was found behind it', $html);
        // fetches
        $this->assertStringContainsString('Slowest requests', $html);
        $this->assertStringContainsString('https://pagey.test/map/', $html);
    }

    public function testNothingFromTheDataCanInjectMarkup(): void
    {
        $this->history();

        foreach (['/', '/runs/2'] as $path) {
            $html = $this->assertValidHtml($this->get($path));

            $this->assertStringNotContainsString('<img src=x', $html, $path);
        }

        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', (string) $this->get('/runs/2')->getBody());
    }

    public function testAFailedRunShowsItsError(): void
    {
        $this->history();

        $html = $this->assertValidHtml($this->get('/runs/3'));

        $this->assertStringContainsString('Community directory not available', $html);
        $this->assertStringContainsString('failed', $html);
        $this->assertStringNotContainsString('Slowest requests', $html, 'a failed run has no requests to show');
    }

    public function testUnknownAndMalformedRunsAreNotFound(): void
    {
        $this->history();

        $this->assertSame(404, $this->get('/runs/99')->getStatusCode());
        $this->assertSame(404, $this->get('/runs/abc')->getStatusCode());
        $this->assertSame(404, $this->get('/runs/1.5')->getStatusCode());
    }

    public function testTheFirstRunHasNoChangesSection(): void
    {
        $this->history();

        $html = $this->assertValidHtml($this->get('/runs/1'));

        $this->assertStringNotContainsString('Changes since run', $html);
        $this->assertStringNotContainsString('← run #0', $html);
    }
}
