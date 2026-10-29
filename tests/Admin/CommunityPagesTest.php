<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use DateTimeImmutable;
use FreifunkKarte\Admin\AdminApp;
use FreifunkKarte\Admin\AdminConfig;
use FreifunkKarte\Admin\Html;
use FreifunkKarte\Admin\Routes;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RunRepository;
use Psr\Http\Message\ResponseInterface;

final class CommunityPagesTest extends AdminTestCase
{
    private RunRepository $repository;
    private AdminApp $app;

    protected function setUp(): void
    {
        $this->repository = RunRepository::inMemory();
        $this->app = Routes::app($this->repository, new AdminConfig());
        $this->seedHistory($this->repository);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(string $path, array $query = []): ResponseInterface
    {
        return $this->app->handle(self::request($path, $query));
    }

    /**
     * The keys of the communities in the table, in the order shown.
     *
     * @return list<string>
     */
    private static function listed(string $html): array
    {
        preg_match_all('#<td><a href="/communities/([^"?]+)">#', $html, $matches);

        return $matches[1];
    }

    public function testListOfAllCommunitiesOfTheLatestRun(): void
    {
        $html = $this->assertValidHtml($this->get('/communities'));
        $keys = self::listed($html);

        $this->assertCount(17, $keys, '16 of the reference world and the one with the page only');
        $this->assertContains('nl-city', $keys);
        $this->assertContains('pagey', $keys);
        $this->assertStringContainsString('17 communities in run <a href="/runs/2">#2</a>', $html);
        $this->assertStringContainsString('<svg class="spark"', $html, 'trend over the runs');
    }

    public function testFilters(): void
    {
        $noData = self::listed($this->assertValidHtml($this->get('/communities', ['outcome' => 'no-data'])));
        $this->assertContains('dead-town', $noData);
        $this->assertContains('hop-town', $noData);
        $this->assertNotContains('nl-city', $noData);

        $fixed = self::listed($this->assertValidHtml($this->get('/communities', ['origin' => 'fixed'])));
        $this->assertSame(3, count($fixed));
        $this->assertSame(['ff_fixed_dead', 'ff_fixed_flat', 'ff_fixed_nl'], $fixed);

        $this->assertSame(['hop-town'], self::listed($this->assertValidHtml($this->get('/communities', ['q' => 'HOP']))));
    }

    public function testSortByNodes(): void
    {
        $keys = self::listed($this->assertValidHtml($this->get('/communities', ['sort' => 'nodes'])));

        $this->assertContains($keys[0], ['ff_fixed_flat', 'flat-town'], 'the communities with the most nodes (6 each) come first');
        $this->assertContains(end($keys), ['dead-town', 'netmon-old', 'hop-town', 'ff_fixed_dead', 'pagey', 'skipped-town', 'broken-api', 'no-maps']);
    }

    public function testNonsenseFilterValuesAreIgnored(): void
    {
        $html = $this->assertValidHtml($this->get('/communities', ['outcome' => "x' OR 1=1 --", 'origin' => ['array'], 'q' => ['a'], 'sort' => '<script>']));

        $this->assertCount(17, self::listed($html));
    }

    public function testTheFilterFormKeepsWhatWasAsked(): void
    {
        $html = $this->assertValidHtml($this->get('/communities', ['outcome' => 'no-data', 'q' => 'a"b']));

        $this->assertStringContainsString('<option value="no-data" selected>no-data</option>', $html);
        $this->assertStringContainsString('value="a&quot;b"', $html);
    }

    public function testACommunityThatDelivers(): void
    {
        $response = $this->get('/communities/nl-city');
        $html = $this->assertValidHtml($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>Freifunk nl-city</h1>', $html);
        $this->assertStringContainsString('nodelist, meshviewer', $html);
        $this->assertStringContainsString('<a href="https://nl.test/nodelist.json" rel="noopener noreferrer nofollow" target="_blank">', $html);
        $this->assertStringNotContainsString('Dry for', $html);
        $this->assertStringContainsString('History (last 2 runs)', $html);
        $this->assertStringContainsString('Account of the run for this community', $html);
    }

    public function testACommunityThatWentDryShowsSinceWhen(): void
    {
        $html = $this->assertValidHtml($this->get('/communities/hop-town'));

        $this->assertStringContainsString('Dry for 1 run.', $html);
        $this->assertStringContainsString('It last delivered nodes in run <a href="/runs/1">#1</a>', $html);
        $this->assertStringContainsString('What was tried', $html);
        $this->assertStringContainsString('The server answers, but there is nothing at the known places.', $html);
    }

    public function testAMapWithAPageButNoDataIsNamedAsSuch(): void
    {
        $html = $this->assertValidHtml($this->get('/communities/pagey'));

        $this->assertStringContainsString('A page answers, but no data was found behind it.', $html);
        $this->assertStringContainsString('<h1>&lt;img src=x onerror=alert(1)&gt;</h1>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function testACommunityThatNeverDelivered(): void
    {
        $html = $this->assertValidHtml($this->get('/communities/dead-town'));

        $this->assertStringContainsString('never delivered nodes in the recorded history', $html);
    }

    public function testUnknownOrOddKeysAreNotFound(): void
    {
        foreach (['/communities/nope', '/communities/a%20b', '/communities/..%2Fetc', '/communities/a/b'] as $path) {
            $this->assertSame(404, $this->get($path)->getStatusCode(), $path);
        }
    }

    public function testUrlsFromTheOutsideAreOnlyLinksIfTheyAreHttp(): void
    {
        $this->assertSame('<a href="https://x.test/a?b=1&amp;c=2" rel="noopener noreferrer nofollow" target="_blank">https://x.test/a?b=1&amp;c=2</a>', Html::externalLink('https://x.test/a?b=1&c=2')->html);
        $this->assertSame('<a href="HTTP://x.test/" rel="noopener noreferrer nofollow" target="_blank">label</a>', Html::externalLink('HTTP://x.test/', 'label')->html);

        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>', 'ftp://x.test/', 'x.test/path', ''] as $url) {
            $this->assertStringNotContainsString('<a ', Html::externalLink($url)->html, $url);
        }
    }

    public function testEventsDefaultToWarningsAndAlerts(): void
    {
        $html = $this->assertValidHtml($this->get('/events'));

        $this->assertStringContainsString('delivered 8 nodes last time and nothing now', $html);
        $this->assertStringContainsString('The run failed', $html);
        $this->assertStringNotContainsString('a small note', $html);
        $this->assertStringContainsString('<option value="warning" selected>warning</option>', $html);
    }

    public function testEventFilters(): void
    {
        $info = (string) $this->get('/events', ['severity' => 'info'])->getBody();
        $this->assertStringContainsString('a small note', $info);

        $alerts = (string) $this->get('/events', ['severity' => 'alert'])->getBody();
        $this->assertStringContainsString('The run failed', $alerts);
        $this->assertStringNotContainsString('delivered 8 nodes', $alerts);

        $kind = (string) $this->get('/events', ['severity' => 'info', 'kind' => 'community-lost'])->getBody();
        $this->assertStringContainsString('delivered 8 nodes', $kind);
        $this->assertStringNotContainsString('a small note', $kind);

        $unknown = $this->assertValidHtml($this->get('/events', ['severity' => 'catastrophe', 'kind' => 'made-up']));
        $this->assertStringContainsString('delivered 8 nodes', $unknown, 'unknown values fall back to the default');
    }

    public function testEventsLinkToTheirRunAndCommunityAndEscapeTheirText(): void
    {
        $this->repository->addEvents(2, [new DriftEvent(DriftEvent::NODES_DROPPED, DriftEvent::WARNING, 'nl-city', '<b>bold</b> & "quoted"')], new DateTimeImmutable('2026-10-28T12:00:40Z'));

        $html = $this->assertValidHtml($this->get('/events'));

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot; <a href="/communities/nl-city">open</a>', $html);
        $this->assertStringContainsString('<a href="/runs/2">#2</a>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
    }

    public function testEventPaging(): void
    {
        $events = [];

        for ($i = 0; $i < 120; $i++) {
            $events[] = new DriftEvent(DriftEvent::NODES_DROPPED, DriftEvent::WARNING, 'nl-city', 'drop number ' . $i);
        }

        $this->repository->addEvents(2, $events, new DateTimeImmutable('2026-10-28T12:00:40Z'));

        $first = $this->assertValidHtml($this->get('/events'));
        $this->assertStringContainsString('older →', $first);
        $this->assertStringContainsString('page=2', $first);
        $this->assertStringContainsString('drop number 119', $first);

        $second = $this->assertValidHtml($this->get('/events', ['page' => '2']));
        $this->assertStringContainsString('← newer', $second);
        $this->assertStringNotContainsString('drop number 119', $second);
    }
}
