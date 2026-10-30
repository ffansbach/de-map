<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

use FreifunkKarte\Pipeline\Resolve\StageKind;
use FreifunkKarte\Pipeline\SourceEntry;

/**
 * One data URL and the community its nodes are added to.
 */
final readonly class PlanStep
{
    /**
     * @param string $owner community key the nodes belong to
     * @param SourceEntry $entry the map that led to the URL
     * @param bool $backfill only fills what the specific sources did not have
     * @param string|null $repeatOf community that used the same URL before, if any
     */
    public function __construct(
        public string $owner,
        public string $url,
        public SourceEntry $entry,
        public StageKind $via,
        public bool $backfill = false,
        public ?string $repeatOf = null,
    ) {
    }
}
