<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

/**
 * The fields every format has, in the format's own types, before the common rules are applied.
 */
final readonly class RawNode
{
    public function __construct(
        public string $id,
        public string $name,
        public mixed $lat,
        public mixed $long,
        public ?bool $online,
        public mixed $lastSeen,
        public mixed $clients,
    ) {
    }
}
