<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

use FreifunkKarte\Audit\Format;

/**
 * ffmap / meshviewer nodes.json (v1 and v2) and HopGlass: entries with a "nodeinfo" object.
 */
final class NodeInfoParser extends AbstractNodeParser
{
    public function format(): Format
    {
        return Format::NodeInfo;
    }

    protected function extract(array $entry): ?RawNode
    {
        $info = self::section($entry['nodeinfo'] ?? null);
        $id = self::text($info['node_id'] ?? null);

        if ($id === '') {
            return null;
        }

        $location = self::section($info['location'] ?? null);

        return new RawNode(
            $id,
            self::text($info['hostname'] ?? null),
            $location['latitude'] ?? null,
            $location['longitude'] ?? null,
            self::flag(self::section($entry['flags'] ?? null)['online'] ?? null),
            $entry['lastseen'] ?? null,
            self::section($entry['statistics'] ?? null)['clients'] ?? null,
        );
    }
}
