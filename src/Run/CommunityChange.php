<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

final readonly class CommunityChange
{
    public const OUTCOME = 'outcome';
    public const FORMAT = 'format';
    public const NODES = 'nodes';
    public const ADDED = 'added';
    public const REMOVED = 'removed';

    public function __construct(
        public string $key,
        public string $name,
        public string $kind,
        public string $before,
        public string $after,
    ) {
    }
}
