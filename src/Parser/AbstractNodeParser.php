<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

use DateTimeImmutable;
use FreifunkKarte\Model\Node;
use FreifunkKarte\Model\NodeStatus;

/**
 * The rules that are the same for every format. A format only says where in an entry
 * the id, name, position, state, last-seen time and client count are.
 */
abstract class AbstractNodeParser implements NodeParser
{
    public function __construct(private readonly OfflineRule $offlineRule = new OfflineRule())
    {
    }

    /**
     * @param array<array-key, mixed> $entry one entry of "nodes"
     * @return RawNode|null null if the entry is not a usable node (no id)
     */
    abstract protected function extract(array $entry): ?RawNode;

    final public function parse(array $data, DateTimeImmutable $now): ParseResult
    {
        $entries = is_array($data['nodes'] ?? null) ? $data['nodes'] : [];

        $nodes = [];
        $withoutLocation = 0;
        $outOfRange = 0;
        $dropped = 0;
        $invalid = 0;

        foreach ($entries as $entry) {
            $raw = is_array($entry) ? $this->extract($entry) : null;

            if ($raw === null) {
                $invalid++;
                continue;
            }

            $coordinates = Coordinates::from($raw->lat, $raw->long);

            if (!$coordinates instanceof Coordinates) {
                $withoutLocation++;
                $outOfRange += $coordinates === LocationProblem::OutOfRange ? 1 : 0;
                continue;
            }

            if ($raw->online === null) {
                $status = NodeStatus::Unknown;
            } elseif ($raw->online) {
                $status = NodeStatus::Online;
            } elseif ($this->offlineRule->keeps($raw->lastSeen, $now)) {
                $status = NodeStatus::Offline;
            } else {
                $dropped++;
                continue;
            }

            $nodes[] = new Node(
                $raw->id,
                $raw->name !== '' ? $raw->name : $raw->id,
                $coordinates->lat,
                $coordinates->long,
                $status,
                ClientCount::from($raw->clients),
            );
        }

        $warnings = [];

        if ($outOfRange > 0) {
            $warnings[] = sprintf('%d nodes have coordinates outside the valid range', $outOfRange);
        }

        if ($invalid > 0) {
            $warnings[] = sprintf('%d entries are not usable nodes (no id)', $invalid);
        }

        return new ParseResult($this->format(), $nodes, count($entries), $withoutLocation, $dropped, $invalid, $warnings);
    }

    /**
     * Ids and names arrive as strings or numbers.
     */
    protected static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected static function section(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    protected static function flag(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
