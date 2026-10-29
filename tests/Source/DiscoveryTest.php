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

    public function testDataPathUrls(): void
    {
        $this->assertSame([
            'https://hop.test/a/nodes.json',
            'https://map.test/data/nodes.json',
        ], Discovery::dataPathUrls('https://map.test/map/', ['https://hop.test/a/', '/data/']));
    }

    public function testDataPathUrlsAreUnique(): void
    {
        $this->assertSame(['https://map.test/data/nodes.json'], Discovery::dataPathUrls('https://map.test/', ['/data/', 'data/']));
    }
}
