<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

/**
 * Why a map delivered no data, judged from what was tried. The interesting case is the first:
 * the map has a page, so somebody should look for where its data really is.
 */
enum NoDataReason: string
{
    case PageWithoutData = 'page-without-data';
    case EmptyData = 'empty-data';
    case NotFound = 'not-found';
    case Unreachable = 'unreachable';
    case NothingTried = 'nothing-tried';

    /**
     * @param list<array{url?: string, status?: int|null, error?: string|null, format?: string|null, nodes?: int}> $attempts
     */
    public static function classify(array $attempts): self
    {
        if ($attempts === []) {
            return self::NothingTried;
        }

        $ok = array_filter($attempts, static fn (array $a): bool => ($a['status'] ?? null) === 200);

        if (array_filter($ok, static fn (array $a): bool => ($a['format'] ?? null) === 'html') !== []) {
            return self::PageWithoutData;
        }

        if (array_filter($ok, static fn (array $a): bool => in_array($a['format'] ?? null, ['empty', 'nodes_empty'], true)) !== []) {
            return self::EmptyData;
        }

        if (array_filter($attempts, static fn (array $a): bool => ($a['status'] ?? null) !== null) !== []) {
            return self::NotFound;
        }

        return self::Unreachable;
    }

    public function label(): string
    {
        return match ($this) {
            self::PageWithoutData => 'a page answers, but no data was found behind it',
            self::EmptyData => 'the data file is there, but empty',
            self::NotFound => 'the server answers, but there is nothing at the known places',
            self::Unreachable => 'no answer from the server',
            self::NothingTried => 'nothing could be tried (no usable map)',
        };
    }
}
