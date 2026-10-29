<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Tests\Run\RunFixtures;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * The real thing: PHP's web server, the front controller, a real HTTP client.
 */
final class AdminOverHttpTest extends TestCase
{
    private string $directory;

    /** @var resource|null */
    private $server = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffmap_admin_http_' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function start(array $config): Client
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            $this->markTestSkipped('Cannot open a local port: ' . $error);
        }

        $this->port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        file_put_contents($this->directory . '/admin.json', (string) json_encode($config));
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $root . '/public-admin', $root . '/public-admin/index.php'],
            [['pipe', 'r'], ['file', $this->directory . '/server.log', 'a'], ['file', $this->directory . '/server.log', 'a']],
            $pipes,
            $root,
            ['FFKARTE_ADMIN_CONFIG' => $this->directory . '/admin.json', 'PATH' => (string) getenv('PATH')],
        );

        if ($process === false) {
            $this->fail('Cannot start PHP\'s web server');
        }

        $this->server = $process;

        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return new Client(['base_uri' => 'http://127.0.0.1:' . $this->port, 'http_errors' => false, 'timeout' => 10]);
            }

            usleep(100_000);
        }

        $this->fail('The web server did not start: ' . (string) @file_get_contents($this->directory . '/server.log'));
    }

    private function history(): string
    {
        $file = $this->directory . '/parser.sqlite';
        $repository = RunRepository::open($file);
        $at = new \DateTimeImmutable('2026-10-29T12:00:00Z');
        $repository->finish($repository->start($at, '/out'), RunFixtures::standard(), RunRepository::OK, $at);

        return $file;
    }

    public function testTheWholeStackWithALogin(): void
    {
        $client = $this->start([
            'db' => $this->history(),
            'user' => 'tino',
            'passwordHash' => password_hash('correct horse battery', PASSWORD_DEFAULT),
            'failedLoginDelayMs' => 0,
        ]);
        $auth = ['auth' => ['tino', 'correct horse battery']];

        $anonymous = $client->get('/');
        $this->assertSame(401, $anonymous->getStatusCode());
        $this->assertStringContainsString('Basic realm=', $anonymous->getHeaderLine('WWW-Authenticate'));
        $this->assertStringContainsString("default-src 'none'", $anonymous->getHeaderLine('Content-Security-Policy'));

        $this->assertSame(401, $client->get('/', ['auth' => ['tino', 'wrong']])->getStatusCode());

        $overview = $client->get('/', $auth);
        $this->assertSame(200, $overview->getStatusCode());
        $this->assertStringContainsString('text/html', $overview->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Latest successful run', (string) $overview->getBody());
        $this->assertStringContainsString('40', (string) $overview->getBody());

        $this->assertSame(200, $client->get('/runs/1', $auth)->getStatusCode());
        $this->assertSame(200, $client->get('/communities/nl-city', $auth)->getStatusCode());
        $this->assertSame(404, $client->get('/nope', $auth)->getStatusCode());
        $this->assertSame(405, $client->post('/', $auth)->getStatusCode());
        $this->assertSame('GET, HEAD', $client->post('/', $auth)->getHeaderLine('Allow'));
    }

    public function testWithoutAHistoryTheAnswerIsGenericAndNamesNoPath(): void
    {
        $client = $this->start(['db' => $this->directory . '/does-not-exist.sqlite']);

        $response = $client->get('/');

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('not available yet', (string) $response->getBody());
        $this->assertStringNotContainsString($this->directory, (string) $response->getBody());
        $this->assertFileDoesNotExist($this->directory . '/does-not-exist.sqlite', 'the UI never creates the history');
    }

    public function testWithoutAPasswordTheMachineItselfIsLetIn(): void
    {
        $client = $this->start(['db' => $this->history()]);

        $this->assertSame(200, $client->get('/runs')->getStatusCode());
    }
}
