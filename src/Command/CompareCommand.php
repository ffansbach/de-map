<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use FreifunkKarte\Output\ResultComparer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'compare',
    description: 'Compare two directories of result files (for example two runs) by what is on the map',
)]
final class CompareCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('old', InputArgument::REQUIRED, 'the older directory, with result_routers.json and result_communities.json')
            ->addArgument('new', InputArgument::REQUIRED, 'directory with the new result files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $old = $this->load((string) $input->getArgument('old'), $io);
        $new = $this->load((string) $input->getArgument('new'), $io);

        if ($old === null || $new === null) {
            return Command::FAILURE;
        }

        $diff = (new ResultComparer())->compare($old['routers'], $new['routers'], $old['communities'], $new['communities']);

        $io->definitionList(
            ['nodes old' => $diff['oldNodes']],
            ['nodes new' => $diff['newNodes']],
            ['on both' => $diff['common']],
            ['only old' => array_sum($diff['onlyOld'])],
            ['only new' => array_sum($diff['onlyNew'])],
        );

        $this->printCounts($io, 'Nodes only in the old result, per community', $diff['onlyOld']);
        $this->printCounts($io, 'Nodes only in the new result, per community', $diff['onlyNew']);
        $this->printList($io, 'Communities only in the old result', $diff['communitiesOnlyOld']);
        $this->printList($io, 'Communities only in the new result', $diff['communitiesOnlyNew']);
        $this->printList($io, 'Communities listed differently (name, url or meta)', $diff['communitiesChanged']);

        if ($io->isVerbose()) {
            $this->printList($io, 'Examples only in old', $diff['samplesOnlyOld']);
            $this->printList($io, 'Examples only in new', $diff['samplesOnlyNew']);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{routers: list<array<string, mixed>>, communities: array<string, array<string, mixed>>}|null
     */
    private function load(string $directory, SymfonyStyle $io): ?array
    {
        $routers = json_decode((string) @file_get_contents($directory . '/result_routers.json'), true);
        $communities = json_decode((string) @file_get_contents($directory . '/result_communities.json'), true);

        if (!is_array($routers) || !is_array($communities)) {
            $io->error('No readable result_routers.json / result_communities.json in ' . $directory);

            return null;
        }

        /** @var list<array<string, mixed>> $routers */
        /** @var array<string, array<string, mixed>> $communities */
        return ['routers' => $routers, 'communities' => $communities];
    }

    /**
     * @param array<string, int> $counts
     */
    private function printCounts(SymfonyStyle $io, string $title, array $counts): void
    {
        if ($counts === []) {
            return;
        }

        $io->section($title);
        $io->table(['community', 'nodes'], array_map(static fn (string $c, int $n): array => [$c, $n], array_keys($counts), $counts));
    }

    /**
     * @param list<string> $items
     */
    private function printList(SymfonyStyle $io, string $title, array $items): void
    {
        if ($items !== []) {
            $io->section($title);
            $io->listing($items);
        }
    }
}
