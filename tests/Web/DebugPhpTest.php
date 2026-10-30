<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Web;

use PHPUnit\Framework\TestCase;

/**
 * The real debug.php, run on its own with a debug file: what a visitor sees for a community, and that
 * nothing from the file reaches the page unescaped.
 */
final class DebugPhpTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffk_debug_' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/cache', 0777, true);
        copy(dirname(__DIR__, 2) . '/web/debug.php', $this->directory . '/debug.php');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/cache/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory . '/cache');
        unlink($this->directory . '/debug.php');
        rmdir($this->directory);
    }

    /**
     * @param array<string, mixed> $communities
     */
    private function writeDebug(array $communities): void
    {
        file_put_contents($this->directory . '/cache/result_debug.json', (string) json_encode(['generated' => '2026-10-30T12:00:00+00:00', 'communities' => $communities]));
    }

    private function page(string $query = ''): string
    {
        $command = [PHP_BINARY, '-r', 'parse_str($argv[1], $_GET); require $argv[2];', $query, $this->directory . '/debug.php'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $this->assertSame('', $errors);

        return $output;
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function community(array $override = []): array
    {
        return $override + [
            'name' => 'Freifunk Beispiel', 'url' => 'https://example.org/', 'meta' => 'Beispielland', 'listed' => true, 'outcome' => 'ok', 'reason' => null,
            'apiUrl' => 'https://example.org/api.json', 'declaredTypes' => ['nodelist'], 'format' => 'nodelist', 'sourceUrls' => ['https://example.org/nodes.json'],
            'claimedNodes' => 120, 'found' => 100, 'added' => 90, 'duplicates' => 7, 'withoutLocation' => 3, 'dropped' => 0, 'invalid' => 0, 'staleSince' => null,
            'attempts' => [], 'warnings' => [], 'messages' => [],
        ];
    }

    public function testWithoutDataThePageSaysSo(): void
    {
        $this->assertStringContainsString('noch keine Parserdaten', $this->page());
    }

    public function testThePageCanScroll(): void
    {
        // css/site.css hides the overflow of <html> for the map; html#debug turns it back on
        $this->assertStringContainsString('<html lang="de" id="debug">', $this->page());
    }

    public function testTheListGroupsCommunitiesByMetaCommunity(): void
    {
        $this->writeDebug(['bsp' => self::community(), 'solo' => self::community(['name' => 'Solo', 'meta' => null])]);

        $page = $this->page();

        $this->assertStringContainsString('<optgroup label="Beispielland">', $page);
        $this->assertStringContainsString('<optgroup label="ohne Metacommunity">', $page);
        $this->assertStringContainsString('<option value="bsp">Freifunk Beispiel</option>', $page);
        $this->assertStringContainsString('Letzter Lauf: 30.10.2026', $page);
    }

    public function testACommunityWithNodesShowsTheCounts(): void
    {
        $this->writeDebug(['bsp' => self::community()]);

        $page = $this->page('c=bsp');

        $this->assertStringContainsString('Knoten gefunden', $page);
        $this->assertMatchesRegularExpression('~Auf die Karte übernommen</th><td>90</td>~', $page);
        $this->assertMatchesRegularExpression('~Duplikate\)</th><td>7</td>~', $page);
        $this->assertStringContainsString('<option value="bsp" selected>', $page);
    }

    public function testACommunityWithoutDataShowsTheReasonAndWhatWasTried(): void
    {
        $this->writeDebug(['bsp' => self::community([
            'outcome' => 'no-data', 'reason' => 'page-without-data', 'added' => 0, 'found' => 0,
            'attempts' => [['url' => 'https://map.example/nodes.json', 'status' => 404, 'error' => null, 'format' => null, 'nodes' => 0], ['url' => 'https://map.example/', 'status' => null, 'error' => 'timeout', 'format' => 'html', 'nodes' => 0]],
        ])]);

        $page = $this->page('c=bsp');

        $this->assertStringContainsString('Die Karte liefert keine Knotendaten', $page);
        $this->assertStringContainsString('auf keinem der bekannten Pfade', $page);
        $this->assertStringContainsString('https://map.example/nodes.json', $page);
        $this->assertStringContainsString('>404<', $page);
        $this->assertStringContainsString('>timeout<', $page);
    }

    public function testNothingFromTheFileReachesThePageUnescaped(): void
    {
        $this->writeDebug(['bsp' => self::community([
            'name' => '<script>alert(1)</script>', 'apiUrl' => 'javascript:alert(2)',
            'warnings' => ['<img src=x onerror=alert(3)>'], 'messages' => ['</pre><script>alert(4)</script>'],
        ])]);

        $page = $this->page('c=bsp');

        $this->assertStringNotContainsString('<script>alert', $page);
        $this->assertStringNotContainsString('<img src=x', $page);
        $this->assertStringNotContainsString('href="javascript:', $page);
    }

    public function testAnUnknownCommunityShowsNoDetails(): void
    {
        $this->writeDebug(['bsp' => self::community()]);

        $this->assertStringNotContainsString('<h2>', $this->page('c=../../etc/passwd'));
    }
}
