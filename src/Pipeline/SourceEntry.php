<?php

declare(strict_types=1);

namespace FreifunkKarte\Pipeline;

/**
 * A map a community points to. The declared type is only a hint for discovery and for reports.
 */
final readonly class SourceEntry
{
    public function __construct(
        public string $community,
        public string $declaredType,
        public string $mapUrl,
    ) {
    }
}
