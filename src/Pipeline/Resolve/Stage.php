<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Resolve;

/**
 * The URLs to look at in one step of following a map.
 */
final readonly class Stage
{
    /**
     * @param list<string> $urls
     */
    public function __construct(
        public StageKind $kind,
        public array $urls,
    ) {
    }
}
