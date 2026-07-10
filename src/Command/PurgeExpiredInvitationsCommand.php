<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use SparkInsight\Config\Config;
use SparkInsight\Service\InvitationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PurgeExpiredInvitationsCommand extends Command
{
    private ?Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this->setName('invite:purge-expired')
            ->setDescription('Delete expired and unused invitations')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Actually delete expired invitations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')) {
            $io->error('Refusing to delete invitations without --force.');

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

        $invitationService = new InvitationService($this->connection);

        try {
            $deleted = $invitationService->purgeExpiredInvitations();
        } catch (\Throwable $e) {
            $io->error('Failed to purge expired invitations: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Purged %d expired invitation(s).', $deleted));

        return Command::SUCCESS;
    }
}
