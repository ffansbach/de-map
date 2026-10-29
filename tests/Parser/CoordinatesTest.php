<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Parser;

use FreifunkKarte\Parser\Coordinates;
use FreifunkKarte\Parser\LocationProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoordinatesTest extends TestCase
{
    /**
     * @return array<string, array{mixed, mixed, string|LocationProblem, 3?: string}>
     */
    public static function coordinateProvider(): array
    {
        return [
            'floats' => [50.1, 8.1, '50.1/8.1'],
            'whole float loses its decimals like the legacy string cast' => [12.0, 7.5, '12/7.5'],
            'numeric strings stay as they are' => ['50.5', '8.50', '50.5/8.50'],
            'southern and western hemisphere' => [-33.9, -70.5, '-33.9/-70.5'],
            'limits are valid' => [90, 180, '90/180'],
            'zero latitude is no position' => [0, 8.1, LocationProblem::Missing],
            'zero as string' => ['0', 8.1, LocationProblem::Missing],
            'zero longitude is no position' => [50.1, 0.0, LocationProblem::Missing],
            'null' => [null, 8.1, LocationProblem::Missing],
            'empty string' => ['', 8.1, LocationProblem::Missing],
            'text' => ['north', 8.1, LocationProblem::Missing],
            'boolean' => [true, 8.1, LocationProblem::Missing],
            'list' => [[50.1], 8.1, LocationProblem::Missing],
            'latitude too large' => [91, 8.1, LocationProblem::OutOfRange],
            'longitude too large' => [50.1, -181, LocationProblem::OutOfRange],
            'swapped values of a node in the Atlantic' => [8.1, 250, LocationProblem::OutOfRange],
        ];
    }

    #[DataProvider('coordinateProvider')]
    public function testFrom(mixed $lat, mixed $long, string|LocationProblem $expected): void
    {
        $result = Coordinates::from($lat, $long);

        if ($expected instanceof LocationProblem) {
            $this->assertSame($expected, $result);

            return;
        }

        $this->assertInstanceOf(Coordinates::class, $result);
        $this->assertSame($expected, $result->lat . '/' . $result->long);
    }

    public function testLongFloatsAreCastLikeTheLegacyParser(): void
    {
        // the legacy output is (string) $float, which depends on the "precision" ini setting
        $result = Coordinates::from(50.123456789012345, 8.1);

        $this->assertInstanceOf(Coordinates::class, $result);
        $this->assertSame((string) 50.123456789012345, $result->lat);
    }
}
