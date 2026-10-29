<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Audit;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Audit\FormatDetector;
use FreifunkKarte\Audit\SampleTrimmer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SampleTrimmerTest extends TestCase
{
    private const SECRETS = ['max@example.org', '12:34:56:78:9a:bc', '2001:db8::1', 'Max Mustermann', 'TP-Link', 'secret-hostname'];

    /**
     * @param array<string, float>|null $location
     * @return array<string, mixed>
     */
    private function nodeinfoNode(string $id, bool $online, ?array $location, int $clients = 3): array
    {
        return [
            'nodeinfo' => array_filter([
                'node_id' => $id,
                'hostname' => 'secret-hostname-' . $id,
                'location' => $location,
                'owner' => ['contact' => 'max@example.org'],
                'network' => ['mac' => '12:34:56:78:9a:bc', 'addresses' => ['2001:db8::1']],
                'hardware' => ['model' => 'TP-Link'],
            ]),
            'flags' => ['online' => $online, 'gateway' => false],
            'statistics' => ['clients' => ['total' => $clients, 'wifi' => $clients], 'gateway' => 'x'],
            'firstseen' => '2020-01-01T00:00:00+0000',
            'lastseen' => '2026-10-29T10:00:00+0000',
        ];
    }

    private function nodeinfoSource(): string
    {
        $nodes = [];

        for ($i = 0; $i < 10; $i++) {
            $nodes[] = $this->nodeinfoNode("aa$i", true, ['latitude' => 50.123456 + $i, 'longitude' => 8.654321]);
        }

        for ($i = 0; $i < 5; $i++) {
            $nodes[] = $this->nodeinfoNode("bb$i", false, ['latitude' => 51.55555, 'longitude' => 9.99999], 0);
        }

        for ($i = 0; $i < 3; $i++) {
            $nodes[] = $this->nodeinfoNode("cc$i", true, null);
            $nodes[] = $this->nodeinfoNode("dd$i", false, null, 0);
        }

        return (string) json_encode(['version' => 2, 'timestamp' => '2026-10-29T10:00:00+0000', 'nodes' => $nodes, 'secret' => 'Max Mustermann']);
    }

    public function testNodeinfoIsReducedAndAnonymised(): void
    {
        $output = (new SampleTrimmer())->trim($this->nodeinfoSource(), Format::NodeInfo);

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }

        $data = json_decode($output, true);
        $this->assertSame(['version', 'timestamp', 'nodes'], array_keys($data));
        // 4 online + 2 offline located, 1 online + 1 offline without location
        $this->assertCount(8, $data['nodes']);

        $first = $data['nodes'][0];
        $this->assertSame('node-1', $first['nodeinfo']['hostname']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $first['nodeinfo']['node_id']);
        $this->assertSame(50.12, $first['nodeinfo']['location']['latitude']);
        $this->assertSame(8.65, $first['nodeinfo']['location']['longitude']);
        $this->assertSame(['online' => true], $first['flags']);
        $this->assertSame(['clients' => ['total' => 3, 'wifi' => 3]], $first['statistics']);
        $this->assertSame('2026-10-29T10:00:00+0000', $first['lastseen']);
    }

    public function testOfflineAndZeroValuesSurvive(): void
    {
        $data = json_decode((new SampleTrimmer())->trim($this->nodeinfoSource(), Format::NodeInfo), true);
        $offline = array_values(array_filter($data['nodes'], static fn (array $n): bool => $n['flags']['online'] === false));

        $this->assertCount(3, $offline);
        $this->assertSame(0, $offline[0]['statistics']['clients']['total']);
    }

    public function testTrimmedOutputIsStillRecognisedWithTheSameShape(): void
    {
        $detector = new FormatDetector();
        $result = $detector->detect((new SampleTrimmer())->trim($this->nodeinfoSource(), Format::NodeInfo));

        $this->assertSame(Format::NodeInfo, $result->format);
        $this->assertSame(8, $result->nodes);
        $this->assertSame(6, $result->located);
    }

    public function testTrimIsDeterministic(): void
    {
        $trimmer = new SampleTrimmer();

        $this->assertSame(
            $trimmer->trim($this->nodeinfoSource(), Format::NodeInfo),
            $trimmer->trim($this->nodeinfoSource(), Format::NodeInfo),
        );
    }

    public function testMeshviewerFlatKeepsOfflineFlagAndZeroClients(): void
    {
        $source = (string) json_encode(['timestamp' => 't', 'links' => [['x']], 'nodes' => [
            ['node_id' => 'n1', 'hostname' => 'secret-hostname', 'owner' => 'max@example.org', 'mac' => '12:34:56:78:9a:bc',
                'location' => ['latitude' => '50.5555', 'longitude' => '8.1111'], 'is_online' => false, 'clients' => 0, 'lastseen' => 'l'],
        ]]);

        $output = (new SampleTrimmer())->trim($source, Format::MeshviewerFlat);
        $node = json_decode($output, true)['nodes'][0];

        $this->assertStringNotContainsString('max@example.org', $output);
        $this->assertStringNotContainsString('secret-hostname', $output);
        $this->assertArrayNotHasKey('links', json_decode($output, true));
        $this->assertFalse($node['is_online']);
        $this->assertSame(0, $node['clients']);
        // string coordinates stay strings: the parser casts them and communities do deliver both
        $this->assertSame('50.56', $node['location']['latitude']);
        $this->assertSame('node-1', $node['hostname']);
    }

    public function testNodelistKeepsNumericIdsAndLongKey(): void
    {
        $source = (string) json_encode(['version' => '1.0.0', 'updated_at' => 'u', 'nodes' => [
            ['id' => 7, 'name' => 'secret-hostname', 'position' => ['lat' => 47.60874, 'long' => 7.67486],
                'status' => ['online' => true, 'clients' => 0, 'lastcontact' => 'a', 'contact' => 'max@example.org']],
            ['id' => 'abc', 'name' => 'y', 'position' => ['lat' => 47.1, 'lon' => 7.1], 'status' => ['online' => true]],
        ]]);

        $output = (new SampleTrimmer())->trim($source, Format::Nodelist);
        $data = json_decode($output, true);

        $this->assertStringNotContainsString('max@example.org', $output);
        $this->assertSame(7, $data['nodes'][0]['id']);
        $this->assertSame(['lat' => 47.61, 'long' => 7.67], $data['nodes'][0]['position']);
        $this->assertSame(['online' => true, 'clients' => 0, 'lastcontact' => 'a'], $data['nodes'][0]['status']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $data['nodes'][1]['id']);
        $this->assertSame(['lat' => 47.1, 'lon' => 7.1], $data['nodes'][1]['position']);
    }

    public function testKeyedNodesStayKeyedWithAnonymisedKeys(): void
    {
        $source = (string) json_encode(['version' => 1, 'nodes' => [
            'secretkey1' => $this->nodeinfoNode('secretkey1', true, ['latitude' => 50.1, 'longitude' => 8.1]),
        ]]);

        $output = (new SampleTrimmer())->trim($source, Format::NodeInfo);
        $nodes = json_decode($output, true)['nodes'];

        $this->assertStringNotContainsString('secretkey1', $output);
        $this->assertFalse(array_is_list($nodes));
    }

    public function testUnsupportedFormatAndBrokenInputAreRejected(): void
    {
        $trimmer = new SampleTrimmer();

        try {
            $trimmer->trim('{"nodes": []}', Format::Html);
            $this->fail('expected exception');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $trimmer->trim('not json', Format::NodeInfo);
    }
}
