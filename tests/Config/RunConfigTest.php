<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Config;

use FreifunkKarte\Config\RunConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RunConfigTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffmap_config_' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testMissingFilesMeanNoConfiguration(): void
    {
        $config = RunConfig::load($this->directory);

        $this->assertSame([], $config->skippedCommunities);
        $this->assertSame([], $config->insecureHosts);
        $this->assertSame($this->directory . '/metacommunities.json', $config->metaCommunitiesFile);
    }

    public function testSkippedCommunitiesWithReasonsOrAsPlainList(): void
    {
        file_put_contents($this->directory . '/skipped-communities.json', '{"slow": "times out", "dead": "gone"}');
        $this->assertSame(['slow' => 'times out', 'dead' => 'gone'], RunConfig::load($this->directory)->skippedCommunities);

        file_put_contents($this->directory . '/skipped-communities.json', '["slow", "dead"]');
        $this->assertSame(['slow' => '', 'dead' => ''], RunConfig::load($this->directory)->skippedCommunities);
    }

    public function testInsecureHostsAreNormalised(): void
    {
        file_put_contents($this->directory . '/insecure-hosts.json', '[" Map.Example.ORG ", 5, "other.test"]');

        $this->assertSame(['map.example.org', 'other.test'], RunConfig::load($this->directory)->insecureHosts);
    }

    public function testBrokenFileIsAnErrorNotSilentlyIgnored(): void
    {
        file_put_contents($this->directory . '/skipped-communities.json', '{oops');

        $this->expectException(RuntimeException::class);
        RunConfig::load($this->directory);
    }

    public function testTheShippedConfigurationLoads(): void
    {
        $config = RunConfig::load(__DIR__ . '/../../config');

        $this->assertSame([], $config->skippedCommunities, 'the old hard-coded skip list is gone on purpose, see PRD');
        $this->assertSame([], $config->insecureHosts);
    }
}
