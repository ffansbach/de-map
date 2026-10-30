<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Web;

use PHPUnit\Framework\TestCase;

/**
 * web/templates/gpxfile.tpl run the way fetch.php runs it: the file is XML whatever the result file holds.
 */
final class GpxTemplateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffk_gpx_' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/web/templates', 0777, true);
        mkdir($this->directory . '/web/cache');
        copy(dirname(__DIR__, 2) . '/web/templates/gpxfile.tpl', $this->directory . '/web/templates/gpxfile.tpl');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->directory);
    }

    /**
     * @param list<array<string, mixed>> $routers
     * @param array<string, string> $request
     */
    private function gpx(array $routers, array $request = []): string
    {
        file_put_contents($this->directory . '/web/cache/result_routers.json', json_encode($routers));
        file_put_contents($this->directory . '/run.php', '<?php $_REQUEST = ' . var_export($request, true) . '; include $argv[1];');
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->directory . '/run.php') . ' ' . escapeshellarg($this->directory . '/web/templates/gpxfile.tpl') . ' 2>&1');

        return (string) $output;
    }

    /**
     * @return array<string, mixed>
     */
    private static function router(mixed $lat, mixed $long, string $name = 'node-1'): array
    {
        return ['id' => '1', 'name' => $name, 'lat' => $lat, 'long' => $long, 'community' => 'town', 'status' => 'online', 'clients' => 0];
    }

    public function testWaypointsKeepTheirCoordinatesAsPlainNumbers(): void
    {
        $xml = $this->gpx([self::router('50.1', '8.25'), self::router(12, 7.1234567891, 'b')]);

        $this->assertStringContainsString('<wpt lon="8.25" lat="50.1">', $xml);
        $this->assertStringContainsString('<wpt lon="7.1234568" lat="12">', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'well-formed XML');
    }

    public function testCoordinatesCannotBreakOutOfTheAttribute(): void
    {
        $xml = $this->gpx([self::router('50.1" onload="x', '8"/><script>alert(1)</script><wpt a="')]);

        $this->assertStringNotContainsString('<script>', $xml);
        $this->assertStringNotContainsString('onload', $xml);
        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);
        $this->assertCount(1, $parsed->wpt);
    }

    public function testNamesAreEscaped(): void
    {
        $xml = $this->gpx([self::router('50.1', '8.1', 'a <b> & "c"')]);

        $this->assertStringContainsString('<name>a &lt;b&gt; &amp; &quot;c&quot;</name>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function testBoundsFilterTheWaypoints(): void
    {
        $xml = $this->gpx(
            [self::router('50.1', '8.1', 'inside'), self::router('60.0', '8.1', 'outside')],
            ['minlat' => '49', 'maxlat' => '51', 'minlon' => '7', 'maxlon' => '9'],
        );

        $this->assertStringContainsString('inside', $xml);
        $this->assertStringNotContainsString('outside', $xml);
    }
}
