<?php

declare(strict_types=1);

namespace FreifunkKarte\Output;

use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\RunResult;
use FreifunkKarte\Run\NoDataReason;

/**
 * result_debug.json: what the last run found out about each community, for the public debug page
 * (web/debug.php), so that people can see why their community has no or few nodes on the map.
 * Only what the sources themselves published; nothing about the parser server.
 */
final class DebugExport
{
    /** the account of the run for one community can be long; the first lines say what matters */
    private const MAX_MESSAGES = 40;

    /**
     * @return array{generated: string, communities: array<string, array<string, mixed>>}
     */
    public function build(RunResult $result): array
    {
        $communities = [];

        foreach ($result->communities as $community) {
            $communities[$community->key] = $this->entry($community);
        }

        ksort($communities, SORT_NATURAL | SORT_FLAG_CASE);

        return ['generated' => $result->startedAt->format('c'), 'communities' => $communities];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(CommunityReport $c): array
    {
        $attempts = array_map(static fn ($a): array => [
            'url' => $a->url,
            'status' => $a->status,
            'error' => $a->error,
            'format' => $a->format,
            'nodes' => $a->nodes,
        ], $c->attempts);

        return [
            'name' => $c->name,
            'url' => $c->url,
            'meta' => $c->meta,
            'listed' => $c->listed,
            'outcome' => $c->outcome,
            'reason' => $c->outcome === CommunityReport::NO_DATA ? NoDataReason::classify($attempts)->value : null,
            'apiUrl' => $c->apiUrl,
            'declaredTypes' => $c->declaredTypes,
            'format' => $c->format,
            'sourceUrls' => $c->sourceUrls,
            'claimedNodes' => $c->claimedNodes,
            'found' => $c->found,
            'added' => $c->added,
            'duplicates' => $c->duplicates,
            'withoutLocation' => $c->withoutLocation,
            'dropped' => $c->dropped,
            'invalid' => $c->invalid,
            'staleSince' => $c->staleSince,
            'attempts' => $attempts,
            'warnings' => $c->warnings,
            'messages' => array_slice($c->messages, 0, self::MAX_MESSAGES),
        ];
    }
}
