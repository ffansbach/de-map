<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Model;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Model\Node;
use FreifunkKarte\Model\NodeCollection;
use FreifunkKarte\Model\NodeStatus;
use FreifunkKarte\Parser\ParseResult;
use PHPUnit\Framework\TestCase;

final class NodeCollectionTest extends TestCase
{
    private static function node(string $id = 'a', string $name = 'alpha', string $lat = '50.1', string $long = '8.1'): Node
    {
        return new Node($id, $name, $lat, $long, NodeStatus::Online, 2);
    }

    public function testDedupeKeyIsTheLegacyFormula(): void
    {
        $this->assertSame(md5('alpha' . 'a' . '50.1' . '8.1'), self::node()->dedupeKey());
    }

    public function testFirstCommunityKeepsTheNode(): void
    {
        $collection = new NodeCollection();

        $this->assertTrue($collection->add(self::node(), 'first'));
        $this->assertFalse($collection->add(self::node(), 'second'));
        $this->assertSame(1, $collection->count());
        $this->assertSame('first', $collection->toRouterEntries()[0]['community']);
    }

    public function testNodesDifferingInAnyKeyFieldAreDifferentNodes(): void
    {
        $collection = new NodeCollection();

        $collection->add(self::node(), 'c');
        $collection->add(self::node(id: 'b'), 'c');
        $collection->add(self::node(name: 'beta'), 'c');
        $collection->add(self::node(lat: '50.2'), 'c');
        $collection->add(self::node(long: '8.2'), 'c');

        $this->assertSame(5, $collection->count());
    }

    public function testRouterEntryHasTheShapeOfResultRoutersJson(): void
    {
        $entry = self::node()->toRouterEntry('town');

        $this->assertSame(['id', 'lat', 'long', 'name', 'community', 'status', 'clients'], array_keys($entry));
        $this->assertSame(['id' => 'a', 'lat' => '50.1', 'long' => '8.1', 'name' => 'alpha', 'community' => 'town', 'status' => 'online', 'clients' => 2], $entry);
    }

    public function testAddResultCountsAddedAndDuplicates(): void
    {
        $collection = new NodeCollection();
        $result = new ParseResult(Format::NodeInfo, [self::node(), self::node(id: 'b'), self::node()]);

        $this->assertSame(['added' => 2, 'duplicates' => 1], $collection->addResult('town', $result));
        $this->assertSame(['added' => 0, 'duplicates' => 3], $collection->addResult('other', $result));
        $this->assertSame(2, $collection->count());
    }
}
