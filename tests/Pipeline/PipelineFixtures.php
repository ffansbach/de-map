<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline;

use DateTimeImmutable;
use FreifunkKarte\Audit\Format;
use FreifunkKarte\Http\FetchResult;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Model\Node;
use FreifunkKarte\Model\NodeStatus;
use FreifunkKarte\Parser\ParseResult;
use FreifunkKarte\Pipeline\SourceEntry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Small builders for the unit tests of the pipeline classes.
 */
trait PipelineFixtures
{
    /** @var list<string> every URL requested through {@see fetcher()}, in order */
    private array $requested = [];

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-30T12:00:00Z');
    }

    private static function node(string $id, string $lat = '50.1'): Node
    {
        return new Node($id, 'node-' . $id, $lat, '8.1', NodeStatus::Online, 0);
    }

    /**
     * @param list<string> $ids
     */
    private static function nodes(array $ids, Format $format = Format::NodeInfo): ParseResult
    {
        return new ParseResult($format, array_map(self::node(...), $ids), count($ids));
    }

    private static function entry(string $community, string $url, string $type = 'meshviewer'): SourceEntry
    {
        return new SourceEntry($community, $type, $url);
    }

    private static function fetched(string $url, ?int $status = 200, ?string $error = null): FetchResult
    {
        return new FetchResult($url, $status, $error);
    }

    /**
     * A fetcher whose network is a map of URL => body (200), int (that HTTP status) or null (no answer).
     * Everything else is a 404.
     *
     * @param array<string, string|int|null> $answers
     */
    private function fetcher(array $answers): ParallelFetcher
    {
        $handler = function (RequestInterface $request) use ($answers) {
            $url = (string) $request->getUri();
            $this->requested[] = $url;
            $answer = array_key_exists($url, $answers) ? $answers[$url] : 404;

            return match (true) {
                $answer === null => Create::rejectionFor(new ConnectException('Could not resolve host', new Request('GET', $url), null, ['errno' => 6])),
                is_int($answer) => Create::promiseFor(new Response($answer)),
                default => Create::promiseFor(new Response(200, [], $answer)),
            };
        };

        return new ParallelFetcher(new Client(['handler' => $handler, 'http_errors' => false]), 8, retryWithoutVerification: false);
    }

    private static function nodelistBody(): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/sources/nodelist.json');
    }

    private static function nodeInfoBody(): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/sources/nodeinfo.json');
    }
}
