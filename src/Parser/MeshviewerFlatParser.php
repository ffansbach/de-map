<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

use FreifunkKarte\Audit\Format;

/**
 * Current meshviewer.json: flat entries with node_id, hostname, location and is_online.
 */
final class MeshviewerFlatParser extends AbstractNodeParser
{
    public function format(): Format
    {
        return Format::MeshviewerFlat;
    }

    protected function extract(array $entry): ?RawNode
    {
        $id = self::text($entry['node_id'] ?? null);

        if ($id === '') {
            return null;
        }

        $location = self::section($entry['location'] ?? null);

        return new RawNode(
            $id,
            self::text($entry['hostname'] ?? null),
            $location['latitude'] ?? null,
            $location['longitude'] ?? null,
            self::flag($entry['is_online'] ?? null) ?? self::flag(self::section($entry['flags'] ?? null)['online'] ?? null),
            $entry['lastseen'] ?? null,
            $entry['clients'] ?? null,
        );
    }
}
