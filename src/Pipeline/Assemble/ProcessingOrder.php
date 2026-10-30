<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Assemble;

use FreifunkKarte\Pipeline\CommunityBuild;

/**
 * The order in which communities add their nodes: the first to deliver a node keeps it. Communities
 * with a nodelist come first, then the other directory communities, the fixed ones last; within a
 * group the order of the directory stays.
 */
final class ProcessingOrder
{
    /**
     * @param list<CommunityBuild> $builds
     * @param array<int, Choice> $choices by index in $builds
     * @return list<int> indexes into $builds
     */
    public static function of(array $builds, array $choices): array
    {
        $groups = ['nodelist' => [], 'others' => [], 'fixed' => []];

        foreach ($builds as $index => $build) {
            $group = match (true) {
                $build->isFixed() => 'fixed',
                $choices[$index]->nodelist => 'nodelist',
                default => 'others',
            };
            $groups[$group][] = $index;
        }

        return [...$groups['nodelist'], ...$groups['others'], ...$groups['fixed']];
    }
}
