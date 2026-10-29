<?php

declare(strict_types=1);

namespace FreifunkKarte\Source;

use RuntimeException;

/**
 * One map server can carry the data of many communities: a page that names sixteen data files and
 * is the map of nine communities. These rules say which community the nodes of a data URL belong to.
 * A prefix matches everything that starts with it: "data/siegburg" catches siegburg1 and siegburg2,
 * "data/a/" does not catch "data/all/". The first rule whose prefix the URL starts with wins; a URL without a rule belongs to the
 * community whose map led to it.
 *
 * config/source-assignments.json:
 *   [{"prefix": "map.example.org/data/siegburg", "community": "siegburg"},
 *    {"prefix": "map.example.org/data/all/", "community": "troisdorf", "backfill": true}]
 */
final class SourceAssignments
{
    /** @var list<SourceAssignment> */
    private readonly array $rules;

    /**
     * @param list<SourceAssignment> $rules
     */
    public function __construct(array $rules = [])
    {
        $this->rules = array_map(
            static fn (SourceAssignment $rule): SourceAssignment => new SourceAssignment(self::identity($rule->prefix), $rule->community, $rule->backfill),
            $rules,
        );
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            return new self();
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data) || !array_is_list($data)) {
            throw new RuntimeException($file . ' must be a list of rules');
        }

        $rules = [];

        foreach ($data as $index => $rule) {
            $prefix = is_array($rule) ? ($rule['prefix'] ?? null) : null;
            $community = is_array($rule) ? ($rule['community'] ?? null) : null;
            $backfill = is_array($rule) ? ($rule['backfill'] ?? false) : false;

            if (!is_string($prefix) || trim($prefix) === '' || !is_string($community) || trim($community) === '' || !is_bool($backfill)) {
                throw new RuntimeException(sprintf('%s: rule %d needs a "prefix" and a "community" (and "backfill" must be true or false)', $file, $index + 1));
            }

            $rules[] = new SourceAssignment(trim($prefix), trim($community), $backfill);
        }

        return new self($rules);
    }

    public function forUrl(string $url): ?SourceAssignment
    {
        $identity = self::identity($url);

        foreach ($this->rules as $rule) {
            if (str_starts_with($identity, $rule->prefix)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * A URL without the scheme, in lower case: http and https of the same address are the same data.
     */
    public static function identity(string $url): string
    {
        return strtolower((string) preg_replace('~^https?://~i', '', trim($url)));
    }

    public function count(): int
    {
        return count($this->rules);
    }
}
