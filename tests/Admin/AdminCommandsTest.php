<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use FreifunkKarte\Admin\ResponseEmitter;
use FreifunkKarte\Command\AdminPasswordCommand;
use FreifunkKarte\Command\AdminServeCommand;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AdminCommandsTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/ffmap_admin_project_' . bin2hex(random_bytes(6));
        mkdir($this->project . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->project . '/config/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->project . '/config');
        rmdir($this->project);
    }

    public function testServeBuildsTheBuiltInServerCommandForLocalUse(): void
    {
        $tester = new CommandTester(new AdminServeCommand($this->project));
        $tester->execute(['--dry-run' => true, '--port' => '9090']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('-S \'127.0.0.1:9090\'', $display);
        $this->assertStringContainsString($this->project . '/public-admin', $display);
        $this->assertStringContainsString($this->project . '/public-admin/index.php', $display, 'the front controller is the router');
    }

    public function testServeRefusesToListenOnTheNetworkWithoutAPassword(): void
    {
        $tester = new CommandTester(new AdminServeCommand($this->project));
        $tester->execute(['--dry-run' => true, '--host' => '0.0.0.0']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('without a password', $tester->getDisplay());
    }

    public function testServeMayListenOnTheNetworkWhenThereIsAPassword(): void
    {
        file_put_contents($this->project . '/config/admin.local.json', (string) json_encode(['user' => 'a', 'passwordHash' => password_hash('x', PASSWORD_DEFAULT)]));

        $tester = new CommandTester(new AdminServeCommand($this->project));
        $tester->execute(['--dry-run' => true, '--host' => '0.0.0.0']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('0.0.0.0:8081', $tester->getDisplay());
    }

    public function testServeChecksThePort(): void
    {
        foreach (['0', '99999', 'abc', '80; rm -rf /'] as $port) {
            $tester = new CommandTester(new AdminServeCommand($this->project));
            $tester->execute(['--dry-run' => true, '--port' => $port]);

            $this->assertSame(Command::INVALID, $tester->getStatusCode(), $port);
        }
    }

    public function testPasswordCommandPrintsAHashThatVerifies(): void
    {
        $tester = new CommandTester(new AdminPasswordCommand());
        $tester->setInputs(['a long enough secret', 'a long enough secret']);
        $tester->execute(['--user' => 'tino']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        preg_match('/\{.*\}/s', $tester->getDisplay(), $matches);
        $config = json_decode($matches[0] ?? '', true);

        $this->assertIsArray($config);
        $this->assertSame('tino', $config['user']);
        $this->assertTrue(password_verify('a long enough secret', $config['passwordHash']));
        $this->assertStringNotContainsString('a long enough secret', $tester->getDisplay());
    }

    public function testPasswordCommandRefusesShortOrDifferingPasswords(): void
    {
        $short = new CommandTester(new AdminPasswordCommand());
        $short->setInputs(['short', 'short']);
        $short->execute([]);
        $this->assertSame(Command::FAILURE, $short->getStatusCode());

        $differs = new CommandTester(new AdminPasswordCommand());
        $differs->setInputs(['a long enough secret', 'another long secret!']);
        $differs->execute([]);
        $this->assertSame(Command::FAILURE, $differs->getStatusCode());
        $this->assertStringNotContainsString('passwordHash', $differs->getDisplay());
    }

    public function testTheEmitterSendsTheBody(): void
    {
        ob_start();
        ResponseEmitter::emit(new Response(404, ['X-Test' => 'yes'], 'not here'));
        $output = (string) ob_get_clean();

        $this->assertSame('not here', $output);
    }
}
