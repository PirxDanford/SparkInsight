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

final class BootstrapAdminCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('user:bootstrap-admin')
            ->setDescription('Promote an existing user to the initial admin role set')
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, 'Email address of the existing user to promote')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Allow promotion even if an admin already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_trim((string) $input->getOption('email'));

        if ($email === '') {
            $io->error('The --email option is required.');

            return Command::FAILURE;
        }

        $config = Config::fromEnvironment();

        try {
            $connection = DriverManager::getConnection($config->getDatabaseConfig());
        } catch (Exception $e) {
            $io->error('Database connection failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        try {
            $rows = $connection->executeQuery(
                'SELECT id, email, name, roles, status FROM users ORDER BY id ASC',
            )->fetchAllAssociative();
        } catch (Throwable $e) {
            $io->error('Failed to query users: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $targetUser = null;
        $adminCount = 0;

        foreach ($rows as $row) {
            $roles = json_decode((string) ($row['roles'] ?? '[]'), true);
            if (!is_array($roles)) {
                $roles = [];
            }

            if (in_array('admin', $roles, true)) {
                $adminCount++;
            }

            if (strcasecmp((string) ($row['email'] ?? ''), $email) === 0) {
                $targetUser = [
                    'id' => (int) $row['id'],
                    'email' => (string) $row['email'],
                    'name' => (string) ($row['name'] ?? ''),
                    'roles' => $roles,
                    'status' => (string) ($row['status'] ?? ''),
                ];
            }
        }

        if ($adminCount > 0 && !$input->getOption('force')) {
            $io->error('An admin user already exists. Use --force only if you intentionally want to promote another account.');

            return Command::FAILURE;
        }

        if ($targetUser === null) {
            $io->error(sprintf('No user found with email %s.', $email));

            return Command::FAILURE;
        }

        $newRoles = array_values(array_unique(array_merge(['admin', 'author', 'reviewer'], $targetUser['roles'])));
        $now = date('Y-m-d H:i:s');

        try {
            $connection->executeStatement(
                'UPDATE users SET roles = ?, status = ?, updated_at = ? WHERE id = ?',
                [json_encode($newRoles), 'active', $now, $targetUser['id']],
            );
        } catch (Throwable $e) {
            $io->error('Failed to promote user: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Admin bootstrap completed successfully.');
        $io->text(sprintf('User: %s <%s>', $targetUser['name'] !== '' ? $targetUser['name'] : 'n/a', $targetUser['email']));
        $io->text('Roles: ' . implode(', ', $newRoles));
        $io->text('Status: active');

        return Command::SUCCESS;
    }
}
