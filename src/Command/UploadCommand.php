<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use Closure;
use FreifunkKarte\Publish\DeliveryFactory;
use FreifunkKarte\Publish\ResultFiles;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'upload',
    description: 'Deliver the result files of an earlier run to the web server',
)]
final class UploadCommand extends Command
{
    /**
     * @param Closure|null $httpHandler replaces the network (tests)
     */
    public function __construct(
        private readonly ?Closure $httpHandler = null,
        private readonly ?string $projectDir = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'directory with the result_*.json files')
            ->addOption('config-dir', null, InputOption::VALUE_REQUIRED, 'directory with upload.local.json')
            ->addOption('upload-config', null, InputOption::VALUE_REQUIRED, 'JSON file with the upload settings (default: config/upload.local.json)')
            ->addOption('legacy-config', null, InputOption::VALUE_REQUIRED, 'take the FTP settings from an old-style config.php');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = $input->getOption('from');

        if (!is_string($from) || $from === '') {
            $io->error('--from=DIRECTORY is required');

            return Command::INVALID;
        }

        $configOption = $input->getOption('config-dir');
        $configDir = is_string($configOption) ? $configOption : ($this->projectDir ?? dirname(__DIR__, 2)) . '/config';
        $upload = $input->getOption('upload-config');
        $legacy = $input->getOption('legacy-config');

        try {
            $delivery = DeliveryFactory::create(
                new Client($this->httpHandler !== null ? ['handler' => $this->httpHandler] : []),
                $configDir,
                is_string($upload) ? $upload : null,
                is_string($legacy) ? $legacy : null,
            );

            if (!$delivery->hasPublishers()) {
                $io->error('No upload is configured (see config/upload.example.json)');

                return Command::FAILURE;
            }

            $report = $delivery->deliver(ResultFiles::fromDirectory($from));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($report->results as $result) {
            $io->writeln(sprintf('%s %s: %s (%.1f s)', $result->ok ? '<info>ok</info>   ' : '<error>FAILED</error>', $result->publisher, $result->message, $result->seconds));
        }

        foreach ($report->warnings as $warning) {
            $io->writeln('<comment>WARNING</comment> ' . $warning);
        }

        if (!$report->delivered()) {
            $io->error('Not delivered: ' . $report->error);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
