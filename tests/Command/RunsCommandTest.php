<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Command;

use DateTimeImmutable;
use FreifunkKarte\Command\RunsCommand;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Tests\Run\RunFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RunsCommandTest extends TestCase
{
    private string $directory;
    private string $db;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffmap_runs_command_' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->db = $this->directory . '/parser.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    private function fill(): void
    {
        $repository = RunRepository::open($this->db);
        $at = new DateTimeImmutable('2026-10-29T12:00:00Z');

        foreach ([1, 2] as $ignored) {
            $runId = $repository->start($at, '/srv/out');
            $repository->finish($runId, RunFixtures::standard(), RunRepository::OK, $at);
        }

        $repository->addEvents(2, [
            new DriftEvent(DriftEvent::NEW_TYPE, DriftEvent::WARNING, 'yanic', 'Technical type "yanic" has not been seen before'),
            new DriftEvent(DriftEvent::NEW_WARNING, DriftEvent::INFO, 'a', 'a small note'),
        ], $at);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function run_(array $input): CommandTester
    {
        $tester = new CommandTester(new RunsCommand());
        $tester->execute($input + ['--db' => $this->db]);

        return $tester;
    }

    public function testWithoutAHistoryItSaysSo(): void
    {
        $tester = $this->run_([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No history', $tester->getDisplay());
    }

    public function testListOfRuns(): void
    {
        $this->fill();

        $display = $this->run_([])->getDisplay();

        $this->assertStringContainsString('2026-10-29 12:00', $display);
        $this->assertStringContainsString('0 / 1 / 1', $display, 'alerts / warnings / notes of run 2');
        $this->assertSame(2, preg_match_all('/^\s+[12]\s+2026-10-29 12:00/m', $display), 'one row per run');
    }

    public function testOneRunInDetail(): void
    {
        $this->fill();

        $tester = $this->run_(['run' => '2']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Run 2: ok', $display);
        $this->assertStringContainsString('/srv/out', $display);
        $this->assertStringContainsString('Technical type "yanic" has not been seen before', $display);
        $this->assertStringContainsString('Maps without data (', $display);
        $this->assertStringContainsString('dead-town', $display);
        $this->assertStringContainsString('Slowest requests', $display);
    }

    public function testUnknownRun(): void
    {
        $this->fill();

        $this->assertSame(Command::FAILURE, $this->run_(['run' => '99'])->getStatusCode());
    }

    public function testEventsOfAllRunsFromWarningUp(): void
    {
        $this->fill();

        $display = $this->run_(['--events' => true])->getDisplay();

        $this->assertStringContainsString('new-technical-type', $display);
        $this->assertStringNotContainsString('a small note', $display);
    }

    public function testHistoryOfOneCommunity(): void
    {
        $this->fill();

        $tester = $this->run_(['--community' => 'nl-city']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('nodelist', $tester->getDisplay());
        $this->assertSame(Command::FAILURE, $this->run_(['--community' => 'nope'])->getStatusCode());
    }
}
