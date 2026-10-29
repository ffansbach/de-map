<?php

declare(strict_types=1);

namespace FreifunkKarte\Http;

/**
 * The URLs still to fetch, handed out so that no host has more than a few requests in flight.
 * One host can carry dozens of maps (a community network with 70 sub-communities), and servers
 * that are asked everything at once answer with errors or empty bodies.
 */
final class FetchQueue
{
    /** @var array<int, string> */
    private array $pending;

    /** @var array<string, int> */
    private array $active = [];

    /**
     * @param list<string> $urls in the order they are preferred
     */
    public function __construct(array $urls, private readonly int $perHost)
    {
        $this->pending = $urls;
    }

    /**
     * The first waiting URL whose host has room, or null if there is none right now.
     * The URL counts as in flight until {@see done()}.
     */
    public function next(): ?string
    {
        foreach ($this->pending as $index => $url) {
            $host = self::host($url);

            if (($this->active[$host] ?? 0) < $this->perHost) {
                unset($this->pending[$index]);
                $this->active[$host] = ($this->active[$host] ?? 0) + 1;

                return $url;
            }
        }

        return null;
    }

    public function done(string $url): void
    {
        $host = self::host($url);
        $this->active[$host] = max(0, ($this->active[$host] ?? 0) - 1);
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}
