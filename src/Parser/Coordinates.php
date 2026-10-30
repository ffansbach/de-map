<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

final readonly class Coordinates
{
    private function __construct(
        public string $lat,
        public string $long,
    ) {
    }

    /**
     * Accepted: both values present, numeric and not 0 (null island
     * is where nodes without a configured position end up). New: values outside the valid range
     * are refused.
     *
     * The strings are PHP's own string cast of what the source delivered, so that the output
     * stays what the map has always received (a float like 12.0 becomes "12").
     */
    public static function from(mixed $lat, mixed $long): self|LocationProblem
    {
        if (!self::usable($lat) || !self::usable($long)) {
            return LocationProblem::Missing;
        }

        /** @var int|float|numeric-string $lat */
        /** @var int|float|numeric-string $long */
        if (abs((float) $lat) > 90 || abs((float) $long) > 180) {
            return LocationProblem::OutOfRange;
        }

        return new self((string) $lat, (string) $long);
    }

    private static function usable(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || is_string($value))
            && is_numeric($value)
            && (float) $value !== 0.0;
    }
}
