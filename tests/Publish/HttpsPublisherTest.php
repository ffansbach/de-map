<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Publish;

use FreifunkKarte\Publish\HttpsPublisher;
use FreifunkKarte\Publish\RequestSigner;
use FreifunkKarte\Publish\ResultFiles;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/../../web/upload.php';

final class HttpsPublisherTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-at-least-thirty-two-characters-long';
    private const URL = 'https://www.example.org/upload.php';

    private string $target;
    private string $state;

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $history;

    protected function setUp(): void
    {
        $this->target = sys_get_temp_dir() . '/ffk_pub_target_' . bin2hex(random_bytes(6));
        $this->state = sys_get_temp_dir() . '/ffk_pub_state_' . bin2hex(random_bytes(6));
        mkdir($this->target);
    }

    protected function tearDown(): void
    {
        foreach ([$this->target, $this->state] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            @rmdir($directory);
        }
    }

    private static function files(): ResultFiles
    {
        $nodes = [];

        for ($i = 0; $i < 1100; $i++) {
            $nodes[] = ['id' => 'n' . $i, 'lat' => '50.1', 'long' => '8.1', 'name' => 'node-' . $i, 'community' => 'c' . ($i % 11), 'status' => 'online', 'clients' => 0];
        }

        $communities = [];

        for ($i = 0; $i < 11; $i++) {
            $communities['c' . $i] = ['name' => 'C' . $i, 'url' => 'https://c' . $i . '.example/', 'meta' => false];
        }

        return new ResultFiles([
            'result_routers.json' => (string) json_encode($nodes),
            'result_communities.json' => (string) json_encode($communities),
            'result_metacommunities.json' => '{}',
        ]);
    }

    /**
     * A client whose "network" is the real receiver code: the request is turned into what PHP would
     * hand the script, the answer comes back as the web server would send it.
     */
    private function clientToReceiver(\FfkUploadReceiver $receiver): Client
    {
        $handler = function (RequestInterface $request) use ($receiver) {
            $body = (string) $request->getBody();
            $server = [
                'REQUEST_METHOD' => $request->getMethod(),
                'REQUEST_URI' => $request->getUri()->getPath(),
                'CONTENT_LENGTH' => (string) strlen($body),
                'HTTPS' => $request->getUri()->getScheme() === 'https' ? 'on' : '',
                'HTTP_CONTENT_ENCODING' => $request->getHeaderLine('Content-Encoding'),
            ];

            foreach (['X-FFK-Timestamp', 'X-FFK-Nonce', 'X-FFK-Content-SHA256', 'X-FFK-Signature'] as $header) {
                $server['HTTP_' . strtoupper(str_replace('-', '_', $header))] = $request->getHeaderLine($header);
            }

            [$status, $answer] = $receiver->handle($server, fn (int $max): string => $body);

            return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($answer)));
        };

        return new Client(['handler' => $handler]);
    }

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function mockClient(array $queue): Client
    {
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $this->assertInstanceOf(\ArrayObject::class, $history);
        $this->history = $history;

        return new Client(['handler' => $stack]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sent(int $index): array
    {
        $entry = $this->history[$index] ?? null;
        $this->assertIsArray($entry);

        return $entry;
    }

    public function testTheSignerBuildsTheCanonicalStringTheReceiverChecks(): void
    {
        $headers = (new RequestSigner(self::SECRET))->headers('/upload.php', str_repeat('a', 64), 1_800_000_000, 'nonce-nonce-nonce-1');

        $this->assertSame("FFK-UPLOAD-V1\nPOST\n/upload.php\n1800000000\nnonce-nonce-nonce-1\n" . str_repeat('a', 64), RequestSigner::canonical('/upload.php', '1800000000', 'nonce-nonce-nonce-1', str_repeat('a', 64)));
        $this->assertSame('v1=' . hash_hmac('sha256', RequestSigner::canonical('/upload.php', '1800000000', 'nonce-nonce-nonce-1', str_repeat('a', 64)), self::SECRET), $headers['X-FFK-Signature']);
        $this->assertSame(['X-FFK-Timestamp', 'X-FFK-Nonce', 'X-FFK-Content-SHA256', 'X-FFK-Signature'], array_keys($headers));
    }

    public function testEveryRequestGetsANewNonceOfTheRightShape(): void
    {
        $signer = new RequestSigner(self::SECRET);

        $a = $signer->headers('/u', str_repeat('0', 64))['X-FFK-Nonce'];
        $b = $signer->headers('/u', str_repeat('0', 64))['X-FFK-Nonce'];

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16,128}$/', $a);
    }

    public function testTheRealReceiverAcceptsWhatThePublisherSends(): void
    {
        $receiver = new \FfkUploadReceiver(['secret' => self::SECRET, 'target' => $this->target, 'stateDir' => $this->state], null, static function (): void {
        });
        $files = self::files();

        $result = (new HttpsPublisher($this->clientToReceiver($receiver), self::URL, self::SECRET, 0))->publish($files);

        $this->assertTrue($result->ok, $result->message);
        $this->assertSame('installed 1100 nodes and 11 communities, ' . (int) round($result->bytesSent / 1024) . ' KB sent', $result->message);

        foreach ($files->files as $name => $content) {
            $this->assertSame($content, file_get_contents($this->target . '/' . $name), $name);
        }
    }

    public function testTheRealReceiverRefusesAWrongSecretAndTheMessageSaysWhereToLook(): void
    {
        $receiver = new \FfkUploadReceiver(['secret' => self::SECRET, 'target' => $this->target, 'stateDir' => $this->state], null, static function (): void {
        });

        $result = (new HttpsPublisher($this->clientToReceiver($receiver), self::URL, str_repeat('x', 40), 0))->publish(self::files());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('HTTP 401: unauthorized', $result->message);
        $this->assertStringContainsString('check the secret, the clocks', $result->message);
        $this->assertStringNotContainsString(str_repeat('x', 40), $result->message);
        $this->assertSame([], glob($this->target . '/result_*'));
    }

    public function testASecondUploadIsNotAReplay(): void
    {
        $receiver = new \FfkUploadReceiver(['secret' => self::SECRET, 'target' => $this->target, 'stateDir' => $this->state], null, static function (): void {
        });
        $publisher = new HttpsPublisher($this->clientToReceiver($receiver), self::URL, self::SECRET, 0);

        $this->assertTrue($publisher->publish(self::files())->ok);
        $this->assertTrue($publisher->publish(self::files())->ok, 'a new nonce every time');
    }

    public function testTheBodyIsGzippedJsonWithAllFilesAndTheRequestIsSignedForThePath(): void
    {
        $client = $this->mockClient([new Response(200, [], '{"ok":true,"routers":1100,"communities":11}')]);
        $files = self::files();

        (new HttpsPublisher($client, self::URL, self::SECRET, 0, fn (): int => 1_800_000_000))->publish($files);

        $request = $this->sent(0)['request'];
        $this->assertInstanceOf(RequestInterface::class, $request);
        $body = (string) $request->getBody();
        $this->assertSame('gzip', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame(hash('sha256', $body), $request->getHeaderLine('X-FFK-Content-SHA256'));
        $this->assertSame('1800000000', $request->getHeaderLine('X-FFK-Timestamp'));
        $this->assertSame('v1=' . hash_hmac('sha256', RequestSigner::canonical('/upload.php', '1800000000', $request->getHeaderLine('X-FFK-Nonce'), hash('sha256', $body)), self::SECRET), $request->getHeaderLine('X-FFK-Signature'));

        $envelope = json_decode((string) gzdecode($body), true);
        $this->assertSame(1, $envelope['version']);
        $this->assertSame($files->files, $envelope['files']);
        $this->assertLessThan($files->size() / 3, strlen($body), 'compressed');

        $options = $this->sent(0)['options'];
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertStringNotContainsString(self::SECRET, $body);
    }

    /**
     * @return array<string, array{Response|\Throwable, string}>
     */
    public static function failureProvider(): array
    {
        return [
            'rejected content' => [new Response(422, [], '{"ok":false,"error":"implausibly small (3 nodes, 1 communities)"}'), 'HTTP 422: implausibly small (3 nodes, 1 communities)'],
            'too large' => [new Response(413, [], '{"ok":false,"error":"too large"}'), 'HTTP 413: too large'],
            'the page of a proxy' => [new Response(502, [], "<html><body><h1>Bad\n  gateway</h1></body></html>"), 'HTTP 502: Bad gateway'],
            'not our script at all' => [new Response(200, [], '<html>welcome</html>'), 'HTTP 200: welcome'],
            'ok false' => [new Response(200, [], '{"ok":false,"error":"server error"}'), 'HTTP 200: server error'],
            'redirect' => [new Response(301, ['Location' => 'https://www.example.org/new/upload.php']), 'redirects to https://www.example.org/new/upload.php'],
            'no connection' => [new ConnectException('cURL error 6: Could not resolve host: www.example.org', new Request('POST', self::URL)), 'no answer: cURL error 6'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failureProvider')]
    public function testFailuresAreResultsNotExceptions(Response|\Throwable $answer, string $message): void
    {
        $client = $this->mockClient([$answer, $answer]);

        $result = (new HttpsPublisher($client, self::URL, self::SECRET, 0))->publish(self::files());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString($message, $result->message);
    }

    public function testAServerErrorIsTriedOnceMoreWithAFreshSignature(): void
    {
        $client = $this->mockClient([new Response(503), new Response(200, [], '{"ok":true,"routers":5,"communities":2}')]);

        $result = (new HttpsPublisher($client, self::URL, self::SECRET, 0))->publish(self::files());

        $this->assertTrue($result->ok);
        $this->assertCount(2, $this->history);
        $nonces = [$this->sent(0)['request']->getHeaderLine('X-FFK-Nonce'), $this->sent(1)['request']->getHeaderLine('X-FFK-Nonce')];
        $this->assertNotSame($nonces[0], $nonces[1], 'a retry is a new request, not a replay');
    }

    public function testTwoFailuresInARowAreAFailureAndNothingWorseIsTried(): void
    {
        $client = $this->mockClient([new Response(500), new Response(500), new Response(200, [], '{"ok":true}')]);

        $result = (new HttpsPublisher($client, self::URL, self::SECRET, 0))->publish(self::files());

        $this->assertFalse($result->ok);
        $this->assertCount(2, $this->history);
    }

    public function testAnAnswerThatCanNotHelpIsNotRetried(): void
    {
        foreach ([401, 422, 413, 301] as $status) {
            $client = $this->mockClient([new Response($status), new Response(200, [], '{"ok":true}')]);

            (new HttpsPublisher($client, self::URL, self::SECRET, 0))->publish(self::files());

            $this->assertCount(1, $this->history, (string) $status);
        }
    }

    public function testPlainHttpIsOnlyForTheMachineItself(): void
    {
        $this->assertSame('https www.example.org', (new HttpsPublisher(new Client(), self::URL, self::SECRET))->name());
        $this->assertSame('https 127.0.0.1', (new HttpsPublisher(new Client(), 'http://127.0.0.1:8080/upload.php', self::SECRET))->name());

        foreach (['http://www.example.org/upload.php', 'ftp://www.example.org/', 'www.example.org/upload.php'] as $url) {
            try {
                new HttpsPublisher(new Client(), $url, self::SECRET);
                $this->fail($url);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('must be https', $e->getMessage());
            }
        }
    }

    public function testAShortSecretIsRefusedUpFront(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 characters');

        new HttpsPublisher(new Client(), self::URL, 'too short');
    }
}
