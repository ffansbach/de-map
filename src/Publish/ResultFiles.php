<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

use RuntimeException;

/**
 * The files the map reads, as the parser wrote them.
 */
final readonly class ResultFiles
{
    public const REQUIRED = ['result_routers.json', 'result_communities.json'];
    public const OPTIONAL = ['result_statistics.json', 'result_metacommunities.json'];

    /**
     * @param array<string, string> $files file name => content
     */
    public function __construct(public array $files)
    {
    }

    public static function fromDirectory(string $directory): self
    {
        $files = [];

        foreach ([...self::REQUIRED, ...self::OPTIONAL] as $name) {
            $path = rtrim($directory, '/') . '/' . $name;

            if (is_file($path)) {
                $files[$name] = (string) file_get_contents($path);
            } elseif (in_array($name, self::REQUIRED, true)) {
                throw new RuntimeException(sprintf('%s is missing in %s: nothing to deliver', $name, $directory));
            }
        }

        return new self($files);
    }

    public function size(): int
    {
        return array_sum(array_map(strlen(...), $this->files));
    }

    public function routerCount(): int
    {
        $routers = json_decode($this->files['result_routers.json'] ?? '[]', true);

        return is_array($routers) ? count($routers) : 0;
    }
}
