<?php

declare(strict_types=1);

namespace FreifunkKarte\Output;

/**
 * Compares two sets of result files (for example two runs) by what is on the map.
 */
final class ResultComparer
{
    /**
     * @param list<array<string, mixed>> $oldRouters
     * @param list<array<string, mixed>> $newRouters
     * @param array<string, array<string, mixed>> $oldCommunities
     * @param array<string, array<string, mixed>> $newCommunities
     * @return array{
     *     oldNodes: int, newNodes: int, common: int,
     *     onlyOld: array<string, int>, onlyNew: array<string, int>,
     *     communitiesOnlyOld: list<string>, communitiesOnlyNew: list<string>, communitiesChanged: list<string>,
     *     samplesOnlyOld: list<string>, samplesOnlyNew: list<string>
     * }
     */
    public function compare(array $oldRouters, array $newRouters, array $oldCommunities, array $newCommunities): array
    {
        $old = $this->index($oldRouters);
        $new = $this->index($newRouters);
        $onlyOld = array_diff_key($old, $new);
        $onlyNew = array_diff_key($new, $old);

        $changed = [];

        foreach (array_intersect_key($oldCommunities, $newCommunities) as $key => $community) {
            if ($community !== $newCommunities[$key]) {
                $changed[] = (string) $key;
            }
        }

        return [
            'oldNodes' => count($old),
            'newNodes' => count($new),
            'common' => count(array_intersect_key($old, $new)),
            'onlyOld' => $this->perCommunity($onlyOld),
            'onlyNew' => $this->perCommunity($onlyNew),
            'communitiesOnlyOld' => array_map('strval', array_keys(array_diff_key($oldCommunities, $newCommunities))),
            'communitiesOnlyNew' => array_map('strval', array_keys(array_diff_key($newCommunities, $oldCommunities))),
            'communitiesChanged' => $changed,
            'samplesOnlyOld' => $this->samples($onlyOld),
            'samplesOnlyNew' => $this->samples($onlyNew),
        ];
    }

    /**
     * A node is identified the way the parsers deduplicate it: name, id and position.
     *
     * @param list<array<string, mixed>> $routers
     * @return array<string, array<string, mixed>>
     */
    private function index(array $routers): array
    {
        $index = [];

        foreach ($routers as $router) {
            $key = md5(self::text($router['name'] ?? '') . self::text($router['id'] ?? '') . self::text($router['lat'] ?? '') . self::text($router['long'] ?? ''));
            $index[$key] = $router;
        }

        return $index;
    }

    /**
     * @param array<string, array<string, mixed>> $routers
     * @return array<string, int> community => nodes, most first
     */
    private function perCommunity(array $routers): array
    {
        $counts = array_count_values(array_map(static fn (array $r): string => self::text($r['community'] ?? ''), $routers));
        arsort($counts);

        return $counts;
    }

    /**
     * @param array<string, array<string, mixed>> $routers
     * @return list<string>
     */
    private function samples(array $routers): array
    {
        return array_values(array_map(
            static fn (array $r): string => sprintf('%s: %s (%s)', self::text($r['community'] ?? ''), self::text($r['name'] ?? ''), self::text($r['id'] ?? '')),
            array_slice($routers, 0, 10),
        ));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
