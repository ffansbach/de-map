<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Publish;

use FreifunkKarte\Publish\ResultFiles;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResultFilesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffk_files_' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testTheThreeFilesAreRead(): void
    {
        foreach (['result_routers.json' => '[1,2,3]', 'result_communities.json' => '{}', 'result_statistics.json' => '{"a":1}', 'result_metacommunities.json' => '{}', 'other.json' => 'x'] as $name => $content) {
            file_put_contents($this->directory . '/' . $name, $content);
        }

        $files = ResultFiles::fromDirectory($this->directory);

        // the retired statistics file and anything else in the directory is not delivered
        $this->assertSame(['result_routers.json', 'result_communities.json', 'result_metacommunities.json'], array_keys($files->files));
        $this->assertSame(3, $files->routerCount());
        $this->assertSame(7 + 2 + 2, $files->size());
    }

    public function testTheOptionalFilesMayBeMissing(): void
    {
        file_put_contents($this->directory . '/result_routers.json', '[]');
        file_put_contents($this->directory . '/result_communities.json', '{}');

        $this->assertSame(['result_routers.json', 'result_communities.json'], array_keys(ResultFiles::fromDirectory($this->directory)->files));
    }

    public function testWithoutTheRequiredFilesThereIsNothingToDeliver(): void
    {
        file_put_contents($this->directory . '/result_routers.json', '[]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('result_communities.json is missing');

        ResultFiles::fromDirectory($this->directory);
    }

    public function testBrokenRoutersCountAsNone(): void
    {
        $this->assertSame(0, (new ResultFiles(['result_routers.json' => 'oops']))->routerCount());
        $this->assertSame(0, (new ResultFiles([]))->routerCount());
    }
}
