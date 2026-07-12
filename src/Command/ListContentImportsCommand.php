<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Exception;
use SparkInsight\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class ListContentImportsCommand extends Command
{
    private ?Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this->setName('content:list-imports')
            ->setDescription('List import batches with IDs for targeted purge operations')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of imports to list', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->connection === null) {
            $config = Config::fromEnvironment();

            try {
                $this->connection = DriverManager::getConnection($config->getDatabaseConfig());
            } catch (Exception $e) {
                $io->error('Cannot connect to database: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        $limit = max(1, min(1000, (int) $input->getOption('limit')));

        try {
            $sql = "SELECT
                    import_batch_id AS import_id,
                    COUNT(*) AS item_count,
                    MIN(imported_at) AS started_at,
                    MAX(imported_at) AS finished_at,
                    MAX(COALESCE(NULLIF(book_title, ''), '-')) AS book_title
                FROM content_versions
                WHERE import_batch_id IS NOT NULL AND import_batch_id != ''
                GROUP BY import_id
                ORDER BY MAX(imported_at) DESC
                LIMIT " . $limit;

            $rows = $this->connection->executeQuery(
                $sql,
            )->fetchAllAssociative();
        } catch (Throwable $e) {
            $io->error('Failed to list imported content: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($rows === []) {
            $io->success('No imported content found.');

            return Command::SUCCESS;
        }

        $tableRows = array_map(static fn (array $row): array => [
            (string) ($row['import_id'] ?? ''),
            (string) ($row['item_count'] ?? '0'),
            (string) (($row['book_title'] ?? '') !== '' ? $row['book_title'] : '-'),
            (string) ($row['started_at'] ?? ''),
            (string) ($row['finished_at'] ?? ''),
        ], $rows);

        $io->section('Import batches');
        $io->table(['Import ID', 'Items', 'Book', 'Started At', 'Finished At'], $tableRows);
        $io->text(sprintf('Listed %d import(s).', count($rows)));
        $io->text('Use IDs with: php si.php content:purge-imports --force --id=<id>');

        return Command::SUCCESS;
    }
}
