<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use RuntimeException;
use ZipArchive;

final class ReleasePackageBuilder
{
    /**
     * @param array<int, string> $includePaths
     */
    public function __construct(
        private readonly string $sourceRoot,
        private readonly array $includePaths = [
            'public',
            'src',
            'templates',
            'resources',
            'database',
            'vendor',
            'si.php',
            'composer.json',
            'composer.lock',
            'LICENSE',
        ],
        private readonly ReleasePathPolicy $pathPolicy = new ReleasePathPolicy(),
    ) {
    }

    /**
     * @param array<int, string> $requiredExtensions
     * @return array{manifest_path: string, signature_path: string, package_path: string, manifest_json: string}
     */
    public function buildFullPackage(string $packagePath, string $privateKeyPath, array $requiredExtensions = ['json', 'openssl', 'pdo', 'session', 'tokenizer', 'zip']): array
    {
        $files = $this->scanFiles($this->sourceRoot);
        $payloadFiles = array_map(static fn (array $file): string => $file['path'], $files);
        $releaseId = $this->resolveReleaseIdFromRoot($this->sourceRoot);

        $manifest = $this->createManifest([
            'package_type' => 'full',
            'release_id' => $releaseId,
            'files' => $files,
            'payload_files' => $payloadFiles,
            'delete' => [],
            'required_extensions' => $requiredExtensions,
        ]);

        return $this->writePackage($packagePath, $privateKeyPath, $manifest, $files);
    }

    /**
     * @param array<int, string> $requiredExtensions
     * @return array{manifest_path: string, signature_path: string, package_path: string, manifest_json: string}
     */
    public function buildPatchPackage(?string $baseRoot, string $packagePath, string $privateKeyPath, array $requiredExtensions = ['json', 'openssl', 'pdo', 'session', 'tokenizer', 'zip'], ?string $basePackagePath = null): array
    {
        [$baseFiles, $baseReleaseId, $baseManifestSha256] = $this->resolvePatchBase($baseRoot, $basePackagePath);
        $targetFiles = $this->scanFiles($this->sourceRoot);
        $releaseId = $this->resolveReleaseIdFromRoot($this->sourceRoot);

        $baseIndex = $this->indexByPath($baseFiles);
        $targetIndex = $this->indexByPath($targetFiles);

        $payloadFiles = [];
        $delete = [];

        foreach ($targetIndex as $path => $file) {
            if (!isset($baseIndex[$path]) || $baseIndex[$path]['sha256'] !== $file['sha256'] || $baseIndex[$path]['size'] !== $file['size']) {
                $payloadFiles[] = $path;
            }
        }

        foreach ($baseIndex as $path => $_file) {
            if (!isset($targetIndex[$path])) {
                $delete[] = $path;
            }
        }

        sort($payloadFiles);
        sort($delete);

        $manifest = $this->createManifest([
            'package_type' => 'patch',
            'release_id' => $releaseId,
            'files' => $targetFiles,
            'payload_files' => $payloadFiles,
            'delete' => $delete,
            'required_extensions' => $requiredExtensions,
            'base_release_id' => $baseReleaseId,
            'base_manifest_sha256' => $baseManifestSha256,
        ]);

        return $this->writePackage($packagePath, $privateKeyPath, $manifest, $targetFiles, $payloadFiles);
    }

    /**
     * @return array{0: array<int, array{path: string, size: int, sha256: string}>, 1: string, 2: string}
     */
    private function resolvePatchBase(?string $baseRoot, ?string $basePackagePath): array
    {
        $basePackagePath = $basePackagePath !== null ? trim($basePackagePath) : null;
        if ($basePackagePath !== null && $basePackagePath !== '') {
            return $this->readBaseFromPackageOrManifest($basePackagePath);
        }

        $normalizedBaseRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $baseRoot), DIRECTORY_SEPARATOR);
        if ($normalizedBaseRoot === '' || !is_dir($normalizedBaseRoot)) {
            throw new RuntimeException('Patch base root does not exist: ' . (string) $baseRoot . '. Provide --base-package with a previous release ZIP/manifest or --base-root with an exact local copy of the currently deployed release.');
        }

        if (!is_file($normalizedBaseRoot . DIRECTORY_SEPARATOR . 'composer.lock')) {
            throw new RuntimeException('Patch base root is missing composer.lock: ' . (string) $baseRoot . '. This does not look like a valid release root.');
        }

        if (!is_dir($normalizedBaseRoot . DIRECTORY_SEPARATOR . 'public') || !is_dir($normalizedBaseRoot . DIRECTORY_SEPARATOR . 'src')) {
            throw new RuntimeException('Patch base root is missing expected release directories (public/src): ' . (string) $baseRoot . '.');
        }

        $baseFiles = $this->scanFiles($normalizedBaseRoot);
        $baseReleaseId = $this->resolveReleaseIdFromRoot($normalizedBaseRoot);
        $baseManifestJson = json_encode([
            'files' => $baseFiles,
            'release_id' => $baseReleaseId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [$baseFiles, $baseReleaseId, hash('sha256', $baseManifestJson)];
    }

    /**
     * @return array{0: array<int, array{path: string, size: int, sha256: string}>, 1: string, 2: string}
     */
    private function readBaseFromPackageOrManifest(string $basePackagePath): array
    {
        if (!is_file($basePackagePath)) {
            throw new RuntimeException('Patch base package/manifest file not found: ' . $basePackagePath);
        }

        $manifestJson = null;
        if (strtolower(pathinfo($basePackagePath, PATHINFO_EXTENSION)) === 'zip') {
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('ZipArchive extension is required to read base package ZIPs.');
            }

            $zip = new ZipArchive();
            $openResult = $zip->open($basePackagePath);
            if ($openResult !== true) {
                throw new RuntimeException('Could not open base package ZIP; code ' . (string) $openResult);
            }

            try {
                $manifestEntry = $zip->getFromName('manifest.json');
                if ($manifestEntry === false) {
                    throw new RuntimeException('Base package ZIP does not contain manifest.json: ' . $basePackagePath);
                }

                $manifestJson = $manifestEntry;
            } finally {
                $zip->close();
            }
        } else {
            $manifestJson = (string) file_get_contents($basePackagePath);
            if ($manifestJson === '') {
                throw new RuntimeException('Base manifest file is empty: ' . $basePackagePath);
            }
        }

        $baseManifest = ReleaseManifest::fromJson($manifestJson, $this->pathPolicy);

        return [$baseManifest->files(), $baseManifest->releaseId(), hash('sha256', $manifestJson)];
    }

    /**
     * @param array<int, string> $requiredExtensions
     * @param array<int, array{path: string, size: int, sha256: string}> $files
     * @return array{manifest_path: string, signature_path: string, package_path: string, manifest_json: string}
     */
    private function writePackage(string $packagePath, string $privateKeyPath, array $manifest, array $files, ?array $payloadFiles = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required to build packages.');
        }

        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $privateKey = $this->readBinaryFile($privateKeyPath, 'private key');
        $signature = sodium_crypto_sign_detached($manifestJson, $privateKey);

        $packageDirectory = dirname($packagePath);
        if ($packageDirectory !== '' && !is_dir($packageDirectory) && !mkdir($packageDirectory, 0o700, true) && !is_dir($packageDirectory)) {
            throw new RuntimeException('Could not create package output directory: ' . $packageDirectory);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Could not create package ZIP; code ' . (string) $opened);
        }

        try {
            if (!$zip->addFromString('manifest.json', $manifestJson)) {
                throw new RuntimeException('Could not add manifest.json to package ZIP.');
            }

            if (!$zip->addFromString('manifest.sig', $signature)) {
                throw new RuntimeException('Could not add manifest.sig to package ZIP.');
            }

            $includedPayloadFiles = $payloadFiles ?? array_map(static fn (array $file): string => $file['path'], $files);
            foreach ($includedPayloadFiles as $payloadPath) {
                $absolutePath = $this->sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $payloadPath);
                if (!$zip->addFile($absolutePath, 'payload/' . $payloadPath)) {
                    throw new RuntimeException('Could not add payload file to package ZIP: ' . $payloadPath);
                }
            }

            if (!$zip->close()) {
                throw new RuntimeException('Could not finalize package ZIP.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($packagePath);
            throw $exception;
        }

        $manifestPath = $packagePath . '.manifest.json';
        $signaturePath = $packagePath . '.manifest.sig';
        file_put_contents($manifestPath, $manifestJson);
        file_put_contents($signaturePath, $signature);

        return [
            'manifest_path' => $manifestPath,
            'signature_path' => $signaturePath,
            'package_path' => $packagePath,
            'manifest_json' => $manifestJson,
        ];
    }

    /**
     * @param array<string, mixed> $manifestData
     * @return array<string, mixed>
     */
    private function createManifest(array $manifestData): array
    {
        $files = $manifestData['files'];
        $payloadFiles = $manifestData['payload_files'];
        $delete = $manifestData['delete'];

        $manifest = [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'package-' . bin2hex(random_bytes(8)),
            'package_type' => $manifestData['package_type'],
            'release_id' => $manifestData['release_id'],
            'created_at' => date(DATE_ATOM),
            'minimum_php' => $this->determineMinimumPhpVersion(),
            'required_extensions' => $manifestData['required_extensions'],
            'composer_lock_sha256' => $this->hashFile($this->sourceRoot . DIRECTORY_SEPARATOR . 'composer.lock') ?? str_repeat('0', 64),
            'expanded_size' => array_reduce($files, static fn (int $carry, array $file): int => $carry + (int) $file['size'], 0),
            'file_count' => count($files),
            'files' => $files,
            'payload_files' => $payloadFiles,
            'delete' => $delete,
        ];

        if ($manifestData['package_type'] === 'patch') {
            $manifest['base_release_id'] = $manifestData['base_release_id'];
            $manifest['base_manifest_sha256'] = $manifestData['base_manifest_sha256'];
        }

        return $manifest;
    }

    /**
     * @return array<int, array{path: string, size: int, sha256: string}>
     */
    private function scanFiles(string $root): array
    {
        $files = [];
        foreach ($this->includePaths as $relativePath) {
            $absolutePath = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
            if (is_dir($absolutePath)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }

                    $files[] = $this->buildFileRecord($root, $fileInfo->getPathname());
                }

                continue;
            }

            if (is_file($absolutePath)) {
                $files[] = $this->buildFileRecord($root, $absolutePath);
            }
        }

        usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $files;
    }

    private function buildFileRecord(string $root, string $absolutePath): array
    {
        $relativePath = str_replace('\\', '/', substr($absolutePath, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
        $normalizedPath = $this->pathPolicy->normalize($relativePath);

        return [
            'path' => $normalizedPath,
            'size' => filesize($absolutePath) ?: 0,
            'sha256' => hash_file('sha256', $absolutePath) ?: '',
        ];
    }

    /**
     * @param array<int, array{path: string, size: int, sha256: string}> $files
     * @return array<string, array{path: string, size: int, sha256: string}>
     */
    private function indexByPath(array $files): array
    {
        $indexed = [];
        foreach ($files as $file) {
            $indexed[$file['path']] = $file;
        }

        return $indexed;
    }

    private function resolveReleaseIdFromRoot(string $root): string
    {
        $resolvedRoot = realpath($root);
        $normalizedRoot = $resolvedRoot !== false ? $resolvedRoot : $root;
        $releaseId = basename(rtrim($normalizedRoot, DIRECTORY_SEPARATOR));

        if ($releaseId === '' || $releaseId === '.' || $releaseId === '..' || $releaseId === DIRECTORY_SEPARATOR) {
            return 'release-' . bin2hex(random_bytes(4));
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/', $releaseId) === 1 ? $releaseId : 'release-' . bin2hex(random_bytes(4));
    }

    private function readBinaryFile(string $path, string $label): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(ucfirst($label) . ' not found: ' . $path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read ' . $label . ': ' . $path);
        }

        return $contents;
    }

    private function hashFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    private function determineMinimumPhpVersion(): string
    {
        $composerJsonPath = $this->sourceRoot . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerJsonPath)) {
            return '8.1.0';
        }

        $decoded = json_decode((string) file_get_contents($composerJsonPath), true);
        if (!is_array($decoded)) {
            return '8.1.0';
        }

        $constraint = $decoded['require']['php'] ?? null;
        if (!is_string($constraint) || trim($constraint) === '') {
            return '8.1.0';
        }

        if (preg_match_all('/(\d+)\.(\d+)(?:\.(\d+))?/', $constraint, $matches, PREG_SET_ORDER) !== 1 && count($matches) < 1) {
            return '8.1.0';
        }

        $lowest = null;
        foreach ($matches as $match) {
            $major = (int) $match[1];
            $minor = (int) $match[2];
            $patch = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 0;
            $candidate = sprintf('%d.%d.%d', $major, $minor, $patch);

            if ($lowest === null || version_compare($candidate, $lowest, '<')) {
                $lowest = $candidate;
            }
        }

        return $lowest ?? '8.1.0';
    }
}