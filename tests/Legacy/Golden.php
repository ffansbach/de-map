<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Legacy;

use PHPUnit\Framework\Assert;

/**
 * Golden files record what the legacy parser did, so that the rewrite can be compared against it.
 * Regenerate deliberately with: UPDATE_GOLDEN=1 vendor/bin/phpunit tests/Legacy
 */
final class Golden
{
    public static function assertMatches(string $name, string $actual): void
    {
        $file = __DIR__ . '/../fixtures/golden/legacy/' . $name;

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents($file, $actual);
        }

        Assert::assertFileExists($file, 'Golden file missing, create it with UPDATE_GOLDEN=1');
        Assert::assertSame((string) file_get_contents($file), $actual, 'Differs from golden file ' . $name);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * @param list<string> $calls
     */
    public static function calls(array $calls): string
    {
        return implode("\n", $calls) . "\n";
    }
}
