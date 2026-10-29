<?php

declare(strict_types=1);

namespace FreifunkKarte\Output;

use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\RunResult;
use RuntimeException;

/**
 * Writes the files the website reads: result_routers.json, result_communities.json,
 * result_statistics.json (for debug.php) and result_metacommunities.json.
 *
 * The first two keep the exact shape and encoding of the legacy parser, js/meta_map.js depends
 * on them. The statistics keep what debug.php reads and add the new facts next to it.
 */
final class LegacyResultWriter
{
    public const FILES = ['result_routers.json', 'result_communities.json', 'result_statistics.json', 'result_metacommunities.json'];

    /**
     * @return array<string, string> file name => path
     */
    public function write(RunResult $result, string $directory): array
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        $contents = [
            'result_routers.json' => self::encode($result->nodes->toRouterEntries()),
            'result_communities.json' => self::encode($this->communities($result)),
            'result_statistics.json' => self::encode($this->statistics($result)),
            'result_metacommunities.json' => self::encode($this->metaCommunities($result)),
        ];

        $paths = [];

        // all temporary files first, so that a failure leaves the old set of files untouched
        foreach ($contents as $name => $json) {
            $paths[$name] = $directory . '/' . $name;

            if (file_put_contents($paths[$name] . '.tmp', $json) === false) {
                throw new RuntimeException('Cannot write ' . $paths[$name] . '.tmp');
            }
        }

        foreach ($paths as $path) {
            if (!rename($path . '.tmp', $path)) {
                throw new RuntimeException('Cannot replace ' . $path);
            }
        }

        return $paths;
    }

    /**
     * @return array<string, array{name: string, url: string, meta: string|false}>
     */
    private function communities(RunResult $result): array
    {
        $communities = [];

        foreach ($result->listedCommunities() as $community) {
            $communities[$community->key] = ['name' => $community->name, 'url' => $community->url, 'meta' => $community->meta ?? false];
        }

        return $communities;
    }

    /**
     * @return array<string, mixed>
     */
    private function statistics(RunResult $result): array
    {
        $entries = [];

        foreach ($result->communities as $community) {
            $entry = [
                // the key, not the display name: that is what debug.php has always shown
                'name' => $community->key,
                'apifile' => $community->apiUrl ?? $community->url,
                'message' => $community->messages,
            ];

            if ($community->outcome !== CommunityReport::API_UNAVAILABLE && $community->outcome !== CommunityReport::SKIPPED) {
                $entry['claimed_nodecount'] = $community->claimedNodes ?? false;
            }

            if ($community->meta !== null) {
                $entry['metacommunity'] = $community->meta;
            }

            if ($community->staleSince !== null) {
                $entry['staleSince'] = $community->staleSince;
            }

            $entry['outcome'] = $community->outcome;
            $entry['format'] = $community->format;
            $entry['sourceUrls'] = $community->sourceUrls;

            if ($community->outcome === CommunityReport::NO_DATA && $community->attempts !== []) {
                // what has been tried for a map that gave no data
                $entry['attempts'] = array_map(static fn ($a): array => [
                    'url' => $a->url,
                    'status' => $a->status,
                    'error' => $a->error,
                    'format' => $a->format,
                ], $community->attempts);
            }

            $entries[$community->key] = $entry;
        }

        return [
            'errorCommunities' => $entries,
            'timestamp' => $result->startedAt->format('c'),
            'run' => [
                'requests' => $result->requests,
                'seconds' => round($result->seconds, 1),
                'nodes' => $result->nodes->count(),
                'communities' => count($result->listedCommunities()),
            ],
        ];
    }

    /**
     * Only meta-communities that have a link; the frontend shows the others as plain text.
     *
     * @return array<string, array{url: string}>|\stdClass
     */
    private function metaCommunities(RunResult $result): array|\stdClass
    {
        $links = [];

        foreach ($result->metaLinks as $name => $link) {
            if ($link->url !== null) {
                $links[$name] = ['url' => $link->url];
            }
        }

        // "{}", not "[]": the frontend indexes it by name
        return $links === [] ? new \stdClass() : $links;
    }

    /**
     * Default flags on purpose: same bytes as the legacy parser (escaped slashes and unicode).
     */
    private static function encode(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
