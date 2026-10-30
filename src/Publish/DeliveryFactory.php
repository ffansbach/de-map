<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

use GuzzleHttp\ClientInterface;

final class DeliveryFactory
{
    /**
     * @param string|null $jsonFile the upload settings; without it config/upload.local.json is used if it exists
     * @param string|null $legacyConfig old-style config.php (its FTP settings), used if there is no JSON file
     */
    public static function create(ClientInterface $client, string $configDir, ?string $jsonFile = null, ?string $legacyConfig = null): Delivery
    {
        $default = $configDir . '/upload.local.json';

        $config = match (true) {
            $jsonFile !== null => UploadConfig::fromJsonFile($jsonFile),
            is_file($default) => UploadConfig::fromJsonFile($default),
            $legacyConfig !== null => UploadConfig::fromLegacyConfig($legacyConfig),
            default => new UploadConfig(),
        };

        $ping = $config->statsPingUrl;

        return new Delivery(
            $config->build($client),
            $ping === null ? null : static function () use ($client, $ping): void {
                $client->request('GET', $ping, ['timeout' => 30, 'allow_redirects' => false]);
            },
        );
    }
}
