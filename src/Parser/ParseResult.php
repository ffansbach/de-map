<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Model\Node;

/**
 * What one node source yielded.
 */
final readonly class ParseResult
{
    /**
     * @param list<Node> $nodes the nodes that make it to the map
     * @param int $found entries in the source
     * @param int $withoutLocation entries without usable coordinates
     * @param int $dropped offline entries not seen for long enough (or at an unknown time)
     * @param int $invalid entries that are not nodes (no id, not an object)
     * @param list<string> $warnings anything odd about the source that a human may want to know
     * @param bool $parsed false if the source was not read at all (no node list, or a format we do not parse)
     */
    public function __construct(
        public Format $format,
        public array $nodes = [],
        public int $found = 0,
        public int $withoutLocation = 0,
        public int $dropped = 0,
        public int $invalid = 0,
        public array $warnings = [],
        public bool $parsed = true,
    ) {
    }

    /**
     * For sources that are not a node list at all.
     */
    public static function unusable(Format $format, string $reason): self
    {
        return new self($format, warnings: [$reason], parsed: false);
    }

    /**
     * @param list<string> $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self(
            $this->format,
            $this->nodes,
            $this->found,
            $this->withoutLocation,
            $this->dropped,
            $this->invalid,
            [...$this->warnings, ...$warnings],
            $this->parsed,
        );
    }

    public function isUsable(): bool
    {
        return $this->parsed;
    }

    public function summary(): string
    {
        return sprintf(
            '%d nodes found, %d accepted, %d without location, %d dropped (offline), %d invalid',
            $this->found,
            count($this->nodes),
            $this->withoutLocation,
            $this->dropped,
            $this->invalid,
        );
    }
}
