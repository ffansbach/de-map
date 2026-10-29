<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Legacy;

use ffmap\CommunityCacheHandler;
use ffmap\CommunityDebug;
use ffmap\NodeListParser;
use RuntimeException;

/**
 * Runs the untouched legacy NodeListParser against a fake network.
 *
 * The cache directory lives as long as the runner, so two run() calls behave like two
 * cron runs on the same machine. Call cleanUp() when done.
 */
final class LegacyRunner
{
    public const DIRECTORY_URL = 'https://directory.test/directory.json';

    private string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/ffmap_legacy_' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/communities', 0777, true);

        // not covered by the composer autoloader: data.php requires it by hand
        require_once __DIR__ . '/../../lib/jsv4/Jsv4.php';
    }

    /**
     * @param array<string, string> $routes url => body
     * @param array<string, array{url: string, parser: string, name?: string}> $fixed as in fixedCommunities.php
     * @param list<string> $skip
     */
    public function run(array $routes, array $fixed = [], array $skip = []): LegacyRunResult
    {
        $this->ageResultFiles();
        $curl = new FakeCurlHelper($routes);
        $parser = new NodeListParser(new CommunityCacheHandler($this->directory), $curl);
        $parser->setCommunityDebug(new CommunityDebug());
        $parser->setCachePath($this->directory . '/');
        $parser->setSource(self::DIRECTORY_URL);
        $parser->setSkipCommunities($skip);

        foreach ($fixed as $key => $source) {
            $parser->addAdditional($key, (object) [
                'name' => $source['name'] ?? $key,
                'nameShort' => $key,
                'url' => $source['url'],
                'homePage' => $source['url'],
                'parser' => $source['parser'],
            ]);
        }

        $warnings = [];
        set_error_handler(static function (int $errno, string $message, string $file, int $line) use (&$warnings): bool {
            $warnings[] = sprintf('%s (%s:%d)', $message, basename($file), $line);

            return true;
        });
        ob_start();

        try {
            $parser->getParsed(true);
        } finally {
            $output = (string) ob_get_clean();
            restore_error_handler();
        }

        $statistics = $this->read('statistics');
        $messages = [];

        foreach ($statistics['errorCommunities'] ?? [] as $name => $community) {
            $messages[(string) $name] = array_values($community['message'] ?? []);
        }

        $routers = [];

        foreach ($this->read('routers') as $router) {
            if (is_array($router)) {
                $routers[] = $router;
            }
        }

        return new LegacyRunResult(
            $routers,
            $this->read('communities'),
            $messages,
            $curl->calls,
            $warnings,
            $output,
        );
    }

    /**
     * Files the legacy parser left in its cache directory (names only, sorted).
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = array_values(array_filter(scandir($this->directory) ?: [], static fn (string $f): bool => $f[0] !== '.' && !is_dir($f)));
        sort($files);

        return array_values(array_filter($files, fn (string $f): bool => is_file($this->directory . '/' . $f)));
    }

    public function cleanUp(): void
    {
        $this->removeTree($this->directory);
    }

    /**
     * getParsed(true) only forces a reparse if the result is older than the current second
     * (TTL 0 compared with ">"). Between cron runs that is always true; here we have to make it so.
     */
    private function ageResultFiles(): void
    {
        foreach (glob($this->directory . '/result_*.json') ?: [] as $file) {
            touch($file, time() - 3600);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function read(string $name): array
    {
        $file = $this->directory . '/result_' . $name . '.json';

        if (!is_file($file)) {
            throw new RuntimeException('Legacy parser did not write ' . $file);
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            is_dir($path . '/' . $entry) ? $this->removeTree($path . '/' . $entry) : unlink($path . '/' . $entry);
        }

        rmdir($path);
    }
}
