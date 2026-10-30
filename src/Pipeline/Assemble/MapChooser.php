<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

use DateTimeImmutable;
use FreifunkKarte\Pipeline\CommunityBuild;
use FreifunkKarte\Pipeline\Resolve\MapWalker;
use FreifunkKarte\Pipeline\Resolve\StageKind;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Source\SourceCache;

/**
 * Picks the map data of a community: all of its nodelists if it has any, else the first map that
 * delivers. Where the data was found by probing, the URLs are remembered for the next run.
 */
final class MapChooser
{
    public function __construct(
        private readonly MapWalker $walker,
        private readonly RunState $state,
        private readonly SourceCache $cache,
    ) {
    }

    public function choose(CommunityBuild $build, DateTimeImmutable $now): Choice
    {
        $resolutions = [];

        foreach ($build->entries as $entry) {
            $walk = $this->walker->walk($entry, $now);

            if (!$walk->isResolved() || $walk->via === null) {
                continue;
            }

            $resolutions[] = new Resolution($entry, $walk->urls, $walk->via);

            if ($walk->via === StageKind::DataPath || $walk->via === StageKind::Candidates) {
                $this->cache->remember($entry->mapUrl, $walk->urls, $now);
            }
        }

        $nodelists = array_values(array_filter($resolutions, fn (Resolution $r): bool => $this->state->anyNodelist($r->urls)));

        if ($nodelists !== []) {
            $nodelistMaps = array_map(static fn (Resolution $r): string => $r->entry->mapUrl, $nodelists);
            $others = array_values(array_filter($resolutions, static fn (Resolution $r): bool => !in_array($r->entry->mapUrl, $nodelistMaps, true)));

            return new Choice(true, $nodelists, $others);
        }

        return new Choice(false, array_slice($resolutions, 0, 1), array_slice($resolutions, 1));
    }
}
