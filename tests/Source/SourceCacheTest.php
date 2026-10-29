<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Source;

use DateTimeImmutable;
use FreifunkKarte\Source\SourceCache;
use PHPUnit\Framework\TestCase;

final class SourceCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffmap_sourcecache_' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    public function testUnknownMapIsNull(): void
    {
        $this->assertNull((new SourceCache())->get('https://map.test/', self::at('2026-10-29T12:00:00Z')));
    }

    public function testRememberedUrlsAreReturnedUntilTheyExpire(): void
    {
        $cache = new SourceCache();
        $cache->remember('https://map.test/', ['https://map.test/a.json', 'https://map.test/b.json'], self::at('2026-10-20T12:00:00Z'));

        $this->assertSame(['https://map.test/a.json', 'https://map.test/b.json'], $cache->get('https://map.test/', self::at('2026-10-27T12:00:00Z')));
        $this->assertNull($cache->get('https://map.test/', self::at('2026-10-27T12:00:01Z')), 'older than 7 days');
    }

    public function testNothingIsRememberedForNoUrls(): void
    {
        $cache = new SourceCache();
        $cache->remember('https://map.test/', [], self::at('2026-10-29T12:00:00Z'));

        $this->assertSame(0, $cache->count());
    }

    public function testForget(): void
    {
        $cache = new SourceCache();
        $cache->remember('https://map.test/', ['https://map.test/a.json'], self::at('2026-10-29T12:00:00Z'));
        $cache->forget('https://map.test/');

        $this->assertNull($cache->get('https://map.test/', self::at('2026-10-29T12:00:00Z')));
    }

    public function testFileRoundTripDropsExpiredEntries(): void
    {
        $file = $this->directory . '/sources.json';
        $cache = new SourceCache($file);
        $cache->remember('https://fresh.test/', ['https://fresh.test/n.json'], self::at('2026-10-28T12:00:00Z'));
        $cache->remember('https://old.test/', ['https://old.test/n.json'], self::at('2026-10-01T12:00:00Z'));
        $cache->save(self::at('2026-10-29T12:00:00Z'));

        $reloaded = new SourceCache($file);

        $this->assertSame(1, $reloaded->count());
        $this->assertSame(['https://fresh.test/n.json'], $reloaded->get('https://fresh.test/', self::at('2026-10-29T12:00:00Z')));
        $this->assertFileDoesNotExist($file . '.tmp');
    }

    public function testBrokenOrMalformedFileIsIgnored(): void
    {
        $file = $this->directory . '/sources.json';
        file_put_contents($file, 'not json');
        $this->assertSame(0, (new SourceCache($file))->count());

        file_put_contents($file, (string) json_encode([
            'https://ok.test/' => ['urls' => ['https://ok.test/n.json'], 'foundAt' => 1],
            'https://nourls.test/' => ['urls' => [], 'foundAt' => 1],
            'https://notime.test/' => ['urls' => ['x']],
            'https://junk.test/' => 'junk',
        ]));
        $this->assertSame(1, (new SourceCache($file))->count());
    }

    public function testCacheWithoutFileDoesNotWrite(): void
    {
        $cache = new SourceCache();
        $cache->remember('https://map.test/', ['https://map.test/a.json'], self::at('2026-10-29T12:00:00Z'));
        $cache->save(self::at('2026-10-29T12:00:00Z'));

        $this->assertSame([], glob($this->directory . '/*'));
    }
}
