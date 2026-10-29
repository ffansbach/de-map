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
     * The files a data directory may hold: current meshviewers serve meshviewer.json, older ffmap
     * and HopGlass setups nodes.json. The first one that has nodes is used for a directory.
     */
    public const DATA_FILES = ['meshviewer.json', 'nodes.json'];

    /**
     * Data URLs named by the dataPath of a map, from its config.json or from its page. A path may be
     * a full URL, start with "/" (relative to the server) or be relative to the map's directory;
     * a path that already names a .json file is taken as it is.
     *
     * @param list<string> $dataPaths
     * @return list<string> for every path one URL per file name, in order of preference
     */
    public static function dataPathUrls(string $mapUrl, array $dataPaths): array
    {
        $parts = parse_url(self::withScheme($mapUrl));

        if (!isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $urls = [];

        foreach ($dataPaths as $path) {
            $path = trim($path);

            if ($path === '') {
                continue;
            }

            $absolute = match (true) {
                preg_match('~^https?://~i', $path) === 1 => $path,
                str_starts_with($path, '/') => $origin . $path,
                default => self::baseUrl($mapUrl) . $path,
            };

            if (preg_match('/\.json$/i', $absolute) === 1) {
                $urls[] = $absolute;

                continue;
            }

            foreach (self::DATA_FILES as $file) {
                $urls[] = rtrim($absolute, '/') . '/' . $file;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Some map pages carry their configuration inline instead of in a config.json:
     * `dataPath: ["/data/a/", "/data/b/"]` in a script. Reads those paths.
     *
     * @return list<string> empty if the page has none
     */
    public static function inlineDataPaths(string $html): array
    {
        if (preg_match('/dataPath[\'"]?\s*:\s*(\[[^\]]*\]|"[^"]*"|\'[^\']*\')/s', $html, $block) !== 1) {
            return [];
        }

        preg_match_all('/["\']([^"\']+)["\']/', $block[1], $quoted);
        $paths = [];

        foreach ($quoted[1] as $path) {
            // only things that can be a path or a URL, nothing a script could make of it
            if (preg_match('#^[A-Za-z0-9_./:%@+=~-]+$#', $path) === 1 && !str_contains($path, '..') && preg_match('~^[a-z][a-z0-9+.-]*:(?!//)~i', $path) !== 1) {
                $paths[] = $path;
            }
        }

        return array_slice(array_values(array_unique($paths)), 0, self::MAX_INLINE_PATHS);
    }

    /** a page that lists more than this many paths is not a map configuration */
    private const MAX_INLINE_PATHS = 100;
}
