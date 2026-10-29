<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Http;

use FreifunkKarte\Http\ParallelFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ParallelFetcherTest extends TestCase
{
    /** @var \ArrayObject<int, array<string, mixed>> requests sent, recorded by Guzzle's history middleware */
    private \ArrayObject $history;

    /**
     * @return array<string, mixed> request and options of the n-th request sent
     */
    private function sent(int $index): array
    {
        $entry = $this->history[$index] ?? null;
        $this->assertIsArray($entry);

        return $entry;
    }

    /**
     * @param list<Response|\Throwable> $queue
     * @param list<string> $insecureHosts
     */
    private function fetcher(array $queue, array $insecureHosts = [], bool $retry = true): ParallelFetcher
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        // Guzzle takes the container by reference, which widens its type for static analysis
        $this->assertInstanceOf(\ArrayObject::class, $history);
        $this->history = $history;

        return new ParallelFetcher(new Client(['handler' => $stack]), concurrency: 1, insecureHosts: $insecureHosts, retryWithoutVerification: $retry);
    }

    public function testBodyCallbackOnlyFor200(): void
    {
        $fetcher = $this->fetcher([new Response(200, [], 'ok-body'), new Response(404, [], 'missing')]);
        $bodies = [];

        $results = $fetcher->fetch(['https://a.test/', 'https://b.test/'], function (string $url, string $body) use (&$bodies): void {
            $bodies[$url] = $body;
        });

        $this->assertSame(['https://a.test/' => 'ok-body'], $bodies);
        $this->assertTrue($results['https://a.test/']->isOk());
        $this->assertSame(7, $results['https://a.test/']->bytes);
        $this->assertSame(404, $results['https://b.test/']->status);
        $this->assertFalse($results['https://b.test/']->isOk());
    }

    public function testDuplicateUrlsAreFetchedOnce(): void
    {
        $fetcher = $this->fetcher([new Response(200, [], 'x')]);

        $results = $fetcher->fetch(['https://a.test/', 'https://a.test/'], static function (): void {
        });

        $this->assertCount(1, $results);
        $this->assertCount(1, $this->history);
    }

    public function testCertificateProblemIsRetriedWithoutVerificationAndFlagged(): void
    {
        $url = 'https://badcert.test/';
        $fetcher = $this->fetcher([
            new RequestException('cURL error 60: SSL certificate problem: certificate has expired', new Request('GET', $url), null, null, ['errno' => 60]),
            new Response(200, [], 'content'),
        ]);
        $bodies = [];

        $results = $fetcher->fetch([$url], function (string $u, string $body) use (&$bodies): void {
            $bodies[] = $body;
        });

        $this->assertSame(['content'], $bodies);
        $this->assertTrue($results[$url]->tlsInvalid);
        $this->assertTrue($results[$url]->isOk());
        $this->assertCount(2, $this->history);
        $this->assertTrue($this->sent(0)['options']['verify']);
        $this->assertFalse($this->sent(1)['options']['verify']);
    }

    public function testOtherConnectionErrorsAreNotRetried(): void
    {
        $url = 'https://down.test/';
        $fetcher = $this->fetcher([
            new ConnectException('Could not resolve host: down.test', new Request('GET', $url), null, ['errno' => 6]),
        ]);

        $results = $fetcher->fetch([$url], static function (): void {
        });

        $this->assertNull($results[$url]->status);
        $this->assertFalse($results[$url]->tlsInvalid);
        $this->assertStringContainsString('Could not resolve host', (string) $results[$url]->error);
        $this->assertCount(1, $this->history);
    }

    public function testRequestsAreBoundedAndIdentified(): void
    {
        $fetcher = $this->fetcher([new Response(200, [], '{}')]);

        $fetcher->fetch(['https://a.test/'], static function (): void {
        });

        $options = $this->sent(0)['options'];
        $this->assertSame(3, $options['allow_redirects']['max']);
        $this->assertSame(['http', 'https'], $options['allow_redirects']['protocols']);
        $this->assertFalse($options['http_errors']);
        $this->assertStringContainsString('freifunk-karte', $this->sent(0)['request']->getHeaderLine('User-Agent'));
    }

    public function testTransferAbortedBySizeLimitIsReportedAsTooLarge(): void
    {
        $url = 'https://huge.test/';
        $fetcher = $this->fetcher([
            new RequestException('cURL error 42: Callback aborted', new Request('GET', $url), null, null, ['errno' => 42]),
        ]);

        $results = $fetcher->fetch([$url], static function (): void {
        });

        $this->assertNull($results[$url]->status);
        $this->assertStringStartsWith('response larger than ', (string) $results[$url]->error);
        $this->assertCount(1, $this->history);
    }

    public function testRequestsAreSpreadAcrossHosts(): void
    {
        $fetcher = $this->fetcher([new Response(200), new Response(200), new Response(200), new Response(200)]);

        $fetcher->fetch(['https://a.test/1', 'https://a.test/2', 'https://a.test/3', 'https://b.test/1'], static function (): void {
        });

        $sent = array_map(fn (int $i): string => (string) $this->sent($i)['request']->getUri(), [0, 1, 2, 3]);
        $this->assertSame(['https://a.test/1', 'https://b.test/1', 'https://a.test/2', 'https://a.test/3'], $sent);
    }

    public function testKnownBrokenHostIsFetchedWithoutVerificationFromTheStart(): void
    {
        $fetcher = $this->fetcher([new Response(200, [], 'x'), new Response(200, [], 'y')], ['broken.test']);

        $results = $fetcher->fetch(['https://Broken.test/a', 'https://fine.test/b'], static function (): void {
        });

        $verify = [];

        foreach ([0, 1] as $i) {
            $verify[(string) $this->sent($i)['request']->getUri()] = $this->sent($i)['options']['verify'];
        }

        $this->assertSame(['https://fine.test/b' => true, 'https://broken.test/a' => false], $verify);
        $this->assertTrue($results['https://Broken.test/a']->tlsInvalid);
        $this->assertFalse($results['https://fine.test/b']->tlsInvalid);
    }

    public function testCertificateProblemWithoutRetryStaysAFailure(): void
    {
        $url = 'https://badcert.test/';
        $fetcher = $this->fetcher([
            new RequestException('cURL error 60: SSL certificate problem', new Request('GET', $url), null, null, ['errno' => 60]),
        ], retry: false);
        $bodies = 0;

        $results = $fetcher->fetch([$url], function () use (&$bodies): void {
            $bodies++;
        });

        $this->assertSame(0, $bodies);
        $this->assertNull($results[$url]->status);
        $this->assertTrue($results[$url]->tlsInvalid, 'flags that the certificate was the reason');
        $this->assertCount(1, $this->history);
    }

    public function testNoHostGetsMoreRequestsInFlightThanTheLimit(): void
    {
        $inFlight = [];
        $peak = [];
        $peakTotal = 0;

        // every request stays "in flight" until the fetcher waits for it
        $handler = function (RequestInterface $request) use (&$inFlight, &$peak, &$peakTotal) {
            $host = $request->getUri()->getHost();
            $inFlight[$host] = ($inFlight[$host] ?? 0) + 1;
            $peak[$host] = max($peak[$host] ?? 0, $inFlight[$host]);
            $peakTotal = max($peakTotal, array_sum($inFlight));

            $promise = null;
            $promise = new Promise(function () use (&$promise, $host, &$inFlight): void {
                $inFlight[$host]--;
                $promise?->resolve(new Response(200, [], 'x'));
            });

            return $promise;
        };

        $urls = [];

        foreach (range(1, 12) as $i) {
            $urls[] = 'https://busy.test/' . $i;
        }

        foreach (range(1, 4) as $i) {
            $urls[] = 'https://other.test/' . $i;
            $urls[] = 'https://third' . $i . '.test/x';
        }

        $fetcher = new ParallelFetcher(new Client(['handler' => $handler]), concurrency: 8, perHost: 2);
        $results = $fetcher->fetch($urls, static function (): void {
        });

        $this->assertCount(count($urls), $results);
        $this->assertSame(2, $peak['busy.test']);
        $this->assertSame(2, $peak['other.test']);
        $this->assertGreaterThan(4, $peakTotal, 'other hosts are fetched in parallel meanwhile');
    }
}
