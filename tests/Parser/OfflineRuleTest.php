<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Parser;

use DateTimeImmutable;
use FreifunkKarte\Parser\OfflineRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfflineRuleTest extends TestCase
{
    private const NOW = '2026-10-29T12:00:00+00:00';

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function lastSeenProvider(): array
    {
        return [
            'an hour ago' => ['2026-10-29T11:00:00+00:00', true],
            'two days ago' => ['2026-10-27T12:00:00Z', true],
            'exactly three days ago' => ['2026-10-26T12:00:00+00:00', true],
            'three days and a second ago' => ['2026-10-26T11:59:59+00:00', false],
            'another time zone' => ['2026-10-26T14:00:00+02:00', true],
            'ten days ago' => ['2026-10-19T12:00:00+00:00', false],
            'in the future (clock skew)' => ['2026-10-30T12:00:00+00:00', true],
            'unknown' => [null, false],
            'empty' => ['', false],
            'blank' => ['  ', false],
            'not a date' => ['yesterday-ish', false],
            'not a string' => [1761739200, false],
        ];
    }

    #[DataProvider('lastSeenProvider')]
    public function testKeeps(mixed $lastSeen, bool $expected): void
    {
        $this->assertSame($expected, (new OfflineRule())->keeps($lastSeen, new DateTimeImmutable(self::NOW)));
    }

    public function testMaximumAgeIsConfigurable(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $this->assertFalse((new OfflineRule(1))->keeps('2026-10-27T12:00:00+00:00', $now));
        $this->assertTrue((new OfflineRule(7))->keeps('2026-10-23T12:00:00+00:00', $now));
    }
}
