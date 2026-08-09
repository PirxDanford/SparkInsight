<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use SparkInsight\Service\ReleasePackageBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BuildReleasePackageCommand extends Command
{
    public function __construct(private readonly ?string $defaultOutputDirectory = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('package:build')
            ->setDescription('Build a signed full or patch release package.')
            ->addArgument('package-path', InputArgument::OPTIONAL, 'Output ZIP path or output directory for auto naming')
            ->addOption('private-key', null, InputOption::VALUE_REQUIRED, 'Path to the Ed25519 private key')
            ->addOption('source-root', null, InputOption::VALUE_REQUIRED, 'Source release root', __DIR__ . '/../../')
            ->addOption('base-root', null, InputOption::VALUE_REQUIRED, 'Base release root for patch packages')
            ->addOption('base-package', null, InputOption::VALUE_REQUIRED, 'Base package ZIP or manifest JSON for patch packages')
            ->addOption('from-scratch', null, InputOption::VALUE_NONE, 'For patch auto naming, clear existing patch artifacts in the output directory before building')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Package type: full or patch', 'full');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $packagePathArg = (string) ($input->getArgument('package-path') ?? '');
        $privateKeyPath = (string) $input->getOption('private-key');
        $sourceRoot = (string) $input->getOption('source-root');
        $packageType = (string) $input->getOption('type');
        $fromScratch = (bool) $input->getOption('from-scratch');

        if ($fromScratch && $packageType !== 'patch') {
            $io->error('The --from-scratch option is only supported for --type patch.');

            return Command::FAILURE;
        }

        if ($fromScratch) {
            $outputDirectory = $this->resolvePatchOutputDirectory($packagePathArg, $packageType);
            if ($outputDirectory === null) {
                $io->error('The --from-scratch option requires a directory package-path (or omitted package-path) for patch auto naming.');

                return Command::FAILURE;
            }

            $removed = $this->clearPatchArtifacts($outputDirectory);
            $io->text(sprintf('Removed %d existing patch artifact(s) from: %s', $removed, $outputDirectory));
        }

        $packagePath = $this->resolvePackagePath($packagePathArg, $packageType);

        if ($privateKeyPath === '') {
            $io->error('The --private-key option is required.');

            return Command::FAILURE;
        }

        $builder = new ReleasePackageBuilder($sourceRoot);

        try {
            if ($packageType === 'patch') {
                $baseRoot = (string) $input->getOption('base-root');
                $basePackage = (string) $input->getOption('base-package');
                $basePackage = $this->resolveIncrementalPatchBasePackage($packagePathArg, $packageType, $baseRoot, $basePackage, $fromScratch);
                if ($baseRoot === '' && $basePackage === '') {
                    $io->error('Patch packages require --base-root or --base-package.');

                    return Command::FAILURE;
                }

                $result = $builder->buildPatchPackage(
                    $baseRoot !== '' ? $baseRoot : null,
                    $packagePath,
                    $privateKeyPath,
                    basePackagePath: $basePackage !== '' ? $basePackage : null,
                );

                if ($baseRoot === '' && $basePackage !== '') {
                    $io->text('Patch base package: ' . $basePackage);
                }
            } else {
                $result = $builder->buildFullPackage($packagePath, $privateKeyPath);
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Package built successfully: ' . $result['package_path']);
        $io->text('Manifest: ' . $result['manifest_path']);
        $io->text('Signature: ' . $result['signature_path']);

        return Command::SUCCESS;
    }

    private function resolvePackagePath(string $packagePathArg, string $packageType): string
    {
        $trimmed = trim($packagePathArg);
        if ($trimmed === '') {
            $outputDirectory = $this->defaultOutputDirectory ?? (__DIR__ . '/../../build');
            return $packageType === 'patch'
                ? $this->generateNextPatchPath($outputDirectory)
                : $this->generateTimestampedFullPath($outputDirectory);
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
        if (is_dir($normalized)) {
            return $packageType === 'patch'
                ? $this->generateNextPatchPath($normalized)
                : $this->generateTimestampedFullPath($normalized);
        }

        return $normalized;
    }

    private function generateNextPatchPath(string $outputDirectory): string
    {
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0o700, true) && !is_dir($outputDirectory)) {
            throw new \RuntimeException('Could not create output directory: ' . $outputDirectory);
        }

        $files = glob(rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sparkinsight-patch-*.zip');
        $max = 0;
        if (is_array($files)) {
            foreach ($files as $file) {
                if (preg_match('/sparkinsight-patch-(\d{6})\.zip$/', str_replace('\\', '/', $file), $matches) === 1) {
                    $max = max($max, (int) $matches[1]);
                }
            }
        }

        $next = $max + 1;
        $fileName = sprintf('sparkinsight-patch-%06d.zip', $next);

        return rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
    }

    private function generateTimestampedFullPath(string $outputDirectory): string
    {
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0o700, true) && !is_dir($outputDirectory)) {
            throw new \RuntimeException('Could not create output directory: ' . $outputDirectory);
        }

        $fileName = 'sparkinsight-full-' . date('Ymd-His') . '.zip';

        return rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
    }

    private function resolvePatchOutputDirectory(string $packagePathArg, string $packageType): ?string
    {
        if ($packageType !== 'patch') {
            return null;
        }

        $trimmed = trim($packagePathArg);
        if ($trimmed === '') {
            return $this->defaultOutputDirectory ?? (__DIR__ . '/../../build');
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);

        return is_dir($normalized) ? $normalized : null;
    }

    private function clearPatchArtifacts(string $outputDirectory): int
    {
        if (!is_dir($outputDirectory)) {
            return 0;
        }

        $files = glob(rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sparkinsight-patch-*.zip');
        if (!is_array($files)) {
            return 0;
        }

        $removed = 0;
        foreach ($files as $file) {
            $this->removeIfExists($file);
            $this->removeIfExists($file . '.manifest.json');
            $this->removeIfExists($file . '.manifest.sig');
            $removed++;
        }

        return $removed;
    }

    private function resolveIncrementalPatchBasePackage(string $packagePathArg, string $packageType, string $baseRoot, string $basePackage, bool $fromScratch): string
    {
        if ($packageType !== 'patch' || $baseRoot !== '' || $fromScratch) {
            return $basePackage;
        }

        $outputDirectory = $this->resolvePatchOutputDirectory($packagePathArg, $packageType);
        if ($outputDirectory === null || !is_dir($outputDirectory)) {
            return $basePackage;
        }

        $files = glob(rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sparkinsight-patch-*.zip');
        if (!is_array($files) || $files === []) {
            return $basePackage;
        }

        $latestFile = null;
        $max = -1;
        foreach ($files as $file) {
            if (preg_match('/sparkinsight-patch-(\d{6})\.zip$/', str_replace('\\', '/', $file), $matches) !== 1) {
                continue;
            }

            $number = (int) $matches[1];
            if ($number > $max) {
                $max = $number;
                $latestFile = $file;
            }
        }

        return $latestFile ?? $basePackage;
    }

    private function removeIfExists(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        if (!@unlink($path) && is_file($path)) {
            throw new \RuntimeException('Could not remove existing patch artifact: ' . $path);
        }
    }
}