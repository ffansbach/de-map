<?php

declare(strict_types=1);

namespace FreifunkKarte\Model;

use FreifunkKarte\Parser\ParseResult;

/**
 * All nodes of a run. The first community to deliver a node keeps it.
 */
final class NodeCollection
{
    /** @var array<string, true> */
    private array $seen = [];

    /** @var list<array{id: string, lat: string, long: string, name: string, community: string, status: string, clients: int}> */
    private array $routers = [];

    /**
     * @return bool false if the node is already in the collection
     */
    public function add(Node $node, string $community): bool
    {
        $key = $node->dedupeKey();

        if (isset($this->seen[$key])) {
            return false;
        }

        $this->seen[$key] = true;
        $this->routers[] = $node->toRouterEntry($community);

        return true;
    }

    /**
     * @return array{added: int, duplicates: int}
     */
    public function addResult(string $community, ParseResult $result): array
    {
        $added = 0;

        foreach ($result->nodes as $node) {
            if ($this->add($node, $community)) {
                $added++;
            }
        }

        return ['added' => $added, 'duplicates' => count($result->nodes) - $added];
    }

    public function count(): int
    {
        return count($this->routers);
    }

    /**
     * @return list<array{id: string, lat: string, long: string, name: string, community: string, status: string, clients: int}>
     */
    public function toRouterEntries(): array
    {
        return $this->routers;
    }
}
