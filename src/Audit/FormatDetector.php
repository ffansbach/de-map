<?php

declare(strict_types=1);

namespace FreifunkKarte\Audit;

use JsonException;

/**
 * Recognises the format of a node source from its response body. Pure: no I/O.
 */
final class FormatDetector
{
    private const NODES_INSPECTED = 50;

    public function detect(string $body): DetectionResult
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return new DetectionResult(Format::Empty);
        }

        if ($trimmed[0] === '<') {
            return new DetectionResult(Format::Html);
        }

        try {
            $data = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new DetectionResult(Format::InvalidJson);
        }

        return $this->detectData($data);
    }

    /**
     * For callers that decoded the JSON themselves (saves decoding megabytes twice).
     */
    public function detectData(mixed $data): DetectionResult
    {
        if (!is_array($data)) {
            return new DetectionResult(Format::JsonOther);
        }

        if (isset($data['dataPath'])) {
            return new DetectionResult(Format::FfmapConfig, dataPaths: $this->dataPaths($data['dataPath']));
        }

        if (!isset($data['nodes']) || !is_array($data['nodes'])) {
            return new DetectionResult(Format::JsonOther);
        }

        $nodes = $data['nodes'];

        if ($nodes === []) {
            return new DetectionResult(Format::NodesEmpty);
        }

        $format = $this->nodesFormat($nodes);

        if ($format === null) {
            return new DetectionResult(Format::NodesUnknown, count($nodes));
        }

        $located = 0;

        foreach ($nodes as $node) {
            if (is_array($node) && $this->isLocated($format, $node)) {
                $located++;
            }
        }

        return new DetectionResult($format, count($nodes), $located);
    }

    /**
     * The first decisive node wins: single nodes can lack the optional keys (a nodelist
     * node without a position, for example), so a few nodes are looked at.
     *
     * @param array<array-key, mixed> $nodes
     */
    private function nodesFormat(array $nodes): ?Format
    {
        foreach (array_slice($nodes, 0, self::NODES_INSPECTED) as $node) {
            $format = is_array($node) ? $this->nodeFormat($node) : null;

            if ($format !== null) {
                return $format;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function nodeFormat(array $node): ?Format
    {
        return match (true) {
            isset($node['nodeinfo']) => Format::NodeInfo,
            isset($node['node_id']) || (isset($node['hostname']) && isset($node['location'])) => Format::MeshviewerFlat,
            array_key_exists('geo', $node) => Format::LegacyGeo,
            isset($node['id']) && (array_key_exists('position', $node) || isset($node['status'])) => Format::Nodelist,
            default => null,
        };
    }

    /**
     * The same plausibility the legacy parser applies: coordinates present, numeric (nodelist: non-empty).
     *
     * @param array<array-key, mixed> $node
     */
    public function isLocated(Format $format, array $node): bool
    {
        return match ($format) {
            Format::NodeInfo => $this->coordinates($node['nodeinfo']['location'] ?? null, 'latitude', 'longitude'),
            Format::MeshviewerFlat => $this->coordinates($node['location'] ?? null, 'latitude', 'longitude'),
            Format::LegacyGeo => !empty($node['geo'][0]) && !empty($node['geo'][1]),
            Format::Nodelist => $this->nodelistCoordinates($node['position'] ?? null),
            default => false,
        };
    }

    private function coordinates(mixed $location, string $latKey, string $lonKey): bool
    {
        return is_array($location)
            && !empty($location[$latKey]) && is_numeric($location[$latKey])
            && !empty($location[$lonKey]) && is_numeric($location[$lonKey]);
    }

    private function nodelistCoordinates(mixed $position): bool
    {
        if (!is_array($position)) {
            return false;
        }

        $lon = $position['lon'] ?? $position['long'] ?? null;

        return !empty($position['lat']) && !empty($lon);
    }

    /**
     * @return list<string>
     */
    private function dataPaths(mixed $dataPath): array
    {
        $paths = is_array($dataPath) ? $dataPath : [$dataPath];

        return array_values(array_filter($paths, is_string(...)));
    }
}
