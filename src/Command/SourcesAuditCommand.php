<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use FreifunkKarte\Audit\FormatDetector;
use FreifunkKarte\Audit\SourceAuditor;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Source\FixedCommunities;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sources:audit',
    description: 'Read-only audit: which node source types and formats are really in use',
)]
final class SourcesAuditCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'write the full report to this file')
            ->addOption('raw-dir', null, InputOption::VALUE_REQUIRED, 'save up to 3 raw responses per detected format here (to build fixtures)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'audit a single community (directory key)')
            ->addOption('no-fixed', null, InputOption::VALUE_NONE, 'skip the communities from fixedCommunities.php')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'parallel requests', '8');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fetcher = new ParallelFetcher(new Client(), max(1, (int) $input->getOption('concurrency')));
        $rawDir = $input->getOption('raw-dir');
        $auditor = new SourceAuditor($fetcher, new FormatDetector(), is_string($rawDir) ? $rawDir : null);

        $only = $input->getOption('only');
        $fixed = $input->getOption('no-fixed') ? [] : FixedCommunities::load(dirname(__DIR__, 2) . '/fixedCommunities.php');

        $report = $auditor->audit(
            $fixed,
            is_string($only) ? $only : null,
            static fn (string $message) => $io->writeln('<comment>' . $message . '</comment>'),
        );

        $this->printSummary($io, $report);

        $json = $input->getOption('json');

        if (is_string($json)) {
            file_put_contents($json, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $io->success('Full report written to ' . $json);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function printSummary(SymfonyStyle $io, array $report): void
    {
        /** @var array<string, mixed> $summary */
        $summary = $report['summary'];
        /** @var array<string, array{entries: int, working: int, nodes: int, located: int, results: array<string, int>}> $byType */
        $byType = $summary['byDeclaredType'];

        $io->section('Declared nodeMap types');
        $rows = [];

        foreach ($byType as $type => $stats) {
            arsort($stats['results']);
            $rows[] = [
                $type,
                $stats['entries'],
                $stats['working'],
                $stats['nodes'],
                $stats['located'],
                implode(', ', array_map(
                    static fn (string $format, int $count): string => $format . ' ×' . $count,
                    array_keys($stats['results']),
                    $stats['results'],
                )),
            ];
        }

        $io->table(['declared type', 'entries', 'working', 'nodes', 'located', 'result per entry'], $rows);

        $io->definitionList(
            ['communities in directory' => $summary['communities']],
            ['API file not loadable' => $summary['apiFileFailed']],
            ['API file without nodeMaps' => $summary['withoutNodeMaps']],
            ['communities with a working source' => $summary['communitiesWithWorkingSource']],
            ['sources with invalid TLS certificate' => count((array) $report['tlsInvalidUrls'])],
        );
    }
}
