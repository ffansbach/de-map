<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Publish;

use FreifunkKarte\Publish\FtpConnection;
use FreifunkKarte\Publish\FtpPublisher;
use FreifunkKarte\Publish\ResultFiles;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FtpPublisherTest extends TestCase
{
    private static function files(): ResultFiles
    {
        return new ResultFiles(['result_routers.json' => '[{"id":"a"}]', 'result_communities.json' => '{"c":{}}', 'result_metacommunities.json' => '{}']);
    }

    private function server(bool $refuseOverwritingRename = false, ?string $failUploadOf = null, int $truncateBy = 0, bool $refuseLogin = false): FakeFtpServer
    {
        return new FakeFtpServer($refuseOverwritingRename, $failUploadOf, $truncateBy, $refuseLogin);
    }

    private function publisher(FakeFtpServer $server, string $password = 's3cret-password'): FtpPublisher
    {
        return new FtpPublisher(fn (): FtpConnection => $server, 'ftp.example.org', 'user', $password, '/cache/');
    }

    public function testFilesAreStagedThenPutInPlace(): void
    {
        $server = $this->server();

        $result = $this->publisher($server)->publish(self::files());

        $this->assertTrue($result->ok, $result->message);
        $this->assertSame('3 files, 0 KB sent', $result->message);
        $this->assertSame('[{"id":"a"}]', $server->files['/cache/result_routers.json']);
        $this->assertSame('{}', $server->files['/cache/result_metacommunities.json'], 'the metacommunities are delivered, too');
        $this->assertSame([], array_filter(array_keys($server->files), static fn (int|string $p): bool => str_ends_with((string) $p, '.staging')));
        $this->assertTrue($server->closed);
        $this->assertSame(['login', 'upload result_routers.json.staging', 'upload result_communities.json.staging', 'upload result_metacommunities.json.staging'], array_slice($server->log, 0, 4), 'everything is up before anything is renamed');
    }

    public function testAServerThatRefusesToRenameOverAFileGetsItDeletedFirst(): void
    {
        $server = $this->server(refuseOverwritingRename: true);

        $result = $this->publisher($server)->publish(self::files());

        $this->assertTrue($result->ok, $result->message);
        $this->assertSame('[{"id":"a"}]', $server->files['/cache/result_routers.json']);
        $this->assertContains('delete result_routers.json', $server->log);
    }

    public function testAFailedTransferLeavesTheOldFilesAndNoStagingFile(): void
    {
        $server = $this->server(failUploadOf: 'result_communities.json');

        $result = $this->publisher($server)->publish(self::files());

        $this->assertFalse($result->ok);
        $this->assertSame('the transfer of result_communities.json.staging failed', $result->message);
        $this->assertSame('OLD', $server->files['/cache/result_routers.json']);
        $this->assertSame(['/cache/result_routers.json'], array_keys($server->files));
        $this->assertTrue($server->closed);
    }

    public function testAFileThatArrivedIncompleteIsNotPutInPlace(): void
    {
        $server = $this->server(truncateBy: 2);

        $result = $this->publisher($server)->publish(self::files());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('arrived with', $result->message);
        $this->assertSame('OLD', $server->files['/cache/result_routers.json']);
        $this->assertNotContains('rename result_routers.json', $server->log);
    }

    public function testARefusedLoginIsAFailureAndTheMessageHasNoPassword(): void
    {
        $result = $this->publisher($this->server(refuseLogin: true), 's3cret-password')->publish(self::files());

        $this->assertFalse($result->ok);
        $this->assertSame('the server refused the login', $result->message);
        $this->assertStringNotContainsString('s3cret-password', $result->message);
    }

    public function testNoConnectionIsAFailureToo(): void
    {
        $publisher = new FtpPublisher(static function (): FtpConnection {
            throw new RuntimeException('cannot connect to ftp.example.org:21 with TLS');
        }, 'ftp.example.org', 'user', 'pw');

        $result = $publisher->publish(self::files());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('cannot connect to ftp.example.org:21 with TLS', $result->message);
    }

    public function testTheNameSaysWhereItGoes(): void
    {
        $this->assertSame('ftp ftp.example.org', $this->publisher($this->server())->name());
    }
}

final class FakeFtpServer implements FtpConnection
{
    /** @var array<string, string> */
    public array $files = ['/cache/result_routers.json' => 'OLD'];
    /** @var list<string> */
    public array $log = [];
    public bool $closed = false;

    public function __construct(private readonly bool $refuseOverwritingRename, private readonly ?string $failUploadOf, private readonly int $truncateBy, private readonly bool $refuseLogin)
    {
    }

    public function login(string $user, string $password): void
    {
        $this->log[] = 'login';

        if ($this->refuseLogin) {
            throw new RuntimeException('the server refused the login');
        }
    }

    public function upload(string $remotePath, string $content): void
    {
        $this->log[] = 'upload ' . basename($remotePath);

        if ($this->failUploadOf !== null && str_contains($remotePath, $this->failUploadOf)) {
            throw new RuntimeException('the transfer of ' . basename($remotePath) . ' failed');
        }

        $this->files[$remotePath] = $this->truncateBy > 0 ? substr($content, 0, -$this->truncateBy) : $content;
    }

    public function size(string $remotePath): int
    {
        return isset($this->files[$remotePath]) ? strlen($this->files[$remotePath]) : -1;
    }

    public function rename(string $from, string $to): bool
    {
        $this->log[] = 'rename ' . basename($to);

        if ($this->refuseOverwritingRename && isset($this->files[$to])) {
            return false;
        }

        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);

        return true;
    }

    public function delete(string $path): bool
    {
        $this->log[] = 'delete ' . basename($path);
        unset($this->files[$path]);

        return true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
