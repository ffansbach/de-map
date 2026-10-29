<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

use Closure;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Sends the result files to web/upload.php over HTTPS, signed, as one gzipped JSON document.
 * Certificates are verified. A plain-http URL is only accepted for the machine itself.
 */
final class HttpsPublisher implements Publisher
{
    private const ATTEMPTS = 2;

    private readonly RequestSigner $signer;
    private readonly string $path;

    /**
     * @param int $retryDelayMs wait before the second attempt (every attempt is signed anew)
     * @param Closure|null $clock for tests: returns the time of a request
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $url,
        string $secret,
        private readonly int $retryDelayMs = 3000,
        private readonly ?Closure $clock = null,
    ) {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);

        if (!isset($parts['scheme']) || ($parts['scheme'] !== 'https' && !($parts['scheme'] === 'http' && $loopback))) {
            throw new InvalidArgumentException('The upload URL must be https (plain http only for the machine itself)');
        }

        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The upload secret must have at least 32 characters (make one with `bin/parser upload:secret`)');
        }

        $this->signer = new RequestSigner($secret);
        $this->path = ($parts['path'] ?? '') !== '' ? $parts['path'] : '/';
    }

    public function name(): string
    {
        return 'https ' . (parse_url($this->url, PHP_URL_HOST) ?: '');
    }

    public function publish(ResultFiles $files): PublishResult
    {
        $started = microtime(true);
        $body = (string) gzencode((string) json_encode(['version' => 1, 'createdAt' => date('c'), 'files' => $files->files], JSON_UNESCAPED_SLASHES), 6);
        $message = '';

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            [$ok, $retry, $message] = $this->send($body);

            if ($ok || !$retry) {
                break;
            }

            if ($attempt < self::ATTEMPTS && $this->retryDelayMs > 0) {
                usleep($this->retryDelayMs * 1000);
            }
        }

        return new PublishResult($this->name(), $ok, $message, microtime(true) - $started, strlen($body));
    }

    /**
     * @return array{bool, bool, string} success, whether another attempt could help, and what to say
     */
    private function send(string $body): array
    {
        $timestamp = $this->clock !== null ? ($this->clock)() : time();

        try {
            $response = $this->client->request('POST', $this->url, [
                'body' => $body,
                'headers' => $this->signer->headers($this->path, hash('sha256', $body), $timestamp) + [
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                    'User-Agent' => 'freifunk-karte parser',
                ],
                'allow_redirects' => false,
                'http_errors' => false,
                'verify' => true,
                'connect_timeout' => 10,
                'timeout' => 120,
            ]);
        } catch (Throwable $e) {
            return [false, true, 'no answer: ' . $this->shorten($e->getMessage())];
        }

        $status = $response->getStatusCode();
        $answer = json_decode((string) $response->getBody(), true);
        $text = is_array($answer) && is_string($answer['error'] ?? null) ? $answer['error'] : $this->shorten(strip_tags((string) $response->getBody()));

        if ($status === 200 && is_array($answer) && ($answer['ok'] ?? false) === true) {
            return [true, false, sprintf('installed %d nodes and %d communities, %d KB sent', (int) ($answer['routers'] ?? 0), (int) ($answer['communities'] ?? 0), (int) round(strlen($body) / 1024))];
        }

        if ($status >= 300 && $status < 400) {
            return [false, false, sprintf('HTTP %d: the server redirects to %s - use that URL, a POST is not followed', $status, $response->getHeaderLine('Location') ?: 'somewhere else')];
        }

        return [false, $status >= 500 || $status === 429, sprintf('HTTP %d: %s%s', $status, $text, $status === 401 ? ' (check the secret, the clocks - they may differ by at most 5 minutes - and the URL path)' : '')];
    }

    private function shorten(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strimwidth($line, 0, 200, '…');
    }
}
