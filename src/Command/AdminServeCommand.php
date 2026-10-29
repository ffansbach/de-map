<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use FreifunkKarte\Admin\AdminConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'admin:serve',
    description: 'Serve the admin UI with PHP\'s built-in web server (for a look; use php-fpm for permanent use)',
)]
final class AdminServeCommand extends Command
{
    public function __construct(private readonly ?string $projectDir = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'address to listen on', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'port', '8081')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'print the command instead of running it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = $this->projectDir ?? dirname(__DIR__, 2);
        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');

        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $io->error('The port must be a number between 1 and 65535.');

            return Command::INVALID;
        }

        $loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);

        if (!$loopback && !AdminConfig::fromFile($root . '/config/admin.local.json')->requiresLogin()) {
            $io->error('Listening on ' . $host . ' without a password would show the history to everyone who can reach it. Set a password first: bin/parser admin:password');

            return Command::FAILURE;
        }

        $command = sprintf(
            '%s -S %s -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host . ':' . $port),
            escapeshellarg($root . '/public-admin'),
            escapeshellarg($root . '/public-admin/index.php'),
        );

        if ($input->getOption('dry-run')) {
            $io->writeln($command);

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Admin UI on <info>http://%s:%s/</info> (Ctrl-C stops it)', $host, $port));
        passthru($command, $status);

        return $status;
    }
}
