<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline\Assemble;

use FreifunkKarte\Model\NodeCollection;
use FreifunkKarte\Pipeline\Assemble\Plan;
use FreifunkKarte\Pipeline\Assemble\PlanStep;
use FreifunkKarte\Pipeline\Resolve\StageKind;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Tests\Pipeline\PipelineFixtures;
use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase
{
    use PipelineFixtures;

    private static function step(string $owner, string $url, bool $backfill = false): PlanStep
    {
        return new PlanStep($owner, $url, self::entry($owner, $url), StageKind::Declared, $backfill);
    }

    public function testBackfillSourcesAreAddedLastAndOnlyFillWhatIsMissing(): void
    {
        $state = new RunState();
        $state->setParsed('all', self::nodes(['1', '2', '3']));
        $state->setParsed('own', self::nodes(['2']));
        $nodes = new NodeCollection();

        $done = (new Plan([self::step('b', 'all', true), self::step('b', 'own')]))->execute($nodes, $state);

        $this->assertSame(['own', 'all'], array_map(static fn ($a) => $a->step->url, $done['b']));
        $this->assertSame([1, 2], array_map(static fn ($a) => $a->added, $done['b']));
        $this->assertSame([0, 1], array_map(static fn ($a) => $a->duplicates, $done['b']));
        $this->assertSame(3, $nodes->count());
    }

    public function testTheFirstCommunityToAddANodeKeepsIt(): void
    {
        $state = new RunState();
        $state->setParsed('u1', self::nodes(['1']));
        $state->setParsed('u2', self::nodes(['1', '2']));
        $nodes = new NodeCollection();

        $done = (new Plan([self::step('a', 'u1'), self::step('b', 'u2')]))->execute($nodes, $state);

        $this->assertSame(1, $done['a'][0]->added);
        $this->assertSame(1, $done['b'][0]->added);
        $this->assertSame(1, $done['b'][0]->duplicates);
        $this->assertSame(['a', 'b'], array_column($nodes->toRouterEntries(), 'community'));
    }

    public function testNothingPlannedNothingDone(): void
    {
        $nodes = new NodeCollection();

        $this->assertSame([], (new Plan([]))->execute($nodes, new RunState()));
        $this->assertSame(0, $nodes->count());
    }
}
