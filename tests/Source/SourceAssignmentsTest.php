<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Source;

use FreifunkKarte\Source\SourceAssignment;
use FreifunkKarte\Source\SourceAssignments;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SourceAssignmentsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/ffmap_assign_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testNoRulesMeansNoAssignment(): void
    {
        $this->assertNull((new SourceAssignments())->forUrl('https://map.test/data/a/meshviewer.json'));
        $this->assertSame(0, (new SourceAssignments())->count());
    }

    public function testThePrefixMatchesWhateverComesAfterIt(): void
    {
        $assignments = new SourceAssignments([new SourceAssignment('map.test/data/siegburg', 'siegburg')]);

        $this->assertSame('siegburg', $assignments->forUrl('https://map.test/data/siegburg1/domain01/meshviewer.json')?->community);
        $this->assertSame('siegburg', $assignments->forUrl('https://map.test/data/siegburg2/domain05/meshviewer.json')?->community);
        $this->assertNull($assignments->forUrl('https://map.test/data/lohmar1/meshviewer.json'));
        $this->assertNull($assignments->forUrl('https://other.test/data/siegburg1/meshviewer.json'));
    }

    public function testSchemeAndCaseDoNotMatter(): void
    {
        $assignments = new SourceAssignments([new SourceAssignment('HTTPS://Map.Test/Data/A/', 'a')]);

        $this->assertNotNull($assignments->forUrl('http://map.test/data/a/x.json'));
        $this->assertNotNull($assignments->forUrl('https://MAP.TEST/DATA/A/x.json'));
        $this->assertSame('map.test/data/a/x.json', SourceAssignments::identity(' HTTP://map.test/data/A/x.json '));
    }

    public function testAPrefixMatchesEverythingThatStartsWithItSoEndItWithASlashWhereThatMatters(): void
    {
        $loose = new SourceAssignments([new SourceAssignment('map.test/data/a', 'a')]);
        $exact = new SourceAssignments([new SourceAssignment('map.test/data/a/', 'a')]);

        $this->assertNotNull($loose->forUrl('https://map.test/data/all/x.json'), '"data/a" also catches "data/all"');
        $this->assertNull($exact->forUrl('https://map.test/data/all/x.json'));
        $this->assertNotNull($exact->forUrl('https://map.test/data/a/x.json'));
    }

    public function testTheFirstMatchingRuleWins(): void
    {
        $assignments = new SourceAssignments([
            new SourceAssignment('map.test/data/special/', 'special'),
            new SourceAssignment('map.test/data/', 'general', true),
        ]);

        $this->assertSame('special', $assignments->forUrl('https://map.test/data/special/x.json')?->community);
        $this->assertSame('general', $assignments->forUrl('https://map.test/data/other/x.json')?->community);
        $this->assertTrue($assignments->forUrl('https://map.test/data/other/x.json')->backfill);
        $this->assertFalse($assignments->forUrl('https://map.test/data/special/x.json')->backfill);
    }

    public function testRulesFromAFile(): void
    {
        file_put_contents($this->file, (string) json_encode([
            ['prefix' => 'map.test/data/a/', 'community' => 'a'],
            ['prefix' => ' map.test/data/all/ ', 'community' => ' b ', 'backfill' => true],
        ]));

        $assignments = SourceAssignments::fromFile($this->file);

        $this->assertSame(2, $assignments->count());
        $this->assertSame('b', $assignments->forUrl('https://map.test/data/all/meshviewer.json')?->community);
        $this->assertTrue($assignments->forUrl('https://map.test/data/all/meshviewer.json')->backfill);
    }

    public function testAMissingFileMeansNoRules(): void
    {
        $this->assertSame(0, SourceAssignments::fromFile($this->file)->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenProvider(): array
    {
        return [
            'not json' => ['{oops', 'must be a list of rules'],
            'an object instead of a list' => ['{"prefix": "a", "community": "b"}', 'must be a list of rules'],
            'rule is no object' => ['["map.test/data"]', 'rule 1 needs'],
            'missing community' => ['[{"prefix": "map.test/data"}]', 'rule 1 needs'],
            'empty prefix' => ['[{"prefix": " ", "community": "a"}]', 'rule 1 needs'],
            'backfill is no boolean' => ['[{"prefix": "a", "community": "b"}, {"prefix": "a", "community": "b", "backfill": "yes"}]', 'rule 2 needs'],
        ];
    }

    /**
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenProvider')]
    public function testABrokenFileIsAnErrorNotSilentlyIgnored(string $content, string $message): void
    {
        file_put_contents($this->file, $content);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        SourceAssignments::fromFile($this->file);
    }
}
