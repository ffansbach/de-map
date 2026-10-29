<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Config;

use FreifunkKarte\Config\IntegrationConfig;
use FreifunkKarte\Run\Listener\HealthchecksListener;
use FreifunkKarte\Run\Listener\InfluxListener;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IntegrationConfigTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/ffmap_integrations_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testJsonFileWithEverything(): void
    {
        file_put_contents($this->file, (string) json_encode([
            'healthchecks' => ['identifier' => 'abc-123', 'failOn' => 'alert'],
            'influxdb' => ['host' => 'http://influx.test', 'port' => 8086, 'token' => 't', 'bucket' => 'b', 'org' => 'o', 'env' => 'live'],
        ]));

        $config = IntegrationConfig::fromJsonFile($this->file);

        $this->assertSame('https://hc-ping.com/abc-123', $config->healthchecksUrl);
        $this->assertSame('alert', $config->healthchecksFailOn);
        $this->assertSame(['host' => 'http://influx.test', 'port' => '8086', 'token' => 't', 'bucket' => 'b', 'org' => 'o', 'env' => 'live'], $config->influx);
        $this->assertEquals([HealthchecksListener::class, InfluxListener::class], array_map(static fn ($l): string => $l::class, $config->listeners(new Client())));
    }

    public function testHealthchecksCanBeAFullUrlForASelfHostedInstance(): void
    {
        file_put_contents($this->file, (string) json_encode(['healthchecks' => ['url' => 'https://hc.example/ping/xyz']]));

        $config = IntegrationConfig::fromJsonFile($this->file);

        $this->assertSame('https://hc.example/ping/xyz', $config->healthchecksUrl);
        $this->assertSame('warning', $config->healthchecksFailOn, 'default: warnings count');
        $this->assertNull($config->influx);
    }

    public function testUnknownFailOnAndIncompleteInfluxSettingsAreIgnored(): void
    {
        file_put_contents($this->file, (string) json_encode([
            'healthchecks' => ['identifier' => 'abc', 'failOn' => 'whenever'],
            'influxdb' => ['host' => 'http://influx.test', 'port' => '8086'],
        ]));

        $config = IntegrationConfig::fromJsonFile($this->file);

        $this->assertSame('warning', $config->healthchecksFailOn);
        $this->assertNull($config->influx);
        $this->assertCount(1, $config->listeners(new Client()));
    }

    public function testNothingConfiguredMeansNoListeners(): void
    {
        file_put_contents($this->file, '{}');

        $this->assertSame([], IntegrationConfig::fromJsonFile($this->file)->listeners(new Client()));
    }

    public function testMissingOrBrokenJsonFileIsAnError(): void
    {
        $this->expectException(RuntimeException::class);

        IntegrationConfig::fromJsonFile($this->file);
    }

    public function testTheLegacyConfigPhpIsUnderstood(): void
    {
        file_put_contents($this->file, <<<'PHP'
            <?php
            $environment = 'dev';
            $healtcheckIdentifier = 'legacy-uuid';
            $influxDB = ['host' => 'http://influx.test', 'port' => '8086', 'org' => 'o', 'token' => 't', 'bucket' => 'b', 'add_tag_env' => $environment];
            echo "this output must not leak";
            PHP);

        $config = IntegrationConfig::fromLegacyConfig($this->file);

        $this->assertSame('https://hc-ping.com/legacy-uuid', $config->healthchecksUrl);
        $this->assertNotNull($config->influx);
        $this->assertSame('dev', $config->influx['env']);
        $this->assertSame('b', $config->influx['bucket']);
    }

    public function testLegacyConfigWithoutTheServicesConfiguresNothing(): void
    {
        file_put_contents($this->file, "<?php\n\$environment = 'dev';\n\$influxDB = ['host' => 'your influxdb host'];\n");

        $config = IntegrationConfig::fromLegacyConfig($this->file);

        $this->assertNull($config->healthchecksUrl);
        $this->assertNull($config->influx);
    }

    public function testMissingLegacyConfigIsAnError(): void
    {
        $this->expectException(RuntimeException::class);

        IntegrationConfig::fromLegacyConfig('/nonexistent/config.php');
    }

    public function testTheShippedExampleIsValid(): void
    {
        $config = IntegrationConfig::fromJsonFile(__DIR__ . '/../../config/integrations.example.json');

        $this->assertSame('https://hc-ping.com/00000000-0000-0000-0000-000000000000', $config->healthchecksUrl);
        $this->assertNotNull($config->influx);
    }
}
