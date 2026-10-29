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
     * @param list<string> $dataPaths only for a map config.json: where it says the data is
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
        public array $dataPaths = [],
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
            $this->dataPaths,
        );
    }

    /**
     * @return array<string, mixed> for the last-good store
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format->value,
            'nodes' => array_map(static fn (Node $node): array => $node->toArray(), $this->nodes),
            'found' => $this->found,
            'withoutLocation' => $this->withoutLocation,
            'dropped' => $this->dropped,
            'invalid' => $this->invalid,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param array<array-key, mixed> $data as written by {@see toArray()}; null if it is not a stored result
     */
    public static function fromArray(array $data): ?self
    {
        $format = is_string($data['format'] ?? null) ? Format::tryFrom($data['format']) : null;

        if ($format === null || !is_array($data['nodes'] ?? null)) {
            return null;
        }

        $nodes = [];

        foreach ($data['nodes'] as $stored) {
            $node = is_array($stored) ? Node::fromArray($stored) : null;

            if ($node === null) {
                return null;
            }

            $nodes[] = $node;
        }

        $warnings = is_array($data['warnings'] ?? null) ? array_values(array_filter($data['warnings'], is_string(...))) : [];

        return new self(
            $format,
            $nodes,
            self::count($data, 'found'),
            self::count($data, 'withoutLocation'),
            self::count($data, 'dropped'),
            self::count($data, 'invalid'),
            $warnings,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function count(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : 0;
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
