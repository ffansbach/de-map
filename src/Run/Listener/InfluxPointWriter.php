<?php

declare(strict_types=1);

namespace FreifunkKarte\Run\Listener;

interface InfluxPointWriter
{
    /**
     * @param array<string, string> $tags
     * @param array<string, int|float|string|bool> $fields
     */
    public function write(string $measurement, array $tags, array $fields): void;
}
