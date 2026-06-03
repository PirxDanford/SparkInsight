<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use SparkInsight\Config\Config;
use SparkInsight\Service\MigrationRunner;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class MigrateDbCommand extends Command
{
    private string $migrationsDir;

    public function __construct(?string $migrationsDir = null)
    {
        parent::__construct();

        $this->migrationsDir = $migrationsDir ?? __DIR__ . '/../../database/migrations';
    }

    protected function configure(): void
    {
        $this->setName('db:migrate')
            ->setDescription('Run database migrations or display migration status.')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show current schema version and pending migrations')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Rollback the last applied migration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status') && $input->getOption('rollback')) {
            $io->error('Use only one of --status or --rollback.');
            return Command::FAILURE;
        }

        $config = Config::fromEnvironment();

        try {
            $connection = DriverManager::getConnection($config->getDatabaseConfig());
        } catch (\Exception $e) {
            $io->error('Cannot connect to database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $runner = new MigrationRunner($connection);

        if ($input->getOption('status')) {
            $currentVersion = $runner->getCurrentVersion();
            $pending = $runner->getPendingMigrations($this->migrationsDir);

            $io->section('Database migration status');
            $io->text('Current schema version: ' . $currentVersion);

            if (empty($pending)) {
                $io->success('No pending migrations.');
            } else {
                $io->writeln('Pending migrations:');
                foreach ($pending as $migration) {
                    $io->text('- ' . $migration);
                }
            }

            return Command::SUCCESS;
        }

        if ($input->getOption('rollback')) {
            try {
                $rolledBack = $runner->rollbackLastMigration($this->migrationsDir);
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }

            if ($rolledBack === null) {
                $io->info('No migration to rollback.');
                return Command::SUCCESS;
            }

            $io->success('Rolled back migration: ' . $rolledBack);
            return Command::SUCCESS;
        }

        $executedMigrations = $runner->runMigrations($this->migrationsDir);

        if (empty($executedMigrations)) {
            $io->info('No migrations to run.');
        } else {
            $io->success('Executed migrations: ' . implode(', ', $executedMigrations));
        }

        return Command::SUCCESS;
    }
}
