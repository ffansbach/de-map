<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Parser;

use FreifunkKarte\Parser\ClientCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientCountTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int}>
     */
    public static function clientsProvider(): array
    {
        return [
            'number' => [5, 5],
            'zero' => [0, 0],
            'numeric string' => ['7', 7],
            'float' => [3.9, 3],
            'list of clients is counted' => [[1, 2, 3], 3],
            'empty list' => [[], 0],
            'object with total' => [['total' => 4, 'wifi' => 9], 4],
            'total as string' => [['total' => '6'], 6],
            'object without total' => [['wifi' => 3], 0],
            'null' => [null, 0],
            'text' => ['abc', 0],
            'boolean' => [true, 0],
        ];
    }

    #[DataProvider('clientsProvider')]
    public function testFrom(mixed $clients, int $expected): void
    {
        $this->assertSame($expected, ClientCount::from($clients));
    }
}
