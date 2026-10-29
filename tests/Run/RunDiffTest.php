<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Run;

use FreifunkKarte\Run\CommunityChange;
use FreifunkKarte\Run\RunDiff;
use FreifunkKarte\Run\StoredCommunity;
use PHPUnit\Framework\TestCase;

final class RunDiffTest extends TestCase
{
    private static function community(string $key, string $outcome = 'ok', ?string $format = 'nodelist', int $added = 100, bool $listed = true): StoredCommunity
    {
        return new StoredCommunity($key, 'Freifunk ' . $key, $outcome, $format, $listed, $added, $added, 0, null, [], [], []);
    }

    /**
     * @param list<StoredCommunity> $communities
     * @return array<string, StoredCommunity>
     */
    private static function history(array $communities): array
    {
        return array_column(array_map(static fn (StoredCommunity $c): array => [$c->key, $c], $communities), 1, 0);
    }

    /**
     * @param list<CommunityChange> $changes
     * @return list<string>
     */
    private static function summary(array $changes): array
    {
        return array_map(static fn (CommunityChange $c): string => sprintf('%s:%s:%s>%s', $c->kind, $c->key, $c->before, $c->after), $changes);
    }

    public function testNothingChanged(): void
    {
        $run = self::history([self::community('a'), self::community('b')]);

        $this->assertSame([], RunDiff::between($run, $run));
    }

    public function testOutcomeFormatAndNodeChanges(): void
    {
        $before = self::history([self::community('lost'), self::community('format', format: 'nodeinfo'), self::community('grown', added: 100), self::community('small', added: 100)]);
        $after = self::history([self::community('lost', 'no-data', null, 0), self::community('format', format: 'meshviewer_flat'), self::community('grown', added: 130), self::community('small', added: 103)]);

        $this->assertSame([
            'format:format:nodeinfo>meshviewer_flat',
            'nodes:grown:100>130',
            'outcome:lost:ok>no-data',
        ], self::summary(RunDiff::between($before, $after)));
    }

    public function testNodeChangesNeedToBeLargeEnoughToMatter(): void
    {
        $before = self::history([self::community('tiny', added: 10), self::community('big', added: 1000)]);
        $after = self::history([self::community('tiny', added: 14), self::community('big', added: 1050)]);

        $this->assertSame([], RunDiff::between($before, $after), '4 nodes and 5% are noise');
        $this->assertSame(['nodes:tiny:10>15'], self::summary(RunDiff::between($before, self::history([self::community('tiny', added: 15), self::community('big', added: 1000)]))));
    }

    public function testNoNodeChangeIsShownWhenTheOutcomeChanged(): void
    {
        $changes = RunDiff::between(self::history([self::community('a', 'no-data', null, 0)]), self::history([self::community('a', added: 300)]));

        $this->assertSame(['outcome:a:no-data>ok'], self::summary($changes));
    }

    public function testCommunitiesComingAndGoingOnlyIfTheyAreListed(): void
    {
        $before = self::history([self::community('gone'), self::community('hidden-gone', 'api-unavailable', null, 0, false), self::community('same')]);
        $after = self::history([self::community('same'), self::community('new', added: 5), self::community('hidden-new', 'skipped', null, 0, false)]);

        $this->assertSame(['removed:gone:ok>', 'added:new:>ok'], self::summary(RunDiff::between($before, $after)));
    }
}
