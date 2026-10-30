<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Resolve;

/**
 * Where a map stands: URLs still to fetch, resolved to data URLs, or out of options.
 */
final readonly class Walk
{
    /**
     * @param list<string> $urls to fetch (Fetch) or the data URLs (Resolved)
     */
    private function __construct(
        public WalkStatus $status,
        public array $urls,
        public ?StageKind $via,
    ) {
    }

    /**
     * @param list<string> $urls
     */
    public static function fetch(array $urls, StageKind $via): self
    {
        return new self(WalkStatus::Fetch, $urls, $via);
    }

    /**
     * @param list<string> $urls
     */
    public static function resolved(array $urls, StageKind $via): self
    {
        return new self(WalkStatus::Resolved, $urls, $via);
    }

    public static function exhausted(): self
    {
        return new self(WalkStatus::Exhausted, [], null);
    }

    public function needsFetch(): bool
    {
        return $this->status === WalkStatus::Fetch;
    }

    public function isResolved(): bool
    {
        return $this->status === WalkStatus::Resolved;
    }
}
