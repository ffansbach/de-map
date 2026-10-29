<?php

declare(strict_types=1);

namespace FreifunkKarte\Audit;

/**
 * What a node source URL really delivers, independent of what the community declared.
 */
enum Format: string
{
    /** ffnodes "nodelist" format (schema/nodelist-schema-*.json) */
    case Nodelist = 'nodelist';
    /** nodes with a "nodeinfo" object: ffmap/meshviewer nodes.json v1/v2 and HopGlass */
    case NodeInfo = 'nodeinfo';
    /** flat nodes with node_id/hostname/location: current meshviewer.json */
    case MeshviewerFlat = 'meshviewer_flat';
    /** very old ffmap nodes with a geo[] array */
    case LegacyGeo = 'legacy_geo';
    /** ffmap/HopGlass config.json that points to the data via dataPath */
    case FfmapConfig = 'ffmap_config';
    case NodesUnknown = 'nodes_unknown';
    case NodesEmpty = 'nodes_empty';
    case JsonOther = 'json_other';
    case InvalidJson = 'invalid_json';
    case Html = 'html';
    case Empty = 'empty';

    /**
     * True for formats from which nodes can be read.
     */
    public function isNodeSource(): bool
    {
        return match ($this) {
            self::Nodelist, self::NodeInfo, self::MeshviewerFlat, self::LegacyGeo => true,
            default => false,
        };
    }
}
