<?php

declare(strict_types=1);

namespace FreifunkKarte\Source;

/**
 * The community directory of the Freifunk API: community key => URL of its API file.
 */
final class Directory
{
    public const URL = 'https://raw.githubusercontent.com/freifunk/directory.api.freifunk.net/master/directory.json';

    /**
     * @return array<string, string> empty if the document is not a directory
     */
    public static function fromJson(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        $directory = [];

        foreach ($data as $key => $url) {
            if (is_string($url) && $url !== '') {
                $directory[(string) $key] = $url;
            }
        }

        return $directory;
    }
}
