<?php

declare(strict_types=1);

final class DeployMirror
{
    private string $repoRoot;

    private string $sourceDir;

    private string $targetDir;

    private string $stateDir;

    private string $preservedEnvPath;

    private string $releaseManifestPath;

    private bool $emitOutput;

    /** @var array<int, string> */
    private array $includePaths = [
        '.htaccess',
        'index.php',
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
    ];

    public function __construct(string $repoRoot, string $targetDir, string $stateDir, bool $emitOutput = true)
    {
        $this->repoRoot = $repoRoot;
        $this->sourceDir = $repoRoot;
        $this->targetDir = $this->normalizePath($targetDir, $repoRoot);
        $this->stateDir = $this->normalizePath($stateDir, $repoRoot);
        $this->preservedEnvPath = $this->targetDir . DIRECTORY_SEPARATOR . '.env';
        $this->releaseManifestPath = $this->targetDir . DIRECTORY_SEPARATOR . 'release-manifest.json';
        $this->emitOutput = $emitOutput;
    }

    public function run(string $command): int
    {
        return match ($command) {
            'status' => $this->status(),
            'sync' => $this->sync(),
            default => throw new RuntimeException('Unknown command: ' . $command),
        };
    }

    private function status(): int
    {
        $sourceManifest = $this->buildManifest($this->sourceDir);
        $targetManifest = $this->loadManifest($this->stateDir);
        $diff = $this->buildDiff($sourceManifest, $targetManifest);
        $envState = $this->inspectPreservedEnv();

        $this->ensureDirectory($this->stateDir);
        $this->writeJson($this->stateDir . DIRECTORY_SEPARATOR . 'manifest.json', $this->buildStatePayload($sourceManifest, $diff, $envState));
        if (!($diff['report']['is_clean'] ?? false)) {
            $this->writeChangesJson($diff['changes'], $diff['report'] + $envState);
        }

        $this->printSummary('Deployment status', $diff['report'], $envState);

        return $diff['report']['is_clean'] ? 0 : 1;
    }

    private function sync(): int
    {
        $sourceManifest = $this->buildManifest($this->sourceDir);
        $targetManifest = $this->loadManifest($this->stateDir);
        $diff = $this->buildDiff($sourceManifest, $targetManifest);
        $envState = $this->inspectPreservedEnv();

        $this->ensureDirectory($this->targetDir);
        $this->ensureDirectory($this->stateDir);
        $this->removeDeletedFiles($diff['removed']);
        $this->removeLocalMetadataFromTarget();
        $this->copyIncludedFiles();
        $this->writeReleaseManifest($sourceManifest);

        $this->removeLegacyStateFiles();
        $this->writeJson($this->stateDir . DIRECTORY_SEPARATOR . 'manifest.json', $this->buildStatePayload($sourceManifest, $diff, $envState));
        if ($diff['report']['is_clean'] ?? false) {
            $this->deleteLatestChangesHistory();
        } else {
            $this->writeChangesJson($diff['changes'], $diff['report'] + $envState);
        }

        $this->printSummary('Deployment sync', $diff['report'], $envState);

        if ($this->emitOutput && !$diff['report']['is_clean']) {
            fwrite(STDOUT, PHP_EOL . 'Mirror updated to source state.' . PHP_EOL);
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(string $baseDir): array
    {
        $files = [];
        foreach ($this->includePaths as $relativePath) {
            $path = $baseDir . DIRECTORY_SEPARATOR . $relativePath;
            if (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST,
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }

                    $absolutePath = $fileInfo->getPathname();
                    $files[] = $this->buildFileRecord($baseDir, $absolutePath);
                }

                continue;
            }

            if (is_file($path)) {
                $files[] = $this->buildFileRecord($baseDir, $path);
            }
        }

        usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        $payload = [
            'generated_at' => date(DATE_ATOM),
            'repo_root' => $this->repoRoot,
            'git_commit' => $this->gitCommit(),
            'composer_lock_hash' => $this->hashFile($baseDir . DIRECTORY_SEPARATOR . 'composer.lock'),
            'files' => $files,
        ];

        $payload['manifest_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFileRecord(string $baseDir, string $absolutePath): array
    {
        $relativePath = str_replace('\\', '/', mb_substr($absolutePath, mb_strlen(mb_rtrim($baseDir, DIRECTORY_SEPARATOR)) + 1));

        return [
            'path' => $relativePath,
            'size' => filesize($absolutePath) ?: 0,
            'sha256' => hash_file('sha256', $absolutePath) ?: '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadManifest(string $directory): array
    {
        $manifestCandidates = [
            $directory . DIRECTORY_SEPARATOR . 'manifest.json',
            $directory . DIRECTORY_SEPARATOR . 'deploy-manifest.json',
        ];

        foreach ($manifestCandidates as $manifestPath) {
            if (!is_file($manifestPath)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($decoded)) {
                continue;
            }

            if (isset($decoded['files']) && is_array($decoded['files'])) {
                return $decoded;
            }
        }

        return [
            'files' => [],
            'composer_lock_hash' => $this->hashFile($directory . DIRECTORY_SEPARATOR . 'composer.lock'),
            'git_commit' => null,
        ];
    }

    /**
     * @return array{changes: array<int, array{status: string, path: string}>, added: array<int, string>, modified: array<int, string>, removed: array<int, string>, report: array<string, mixed>}
     */
    private function buildDiff(array $sourceManifest, array $targetManifest): array
    {
        $sourceFiles = $this->indexByPath($sourceManifest['files'] ?? []);
        $targetFiles = $this->indexByPath($targetManifest['files'] ?? []);

        $added = [];
        $modified = [];
        $removed = [];
        $changes = [];

        foreach ($sourceFiles as $path => $sourceFile) {
            if (!isset($targetFiles[$path])) {
                $added[] = $path;
                $changes[] = ['status' => 'A', 'path' => $path];
                continue;
            }

            if ($sourceFile['sha256'] !== ($targetFiles[$path]['sha256'] ?? null) || $sourceFile['size'] !== ($targetFiles[$path]['size'] ?? null)) {
                $modified[] = $path;
                $changes[] = ['status' => 'M', 'path' => $path];
            }
        }

        foreach ($targetFiles as $path => $_targetFile) {
            if (!isset($sourceFiles[$path])) {
                $removed[] = $path;
                $changes[] = ['status' => 'D', 'path' => $path];
            }
        }

        sort($added);
        sort($modified);
        sort($removed);

        $sourceLockHash = $sourceManifest['composer_lock_hash'] ?? $this->hashFile($this->sourceDir . DIRECTORY_SEPARATOR . 'composer.lock');
        $targetLockHash = $targetManifest['composer_lock_hash'] ?? $this->hashFile($this->targetDir . DIRECTORY_SEPARATOR . 'composer.lock');
        $sourceCommit = $sourceManifest['git_commit'] ?? $this->gitCommit();
        $targetCommit = $targetManifest['git_commit'] ?? $this->gitCommit();

        $report = [
            'source_lock_hash' => $sourceLockHash ?? 'unknown',
            'target_lock_hash' => $targetLockHash ?? 'unknown',
            'source_commit' => $sourceCommit ?? 'unknown',
            'target_commit' => $targetCommit ?? 'unknown',
            'added_count' => count($added),
            'modified_count' => count($modified),
            'removed_count' => count($removed),
            'is_clean' => count($added) === 0 && count($modified) === 0 && count($removed) === 0,
        ];

        return [
            'changes' => $changes,
            'added' => $added,
            'modified' => $modified,
            'removed' => $removed,
            'report' => $report,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<string, mixed>>
     */
    private function indexByPath(array $files): array
    {
        $indexed = [];
        foreach ($files as $file) {
            if (!isset($file['path']) || !is_string($file['path'])) {
                continue;
            }

            $indexed[$file['path']] = $file;
        }

        return $indexed;
    }

    /**
     * @param array<int, string> $removed
     */
    private function removeDeletedFiles(array $removed): void
    {
        foreach ($removed as $relativePath) {
            $targetPath = $this->targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }

    private function copyIncludedFiles(): void
    {
        foreach ($this->includePaths as $relativePath) {
            $sourcePath = $this->sourceDir . DIRECTORY_SEPARATOR . $relativePath;
            $targetPath = $this->targetDir . DIRECTORY_SEPARATOR . $relativePath;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);
                continue;
            }

            if (is_file($sourcePath)) {
                $this->ensureDirectory(dirname($targetPath));
                if (!copy($sourcePath, $targetPath)) {
                    throw new RuntimeException('Could not copy file to target: ' . $relativePath);
                }
            }
        }
    }

    private function removeLocalMetadataFromTarget(): void
    {
        $metadataFiles = [
            'manifest.json',
            'deploy-manifest.json',
            'deploy-state.json',
            'deploy-status.json',
            'deploy-changes.txt',
        ];

        foreach ($metadataFiles as $metadataFile) {
            $targetPath = $this->targetDir . DIRECTORY_SEPARATOR . $metadataFile;
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }

    private function removeLegacyStateFiles(): void
    {
        $this->ensureDirectory($this->stateDir);

        $legacyFiles = [
            'deploy-manifest.json',
            'deploy-state.json',
            'deploy-status.json',
            'deploy-changes.txt',
        ];

        foreach ($legacyFiles as $fileName) {
            $path = $this->stateDir . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * The target .env is treated as a preserved secret artifact and never
     * participates in manifest comparisons or sync copies.
     *
     * @return array{target_env_present: bool, target_env_preserved: bool}
     */
    private function inspectPreservedEnv(): array
    {
        return [
            'target_env_present' => is_file($this->preservedEnvPath),
            'target_env_preserved' => true,
        ];
    }

    private function copyDirectory(string $sourceDir, string $targetDir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            $relativePath = mb_substr($fileInfo->getPathname(), mb_strlen(mb_rtrim($sourceDir, DIRECTORY_SEPARATOR)) + 1);
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

            if ($fileInfo->isDir()) {
                $this->ensureDirectory($targetPath);
                continue;
            }

            $this->ensureDirectory(dirname($targetPath));
            if (!copy($fileInfo->getPathname(), $targetPath)) {
                throw new RuntimeException('Could not copy file to target: ' . $relativePath);
            }
        }
    }

    private function buildStatePayload(array $manifest, array $diff, array $envState): array
    {
        $report = $diff['report'] + $envState;
        $report['source_lock_hash'] ??= 'unknown';
        $report['target_lock_hash'] ??= 'unknown';
        $report['source_commit'] ??= 'unknown';
        $report['target_commit'] ??= 'unknown';

        return [
            'generated_at' => date(DATE_ATOM),
            'source_lock_hash' => $report['source_lock_hash'],
            'source_commit' => $report['source_commit'],
            'manifest_hash' => $manifest['manifest_hash'] ?? null,
            'report' => $report,
            'changes' => $diff['changes'],
            'files' => $manifest['files'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode JSON for: ' . $path);
        }

        if (file_put_contents($path, $encoded . PHP_EOL) === false) {
            throw new RuntimeException('Could not write file: ' . $path);
        }
    }

    /**
     * @param array<int, array{status: string, path: string}> $changes
     * @param array<string, mixed> $report
     */
    private function writeChangesJson(array $changes, array $report): void
    {
        $timestamp = date('Ymd-His');
        $path = $this->stateDir . DIRECTORY_SEPARATOR . 'changes-' . $timestamp . '.json';
        $payload = [
            'generated_at' => date(DATE_ATOM),
            'report' => $report,
            'changes' => $changes,
        ];

        $this->writeJson($path, $payload);
    }

    private function deleteLatestChangesHistory(): void
    {
        $changeFiles = glob($this->stateDir . DIRECTORY_SEPARATOR . 'changes-*.json');
        if ($changeFiles === false || $changeFiles === []) {
            return;
        }

        usort($changeFiles, static fn (string $left, string $right): int => strcmp($right, $left));
        foreach ($changeFiles as $changeFile) {
            @unlink($changeFile);

            return;
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function printSummary(string $label, array $report, array $envState): void
    {
        if (!$this->emitOutput) {
            return;
        }

        fwrite(STDOUT, $label . PHP_EOL);
        fwrite(STDOUT, 'Source lock: ' . ($report['source_lock_hash'] ?? 'n/a') . PHP_EOL);
        fwrite(STDOUT, 'Target lock: ' . ($report['target_lock_hash'] ?? 'n/a') . PHP_EOL);
        fwrite(STDOUT, 'Added: ' . ($report['added_count'] ?? 0) . PHP_EOL);
        fwrite(STDOUT, 'Modified: ' . ($report['modified_count'] ?? 0) . PHP_EOL);
        fwrite(STDOUT, 'Removed: ' . ($report['removed_count'] ?? 0) . PHP_EOL);
        fwrite(STDOUT, 'Clean: ' . (($report['is_clean'] ?? false) ? 'yes' : 'no') . PHP_EOL);
        fwrite(STDOUT, 'Preserved .env: ' . (($envState['target_env_present'] ?? false) ? 'present' : 'missing') . PHP_EOL);
        fwrite(STDOUT, 'Release manifest: ' . (is_file($this->releaseManifestPath) ? 'present' : 'missing') . PHP_EOL);
        fwrite(STDOUT, 'Target dir: ' . $this->targetDir . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $sourceManifest
     */
    private function writeReleaseManifest(array $sourceManifest): void
    {
        $files = [];
        foreach (($sourceManifest['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            if ($path === '' || $path === 'release-manifest.json') {
                continue;
            }

            $files[] = [
                'path' => $path,
                'size' => (int) ($file['size'] ?? 0),
                'sha256' => (string) ($file['sha256'] ?? ''),
            ];
        }

        $payload = [
            'generated_at' => date(DATE_ATOM),
            'source_commit' => $sourceManifest['git_commit'] ?? null,
            'source_lock_hash' => $sourceManifest['composer_lock_hash'] ?? null,
            'manifest_hash' => $sourceManifest['manifest_hash'] ?? null,
            'file_count' => count($files),
            'files' => $files,
        ];

        $this->writeJson($this->releaseManifestPath, $payload);
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory === '' || is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create directory: ' . $directory);
        }
    }

    private function hashFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    private function gitCommit(): ?string
    {
        $originalCwd = getcwd();
        if ($originalCwd === false) {
            return null;
        }

        chdir($this->repoRoot);
        try {
            $output = [];
            $exitCode = 0;
            @exec('git rev-parse --short HEAD 2>NUL', $output, $exitCode);

            if ($exitCode !== 0 || $output === []) {
                return null;
            }

            return mb_trim((string) $output[0]);
        } finally {
            chdir($originalCwd);
        }
    }

    private function normalizePath(string $path, string $baseDir): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\/', $path) === 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $repoRoot = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
    $argv = $_SERVER['argv'] ?? [];
    $command = 'sync';
    if (isset($argv[1]) && !str_starts_with((string) $argv[1], '-')) {
        $command = (string) $argv[1];
    }

    $options = getopt('', ['target-dir::', 'state-dir::']);
    $targetDir = isset($options['target-dir']) && is_string($options['target-dir']) && $options['target-dir'] !== ''
        ? $options['target-dir']
        : 'production';
    $stateDir = isset($options['state-dir']) && is_string($options['state-dir']) && $options['state-dir'] !== ''
        ? $options['state-dir']
        : '.deploy' . DIRECTORY_SEPARATOR . mb_trim($targetDir, DIRECTORY_SEPARATOR);

    try {
        $deploy = new DeployMirror($repoRoot, $targetDir, $stateDir);
        exit($deploy->run($command));
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Deployment helper failed: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
