<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

use FreifunkKarte\Audit\Format;

/**
 * The nodelist format (https://github.com/ffansbach/nodelist): id, name, position, status.
 */
final class NodelistParser extends AbstractNodeParser
{
    public function format(): Format
    {
        return Format::Nodelist;
    }

    protected function extract(array $entry): ?RawNode
    {
        $id = self::text($entry['id'] ?? null);

        if ($id === '') {
            return null;
        }

        $position = self::section($entry['position'] ?? null);
        $status = self::section($entry['status'] ?? null);

        return new RawNode(
            $id,
            self::text($entry['name'] ?? null),
            $position['lat'] ?? null,
            $position['lon'] ?? $position['long'] ?? null,
            self::flag($status['online'] ?? null),
            $status['lastcontact'] ?? null,
            $status['clients'] ?? null,
        );
    }
}
