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
     * @return array{id: string, name: string, lat: string, long: string, status: string, clients: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lat' => $this->lat,
            'long' => $this->long,
            'status' => $this->status->value,
            'clients' => $this->clients,
        ];
    }

    /**
     * @param array<array-key, mixed> $data as written by {@see toArray()}; null if it is not a node
     */
    public static function fromArray(array $data): ?self
    {
        $status = is_string($data['status'] ?? null) ? NodeStatus::tryFrom($data['status']) : null;

        if (!is_string($data['id'] ?? null) || !is_string($data['name'] ?? null) || !is_string($data['lat'] ?? null)
            || !is_string($data['long'] ?? null) || $status === null || !is_int($data['clients'] ?? null)) {
            return null;
        }

        return new self($data['id'], $data['name'], $data['lat'], $data['long'], $status, $data['clients']);
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
