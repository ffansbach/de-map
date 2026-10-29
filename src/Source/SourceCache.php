<?php

declare(strict_types=1);

namespace FreifunkKarte\Source;

use DateTimeImmutable;
use RuntimeException;

/**
 * Remembers which data URLs a map page led to, so that the next run can fetch them directly
 * instead of probing. It stores URLs, never data: every run still downloads fresh nodes.
 *
 * An entry expires some days after it was found - not after it was last used - so that a map
 * that gained a second dataPath, or moved its data, is looked at again.
 */
final class SourceCache
{
    public const DEFAULT_TTL = 7 * 86400;

    /** @var array<string, array{urls: list<string>, foundAt: int}> */
    private array $entries = [];

    public function __construct(private readonly ?string $file = null, private readonly int $ttl = self::DEFAULT_TTL)
    {
        if ($file !== null && is_file($file)) {
            $this->entries = $this->read($file);
        }
    }

    /**
     * @return list<string>|null null if nothing is known, or the entry is too old
     */
    public function get(string $mapUrl, DateTimeImmutable $now): ?array
    {
        $entry = $this->entries[$mapUrl] ?? null;

        if ($entry === null || $now->getTimestamp() - $entry['foundAt'] > $this->ttl) {
            return null;
        }

        return $entry['urls'];
    }

    /**
     * @param list<string> $dataUrls
     */
    public function remember(string $mapUrl, array $dataUrls, DateTimeImmutable $now): void
    {
        if ($dataUrls === []) {
            return;
        }

        $this->entries[$mapUrl] = ['urls' => $dataUrls, 'foundAt' => $now->getTimestamp()];
    }

    public function forget(string $mapUrl): void
    {
        unset($this->entries[$mapUrl]);
    }

    /**
     * Writes the file (if there is one), dropping expired entries.
     */
    public function save(DateTimeImmutable $now): void
    {
        $this->entries = array_filter(
            $this->entries,
            fn (array $entry): bool => $now->getTimestamp() - $entry['foundAt'] <= $this->ttl,
        );

        if ($this->file === null) {
            return;
        }

        $temporary = $this->file . '.tmp';

        if (file_put_contents($temporary, json_encode($this->entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false
            || !rename($temporary, $this->file)) {
            throw new RuntimeException('Cannot write source cache ' . $this->file);
        }
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return array<string, array{urls: list<string>, foundAt: int}>
     */
    private function read(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        $entries = [];

        foreach (is_array($data) ? $data : [] as $mapUrl => $entry) {
            if (!is_array($entry) || !is_array($entry['urls'] ?? null) || !is_int($entry['foundAt'] ?? null)) {
                continue;
            }

            $urls = array_values(array_filter($entry['urls'], is_string(...)));

            if ($urls !== []) {
                $entries[(string) $mapUrl] = ['urls' => $urls, 'foundAt' => $entry['foundAt']];
            }
        }

        return $entries;
    }
}
