<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Audit;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Audit\FormatDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string, Format, int, int}>
     */
    public static function formatProvider(): array
    {
        return [
            'empty body' => ['', Format::Empty, 0, 0],
            'whitespace only' => ["  \n", Format::Empty, 0, 0],
            'html error page' => ['<!doctype html><html></html>', Format::Html, 0, 0],
            'broken json' => ['{"nodes": [', Format::InvalidJson, 0, 0],
            'json scalar' => ['42', Format::JsonOther, 0, 0],
            'json without nodes' => ['{"foo": "bar"}', Format::JsonOther, 0, 0],
            'nodes is not a container' => ['{"nodes": "none"}', Format::JsonOther, 0, 0],
            'empty node list' => ['{"nodes": []}', Format::NodesEmpty, 0, 0],
            'scalar nodes' => ['{"nodes": [1, 2]}', Format::NodesUnknown, 2, 0],
            'unrecognised nodes' => ['{"nodes": [{"foo": 1}]}', Format::NodesUnknown, 1, 0],
            'nodeinfo list (meshviewer v2 / hopglass)' => [
                '{"version": 2, "nodes": [
                    {"nodeinfo": {"node_id": "a", "location": {"latitude": 50.1, "longitude": 8.6}}, "flags": {"online": true}},
                    {"nodeinfo": {"node_id": "b"}, "flags": {"online": false}}
                ]}',
                Format::NodeInfo, 2, 1,
            ],
            'nodeinfo keyed by id (ffmap v1)' => [
                '{"version": 1, "nodes": {
                    "aa": {"nodeinfo": {"node_id": "aa", "location": {"latitude": "50.1", "longitude": "8.6"}}},
                    "bb": {"nodeinfo": {"node_id": "bb", "location": {"latitude": 0, "longitude": 0}}}
                }}',
                Format::NodeInfo, 2, 1,
            ],
            'meshviewer flat' => [
                '{"nodes": [
                    {"node_id": "a", "hostname": "x", "location": {"latitude": 50.1, "longitude": 8.6}, "is_online": true},
                    {"node_id": "b", "hostname": "y", "is_online": true}
                ]}',
                Format::MeshviewerFlat, 2, 1,
            ],
            'legacy geo' => [
                '{"nodes": [{"name": "x", "geo": [50.1, 8.6]}, {"name": "y", "geo": null}]}',
                Format::LegacyGeo, 2, 1,
            ],
            'nodelist with lon' => [
                '{"version": "1.0.0", "nodes": [{"id": "a", "position": {"lat": 50.1, "lon": 8.6}}]}',
                Format::Nodelist, 1, 1,
            ],
            'nodelist, first node has no position' => [
                '{"version": "1.0.0", "nodes": [
                    {"id": "a", "name": "x", "status": {"online": true, "clients": 0}},
                    {"id": "b", "position": {"lat": 47.6, "long": 7.6}, "status": {"online": true}}
                ]}',
                Format::Nodelist, 2, 1,
            ],
            'format decided by a later node' => [
                '{"nodes": [1, {"foo": 1}, {"geo": [50.1, 8.6]}]}',
                Format::LegacyGeo, 3, 1,
            ],
            'nodelist with long' => [
                '{"version": "1.0.0", "nodes": [{"id": "a", "position": {"lat": 50.1, "long": 8.6}}, {"id": "b", "position": {}}]}',
                Format::Nodelist, 2, 1,
            ],
        ];
    }

    #[DataProvider('formatProvider')]
    public function testDetect(string $body, Format $expected, int $nodes, int $located): void
    {
        $result = (new FormatDetector())->detect($body);

        $this->assertSame($expected, $result->format);
        $this->assertSame($nodes, $result->nodes);
        $this->assertSame($located, $result->located);
    }

    public function testConfigJsonWithSingleDataPath(): void
    {
        $result = (new FormatDetector())->detect('{"dataPath": "/data/", "siteName": "x"}');

        $this->assertSame(Format::FfmapConfig, $result->format);
        $this->assertSame(['/data/'], $result->dataPaths);
        $this->assertFalse($result->hasNodes());
    }

    public function testConfigJsonWithHopglassDataPathList(): void
    {
        $result = (new FormatDetector())->detect('{"dataPath": ["https://a.example/", "https://b.example/", 5]}');

        $this->assertSame(Format::FfmapConfig, $result->format);
        $this->assertSame(['https://a.example/', 'https://b.example/'], $result->dataPaths);
    }

    public function testOnlyNodeSourcesWithNodesCount(): void
    {
        $detector = new FormatDetector();

        $this->assertTrue($detector->detect('{"nodes": [{"geo": [1, 2]}]}')->hasNodes());
        $this->assertFalse($detector->detect('{"nodes": []}')->hasNodes());
        $this->assertFalse($detector->detect('{"nodes": [{"foo": 1}]}')->hasNodes());
    }
}
