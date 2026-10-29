<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

/**
 * One URL requested during a run, as the run history keeps it.
 */
final readonly class FetchRecord
{
    public function __construct(
        public string $url,
        public ?int $status,
        public ?string $error,
        public int $bytes,
        public float $seconds,
        public bool $tlsInvalid,
        public ?string $format,
        public int $nodes,
    ) {
    }
}
