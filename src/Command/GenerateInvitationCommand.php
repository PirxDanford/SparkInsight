<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use SparkInsight\Config\Config;
use SparkInsight\Service\AppSettingsService;
use SparkInsight\Service\InvitationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class GenerateInvitationCommand extends Command
{
    private ?Connection $connection;

    /**
     * Constructor supporting dependency injection for testing.
     * 
     * @param Connection|null $connection Optional database connection (for testing; if null, creates from environment)
     */
    public function __construct(?Connection $connection = null)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this->setName('invite:generate')
             ->setDescription('Generate an invitation link for signing up')
             ->addOption('admin', 'a', InputOption::VALUE_NONE, 'Make user admin and author')
             ->addOption('reviewer', 'r', InputOption::VALUE_NONE, 'Make user reviewer (default if no role specified)')
             ->addOption('author', null, InputOption::VALUE_NONE, 'Make user author')
             ->addOption('email', 'e', InputOption::VALUE_OPTIONAL, 'Email to restrict invitation to (optional)')
             ->addOption('hours', null, InputOption::VALUE_OPTIONAL, 'Hours until expiration (defaults to admin settings value)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Use injected connection if available (for testing), otherwise create from environment
        $config = Config::fromEnvironment();
        if ($this->connection === null) {
            $dbConfig = $config->getDatabaseConfig();

            try {
                $this->connection = DriverManager::getConnection([
                    'driver' => $dbConfig['driver'],
                    'host' => $dbConfig['host'],
                    'port' => $dbConfig['port'],
                    'dbname' => $dbConfig['dbname'],
                    'user' => $dbConfig['user'],
                    'password' => $dbConfig['password'],
                    'charset' => $dbConfig['charset'],
                ]);
            } catch (\Exception $e) {
                $io->error('Database connection failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Determine roles
        $roles = [];
        if ($input->getOption('admin')) {
            $roles = ['admin', 'author', 'reviewer'];
        } elseif ($input->getOption('author')) {
            $roles = ['author', 'reviewer'];
        } elseif ($input->getOption('reviewer')) {
            $roles = ['reviewer'];
        } else {
            $roles = ['reviewer']; // default
        }

        $invitationService = new InvitationService($this->connection);
        $settingsService = new AppSettingsService($this->connection);
        $email = $input->getOption('email');
        $hoursInput = $input->getOption('hours');
        $hours = $hoursInput !== null ? (int) $hoursInput : $settingsService->getInvitationDefaultHours();
        if ($hours < 1) {
            $hours = $settingsService->getInvitationDefaultHours();
        }

        try {
            $code = $invitationService->generateInvitation($roles, $email, $hours);
            $appUrl = $config->get('app_url');

            $io->success('Invitation generated successfully!');
            $io->info('');
            $io->info('Invitation Details:');
            $io->info('  Code: ' . $code);
            $io->info('  Roles: ' . implode(', ', $roles));
            $io->info('  Expires in: ' . $hours . ' hours');
            if ($email) {
                $io->info('  Restricted to: ' . $email);
            }

            $io->info('');
            $io->section('Sign-in link for each provider:');
            $io->info('GitHub:  ' . $appUrl . '/auth/github?code=' . $code);
            $io->info('Google:  ' . $appUrl . '/auth/google?code=' . $code);
            $io->info('LinkedIn:' . $appUrl . '/auth/linkedin?code=' . $code);
            $io->info('');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to generate invitation: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}