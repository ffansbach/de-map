<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Run\DriftDetector;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\StoredCommunity;
use PHPUnit\Framework\TestCase;

final class DriftDetectorTest extends TestCase
{
    /**
     * @param list<string> $types
     * @param list<string> $warnings
     */
    private static function now(string $key, string $outcome = CommunityReport::OK, ?string $format = 'nodelist', int $added = 100, int $duplicates = 0, array $types = ['nodelist'], array $warnings = [], ?string $staleSince = null, bool $listed = true): CommunityReport
    {
        return new CommunityReport(
            $key,
            'Freifunk ' . $key,
            'https://' . $key . '.example/',
            null,
            'directory',
            'https://api.test/' . $key . '.json',
            $listed,
            $outcome,
            declaredTypes: $types,
            sourceUrls: ['https://' . $key . '.test/n.json'],
            format: $format,
            added: $added,
            duplicates: $duplicates,
            warnings: $warnings,
            staleSince: $staleSince,
        );
    }

    /**
     * @param list<string> $types
     * @param list<string> $warnings
     */
    private static function before(string $key, string $outcome = CommunityReport::OK, ?string $format = 'nodelist', int $added = 100, int $duplicates = 0, array $types = ['nodelist'], array $warnings = [], ?string $staleSince = null, bool $listed = true): StoredCommunity
    {
        return new StoredCommunity($key, 'Freifunk ' . $key, $outcome, $format, $listed, $added + $duplicates, $added, $duplicates, $staleSince, $types, [], $warnings);
    }

    /**
     * @param list<CommunityReport> $current
     * @param list<StoredCommunity> $previous
     * @param list<string> $knownTypes
     * @return list<DriftEvent>
     */
    private function detect(array $current, array $previous, array $knownTypes = ['nodelist'], int $nodes = 1000, ?int $previousNodes = 1000, ?DriftDetector $detector = null): array
    {
        $byKey = [];

        foreach ($previous as $stored) {
            $byKey[$stored->key] = $stored;
        }

        return ($detector ?? new DriftDetector())->detect($current, $byKey, $knownTypes, $nodes, $previousNodes);
    }

    /**
     * @param list<DriftEvent> $events
     * @return list<string>
     */
    private static function kinds(array $events): array
    {
        return array_map(static fn (DriftEvent $e): string => $e->severity . ':' . $e->kind . ':' . $e->subject, $events);
    }

    public function testNothingToSayAboutTheFirstRun(): void
    {
        $this->assertSame([], $this->detect([self::now('a', types: ['brand-new'])], [], [], 1000, null));
    }

    public function testUnchangedRunIsQuiet(): void
    {
        $this->assertSame([], $this->detect([self::now('a'), self::now('b')], [self::before('a'), self::before('b')]));
    }

    public function testATechnicalTypeNeverSeenBeforeIsReported(): void
    {
        $events = $this->detect(
            [self::now('a', types: ['nodelist', 'yanic-v3']), self::now('b', types: ['yanic-v3']), self::now('c')],
            [self::before('a'), self::before('b'), self::before('c')],
            ['nodelist'],
        );

        $this->assertSame(['warning:new-technical-type:yanic-v3'], self::kinds($events));
        $this->assertSame(['a', 'b'], $events[0]->details['communities']);
        $this->assertStringContainsString('has not been seen before', $events[0]->message);
    }

    public function testTheFormatOfAWorkingSourceChanging(): void
    {
        $events = $this->detect([self::now('a', format: 'meshviewer_flat')], [self::before('a', format: 'nodeinfo')]);

        $this->assertSame(['warning:format-changed:a'], self::kinds($events));
        $this->assertSame(['before' => 'nodeinfo', 'after' => 'meshviewer_flat', 'sources' => ['https://a.test/n.json']], $events[0]->details);
    }

    public function testNodesDroppingToHalfOrLess(): void
    {
        $this->assertSame(['warning:nodes-dropped:a'], self::kinds($this->detect([self::now('a', added: 50)], [self::before('a', added: 100)])));
        $this->assertSame([], $this->detect([self::now('a', added: 51)], [self::before('a', added: 100)]));
        $this->assertSame(['warning:nodes-dropped:a'], self::kinds($this->detect([self::now('a', added: 0, duplicates: 10)], [self::before('a', added: 100, duplicates: 0)])), 'nodes that another community kept still count as delivered');
    }

    public function testSmallCommunitiesMayFluctuate(): void
    {
        $this->assertSame([], $this->detect([self::now('a', added: 2)], [self::before('a', added: 19)]));
    }

    public function testLosingACommunityAndGettingItBack(): void
    {
        $lost = $this->detect([self::now('a', CommunityReport::NO_DATA, null, 0)], [self::before('a')]);
        $this->assertSame(['warning:community-lost:a'], self::kinds($lost));
        $this->assertSame('no-data', $lost[0]->details['outcome']);

        $apiGone = $this->detect([self::now('a', CommunityReport::API_UNAVAILABLE, null, 0)], [self::before('a')]);
        $this->assertSame(['warning:community-lost:a'], self::kinds($apiGone));

        $back = $this->detect([self::now('a')], [self::before('a', CommunityReport::NO_DATA, null, 0)]);
        $this->assertSame(['info:community-recovered:a'], self::kinds($back));
    }

    public function testSkippingACommunityIsNotLosingIt(): void
    {
        $this->assertSame([], $this->detect([self::now('a', CommunityReport::SKIPPED, null, 0)], [self::before('a')]));
    }

    public function testStaleDataIsAWarningTheFirstTimeAndInfoAfterwards(): void
    {
        $first = $this->detect([self::now('a', staleSince: '2026-10-29T10:00:00+00:00')], [self::before('a')]);
        $again = $this->detect([self::now('a', staleSince: '2026-10-29T10:00:00+00:00')], [self::before('a', staleSince: '2026-10-29T10:00:00+00:00')]);

        $this->assertSame(['warning:stale-data:a'], self::kinds($first));
        $this->assertSame(['info:stale-data:a'], self::kinds($again));
    }

    public function testStaleNodesDoNotAlsoCountAsAFormatChangeOrDrop(): void
    {
        $events = $this->detect([self::now('a', format: 'nodeinfo', added: 3, staleSince: '2026-10-29T10:00:00+00:00')], [self::before('a', format: 'nodelist')]);

        $this->assertSame(['warning:stale-data:a'], self::kinds($events));
    }

    public function testNewKindOfSourceWarningButNotTheSameKindWithOtherNumbers(): void
    {
        $same = $this->detect(
            [self::now('a', warnings: ['schema 1.0.1: nodes[].id: Integer value found, but a string is required (91x)'])],
            [self::before('a', warnings: ['schema 1.0.1: nodes[].id: Integer value found, but a string is required (12x)'])],
        );
        $new = $this->detect(
            [self::now('a', warnings: ['unknown nodelist version "1", checked against 1.0.1'])],
            [self::before('a')],
        );

        $this->assertSame([], $same);
        $this->assertSame(['info:new-source-warning:a'], self::kinds($new));
        $this->assertSame(['unknown nodelist version "X", checked against N.N.N'], $new[0]->details['kinds']);
    }

    public function testCommunitiesComingAndGoing(): void
    {
        $events = $this->detect(
            [self::now('stays'), self::now('new'), self::now('hidden', CommunityReport::NO_NODEMAPS, null, 0, listed: false)],
            [self::before('stays'), self::before('gone'), self::before('was-hidden', CommunityReport::NO_NODEMAPS, null, 0, listed: false)],
        );

        $kinds = self::kinds($events);
        $this->assertContains('info:community-added:new', $kinds);
        $this->assertContains('info:community-removed:gone', $kinds);
        $this->assertCount(2, $kinds, 'communities that are not listed are not worth a message');
    }

    public function testManyCommunitiesLostAtOnceIsAnAlert(): void
    {
        $current = $previous = [];

        foreach (['a', 'b', 'c', 'd', 'e'] as $key) {
            $current[] = self::now($key, CommunityReport::NO_DATA, null, 0);
            $previous[] = self::before($key);
        }

        $events = $this->detect($current, $previous);
        $this->assertContains('alert:many-communities-lost:run', self::kinds($events));

        $fewer = $this->detect(array_slice($current, 0, 4), array_slice($previous, 0, 4));
        $this->assertNotContains('alert:many-communities-lost:run', self::kinds($fewer));
    }

    public function testARunWithAFifthFewerNodesIsAnAlert(): void
    {
        $alert = $this->detect([self::now('a')], [self::before('a')], nodes: 799, previousNodes: 1000);
        $fine = $this->detect([self::now('a')], [self::before('a')], nodes: 800, previousNodes: 1000);

        $this->assertSame(['alert:run-nodes-dropped:run'], self::kinds($alert));
        $this->assertSame(['before' => 1000, 'after' => 799], $alert[0]->details);
        $this->assertSame([], $fine);
    }

    public function testThresholdsCanBeChanged(): void
    {
        $detector = new DriftDetector(minNodesForDrop: 5, dropRatio: 0.9, runDropRatio: 0.99, manyLost: 1);

        $events = $this->detect([self::now('a', added: 8), self::now('b', CommunityReport::NO_DATA, null, 0)], [self::before('a', added: 10), self::before('b')], nodes: 980, detector: $detector);

        $this->assertEqualsCanonicalizing(['warning:nodes-dropped:a', 'warning:community-lost:b', 'alert:many-communities-lost:run', 'alert:run-nodes-dropped:run'], self::kinds($events));
    }
}
