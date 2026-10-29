<?php

declare(strict_types=1);

namespace FreifunkKarte\Source;

final readonly class SourceAssignment
{
    /**
     * @param string $prefix start of the data URL without the scheme, e.g. "map.example.org/data/siegburg"
     * @param string $community key of the community the nodes belong to
     * @param bool $backfill add these nodes after everybody else's, to fill what the specific sources did not have
     */
    public function __construct(
        public string $prefix,
        public string $community,
        public bool $backfill = false,
    ) {
    }
}
