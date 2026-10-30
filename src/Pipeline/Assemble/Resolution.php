<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

use FreifunkKarte\Pipeline\Resolve\StageKind;
use FreifunkKarte\Pipeline\SourceEntry;

/**
 * A map that led to data: the data URLs, and the stage that found them.
 */
final readonly class Resolution
{
    /**
     * @param list<string> $urls
     */
    public function __construct(
        public SourceEntry $entry,
        public array $urls,
        public StageKind $via,
    ) {
    }

    /**
     * @return bool whether these data URLs were remembered by an earlier run
     */
    public function isCached(): bool
    {
        return $this->via === StageKind::Cache;
    }
}
