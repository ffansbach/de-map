<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Resolve;

use DateTimeImmutable;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Pipeline\SourceEntry;
use FreifunkKarte\Source\Discovery;
use FreifunkKarte\Source\SourceCache;

/**
 * Follows a map stage by stage to its data, on what the run knows so far. It never fetches: it
 * says which URLs it needs next, and once they have been fetched where the map stands.
 */
final class MapWalker
{
    public function __construct(
        private readonly RunState $state,
        private readonly SourceCache $cache,
    ) {
    }

    /**
     * The first stage that still has URLs to fetch, else the first that yields nodes, else exhausted.
     */
    public function walk(SourceEntry $entry, DateTimeImmutable $now): Walk
    {
        foreach ($this->stages($entry, $now) as $stage) {
            $unfetched = array_values(array_filter($stage->urls, fn (string $url): bool => !$this->state->isFetched($url)));

            if ($unfetched !== []) {
                return Walk::fetch($unfetched, $stage->kind);
            }

            $hits = $this->hits($stage);

            if ($hits !== []) {
                return Walk::resolved($hits, $stage->kind);
            }

            if ($stage->kind === StageKind::Cache) {
                // what we remembered does not deliver any more: look again from the start
                $this->cache->forget($entry->mapUrl);
            }

            if ($stage->kind === StageKind::Declared && $this->state->fetch($entry->mapUrl)?->status === null) {
                // the host does not answer at all: its other paths will not either
                break;
            }
        }

        return Walk::exhausted();
    }

    /**
     * The URLs to look at for a map, stage by stage, in order of preference.
     *
     * @return list<Stage>
     */
    public function stages(SourceEntry $entry, DateTimeImmutable $now): array
    {
        $stages = [];
        $cached = $this->cache->get($entry->mapUrl, $now);

        if ($cached !== null) {
            $stages[] = new Stage(StageKind::Cache, $cached);
        }

        $stages[] = new Stage(StageKind::Declared, [$entry->mapUrl]);
        $candidates = Discovery::candidates($entry->mapUrl, $entry->declaredType);

        if ($candidates === []) {
            return $stages;
        }

        $config = Discovery::baseUrl($entry->mapUrl) . 'config.json';
        $stages[] = new Stage(StageKind::Config, [$config]);
        $stages[] = new Stage(StageKind::DataPath, Discovery::dataPathUrls($entry->mapUrl, $this->dataPaths([$config, $entry->mapUrl])));
        $stages[] = new Stage(StageKind::Candidates, array_values(array_diff($candidates, [$config])));

        return $stages;
    }

    /**
     * The data URLs of a stage that deliver nodes, as many as the stage may use.
     *
     * @return list<string>
     */
    private function hits(Stage $stage): array
    {
        $withNodes = array_values(array_filter($stage->urls, $this->state->hasNodes(...)));

        return match ($stage->kind) {
            StageKind::Candidates => array_slice($withNodes, 0, 1),
            // meshviewer.json and nodes.json of one directory are the same data: the first is enough
            StageKind::DataPath => self::firstPerDirectory($withNodes),
            default => $withNodes,
        };
    }

    /**
     * The data paths come from config.json or, for pages that carry their configuration inline, from the page.
     *
     * @param list<string> $sources
     * @return list<string>
     */
    private function dataPaths(array $sources): array
    {
        $paths = [];

        foreach ($sources as $source) {
            array_push($paths, ...($this->state->parsed($source)->dataPaths ?? []));
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private static function firstPerDirectory(array $urls): array
    {
        $first = [];

        foreach ($urls as $url) {
            $first[dirname($url)] ??= $url;
        }

        return array_values($first);
    }
}
