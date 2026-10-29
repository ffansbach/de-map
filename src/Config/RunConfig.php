<?php

declare(strict_types=1);

namespace FreifunkKarte\Config;

use RuntimeException;

/**
 * The small hand-kept files in config/:
 *
 *  skipped-communities.json  {"community-key": "why it is skipped"}
 *  insecure-hosts.json       ["host.example"] hosts with a broken certificate, fetched unverified
 *  metacommunities.json      overrides for meta-community links (see MetaCommunityResolver)
 */
final readonly class RunConfig
{
    /**
     * @param array<string, string> $skippedCommunities key => reason
     * @param list<string> $insecureHosts
     */
    public function __construct(
        public array $skippedCommunities = [],
        public array $insecureHosts = [],
        public string $metaCommunitiesFile = '',
    ) {
    }

    public static function load(string $directory): self
    {
        $skipped = [];

        foreach (self::read($directory . '/skipped-communities.json') as $key => $reason) {
            // a plain list of keys is accepted, too
            $skipped[is_int($key) ? (string) $reason : (string) $key] = is_int($key) ? '' : (string) $reason;
        }

        $hosts = array_values(array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host)),
            array_filter(self::read($directory . '/insecure-hosts.json'), is_string(...)),
        ));

        return new self($skipped, $hosts, $directory . '/metacommunities.json');
    }

    /**
     * @return array<array-key, mixed> empty if the file does not exist
     */
    private static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException($file . ' is not valid JSON');
        }

        return $data;
    }
}
