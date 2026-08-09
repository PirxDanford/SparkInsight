<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;
use ZipArchive;

final class ReleasePackageIntakeService
{
    private readonly string $deployRoot;

    private readonly ReleasePackageInspector $inspector;

    private readonly DeploymentStateStore $stateStore;

    private readonly DeploymentLock $deploymentLock;

    public function __construct(
        string $deployRoot,
        ?ReleasePackageInspector $inspector = null,
        ?DeploymentStateStore $stateStore = null,
    ) {
        $this->deployRoot = $deployRoot;
        $this->inspector = $inspector ?? new ReleasePackageInspector();
        $this->stateStore = $stateStore ?? new DeploymentStateStore($deployRoot);
        $this->deploymentLock = new DeploymentLock($deployRoot);
    }

    /**
     * @return array{operation_id: string, package_path: string, package_hash: string, manifest: ReleaseManifest}
     */
    public function intake(string $uploadedPackagePath, string $publicKeyPath, ?string $operationId = null): array
    {
        $operationId ??= 'package-' . bin2hex(random_bytes(8));
        return $this->deploymentLock->withLock($operationId, function () use ($uploadedPackagePath, $publicKeyPath, $operationId): array {
            if (!is_file($uploadedPackagePath)) {
                throw new RuntimeException('Uploaded package file not found: ' . $uploadedPackagePath);
            }

            if (!is_file($publicKeyPath)) {
                throw new RuntimeException('Trusted release public key not found: ' . $publicKeyPath);
            }

            $packageDirectory = rtrim($this->deployRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'uploads';
            $packagePath = $packageDirectory . DIRECTORY_SEPARATOR . $operationId . '.zip';
            $this->ensureDirectory($packageDirectory);

            if (!copy($uploadedPackagePath, $packagePath)) {
                throw new RuntimeException('Could not store uploaded package: ' . $packagePath);
            }

            $packageHash = hash_file('sha256', $packagePath);
            if ($packageHash === false) {
                @unlink($packagePath);
                throw new RuntimeException('Could not hash stored package: ' . $packagePath);
            }

            $publicKey = (string) file_get_contents($publicKeyPath);
            if ($publicKey === '') {
                @unlink($packagePath);
                throw new RuntimeException('Trusted release public key file is empty: ' . $publicKeyPath);
            }

            $manifest = $this->inspector->inspect($packagePath, $publicKey);
            $manifestSha256 = $this->readPackageManifestSha256($packagePath);

            $this->stateStore->write($operationId, [
                'operation_id' => $operationId,
                'package_id' => $manifest->packageId(),
                'package_type' => $manifest->packageType(),
                'release_id' => $manifest->releaseId(),
                'base_release_id' => $manifest->baseReleaseId(),
                'base_manifest_sha256' => $manifest->baseManifestSha256(),
                'manifest_sha256' => $manifestSha256,
                'package_path' => $packagePath,
                'package_hash' => $packageHash,
                'state' => 'uploaded',
                'created_at' => date(DATE_ATOM),
            ]);

            $this->stateStore->appendAuditEvent($operationId, [
                'event' => 'package_uploaded',
                'operation_id' => $operationId,
                'package_id' => $manifest->packageId(),
                'package_hash' => $packageHash,
                'manifest_sha256' => $manifestSha256,
                'release_id' => $manifest->releaseId(),
                'package_type' => $manifest->packageType(),
                'created_at' => date(DATE_ATOM),
            ]);

            return [
                'operation_id' => $operationId,
                'package_path' => $packagePath,
                'package_hash' => $packageHash,
                'manifest' => $manifest,
            ];
        });
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory === '' || is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create directory: ' . $directory);
        }
    }

    private function readPackageManifestSha256(string $packagePath): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required to inspect package manifests.');
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($packagePath);
        if ($openResult !== true) {
            throw new RuntimeException('Could not open package ZIP for manifest hash; code ' . (string) $openResult);
        }

        try {
            $manifestJson = $zip->getFromName('manifest.json');
            if ($manifestJson === false) {
                throw new RuntimeException('Package ZIP is missing manifest.json.');
            }

            return hash('sha256', $manifestJson);
        } finally {
            $zip->close();
        }
    }
}