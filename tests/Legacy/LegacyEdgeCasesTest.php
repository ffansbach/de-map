<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules the legacy parsers apply to single nodes, one world per case.
 *
 * An expected map is router id => [status, clients]; nodes that must not appear are left out.
 */
final class LegacyEdgeCasesTest extends TestCase
{
    private static function ago(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() - $seconds);
    }

    /**
     * @return array<string, mixed>
     */
    private static function nodeinfo(string $id, bool $online = true, mixed $clients = ['total' => 2], ?string $lastseen = null, mixed $lat = 50.1, mixed $lon = 8.1): array
    {
        $node = [
            'nodeinfo' => ['node_id' => $id, 'hostname' => 'h-' . $id, 'location' => ['latitude' => $lat, 'longitude' => $lon]],
            'flags' => ['online' => $online],
        ];

        if ($clients !== null) {
            $node['statistics'] = ['clients' => $clients];
        }

        if ($lastseen !== null) {
            $node['lastseen'] = $lastseen;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function listNode(string|int $id, ?array $status = null): array
    {
        $node = ['id' => $id, 'name' => 'n-' . $id, 'position' => ['lat' => 50.1, 'long' => 8.1]];

        return $status === null ? $node : $node + ['status' => $status];
    }

    /**
     * @param list<mixed>|array<string, mixed> $nodes
     */
    private static function nodesFile(array $nodes): string
    {
        return (string) json_encode(['version' => 2, 'nodes' => $nodes]);
    }

    /**
     * @param list<mixed> $nodes
     */
    private static function nodelistFile(array $nodes): string
    {
        return (string) json_encode(['version' => '1.0.0', 'updated_at' => self::ago(10), 'nodes' => $nodes]);
    }

    /**
     * @return array<string, array{string, string, array<array-key, array{string, int}>}>
     */
    public static function nodeRuleProvider(): array
    {
        return [
            // client counts
            'clients: object with total' => ['ffmap', self::nodesFile([self::nodeinfo('a', clients: ['total' => 7, 'wifi' => 9])]), ['a' => ['online', 7]]],
            'clients: plain number' => ['ffmap', self::nodesFile([self::nodeinfo('a', clients: 5)]), ['a' => ['online', 5]]],
            'clients: list is counted' => ['ffmap', self::nodesFile([self::nodeinfo('a', clients: [1, 2, 3])]), ['a' => ['online', 3]]],
            'clients: object without total is zero' => ['ffmap', self::nodesFile([self::nodeinfo('a', clients: ['wifi' => 3])]), ['a' => ['online', 0]]],
            'clients: no statistics' => ['ffmap', self::nodesFile([self::nodeinfo('a', clients: null)]), ['a' => ['online', 0]]],

            // offline handling (nodeinfo)
            'nodeinfo: offline for an hour is kept as offline' => ['ffmap', self::nodesFile([self::nodeinfo('a', false, null, self::ago(3600))]), ['a' => ['offline', 0]]],
            'nodeinfo: offline for ten days is dropped' => ['ffmap', self::nodesFile([self::nodeinfo('a', false, null, self::ago(10 * 86400))]), []],
            'nodeinfo: offline without lastseen is dropped' => ['ffmap', self::nodesFile([self::nodeinfo('a', false, null)]), []],

            // coordinates
            'location: latitude 0 counts as no location' => ['ffmap', self::nodesFile([self::nodeinfo('a', lat: 0)]), []],
            'location: latitude "0" counts as no location' => ['ffmap', self::nodesFile([self::nodeinfo('a', lat: '0')]), []],
            'location: non numeric latitude' => ['ffmap', self::nodesFile([self::nodeinfo('a', lat: 'north')]), []],
            'location: numeric strings are fine' => ['ffmap', self::nodesFile([self::nodeinfo('a', lat: '50.5', lon: '8.5')]), ['a' => ['online', 2]]],

            // flat meshviewer.json
            'flat: online' => ['ffmap', self::nodesFile([['node_id' => 'a', 'hostname' => 'h', 'location' => ['latitude' => 50.1, 'longitude' => 8.1], 'is_online' => true, 'clients' => 3]]), ['a' => ['online', 3]]],
            'flat: offline is dropped even if seen an hour ago' => ['ffmap', self::nodesFile([['node_id' => 'a', 'hostname' => 'h', 'location' => ['latitude' => 50.1, 'longitude' => 8.1], 'is_online' => false, 'lastseen' => self::ago(3600), 'clients' => 0]]), []],

            // old style geo[] (no source in the wild delivers this any more, see PRD section 8)
            'geo: online, id is the name, clientcount' => ['ffmap', self::nodesFile([['name' => 'x', 'geo' => [50.1, 8.1], 'flags' => ['online' => true], 'clientcount' => 4]]), ['x' => ['online', 4]]],
            'geo: offline is dropped' => ['ffmap', self::nodesFile([['name' => 'x', 'geo' => [50.1, 8.1], 'flags' => ['online' => false]]]), []],

            // structure
            'nodes keyed by id (ffmap v1)' => ['ffmap', self::nodesFile(['a' => self::nodeinfo('a'), 'b' => self::nodeinfo('b')]), ['a' => ['online', 2], 'b' => ['online', 2]]],
            'duplicate inside one source counts once' => ['ffmap', self::nodesFile([self::nodeinfo('a'), self::nodeinfo('a')]), ['a' => ['online', 2]]],

            // nodelist
            'nodelist: no status at all is unknown' => ['nodelist', self::nodelistFile([self::listNode('a')]), ['a' => ['unknown', 0]]],
            'nodelist: online with clients' => ['nodelist', self::nodelistFile([self::listNode('a', ['online' => true, 'clients' => 6])]), ['a' => ['online', 6]]],
            'nodelist: offline for a day is kept' => ['nodelist', self::nodelistFile([self::listNode('a', ['online' => false, 'lastcontact' => self::ago(86400)])]), ['a' => ['offline', 0]]],
            'nodelist: offline for ten days is dropped' => ['nodelist', self::nodelistFile([self::listNode('a', ['online' => false, 'lastcontact' => self::ago(10 * 86400)])]), []],
            'nodelist: offline without lastcontact is dropped' => ['nodelist', self::nodelistFile([self::listNode('a', ['online' => false])]), []],
            'nodelist: numeric id becomes a string' => ['nodelist', self::nodelistFile([self::listNode(42, ['online' => true])]), ['42' => ['online', 0]]],
            'nodelist: not valid against the schema is parsed anyway' => ['nodelist', (string) json_encode(['nodes' => [self::listNode('a', ['online' => true])]]), ['a' => ['online', 0]]],
            'nodelist: node without position is skipped' => ['nodelist', self::nodelistFile([['id' => 'a', 'name' => 'x', 'status' => ['online' => true]]]), []],
        ];
    }

    /**
     * @param array<array-key, array{string, int}> $expected
     */
    #[DataProvider('nodeRuleProvider')]
    public function testNodeRules(string $type, string $body, array $expected): void
    {
        $result = $this->runEdge($type, $body);

        $actual = [];

        foreach ($result->routers as $router) {
            $actual[(string) $router['id']] = [$router['status'], $router['clients']];
        }

        $this->assertSame($expected, $actual);
        $this->assertSame([], $result->warnings);
    }

    public function testNodelistUsesLonOrLongAndFallsBackToIdAsName(): void
    {
        $body = self::nodelistFile([
            ['id' => 'a', 'position' => ['lat' => 50.1, 'lon' => 8.2], 'status' => ['online' => true]],
            ['id' => 'b', 'name' => 'Bee', 'position' => ['lat' => 50.3, 'long' => 8.4], 'status' => ['online' => true]],
        ]);

        $routers = $this->runEdge('nodelist', $body)->routers;

        $this->assertSame(['id' => 'a', 'lat' => '50.1', 'long' => '8.2', 'name' => 'a', 'community' => 'edge', 'status' => 'online', 'clients' => 0], $routers[0]);
        $this->assertSame(['id' => 'b', 'lat' => '50.3', 'long' => '8.4', 'name' => 'Bee', 'community' => 'edge', 'status' => 'online', 'clients' => 0], $routers[1]);
    }

    public function testNodelistSchemaValidationNeverRejects(): void
    {
        // LEGACY BUG: Jsv4::validate() returns an object, which is always truthy, so
        // "if (!$validationResult)" in parser\NodeList can not fire. Garbage that merely
        // has a "nodes" array is accepted. Expected after the rewrite: validation that rejects.
        $result = $this->runEdge('nodelist', (string) json_encode(['nodes' => [self::listNode('a', ['online' => true])], 'unexpected' => true]));

        $this->assertSame(['a'], $result->idsOf('edge'));
        $this->assertFalse($result->hasMessage('edge', 'is no valid nodelist'));
    }

    public function testNodelistWithoutNodesYieldsNothing(): void
    {
        $result = $this->runEdge('nodelist', self::nodelistFile([]));

        $this->assertSame([], $result->routers);
        $this->assertTrue($result->hasMessage('edge', 'contains no nodes'));
    }

    public function testSourceThatIsNotJson(): void
    {
        $result = $this->runEdge('ffmap', '<html>404</html>');

        $this->assertSame([], $result->routers);
        $this->assertTrue($result->hasMessage('edge', 'returns no valid json'));
    }

    public function testJsonWithoutNodesKeyRaisesAPhpWarning(): void
    {
        // LEGACY BUG: ffmapUrlCheck() reads $responseObject->nodes without checking it exists.
        $result = $this->runEdge('ffmap', '{"foo": 1}');

        $this->assertSame([], $result->routers);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('Undefined property: stdClass::$nodes', $result->warnings[0]);
    }

    public function testBlacklistedUrlIsNotFetched(): void
    {
        $url = 'http://map.freifunk-ruhrgebiet.de/nodes.json';
        $world = (new World())->community('edge', ['nodeMaps' => [['technicalType' => 'ffmap', 'url' => $url]]])->route($url, self::nodesFile([self::nodeinfo('a')]));
        $runner = new LegacyRunner();

        try {
            $result = $runner->run($world->routes());
        } finally {
            $runner->cleanUp();
        }

        $this->assertSame([], $result->routers);
        $this->assertNotContains($url, $result->calls);
        $this->assertTrue($result->hasMessage('edge', 'is blacklisted'));
    }

    private function runEdge(string $type, string $body): LegacyRunResult
    {
        $url = $type === 'nodelist' ? 'https://edge.test/nodelist.json' : 'https://edge.test/data/nodes.json';
        $world = (new World())
            ->community('edge', ['nodeMaps' => [['technicalType' => $type, 'url' => $url]]])
            ->route($url, $body);
        $runner = new LegacyRunner();

        try {
            return $runner->run($world->routes());
        } finally {
            $runner->cleanUp();
        }
    }
}
