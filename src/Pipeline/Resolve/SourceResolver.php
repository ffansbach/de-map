<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Resolve;

use DateTimeImmutable;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Pipeline\SourceEntry;

/**
 * The third round of a run: every map is followed to its data. For each map one stage per round,
 * all maps advance together, a map stops at the first stage that yields nodes.
 */
final class SourceResolver
{
    private const MAX_ROUNDS = 8;

    public function __construct(
        private readonly MapWalker $walker,
        private readonly SourceProbe $probe,
        private readonly RunState $state,
    ) {
    }

    /**
     * @param list<SourceEntry> $entries
     * @param callable(string): void $log
     */
    public function resolve(array $entries, DateTimeImmutable $now, callable $log): void
    {
        $byCommunity = [];

        foreach ($entries as $entry) {
            $byCommunity[$entry->community][] = $entry;
        }

        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $urls = $this->urlsForRound($entries, $byCommunity, $now);

            if ($urls === []) {
                return;
            }

            $log(sprintf('round %d: %d URLs', $round, count($urls)));
            $this->probe->probe($urls, $now);
        }
    }

    /**
     * @param list<SourceEntry> $entries
     * @param array<string, list<SourceEntry>> $byCommunity
     * @return list<string>
     */
    private function urlsForRound(array $entries, array $byCommunity, DateTimeImmutable $now): array
    {
        $urls = [];
        // nothing is fetched while the round is put together, so a community's answer is the same for all its maps
        $nodelistFirst = [];

        foreach ($entries as $entry) {
            $community = $entry->community;
            $nodelistFirst[$community] ??= $this->nodelistTakesPriority($byCommunity[$community], $now);

            if ($entry->declaredType !== 'nodelist' && $nodelistFirst[$community]) {
                continue;
            }

            $walk = $this->walker->walk($entry, $now);

            if ($walk->needsFetch()) {
                array_push($urls, ...$walk->urls);
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * A community that declares a nodelist uses nothing else if the nodelist delivers. So its other
     * maps are not touched while the nodelist is still being looked at, and never if it delivers.
     *
     * @param list<SourceEntry> $maps all maps of one community
     */
    private function nodelistTakesPriority(array $maps, DateTimeImmutable $now): bool
    {
        foreach ($maps as $map) {
            if ($map->declaredType !== 'nodelist') {
                continue;
            }

            $walk = $this->walker->walk($map, $now);

            if ($walk->needsFetch() || ($walk->isResolved() && $this->state->anyNodelist($walk->urls))) {
                return true;
            }
        }

        return false;
    }
}
