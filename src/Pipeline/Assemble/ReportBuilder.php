<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

use DateTimeImmutable;
use FreifunkKarte\Pipeline\CommunityBuild;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\Resolve\MapWalker;
use FreifunkKarte\Pipeline\Resolve\StageKind;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Pipeline\SourceAttempt;

/**
 * Writes the account of a community's run: the outcome, the counts, what was tried, what to tell a person.
 */
final class ReportBuilder
{
    public function __construct(
        private readonly RunState $state,
        private readonly MapWalker $walker,
    ) {
    }

    /**
     * @param list<AddedSource> $owned the sources whose nodes belong to this community
     * @param list<string> $notes what to tell about sources that were assigned elsewhere
     * @param bool $led whether a map of this community led to data at all (which may belong to others)
     */
    public function build(CommunityBuild $build, Choice $choice, array $owned, array $notes, bool $led, DateTimeImmutable $now): CommunityReport
    {
        $messages = [...$build->messages, ...$notes];
        $warnings = [];
        $sourceUrls = [];
        $counts = ['found' => 0, 'added' => 0, 'duplicates' => 0, 'withoutLocation' => 0, 'dropped' => 0, 'invalid' => 0];
        $format = null;
        $viaCache = false;
        $staleSince = null;

        foreach ($owned as $source) {
            $step = $source->step;
            $result = $source->result;
            $sourceUrls[] = $step->url;
            $format ??= $result->format->value;
            $viaCache = $viaCache || $step->via === StageKind::Cache;
            $counts['found'] += $result->found;
            $counts['added'] += $source->added;
            $counts['duplicates'] += $source->duplicates;
            $counts['withoutLocation'] += $result->withoutLocation;
            $counts['dropped'] += $result->dropped;
            $counts['invalid'] += $result->invalid;
            $staleSince = $this->state->staleSince($step->url) ?? $staleSince;
            $messages[] = sprintf('%s (%s%s%s): %s', $step->url, $step->entry->declaredType, $step->via === StageKind::Cache ? ', cached' : '', $step->backfill ? ', backfill' : '', $result->summary());

            if ($step->repeatOf !== null) {
                // the oddities of a shared source are reported where it was used first
                $messages[] = sprintf('%s was already used by "%s", its nodes belong there', $step->url, $step->repeatOf);
            } else {
                array_push($warnings, ...$result->warnings);
            }
        }

        foreach ($choice->unused as $unused) {
            $messages[] = sprintf('not used: %s (%s) - %s', implode(', ', $unused->urls), $unused->entry->declaredType, $choice->nodelist ? 'the community has a nodelist, which takes priority' : 'another map of this community was used');
        }

        $delivered = $sourceUrls !== [] || $led;

        if (!$delivered) {
            foreach ($build->entries as $entry) {
                $messages[] = sprintf('no data found for %s (%s)', $entry->mapUrl, $entry->declaredType);
            }
        }

        foreach ($warnings as $warning) {
            $messages[] = 'warning: ' . $warning;
        }

        return new CommunityReport(
            $build->info->key,
            $build->info->name,
            $build->info->url,
            $build->info->meta,
            $build->origin,
            $build->apiUrl,
            $build->listed,
            $delivered ? CommunityReport::OK : $build->outcome,
            $build->declaredTypes,
            $sourceUrls,
            $format,
            $viaCache,
            $counts['found'],
            $counts['added'],
            $counts['duplicates'],
            $counts['withoutLocation'],
            $counts['dropped'],
            $counts['invalid'],
            $this->attempts($build, $now),
            $warnings,
            $messages,
            $build->claimedNodes,
            $this->staleSince($build->info->key, $staleSince)?->format('c'),
        );
    }

    /**
     * The older of the nodes' copy and the API file's copy: that is how long a community has been shown from copies.
     */
    private function staleSince(string $key, ?DateTimeImmutable $nodesSince): ?DateTimeImmutable
    {
        $apiSince = $this->state->apiStaleSince($key);

        return $apiSince !== null && ($nodesSince === null || $apiSince < $nodesSince) ? $apiSince : $nodesSince;
    }

    /**
     * Every URL tried for the maps of a community, once, with what came back.
     *
     * @return list<SourceAttempt>
     */
    private function attempts(CommunityBuild $build, DateTimeImmutable $now): array
    {
        $attempts = [];

        foreach ($build->entries as $entry) {
            foreach ($this->walker->stages($entry, $now) as $stage) {
                foreach ($stage->urls as $url) {
                    $fetch = $this->state->fetch($url);

                    if ($fetch === null || isset($attempts[$url])) {
                        continue;
                    }

                    $parsed = $this->state->parsed($url);
                    $attempts[$url] = new SourceAttempt($url, $fetch->status, $fetch->error, $parsed?->format->value, $parsed !== null ? $parsed->found : 0, $fetch->tlsInvalid);
                }
            }
        }

        return array_values($attempts);
    }
}
