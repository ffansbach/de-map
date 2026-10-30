<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

use FreifunkKarte\Model\NodeCollection;
use FreifunkKarte\Pipeline\RunState;

/**
 * Which community every data URL belongs to, and in which order the sources are added.
 */
final readonly class Plan
{
    /**
     * @param list<PlanStep> $steps in the order they were planned
     * @param array<string, list<string>> $notes community key => what to tell about sources assigned elsewhere
     * @param array<string, true> $led communities with a map that led to data (which may belong to others)
     */
    public function __construct(
        public array $steps,
        public array $notes = [],
        public array $led = [],
    ) {
    }

    /**
     * Adds the sources to the collection: the specific ones in the order of the plan, then the
     * backfill sources, which only fill what is still missing.
     *
     * @return array<string, list<AddedSource>> by owner
     */
    public function execute(NodeCollection $nodes, RunState $state): array
    {
        $specific = array_filter($this->steps, static fn (PlanStep $step): bool => !$step->backfill);
        $backfill = array_filter($this->steps, static fn (PlanStep $step): bool => $step->backfill);
        $done = [];

        foreach ([...$specific, ...$backfill] as $step) {
            $result = $state->parsed($step->url);

            if ($result === null) {
                continue;
            }

            $outcome = $nodes->addResult($step->owner, $result);
            $done[$step->owner][] = new AddedSource($step, $result, $outcome['added'], $outcome['duplicates']);
        }

        return $done;
    }
}
