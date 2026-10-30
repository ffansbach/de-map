<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline\Resolve;

use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\Resolve\MapWalker;
use FreifunkKarte\Pipeline\Resolve\SourceProbe;
use FreifunkKarte\Pipeline\Resolve\SourceResolver;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Pipeline\SourceEntry;
use FreifunkKarte\Source\SourceCache;
use FreifunkKarte\Tests\Pipeline\PipelineFixtures;
use PHPUnit\Framework\TestCase;

final class SourceResolverTest extends TestCase
{
    use PipelineFixtures;

    private RunState $state;

    /** @var list<string> */
    private array $log = [];

    /**
     * @param list<SourceEntry> $entries
     * @param array<string, string|int|null> $answers
     */
    private function resolve(array $entries, array $answers): void
    {
        $this->state = new RunState();
        $walker = new MapWalker($this->state, new SourceCache());
        $probe = new SourceProbe($this->fetcher($answers), new SourceParser(), $this->state, null, 0);
        $this->log = [];

        (new SourceResolver($walker, $probe, $this->state))->resolve($entries, self::now(), function (string $message): void {
            $this->log[] = $message;
        });
    }

    public function testADeclaredDataUrlTakesOneRound(): void
    {
        $this->resolve([self::entry('town', 'https://map.example/nodes.json')], ['https://map.example/nodes.json' => self::nodeInfoBody()]);

        $this->assertSame(['round 1: 1 URLs'], $this->log);
        $this->assertTrue($this->state->hasNodes('https://map.example/nodes.json'));
    }

    public function testAMapPageIsFollowedThroughConfigToItsData(): void
    {
        $this->resolve([self::entry('town', 'https://map.example/')], [
            'https://map.example/' => '<html>map</html>',
            'https://map.example/config.json' => (string) json_encode(['dataPath' => 'data/']),
            'https://map.example/data/meshviewer.json' => self::nodeInfoBody(),
        ]);

        $this->assertSame(['round 1: 1 URLs', 'round 2: 1 URLs', 'round 3: 2 URLs'], $this->log);
        $this->assertTrue($this->state->hasNodes('https://map.example/data/meshviewer.json'));
    }

    public function testAllMapsAdvanceTogetherAndSharedUrlsAreFetchedOnce(): void
    {
        $this->resolve([
            self::entry('a', 'https://map.example/nodes.json'),
            self::entry('b', 'https://map.example/nodes.json'),
            self::entry('c', 'https://other.example/nodes.json'),
        ], [
            'https://map.example/nodes.json' => self::nodeInfoBody(),
            'https://other.example/nodes.json' => self::nodeInfoBody(),
        ]);

        $this->assertSame(['round 1: 2 URLs'], $this->log);
        $this->assertSame(2, $this->state->requests());
    }

    public function testStopsWhenNothingIsLeftToTry(): void
    {
        $this->resolve([self::entry('town', 'https://dead.example/', 'nodelist')], ['https://dead.example/' => null]);

        $this->assertSame(['round 1: 1 URLs'], $this->log);
    }

    public function testOtherMapsOfACommunityWaitWhileItsNodelistIsLookedAt(): void
    {
        $this->resolve([
            self::entry('town', 'https://list.example/nodelist.json', 'nodelist'),
            self::entry('town', 'https://map.example/nodes.json'),
        ], [
            'https://list.example/nodelist.json' => self::nodelistBody(),
            'https://map.example/nodes.json' => self::nodeInfoBody(),
        ]);

        $this->assertSame(['round 1: 1 URLs'], $this->log);
        $this->assertFalse($this->state->isFetched('https://map.example/nodes.json'), 'the nodelist delivers: the other map is never touched');
    }

    public function testOtherMapsAreUsedWhenTheNodelistDoesNotDeliver(): void
    {
        $this->resolve([
            self::entry('town', 'https://list.example/nodelist.json', 'nodelist'),
            self::entry('town', 'https://map.example/nodes.json'),
        ], [
            'https://list.example/nodelist.json' => 404,
            'https://map.example/nodes.json' => self::nodeInfoBody(),
        ]);

        $this->assertSame(['round 1: 1 URLs', 'round 2: 1 URLs'], $this->log);
        $this->assertTrue($this->state->hasNodes('https://map.example/nodes.json'));
    }

    public function testANodelistDoesNotHoldBackOtherCommunities(): void
    {
        $this->resolve([
            self::entry('a', 'https://list.example/nodelist.json', 'nodelist'),
            self::entry('b', 'https://map.example/nodes.json'),
        ], [
            'https://list.example/nodelist.json' => self::nodelistBody(),
            'https://map.example/nodes.json' => self::nodeInfoBody(),
        ]);

        $this->assertSame(['round 1: 2 URLs'], $this->log);
    }
}
