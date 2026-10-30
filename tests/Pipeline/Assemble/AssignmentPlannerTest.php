<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline\Assemble;

use FreifunkKarte\Community\CommunityInfo;
use FreifunkKarte\Pipeline\Assemble\AssignmentPlanner;
use FreifunkKarte\Pipeline\Assemble\Choice;
use FreifunkKarte\Pipeline\Assemble\Resolution;
use FreifunkKarte\Pipeline\CommunityBuild;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\Resolve\StageKind;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Source\SourceAssignment;
use FreifunkKarte\Source\SourceAssignments;
use FreifunkKarte\Tests\Pipeline\PipelineFixtures;
use PHPUnit\Framework\TestCase;

final class AssignmentPlannerTest extends TestCase
{
    use PipelineFixtures;

    private RunState $state;

    protected function setUp(): void
    {
        $this->state = new RunState();
    }

    private static function build(string $key, bool $listed = true): CommunityBuild
    {
        return new CommunityBuild(new CommunityInfo($key, $key, ''), CommunityBuild::ORIGIN_DIRECTORY, null, $listed, CommunityReport::NO_DATA);
    }

    /**
     * @param list<string> $urls
     */
    private function leadsTo(string $community, array $urls): Choice
    {
        foreach ($urls as $url) {
            $this->state->setParsed($url, self::nodes(['1']));
        }

        return new Choice(false, [new Resolution(self::entry($community, 'https://map.example/'), $urls, StageKind::Declared)], []);
    }

    /**
     * @param list<SourceAssignment> $rules
     */
    private function planner(array $rules = []): AssignmentPlanner
    {
        return new AssignmentPlanner($this->state, new SourceAssignments($rules));
    }

    public function testWithoutRulesEveryUrlBelongsToTheCommunityThatLedToIt(): void
    {
        $plan = $this->planner()->plan(
            [self::build('a'), self::build('b')],
            [$this->leadsTo('a', ['https://x.example/1.json', 'https://x.example/2.json']), $this->leadsTo('b', ['https://y.example/1.json'])],
            [0, 1],
        );

        $this->assertSame(['a', 'a', 'b'], array_map(static fn ($s) => $s->owner, $plan->steps));
        $this->assertSame(['a' => true, 'b' => true], $plan->led);
        $this->assertSame([], $plan->notes);
    }

    public function testUrlsThatWereNotFetchedAreLeftOut(): void
    {
        $choice = new Choice(false, [new Resolution(self::entry('a', 'https://map.example/'), ['https://x.example/1.json'], StageKind::Declared)], []);

        $plan = $this->planner()->plan([self::build('a')], [$choice], [0]);

        $this->assertSame([], $plan->steps);
        $this->assertSame([], $plan->led);
    }

    public function testASourceUsedByTwoCommunitiesRemembersWhoHadItFirst(): void
    {
        $plan = $this->planner()->plan(
            [self::build('a'), self::build('b')],
            [$this->leadsTo('a', ['https://x.example/1.json']), $this->leadsTo('b', ['https://x.example/1.json'])],
            [0, 1],
        );

        $this->assertNull($plan->steps[0]->repeatOf);
        $this->assertSame('a', $plan->steps[1]->repeatOf);
        $this->assertSame('b', $plan->steps[1]->owner);
    }

    public function testTheOrderOfProcessingDecidesWhoIsFirst(): void
    {
        $plan = $this->planner()->plan(
            [self::build('a'), self::build('b')],
            [$this->leadsTo('a', ['https://x.example/1.json']), $this->leadsTo('b', ['https://x.example/1.json'])],
            [1, 0],
        );

        $this->assertSame(['b', 'a'], array_map(static fn ($s) => $s->owner, $plan->steps));
        $this->assertSame('b', $plan->steps[1]->repeatOf);
    }

    public function testARuleGivesTheDataToAnotherCommunityAndTellsWhere(): void
    {
        $plan = $this->planner([new SourceAssignment('x.example/data/b', 'b')])->plan(
            [self::build('a'), self::build('b')],
            [$this->leadsTo('a', ['https://x.example/data/b1.json', 'https://x.example/data/b2.json', 'https://x.example/data/a.json']), new Choice(false, [], [])],
            [0, 1],
        );

        $this->assertSame(['b', 'b', 'a'], array_map(static fn ($s) => $s->owner, $plan->steps));
        $this->assertSame(['2 data files of this map are assigned to community "b"'], $plan->notes['a']);
        $this->assertSame(['a' => true], $plan->led);
    }

    public function testOneDataFileAssignedAwayIsSingular(): void
    {
        $plan = $this->planner([new SourceAssignment('x.example/data/b', 'b')])->plan(
            [self::build('a'), self::build('b')],
            [$this->leadsTo('a', ['https://x.example/data/b1.json']), new Choice(false, [], [])],
            [0, 1],
        );

        $this->assertSame(['1 data file of this map is assigned to community "b"'], $plan->notes['a']);
    }

    public function testAnAssignedAddressIsPlannedOnceWhateverSchemeOrCommunityNamedIt(): void
    {
        $plan = $this->planner([new SourceAssignment('x.example/data', 'c')])->plan(
            [self::build('a'), self::build('b'), self::build('c')],
            [$this->leadsTo('a', ['http://x.example/data/1.json']), $this->leadsTo('b', ['https://x.example/data/1.json']), new Choice(false, [], [])],
            [0, 1, 2],
        );

        $this->assertCount(1, $plan->steps);
        $this->assertSame('c', $plan->steps[0]->owner);
        $this->assertNull($plan->steps[0]->repeatOf);
    }

    public function testBackfillIsPassedOn(): void
    {
        $plan = $this->planner([new SourceAssignment('x.example/all/', 'b', true)])->plan(
            [self::build('a'), self::build('b')],
            [$this->leadsTo('a', ['https://x.example/all/1.json']), new Choice(false, [], [])],
            [0, 1],
        );

        $this->assertTrue($plan->steps[0]->backfill);
    }

    public function testARuleForAnUnlistedCommunityKeepsTheDataWhereItWasFound(): void
    {
        $plan = $this->planner([new SourceAssignment('x.example/data', 'ghost')])->plan(
            [self::build('a'), self::build('ghost', listed: false)],
            [$this->leadsTo('a', ['https://x.example/data/1.json']), new Choice(false, [], [])],
            [0, 1],
        );

        $this->assertSame('a', $plan->steps[0]->owner);
        $this->assertSame(['https://x.example/data/1.json is assigned to "ghost", which is not a listed community: kept here'], $plan->notes['a']);
    }

    public function testARuleForTheCommunityItselfSaysNothing(): void
    {
        $plan = $this->planner([new SourceAssignment('x.example/data', 'a')])->plan(
            [self::build('a')],
            [$this->leadsTo('a', ['https://x.example/data/1.json'])],
            [0],
        );

        $this->assertSame('a', $plan->steps[0]->owner);
        $this->assertSame([], $plan->notes);
    }
}
