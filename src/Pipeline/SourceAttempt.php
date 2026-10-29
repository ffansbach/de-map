<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

/**
 * One URL that was tried for a map, and what came back. For a map without data this is the
 * list a person needs to see what has been looked at.
 */
final readonly class SourceAttempt
{
    public function __construct(
        public string $url,
        public ?int $status,
        public ?string $error,
        public ?string $format,
        public int $nodes,
        public bool $tlsInvalid,
    ) {
    }
}
