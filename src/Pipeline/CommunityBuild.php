<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

use FreifunkKarte\Community\CommunityInfo;
use FreifunkKarte\Source\Discovery;

/**
 * A community on its way through a run: what we know before any map is fetched.
 *
 * @internal
 */
final readonly class CommunityBuild
{
    public const ORIGIN_DIRECTORY = 'directory';
    public const ORIGIN_FIXED = 'fixed';

    /**
     * @param list<SourceEntry> $entries maps to look at, in the order the community declared them
     * @param list<string> $messages
     * @param list<string> $declaredTypes
     */
    public function __construct(
        public CommunityInfo $info,
        public string $origin,
        public ?string $apiUrl,
        public bool $listed,
        public string $outcome,
        public array $entries = [],
        public array $messages = [],
        public array $declaredTypes = [],
        public ?int $claimedNodes = null,
    ) {
    }

    /**
     * A directory community that is not looked at on purpose.
     */
    public static function skipped(string $key, string $apiUrl): self
    {
        return new self(new CommunityInfo($key, $key, ''), self::ORIGIN_DIRECTORY, $apiUrl, false, CommunityReport::SKIPPED, messages: ['skipped by configuration']);
    }

    /**
     * A directory community whose API file could not be had.
     */
    public static function apiUnavailable(string $key, string $apiUrl, string $reason): self
    {
        return new self(new CommunityInfo($key, $key, ''), self::ORIGIN_DIRECTORY, $apiUrl, false, CommunityReport::API_UNAVAILABLE, messages: ['API file not available: ' . $reason]);
    }

    /**
     * A community from the operator's own list, with one map that is not in the directory.
     *
     * @param array{url: string, parser: string, name: string, homePage: string} $source
     */
    public static function fixed(string $key, array $source): self
    {
        $type = strtolower($source['parser']);

        return new self(
            new CommunityInfo($key, $source['name'], $source['homePage']),
            self::ORIGIN_FIXED,
            null,
            true,
            CommunityReport::NO_DATA,
            [new SourceEntry($key, $type, Discovery::withScheme($source['url']))],
            ['fixed community'],
            [$type],
        );
    }

    /**
     * What a community's API file says: its name, home page, meta-community and the maps it points to.
     * Maps without a url or a technicalType are ignored (and said so).
     *
     * @param array<array-key, mixed> $document
     */
    public static function fromApiDocument(string $key, string $apiUrl, array $document): self
    {
        $name = self::text($document['name'] ?? null) ?? $key;
        $url = self::text($document['homePage'] ?? null) ?? (is_string($document['url'] ?? null) ? $document['url'] : '');
        $meta = self::text($document['metacommunity'] ?? null);
        $info = new CommunityInfo($key, $name, $url, $meta);
        $state = is_array($document['state'] ?? null) ? $document['state'] : [];
        $claimed = !empty($state['nodes']) && is_numeric($state['nodes']) ? (int) $state['nodes'] : null;

        $maps = $document['nodeMaps'] ?? null;

        if (!is_array($maps)) {
            return new self($info, self::ORIGIN_DIRECTORY, $apiUrl, false, CommunityReport::NO_NODEMAPS, messages: ['API file has no nodeMaps'], claimedNodes: $claimed);
        }

        if (json_encode(['name' => $name, 'url' => $url]) === false) {
            return new self($info, self::ORIGIN_DIRECTORY, $apiUrl, false, CommunityReport::NO_DATA, messages: ['name or url are corrupt (not valid UTF-8) - ignored'], claimedNodes: $claimed);
        }

        $entries = [];
        $types = [];
        $messages = $meta !== null ? ['meta-community: ' . $meta] : [];

        foreach ($maps as $map) {
            if (!is_array($map) || !is_string($map['url'] ?? null) || trim($map['url']) === '') {
                $messages[] = 'ignored a map without url';
                continue;
            }

            $type = is_string($map['technicalType'] ?? null) ? strtolower($map['technicalType']) : '';
            $types[] = $type !== '' ? $type : '(none)';

            if ($type === '') {
                $messages[] = 'ignored map without technicalType: ' . $map['url'];
                continue;
            }

            $entries[] = new SourceEntry($key, $type, Discovery::withScheme($map['url']));
        }

        return new self($info, self::ORIGIN_DIRECTORY, $apiUrl, true, CommunityReport::NO_DATA, $entries, $messages, $types, $claimed);
    }

    public function withMessage(string $message): self
    {
        return new self($this->info, $this->origin, $this->apiUrl, $this->listed, $this->outcome, $this->entries, [...$this->messages, $message], $this->declaredTypes, $this->claimedNodes);
    }

    public function isFixed(): bool
    {
        return $this->origin === self::ORIGIN_FIXED;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
