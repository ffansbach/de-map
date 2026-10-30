<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

use DateTimeImmutable;
use FreifunkKarte\Model\NodeCollection;
use FreifunkKarte\Pipeline\CommunityBuild;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\RunState;

/**
 * The last step of a run: with all maps resolved, decides what every community uses, adds the
 * nodes in priority order and reports on each community.
 */
final class CommunityAssembler
{
    public function __construct(
        private readonly RunState $state,
        private readonly MapChooser $chooser,
        private readonly AssignmentPlanner $planner,
        private readonly ReportBuilder $reports,
    ) {
    }

    /**
     * @param list<CommunityBuild> $builds
     * @return list<CommunityReport> in the order the nodes were added
     */
    public function assemble(array $builds, NodeCollection $nodes, DateTimeImmutable $now): array
    {
        $choices = [];

        foreach ($builds as $index => $build) {
            $choices[$index] = $this->chooser->choose($build, $now);
        }

        $order = ProcessingOrder::of($builds, $choices);
        $plan = $this->planner->plan($builds, $choices, $order);
        $added = $plan->execute($nodes, $this->state);
        $reports = [];

        foreach ($order as $index) {
            $key = $builds[$index]->info->key;
            $reports[] = $this->reports->build($builds[$index], $choices[$index], $added[$key] ?? [], $plan->notes[$key] ?? [], isset($plan->led[$key]), $now);
        }

        return $reports;
    }
}
