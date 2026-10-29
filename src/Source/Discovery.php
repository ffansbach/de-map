<?php

declare(strict_types=1);

namespace FreifunkKarte\Source;

/**
 * A community gives us the URL of a map page, not of the data behind it. These are the places
 * where the data usually is - the rules the legacy parser probed, without its quirks.
 */
final class Discovery
{
    /** technicalTypes whose URL may be a page in front of the data */
    public const DISCOVERY_TYPES = ['ffmap', 'meshviewer', 'hopglass'];

    /**
     * "map.example.org/foo" becomes "http://map.example.org/foo", like the legacy parser did.
     */
    public static function withScheme(string $url): string
    {
        $url = trim($url);

        return preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) === 1 ? $url : 'http://' . ltrim($url, '/');
    }

    /**
     * The directory of a map page: scheme, host and path without a trailing file name, always
     * with a closing slash ("https://x.test/map/index.html" and "https://x.test/map" both
     * become "https://x.test/map/").
     */
    public static function baseUrl(string $url): string
    {
        $parts = parse_url(self::withScheme($url));
        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), static fn (string $s): bool => $s !== ''));

        if ($segments !== [] && str_contains($segments[array_key_last($segments)], '.')) {
            array_pop($segments);
        }

        $base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '');

        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        return $base . '/' . ($segments === [] ? '' : implode('/', $segments) . '/');
    }

    /**
     * Other URLs to try if the declared one is not a node source, in the order they are preferred.
     * Only maps of the discovery types are probed, and never a URL that already is a .json file.
     *
     * @return list<string>
     */
    public static function candidates(string $url, string $declaredType): array
    {
        $url = self::withScheme($url);

        if (!in_array($declaredType, self::DISCOVERY_TYPES, true) || preg_match('/\.json$/', $url) === 1) {
            return [];
        }

        $base = self::baseUrl($url);
        $candidates = [
            $base . 'config.json',
            $base . 'nodes.json',
            $base . 'data/nodes.json',
            $base . 'json/nodes.json',
            $base . 'meshviewer/data/meshviewer.json',
            $base . 'data/meshviewer.json',
        ];

        if (str_contains($base, '/meshviewer/')) {
            $root = str_replace('/meshviewer', '', $base);
            array_push($candidates, $root . 'nodes.json', $root . 'data/nodes.json', $root . 'json/nodes.json');
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Data URLs named by a map's config.json (its dataPath entries).
     *
     * @param list<string> $dataPaths
     * @return list<string>
     */
    public static function dataPathUrls(string $mapUrl, array $dataPaths): array
    {
        $parts = parse_url(self::withScheme($mapUrl));
        $urls = [];

        foreach ($dataPaths as $path) {
            $path .= 'nodes.json';

            if (preg_match('/https?:/', $path) !== 1) {
                if (!isset($parts['scheme'], $parts['host'])) {
                    continue;
                }

                $path = $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim($path, '/');
            }

            $urls[] = $path;
        }

        return array_values(array_unique($urls));
    }
}
