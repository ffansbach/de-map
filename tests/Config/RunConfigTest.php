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

    public function testSourceAssignmentsAreLoadedWithTheOtherFiles(): void
    {
        file_put_contents($this->directory . '/source-assignments.json', '[{"prefix": "map.test/data/a/", "community": "a"}]');

        $this->assertSame('a', RunConfig::load($this->directory)->assignments->forUrl('https://map.test/data/a/meshviewer.json')?->community);
        $this->assertSame(0, RunConfig::load(sys_get_temp_dir() . '/ffmap_no_such_config_dir')->assignments->count());
    }

    public function testTheShippedAssignmentsKnowTheRheinSiegData(): void
    {
        $assignments = RunConfig::load(__DIR__ . '/../../config')->assignments;
        $base = 'https://map.freifunk-rhein-sieg.net/data/';

        $this->assertSame('siegburg', $assignments->forUrl($base . 'siegburg2/domain05/meshviewer.json')?->community);
        $this->assertSame('lohmar', $assignments->forUrl($base . 'lohmar3/domain11/meshviewer.json')?->community);
        $this->assertSame('niederkassel', $assignments->forUrl($base . 'niederkassel1/domain04/meshviewer.json')?->community);
        $this->assertSame('troisdorf', $assignments->forUrl($base . 'tdf/all/meshviewer.json')?->community);
        $this->assertTrue($assignments->forUrl($base . 'tdf/all/meshviewer.json')->backfill);
        $this->assertNull($assignments->forUrl('https://elsewhere.test/data/siegburg1/meshviewer.json'));
    }
}
