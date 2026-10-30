<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

use FreifunkKarte\Pipeline\CommunityReport;

/**
 * Compares a run with the one before and says what a person should look at. This space changes
 * a lot - new map software, moved data, formats that drift - and a run that quietly delivers
 * less than yesterday is the thing to catch.
 *
 * Nothing here stops a run; the severities decide how loud the notification is:
 *  info     worth knowing, goes to the log and the history
 *  warning  look at it when you have a minute (new technical type, format change, nodes dropped)
 *  alert    look at it now (the run lost a fifth of the nodes, or many communities at once)
 */
final class DriftDetector
{
    /**
     * @param int $minNodesForDrop a community needs this many nodes before a drop is worth a warning
     * @param float $dropRatio a community that delivers at most this share of its previous nodes has dropped
     * @param float $runDropRatio a run with less than this share of the previous run's nodes is an alert
     * @param int $manyLost this many communities lost in one run is an alert
     */
    public function __construct(
        private readonly int $minNodesForDrop = 20,
        private readonly float $dropRatio = 0.5,
        private readonly float $runDropRatio = 0.8,
        private readonly int $manyLost = 5,
    ) {
    }

    /**
     * @param list<CommunityReport> $current
     * @param array<string, StoredCommunity> $previous the communities of the previous run, empty if there is none
     * @param list<string> $knownTypes technicalTypes seen in earlier runs
     * @return list<DriftEvent> empty for the very first run: there is nothing to compare with
     */
    public function detect(array $current, array $previous, array $knownTypes, int $nodes, ?int $previousNodes): array
    {
        if ($previous === [] && $knownTypes === []) {
            return [];
        }

        $events = [...$this->newTypes($current, $knownTypes)];
        $lost = 0;

        foreach ($current as $report) {
            $before = $previous[$report->key] ?? null;

            if ($before === null) {
                if ($report->listed) {
                    $events[] = new DriftEvent(DriftEvent::COMMUNITY_ADDED, DriftEvent::INFO, $report->key, sprintf('New community "%s" (%s)', $report->name, $report->outcome));
                }

                continue;
            }

            $lost += $this->wasLost($report, $before) ? 1 : 0;
            array_push($events, ...$this->community($report, $before));
        }

        foreach ($previous as $key => $before) {
            if (!$this->isCurrent($current, $key) && $before->listed) {
                $events[] = new DriftEvent(DriftEvent::COMMUNITY_REMOVED, DriftEvent::INFO, $key, sprintf('Community "%s" is no longer in the directory', $before->name));
            }
        }

        if ($lost >= $this->manyLost) {
            $events[] = new DriftEvent(DriftEvent::MANY_COMMUNITIES_LOST, DriftEvent::ALERT, 'run', sprintf('%d communities that had nodes last time deliver none now', $lost), ['lost' => $lost]);
        }

        if ($previousNodes !== null && $previousNodes > 0 && $nodes < $this->runDropRatio * $previousNodes) {
            $events[] = new DriftEvent(
                DriftEvent::RUN_NODES_DROPPED,
                DriftEvent::ALERT,
                'run',
                sprintf('The run found %d nodes, the one before %d (%d%%)', $nodes, $previousNodes, (int) round(100 * $nodes / $previousNodes)),
                ['before' => $previousNodes, 'after' => $nodes],
            );
        }

        return $events;
    }

    /**
     * @param list<CommunityReport> $current
     * @param list<string> $knownTypes
     * @return list<DriftEvent>
     */
    private function newTypes(array $current, array $knownTypes): array
    {
        if ($knownTypes === []) {
            return [];
        }

        $users = [];

        foreach ($current as $report) {
            foreach (array_unique($report->declaredTypes) as $type) {
                if (!in_array($type, $knownTypes, true)) {
                    $users[$type][] = $report->key;
                }
            }
        }

        $events = [];

        foreach ($users as $type => $communities) {
            $events[] = new DriftEvent(
                DriftEvent::NEW_TYPE,
                DriftEvent::WARNING,
                (string) $type,
                sprintf('Technical type "%s" has not been seen before (used by %s)', $type, $this->shortList($communities)),
                ['communities' => $communities],
            );
        }

        return $events;
    }

    /**
     * @return list<DriftEvent>
     */
    private function community(CommunityReport $now, StoredCommunity $before): array
    {
        $events = [];
        $wasOk = $before->outcome === CommunityReport::OK;
        $isOk = $now->outcome === CommunityReport::OK;

        if ($wasOk && !$isOk && $now->outcome !== CommunityReport::SKIPPED) {
            $events[] = new DriftEvent(
                DriftEvent::COMMUNITY_LOST,
                DriftEvent::WARNING,
                $now->key,
                sprintf('"%s" delivered %d nodes last time and nothing now (%s)', $now->name, $before->accepted(), $now->outcome),
                ['outcome' => $now->outcome, 'before' => $before->accepted()],
            );
        }

        if (!$wasOk && $isOk && in_array($before->outcome, [CommunityReport::NO_DATA, CommunityReport::API_UNAVAILABLE], true)) {
            $events[] = new DriftEvent(DriftEvent::COMMUNITY_RECOVERED, DriftEvent::INFO, $now->key, sprintf('"%s" delivers nodes again (was: %s)', $now->name, $before->outcome));
        }

        if ($now->staleSince !== null) {
            $events[] = new DriftEvent(
                DriftEvent::STALE_DATA,
                $before->staleSince === null ? DriftEvent::WARNING : DriftEvent::INFO,
                $now->key,
                sprintf('"%s": the source has trouble, showing data saved at %s', $now->name, $now->staleSince),
                ['staleSince' => $now->staleSince],
            );
        }

        if ($wasOk && $isOk && $now->staleSince === null) {
            array_push($events, ...$this->changesOfAWorkingCommunity($now, $before));
        }

        $newKinds = array_values(array_diff($this->warningKinds($now->warnings), $this->warningKinds($before->warnings)));

        if ($newKinds !== []) {
            $events[] = new DriftEvent(DriftEvent::NEW_WARNING, DriftEvent::INFO, $now->key, sprintf('"%s": new kind of warning about its source: %s', $now->name, implode('; ', $newKinds)), ['kinds' => $newKinds]);
        }

        return $events;
    }

    /**
     * @return list<DriftEvent>
     */
    private function changesOfAWorkingCommunity(CommunityReport $now, StoredCommunity $before): array
    {
        $events = [];

        if ($before->format !== null && $now->format !== null && $before->format !== $now->format) {
            $events[] = new DriftEvent(
                DriftEvent::FORMAT_CHANGED,
                DriftEvent::WARNING,
                $now->key,
                sprintf('"%s" used to deliver %s and now delivers %s', $now->name, $before->format, $now->format),
                ['before' => $before->format, 'after' => $now->format, 'sources' => $now->sourceUrls],
            );
        }

        $nowAccepted = $now->added + $now->duplicates;

        if ($before->accepted() >= $this->minNodesForDrop && $nowAccepted <= $this->dropRatio * $before->accepted()) {
            $events[] = new DriftEvent(
                DriftEvent::NODES_DROPPED,
                DriftEvent::WARNING,
                $now->key,
                sprintf('"%s" delivers %d nodes, last time %d', $now->name, $nowAccepted, $before->accepted()),
                ['before' => $before->accepted(), 'after' => $nowAccepted],
            );
        }

        return $events;
    }

    private function wasLost(CommunityReport $now, StoredCommunity $before): bool
    {
        // skipping a community is the operator's decision, not something that was lost
        return $before->outcome === CommunityReport::OK && !in_array($now->outcome, [CommunityReport::OK, CommunityReport::SKIPPED], true);
    }

    /**
     * @param list<CommunityReport> $current
     */
    private function isCurrent(array $current, string $key): bool
    {
        foreach ($current as $report) {
            if ($report->key === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $items
     */
    private function shortList(array $items, int $shown = 5): string
    {
        return count($items) <= $shown
            ? implode(', ', $items)
            : implode(', ', array_slice($items, 0, $shown)) . sprintf(' and %d more', count($items) - $shown);
    }

    /**
     * Warnings differ in numbers and names only; the kind is what is new or not.
     *
     * @param list<string> $warnings
     * @return list<string>
     */
    private function warningKinds(array $warnings): array
    {
        $kinds = [];

        foreach ($warnings as $warning) {
            $kind = preg_replace(['/\d+/', '/"[^"]*"/'], ['N', '"X"'], $warning) ?? $warning;
            $kinds[mb_substr($kind, 0, 120)] = true;
        }

        return array_map('strval', array_keys($kinds));
    }
}
