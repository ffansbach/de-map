<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Parser;

use DateTimeImmutable;
use FreifunkKarte\Audit\Format;
use FreifunkKarte\Model\NodeStatus;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Tests\Support\World;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceParserTest extends TestCase
{
    private function parse(string $body): \FreifunkKarte\Parser\ParseResult
    {
        return (new SourceParser())->parse($body, new DateTimeImmutable());
    }

    public function testNodeinfoFixture(): void
    {
        $result = $this->parse(World::fixture('nodeinfo'));

        $this->assertSame(Format::NodeInfo, $result->format);
        $this->assertSame(7, $result->found);
        $this->assertCount(4, $result->nodes);
        $this->assertSame(2, $result->withoutLocation);
        $this->assertSame(1, $result->dropped);
        $this->assertSame(0, $result->invalid);
        $this->assertSame([], $result->warnings);
        $this->assertSame('7 nodes found, 4 accepted, 2 without location, 1 dropped (offline), 0 invalid', $result->summary());

        $first = $result->nodes[0];
        $this->assertSame('node-1', $first->name);
        $this->assertSame(NodeStatus::Online, $first->status);
        $this->assertSame(5, $first->clients);
    }

    public function testMeshviewerFlatFixtureKeepsRecentlyOfflineNodes(): void
    {
        // the fixture has two offline nodes with a position that were seen a day ago and
        // minutes ago: they stay
        $result = $this->parse(World::fixture('meshviewer_flat'));

        $this->assertSame(Format::MeshviewerFlat, $result->format);
        $this->assertSame(8, $result->found);
        $this->assertCount(6, $result->nodes);
        $this->assertSame(2, $result->withoutLocation);
        $this->assertSame(0, $result->dropped);

        $statuses = array_count_values(array_map(static fn ($n): string => $n->status->value, $result->nodes));
        $this->assertSame(['online' => 4, 'offline' => 2], $statuses);
    }

    public function testNodelistFixture(): void
    {
        $result = $this->parse(World::fixture('nodelist'));

        $this->assertSame(Format::Nodelist, $result->format);
        $this->assertSame(8, $result->found);
        $this->assertCount(4, $result->nodes);
        $this->assertSame(2, $result->withoutLocation);
        $this->assertSame(2, $result->dropped);
        $this->assertSame([], $result->warnings, 'the fixture is a valid nodelist 1.0.1');
    }

    /**
     * @return array<string, array{string, Format, string}>
     */
    public static function unusableProvider(): array
    {
        return [
            'empty' => ['', Format::Empty, 'empty'],
            'web page' => ['<html>404</html>', Format::Html, 'web page'],
            'broken json' => ['{"nodes": [', Format::InvalidJson, 'not valid JSON'],
            'json without nodes' => ['{"foo": 1}', Format::JsonOther, 'no node list'],
            'empty node list' => ['{"nodes": []}', Format::NodesEmpty, 'empty'],
            'map config' => ['{"dataPath": "/data/"}', Format::FfmapConfig, 'config.json'],
            'unknown nodes' => ['{"nodes": [{"foo": 1}]}', Format::NodesUnknown, 'unknown format'],
            'old geo format' => ['{"nodes": [{"name": "x", "geo": [50.1, 8.1]}]}', Format::NodesUnknown, 'unknown format'],
        ];
    }

    #[DataProvider('unusableProvider')]
    public function testSourcesThatAreNoNodeList(string $body, Format $format, string $reason): void
    {
        $result = $this->parse($body);

        $this->assertSame($format, $result->format);
        $this->assertFalse($result->isUsable());
        $this->assertSame([], $result->nodes);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString($reason, $result->warnings[0]);
    }

    public function testMapConfigCarriesItsDataPaths(): void
    {
        $result = $this->parse('{"dataPath": ["https://a.test/", "https://b.test/"]}');

        $this->assertSame(Format::FfmapConfig, $result->format);
        $this->assertFalse($result->isUsable());
        $this->assertSame(['https://a.test/', 'https://b.test/'], $result->dataPaths);
    }

    public function testAMapPageCarriesTheDataPathsItNames(): void
    {
        $result = $this->parse('<html><script>window.config = {dataPath: ["/data/a/", "/data/b/"]}</script></html>');

        $this->assertSame(Format::Html, $result->format);
        $this->assertFalse($result->isUsable());
        $this->assertSame(['/data/a/', '/data/b/'], $result->dataPaths);
        $this->assertCount(1, $result->warnings);
        $this->assertSame([], $this->parse('<html>nothing</html>')->dataPaths);
    }

    public function testNodelistNotMatchingTheSchemaIsParsedAndWarnsOnce(): void
    {
        $body = (string) json_encode(['version' => '1.0.1', 'updated_at' => 'x', 'nodes' => [
            ['id' => 1, 'name' => 'a', 'position' => ['lat' => 50.1, 'long' => 8.1], 'status' => ['online' => true]],
            ['id' => 2, 'name' => 'b', 'position' => ['lat' => 50.2, 'long' => 8.2], 'status' => ['online' => true]],
        ]]);

        $result = $this->parse($body);

        $this->assertCount(2, $result->nodes, 'schema deviations never stop the parsing');
        $this->assertSame(['1', '2'], array_map(static fn ($n): string => $n->id, $result->nodes));
        $this->assertCount(1, $result->warnings);
        $this->assertSame('schema 1.0.1: nodes[].id: Integer value found, but a string is required (2x)', $result->warnings[0]);
    }

    public function testNodelistWithoutOrWithUnknownVersionIsCheckedAgainstTheLatestSchema(): void
    {
        $node = ['id' => 'a', 'name' => 'a', 'position' => ['lat' => 50.1, 'long' => 8.1], 'status' => ['online' => true]];

        $unknown = $this->parse((string) json_encode(['version' => '9.9', 'nodes' => [$node]]));
        $this->assertCount(1, $unknown->nodes);
        $this->assertStringContainsString('unknown nodelist version "9.9"', $unknown->warnings[0]);

        $missing = $this->parse((string) json_encode(['nodes' => [$node]]));
        $this->assertCount(1, $missing->nodes);
        $this->assertStringContainsString('unknown nodelist version null', $missing->warnings[0]);
        $this->assertContains('schema 1.0.1: version: The property version is required', $missing->warnings);
    }

    public function testNodelistVersion100IsCheckedAgainstItsOwnSchema(): void
    {
        $body = (string) json_encode(['version' => '1.0.0', 'nodes' => [
            ['id' => 'a', 'name' => 'a', 'position' => ['lat' => 50.1, 'long' => 8.1], 'status' => ['online' => true]],
        ]]);

        $this->assertSame([], $this->parse($body)->warnings);
    }

    public function testEntriesWithoutIdAreCountedAsInvalid(): void
    {
        $body = (string) json_encode(['version' => 2, 'nodes' => [
            ['nodeinfo' => ['hostname' => 'no-id', 'location' => ['latitude' => 50.1, 'longitude' => 8.1]], 'flags' => ['online' => true]],
            'garbage',
            ['nodeinfo' => ['node_id' => 'ok', 'hostname' => 'fine', 'location' => ['latitude' => 50.1, 'longitude' => 8.1]], 'flags' => ['online' => true]],
        ]]);

        $result = $this->parse($body);

        $this->assertSame(3, $result->found);
        $this->assertSame(2, $result->invalid);
        $this->assertCount(1, $result->nodes);
        $this->assertSame(['2 entries are not usable nodes (no id)'], $result->warnings);
    }

    public function testOutOfRangeCoordinatesAreReported(): void
    {
        $body = (string) json_encode(['version' => 2, 'nodes' => [
            ['nodeinfo' => ['node_id' => 'a', 'hostname' => 'x', 'location' => ['latitude' => 150, 'longitude' => 8.1]], 'flags' => ['online' => true]],
        ]]);

        $result = $this->parse($body);

        $this->assertSame(1, $result->withoutLocation);
        $this->assertSame(['1 nodes have coordinates outside the valid range'], $result->warnings);
    }

    public function testNameFallsBackToId(): void
    {
        $body = (string) json_encode(['version' => '1.0.1', 'nodes' => [
            ['id' => 'abc', 'position' => ['lat' => 50.1, 'lon' => 8.1], 'status' => ['online' => true]],
        ]]);

        $this->assertSame('abc', $this->parse($body)->nodes[0]->name);
    }

    public function testUnknownStateIsKeptAsUnknown(): void
    {
        $body = (string) json_encode(['version' => '1.0.1', 'nodes' => [
            ['id' => 'abc', 'name' => 'x', 'position' => ['lat' => 50.1, 'long' => 8.1]],
        ]]);

        $this->assertSame(NodeStatus::Unknown, $this->parse($body)->nodes[0]->status);
    }
}
