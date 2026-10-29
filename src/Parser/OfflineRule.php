<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

use DateTimeImmutable;
use Exception;

/**
 * An offline node stays on the map for a few days, but only if we know when it was last seen.
 */
final readonly class OfflineRule
{
    public function __construct(private int $maxAgeDays = 3)
    {
    }

    public function keeps(mixed $lastSeen, DateTimeImmutable $now): bool
    {
        if (!is_string($lastSeen) || trim($lastSeen) === '') {
            return false;
        }

        try {
            $seen = new DateTimeImmutable($lastSeen);
        } catch (Exception) {
            return false;
        }

        return $now->getTimestamp() - $seen->getTimestamp() <= $this->maxAgeDays * 86400;
    }
}
