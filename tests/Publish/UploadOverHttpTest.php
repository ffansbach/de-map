<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Publish;

use FreifunkKarte\Command\UploadCommand;
use FreifunkKarte\Publish\RequestSigner;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The real thing: PHP's web server running web/upload.php, the `upload` command as the client.
 */
final class UploadOverHttpTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-at-least-thirty-two-characters-long';

    private string $directory;

    /** @var resource|null */
    private $server = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffk_upload_http_' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/target', 0777, true);
        mkdir($this->directory . '/out');
        file_put_contents($this->directory . '/target/result_routers.json', '[]');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        $this->remove($this->directory);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                is_dir($path . '/' . $entry) ? $this->remove($path . '/' . $entry) : unlink($path . '/' . $entry);
            }
        }

        rmdir($path);
    }

    private function start(bool $withConfig = true): void
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            $this->markTestSkipped('Cannot open a local port: ' . $error);
        }

        $name = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($socket);

        $config = $this->directory . '/receiver.config.php';

        if ($withConfig) {
            file_put_contents($config, '<?php return ' . var_export([
                'secret' => self::SECRET,
                'target' => $this->directory . '/target',
                'stateDir' => $this->directory . '/state',
                'requireHttps' => false,
                'minCommunities' => 10,
                'minRouters' => 1000,
            ], true) . ';');
        }

        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $root . '/web', $root . '/web/upload.php'],
            [['pipe', 'r'], ['file', $this->directory . '/server.log', 'a'], ['file', $this->directory . '/server.log', 'a']],
            $pipes,
            $root,
            ['FFK_UPLOAD_CONFIG' => $config, 'PATH' => (string) getenv('PATH')],
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

    private function writeResult(): void
    {
        $nodes = [];

        for ($i = 0; $i < 1100; $i++) {
            $nodes[] = ['id' => 'n' . $i, 'lat' => '50.1', 'long' => '8.1', 'name' => 'node-' . $i, 'community' => 'c' . ($i % 11), 'status' => 'online', 'clients' => 0];
        }

        $communities = [];

        for ($i = 0; $i < 11; $i++) {
            $communities['c' . $i] = ['name' => 'C' . $i, 'url' => 'https://c' . $i . '.example/', 'meta' => false];
        }

        file_put_contents($this->directory . '/out/result_routers.json', (string) json_encode($nodes));
        file_put_contents($this->directory . '/out/result_communities.json', (string) json_encode($communities));
        file_put_contents($this->directory . '/out/result_metacommunities.json', '{}');
    }

    private function upload(string $secret = self::SECRET): CommandTester
    {
        file_put_contents($this->directory . '/upload.json', (string) json_encode(['publishers' => [
            ['type' => 'https', 'url' => 'http://127.0.0.1:' . $this->port . '/upload.php', 'secret' => $secret],
        ]]));

        $tester = new CommandTester(new UploadCommand(null, $this->directory));
        $tester->execute(['--from' => $this->directory . '/out', '--upload-config' => $this->directory . '/upload.json']);

        return $tester;
    }

    public function testTheFilesArriveAndTheOldOnesAreReplaced(): void
    {
        $this->start();
        $this->writeResult();

        $tester = $this->upload();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('installed 1100 nodes and 11 communities', $tester->getDisplay());

        foreach (['result_routers.json', 'result_communities.json', 'result_metacommunities.json'] as $name) {
            $this->assertFileEquals($this->directory . '/out/' . $name, $this->directory . '/target/' . $name);
        }
    }

    public function testAWrongSecretChangesNothing(): void
    {
        $this->start();
        $this->writeResult();

        $tester = $this->upload(str_repeat('x', 40));

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('HTTP 401', $tester->getDisplay());
        $this->assertSame('[]', file_get_contents($this->directory . '/target/result_routers.json'));
        $this->assertFileDoesNotExist($this->directory . '/target/result_communities.json');
    }

    public function testAReceiverWithoutConfigurationRefusesEverything(): void
    {
        $this->start(withConfig: false);
        $this->writeResult();

        $tester = $this->upload();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame('[]', file_get_contents($this->directory . '/target/result_routers.json'));
        $this->assertFileDoesNotExist($this->directory . '/target/result_communities.json');
    }

    public function testTheSameSignedRequestCannotBeSentTwice(): void
    {
        $this->start();
        $body = (string) gzencode((string) json_encode(['version' => 1, 'files' => ['result_routers.json' => '[]']]));
        $headers = (new RequestSigner(self::SECRET))->headers('/upload.php', hash('sha256', $body), time(), 'nonce-' . bin2hex(random_bytes(8))) + ['Content-Encoding' => 'gzip'];
        $client = new Client(['http_errors' => false, 'base_uri' => 'http://127.0.0.1:' . $this->port]);

        $first = $client->post('/upload.php', ['body' => $body, 'headers' => $headers]);
        $second = $client->post('/upload.php', ['body' => $body, 'headers' => $headers]);

        $this->assertNotSame(401, $first->getStatusCode(), 'the first one is authentic (it is then judged on its content)');
        $this->assertSame(401, $second->getStatusCode(), 'the replay is refused as unauthorized');
        $this->assertSame('{"ok":false,"error":"unauthorized"}', (string) $second->getBody());
    }

    public function testARequestSignedWithAWrongClockIsRefused(): void
    {
        $this->start();
        $body = (string) gzencode('{}');
        $headers = (new RequestSigner(self::SECRET))->headers('/upload.php', hash('sha256', $body), time() - 3600) + ['Content-Encoding' => 'gzip'];
        $client = new Client(['http_errors' => false, 'base_uri' => 'http://127.0.0.1:' . $this->port]);

        $response = $client->post('/upload.php', ['body' => $body, 'headers' => $headers]);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testAGetRequestAndAnUnsignedPostAreRefused(): void
    {
        $this->start();
        $client = new Client(['http_errors' => false, 'base_uri' => 'http://127.0.0.1:' . $this->port]);

        $this->assertGreaterThanOrEqual(400, $client->get('/upload.php')->getStatusCode());
        $this->assertSame(401, $client->post('/upload.php', ['body' => 'x'])->getStatusCode());
    }
}
