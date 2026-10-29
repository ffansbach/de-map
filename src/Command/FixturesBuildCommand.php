<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Audit\SampleTrimmer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'fixtures:build',
    description: 'Build small anonymised test fixtures from the raw responses saved by sources:audit --raw-dir',
)]
final class FixturesBuildCommand extends Command
{
    private const FORMATS = [Format::NodeInfo, Format::MeshviewerFlat, Format::Nodelist];

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'directory with the *.raw files (never commit these)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'target directory', 'tests/fixtures/sources');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = $input->getOption('from');
        $to = $input->getOption('to');

        if (!is_string($from) || !is_dir($from) || !is_string($to)) {
            $io->error('--from must point to an existing directory');

            return Command::INVALID;
        }

        if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
            $io->error('Cannot create ' . $to);

            return Command::FAILURE;
        }

        $trimmer = new SampleTrimmer();

        foreach (self::FORMATS as $format) {
            $source = $this->largestSample($from, $format);

            if ($source === null) {
                $io->warning('No raw sample for ' . $format->value);
                continue;
            }

            $target = sprintf('%s/%s.json', $to, $format->value);
            file_put_contents($target, $trimmer->trim((string) file_get_contents($source), $format));
            $io->writeln(sprintf('%s (%d bytes)', $target, (int) filesize($target)));
        }

        return Command::SUCCESS;
    }

    /**
     * The largest sample has the most variety of nodes to choose from.
     */
    private function largestSample(string $directory, Format $format): ?string
    {
        $files = glob(sprintf('%s/%s-*.raw', $directory, $format->value)) ?: [];

        usort($files, static fn (string $a, string $b): int => filesize($b) <=> filesize($a));

        return $files[0] ?? null;
    }
}
