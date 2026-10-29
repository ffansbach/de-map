<?php

declare(strict_types=1);

namespace FreifunkKarte\Audit;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Fetches many URLs concurrently. Certificates are verified; URLs that fail
 * only because of the certificate are retried once without verification and
 * flagged, so we learn which sources have broken TLS.
 */
final class ParallelFetcher
{
    /** curl errnos that mean "TLS/certificate problem" */
    private const TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly int $concurrency = 8,
        private readonly int $maxBytes = 25_000_000,
        private readonly float $timeout = 20.0,
        private readonly float $connectTimeout = 5.0,
    ) {
    }

    /**
     * @param list<string> $urls
     * @param callable(string, string): void $onBody called with url and body for every HTTP 200 response
     * @return array<string, FetchResult> keyed by url
     */
    public function fetch(array $urls, callable $onBody): array
    {
        $urls = array_values(array_unique($urls));
        $results = $this->pass($urls, true, $onBody);

        $tlsFailed = array_keys(array_filter(
            $results,
            static fn (FetchResult $r): bool => $r->tlsInvalid,
        ));

        if ($tlsFailed !== []) {
            $results = [...$results, ...$this->pass($tlsFailed, false, $onBody)];
        }

        return $results;
    }

    /**
     * @param list<string> $urls
     * @param callable(string, string): void $onBody
     * @return array<string, FetchResult>
     */
    private function pass(array $urls, bool $verify, callable $onBody): array
    {
        /** @var array<string, FetchResult> $results */
        $results = [];
        /** @var array<string, float> $seconds */
        $seconds = [];

        $requests = function () use ($urls, $verify, &$seconds): \Generator {
            foreach ($urls as $url) {
                yield $url => fn (): PromiseInterface => $this->client->requestAsync('GET', $url, [
                    'verify' => $verify,
                    'http_errors' => false,
                    'timeout' => $this->timeout,
                    'connect_timeout' => $this->connectTimeout,
                    'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
                    'headers' => ['User-Agent' => 'freifunk-karte source audit (+https://www.freifunk-karte.de/)'],
                    // returning non-zero aborts only this transfer (throwing would abort all running ones)
                    'curl' => [
                        CURLOPT_NOPROGRESS => false,
                        CURLOPT_PROGRESSFUNCTION => fn ($handle, $downloadTotal, $downloaded): int => $downloaded > $this->maxBytes ? 1 : 0,
                    ],
                    'on_stats' => static function (TransferStats $stats) use ($url, &$seconds): void {
                        $seconds[$url] = (float) $stats->getTransferTime();
                    },
                ]);
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function (ResponseInterface $response, string $url) use (&$results, &$seconds, $verify, $onBody): void {
                $body = (string) $response->getBody();
                $status = $response->getStatusCode();

                if ($status === 200) {
                    $onBody($url, $body);
                }

                $results[$url] = new FetchResult($url, $status, null, strlen($body), $seconds[$url] ?? 0.0, !$verify);
            },
            'rejected' => function (Throwable $reason, string $url) use (&$results, &$seconds, $verify): void {
                $errno = $this->curlErrno($reason);
                // in the retry pass (no verification) every result stays flagged
                $tls = !$verify || in_array($errno, self::TLS_ERRNOS, true);
                $aborted = $errno === CURLE_ABORTED_BY_CALLBACK;

                $results[$url] = new FetchResult(
                    $url,
                    null,
                    $aborted ? 'response larger than ' . $this->maxBytes . ' bytes' : $this->shorten($reason->getMessage()),
                    0,
                    $seconds[$url] ?? 0.0,
                    $tls,
                );
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }

    /**
     * Guzzle wraps only connect-phase failures in ConnectException; certificate and
     * transfer errors arrive as RequestException. Both carry the curl errno.
     */
    private function curlErrno(Throwable $reason): int
    {
        if ($reason instanceof ConnectException || $reason instanceof RequestException) {
            return (int) ($reason->getHandlerContext()['errno'] ?? 0);
        }

        return 0;
    }

    private function shorten(string $message): string
    {
        $firstLine = strtok($message, "\n") ?: $message;

        return mb_strimwidth($firstLine, 0, 160, '…');
    }
}
