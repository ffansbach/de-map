<?php

declare(strict_types=1);

namespace FreifunkKarte\Model;

/**
 * One node on the map. Coordinates stay strings: that is what result_routers.json has always
 * contained and what js/meta_map.js is fed.
 */
final readonly class Node
{
    public function __construct(
        public string $id,
        public string $name,
        public string $lat,
        public string $long,
        public NodeStatus $status,
        public int $clients,
    ) {
    }

    /**
     * Two sources describing the same node (same name, id and position) give the same key.
     * The formula is the legacy one, so deduplication stays comparable.
     */
    public function dedupeKey(): string
    {
        return md5($this->name . $this->id . $this->lat . $this->long);
    }

    /**
     * The entry in result_routers.json.
     *
     * @return array{id: string, lat: string, long: string, name: string, community: string, status: string, clients: int}
     */
    public function toRouterEntry(string $community): array
    {
        return [
            'id' => $this->id,
            'lat' => $this->lat,
            'long' => $this->long,
            'name' => $this->name,
            'community' => $community,
            'status' => $this->status->value,
            'clients' => $this->clients,
        ];
    }
}
