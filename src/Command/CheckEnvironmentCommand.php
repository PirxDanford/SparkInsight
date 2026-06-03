<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use SparkInsight\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class CheckEnvironmentCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('check-environment')
             ->setDescription('Check if the development environment is set up properly');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('SparkInsight Environment Check');

        $config = Config::fromEnvironment();

        // Check .env file
        $envFile = __DIR__ . '/../../.env';
        if (file_exists($envFile)) {
            $io->success('.env file exists');
        } else {
            $io->warning('.env file not found. Copy .env.example to .env and configure it.');
        }

        // Check app environment
        $appEnv = $config->get('app_env');
        $io->info("App environment: {$appEnv}");

        // Check database connection
        $dbConfig = $config->getDatabaseConfig();
        try {
            $conn = DriverManager::getConnection($dbConfig);
            $conn->executeQuery('SELECT 1');
            $io->success('Database connection successful');
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            if (($dbConfig['driver'] ?? '') !== 'pdo_sqlite' && (stripos($errorMessage, 'unknown database') !== false || stripos($errorMessage, 'database') !== false)) {
                // Database doesn't exist, check if user can create it
                try {
                    $connectionParamsWithoutDb = $dbConfig;
                    unset($connectionParamsWithoutDb['dbname']);
                    $conn = DriverManager::getConnection($connectionParamsWithoutDb);
                    $conn->executeStatement("CREATE DATABASE `{$dbConfig['dbname']}`");
                    $conn->executeStatement("DROP DATABASE `{$dbConfig['dbname']}`");
                    $io->warning("Database '{$dbConfig['dbname']}' does not exist, but user has create rights.");
                } catch (\Exception $createException) {
                    $io->error('Database connection failed: ' . $errorMessage . ' User does not have create rights: ' . $createException->getMessage());
                    return Command::FAILURE;
                }
            } else {
                $io->error('Database connection failed: ' . $errorMessage);
                return Command::FAILURE;
            }
        }

        // Check OAuth providers
        $activeProviders = $config->getActiveProviders();
        if (empty($activeProviders)) {
            $io->warning('No OAuth providers configured. Set OAUTH_* environment variables.');
        } else {
            $io->success('OAuth providers configured: ' . implode(', ', array_keys($activeProviders)));
        }

        $io->success('Environment check completed successfully');

        return Command::SUCCESS;
    }
}