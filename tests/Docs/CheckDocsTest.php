<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * bin/check-docs keeps the links and anchors of README.md and docs/*.md working.
 */
final class CheckDocsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffk_docs_' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/docs', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['README.md', 'docs/a.md', 'docs/b.md'] as $file) {
            @unlink($this->directory . '/' . $file);
        }

        @rmdir($this->directory . '/docs');
        @rmdir($this->directory);
    }

    /**
     * @return array{status: int, output: string}
     */
    private function check(string $root): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/check-docs', $root], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'output' => $output];
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->directory . '/' . $file, $content);
    }

    public function testWorkingLinksAndAnchorsPass(): void
    {
        $this->write('README.md', "# Titel\n\n## Überwachung: healthchecks.io und InfluxDB\n\n[a](docs/a.md#schritt-1-eins) [b](docs/b.md) [hier](#überwachung-healthchecksio-und-influxdb)\n\n<a href=\"docs/a.md\">x</a> <a href=\"#titel\">y</a> [web](https://example.org/#nope)\n");
        $this->write('docs/a.md', "# A\n\n## Schritt 1: Eins\n\n## Noch einer\n\n## Noch einer\n\n[zurück](../README.md#titel) [doppelt](a.md#noch-einer-1)\n");
        $this->write('docs/b.md', "# B\n");

        $result = $this->check($this->directory);

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertStringContainsString('3 documents checked, 0 problems', $result['output']);
    }

    public function testAMissingFileAndAMissingAnchorAreReported(): void
    {
        $this->write('README.md', "# Titel\n\n[weg](docs/gibt-es-nicht.md) [anker](docs/a.md#falsch) [selbst](#auch-falsch)\n");
        $this->write('docs/a.md', "# A\n");

        $result = $this->check($this->directory);

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('README.md: docs/gibt-es-nicht.md does not exist', $result['output']);
        $this->assertStringContainsString('README.md: docs/a.md has no heading #falsch', $result['output']);
        $this->assertStringContainsString('README.md: README.md has no heading #auch-falsch', $result['output']);
    }

    public function testLinksInsideCodeBlocksAreNotChecked(): void
    {
        $this->write('README.md', "# Titel\n\n```\n[kein Link](gibt-es-nicht.md)\n```\n");

        $this->assertSame(0, $this->check($this->directory)['status']);
    }

    public function testACodeBlockThatIsNotClosedIsReported(): void
    {
        $this->write('README.md', "# Titel\n\n```bash\nls\n");

        $result = $this->check($this->directory);

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('a code block is not closed', $result['output']);
    }

    public function testTheDocumentationOfThisRepositoryHasNoBrokenLinks(): void
    {
        $result = $this->check(dirname(__DIR__, 2));

        $this->assertSame(0, $result['status'], $result['output']);
    }
}
