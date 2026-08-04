<?php

declare(strict_types=1);

/**
 * Simulate third-party Composer consumption of this repository.
 *
 * This verifies that a consumer project can resolve SparkInsight through
 * a path repository, even before package registration/tagging.
 */
final class ComposerInstallSimulation
{
    private string $repoRoot;

    private string $simDir;

    public function __construct()
    {
        $this->repoRoot = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
        $this->simDir = mb_rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'sparkinsight-composer-install-sim';
    }

    public function run(): int
    {
        $this->prepareSimulationDirectory();
        $this->writeConsumerComposerJson();

        $this->runCommand(
            sprintf(
                '%s --working-dir=%s update --no-interaction --no-scripts --no-progress',
                'composer',
                escapeshellarg($this->simDir),
            ),
            $this->simDir,
        );

        $lockFile = $this->simDir . DIRECTORY_SEPARATOR . 'composer.lock';
        if (!is_file($lockFile)) {
            throw new RuntimeException('composer.lock was not generated in simulation project.');
        }

        $lockData = json_decode((string) file_get_contents($lockFile), true);
        if (!is_array($lockData)) {
            throw new RuntimeException('Could not parse simulation composer.lock.');
        }

        $packages = $lockData['packages'] ?? [];
        $found = false;
        foreach ($packages as $package) {
            if (($package['name'] ?? '') === 'sparkinsight/sparkinsight') {
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new RuntimeException('sparkinsight/sparkinsight was not resolved in simulation lock file.');
        }

        fwrite(STDOUT, "Composer install simulation passed.\n");

        return 0;
    }

    private function prepareSimulationDirectory(): void
    {
        if (is_dir($this->simDir)) {
            $this->deleteDirectory($this->simDir);
        }

        if (!mkdir($concurrentDirectory = $this->simDir, 0o777, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('Could not create simulation directory: ' . $this->simDir);
        }
    }

    private function writeConsumerComposerJson(): void
    {
        $repoPath = str_replace('\\', '/', $this->repoRoot);

        $payload = [
            'name' => 'sparkinsight/install-simulation',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'require' => [
                'sparkinsight/sparkinsight' => '*',
            ],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $repoPath,
                    'options' => [
                        'symlink' => false,
                    ],
                ],
            ],
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode simulation composer.json payload.');
        }

        $composerJsonPath = $this->simDir . DIRECTORY_SEPARATOR . 'composer.json';
        file_put_contents($composerJsonPath, $encoded . PHP_EOL);
    }

    private function runCommand(string $command, string $cwd): void
    {
        $originalCwd = getcwd();
        if ($originalCwd === false) {
            throw new RuntimeException('Could not determine current working directory.');
        }

        if (!chdir($cwd)) {
            throw new RuntimeException('Could not change directory to: ' . $cwd);
        }

        try {
            $exitCode = 0;
            passthru($command, $exitCode);

            if ($exitCode !== 0) {
                throw new RuntimeException(sprintf('Command failed with exit code %d: %s', $exitCode, $command));
            }
        } finally {
            chdir($originalCwd);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}

try {
    $simulation = new ComposerInstallSimulation();
    exit($simulation->run());
} catch (Throwable $exception) {
    fwrite(STDERR, 'Composer install simulation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
