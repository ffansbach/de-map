<?php

declare(strict_types=1);

namespace FreifunkKarte\Config;

use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\Listener\HealthchecksListener;
use FreifunkKarte\Run\Listener\InfluxDb2Writer;
use FreifunkKarte\Run\Listener\InfluxListener;
use FreifunkKarte\Run\RunListener;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * The outside services a run reports to. Secrets live outside the repository: either in
 * config/integrations.local.json (see integrations.example.json) or in the old-style config.php
 * (--legacy-config), which already has the healthchecks identifier and the InfluxDB settings.
 */
final readonly class IntegrationConfig
{
    /**
     * @param array{host: string, port: string, token: string, bucket: string, org: string, env: string}|null $influx
     */
    public function __construct(
        public ?string $healthchecksUrl = null,
        public string $healthchecksFailOn = DriftEvent::ALERT,
        public ?array $influx = null,
    ) {
    }

    public static function fromJsonFile(string $file): self
    {
        $data = json_decode((string) @file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException($file . ' is missing or not valid JSON');
        }

        $healthchecks = is_array($data['healthchecks'] ?? null) ? $data['healthchecks'] : [];
        $url = is_string($healthchecks['url'] ?? null) ? $healthchecks['url']
            : (is_string($healthchecks['identifier'] ?? null) && $healthchecks['identifier'] !== '' ? 'https://hc-ping.com/' . $healthchecks['identifier'] : null);
        $failOn = is_string($healthchecks['failOn'] ?? null) && in_array($healthchecks['failOn'], [DriftEvent::INFO, DriftEvent::WARNING, DriftEvent::ALERT], true)
            ? $healthchecks['failOn'] : DriftEvent::ALERT;

        return new self($url, $failOn, self::influx(is_array($data['influxdb'] ?? null) ? $data['influxdb'] : []));
    }

    /**
     * Reads $healtcheckIdentifier, $influxDB and $environment from an old-style config.php.
     */
    public static function fromLegacyConfig(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException($file . ' does not exist');
        }

        ob_start();

        try {
            $variables = (static function (string $configFile): array {
                require $configFile;
                unset($configFile);

                return get_defined_vars();
            })($file);
        } finally {
            ob_end_clean();
        }

        $identifier = $variables['healtcheckIdentifier'] ?? null;
        $influx = is_array($variables['influxDB'] ?? null) ? $variables['influxDB'] : [];

        if (isset($influx['add_tag_env'])) {
            $influx['env'] = $influx['add_tag_env'];
        } elseif (is_string($variables['environment'] ?? null)) {
            $influx['env'] = $variables['environment'];
        }

        return new self(
            is_string($identifier) && $identifier !== '' ? 'https://hc-ping.com/' . $identifier : null,
            DriftEvent::ALERT,
            self::influx($influx),
        );
    }

    /**
     * @return list<RunListener>
     */
    public function listeners(ClientInterface $client): array
    {
        $listeners = [];

        if ($this->healthchecksUrl !== null) {
            $listeners[] = new HealthchecksListener($client, $this->healthchecksUrl, $this->healthchecksFailOn);
        }

        if ($this->influx !== null) {
            $listeners[] = new InfluxListener(
                new InfluxDb2Writer($this->influx['host'], $this->influx['port'], $this->influx['token'], $this->influx['bucket'], $this->influx['org']),
                $this->influx['env'],
            );
        }

        return $listeners;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{host: string, port: string, token: string, bucket: string, org: string, env: string}|null
     */
    private static function influx(array $data): ?array
    {
        $settings = [];

        foreach (['host', 'port', 'token', 'bucket', 'org'] as $key) {
            if (!is_string($data[$key] ?? null) && !is_int($data[$key] ?? null) || (string) $data[$key] === '') {
                return null;
            }

            $settings[$key] = (string) $data[$key];
        }

        $settings['env'] = is_string($data['env'] ?? null) ? $data['env'] : 'undefined';

        return $settings;
    }
}
