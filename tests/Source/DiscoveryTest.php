<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Source;

use FreifunkKarte\Source\Discovery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function baseUrlProvider(): array
    {
        return [
            'with trailing slash' => ['https://map.test/map/', 'https://map.test/map/'],
            'without trailing slash' => ['https://map.test/map', 'https://map.test/map/'],
            'host only' => ['https://map.test', 'https://map.test/'],
            'file name is dropped' => ['https://map.test/map/index.html', 'https://map.test/map/'],
            'json file is dropped, too' => ['https://map.test/data/nodes.json', 'https://map.test/data/'],
            'no scheme' => ['map.test/foo', 'http://map.test/foo/'],
            'double slashes' => ['https://map.test//a//b/', 'https://map.test/a/b/'],
            'port' => ['http://map.test:8080/x', 'http://map.test:8080/x/'],
            'query is not part of the base' => ['https://map.test/map/?x=1', 'https://map.test/map/'],
        ];
    }

    #[DataProvider('baseUrlProvider')]
    public function testBaseUrl(string $url, string $expected): void
    {
        $this->assertSame($expected, Discovery::baseUrl($url));
    }

    public function testWithScheme(): void
    {
        $this->assertSame('http://a.test/x', Discovery::withScheme(' a.test/x '));
        $this->assertSame('https://a.test/x', Discovery::withScheme('https://a.test/x'));
        $this->assertSame('http://a.test', Discovery::withScheme('//a.test'));
    }

    public function testCandidatesForAMapPageInPreferredOrderWithoutDoubleSlash(): void
    {
        $this->assertSame([
            'https://map.test/map/config.json',
            'https://map.test/map/nodes.json',
            'https://map.test/map/data/nodes.json',
            'https://map.test/map/json/nodes.json',
            'https://map.test/map/meshviewer/data/meshviewer.json',
            'https://map.test/map/data/meshviewer.json',
        ], Discovery::candidates('https://map.test/map', 'meshviewer'));
    }

    public function testMeshviewerSubdirectoryAlsoTriesTheParent(): void
    {
        $candidates = Discovery::candidates('https://map.test/meshviewer/', 'ffmap');

        $this->assertContains('https://map.test/nodes.json', $candidates);
        $this->assertContains('https://map.test/data/nodes.json', $candidates);
        $this->assertContains('https://map.test/json/nodes.json', $candidates);
    }

    public function testNoCandidatesForJsonUrlsOrOtherTypes(): void
    {
        $this->assertSame([], Discovery::candidates('https://map.test/data/nodes.json', 'ffmap'));
        $this->assertSame([], Discovery::candidates('https://map.test/map/', 'nodelist'));
        $this->assertSame([], Discovery::candidates('https://map.test/map/', 'openwifimap'));
        $this->assertSame([], Discovery::candidates('https://map.test/map/', '(none)'));
    }

    public function testDataPathUrlsTryMeshviewerJsonAndNodesJson(): void
    {
        $this->assertSame([
            'https://hop.test/a/meshviewer.json',
            'https://hop.test/a/nodes.json',
            'https://map.test/data/meshviewer.json',
            'https://map.test/data/nodes.json',
        ], Discovery::dataPathUrls('https://map.test/map/', ['https://hop.test/a/', '/data/']));
    }

    public function testDataPathsAreResolvedLikeABrowserWould(): void
    {
        $this->assertSame(
            ['https://map.test/map/data/x/meshviewer.json', 'https://map.test/map/data/x/nodes.json'],
            Discovery::dataPathUrls('https://map.test/map/index.html', ['data/x']),
            'relative to the directory of the page, a missing slash is added',
        );
        $this->assertSame(
            ['http://map.test:8080/data/meshviewer.json', 'http://map.test:8080/data/nodes.json'],
            Discovery::dataPathUrls('http://map.test:8080/map/', ['/data/']),
            'the port is kept',
        );
    }

    public function testADataPathThatNamesAFileIsTakenAsItIs(): void
    {
        $this->assertSame(['https://map.test/data/all.json'], Discovery::dataPathUrls('https://map.test/', ['/data/all.json']));
    }

    public function testDataPathUrlsAreUnique(): void
    {
        $this->assertSame(
            ['https://map.test/data/meshviewer.json', 'https://map.test/data/nodes.json'],
            Discovery::dataPathUrls('https://map.test/', ['/data/', 'data/', ' /data ', '']),
        );
    }

    public function testNoDataPathUrlsWithoutAHost(): void
    {
        $this->assertSame([], Discovery::dataPathUrls('', ['/data/']));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function inlineProvider(): array
    {
        return [
            'array in a script (Rhein-Sieg)' => ['<script>window.config={dataPath:["/data/tdf/all/","/data/siegburg1/domain01/"],x:1}</script>', ['/data/tdf/all/', '/data/siegburg1/domain01/']],
            'json style with quoted key' => ['<script>{"dataPath": ["https://a.test/d/"]}</script>', ['https://a.test/d/']],
            'a single string' => ['<script>var c = {dataPath: "/data/"};</script>', ['/data/']],
            'single quotes and line breaks' => ["<script>dataPath: [
  '/data/a/',
  '/data/b/'
]</script>", ['/data/a/', '/data/b/']],
            'no dataPath' => ['<html><body>a viewer</body></html>', []],
            'an empty list' => ['<script>dataPath: []</script>', []],
            'duplicates once' => ['dataPath: ["/data/", "/data/"]', ['/data/']],
            'nothing a script could make of' => ['dataPath: ["javascript:alert(1)", "/ok/", "../../etc/", "a b", "<x>", "data:text/html,x"]', ['/ok/']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('inlineProvider')]
    public function testInlineDataPaths(string $html, array $expected): void
    {
        $this->assertSame($expected, Discovery::inlineDataPaths($html));
    }

    public function testAPageThatListsHundredsOfPathsIsCut(): void
    {
        $html = 'dataPath: [' . implode(',', array_map(static fn (int $i): string => '"/d' . $i . '/"', range(1, 300))) . ']';

        $this->assertCount(100, Discovery::inlineDataPaths($html));
    }
}
