<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'upload:secret',
    description: 'Make a new secret for the upload: it goes into config/upload.local.json here and into upload.config.php on the web server',
)]
final class UploadSecretCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->writeln(bin2hex(random_bytes(32)));
        $io->writeln('<comment>Both sides need the same value. Keep it out of the repository; to rotate it, change both.</comment>');

        return Command::SUCCESS;
    }
}
