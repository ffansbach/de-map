<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Command;

use FreifunkKarte\Command\CompareCommand;
use FreifunkKarte\Command\RunCommand;
use FreifunkKarte\Tests\Legacy\LegacyRunner;
use FreifunkKarte\Tests\Legacy\StandardWorld;
use FreifunkKarte\Tests\Legacy\World;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RunCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffmap_command_' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                is_dir($path . '/' . $entry) ? $this->remove($path . '/' . $entry) : unlink($path . '/' . $entry);
            }
        }

        rmdir($path);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function execute(array $options, ?World $world = null): CommandTester
    {
        $world ??= StandardWorld::build();
        $command = new RunCommand($world->httpHandler(), $this->directory);
        // the reference world has a directory URL of its own
        $tester = new CommandTester($command);
        $tester->execute($options + [
            '--directory-url' => LegacyRunner::DIRECTORY_URL,
            '--out' => $this->directory . '/out',
            '--cache-dir' => $this->directory . '/cache',
            '--config-dir' => $this->directory . '/config',
            '--no-fixed' => true,
            '--min-nodes' => '1',
            '--min-communities' => '1',
        ]);

        return $tester;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readOut(string $name): array
    {
        $decoded = json_decode((string) file_get_contents($this->directory . '/out/' . $name), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testOutIsRequired(): void
    {
        $tester = new CommandTester(new RunCommand(StandardWorld::build()->httpHandler(), $this->directory));
        $tester->execute([]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('--out', $tester->getDisplay());
    }

    public function testWritesTheResultFilesAndReportsASummary(): void
    {
        $tester = $this->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFileExists($this->directory . '/out/result_routers.json');
        $this->assertFileExists($this->directory . '/out/result_metacommunities.json');
        $this->assertCount(34, $this->readOut('result_routers.json'), 'reference world without the fixed communities, nothing skipped');

        $display = $tester->getDisplay();
        $this->assertStringContainsString('nodes', $display);
        $this->assertStringContainsString('requests', $display);
        $this->assertStringContainsString('maps without data', $display);
    }

    public function testImplausiblySmallResultIsNotWritten(): void
    {
        $tester = $this->execute(['--min-nodes' => '1000', '--min-communities' => '10']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('implausible', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->directory . '/out');
    }

    public function testForceWritesAnImplausiblySmallResult(): void
    {
        $tester = $this->execute(['--min-nodes' => '1000', '--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFileExists($this->directory . '/out/result_routers.json');
    }

    public function testSkippedCommunitiesFromTheConfigFileAndTheOption(): void
    {
        file_put_contents($this->directory . '/config/skipped-communities.json', '{"flat-town": "too slow"}');

        $this->execute(['--skip' => 'hop-town, dup-a']);
        $communities = $this->readOut('result_statistics.json')['errorCommunities'];

        foreach (['flat-town', 'hop-town', 'dup-a'] as $key) {
            $this->assertSame('skipped', $communities[$key]['outcome'], $key);
        }

        $this->assertSame('ok', $communities['mv-city']['outcome']);
    }

    public function testOnlyRunsOneCommunity(): void
    {
        $this->execute(['--only' => 'flat-town']);

        $this->assertSame(['flat-town'], array_keys($this->readOut('result_communities.json')));
    }

    public function testASecondRunUsesWhatTheFirstOneLearned(): void
    {
        $this->execute([]);
        $first = $this->readOut('result_statistics.json')['run'];
        $this->assertFileExists($this->directory . '/cache/sources.json');

        $this->execute([]);
        $second = $this->readOut('result_statistics.json')['run'];

        $this->assertLessThan($first['requests'], $second['requests']);
        $this->assertSame($first['nodes'], $second['nodes']);
    }

    public function testMetaCommunityOverrideFileIsUsed(): void
    {
        file_put_contents($this->directory . '/config/metacommunities.json', '{"Meta Nord": {"url": "https://meta-nord.example/"}}');

        $this->execute([]);

        $this->assertSame(['Meta Nord' => ['url' => 'https://meta-nord.example/']], $this->readOut('result_metacommunities.json'));
    }

    public function testCompareShowsWhatDiffersBetweenTwoResultDirectories(): void
    {
        $this->execute(['--out' => $this->directory . '/all']);
        $this->execute(['--out' => $this->directory . '/flat', '--only' => 'flat-town']);

        $tester = new CommandTester(new CompareCommand());
        $tester->execute(['old' => $this->directory . '/all', 'new' => $this->directory . '/flat']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('only old', $display);
        $this->assertStringContainsString('Nodes only in the old result', $display);
        $this->assertStringContainsString('nl-city', $display);
        $this->assertStringNotContainsString('Nodes only in the new result', $display);
    }

    public function testCompareFailsClearlyWithoutResultFiles(): void
    {
        $tester = new CommandTester(new CompareCommand());
        $tester->execute(['old' => $this->directory . '/nope', 'new' => $this->directory . '/nope']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No readable result_routers.json', $tester->getDisplay());
    }
}
