<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Source;

use DateTimeImmutable;
use FreifunkKarte\Audit\Format;
use FreifunkKarte\Model\Node;
use FreifunkKarte\Model\NodeStatus;
use FreifunkKarte\Parser\ParseResult;
use FreifunkKarte\Source\LastGoodStore;
use PHPUnit\Framework\TestCase;

final class LastGoodStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffmap_lastgood_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private static function sampleResult(): ParseResult
    {
        return new ParseResult(
            Format::Nodelist,
            [new Node('a', 'alpha', '50.1', '8.1', NodeStatus::Online, 3), new Node('b', 'beta', '50.2', '8.2', NodeStatus::Offline, 0)],
            5,
            1,
            2,
            0,
            ['schema 1.0.1: something'],
        );
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    public function testParseResultSurvivesSerialisation(): void
    {
        $restored = ParseResult::fromArray(self::sampleResult()->toArray());

        $this->assertEquals(self::sampleResult(), $restored);
    }

    public function testBrokenStoredResultsAreRefused(): void
    {
        $this->assertNull(ParseResult::fromArray([]));
        $this->assertNull(ParseResult::fromArray(['format' => 'no-such-format', 'nodes' => []]));
        $this->assertNull(ParseResult::fromArray(['format' => 'nodelist', 'nodes' => [['id' => 'a']]]));
        $this->assertNull(Node::fromArray(['id' => 'a', 'name' => 'n', 'lat' => '1', 'long' => '2', 'status' => 'sleeping', 'clients' => 1]));
    }

    public function testSavedResultIsReturnedWithinADay(): void
    {
        $store = new LastGoodStore($this->directory);
        $store->save('https://a.test/n.json', self::sampleResult(), self::at('2026-10-29T12:00:00Z'));

        $loaded = $store->load('https://a.test/n.json', self::at('2026-10-30T12:00:00Z'));

        $this->assertNotNull($loaded);
        $this->assertEquals(self::sampleResult(), $loaded['result']);
        $this->assertSame('2026-10-29T12:00:00+00:00', $loaded['savedAt']->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertNull($store->load('https://a.test/n.json', self::at('2026-10-30T12:00:01Z')), 'older than a day');
        $this->assertNull($store->load('https://other.test/n.json', self::at('2026-10-29T12:00:00Z')));
    }

    public function testASecondStoreOnTheSameDirectoryFindsIt(): void
    {
        (new LastGoodStore($this->directory))->save('https://a.test/n.json', self::sampleResult(), self::at('2026-10-29T12:00:00Z'));

        $loaded = (new LastGoodStore($this->directory))->load('https://a.test/n.json', self::at('2026-10-29T13:00:00Z'));

        $this->assertEquals(self::sampleResult(), $loaded['result'] ?? null);
        $this->assertSame([], glob($this->directory . '/*.tmp'));
    }

    public function testNothingWithoutNodesIsStored(): void
    {
        $store = new LastGoodStore($this->directory);
        $store->save('https://a.test/empty.json', new ParseResult(Format::Nodelist, [], 3), self::at('2026-10-29T12:00:00Z'));
        $store->save('https://a.test/html', ParseResult::unusable(Format::Html, 'web page'), self::at('2026-10-29T12:00:00Z'));

        $this->assertSame([], glob($this->directory . '/*.json'));
        $this->assertNull($store->load('https://a.test/empty.json', self::at('2026-10-29T12:00:00Z')));
    }

    public function testCorruptFileIsIgnored(): void
    {
        $store = new LastGoodStore($this->directory);
        file_put_contents($this->directory . '/' . md5('https://a.test/n.json') . '.json', '{broken');

        $this->assertNull($store->load('https://a.test/n.json', self::at('2026-10-29T12:00:00Z')));
    }

    public function testPruneRemovesOldAndBrokenFiles(): void
    {
        $store = new LastGoodStore($this->directory);
        $store->save('https://old.test/n.json', self::sampleResult(), self::at('2026-10-20T12:00:00Z'));
        $store->save('https://new.test/n.json', self::sampleResult(), self::at('2026-10-29T11:00:00Z'));
        file_put_contents($this->directory . '/junk.json', 'junk');

        $store->prune(self::at('2026-10-29T12:00:00Z'));

        $this->assertSame([md5('https://new.test/n.json') . '.json'], array_map('basename', glob($this->directory . '/*.json') ?: []));
    }

    public function testWithoutADirectoryItKeepsThingsInMemoryOnly(): void
    {
        $store = new LastGoodStore();
        $store->save('https://a.test/n.json', self::sampleResult(), self::at('2026-10-29T12:00:00Z'));

        $this->assertNotNull($store->load('https://a.test/n.json', self::at('2026-10-29T13:00:00Z')));
        $this->assertNull((new LastGoodStore())->load('https://a.test/n.json', self::at('2026-10-29T13:00:00Z')));
    }
}
