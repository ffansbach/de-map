<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Parser;

use DateTimeImmutable;
use FreifunkKarte\Model\NodeCollection;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Tests\Legacy\LegacyEdgeCasesTest;
use FreifunkKarte\Tests\Legacy\LegacyRunner;
use FreifunkKarte\Tests\Legacy\World;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * The new parsers must produce what the legacy parser produced, except where the operator decided
 * otherwise. Every legacy edge case is replayed here; the deliberate differences are listed.
 */
final class LegacyParityTest extends TestCase
{
    /**
     * Legacy edge cases whose outcome changes on purpose: name => id => [status, clients].
     *
     * @var array<string, array<string, array{string, int}>>
     */
    private const DELIBERATE_DIFFERENCES = [
        // one offline rule for all formats: last-seen known and within 3 days => kept
        'flat: offline is dropped even if seen an hour ago' => ['a' => ['offline', 0]],
        // the old geo[] format is not supported any more: no source delivers it
        'geo: online, id is the name, clientcount' => [],
    ];

    /**
     * @param array<array-key, array{string, int}> $legacyExpected
     */
    #[DataProviderExternal(LegacyEdgeCasesTest::class, 'nodeRuleProvider')]
    public function testEdgeCasesGiveTheLegacyResult(string $type, string $body, array $legacyExpected): void
    {
        $expected = self::DELIBERATE_DIFFERENCES[$this->dataName()] ?? $legacyExpected;

        $collection = new NodeCollection();
        $collection->addResult('edge', (new SourceParser())->parse($body, new DateTimeImmutable()));

        $actual = [];

        foreach ($collection->toRouterEntries() as $router) {
            $actual[$router['id']] = [$router['status'], $router['clients']];
        }

        $this->assertEquals($expected, $actual);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fixtureProvider(): array
    {
        return [
            'nodeinfo' => ['nodeinfo', 'ffmap'],
            'meshviewer_flat' => ['meshviewer_flat', 'ffmap'],
            'nodelist' => ['nodelist', 'nodelist'],
        ];
    }

    #[DataProvider('fixtureProvider')]
    public function testFixturesGiveTheLegacyRoutersExceptDeliberateDifferences(string $fixture, string $type): void
    {
        $body = World::fixture($fixture, 'p');
        $url = 'https://parity.test/data/nodes.json';
        $world = (new World())->community('edge', ['nodeMaps' => [['technicalType' => $type, 'url' => $url]]])->route($url, $body);
        $runner = new LegacyRunner();

        try {
            $legacy = $runner->run($world->routes())->routers;
        } finally {
            $runner->cleanUp();
        }

        $collection = new NodeCollection();
        $collection->addResult('edge', (new SourceParser())->parse($body, new DateTimeImmutable()));
        $new = $collection->toRouterEntries();

        // the only change: the flat format now keeps its two recently seen offline nodes
        $extra = $fixture === 'meshviewer_flat' ? 2 : 0;

        $this->assertCount(count($legacy) + $extra, $new);
        $this->assertSame($legacy, array_values(array_filter($new, static fn (array $r): bool => $extra === 0 || $r['status'] === 'online')));

        if ($extra > 0) {
            $offline = array_values(array_filter($new, static fn (array $r): bool => $r['status'] === 'offline'));
            $this->assertCount($extra, $offline);
        }
    }
}
