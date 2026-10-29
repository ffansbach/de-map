<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'admin:password',
    description: 'Make the login for the admin UI: asks for a password and prints what goes into config/admin.local.json',
)]
final class AdminPasswordCommand extends Command
{
    private const MIN_LENGTH = 12;

    protected function configure(): void
    {
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'user name', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $password = $io->askHidden('Password (at least ' . self::MIN_LENGTH . ' characters)');
        $again = $io->askHidden('Again');

        if (!is_string($password) || mb_strlen($password) < self::MIN_LENGTH) {
            $io->error('The password needs at least ' . self::MIN_LENGTH . ' characters.');

            return Command::FAILURE;
        }

        if ($password !== $again) {
            $io->error('The two passwords differ.');

            return Command::FAILURE;
        }

        $io->writeln('Put this into <info>config/admin.local.json</info> (see config/admin.example.json):');
        $io->writeln((string) json_encode(
            ['user' => (string) $input->getOption('user'), 'passwordHash' => password_hash($password, PASSWORD_DEFAULT)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return Command::SUCCESS;
    }
}
