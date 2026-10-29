<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

final readonly class PublishResult
{
    public function __construct(
        public string $publisher,
        public bool $ok,
        public string $message,
        public float $seconds,
        public int $bytesSent = 0,
    ) {
    }
}
