<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Audit;

use FreifunkKarte\Audit\FormatDetector;
use FreifunkKarte\Audit\ParallelFetcher;
use FreifunkKarte\Audit\SourceAuditor;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SourceAuditorTest extends TestCase
{
    private const NODEINFO = '{"version": 2, "nodes": [{"nodeinfo": {"node_id": "a", "location": {"latitude": 50.1, "longitude": 8.6}}, "flags": {"online": true}}]}';

    /**
     * @param array<string, string> $routes url => 200 body; everything else is a 404
     */
    private function auditor(array $routes): SourceAuditor
    {
        $handler = static fn (RequestInterface $request) => Create::promiseFor(
            isset($routes[(string) $request->getUri()])
                ? new Response(200, [], $routes[(string) $request->getUri()])
                : new Response(404),
        );

        return new SourceAuditor(new ParallelFetcher(new Client(['handler' => $handler])), new FormatDetector());
    }

    /**
     * @return array<string, string>
     */
    private function routes(): array
    {
        return [
            SourceAuditor::DIRECTORY_URL => (string) json_encode([
                'aachen' => 'https://api.test/aachen.json',
                'gone' => 'https://api.test/gone.json',
                'nomaps' => 'https://api.test/nomaps.json',
            ]),
            'https://api.test/aachen.json' => (string) json_encode(['nodeMaps' => [
                ['technicalType' => 'meshviewer', 'url' => 'https://map.test/aachen/'],
                ['technicalType' => 'NetMon', 'url' => 'https://netmon.test/'],
                ['technicalType' => 'ffmap'],
                ['technicalType' => 'hopglass', 'url' => 'schemeless.test/map/data/nodes.json'],
            ]]),
            'https://api.test/nomaps.json' => '{"nodeMaps": []}',
            // the declared URL is a viewer page; the real data is found via config.json -> dataPath
            'https://map.test/aachen/' => '<html></html>',
            'https://map.test/aachen/config.json' => '{"dataPath": "/data/"}',
            'https://map.test/data/nodes.json' => self::NODEINFO,
            'https://netmon.test/' => '<html>netmon</html>',
            'http://schemeless.test/map/data/nodes.json' => self::NODEINFO,
            'https://fixed.test/nodes.json' => '{"nodes": [{"name": "x", "geo": [50.1, 8.6]}]}',
        ];
    }

    public function testAudit(): void
    {
        $messages = [];
        $report = $this->auditor($this->routes())->audit(
            ['ff_fixed' => ['url' => 'https://fixed.test/nodes.json', 'parser' => 'Ffmap']],
            null,
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertNotEmpty($messages);
        $this->assertSame(3, $report['directoryCommunities']);
        $this->assertSame(['gone' => 'HTTP 404'], $report['apiFailures']);
        $this->assertSame(['nomaps'], $report['withoutNodeMaps']);

        $byKey = [];

        foreach ($report['entries'] as $entry) {
            $byKey[$entry['community'] . '/' . $entry['declaredType']] = $entry;
        }

        // entries without a url are ignored
        $this->assertCount(4, $byKey);

        $schemeless = $byKey['aachen/hopglass'];
        $this->assertSame('http://schemeless.test/map/data/nodes.json', $schemeless['url']);
        $this->assertTrue($schemeless['working']);

        $meshviewer = $byKey['aachen/meshviewer'];
        $this->assertTrue($meshviewer['working']);
        $this->assertSame('https://map.test/data/nodes.json', $meshviewer['resolvedUrl']);
        $this->assertSame('nodeinfo', $meshviewer['format']);
        $this->assertSame(1, $meshviewer['nodes']);
        $this->assertSame(1, $meshviewer['located']);
        $this->assertSame('html', $meshviewer['declaredUrlFormat']);

        // netmon is not a discovery type: only the declared URL is probed
        $netmon = $byKey['aachen/netmon'];
        $this->assertFalse($netmon['working']);
        $this->assertSame('html', $netmon['format']);
        $this->assertCount(1, $netmon['tried']);

        $fixed = $byKey['ff_fixed/ffmap'];
        $this->assertTrue($fixed['working']);
        $this->assertSame('legacy_geo', $fixed['format']);
        $this->assertSame('fixed', $fixed['origin']);

        $summary = $report['summary'];
        $this->assertSame(2, $summary['communitiesWithWorkingSource']);
        $this->assertSame(1, $summary['byDeclaredType']['hopglass']['working']);
        $this->assertSame(1, $summary['apiFileFailed']);
        $this->assertSame(['entries' => 1, 'working' => 0, 'nodes' => 0, 'located' => 0, 'results' => ['html' => 1]], $summary['byDeclaredType']['netmon']);
        $this->assertSame(1, $summary['byDeclaredType']['meshviewer']['working']);
    }

    public function testOnlyRestrictsToOneCommunity(): void
    {
        $report = $this->auditor($this->routes())->audit([], 'aachen');

        $this->assertSame(1, $report['directoryCommunities']);
        $this->assertSame([], $report['apiFailures']);
        $this->assertCount(3, $report['entries']);
    }

    public function testBrokenDirectoryAborts(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->auditor([])->audit();
    }
}
