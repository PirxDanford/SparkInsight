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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Actually delete the imported content')
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Specific import batch ID(s) to purge');
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
            } catch (Exception $e) {
                $io->error('Cannot connect to database: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        $rawIds = $input->getOption('id');
        $targetIds = $this->normalizeTargetIds(is_array($rawIds) ? $rawIds : []);
        $hasTargetIds = $targetIds !== [];

        try {
            if ($hasTargetIds) {
                [$whereSql, $params] = $this->buildBatchWhereClause($targetIds);
                $count = (int) $this->connection->executeQuery(
                    'SELECT COUNT(*) FROM content_versions WHERE ' . $whereSql,
                    $params,
                )->fetchOne();
            } else {
                $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM content_versions');
            }
        } catch (Throwable $e) {
            $io->error('Failed to inspect imported content: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($count === 0) {
            $io->success('No imported content found to delete.');

            return Command::SUCCESS;
        }

        $this->connection->beginTransaction();

        try {
            if ($hasTargetIds) {
                [$whereSql, $params] = $this->buildBatchWhereClause($targetIds);

                $this->connection->executeStatement(
                    'DELETE FROM reviews WHERE content_version_id IN (SELECT id FROM content_versions WHERE ' . $whereSql . ')',
                    $params,
                );
                $this->connection->executeStatement(
                    'DELETE FROM review_assignments WHERE content_version_id IN (SELECT id FROM content_versions WHERE ' . $whereSql . ')',
                    $params,
                );
                $this->connection->executeStatement(
                    'DELETE FROM content_versions WHERE ' . $whereSql,
                    $params,
                );
            } else {
                $this->connection->executeStatement('DELETE FROM reviews');
                $this->connection->executeStatement('DELETE FROM review_assignments');
                $this->connection->executeStatement('DELETE FROM content_versions');
            }
            $this->connection->commit();
        } catch (Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $io->error('Failed to purge imported content: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($hasTargetIds) {
            $io->success(sprintf('Purged %d imported content version(s) from import batch(es): %s', $count, implode(', ', $targetIds)));
        } else {
            $io->success(sprintf('Purged %d imported content version(s).', $count));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<int, mixed> $rawIds
     * @return array<int, string>
     */
    private function normalizeTargetIds(array $rawIds): array
    {
        $ids = [];
        foreach ($rawIds as $rawId) {
            $id = mb_trim((string) $rawId);
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<int, string> $batchIds
     * @return array{0: string, 1: array<int, string>}
     */
    private function buildBatchWhereClause(array $batchIds): array
    {
        if ($batchIds === []) {
            return ['1 = 0', []];
        }

        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));

        return ['import_batch_id IN (' . $placeholders . ')', $batchIds];
    }
}
