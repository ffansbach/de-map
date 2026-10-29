<?php

declare(strict_types=1);

namespace FreifunkKarte\Admin;

use FreifunkKarte\Run\RunRepository;

/**
 * What a page gets to work with: the history (read-only), the settings, and what the request said.
 */
final readonly class PageContext
{
    /**
     * @param array<string, string> $params from the path
     * @param array<string, mixed> $query from the query string
     */
    public function __construct(
        public RunRepository $repository,
        public AdminConfig $config,
        public array $params,
        public array $query,
    ) {
    }

    /**
     * A query parameter as text, or null if it is missing or empty.
     */
    public function text(string $key): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function int(string $key, int $default, int $min = 1): int
    {
        $value = $this->text($key);

        return $value !== null && ctype_digit($value) ? max($min, (int) $value) : $default;
    }

    public function time(mixed $iso): string
    {
        return Html::time($iso, $this->config->timezone);
    }
}
