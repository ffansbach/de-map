<?php

declare(strict_types=1);

namespace FreifunkKarte\Audit;

use FreifunkKarte\Http\FetchResult;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Source\Directory;
use FreifunkKarte\Source\Discovery;
use RuntimeException;

/**
 * Read-only audit of every node source the map knows about: which technicalType
 * is declared, what the URL really delivers and whether nodes can be read from it.
 *
 * Probing follows the legacy parser: the declared URL first; for ffmap/meshviewer/hopglass
 * maps without a direct hit the usual nodes.json locations and the dataPath of a config.json.
 */
final class SourceAuditor
{
    public const DIRECTORY_URL = Directory::URL;

    private const RAW_SAMPLES_PER_FORMAT = 3;

    /** @var array<string, DetectionResult> */
    private array $detections = [];

    /** @var array<string, FetchResult> */
    private array $fetches = [];

    /** @var array<string, int> */
    private array $rawSaved = [];

    public function __construct(
        private readonly ParallelFetcher $fetcher,
        private readonly FormatDetector $detector,
        private readonly ?string $rawDir = null,
    ) {
    }

    /**
     * @param array<string, array{url: string, parser: string}> $fixedCommunities key => source, as in fixedCommunities.php
     * @param callable(string): void|null $log progress messages
     * @return array<string, mixed>
     */
    public function audit(array $fixedCommunities = [], ?string $only = null, ?callable $log = null): array
    {
        $log ??= static function (string $message): void {
        };

        $log('fetching community directory');
        $directory = $this->fetchDirectory();

        if ($only !== null) {
            $directory = array_intersect_key($directory, [$only => true]);
        }

        $log('fetching ' . count($directory) . ' community API files');
        [$entries, $apiFailures, $withoutNodeMaps] = $this->collectEntries($directory);

        foreach ($fixedCommunities as $key => $source) {
            if ($only === null || $only === $key) {
                $entries[] = [
                    'community' => $key,
                    'origin' => 'fixed',
                    'declaredType' => strtolower($source['parser']),
                    'url' => $source['url'],
                ];
            }
        }

        $log('probing ' . count($entries) . ' node sources');
        $this->probe(array_column($entries, 'url'));

        $candidates = [];

        foreach ($entries as $entry) {
            if (in_array($entry['declaredType'], Discovery::DISCOVERY_TYPES, true) && !$this->works($entry['url'])) {
                array_push($candidates, ...$this->candidates($entry['url'], $entry['declaredType']));
            }
        }

        $log('probing ' . count(array_unique($candidates)) . ' discovery candidates');
        $this->probe($candidates);

        $dataPathUrls = [];

        foreach ($entries as $entry) {
            array_push($dataPathUrls, ...$this->dataPathUrls($entry['url'], $entry['declaredType']));
        }

        $log('probing ' . count(array_unique($dataPathUrls)) . ' config.json dataPath URLs');
        $this->probe($dataPathUrls);

        $results = array_map($this->resolve(...), $entries);

        return [
            'generatedAt' => date('c'),
            'directoryCommunities' => count($directory),
            'apiFailures' => $apiFailures,
            'withoutNodeMaps' => $withoutNodeMaps,
            'entries' => $results,
            'summary' => $this->summarize($results, count($directory), $apiFailures, $withoutNodeMaps),
            'tlsInvalidUrls' => array_keys(array_filter(
                $this->fetches,
                static fn (FetchResult $f): bool => $f->tlsInvalid && $f->isOk(),
            )),
        ];
    }

    /**
     * @return array<string, string> community => API file url
     */
    private function fetchDirectory(): array
    {
        $directory = null;

        $this->fetcher->fetch([self::DIRECTORY_URL], static function (string $url, string $body) use (&$directory): void {
            $directory = json_decode($body, true);
        });

        if (!is_array($directory)) {
            throw new RuntimeException('Could not load ' . self::DIRECTORY_URL);
        }

        return array_filter($directory, is_string(...));
    }

    /**
     * @param array<string, string> $directory
     * @return array{0: list<array{community: string, origin: string, declaredType: string, url: string}>, 1: array<string, string>, 2: list<string>}
     */
    private function collectEntries(array $directory): array
    {
        /** @var array<string, array<array-key, mixed>> $documents */
        $documents = [];

        $fetches = $this->fetcher->fetch(array_values($directory), static function (string $url, string $body) use (&$documents): void {
            $document = json_decode($body, true);

            if (is_array($document)) {
                $documents[$url] = $document;
            }
        });

        $entries = [];
        $apiFailures = [];
        $withoutNodeMaps = [];

        foreach ($directory as $community => $apiUrl) {
            if (!isset($documents[$apiUrl])) {
                $apiFailures[$community] = $this->describe($fetches[$apiUrl] ?? null);
                continue;
            }

            $maps = $documents[$apiUrl]['nodeMaps'] ?? null;

            if (!is_array($maps) || $maps === []) {
                $withoutNodeMaps[] = $community;
                continue;
            }

            foreach ($maps as $map) {
                if (!is_array($map) || !isset($map['url']) || !is_string($map['url']) || $map['url'] === '') {
                    continue;
                }

                $entries[] = [
                    'community' => $community,
                    'origin' => 'directory',
                    'declaredType' => strtolower(is_string($map['technicalType'] ?? null) ? $map['technicalType'] : '(none)'),
                    'url' => Discovery::withScheme($map['url']),
                ];
            }
        }

        return [$entries, $apiFailures, $withoutNodeMaps];
    }

    /**
     * @param list<string> $urls
     */
    private function probe(array $urls): void
    {
        $todo = array_values(array_filter(
            array_unique($urls),
            fn (string $url): bool => !isset($this->fetches[$url]),
        ));

        $this->fetches += $this->fetcher->fetch($todo, function (string $url, string $body): void {
            $detection = $this->detector->detect($body);
            $this->detections[$url] = $detection;
            $this->saveRaw($detection->format, $body);
        });
    }

    private function saveRaw(Format $format, string $body): void
    {
        if ($this->rawDir === null) {
            return;
        }

        $count = $this->rawSaved[$format->value] ?? 0;

        if ($count >= self::RAW_SAMPLES_PER_FORMAT) {
            return;
        }

        if (!is_dir($this->rawDir) && !mkdir($this->rawDir, 0777, true) && !is_dir($this->rawDir)) {
            throw new RuntimeException('Cannot create ' . $this->rawDir);
        }

        file_put_contents(sprintf('%s/%s-%d.raw', $this->rawDir, $format->value, $count + 1), $body);
        $this->rawSaved[$format->value] = $count + 1;
    }

    private function works(string $url): bool
    {
        return isset($this->detections[$url]) && $this->detections[$url]->hasNodes();
    }

    /**
     * @return list<string>
     */
    private function candidates(string $url, string $declaredType): array
    {
        return Discovery::candidates($url, $declaredType);
    }

    /**
     * URLs that config.json files below a map URL point to (dataPath + nodes.json).
     *
     * @return list<string>
     */
    private function dataPathUrls(string $mapUrl, string $declaredType): array
    {
        $urls = [];

        foreach ([$mapUrl, ...$this->candidates($mapUrl, $declaredType)] as $url) {
            $detection = $this->detections[$url] ?? null;

            if ($detection !== null && $detection->format === Format::FfmapConfig) {
                array_push($urls, ...Discovery::dataPathUrls($mapUrl, $detection->dataPaths));
            }
        }

        return $urls;
    }

    /**
     * @param array{community: string, origin: string, declaredType: string, url: string} $entry
     * @return array<string, mixed>
     */
    private function resolve(array $entry): array
    {
        $sequence = array_values(array_unique([
            $entry['url'],
            ...$this->candidates($entry['url'], $entry['declaredType']),
            ...$this->dataPathUrls($entry['url'], $entry['declaredType']),
        ]));

        $tried = [];
        $resolved = null;

        foreach ($sequence as $url) {
            $fetch = $this->fetches[$url] ?? null;
            $detection = $this->detections[$url] ?? null;

            $tried[] = [
                'url' => $url,
                'status' => $fetch?->status,
                'error' => $fetch?->error,
                'tlsInvalid' => $fetch !== null && $fetch->tlsInvalid,
                'seconds' => round($fetch->seconds ?? 0.0, 2),
                'format' => $detection?->format->value,
                'nodes' => $detection !== null ? $detection->nodes : 0,
            ];

            if ($detection !== null && $detection->hasNodes()) {
                $resolved = [$url, $detection];
                break;
            }
        }

        $declared = $this->detections[$entry['url']] ?? null;

        return [
            ...$entry,
            'working' => $resolved !== null,
            'resolvedUrl' => $resolved[0] ?? null,
            'format' => $resolved !== null ? $resolved[1]->format->value : $this->failureKind($entry['url']),
            'nodes' => $resolved[1]->nodes ?? 0,
            'located' => $resolved[1]->located ?? 0,
            'tlsInvalid' => $resolved !== null && ($this->fetches[$resolved[0]]->tlsInvalid ?? false),
            'declaredUrlFormat' => $declared?->format->value,
            'tried' => $tried,
        ];
    }

    /**
     * Why the declared URL did not work, as a short key for the summary.
     */
    private function failureKind(string $url): string
    {
        $fetch = $this->fetches[$url] ?? null;

        return match (true) {
            $fetch === null => 'not_probed',
            $fetch->status === null => $fetch->tlsInvalid ? 'tls_and_unreachable' : 'unreachable',
            $fetch->status !== 200 => 'http_' . $fetch->status,
            default => $this->detections[$url]->format->value ?? 'unknown',
        };
    }

    private function describe(?FetchResult $fetch): string
    {
        return match (true) {
            $fetch === null => 'not fetched',
            $fetch->status === null => $fetch->error ?? 'unreachable',
            $fetch->status !== 200 => 'HTTP ' . $fetch->status,
            default => 'not a JSON object',
        };
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param array<string, string> $apiFailures
     * @param list<string> $withoutNodeMaps
     * @return array<string, mixed>
     */
    private function summarize(array $results, int $communities, array $apiFailures, array $withoutNodeMaps): array
    {
        $byType = [];
        $workingCommunities = [];

        foreach ($results as $result) {
            $type = $result['declaredType'];
            $byType[$type] ??= ['entries' => 0, 'working' => 0, 'nodes' => 0, 'located' => 0, 'results' => []];
            $byType[$type]['entries']++;
            $byType[$type]['results'][$result['format']] = ($byType[$type]['results'][$result['format']] ?? 0) + 1;

            if ($result['working']) {
                $byType[$type]['working']++;
                $byType[$type]['nodes'] += $result['nodes'];
                $byType[$type]['located'] += $result['located'];
                $workingCommunities[$result['community']] = true;
            }
        }

        ksort($byType);

        return [
            'communities' => $communities,
            'apiFileFailed' => count($apiFailures),
            'withoutNodeMaps' => count($withoutNodeMaps),
            'communitiesWithWorkingSource' => count($workingCommunities),
            'byDeclaredType' => $byType,
        ];
    }
}
