<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Output;

use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Output\ResultWriter;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\ParseRun;
use FreifunkKarte\Pipeline\RunResult;
use FreifunkKarte\Source\SourceCache;
use FreifunkKarte\Tests\Support\StandardWorld;
use FreifunkKarte\Tests\Support\World;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class ResultWriterTest extends TestCase
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
            World::DIRECTORY_URL,
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
        $paths = (new ResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);

        $expected = ResultWriter::FILES;
        sort($expected);

        $this->assertSame(ResultWriter::FILES, array_keys($paths));
        $this->assertSame($expected, array_map('basename', glob($this->directory . '/*') ?: []));
    }

    public function testRoutersAndCommunitiesHaveTheShapeAndEncodingTheMapReads(): void
    {
        (new ResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);

        $routers = $this->read('result_routers.json');
        $this->assertCount(40, $routers);
        $this->assertSame(['id', 'lat', 'long', 'name', 'community', 'status', 'clients'], array_keys($routers[0]));

        /** @var array<string, array{name: string, url: string, meta: string|false}> $reference */
        $reference = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/golden/reference/communities.json'), true);
        $this->assertSame($reference, $this->read('result_communities.json'));

        // json_encode defaults: slashes are escaped
        $raw = (string) file_get_contents($this->directory . '/result_communities.json');
        $this->assertStringContainsString('https:\/\/flat.example\/home', $raw);
        $this->assertStringStartsWith('{"nl-city":{"name":"Freifunk nl-city"', $raw);
        $this->assertSame((string) json_encode($reference), $raw, 'byte for byte the recorded reference');
    }

    public function testMetaCommunitiesAreAnObjectEvenWithoutLinks(): void
    {
        (new ResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);

        $this->assertSame('{}', (string) file_get_contents($this->directory . '/result_metacommunities.json'));
    }

    public function testMetaCommunitiesWithALinkAreWritten(): void
    {
        $maps = static fn (string $key): array => ['metacommunity' => 'Funk Nord', 'url' => "https://funknord.test/$key/", 'nodeMaps' => []];
        $world = (new World())->community('a', $maps('a'))->community('b', $maps('b'));

        (new ResultWriter())->write($this->runResult($world), $this->directory);

        $this->assertSame(['Funk Nord' => ['url' => 'https://funknord.test/']], $this->read('result_metacommunities.json'));
    }

    public function testExistingFilesAreReplaced(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/result_routers.json', 'old');

        (new ResultWriter())->write($this->runResult(StandardWorld::build()), $this->directory);

        $this->assertNotSame('old', file_get_contents($this->directory . '/result_routers.json'));
    }
}
