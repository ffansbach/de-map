<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use DOMDocument;
use FreifunkKarte\Admin\AdminApp;
use FreifunkKarte\Admin\AdminConfig;
use FreifunkKarte\Admin\Page;
use FreifunkKarte\Run\RunRepository;
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
}
