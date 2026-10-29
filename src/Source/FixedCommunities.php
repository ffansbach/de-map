<?php

declare(strict_types=1);

namespace FreifunkKarte\Source;

/**
 * Communities that are not (or not correctly) in the directory and are listed by hand in
 * fixedCommunities.php. That file calls $parser->addAdditional(key, object); we only collect
 * what it adds and run no parser.
 */
final class FixedCommunities
{
    /**
     * @return array<string, array{url: string, parser: string, name: string, homePage: string}>
     */
    public static function load(string $file): array
    {
        $collector = new class () {
            /** @var array<string, array{url: string, parser: string, name: string, homePage: string}> */
            public array $items = [];

            public function addAdditional(string $key, \stdClass $item): void
            {
                $this->items[$key] = [
                    'url' => (string) $item->url,
                    'parser' => (string) $item->parser,
                    'name' => (string) ($item->name ?? $key),
                    'homePage' => (string) ($item->homePage ?? $item->url),
                ];
            }
        };

        (static function () use ($collector, $file): void {
            $parser = $collector;
            require $file;
        })();

        return $collector->items;
    }
}
