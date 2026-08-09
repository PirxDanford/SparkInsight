<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use RuntimeException;
use SparkInsight\Service\BookPackageBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class BuildBookPackageCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('book:package')
            ->setDescription('Build a signed book import package from a Scrivener project directory.')
            ->addArgument('package-path', InputArgument::OPTIONAL, 'Output ZIP path or output directory for auto naming')
            ->addOption('source-root', null, InputOption::VALUE_REQUIRED, 'Scrivener project directory to package', __DIR__ . '/../../scrivener')
            ->addOption('private-key', null, InputOption::VALUE_REQUIRED, 'Path to the Ed25519 private key')
            ->addOption('book-title', null, InputOption::VALUE_REQUIRED, 'Book title to embed in the import manifest', 'Enterprise Community Management');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $packagePathArg = (string) ($input->getArgument('package-path') ?? '');
        $sourceRoot = (string) $input->getOption('source-root');
        $privateKeyPath = (string) $input->getOption('private-key');
        $bookTitle = (string) $input->getOption('book-title');

        if ($privateKeyPath === '') {
            $io->error('The --private-key option is required.');

            return Command::FAILURE;
        }

        $packagePath = $this->resolvePackagePath($packagePathArg);

        try {
            $builder = new BookPackageBuilder();
            $result = $builder->buildBookPackage($packagePath, $sourceRoot, $privateKeyPath, $bookTitle);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Book package built successfully: ' . $result['package_path']);
        $io->text('Manifest: ' . $result['manifest_path']);
        $io->text('Signature: ' . $result['signature_path']);

        return Command::SUCCESS;
    }

    private function resolvePackagePath(string $packagePathArg): string
    {
        $trimmed = mb_trim($packagePathArg);
        if ($trimmed === '') {
            $outputDirectory = __DIR__ . '/../../build';
            if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0o700, true) && !is_dir($outputDirectory)) {
                throw new RuntimeException('Could not create output directory: ' . $outputDirectory);
            }

            return $outputDirectory . DIRECTORY_SEPARATOR . 'book-package-' . date('Ymd-His') . '.zip';
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
        if (is_dir($normalized)) {
            return $normalized . DIRECTORY_SEPARATOR . 'book-package-' . date('Ymd-His') . '.zip';
        }

        return $normalized;
    }
}
