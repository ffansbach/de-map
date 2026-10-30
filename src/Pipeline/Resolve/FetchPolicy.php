<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline\Resolve;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Http\FetchResult;
use FreifunkKarte\Parser\ParseResult;

/**
 * What to make of an answer that is not good: ask again at once, show the last good data instead,
 * or believe it (a 404 and valid empty data are believed).
 */
final class FetchPolicy
{
    /**
     * No answer at all (connection, DNS, TLS, timeout), request timeout, too many requests or a server error.
     */
    public static function hasTrouble(FetchResult $fetch): bool
    {
        return $fetch->status === null || $fetch->status === 408 || $fetch->status === 429 || $fetch->status >= 500;
    }

    /**
     * Worth asking again right away: a server under load answers with an error or an empty body.
     * Not a timeout or any other missing answer: a host that timed out usually does it again.
     */
    public static function isTransient(?FetchResult $fetch, ?ParseResult $parsed): bool
    {
        if ($fetch === null || $fetch->status === null) {
            return false;
        }

        if ($fetch->status === 429 || $fetch->status >= 500) {
            return true;
        }

        return self::isEmptyBody($fetch, $parsed);
    }

    /**
     * Whether the last good nodes of a source may be shown instead of what it delivered now: the
     * server does not answer or answers with an error, or its body is empty. Wider than
     * {@see isTransient()}.
     */
    public static function mayFallBack(?FetchResult $fetch, ?ParseResult $parsed): bool
    {
        if ($fetch === null) {
            return false;
        }

        return self::hasTrouble($fetch) || self::isEmptyBody($fetch, $parsed);
    }

    /**
     * Why the last good nodes are shown, for the warning: what the source did instead.
     */
    public static function fallbackReason(FetchResult $fetch): string
    {
        return match (true) {
            $fetch->status === null => 'no answer (' . ($fetch->error ?? 'unreachable') . ')',
            $fetch->status === 200 => 'an empty body',
            default => 'HTTP ' . $fetch->status,
        };
    }

    /**
     * What went wrong with a JSON file that was expected, in a few words.
     */
    public static function describe(?FetchResult $fetch): string
    {
        return match (true) {
            $fetch === null => 'not fetched',
            $fetch->status === null => $fetch->error ?? 'unreachable',
            $fetch->status !== 200 => 'HTTP ' . $fetch->status,
            default => 'not a JSON object',
        };
    }

    private static function isEmptyBody(FetchResult $fetch, ?ParseResult $parsed): bool
    {
        return $fetch->status === 200 && $parsed?->format === Format::Empty;
    }
}
