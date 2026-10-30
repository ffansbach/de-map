<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline;

use FreifunkKarte\Pipeline\CommunityBuilder;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\RunState;
use FreifunkKarte\Source\ApiDocumentStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommunityBuilderTest extends TestCase
{
    use PipelineFixtures;

    private const DIRECTORY = 'https://directory.example/directory.json';

    private RunState $state;

    protected function setUp(): void
    {
        $this->state = new RunState();
    }

    /**
     * @param array<string, string|int|null> $answers
     */
    private function builder(array $answers, ?ApiDocumentStore $store = null): CommunityBuilder
    {
        return new CommunityBuilder($this->fetcher($answers), $this->state, self::DIRECTORY, $store);
    }

    private static function api(string $name = 'Town'): string
    {
        return (string) json_encode(['name' => $name, 'nodeMaps' => [['url' => 'https://map.example/', 'technicalType' => 'meshviewer']]]);
    }

    public function testDirectoryIsFetchedAndRecorded(): void
    {
        $directory = $this->builder([self::DIRECTORY => (string) json_encode(['a' => 'https://api/a.json', 'b' => 'https://api/b.json'])])->directory(null);

        $this->assertSame(['a' => 'https://api/a.json', 'b' => 'https://api/b.json'], $directory);
        $this->assertTrue($this->state->isFetched(self::DIRECTORY));
        $this->assertSame(1, $this->state->requests());
    }

    public function testOnlyNarrowsTheDirectory(): void
    {
        $answers = [self::DIRECTORY => (string) json_encode(['a' => 'https://api/a.json', 'b' => 'https://api/b.json'])];

        $this->assertSame(['b' => 'https://api/b.json'], $this->builder($answers)->directory('b'));
        $this->assertSame([], $this->builder($answers)->directory('unknown'));
    }

    public function testAMissingDirectoryStopsTheRun(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Community directory not available: ' . self::DIRECTORY);

        $this->builder([self::DIRECTORY => 503])->directory(null);
    }

    public function testBuildsEveryCommunityInDirectoryOrder(): void
    {
        $builds = $this->builder(['https://api/a.json' => self::api('A'), 'https://api/b.json' => 'not json', 'https://api/c.json' => 404])
            ->fromDirectory(['a' => 'https://api/a.json', 'b' => 'https://api/b.json', 'c' => 'https://api/c.json'], [], self::now());

        $this->assertSame(['a', 'b', 'c'], array_map(static fn ($b) => $b->info->key, $builds));
        $this->assertSame('A', $builds[0]->info->name);
        $this->assertTrue($builds[0]->listed);
        $this->assertSame(CommunityReport::API_UNAVAILABLE, $builds[1]->outcome);
        $this->assertSame(['API file not available: not a JSON object'], $builds[1]->messages);
        $this->assertSame(['API file not available: HTTP 404'], $builds[2]->messages);
    }

    public function testSkippedCommunitiesAreNotFetched(): void
    {
        $builds = $this->builder(['https://api/b.json' => self::api()])
            ->fromDirectory(['a' => 'https://api/a.json', 'b' => 'https://api/b.json'], ['a'], self::now());

        $this->assertSame(CommunityReport::SKIPPED, $builds[0]->outcome);
        $this->assertSame(['https://api/b.json'], $this->requested);
    }

    public function testASuccessfulApiFileIsKeptForLater(): void
    {
        $store = new ApiDocumentStore();
        $this->builder(['https://api/a.json' => self::api()], $store)->fromDirectory(['a' => 'https://api/a.json'], [], self::now());

        $this->assertNotNull($store->load('https://api/a.json', self::now()));
    }

    public function testADocumentWithoutNodeMapsIsNotKept(): void
    {
        $store = new ApiDocumentStore();
        $this->builder(['https://api/a.json' => (string) json_encode(['name' => 'A'])], $store)->fromDirectory(['a' => 'https://api/a.json'], [], self::now());

        $this->assertNull($store->load('https://api/a.json', self::now()));
    }

    public function testAServerWithTroubleShowsTheCopyOfAnEarlierRun(): void
    {
        $store = new ApiDocumentStore();
        $this->builder(['https://api/a.json' => self::api('A')], $store)->fromDirectory(['a' => 'https://api/a.json'], [], self::now());

        $later = self::now()->modify('+1 hour');
        $this->state = new RunState();
        [$build] = $this->builder(['https://api/a.json' => 503], $store)->fromDirectory(['a' => 'https://api/a.json'], [], $later);

        $this->assertSame('A', $build->info->name);
        $this->assertTrue($build->listed);
        $this->assertSame(['API file not available (HTTP 503); using the copy saved at 2026-10-30 12:00'], $build->messages);
        $this->assertEquals(self::now(), $this->state->apiStaleSince('a'));
    }

    public function testAFileThatIsNotThereIsBelievedAndAnUnreadableBodyUsesTheCopy(): void
    {
        $store = new ApiDocumentStore();
        $this->builder(['https://api/a.json' => self::api('A')], $store)->fromDirectory(['a' => 'https://api/a.json'], [], self::now());

        $this->state = new RunState();
        [$gone] = $this->builder(['https://api/a.json' => 404], $store)->fromDirectory(['a' => 'https://api/a.json'], [], self::now());
        $this->assertSame(CommunityReport::API_UNAVAILABLE, $gone->outcome);
        $this->assertNull($this->state->apiStaleSince('a'));

        [$garbled] = $this->builder(['https://api/a.json' => 'oops'], $store)->fromDirectory(['a' => 'https://api/a.json'], [], self::now());
        $this->assertTrue($garbled->listed);
        $this->assertStringContainsString('not a JSON object', $garbled->messages[0]);
    }

    public function testACopyThatIsTooOldIsNotUsed(): void
    {
        $store = new ApiDocumentStore(ttl: 3600);
        $this->builder(['https://api/a.json' => self::api()], $store)->fromDirectory(['a' => 'https://api/a.json'], [], self::now());

        [$build] = $this->builder(['https://api/a.json' => 503], $store)->fromDirectory(['a' => 'https://api/a.json'], [], self::now()->modify('+2 hours'));

        $this->assertSame(CommunityReport::API_UNAVAILABLE, $build->outcome);
    }
}
