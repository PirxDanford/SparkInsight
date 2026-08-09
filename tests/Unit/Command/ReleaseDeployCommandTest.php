<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use SparkInsight\Command\ReleaseDeployCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class ReleaseDeployCommandTest extends TestCase
{
    private function invokePrivate(ReleaseDeployCommand $command, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($command, $method);

        return $reflection->invoke($command, ...$args);
    }

    public function testCommandNameAndDescription(): void
    {
        $command = new ReleaseDeployCommand();

        $this->assertSame('release:deploy', $command->getName());
        $this->assertStringContainsString('Execute an uploaded release package', (string) $command->getDescription());
    }

    public function testExecuteFailsWhenOperationIdIsMissing(): void
    {
        $command = new ReleaseDeployCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The --operation-id option is required.', $tester->getDisplay());
    }

    public function testExecuteFailsWhenTrustedPublicKeyFileIsMissing(): void
    {
        $command = new ReleaseDeployCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--operation-id' => 'op-123',
            '--public-key' => sys_get_temp_dir() . '/missing-release-key-' . bin2hex(random_bytes(8)) . '.pub',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Trusted release public key file not found', $tester->getDisplay());
    }

    public function testExecuteFailsWhenDatabaseConnectionCannotBeCreated(): void
    {
        $publicKeyPath = sys_get_temp_dir() . '/release-key-' . bin2hex(random_bytes(8)) . '.pub';
        file_put_contents($publicKeyPath, 'public-key');

        $previousDriver = $_ENV['DB_DRIVER'] ?? null;
        $_ENV['DB_DRIVER'] = 'invalid_driver_for_test';

        try {
            $command = new ReleaseDeployCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--operation-id' => 'op-123',
                '--public-key' => $publicKeyPath,
            ]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Could not connect to the configured database', $tester->getDisplay());
        } finally {
            @unlink($publicKeyPath);
            if ($previousDriver === null) {
                unset($_ENV['DB_DRIVER']);
            } else {
                $_ENV['DB_DRIVER'] = $previousDriver;
            }
        }
    }

    public function testExecuteReturnsSuccessWhenOperationAlreadyDeployed(): void
    {
        $projectRoot = sys_get_temp_dir() . '/sparkinsight-release-deploy-command-' . bin2hex(random_bytes(8));
        $stateDir = $projectRoot . '/.deploy/state';
        $logsDir = $projectRoot . '/.deploy/logs';
        mkdir($stateDir, 0777, true);
        mkdir($logsDir, 0777, true);

        $operationId = 'op-123';
        file_put_contents($stateDir . '/' . $operationId . '.json', json_encode([
            'operation_id' => $operationId,
            'state' => 'deployed',
            'release_id' => 'release-123',
        ], JSON_THROW_ON_ERROR));

        $publicKeyPath = $projectRoot . '/trusted-release-key.pub';
        file_put_contents($publicKeyPath, str_repeat('a', 32));

        $previousDriver = $_ENV['DB_DRIVER'] ?? null;
        $previousPath = $_ENV['DB_PATH'] ?? null;
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_PATH'] = $projectRoot . '/deploy.sqlite';

        try {
            $command = new ReleaseDeployCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--operation-id' => $operationId,
                '--public-key' => $publicKeyPath,
                '--project-root' => $projectRoot,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('Operation already marked as deployed', $tester->getDisplay());
        } finally {
            if ($previousDriver === null) {
                unset($_ENV['DB_DRIVER']);
            } else {
                $_ENV['DB_DRIVER'] = $previousDriver;
            }
            if ($previousPath === null) {
                unset($_ENV['DB_PATH']);
            } else {
                $_ENV['DB_PATH'] = $previousPath;
            }

            $this->deleteDirectory($projectRoot);
        }
    }

    public function testExecuteFailsWhenOperationStateMissingAfterLockAcquisition(): void
    {
        $projectRoot = sys_get_temp_dir() . '/sparkinsight-release-deploy-command-' . bin2hex(random_bytes(8));
        mkdir($projectRoot, 0777, true);

        $publicKeyPath = $projectRoot . '/trusted-release-key.pub';
        file_put_contents($publicKeyPath, str_repeat('a', 32));

        $previousDriver = $_ENV['DB_DRIVER'] ?? null;
        $previousPath = $_ENV['DB_PATH'] ?? null;
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_PATH'] = $projectRoot . '/deploy.sqlite';

        try {
            $command = new ReleaseDeployCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--operation-id' => 'op-404',
                '--public-key' => $publicKeyPath,
                '--project-root' => $projectRoot,
            ]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('No deployment state exists for operation id', $tester->getDisplay());
        } finally {
            if ($previousDriver === null) {
                unset($_ENV['DB_DRIVER']);
            } else {
                $_ENV['DB_DRIVER'] = $previousDriver;
            }
            if ($previousPath === null) {
                unset($_ENV['DB_PATH']);
            } else {
                $_ENV['DB_PATH'] = $previousPath;
            }

            $this->deleteDirectory($projectRoot);
        }
    }

    public function testWriteLiveManifestSha256WritesFileAndContent(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-release-deploy-command-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $command = new ReleaseDeployCommand();
            $hashPath = $root . '/.deploy/live-manifest-sha256.txt';
            $this->invokePrivate($command, 'writeLiveManifestSha256', $hashPath, str_repeat('a', 64));

            $this->assertFileExists($hashPath);
            $this->assertSame(str_repeat('a', 64) . PHP_EOL, (string) file_get_contents($hashPath));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteLiveManifestSha256ThrowsWhenDirectoryCannotBeCreated(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-release-deploy-command-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        file_put_contents($root . '/blocked', 'x');

        try {
            $command = new ReleaseDeployCommand();
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not create live manifest hash directory:');
            $this->invokePrivate($command, 'writeLiveManifestSha256', $root . '/blocked/live-manifest-sha256.txt', str_repeat('a', 64));
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testWriteLiveManifestSha256ThrowsWhenTargetPathIsDirectory(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-release-deploy-command-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/live-manifest-sha256.txt', 0777, true);

        try {
            $command = new ReleaseDeployCommand();
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not write live manifest hash file:');
            $this->invokePrivate($command, 'writeLiveManifestSha256', $root . '/.deploy/live-manifest-sha256.txt', str_repeat('a', 64));
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testClearReleasesDirectoryRemovesChildren(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-release-deploy-command-' . bin2hex(random_bytes(8));
        $releases = $root . '/.deploy/releases';
        mkdir($releases . '/release-a/public', 0777, true);
        mkdir($releases . '/release-b/public', 0777, true);
        file_put_contents($releases . '/release-a/public/index.php', "<?php\n");
        file_put_contents($releases . '/release-b/public/index.php', "<?php\n");

        try {
            $command = new ReleaseDeployCommand();
            $this->invokePrivate($command, 'clearReleasesDirectory', $releases);

            $this->assertSame(['.', '..'], scandir($releases));
            $this->invokePrivate($command, 'clearReleasesDirectory', $root . '/does-not-exist');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testRemoveDirectoryHandlesFileAndDirectoryAndMissingPaths(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-release-deploy-command-' . bin2hex(random_bytes(8));
        mkdir($root . '/dir/sub', 0777, true);
        file_put_contents($root . '/dir/sub/file.txt', 'x');
        file_put_contents($root . '/single.txt', 'x');

        try {
            $command = new ReleaseDeployCommand();

            $this->invokePrivate($command, 'removeDirectory', $root . '/single.txt');
            $this->assertFileDoesNotExist($root . '/single.txt');

            $this->invokePrivate($command, 'removeDirectory', $root . '/dir');
            $this->assertDirectoryDoesNotExist($root . '/dir');

            $this->invokePrivate($command, 'removeDirectory', $root . '/missing-path');
            $this->assertTrue(true);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function deleteDirectory(string $directory): void
    {
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
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
