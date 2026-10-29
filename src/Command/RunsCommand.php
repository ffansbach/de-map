<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Run\RunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'runs',
    description: 'Look at the history of runs: the latest runs, one run in detail, recent events, or one community',
)]
final class RunsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('run', InputArgument::OPTIONAL, 'show this run in detail')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'SQLite file with the history of runs', 'var/parser.sqlite')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'how many rows', '20')
            ->addOption('events', null, InputOption::VALUE_NONE, 'list the recent warnings and alerts of all runs')
            ->addOption('community', null, InputOption::VALUE_REQUIRED, 'show how this community did in the last runs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $db = (string) $input->getOption('db');

        if (!is_file($db)) {
            $io->error('No history at ' . $db . ' yet. It is created by `bin/parser run`.');

            return Command::FAILURE;
        }

        $repository = RunRepository::open($db);
        $limit = max(1, (int) $input->getOption('limit'));
        $community = $input->getOption('community');

        return match (true) {
            is_string($community) => $this->community($io, $repository, $community, $limit),
            $input->getOption('events') === true => $this->events($io, $repository, $limit),
            $input->getArgument('run') !== null => $this->showRun($io, $repository, (int) $input->getArgument('run')),
            default => $this->list($io, $repository, $limit),
        };
    }

    private function list(SymfonyStyle $io, RunRepository $repository, int $limit): int
    {
        $rows = [];

        foreach ($repository->runs($limit) as $run) {
            $severities = array_count_values(array_column($repository->events((int) $run['id']), 'severity'));
            $rows[] = [
                $run['id'], $this->time($run['started_at']), $run['status'], $run['nodes'] ?? '-', $run['communities'] ?? '-', $run['with_nodes'] ?? '-', $run['without_data'] ?? '-',
                $run['requests'] ?? '-', isset($run['seconds']) ? sprintf('%.1f', (float) $run['seconds']) : '-',
                ($severities['alert'] ?? 0) . ' / ' . ($severities['warning'] ?? 0) . ' / ' . ($severities['info'] ?? 0),
            ];
        }

        $io->table(['run', 'started', 'status', 'nodes', 'communities', 'with nodes', 'no data', 'requests', 'seconds', 'alerts / warnings / notes'], $rows);

        return Command::SUCCESS;
    }

    private function showRun(SymfonyStyle $io, RunRepository $repository, int $runId): int
    {
        $run = $repository->run($runId);

        if ($run === null) {
            $io->error('No run ' . $runId);

            return Command::FAILURE;
        }

        $io->title(sprintf('Run %d: %s', $runId, $run['status']));
        $io->definitionList(
            ['started' => $this->time($run['started_at'])],
            ['nodes' => $run['nodes'] ?? '-'],
            ['communities listed' => $run['communities'] ?? '-'],
            ['requests' => $run['requests'] ?? '-'],
            ['seconds' => isset($run['seconds']) ? sprintf('%.1f', (float) $run['seconds']) : '-'],
            ['result files in' => $run['out_dir'] ?? '-'],
            ['error' => $run['error'] ?? '-'],
        );

        $events = $repository->events($runId);

        if ($events !== []) {
            $io->section('Events');
            $io->table(['severity', 'kind', 'what'], array_map(static fn (array $e): array => [$e['severity'], $e['kind'], $e['message']], $events));
        }

        $noData = [];

        foreach ($repository->communities($runId) as $community) {
            if ($community->outcome === CommunityReport::NO_DATA) {
                $noData[] = [$community->key, implode(', ', $community->declaredTypes)];
            }
        }

        if ($noData !== []) {
            $io->section(sprintf('Maps without data (%d)', count($noData)));
            $io->table(['community', 'declared types'], $noData);
        }

        $io->section('Slowest requests');
        $io->table(['seconds', 'status', 'url'], array_map(
            static fn (array $f): array => [sprintf('%.2f', (float) $f['seconds']), $f['status'] ?? ($f['error'] ?? '-'), $f['url']],
            array_slice($repository->fetches($runId), 0, 10),
        ));

        return Command::SUCCESS;
    }

    private function events(SymfonyStyle $io, RunRepository $repository, int $limit): int
    {
        $io->table(['run', 'severity', 'kind', 'what'], array_map(
            static fn (array $e): array => [$e['run_id'], $e['severity'], $e['kind'], $e['message']],
            $repository->recentEvents($limit, 'warning'),
        ));

        return Command::SUCCESS;
    }

    private function community(SymfonyStyle $io, RunRepository $repository, string $key, int $limit): int
    {
        $history = $repository->communityHistory($key, $limit);

        if ($history === []) {
            $io->error('No community "' . $key . '" in the history');

            return Command::FAILURE;
        }

        $io->table(['run', 'started', 'outcome', 'format', 'nodes found', 'kept', 'duplicates', 'stale since'], array_map(
            fn (array $h): array => [$h['run_id'], $this->time($h['started_at']), $h['outcome'], $h['format'] ?? '-', $h['found'], $h['added'], $h['duplicates'], $h['stale_since'] ?? '-'],
            $history,
        ));

        return Command::SUCCESS;
    }

    private function time(mixed $iso): string
    {
        return is_string($iso) ? (new \DateTimeImmutable($iso))->format('Y-m-d H:i') : '-';
    }
}
