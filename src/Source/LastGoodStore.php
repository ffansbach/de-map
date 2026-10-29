<?php

declare(strict_types=1);

namespace FreifunkKarte\Source;

use DateTimeImmutable;
use FreifunkKarte\Parser\ParseResult;
use RuntimeException;

/**
 * The last nodes a source delivered, kept for a day. Some servers answer from time to time with an
 * error or an empty body although nothing changed (one network does it for a tenth of the map);
 * showing yesterday's nodes for a few hours is better than dropping them from the map.
 *
 * Only used for answers that mean "the server has trouble" (empty body, 429, 5xx), never for valid
 * data, so a community that really empties its list is believed.
 */
final class LastGoodStore
{
    public const DEFAULT_TTL = 86400;

    /** @var array<string, array{savedAt: int, result: ParseResult}> */
    private array $memory = [];

    public function __construct(private readonly ?string $directory = null, private readonly int $ttl = self::DEFAULT_TTL)
    {
        if ($directory !== null && !is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }
    }

    public function save(string $url, ParseResult $result, DateTimeImmutable $now): void
    {
        if (!$result->isUsable() || $result->nodes === []) {
            return;
        }

        $this->memory[$url] = ['savedAt' => $now->getTimestamp(), 'result' => $result];

        if ($this->directory === null) {
            return;
        }

        $file = $this->file($url);
        $json = json_encode(['url' => $url, 'savedAt' => $now->getTimestamp(), 'result' => $result->toArray()], JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($file . '.tmp', $json) === false || !rename($file . '.tmp', $file)) {
            throw new RuntimeException('Cannot write ' . $file);
        }
    }

    /**
     * @return array{result: ParseResult, savedAt: DateTimeImmutable}|null null if there is nothing, or it is too old
     */
    public function load(string $url, DateTimeImmutable $now): ?array
    {
        $entry = $this->memory[$url] ?? $this->read($url);

        if ($entry === null || $now->getTimestamp() - $entry['savedAt'] > $this->ttl) {
            return null;
        }

        return ['result' => $entry['result'], 'savedAt' => (new DateTimeImmutable())->setTimestamp($entry['savedAt'])];
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
     * @return array{savedAt: int, result: ParseResult}|null
     */
    private function read(string $url): ?array
    {
        if ($this->directory === null || !is_file($this->file($url))) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->file($url)), true);
        $result = is_array($data) && is_array($data['result'] ?? null) ? ParseResult::fromArray($data['result']) : null;

        if (!is_array($data) || ($data['url'] ?? null) !== $url || !is_int($data['savedAt'] ?? null) || $result === null) {
            return null;
        }

        return $this->memory[$url] = ['savedAt' => $data['savedAt'], 'result' => $result];
    }

    private function file(string $url): string
    {
        return $this->directory . '/' . md5($url) . '.json';
    }
}
