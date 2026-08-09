<?php

declare(strict_types=1);

namespace SparkInsight\Command;

use Doctrine\DBAL\DriverManager;
use SparkInsight\Config\Config;
use SparkInsight\Service\CurrentPointerStore;
use SparkInsight\Service\DeploymentLock;
use SparkInsight\Service\DeploymentStateStore;
use SparkInsight\Service\ReleaseActivationService;
use SparkInsight\Service\ReleaseFinalizationService;
use SparkInsight\Service\ReleaseHealthCheck;
use SparkInsight\Service\ReleaseMaterializer;
use SparkInsight\Service\ReleaseRootPublisher;
use SparkInsight\Service\ReleasePreflight;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ReleaseDeployCommand extends Command
{
    private const LIVE_MANIFEST_SHA256_FILE = '.deploy/live-manifest-sha256.txt';

    protected function configure(): void
    {
        $this->setName('release:deploy')
            ->setDescription('Execute an uploaded release package through materialization, activation, and finalization.')
            ->addOption('operation-id', null, InputOption::VALUE_REQUIRED, 'Existing operation id from package intake')
            ->addOption('public-key', null, InputOption::VALUE_REQUIRED, 'Path to trusted release public key', __DIR__ . '/../../.deploy/trusted-release-key.pub')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Project root', __DIR__ . '/../../');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $operationId = trim((string) $input->getOption('operation-id'));
        if ($operationId === '') {
            $io->error('The --operation-id option is required.');

            return Command::FAILURE;
        }

        $projectRoot = (string) $input->getOption('project-root');
        $projectRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $projectRoot), DIRECTORY_SEPARATOR);
        $publicKeyPath = (string) $input->getOption('public-key');

        if (!is_file($publicKeyPath)) {
            $io->error('Trusted release public key file not found: ' . $publicKeyPath);

            return Command::FAILURE;
        }

        $stateStore = new DeploymentStateStore($projectRoot);
        $deploymentLock = new DeploymentLock($projectRoot);
        $pointerStore = new CurrentPointerStore($projectRoot);

        try {
            $config = Config::fromEnvironment();
            $connection = DriverManager::getConnection($config->getDatabaseConfig());
        } catch (\Throwable $throwable) {
            $io->error('Could not connect to the configured database: ' . $throwable->getMessage());

            return Command::FAILURE;
        }

        $materializer = new ReleaseMaterializer();
        $rootPublisher = new ReleaseRootPublisher();
        $preflight = new ReleasePreflight();
        $activation = new ReleaseActivationService($projectRoot, $pointerStore);
        $healthCheck = new ReleaseHealthCheck($connection);
        $finalization = new ReleaseFinalizationService($projectRoot, $pointerStore);
        $activationResult = null;

        try {
            $result = $deploymentLock->withLock($operationId, function () use ($operationId, $stateStore, $pointerStore, $materializer, $rootPublisher, $preflight, $activation, $healthCheck, $finalization, $publicKeyPath, $projectRoot, &$activationResult): array {
                $state = $stateStore->read($operationId);
                if ($state === null) {
                    throw new \RuntimeException('No deployment state exists for operation id: ' . $operationId);
                }

                if (($state['state'] ?? '') === 'deployed') {
                    return [
                        'release_id' => (string) ($state['release_id'] ?? ''),
                        'operation_id' => $operationId,
                        'status' => 'already_deployed',
                    ];
                }

                $packagePath = (string) ($state['package_path'] ?? '');
                if ($packagePath === '' || !is_file($packagePath)) {
                    throw new \RuntimeException('Package path in state is missing or not readable.');
                }

                $liveManifestSha256Path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::LIVE_MANIFEST_SHA256_FILE);

                $pointer = $pointerStore->read();
                $baseReleaseRoot = null;
                if (($state['package_type'] ?? '') === 'patch') {
                    $liveManifestSha256 = is_file($liveManifestSha256Path) ? trim((string) file_get_contents($liveManifestSha256Path)) : '';
                    if ($liveManifestSha256 === '' || preg_match('/^[a-f0-9]{64}$/i', $liveManifestSha256) !== 1) {
                        throw new \RuntimeException('Patch deployment requires a live manifest hash from a previously deployed package. Deploy a full package first.');
                    }

                    $expectedBaseManifestSha256 = mb_strtolower(trim((string) ($state['base_manifest_sha256'] ?? '')));
                    if ($expectedBaseManifestSha256 === '' || preg_match('/^[a-f0-9]{64}$/i', $expectedBaseManifestSha256) !== 1) {
                        throw new \RuntimeException('Patch deployment state is missing a valid base manifest hash. Re-upload the package.');
                    }

                    if (!hash_equals($expectedBaseManifestSha256, mb_strtolower($liveManifestSha256))) {
                        throw new \RuntimeException('Patch base does not match the currently live deployment. Build the patch against the manifest of the live package.');
                    }

                    $baseReleaseRoot = $projectRoot;
                }

                $stagingRoot = $projectRoot . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'staging-releases';
                $materialized = $materializer->materialize($packagePath, (string) file_get_contents($publicKeyPath), $stagingRoot, $baseReleaseRoot);
                $manifest = $materialized['manifest'];
                $releaseRoot = (string) $materialized['release_root'];

                $stateStore->write($operationId, array_merge($state, [
                    'state' => 'materialized',
                    'release_id' => $manifest->releaseId(),
                    'base_release_id' => $manifest->baseReleaseId(),
                    'materialized_at' => date(DATE_ATOM),
                ]));
                $stateStore->appendAuditEvent($operationId, [
                    'event' => 'package_materialized',
                    'operation_id' => $operationId,
                    'release_id' => $manifest->releaseId(),
                    'created_at' => date(DATE_ATOM),
                ]);

                $preflight->run($releaseRoot, $manifest);
                $stateStore->appendAuditEvent($operationId, [
                    'event' => 'preflight_passed',
                    'operation_id' => $operationId,
                    'release_id' => $manifest->releaseId(),
                    'created_at' => date(DATE_ATOM),
                ]);

                $rootPublisher->publish($releaseRoot, $projectRoot);
                $stateStore->appendAuditEvent($operationId, [
                    'event' => 'root_published',
                    'operation_id' => $operationId,
                    'release_id' => $manifest->releaseId(),
                    'created_at' => date(DATE_ATOM),
                ]);

                $packageManifestSha256 = mb_strtolower(trim((string) ($state['manifest_sha256'] ?? '')));
                if ($packageManifestSha256 === '' || preg_match('/^[a-f0-9]{64}$/i', $packageManifestSha256) !== 1) {
                    throw new \RuntimeException('Deployment state is missing package manifest hash. Re-upload the package.');
                }

                $this->writeLiveManifestSha256($liveManifestSha256Path, $packageManifestSha256);

                $activationResult = $activation->activateFromRoot($manifest->releaseId(), $releaseRoot, $operationId);
                $stateStore->appendAuditEvent($operationId, [
                    'event' => 'release_activated',
                    'operation_id' => $operationId,
                    'release_id' => $manifest->releaseId(),
                    'previous_release_id' => $activationResult['previous'],
                    'created_at' => date(DATE_ATOM),
                ]);

                $healthCheck->run($projectRoot);
                $stateStore->appendAuditEvent($operationId, [
                    'event' => 'health_check_passed',
                    'operation_id' => $operationId,
                    'release_id' => $manifest->releaseId(),
                    'created_at' => date(DATE_ATOM),
                ]);

                $finalization->finalize($operationId);
                $this->removeDirectory($releaseRoot);
                $this->clearReleasesDirectory($projectRoot . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'releases');
                $stateStore->write($operationId, array_merge($stateStore->read($operationId) ?? [], [
                    'state' => 'deployed',
                    'release_id' => $manifest->releaseId(),
                    'deployed_at' => date(DATE_ATOM),
                ]));
                $stateStore->appendAuditEvent($operationId, [
                    'event' => 'deployment_finalized',
                    'operation_id' => $operationId,
                    'release_id' => $manifest->releaseId(),
                    'created_at' => date(DATE_ATOM),
                ]);

                return [
                    'release_id' => $manifest->releaseId(),
                    'operation_id' => $operationId,
                    'status' => 'deployed',
                ];
            });
        } catch (\Throwable $throwable) {
            if (is_array($activationResult) && isset($activationResult['previous']) && is_string($activationResult['previous']) && $activationResult['previous'] !== '') {
                try {
                    $pointerStore->write($activationResult['previous'], null, $operationId);
                } catch (\Throwable) {
                }
            }

            try {
                $stateStore->appendAuditEvent($operationId, [
                    'event' => 'deployment_failed',
                    'operation_id' => $operationId,
                    'error' => $throwable->getMessage(),
                    'created_at' => date(DATE_ATOM),
                ]);
            } catch (\Throwable) {
            }

            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        if (($result['status'] ?? '') === 'already_deployed') {
            $io->success('Operation already marked as deployed: ' . $operationId);

            return Command::SUCCESS;
        }

        $io->success('Release activated and finalized: ' . $result['release_id']);
        $io->text('Operation: ' . $result['operation_id']);

        return Command::SUCCESS;
    }

    private function writeLiveManifestSha256(string $path, string $sha256): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create live manifest hash directory: ' . $directory);
        }

        if (file_put_contents($path, $sha256 . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write live manifest hash file: ' . $path);
        }
    }

    private function clearReleasesDirectory(string $releasesDirectory): void
    {
        if (!is_dir($releasesDirectory)) {
            return;
        }

        $items = scandir($releasesDirectory) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $releasesDirectory . DIRECTORY_SEPARATOR . $item;
            $this->removeDirectory($path);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (is_file($directory)) {
            @unlink($directory);
            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}