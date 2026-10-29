<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Admin;

use FreifunkKarte\Admin\AdminConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminConfigTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/ffmap_admin_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testWithoutAFileThereAreDefaultsAndNoLogin(): void
    {
        $config = AdminConfig::fromFile($this->file);

        $this->assertSame('var/parser.sqlite', $config->dbFile);
        $this->assertSame('Europe/Berlin', $config->timezone);
        $this->assertFalse($config->requiresLogin());
    }

    public function testFileSettings(): void
    {
        file_put_contents($this->file, (string) json_encode(['db' => '/srv/p.sqlite', 'user' => 'tino', 'passwordHash' => '$2y$x', 'timezone' => 'UTC', 'title' => 'Mine']));

        $config = AdminConfig::fromFile($this->file);

        $this->assertSame(['/srv/p.sqlite', 'tino', '$2y$x', 'UTC', 'Mine'], [$config->dbFile, $config->user, $config->passwordHash, $config->timezone, $config->title]);
        $this->assertTrue($config->requiresLogin());
    }

    public function testBrokenFileAndUnknownTimeZoneAreErrors(): void
    {
        file_put_contents($this->file, '{oops');

        try {
            AdminConfig::fromFile($this->file);
            $this->fail('expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not valid JSON', $e->getMessage());
        }

        file_put_contents($this->file, '{"timezone": "Mars/Olympus"}');
        $this->expectExceptionMessage('Unknown time zone');
        AdminConfig::fromFile($this->file);
    }
}
