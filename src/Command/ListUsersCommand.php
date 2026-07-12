<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use Doctrine\DBAL\DriverManager;
use Exception;
use SparkInsight\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class ListUsersCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('user:list')
            ->setDescription('List users by role')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Filter by role (author, reviewer, admin)', null)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include disabled users in the listing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = Config::fromEnvironment();

        try {
            $connection = DriverManager::getConnection($config->getDatabaseConfig());
        } catch (Exception $e) {
            $io->error('Database connection failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        try {
            $rows = $connection->executeQuery(
                'SELECT id, provider, name, email, roles, status FROM users ORDER BY id ASC',
            )->fetchAllAssociative();
        } catch (Throwable $e) {
            $io->error('Failed to query users: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $includeDisabled = (bool) $input->getOption('all');
        $roleFilter = $input->getOption('role');
        if ($roleFilter !== null) {
            $roleFilter = mb_strtolower(mb_trim((string) $roleFilter));
            if (!in_array($roleFilter, ['author', 'reviewer', 'admin'], true)) {
                $io->error('Invalid role filter. Use author, reviewer, or admin.');

                return Command::FAILURE;
            }
        }

        $users = [];

        foreach ($rows as $row) {
            $roles = json_decode((string) ($row['roles'] ?? '[]'), true);
            if (!is_array($roles)) {
                continue;
            }

            if ($roleFilter !== null && !in_array($roleFilter, $roles, true)) {
                continue;
            }

            if (!$includeDisabled && ($row['status'] ?? '') !== 'active') {
                continue;
            }

            $users[] = [
                'id' => (int) $row['id'],
                'provider' => (string) ($row['provider'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'roles' => implode(', ', $roles),
            ];
        }

        $io->title('Users');
        $io->text($roleFilter !== null
            ? sprintf('Showing users with role: %s.', $roleFilter)
            : 'Showing users with any role.');
        $io->text($includeDisabled ? 'Including disabled accounts.' : 'Showing active users only.');

        if ($users === []) {
            $io->warning('No users found.');

            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Provider', 'Name', 'Email', 'Roles', 'Status'],
            array_map(static fn (array $user): array => [
                $user['id'],
                $user['provider'],
                $user['name'],
                $user['email'],
                $user['roles'],
                $user['status'],
            ], $users),
        );

        $io->success(sprintf('%d user(s) found.', count($users)));

        return Command::SUCCESS;
    }
}
