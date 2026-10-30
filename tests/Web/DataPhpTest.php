<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Web;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The real data.php under PHP's web server, with result files of its own: the response stays what the map
 * expects, and a browser that already has the current files gets an empty 304.
 */
final class DataPhpTest extends TestCase
{
    private string $directory;

    /** @var resource|null */
    private $server = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffk_data_http_' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/cache', 0777, true);
        copy(dirname(__DIR__, 2) . '/web/data.php', $this->directory . '/data.php');
        $this->writeResult('1');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        foreach (glob($this->directory . '/cache/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }

        rmdir($this->directory);
    }

    /**
     * How data.php writes a string: JSON with the hex flags, without the surrounding quotes.
     */
    private static function hexEscaped(string $text): string
    {
        return substr((string) json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), 1, -1);
    }

    private function writeResult(string $version): void
    {
        $routers = [['id' => 'a', 'lat' => '50.1', 'long' => '8.1', 'name' => 'node <' . $version . '> & "q"', 'community' => 'c', 'status' => 'online', 'clients' => 2]];
        $communities = ['c' => ['name' => 'C', 'url' => 'https://c.example/', 'meta' => false]];

        file_put_contents($this->directory . '/cache/result_routers.json', (string) json_encode($routers));
        file_put_contents($this->directory . '/cache/result_communities.json', (string) json_encode($communities));
        file_put_contents($this->directory . '/cache/result_metacommunities.json', '{}');
    }

    private function start(): void
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            $this->markTestSkipped('Cannot open a local port: ' . $error);
        }

        $name = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->directory],
            [['pipe', 'r'], ['file', $this->directory . '/server.log', 'a'], ['file', $this->directory . '/server.log', 'a']],
            $pipes,
            $this->directory,
        );

        if ($process === false) {
            $this->fail('Cannot start PHP\'s web server');
        }

        $this->server = $process;

        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        $this->fail('The web server did not start: ' . (string) @file_get_contents($this->directory . '/server.log'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(array $headers = [], string $method = 'GET'): ResponseInterface
    {
        return (new Client(['http_errors' => false, 'allow_redirects' => false, 'decode_content' => false]))
            ->request($method, 'http://127.0.0.1:' . $this->port . '/data.php', ['headers' => $headers]);
    }

    public function testAnswersWithTheThreeFilesInTheFormatTheMapReads(): void
    {
        $this->start();

        $response = $this->get();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('public, max-age=600', $response->getHeaderLine('Cache-Control'));

        $body = (string) $response->getBody();
        // the page has always received < > & ' \" as hex escapes
        $this->assertStringContainsString(self::hexEscaped('node <1> & "q"'), $body);
        $data = json_decode($body, true);
        $this->assertSame(['communities', 'allTheRouters', 'metaCommunities', 'isCachedresult'], array_keys($data));
        $this->assertTrue($data['isCachedresult']);
        $this->assertSame('c', $data['allTheRouters'][0]['community']);
        $this->assertSame('C', $data['communities']['c']['name']);
    }

    public function testSendsValidators(): void
    {
        $this->start();

        $response = $this->get();

        $this->assertMatchesRegularExpression('/^W\/"[0-9a-f]{32}"$/', $response->getHeaderLine('ETag'));
        $this->assertSame(
            gmdate('D, d M Y H:i:s', (int) filemtime($this->directory . '/cache/result_routers.json')) . ' GMT',
            $response->getHeaderLine('Last-Modified'),
        );
    }

    public function testAnswers304WithoutABodyWhenTheBrowserHasTheCurrentFiles(): void
    {
        $this->start();
        $etag = $this->get()->getHeaderLine('ETag');

        $response = $this->get(['If-None-Match' => $etag]);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame($etag, $response->getHeaderLine('ETag'));
        $this->assertSame('public, max-age=600', $response->getHeaderLine('Cache-Control'));
    }

    public function testRecognisesTheEtagAsProxiesAndBrowsersReturnIt(): void
    {
        $this->start();
        $etag = $this->get()->getHeaderLine('ETag');
        $bare = trim(substr($etag, 2), '"');

        foreach ([$etag, substr($etag, 2), 'W/"' . $bare . '-br"', '"' . $bare . '-gzip"', '"other", ' . $etag, '*'] as $sent) {
            $this->assertSame(304, $this->get(['If-None-Match' => $sent])->getStatusCode(), $sent);
        }

        foreach (['"something-else"', 'W/"' . md5('x') . '"'] as $sent) {
            $this->assertSame(200, $this->get(['If-None-Match' => $sent])->getStatusCode(), $sent);
        }
    }

    public function testIfModifiedSinceIsUsedWhenThereIsNoEtag(): void
    {
        $this->start();
        $modified = $this->get()->getHeaderLine('Last-Modified');
        $time = (int) strtotime($modified);

        $this->assertSame(304, $this->get(['If-Modified-Since' => $modified])->getStatusCode());
        $this->assertSame(304, $this->get(['If-Modified-Since' => gmdate('D, d M Y H:i:s', $time + 3600) . ' GMT'])->getStatusCode());
        $this->assertSame(200, $this->get(['If-Modified-Since' => gmdate('D, d M Y H:i:s', $time - 1) . ' GMT'])->getStatusCode());
        $this->assertSame(200, $this->get(['If-Modified-Since' => 'not a date'])->getStatusCode());
    }

    public function testIfNoneMatchWinsOverIfModifiedSince(): void
    {
        $this->start();
        $modified = $this->get()->getHeaderLine('Last-Modified');

        $response = $this->get(['If-None-Match' => '"stale"', 'If-Modified-Since' => $modified]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNewFilesFromTheNextRunInvalidateTheBrowsersCopy(): void
    {
        $this->start();
        $before = $this->get();
        $etag = $before->getHeaderLine('ETag');

        // the upload replaces the files: other content, other size, later time
        $this->writeResult('22');
        touch($this->directory . '/cache/result_routers.json', time() + 120);

        $after = $this->get(['If-None-Match' => $etag, 'If-Modified-Since' => $before->getHeaderLine('Last-Modified')]);

        $this->assertSame(200, $after->getStatusCode());
        $this->assertNotSame($etag, $after->getHeaderLine('ETag'));
        $this->assertStringContainsString(self::hexEscaped('node <22>'), (string) $after->getBody());
    }

    public function testWithoutResultFilesThereIsNothingToValidate(): void
    {
        unlink($this->directory . '/cache/result_routers.json');
        $this->start();

        $response = $this->get(['If-None-Match' => '*']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('ETag'));
        $this->assertFalse(json_decode((string) $response->getBody(), true)['allTheRouters']);
    }

    public function testHeadGetsTheHeadersAndNoBody(): void
    {
        $this->start();

        $response = $this->get([], 'HEAD');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame('', $response->getHeaderLine('ETag'));
        $this->assertSame('', (string) $response->getBody());
    }
}
