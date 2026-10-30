<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Source;

use DateTimeImmutable;
use FreifunkKarte\Source\ApiDocumentStore;
use PHPUnit\Framework\TestCase;

final class ApiDocumentStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffkarte-api-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testGivesBackWhatWasSavedWithTheTimeItWasSaved(): void
    {
        $store = new ApiDocumentStore();
        $saved = new DateTimeImmutable('2026-10-30T12:00:00+00:00');
        $store->save('https://api.test/a.json', ['name' => 'A', 'nodeMaps' => []], $saved);

        $loaded = $store->load('https://api.test/a.json', $saved->modify('+1 hour'));

        $this->assertNotNull($loaded);
        $this->assertSame(['name' => 'A', 'nodeMaps' => []], $loaded['document']);
        $this->assertSame($saved->getTimestamp(), $loaded['savedAt']->getTimestamp());
        $this->assertNull($store->load('https://api.test/other.json', $saved));
    }

    public function testNothingIsGivenBackAfterTheTimeToLive(): void
    {
        $store = new ApiDocumentStore(null, 3600);
        $saved = new DateTimeImmutable('2026-10-30T12:00:00+00:00');
        $store->save('https://api.test/a.json', ['name' => 'A'], $saved);

        $this->assertNotNull($store->load('https://api.test/a.json', $saved->modify('+3600 seconds')));
        $this->assertNull($store->load('https://api.test/a.json', $saved->modify('+3601 seconds')));
    }

    public function testSurvivesARestartAndPrunesWhatIsTooOld(): void
    {
        $saved = new DateTimeImmutable('2026-10-30T12:00:00+00:00');
        (new ApiDocumentStore($this->directory))->save('https://api.test/a.json', ['name' => 'A'], $saved);
        (new ApiDocumentStore($this->directory))->save('https://api.test/b.json', ['name' => 'B'], $saved->modify('-2 days'));

        $next = new ApiDocumentStore($this->directory);
        $this->assertSame(['name' => 'A'], $next->load('https://api.test/a.json', $saved->modify('+1 hour'))['document'] ?? null);

        $next->prune($saved);

        $this->assertCount(1, glob($this->directory . '/*.json') ?: []);
        $this->assertNull((new ApiDocumentStore($this->directory))->load('https://api.test/b.json', $saved));
    }

    public function testAFileThatIsNotOurOwnIsIgnored(): void
    {
        $store = new ApiDocumentStore($this->directory);
        $store->save('https://api.test/a.json', ['name' => 'A'], new DateTimeImmutable());
        $file = (glob($this->directory . '/*.json') ?: [])[0];
        file_put_contents($file, '{"url":"https://api.test/someone-else.json","savedAt":1,"document":{"name":"X"}}');

        $this->assertNull((new ApiDocumentStore($this->directory))->load('https://api.test/a.json', (new DateTimeImmutable())->setTimestamp(2)));
    }
}
