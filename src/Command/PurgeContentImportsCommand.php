<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use SparkInsight\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PurgeContentImportsCommand extends Command
{
    private ?Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this->setName('content:purge-imports')
            ->setDescription('Delete previously imported content versions and their review data')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Actually delete the imported content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')) {
            $io->error('Refusing to delete imports without --force.');
            return Command::FAILURE;
        }

        if ($this->connection === null) {
            $config = Config::fromEnvironment();

            try {
                $this->connection = DriverManager::getConnection($config->getDatabaseConfig());
            } catch (\Exception $e) {
                $io->error('Cannot connect to database: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        try {
            $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_versions');
        } catch (\Throwable $e) {
            $io->error('Failed to inspect imported content: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($count === 0) {
            $io->success('No imported content found to delete.');
            return Command::SUCCESS;
        }

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('DELETE FROM reviews');
            $this->connection->executeStatement('DELETE FROM review_assignments');
            $this->connection->executeStatement('DELETE FROM content_versions');
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $io->error('Failed to purge imported content: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Purged %d imported content version(s).', $count));

        return Command::SUCCESS;
    }
}