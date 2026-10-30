<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

use FreifunkKarte\Parser\ParseResult;

/**
 * A planned source after its nodes were added to the collection.
 */
final readonly class AddedSource
{
    public function __construct(
        public PlanStep $step,
        public ParseResult $result,
        public int $added,
        public int $duplicates,
    ) {
    }
}
