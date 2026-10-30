<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Publish;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../web/upload.php';

/**
 * The public endpoint on the web server. Every way a request can be wrong has a test.
 */
final class ReceiverTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-at-least-thirty-two-characters-long';
    private const NOW = 1_800_000_000;

    private string $target;
    private string $state;
    private int $now = self::NOW;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->target = sys_get_temp_dir() . '/ffk_target_' . bin2hex(random_bytes(6));
        $this->state = sys_get_temp_dir() . '/ffk_state_' . bin2hex(random_bytes(6));
        mkdir($this->target);
        $this->now = self::NOW;
        $this->log = [];
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

    /**
     * @param array<string, mixed> $config
     */
    private function receiver(array $config = []): \FfkUploadReceiver
    {
        return new \FfkUploadReceiver($config + ['secret' => self::SECRET, 'target' => $this->target, 'stateDir' => $this->state], fn (): int => $this->now, function (string $line): void {
            $this->log[] = $line;
        });
    }

    /**
     * @return array<string, string> the four result files, as the parser writes them
     */
    private static function files(int $routers = 1200, int $communities = 12): array
    {
        $nodes = [];

        for ($i = 0; $i < $routers; $i++) {
            $nodes[] = ['id' => 'n' . $i, 'lat' => '50.1', 'long' => '8.1', 'name' => 'node-' . $i, 'community' => 'c' . ($i % $communities), 'status' => 'online', 'clients' => 1];
        }

        $communityList = [];

        for ($i = 0; $i < $communities; $i++) {
            $communityList['c' . $i] = ['name' => 'Community ' . $i, 'url' => 'https://c' . $i . '.example/', 'meta' => false];
        }

        return [
            'result_routers.json' => (string) json_encode($nodes),
            'result_communities.json' => (string) json_encode($communityList),
            'result_metacommunities.json' => '{}',
        ];
    }

    /**
     * A correctly signed request. $override replaces header values or the body to build a wrong one.
     *
     * @param array<string, string> $files
     * @param array<string, mixed> $override
     * @return array{array<string, mixed>, callable, string}
     */
    private function request(array $files, array $override = []): array
    {
        $body = $override['body'] ?? (string) json_encode(['version' => 1, 'files' => $files]);

        if (($override['gzip'] ?? false) === true) {
            $body = (string) gzencode($body);
        }

        $timestamp = (string) ($override['timestamp'] ?? $this->now);
        $nonce = $override['nonce'] ?? bin2hex(random_bytes(16));
        $hash = $override['hash'] ?? hash('sha256', $body);
        $path = $override['signedPath'] ?? '/upload.php';
        $signature = $override['signature'] ?? 'v1=' . hash_hmac('sha256', implode("\n", ['FFK-UPLOAD-V1', 'POST', $path, $timestamp, $nonce, $hash]), $override['secret'] ?? self::SECRET);

        $server = [
            'REQUEST_METHOD' => $override['method'] ?? 'POST',
            'REQUEST_URI' => '/upload.php',
            'CONTENT_LENGTH' => (string) ($override['length'] ?? strlen($body)),
            'HTTPS' => $override['https'] ?? 'on',
            'HTTP_X_FFK_TIMESTAMP' => $timestamp,
            'HTTP_X_FFK_NONCE' => $nonce,
            'HTTP_X_FFK_CONTENT_SHA256' => $hash,
            'HTTP_X_FFK_SIGNATURE' => $signature,
        ];

        if (($override['gzip'] ?? false) === true) {
            $server['HTTP_CONTENT_ENCODING'] = 'gzip';
        }

        foreach ($override['drop'] ?? [] as $header) {
            unset($server[$header]);
        }

        return [$server, fn (int $max): string => $body, $body];
    }

    /**
     * @param array<string, mixed> $override
     * @param array<string, string>|null $files
     * @return array{int, array<string, mixed>}
     */
    private function send(array $override = [], ?array $files = null, ?\FfkUploadReceiver $receiver = null): array
    {
        [$server, $reader] = $this->request($files ?? self::files(), $override);

        return ($receiver ?? $this->receiver())->handle($server, $reader);
    }

    public function testTheDebugFileIsInstalledToo(): void
    {
        $files = self::files() + ['result_debug.json' => '{"generated":"2026-10-30T12:00:00+00:00","communities":{}}'];

        [$status] = $this->send([], $files);

        $this->assertSame(200, $status);
        $this->assertSame($files['result_debug.json'], file_get_contents($this->target . '/result_debug.json'));
    }

    public function testASignedUploadIsInstalled(): void
    {
        $files = self::files();

        [$status, $answer] = $this->send([], $files);

        $this->assertSame(200, $status);
        $this->assertTrue($answer['ok']);
        $this->assertSame([1200, 12], [$answer['routers'], $answer['communities']]);

        foreach ($files as $name => $content) {
            $this->assertSame($content, file_get_contents($this->target . '/' . $name), $name);
        }

        $this->assertSame([], glob($this->target . '/*.staging'), 'no staging file is left behind');
    }

    public function testAGzippedUploadIsInstalledToo(): void
    {
        [$status, $answer] = $this->send(['gzip' => true]);

        $this->assertSame(200, $status);
        $this->assertSame(1200, $answer['routers']);
    }

    public function testExistingFilesAreReplaced(): void
    {
        file_put_contents($this->target . '/result_routers.json', '[]');

        $this->assertSame(200, $this->send()[0]);
        $this->assertNotSame('[]', file_get_contents($this->target . '/result_routers.json'));
    }

    public function testOnlyPostIsAccepted(): void
    {
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $this->assertSame(405, $this->send(['method' => $method])[0], $method);
        }

        $this->assertSame([], glob($this->target . '/result_*'));
    }

    public function testHttpIsRefusedUnlessSwitchedOff(): void
    {
        $this->assertSame(400, $this->send(['https' => ''])[0]);
        $this->assertSame(400, $this->send(['https' => 'off'])[0]);
        $this->assertSame(200, $this->send(['https' => ''], null, $this->receiver(['requireHttps' => false]))[0]);
    }

    public function testBehindATlsTerminatingProxyTheForwardedProtocolCanBeTrusted(): void
    {
        [$server, $reader] = $this->request(self::files(), ['https' => '']);
        $server['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertSame(400, $this->receiver()->handle($server, $reader)[0], 'not trusted by default');
        $this->assertSame(200, $this->receiver(['trustForwardedProto' => true])->handle($server, $reader)[0]);
    }

    public function testMissingOrMalformedHeadersAreUnauthorizedWithTheSameShortAnswer(): void
    {
        $cases = [
            ['drop' => ['HTTP_X_FFK_SIGNATURE']],
            ['drop' => ['HTTP_X_FFK_NONCE']],
            ['drop' => ['HTTP_X_FFK_TIMESTAMP']],
            ['drop' => ['HTTP_X_FFK_CONTENT_SHA256']],
            ['nonce' => 'short'],
            ['nonce' => 'has spaces and is long enough to pass the length'],
            ['hash' => 'not-a-hash'],
            ['timestamp' => 'yesterday'],
        ];

        foreach ($cases as $case) {
            [$status, $answer] = $this->send($case);

            $this->assertSame(401, $status, (string) json_encode($case));
            $this->assertSame(['ok' => false, 'error' => 'unauthorized'], $answer);
        }

        $this->assertSame([], glob($this->target . '/result_*'));
    }

    public function testAWrongSignatureIsUnauthorized(): void
    {
        $this->assertSame(401, $this->send(['secret' => 'another-secret-that-is-also-long-enough-1234'])[0]);
        $this->assertSame(401, $this->send(['signature' => 'v1=' . str_repeat('0', 64)])[0]);
        $this->assertSame(401, $this->send(['signature' => 'v2=' . str_repeat('0', 64)])[0]);
        $this->assertSame(401, $this->send(['signedPath' => '/other.php'])[0], 'a signature for another endpoint is worthless here');
        $this->assertSame([], glob($this->target . '/result_*'));
    }

    public function testTheReasonOfARefusalIsInTheLogButNotInTheAnswer(): void
    {
        [, $answer] = $this->send(['secret' => 'another-secret-that-is-also-long-enough-1234']);

        $this->assertSame('unauthorized', $answer['error']);
        $this->assertStringContainsString('wrong signature', implode("\n", $this->log));
        $this->assertStringNotContainsString(self::SECRET, implode("\n", $this->log));
    }

    public function testTheClockMayDifferByFiveMinutesButNotMore(): void
    {
        $this->assertSame(200, $this->send(['timestamp' => self::NOW - 299])[0]);
        $this->assertSame(200, $this->send(['timestamp' => self::NOW + 299])[0]);
        $this->assertSame(401, $this->send(['timestamp' => self::NOW - 301])[0]);
        $this->assertSame(401, $this->send(['timestamp' => self::NOW + 301])[0]);
        $this->assertStringContainsString('timestamp outside the window', implode("\n", $this->log));
    }

    public function testAReplayedRequestIsRefused(): void
    {
        [$server, $reader] = $this->request(self::files());
        $receiver = $this->receiver();

        $this->assertSame(200, $receiver->handle($server, $reader)[0]);
        $this->assertSame(401, $receiver->handle($server, $reader)[0], 'the very same request, once more');
        $this->assertStringContainsString('nonce used before', implode("\n", $this->log));
    }

    public function testASeparateReceiverObjectKnowsTheUsedNoncesToo(): void
    {
        [$server, $reader] = $this->request(self::files());

        $this->assertSame(200, $this->receiver()->handle($server, $reader)[0]);
        $this->assertSame(401, $this->receiver()->handle($server, $reader)[0], 'state is on disk, not in memory');
    }

    public function testWithoutAStateDirTheNoncesLiveNextToTheResultFilesNotInTheSharedTempDirectory(): void
    {
        $default = $this->target . '/.upload-state';

        try {
            $receiver = $this->receiver(['stateDir' => null]);
            [$server, $reader] = $this->request(self::files());

            $this->assertSame(200, $receiver->handle($server, $reader)[0]);
            $this->assertCount(1, glob($default . '/nonce-*') ?: []);
            $this->assertSame(401, $receiver->handle($server, $reader)[0], 'replay protection works with the default directory');
            $this->assertSame('0700', substr(sprintf('%o', fileperms($default)), -4));
        } finally {
            foreach (glob($default . '/*') ?: [] as $file) {
                unlink($file);
            }

            @rmdir($default);
        }
    }

    public function testOldNoncesAreForgottenButANewRequestStillWorks(): void
    {
        $receiver = $this->receiver();
        [$first] = $this->request(self::files(), ['nonce' => 'first-nonce-0123456789abcdef']);
        $receiver->handle($first, fn (): string => '');
        $this->assertCount(1, glob($this->state . '/nonce-*') ?: []);

        $this->now += 1200;
        $this->assertSame(200, $this->send([], null, $receiver)[0]);

        $this->assertCount(1, glob($this->state . '/nonce-*') ?: [], 'the old one is gone, the new one is there');
    }

    public function testABodyThatDoesNotMatchTheSignedHashIsRefused(): void
    {
        $original = (string) json_encode(['version' => 1, 'files' => self::files()]);
        $tampered = $original . ' ';
        $hashOfTheOriginal = hash('sha256', $original);

        [$status, $answer] = $this->send(['body' => $tampered, 'hash' => $hashOfTheOriginal]);

        $this->assertSame(400, $status);
        $this->assertSame('body does not match its hash', $answer['error']);
        $this->assertSame([], glob($this->target . '/result_*'));
    }

    public function testATamperedBodyWithAFittingHashButWithoutTheSecretIsRefused(): void
    {
        $tampered = (string) json_encode(['version' => 1, 'files' => self::files(1500)]);

        [$server, $reader] = $this->request(self::files(), []);
        $server['HTTP_X_FFK_CONTENT_SHA256'] = hash('sha256', $tampered);

        $this->assertSame(401, $this->receiver()->handle($server, fn (): string => $tampered)[0], 'the signature covered the other hash');
    }

    public function testTheBodyIsNotReadForAnUnsignedRequest(): void
    {
        $read = false;
        [$server] = $this->request(self::files(), ['signature' => 'v1=' . str_repeat('a', 64)]);

        $status = $this->receiver()->handle($server, function (int $max) use (&$read): string {
            $read = true;

            return '';
        })[0];

        $this->assertSame(401, $status);
        $this->assertFalse($read, 'nobody without the secret can make the script read megabytes');
    }

    public function testAnOversizedRequestIsRefusedBeforeAnythingIsRead(): void
    {
        $read = false;
        [$server] = $this->request(self::files(), ['length' => 50_000_000]);

        $status = $this->receiver()->handle($server, function (int $max) use (&$read): string {
            $read = true;

            return '';
        })[0];

        $this->assertSame(413, $status);
        $this->assertFalse($read);
    }

    public function testABodyThatTurnsOutLargerThanItsHeaderSaidIsRefused(): void
    {
        // the header promises 10 bytes, the signed body is far more than the limit
        [$status] = $this->send(['length' => 10], null, $this->receiver(['maxBytes' => 1000]));

        $this->assertSame(413, $status);
        $this->assertSame([], glob($this->target . '/result_*'));
    }

    public function testAGzipBombIsRefused(): void
    {
        $bomb = (string) gzencode(str_repeat('A', 5_000_000));

        [$status, $answer] = $this->send(['gzip' => true, 'body' => str_repeat('A', 5_000_000)], null, $this->receiver(['maxDecodedBytes' => 1_000_000]));

        $this->assertLessThan(5_000_000, strlen($bomb), 'small on the wire, large when unpacked');
        $this->assertSame(422, $status, 'a limit that is exceeded makes gzdecode fail: refused like broken gzip');
        $this->assertSame('body is not valid gzip', $answer['error']);
        $this->assertSame([], glob($this->target . '/result_*'));
    }

    public function testBrokenGzipAndBrokenJsonAreRefused(): void
    {
        [$server, $reader] = $this->request(self::files(), ['body' => 'definitely not gzip', 'gzip' => false]);
        $server['HTTP_CONTENT_ENCODING'] = 'gzip';

        $this->assertSame(422, $this->receiver()->handle($server, $reader)[0]);
        $this->assertSame(422, $this->send(['body' => '{not json'])[0]);
        $this->assertSame(422, $this->send(['body' => '"a string"'])[0]);
    }

    /**
     * @return array<string, array{callable(array<string, string>): array<string, mixed>, string}>
     */
    public static function badContentProvider(): array
    {
        return [
            'unknown protocol version' => [static fn (array $f): array => ['version' => 2, 'files' => $f], 'unknown protocol version'],
            'no files' => [static fn (array $f): array => ['version' => 1], 'no files'],
            'a file that must not be there' => [static fn (array $f): array => ['version' => 1, 'files' => $f + ['../../evil.php' => '[]']], 'unknown file'],
            'a file name with a path' => [static fn (array $f): array => ['version' => 1, 'files' => $f + ['sub/result_routers.json' => '[]']], 'unknown file'],
            'a file that is no text' => [static fn (array $f): array => ['version' => 1, 'files' => ['result_routers.json' => [1, 2]] + $f], 'is no text'],
            'routers that are not JSON' => [static fn (array $f): array => ['version' => 1, 'files' => ['result_routers.json' => '<?php evil();'] + $f], 'is not valid JSON'],
            'routers that are no nodes' => [static fn (array $f): array => ['version' => 1, 'files' => ['result_routers.json' => '[1, 2, 3]'] + $f], 'an entry is not a node'],
            'communities that are no communities' => [static fn (array $f): array => ['version' => 1, 'files' => ['result_communities.json' => '{"a": 5}'] + $f], 'an entry is not a community'],
            'a debug file without communities' => [static fn (array $f): array => ['version' => 1, 'files' => ['result_debug.json' => '{"generated":"x"}'] + $f], 'no communities'],
            'the retired statistics file' => [static fn (array $f): array => ['version' => 1, 'files' => $f + ['result_statistics.json' => '{}']], 'unknown file'],
            'the routers are missing' => [static fn (array $f): array => ['version' => 1, 'files' => array_diff_key($f, ['result_routers.json' => 1])], 'result_routers.json is missing'],
            'the communities are missing' => [static fn (array $f): array => ['version' => 1, 'files' => array_diff_key($f, ['result_communities.json' => 1])], 'result_communities.json is missing'],
        ];
    }

    /**
     * @param callable(array<string, string>): array<string, mixed> $envelope
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badContentProvider')]
    public function testContentThatIsNotAResultIsRefusedAndNothingIsInstalled(callable $envelope, string $message): void
    {
        file_put_contents($this->target . '/result_routers.json', '["old"]');

        [$status, $answer] = $this->send(['body' => (string) json_encode($envelope(self::files()))]);

        $this->assertSame(422, $status);
        $this->assertStringContainsString($message, $answer['error']);
        $this->assertSame('["old"]', file_get_contents($this->target . '/result_routers.json'), 'what was there stays');
        $this->assertFileDoesNotExist($this->target . '/result_communities.json');
    }

    public function testAnImplausiblySmallResultIsRefusedLikeDataPhpDid(): void
    {
        [$status, $answer] = $this->send([], self::files(routers: 999));
        $this->assertSame(422, $status);
        $this->assertStringContainsString('implausibly small (999 nodes, 12 communities)', $answer['error']);

        [$status, $answer] = $this->send([], self::files(communities: 9));
        $this->assertSame(422, $status);
        $this->assertStringContainsString('9 communities', $answer['error']);

        $this->assertSame(200, $this->send([], self::files(routers: 1000, communities: 10))[0], 'the limits themselves are fine');
    }

    public function testAResultThatShrunkByMoreThanHalfIsRefused(): void
    {
        file_put_contents($this->target . '/result_routers.json', (string) json_encode(array_fill(0, 3000, ['id' => 'x'])));

        [$status, $answer] = $this->send([], self::files(1400));
        $this->assertSame(422, $status);
        $this->assertStringContainsString('1400 nodes where there are 3000 now', $answer['error']);

        $this->assertSame(200, $this->send([], self::files(1600))[0], 'more than half of 3000 is fine');
        $this->assertSame(200, $this->send([], self::files(1100), $this->receiver(['maxShrink' => 0]))[0], 'the check can be switched off');
    }

    public function testWithoutASecretOrATargetTheScriptRefusesToWork(): void
    {
        foreach ([['secret' => 'too short'], ['secret' => ''], ['target' => '/no/such/directory']] as $config) {
            [$status, $answer] = $this->send([], null, $this->receiver($config));

            $this->assertSame(500, $status, (string) json_encode($config));
            $this->assertSame('not configured', $answer['error']);
        }
    }

    public function testAFailureWhileInstallingGivesAGenericErrorAndLeavesNoStagingFile(): void
    {
        // a directory where a result file should go makes the final rename fail
        mkdir($this->target . '/result_metacommunities.json');

        [$status, $answer] = $this->send();

        $this->assertSame(500, $status);
        $this->assertSame('server error', $answer['error']);
        $this->assertStringNotContainsString($this->target, json_encode($answer) ?: '');
        $this->assertStringContainsString('cannot replace', implode("\n", $this->log));
        $this->assertSame([], glob($this->target . '/*.staging'));

        rmdir($this->target . '/result_metacommunities.json');
    }

    public function testOptionalFilesMayBeLeftOut(): void
    {
        $files = array_diff_key(self::files(), ['result_metacommunities.json' => 1]);

        $this->assertSame(200, $this->send([], $files)[0]);
        $this->assertFileDoesNotExist($this->target . '/result_metacommunities.json');
    }
}
