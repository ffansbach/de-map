<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

/**
 * Sources report clients as a number, as an object with a total (nodeinfo statistics:
 * {"total": 5, "wifi": 5}) or as a list of clients.
 */
final class ClientCount
{
    public static function from(mixed $clients): int
    {
        if (is_int($clients)) {
            return $clients;
        }

        if (is_float($clients) || (is_string($clients) && is_numeric($clients))) {
            return (int) $clients;
        }

        if (is_array($clients)) {
            if (array_is_list($clients)) {
                return count($clients);
            }

            $total = $clients['total'] ?? null;

            return is_numeric($total) ? (int) $total : 0;
        }

        return 0;
    }
}
