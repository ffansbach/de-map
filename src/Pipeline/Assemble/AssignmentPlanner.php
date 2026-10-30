<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

use FreifunkKarte\Pipeline\CommunityBuild;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Source\SourceAssignments;

/**
 * Decides which community every data URL belongs to. By default a URL belongs to the community
 * whose map led to it; assignment rules can say otherwise, which is what a map server carrying the
 * data of many communities needs.
 */
final class AssignmentPlanner
{
    public function __construct(
        private readonly RunState $state,
        private readonly SourceAssignments $assignments,
    ) {
    }

    /**
     * @param list<CommunityBuild> $builds
     * @param array<int, Choice> $choices by index in $builds
     * @param list<int> $order indexes into $builds, see {@see ProcessingOrder}
     */
    public function plan(array $builds, array $choices, array $order): Plan
    {
        $listed = [];

        foreach ($builds as $build) {
            if ($build->listed) {
                $listed[$build->info->key] = true;
            }
        }

        $steps = [];
        $notes = [];
        $led = [];
        /** @var array<string, array<string, int>> $away community that led to data => owner => number of URLs */
        $away = [];
        /** @var array<string, true> $assigned */
        $assigned = [];
        /** @var array<string, string> $firstUser */
        $firstUser = [];

        foreach ($order as $index) {
            $key = $builds[$index]->info->key;

            foreach ($choices[$index]->resolutions as $resolution) {
                foreach ($resolution->urls as $url) {
                    if ($this->state->parsed($url) === null) {
                        continue;
                    }

                    $led[$key] = true;
                    $rule = $this->assignments->forUrl($url);

                    if ($rule !== null && !isset($listed[$rule->community])) {
                        $notes[$key][] = sprintf('%s is assigned to "%s", which is not a listed community: kept here', $url, $rule->community);
                        $rule = null;
                    }

                    if ($rule === null) {
                        $steps[] = new PlanStep($key, $url, $resolution->entry, $resolution->via, false, $firstUser[$url] ?? null);
                        $firstUser[$url] ??= $key;
                        continue;
                    }

                    if ($rule->community !== $key) {
                        $away[$key][$rule->community] = ($away[$key][$rule->community] ?? 0) + 1;
                    }

                    $identity = SourceAssignments::identity($url);

                    // http and https of the same address, or the same map named by several communities: added once
                    if (!isset($assigned[$identity])) {
                        $assigned[$identity] = true;
                        $steps[] = new PlanStep($rule->community, $url, $resolution->entry, $resolution->via, $rule->backfill);
                    }
                }
            }
        }

        foreach ($away as $key => $owners) {
            foreach ($owners as $owner => $count) {
                $notes[$key][] = sprintf('%d data %s of this map %s assigned to community "%s"', $count, $count === 1 ? 'file' : 'files', $count === 1 ? 'is' : 'are', $owner);
            }
        }

        return new Plan($steps, $notes, $led);
    }
}
