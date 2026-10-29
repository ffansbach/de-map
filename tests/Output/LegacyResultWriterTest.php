<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Output;

use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Output\LegacyResultWriter;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\ParseRun;
use FreifunkKarte\Pipeline\RunResult;
use FreifunkKarte\Source\SourceCache;
use FreifunkKarte\Tests\Legacy\LegacyRunner;
use FreifunkKarte\Tests\Legacy\StandardWorld;
use FreifunkKarte\Tests\Legacy\World;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class LegacyResultWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffmap_writer_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function runResult(World $world): RunResult
    {
        $run = new ParseRun(
            new ParallelFetcher(new Client(['handler' => $world->httpHandler()]), 8, retryWithoutVerification: false),
            new SourceParser(),
            new SourceCache(),
            new MetaCommunityResolver(),
            null,
            LegacyRunner::DIRECTORY_URL,
            retryDelayMs: 0,
        );

        return $run->run($world->fixedCommunities(), StandardWorld::SKIP);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function read(string $name): array
    {
        $decoded = json_decode((string) file_get_contents($this->directory . '/' . $name), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testWritesTheFourFilesAndNoTemporaryOnes(): void
    {
        $paths = (new LegacyResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);

        $expected = LegacyResultWriter::FILES;
        sort($expected);

        $this->assertSame(LegacyResultWriter::FILES, array_keys($paths));
        $this->assertSame($expected, array_map('basename', glob($this->directory . '/*') ?: []));
    }

    public function testRoutersAndCommunitiesHaveTheLegacyShapeAndEncoding(): void
    {
        (new LegacyResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);

        $routers = $this->read('result_routers.json');
        $this->assertCount(40, $routers);
        $this->assertSame(['id', 'lat', 'long', 'name', 'community', 'status', 'clients'], array_keys($routers[0]));

        /** @var array<string, array{name: string, url: string, meta: string|false}> $legacy */
        $legacy = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/golden/legacy/communities.json'), true);
        $this->assertSame($legacy, $this->read('result_communities.json'));

        // json_encode defaults, like the legacy parser: slashes are escaped
        $raw = (string) file_get_contents($this->directory . '/result_communities.json');
        $this->assertStringContainsString('https:\/\/flat.example\/home', $raw);
        $this->assertStringStartsWith('{"nl-city":{"name":"Freifunk nl-city"', $raw);
        $this->assertSame((string) json_encode($legacy), $raw, 'byte for byte what the legacy parser would write');
    }

    public function testStatisticsKeepWhatDebugPhpReadsAndAddTheNewFacts(): void
    {
        (new LegacyResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);
        $statistics = $this->read('result_statistics.json');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $statistics['timestamp']);
        $this->assertSame(['requests', 'seconds', 'nodes', 'communities'], array_keys($statistics['run']));
        $this->assertSame(40, $statistics['run']['nodes']);

        $mv = $statistics['errorCommunities']['mv-city'];
        $this->assertSame('mv-city', $mv['name']);
        $this->assertSame('https://api.test/mv-city.json', $mv['apifile']);
        $this->assertSame('Meta Nord', $mv['metacommunity']);
        $this->assertFalse($mv['claimed_nodecount']);
        $this->assertContains('meta-community: Meta Nord', $mv['message']);
        $this->assertSame(['ok', 'nodeinfo'], [$mv['outcome'], $mv['format']]);

        // every community seen is in there, also the ones that are not listed
        $this->assertSame('skipped', $statistics['errorCommunities']['skipped-town']['outcome']);
        $this->assertArrayNotHasKey('claimed_nodecount', $statistics['errorCommunities']['broken-api']);
    }

    public function testMapsWithoutDataListWhatWasTried(): void
    {
        (new LegacyResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);
        $dead = $this->read('result_statistics.json')['errorCommunities']['dead-town'];

        $this->assertSame('no-data', $dead['outcome']);
        $this->assertSame('https://dead.test/map/', $dead['attempts'][0]['url']);
        $this->assertSame(404, $dead['attempts'][0]['status']);
        $this->assertArrayNotHasKey('attempts', $this->read('result_statistics.json')['errorCommunities']['nl-city']);
    }

    public function testMetaCommunitiesAreAnObjectEvenWithoutLinks(): void
    {
        (new LegacyResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);

        $this->assertSame('{}', (string) file_get_contents($this->directory . '/result_metacommunities.json'));
    }

    public function testMetaCommunitiesWithALinkAreWritten(): void
    {
        $maps = static fn (string $key): array => ['metacommunity' => 'Funk Nord', 'url' => "https://funknord.test/$key/", 'nodeMaps' => []];
        $world = (new World())->community('a', $maps('a'))->community('b', $maps('b'));

        (new LegacyResultWriter())->write($this->runResult($world), $this->directory);

        $this->assertSame(['Funk Nord' => ['url' => 'https://funknord.test/']], $this->read('result_metacommunities.json'));
    }

    public function testExistingFilesAreReplaced(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/result_routers.json', 'old');

        (new LegacyResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);

        $this->assertNotSame('old', file_get_contents($this->directory . '/result_routers.json'));
    }
}
