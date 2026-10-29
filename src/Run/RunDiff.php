<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

/**
 * What changed between two runs, community by community. Only what a person would care about:
 * another outcome, another format, clearly more or fewer nodes, communities coming and going.
 */
final class RunDiff
{
    /** a change of node count is shown if it is at least this many nodes ... */
    private const MIN_NODE_CHANGE = 5;
    /** ... and at least this share of the earlier count */
    private const MIN_NODE_SHARE = 0.1;

    /**
     * @param array<string, StoredCommunity> $before
     * @param array<string, StoredCommunity> $after
     * @return list<CommunityChange> ordered by community key
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($keys);

        foreach ($keys as $key) {
            $key = (string) $key;
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old === null && $new !== null && $new->listed) {
                $changes[] = new CommunityChange($key, $new->name, CommunityChange::ADDED, '', $new->outcome);
            } elseif ($new === null && $old !== null && $old->listed) {
                $changes[] = new CommunityChange($key, $old->name, CommunityChange::REMOVED, $old->outcome, '');
            } elseif ($old !== null && $new !== null) {
                array_push($changes, ...self::compare($old, $new));
            }
        }

        return $changes;
    }

    /**
     * @return list<CommunityChange>
     */
    private static function compare(StoredCommunity $old, StoredCommunity $new): array
    {
        $changes = [];

        if ($old->outcome !== $new->outcome) {
            $changes[] = new CommunityChange($new->key, $new->name, CommunityChange::OUTCOME, $old->outcome, $new->outcome);
        }

        if ($old->format !== null && $new->format !== null && $old->format !== $new->format) {
            $changes[] = new CommunityChange($new->key, $new->name, CommunityChange::FORMAT, $old->format, $new->format);
        }

        $before = $old->accepted();
        $after = $new->accepted();

        if ($old->outcome === $new->outcome && abs($after - $before) >= max(self::MIN_NODE_CHANGE, self::MIN_NODE_SHARE * $before)) {
            $changes[] = new CommunityChange($new->key, $new->name, CommunityChange::NODES, (string) $before, (string) $after);
        }

        return $changes;
    }
}
