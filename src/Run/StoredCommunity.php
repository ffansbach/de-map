<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

/**
 * A community as one earlier run left it in the history.
 */
final readonly class StoredCommunity
{
    /**
     * @param list<string> $declaredTypes
     * @param list<string> $sourceUrls
     * @param list<string> $warnings
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $outcome,
        public ?string $format,
        public bool $listed,
        public int $found,
        public int $added,
        public int $duplicates,
        public ?string $staleSince,
        public array $declaredTypes,
        public array $sourceUrls,
        public array $warnings,
    ) {
    }

    /**
     * Nodes the community's sources delivered, whoever kept them.
     */
    public function accepted(): int
    {
        return $this->added + $this->duplicates;
    }
}
