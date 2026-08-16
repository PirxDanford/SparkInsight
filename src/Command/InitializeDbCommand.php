<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use Exception;
use SparkInsight\Config\Config;
use SparkInsight\Service\MigrationRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class InitializeDbCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('db:init')
            ->setDescription('Initialize the database by running all migrations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $config = Config::fromEnvironment();
        $dbConfig = $config->getDatabaseConfig();

        // Check if database exists
        try {
            $connectionParams = ($dbConfig['driver'] ?? '') === 'pdo_sqlite'
                ? $dbConfig
                : [
                    'driver' => $dbConfig['driver'],
                    'host' => $dbConfig['host'] ?? 'localhost',
                    'port' => $dbConfig['port'] ?? null,
                    'dbname' => $dbConfig['dbname'] ?? null,
                    'user' => $dbConfig['user'] ?? null,
                    'password' => $dbConfig['password'] ?? null,
                    'charset' => $dbConfig['charset'] ?? null,
                ];
            $conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParams);
            $conn->executeQuery('SELECT 1');
        } catch (Exception $e) {
            if (mb_stripos($e->getMessage(), 'unknown database') !== false) {
                // Create the database
                $connectionParamsWithoutDb = $connectionParams;
                unset($connectionParamsWithoutDb['dbname']);
                $conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParamsWithoutDb);
                $conn->executeStatement("CREATE DATABASE `{$dbConfig['dbname']}`");
                $io->info("Created database '{$dbConfig['dbname']}'");
            } else {
                $io->error('Cannot connect to database: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        // Load migrations config
        $connection = \Doctrine\DBAL\DriverManager::getConnection($dbConfig);

        $migrationRunner = new MigrationRunner($connection);
        $currentVersion = $migrationRunner->getCurrentVersion();

        if ($currentVersion > 0) {
            $io->info('Database already initialized (version ' . $currentVersion . '). Checking for new migrations...');
        } else {
            $io->info('Database not initialized. Running migrations...');
        }

        $executedMigrations = $migrationRunner->runMigrations(__DIR__ . '/../../database/migrations');

        if (empty($executedMigrations)) {
            $io->info('No migrations to run.');
        } else {
            $io->success('Executed migrations: ' . implode(', ', $executedMigrations));
        }

        return Command::SUCCESS;
    }
}
