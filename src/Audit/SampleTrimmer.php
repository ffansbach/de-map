<?php

declare(strict_types=1);

namespace FreifunkKarte\Audit;

use InvalidArgumentException;
use JsonException;

/**
 * Turns a real node source response into a small, anonymised test fixture.
 *
 * Only keys the parsers read are kept (a whitelist, so unknown fields such as
 * contact data, MAC and IP addresses never get through). Host names and ids are
 * replaced, coordinates are rounded to about a kilometre. A few nodes of each
 * interesting kind are kept: online and offline, with and without location.
 */
final class SampleTrimmer
{
    private const KEEP_ONLINE_LOCATED = 4;
    private const KEEP_OFFLINE_LOCATED = 2;
    private const KEEP_ONLINE_UNLOCATED = 1;
    private const KEEP_OFFLINE_UNLOCATED = 1;

    private const COORDINATE_DECIMALS = 2;

    public function __construct(private readonly FormatDetector $detector = new FormatDetector())
    {
    }

    public function trim(string $body, Format $format): string
    {
        if (!in_array($format, [Format::NodeInfo, Format::MeshviewerFlat, Format::Nodelist], true)) {
            throw new InvalidArgumentException('No fixtures for format ' . $format->value);
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data) || !is_array($data['nodes'] ?? null)) {
            throw new InvalidArgumentException('No nodes found');
        }

        $result = $this->pick($data, $format === Format::Nodelist ? ['version', 'updated_at'] : ['version', 'timestamp']);
        $result['nodes'] = $this->sanitizeNodes($data['nodes'], $format);

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * @param array<array-key, mixed> $nodes
     * @return array<array-key, mixed>
     */
    private function sanitizeNodes(array $nodes, Format $format): array
    {
        $limits = [
            'online-located' => self::KEEP_ONLINE_LOCATED,
            'offline-located' => self::KEEP_OFFLINE_LOCATED,
            'online-unlocated' => self::KEEP_ONLINE_UNLOCATED,
            'offline-unlocated' => self::KEEP_OFFLINE_UNLOCATED,
        ];
        $taken = array_fill_keys(array_keys($limits), 0);
        $keyed = !array_is_list($nodes);
        $sanitized = [];
        $counter = 0;

        foreach ($nodes as $key => $node) {
            if (!is_array($node)) {
                continue;
            }

            $kind = ($this->isOnline($node, $format) ? 'online' : 'offline')
                . '-' . ($this->detector->isLocated($format, $node) ? 'located' : 'unlocated');

            if ($taken[$kind] >= $limits[$kind]) {
                continue;
            }

            $taken[$kind]++;
            $counter++;
            $clean = $this->sanitizeNode($node, $format, $counter);

            if ($keyed) {
                $sanitized[$this->anonymousId((string) $key)] = $clean;
            } else {
                $sanitized[] = $clean;
            }
        }

        return $sanitized;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function isOnline(array $node, Format $format): bool
    {
        return match ($format) {
            Format::NodeInfo => (bool) ($node['flags']['online'] ?? false),
            Format::MeshviewerFlat => (bool) ($node['is_online'] ?? false),
            default => (bool) ($node['status']['online'] ?? false),
        };
    }

    /**
     * @param array<array-key, mixed> $node
     * @return array<string, mixed>
     */
    private function sanitizeNode(array $node, Format $format, int $number): array
    {
        $name = 'node-' . $number;

        return match ($format) {
            Format::NodeInfo => $this->present([
                'nodeinfo' => $this->present([
                    'node_id' => $this->anonymousId((string) ($node['nodeinfo']['node_id'] ?? $number)),
                    'hostname' => $name,
                    'location' => $this->location($node['nodeinfo']['location'] ?? null, 'latitude', 'longitude'),
                ]),
                'flags' => $this->pick($node['flags'] ?? [], ['online']),
                'statistics' => $this->pick($node['statistics'] ?? [], ['clients']),
            ] + $this->pick($node, ['firstseen', 'lastseen'])),
            Format::MeshviewerFlat => $this->present([
                'node_id' => $this->anonymousId((string) ($node['node_id'] ?? $number)),
                'hostname' => $name,
                'location' => $this->location($node['location'] ?? null, 'latitude', 'longitude'),
            ] + $this->pick($node, ['is_online', 'is_gateway', 'clients', 'firstseen', 'lastseen'])),
            default => $this->present([
                // nodelist ids may be numbers; the parser casts them to string either way
                'id' => is_int($node['id'] ?? null) ? $node['id'] : $this->anonymousId((string) ($node['id'] ?? $number)),
                'name' => $name,
                'position' => $this->location($node['position'] ?? null, 'lat', array_key_exists('lon', $node['position'] ?? []) ? 'lon' : 'long'),
                'status' => $this->pick($node['status'] ?? [], ['online', 'clients', 'lastcontact', 'firstcontact']),
            ]),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function location(mixed $location, string $latKey, string $lonKey): ?array
    {
        if (!is_array($location)) {
            return null;
        }

        $result = [];

        foreach ([$latKey, $lonKey] as $key) {
            if (array_key_exists($key, $location)) {
                $result[$key] = $this->round($location[$key]);
            }
        }

        return $result === [] ? null : $result;
    }

    private function round(mixed $coordinate): mixed
    {
        if (is_string($coordinate) && is_numeric($coordinate)) {
            return (string) round((float) $coordinate, self::COORDINATE_DECIMALS);
        }

        if (is_int($coordinate) || is_float($coordinate)) {
            return round($coordinate, self::COORDINATE_DECIMALS);
        }

        return $coordinate;
    }

    private function anonymousId(string $id): string
    {
        return substr(sha1('fixture:' . $id), 0, 12);
    }

    /**
     * Drops missing values only; false and 0 are real data (offline, no clients).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function present(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param mixed $source
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function pick(mixed $source, array $keys): array
    {
        return is_array($source) ? array_intersect_key($source, array_flip($keys)) : [];
    }
}
