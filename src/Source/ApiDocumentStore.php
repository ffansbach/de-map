<?php

declare(strict_types=1);

namespace FreifunkKarte\Source;

use DateTimeImmutable;
use RuntimeException;

/**
 * The last API file of every community, kept for a day. When the server that hosts it is not
 * reachable for an hour, the community would drop off the map with all its nodes although its maps
 * still work; the copy of yesterday still says where they are.
 *
 * Only used when the file cannot be fetched properly (no answer, timeout, 5xx, 429, no JSON), never
 * for a missing file (404): a community that removed its file is believed.
 */
final class ApiDocumentStore
{
    public const DEFAULT_TTL = LastGoodStore::DEFAULT_TTL;

    /** @var array<string, array{savedAt: int, document: array<array-key, mixed>}> */
    private array $memory = [];

    public function __construct(private readonly ?string $directory = null, private readonly int $ttl = self::DEFAULT_TTL)
    {
        if ($directory !== null && !is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }
    }

    /**
     * @param array<array-key, mixed> $document
     */
    public function save(string $url, array $document, DateTimeImmutable $now): void
    {
        $this->memory[$url] = ['savedAt' => $now->getTimestamp(), 'document' => $document];

        if ($this->directory === null) {
            return;
        }

        $file = $this->file($url);
        $json = json_encode(['url' => $url, 'savedAt' => $now->getTimestamp(), 'document' => $document], JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($file . '.tmp', $json) === false || !rename($file . '.tmp', $file)) {
            throw new RuntimeException('Cannot write ' . $file);
        }
    }

    /**
     * @return array{document: array<array-key, mixed>, savedAt: DateTimeImmutable}|null null if there is nothing, or it is too old
     */
    public function load(string $url, DateTimeImmutable $now): ?array
    {
        $entry = $this->memory[$url] ?? $this->read($url);

        if ($entry === null || $now->getTimestamp() - $entry['savedAt'] > $this->ttl) {
            return null;
        }

        return ['document' => $entry['document'], 'savedAt' => (new DateTimeImmutable())->setTimestamp($entry['savedAt'])];
    }

    /**
     * Removes what is too old.
     */
    public function prune(DateTimeImmutable $now): void
    {
        if ($this->directory === null) {
            return;
        }

        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data) || !is_int($data['savedAt'] ?? null) || $now->getTimestamp() - $data['savedAt'] > $this->ttl) {
                unlink($file);
            }
        }
    }

    /**
     * @return array{savedAt: int, document: array<array-key, mixed>}|null
     */
    private function read(string $url): ?array
    {
        if ($this->directory === null || !is_file($this->file($url))) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->file($url)), true);

        if (!is_array($data) || ($data['url'] ?? null) !== $url || !is_int($data['savedAt'] ?? null) || !is_array($data['document'] ?? null)) {
            return null;
        }

        return $this->memory[$url] = ['savedAt' => $data['savedAt'], 'document' => $data['document']];
    }

    private function file(string $url): string
    {
        return $this->directory . '/' . md5($url) . '.json';
    }
}
