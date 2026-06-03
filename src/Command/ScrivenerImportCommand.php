<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use Doctrine\DBAL\DriverManager;
use SparkInsight\Config\Config;
use SparkInsight\Service\ContentImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ScrivenerImportCommand extends Command
{
    private string $defaultDirectory;

    public function __construct(?string $defaultDirectory = null)
    {
        $this->defaultDirectory = $defaultDirectory ?? __DIR__ . '/../../scrivener';
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('content:import-scrivener')
            ->setDescription('Import Scrivener FDX files from a synced directory')
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Directory containing Scrivener FDX exports', $this->defaultDirectory)
            ->addOption('author-id', null, InputOption::VALUE_REQUIRED, 'Author ID to assign to imported content versions', '1')
            ->addOption('label-prefix', null, InputOption::VALUE_REQUIRED, 'Optional prefix for generated version labels', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate FDX files without inserting them into the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = (string) $input->getOption('directory');
        $authorId = (int) $input->getOption('author-id');
        $labelPrefix = $input->getOption('label-prefix') !== null ? (string) $input->getOption('label-prefix') : null;
        $dryRun = $input->getOption('dry-run');

        $config = Config::fromEnvironment();

        try {
            $connection = DriverManager::getConnection($config->getDatabaseConfig());
        } catch (\Exception $e) {
            $io->error('Cannot connect to database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $service = new ContentImportService($connection);

        try {
            $result = $service->importFdxDirectory($directory, $authorId, $labelPrefix, $dryRun);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->section($dryRun ? 'Scrivener FDX dry-run validation' : 'Scrivener FDX import result');
        $io->text('Directory: ' . $directory);
        $io->text('Scanned files: ' . $result['scanned']);
        $io->text('Imported files: ' . count($result['imported']));
        $io->text('Failed files: ' . count($result['failed']));

        if (!empty($result['imported'])) {
            $io->section('Imported files');
            foreach ($result['imported'] as $path => $info) {
                $io->text(sprintf('- %s (%s)', $path, $info['version_label']));
            }
        }

        if (!empty($result['failed'])) {
            $io->section('Failed files');
            foreach ($result['failed'] as $path => $errors) {
                $io->writeln(sprintf('- %s', $path));
                foreach ($errors as $error) {
                    $io->text('  * ' . $error);
                }
            }

            return Command::FAILURE;
        }

        if ($result['scanned'] === 0) {
            $io->warning('No Scrivener FDX files were found in the directory.');
            return Command::SUCCESS;
        }

        $io->success($dryRun ? 'Dry-run validation completed successfully.' : 'Scrivener FDX import completed successfully.');

        return Command::SUCCESS;
    }
}
