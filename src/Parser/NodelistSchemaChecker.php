<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * Checks a nodelist against its published JSON schema (draft-03) - for information only.
 * A deviation never stops a source from being parsed; it is a warning and a drift signal.
 */
final class NodelistSchemaChecker
{
    private const LATEST = '1.0.1';
    private const KNOWN = ['1.0.0', '1.0.1'];
    private const MAX_REPORTED = 5;

    public function __construct(private readonly string $schemaDirectory = __DIR__ . '/../../schema')
    {
    }

    /**
     * @return list<string> one line per kind of deviation, empty if the nodelist conforms
     */
    public function check(string $json): array
    {
        $data = json_decode($json);

        if (!is_object($data)) {
            return [];
        }

        $version = $data->version ?? null;
        $warnings = [];

        if (!is_string($version) || !in_array($version, self::KNOWN, true)) {
            $warnings[] = sprintf('unknown nodelist version %s, checked against %s', json_encode($version), self::LATEST);
            $version = self::LATEST;
        }

        $schema = json_decode((string) file_get_contents(sprintf('%s/nodelist-schema-%s.json', $this->schemaDirectory, $version)));

        $validator = new Validator();
        $validator->validate($data, $schema, Constraint::CHECK_MODE_NORMAL);

        return [...$warnings, ...$this->summarise($validator->getErrors(), $version)];
    }

    /**
     * Thousands of nodes can break the same rule; report each rule once with its count.
     *
     * @param array<int, array<string, mixed>> $errors
     * @return list<string>
     */
    private function summarise(array $errors, string $version): array
    {
        $counts = [];

        foreach ($errors as $error) {
            $property = preg_replace('/\[\d+\]/', '[]', (string) ($error['property'] ?? '')) ?? '';
            $line = sprintf('%s: %s', $property !== '' ? $property : '(root)', (string) ($error['message'] ?? ''));
            $counts[$line] = ($counts[$line] ?? 0) + 1;
        }

        arsort($counts);
        $lines = [];

        foreach (array_slice($counts, 0, self::MAX_REPORTED, true) as $line => $count) {
            $lines[] = sprintf('schema %s: %s%s', $version, $line, $count > 1 ? sprintf(' (%dx)', $count) : '');
        }

        if (count($counts) > self::MAX_REPORTED) {
            $lines[] = sprintf('schema %s: and %d more kinds of deviation', $version, count($counts) - self::MAX_REPORTED);
        }

        return $lines;
    }
}
