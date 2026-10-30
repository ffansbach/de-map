<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Command;

use FreifunkKarte\Command\CompareCommand;
use FreifunkKarte\Command\RunCommand;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Tests\Legacy\StandardWorld;
use FreifunkKarte\Tests\Legacy\World;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
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
    private function execute(array $options, ?World $world = null, ?\Closure $handler = null): CommandTester
    {
        $world ??= StandardWorld::build();
        $command = new RunCommand($handler ?? $world->httpHandler(), $this->directory);
        // the reference world has a directory URL of its own
        $tester = new CommandTester($command);
        $tester->execute($options + [
            '--directory-url' => World::DIRECTORY_URL,
            '--out' => $this->directory . '/out',
            '--cache-dir' => $this->directory . '/cache',
            '--db' => $this->directory . '/state/parser.sqlite',
            '--log-file' => $this->directory . '/state/log/parser.log',
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

    private function history(): RunRepository
    {
        return RunRepository::open($this->directory . '/state/parser.sqlite');
    }

    public function testEveryRunIsRecordedAndComparedWithTheOneBefore(): void
    {
        $this->execute([]);
        $second = $this->execute([], StandardWorld::build()
            ->route('https://hop.test/a/nodes.json', 'not json')
            ->route('https://hop.test/b/nodes.json', 'not json')
            ->community('newcomer', ['nodeMaps' => [['technicalType' => 'yanic', 'url' => 'https://newcomer.test/map/']]]));

        $history = $this->history();
        $this->assertCount(2, $history->runs());
        $this->assertSame(['ok', 'ok'], array_column($history->runs(), 'status'));

        $display = $second->getDisplay();
        $this->assertStringContainsString('To look at', $display);
        $this->assertStringContainsString('Technical type "yanic" has not been seen before', $display);
        $this->assertStringContainsString('"Freifunk hop-town" delivered', $display);
        $this->assertStringNotContainsString('New community', $display, 'notes of low importance stay in the log');

        $events = $history->events(2);
        $this->assertContains('new-technical-type', array_column($events, 'kind'));
    }

    public function testTheLogIsWrittenAsJsonLines(): void
    {
        $this->execute([]);

        $files = glob($this->directory . '/state/log/parser-*.log') ?: [];
        $this->assertCount(1, $files);
        $messages = array_map(static fn (string $line): string => (string) (json_decode($line, true)['message'] ?? ''), array_values(array_filter(explode("\n", (string) file_get_contents($files[0])))));
        $this->assertSame(['run started', 'run finished'], $messages);
    }

    public function testARefusedResultIsInTheHistoryAndTheCommandFails(): void
    {
        $tester = $this->execute(['--min-nodes' => '1000', '--min-communities' => '10']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('ALERT', $tester->getDisplay());
        $this->assertSame(['refused'], array_column($this->history()->runs(), 'status'));
        $this->assertDirectoryDoesNotExist($this->directory . '/out');
    }

    public function testAFailedRunIsInTheHistoryToo(): void
    {
        $broken = $this->execute([], null, static fn () => \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(500)));

        $this->assertSame(Command::FAILURE, $broken->getStatusCode());
        $this->assertStringContainsString('Community directory not available', $broken->getDisplay());
        $runs = $this->history()->runs();
        $this->assertSame('failed', $runs[0]['status']);
        $this->assertStringContainsString('Community directory not available', (string) $runs[0]['error']);
    }

    public function testTheWholeHistoryIsKeptUnlessAskedOtherwise(): void
    {
        $repository = $this->history();
        $old = new \DateTimeImmutable('2020-01-01T12:00:00Z');
        $oldRun = $repository->start($old, '/old');
        $repository->finish($oldRun, \FreifunkKarte\Tests\Run\RunFixtures::standard(), RunRepository::OK, $old);
        unset($repository);

        $this->execute([]);
        $this->assertCount(2, $this->history()->runs(), 'a six year old run is still there');

        $this->execute(['--keep-days' => '30']);
        $runs = $this->history()->runs();
        $this->assertCount(2, $runs, 'the old run is gone, the two recent ones are there');
        $this->assertNotContains(1, array_column($runs, 'id'));
    }

    public function testNoHistoryLeavesNoTraces(): void
    {
        $this->execute(['--no-history' => true]);

        $this->assertFileDoesNotExist($this->directory . '/state/parser.sqlite');
        $this->assertDirectoryDoesNotExist($this->directory . '/state/log');
        $this->assertFileExists($this->directory . '/out/result_routers.json');
    }

    /**
     * @param list<string> $requests
     */
    private function recordingHandler(array &$requests): \Closure
    {
        $inner = StandardWorld::build()->httpHandler();

        return static function (RequestInterface $request, array $options) use ($inner, &$requests) {
            if ($request->getUri()->getHost() === 'hc.test') {
                $requests[] = $request->getMethod() . ' ' . $request->getUri() . ' ' . $request->getBody();
            }

            return $inner($request, $options);
        };
    }

    public function testHealthchecksIsToldWhenTheRunStartsAndHowItEnded(): void
    {
        file_put_contents($this->directory . '/integrations.json', (string) json_encode(['healthchecks' => ['url' => 'https://hc.test/ping/abc']]));
        $requests = [];

        $this->execute(['--integrations' => $this->directory . '/integrations.json'], null, $this->recordingHandler($requests));

        $this->assertCount(2, $requests);
        $this->assertSame('POST https://hc.test/ping/abc/start ', $requests[0]);
        $this->assertStringStartsWith('POST https://hc.test/ping/abc run 1: ok, 34 nodes, 11 communities', $requests[1]);
    }

    public function testHealthchecksFromTheLegacyConfigPhp(): void
    {
        file_put_contents($this->directory . '/config.php', "<?php\n\$healtcheckIdentifier = 'legacy-id';\n");
        $requests = [];
        $inner = StandardWorld::build()->httpHandler();
        $handler = static function (RequestInterface $request, array $options) use ($inner, &$requests) {
            if ($request->getUri()->getHost() === 'hc-ping.com') {
                $requests[] = $request->getMethod() . ' ' . $request->getUri();
            }

            return $inner($request, $options);
        };

        $this->execute(['--legacy-config' => $this->directory . '/config.php'], null, $handler);

        $this->assertSame(['POST https://hc-ping.com/legacy-id/start', 'POST https://hc-ping.com/legacy-id'], $requests);
    }

    public function testFailingHealthchecksDoesNotFailTheRun(): void
    {
        file_put_contents($this->directory . '/integrations.json', (string) json_encode(['healthchecks' => ['url' => 'https://hc.test/ping/abc']]));
        $inner = StandardWorld::build()->httpHandler();
        $handler = static function (RequestInterface $request, array $options) use ($inner) {
            if ($request->getUri()->getHost() === 'hc.test') {
                return \GuzzleHttp\Promise\Create::rejectionFor(new \GuzzleHttp\Exception\ConnectException('no route to host', $request));
            }

            return $inner($request, $options);
        };

        $tester = $this->execute(['--integrations' => $this->directory . '/integrations.json'], null, $handler);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFileExists($this->directory . '/out/result_routers.json');
    }

    private function uploadConfig(): string
    {
        $file = $this->directory . '/upload.json';
        file_put_contents($file, (string) json_encode(['publishers' => [['type' => 'https', 'url' => 'https://up.test/upload.php', 'secret' => str_repeat('k', 32)]], 'statsPingUrl' => 'https://up.test/ping']));

        return $file;
    }

    public function testUploadDeliversTheWrittenFilesAndPingsTheStatistics(): void
    {
        $requests = new \ArrayObject();
        $world = StandardWorld::build()->route('https://up.test/upload.php', '{"ok":true,"routers":34,"communities":9}')->route('https://up.test/ping', 'ok');
        $inner = $world->httpHandler();
        $handler = static function (RequestInterface $request) use ($inner, $requests) {
            if ($request->getUri()->getHost() === 'up.test') {
                $requests->append($request->getMethod() . ' ' . $request->getUri()->getPath());
            }

            return $inner($request);
        };

        $tester = $this->execute(['--upload' => true, '--upload-config' => $this->uploadConfig()], $world, $handler);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['POST /upload.php', 'GET /ping'], $requests->getArrayCopy());
        $this->assertStringContainsString('delivered https up.test', $tester->getDisplay());
    }

    public function testAFailedUploadFailsTheRunAndIsRecordedAsAnAlert(): void
    {
        // no route for the upload: the server answers 404
        $tester = $this->execute(['--upload' => true, '--upload-config' => $this->uploadConfig()]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('not delivered', $tester->getDisplay());
        $this->assertFileExists($this->directory . '/out/result_routers.json', 'the files are there for a later `upload`');

        $repository = RunRepository::open($this->directory . '/state/parser.sqlite');
        $this->assertContains('delivery-failed', array_column($repository->events(1), 'kind'));
    }

    public function testUploadWithoutAnyConfigurationStopsBeforeTheRun(): void
    {
        $tester = $this->execute(['--upload' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('no upload is configured', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->directory . '/out');
    }

    public function testARefusedResultIsNotUploaded(): void
    {
        $world = StandardWorld::build()->route('https://up.test/upload.php', '{"ok":true}');

        $tester = $this->execute(['--upload' => true, '--upload-config' => $this->uploadConfig(), '--min-nodes' => '1000'], $world);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringNotContainsString('delivered', $tester->getDisplay());
    }
}
