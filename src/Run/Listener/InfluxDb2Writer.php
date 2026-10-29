<?php

declare(strict_types=1);

namespace FreifunkKarte\Run\Listener;

use InfluxDB2\Client;
use InfluxDB2\Model\WritePrecision;

final class InfluxDb2Writer implements InfluxPointWriter
{
    public function __construct(
        private readonly string $host,
        private readonly string $port,
        private readonly string $token,
        private readonly string $bucket,
        private readonly string $org,
    ) {
    }

    public function write(string $measurement, array $tags, array $fields): void
    {
        $client = new Client([
            'url' => $this->host . ':' . $this->port,
            'token' => $this->token,
            'bucket' => $this->bucket,
            'org' => $this->org,
            'precision' => WritePrecision::NS,
        ]);

        try {
            $client->createWriteApi()->write(
                ['name' => $measurement, 'tags' => $tags, 'fields' => $fields, 'time' => microtime(true)],
                WritePrecision::S,
                $this->bucket,
                $this->org,
            );
        } finally {
            $client->close();
        }
    }
}
