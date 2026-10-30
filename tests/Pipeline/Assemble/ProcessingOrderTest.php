<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Pipeline\Assemble;

use FreifunkKarte\Community\CommunityInfo;
use FreifunkKarte\Pipeline\Assemble\Choice;
use FreifunkKarte\Pipeline\Assemble\ProcessingOrder;
use FreifunkKarte\Pipeline\CommunityBuild;
use FreifunkKarte\Pipeline\CommunityReport;
use PHPUnit\Framework\TestCase;

final class ProcessingOrderTest extends TestCase
{
    private static function build(string $key, string $origin = CommunityBuild::ORIGIN_DIRECTORY): CommunityBuild
    {
        return new CommunityBuild(new CommunityInfo($key, $key, ''), $origin, null, true, CommunityReport::NO_DATA);
    }

    private static function choice(bool $nodelist): Choice
    {
        return new Choice($nodelist, [], []);
    }

    public function testNodelistsFirstThenOthersThenFixedEachInDirectoryOrder(): void
    {
        $builds = [
            self::build('a'),
            self::build('fixed1', CommunityBuild::ORIGIN_FIXED),
            self::build('b'),
            self::build('c'),
            self::build('fixed2', CommunityBuild::ORIGIN_FIXED),
        ];
        $choices = [self::choice(false), self::choice(false), self::choice(true), self::choice(false), self::choice(true)];

        // a fixed community stays in the last group even if its source is a nodelist
        $this->assertSame([2, 0, 3, 1, 4], ProcessingOrder::of($builds, $choices));
    }

    public function testNothingToOrder(): void
    {
        $this->assertSame([], ProcessingOrder::of([], []));
    }
}
