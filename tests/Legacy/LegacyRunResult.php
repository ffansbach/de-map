<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Legacy;

/**
 * Everything observable from one run of the legacy parser.
 */
final readonly class LegacyRunResult
{
    /**
     * @param list<array<array-key, mixed>> $routers contents of result_routers.json
     * @param array<array-key, mixed> $communities contents of result_communities.json
     * @param array<string, list<string>> $messages per community: the debug messages of result_statistics.json
     * @param list<string> $calls URLs requested, in order
     * @param list<string> $warnings PHP warnings/notices/deprecations raised while parsing
     */
    public function __construct(
        public array $routers,
        public array $communities,
        public array $messages,
        public array $calls,
        public array $warnings,
        public string $output,
    ) {
    }

    /**
     * Router ids of one community, in output order.
     *
     * @return list<string>
     */
    public function idsOf(string $community): array
    {
        $ids = [];

        foreach ($this->routers as $router) {
            if ($router['community'] === $community) {
                $ids[] = (string) $router['id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function messagesOf(string $community): array
    {
        return $this->messages[$community] ?? [];
    }

    public function hasMessage(string $community, string $needle): bool
    {
        foreach ($this->messagesOf($community) as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
