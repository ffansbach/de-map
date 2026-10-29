<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use DateTimeImmutable;
use DOMDocument;
use FreifunkKarte\Admin\AdminApp;
use FreifunkKarte\Admin\AdminConfig;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Tests\Legacy\StandardWorld;
use FreifunkKarte\Tests\Run\RunFixtures;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

abstract class AdminTestCase extends TestCase
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    protected static function request(string $path, array $query = [], string $method = 'GET', string $remote = '127.0.0.1', array $headers = []): ServerRequest
    {
        return (new ServerRequest($method, $path, $headers, null, '1.1', ['REMOTE_ADDR' => $remote]))->withQueryParams($query);
    }

    /**
     * @param list<array{string, Page}> $routes
     */
    protected static function buildApp(RunRepository $repository, array $routes, ?AdminConfig $config = null): AdminApp
    {
        return new AdminApp($repository, $config ?? new AdminConfig(), $routes);
    }

    protected function assertValidHtml(ResponseInterface $response): string
    {
        $html = (string) $response->getBody();
        $this->assertStringStartsWith('<!doctype html>', $html);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument();
        $document->loadHTML($html);
        // 801 = "unknown tag": the HTML4 parser does not know nav, main, figure, svg ... - everything else is a real problem
        $problems = array_filter(libxml_get_errors(), static fn (\LibXMLError $e): bool => $e->code !== 801);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertSame([], array_map(static fn (\LibXMLError $e): string => trim($e->message) . ' (line ' . $e->line . ')', $problems), 'the page is well-formed HTML');

        return $html;
    }

    /**
     * Run 1: normal. Run 2: a bad day with a map that only has a page, and a community named like an attack.
     * Run 3: failed.
     */
    protected function seedHistory(RunRepository $repository, bool $withFailedRun = true): void
    {
        $r = $repository;
        $r->finish($r->start(new DateTimeImmutable('2026-10-27T12:00:00Z'), '/srv/out'), RunFixtures::standard(), RunRepository::OK, new DateTimeImmutable('2026-10-27T12:00:30Z'));

        $second = $r->start(new DateTimeImmutable('2026-10-28T12:00:00Z'), '/srv/out');
        $world = StandardWorld::build()
            ->route('https://hop.test/a/nodes.json', 'not json')
            ->route('https://hop.test/b/nodes.json', 'not json')
            ->community('pagey', ['name' => '<img src=x onerror=alert(1)>', 'nodeMaps' => [['technicalType' => 'meshviewer', 'url' => 'https://pagey.test/map/']]])
            ->route('https://pagey.test/map/', '<html>a viewer without data</html>');
        $r->finish($second, RunFixtures::standard($world), RunRepository::OK, new DateTimeImmutable('2026-10-28T12:00:30Z'));
        $r->addEvents($second, [
            new DriftEvent(DriftEvent::COMMUNITY_LOST, DriftEvent::WARNING, 'hop-town', '"Freifunk hop-town" delivered 8 nodes last time and nothing now'),
            new DriftEvent(DriftEvent::NEW_WARNING, DriftEvent::INFO, 'nl-city', 'a small note'),
            new DriftEvent(DriftEvent::RUN_NODES_DROPPED, DriftEvent::ALERT, 'run', 'The run found 32 nodes, the one before 40'),
        ], new DateTimeImmutable('2026-10-28T12:00:30Z'));

        if ($withFailedRun) {
            $third = $r->start(new DateTimeImmutable('2026-10-29T08:00:00Z'), '/srv/out');
            $r->fail($third, 'RuntimeException: Community directory not available', new DateTimeImmutable('2026-10-29T08:00:10Z'));
            $r->addEvents($third, [new DriftEvent(DriftEvent::RUN_FAILED, DriftEvent::ALERT, 'run', 'The run failed: Community directory not available')], new DateTimeImmutable('2026-10-29T08:00:10Z'));
        }
    }
}
