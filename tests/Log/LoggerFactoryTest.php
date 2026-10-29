<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Log;

use FreifunkKarte\Log\LoggerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LoggerFactoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffmap_log_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/nested/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->directory . '/nested');
        @rmdir($this->directory);
    }

    public function testWithoutAFileNothingIsLogged(): void
    {
        $this->assertInstanceOf(NullLogger::class, LoggerFactory::create(null));
    }

    public function testOneJsonObjectPerLineAndTheDirectoryIsCreated(): void
    {
        $logger = LoggerFactory::create($this->directory . '/nested/parser.log');

        $logger->info('run finished', ['run' => 4, 'nodes' => 25160]);
        $logger->warning('Technical type "yanic" has not been seen before', ['kind' => 'new-technical-type']);
        $logger->debug('too detailed to keep');

        $files = glob($this->directory . '/nested/*.log') ?: [];
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/parser-\d{4}-\d{2}-\d{2}\.log$/', $files[0], 'one file per day');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($files[0]))));
        $this->assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $this->assertIsArray($first);
        $this->assertSame('run finished', $first['message']);
        $this->assertSame(['run' => 4, 'nodes' => 25160], $first['context']);
        $this->assertSame('INFO', $first['level_name']);
        $this->assertSame('WARNING', json_decode($lines[1], true)['level_name'] ?? null);
    }
}
