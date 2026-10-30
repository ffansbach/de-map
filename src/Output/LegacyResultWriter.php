<?php

declare(strict_types=1);

namespace FreifunkKarte\Output;

use FreifunkKarte\Pipeline\RunResult;
use RuntimeException;

/**
 * Writes the files the website reads: result_routers.json, result_communities.json and
 * result_metacommunities.json.
 *
 * The shape and the encoding of the first two are what js/meta_map.js depends on.
 */
final class LegacyResultWriter
{
    public const FILES = ['result_routers.json', 'result_communities.json', 'result_metacommunities.json'];

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
