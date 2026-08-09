<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use SparkInsight\Command\BuildReleasePackageCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildReleasePackageCommandTest extends TestCase
{
    private function invokePrivate(BuildReleasePackageCommand $command, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($command, $method);

        return $reflection->invoke($command, ...$args);
    }

    public function testCommandNameAndDescription(): void
    {
        $command = new BuildReleasePackageCommand();

        $this->assertSame('package:build', $command->getName());
        $this->assertStringContainsString('Build a signed full or patch release package.', (string) $command->getDescription());
    }

    public function testExecuteFailsWithoutPrivateKey(): void
    {
        $command = new BuildReleasePackageCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The --private-key option is required.', $tester->getDisplay());
    }

    public function testExecuteFailsForPatchWithoutBase(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $defaultBuildDir = $root . '/default-build';
        mkdir($root, 0777, true);
        mkdir($defaultBuildDir, 0777, true);
        file_put_contents($root . '/composer.lock', "{}\n");

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildReleasePackageCommand($defaultBuildDir);
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--private-key' => $privateKeyPath,
                '--source-root' => $root,
                '--type' => 'patch',
            ]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Patch packages require --base-root or --base-package.', $tester->getDisplay());
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteBuildsFullPackageWithAutoNameInDirectory(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $buildDir = $root . '/build';
        mkdir($root . '/src', 0777, true);
        mkdir($buildDir, 0777, true);
        file_put_contents($root . '/composer.lock', "{}\n");
        file_put_contents($root . '/src/App.php', "<?php\nreturn 1;\n");

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildReleasePackageCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'package-path' => $buildDir,
                '--private-key' => $privateKeyPath,
                '--source-root' => $root,
                '--type' => 'full',
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('Package built successfully:', $tester->getDisplay());

            $files = glob($buildDir . '/sparkinsight-full-*.zip');
            $this->assertIsArray($files);
            $this->assertCount(1, $files);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteBuildsFullPackageWithDefaultBuildDirectoryWhenPathOmitted(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $defaultBuildDir = $root . '/default-build';
        mkdir($root . '/src', 0777, true);
        mkdir($defaultBuildDir, 0777, true);
        file_put_contents($root . '/composer.lock', "{}\n");
        file_put_contents($root . '/src/App.php', "<?php\nreturn 1;\n");

        $before = glob($defaultBuildDir . '/sparkinsight-full-*.zip') ?: [];

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildReleasePackageCommand($defaultBuildDir);
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--private-key' => $privateKeyPath,
                '--source-root' => $root,
                '--type' => 'full',
            ]);

            $this->assertSame(0, $exitCode);
            $after = glob($defaultBuildDir . '/sparkinsight-full-*.zip') ?: [];
            $this->assertGreaterThan(count($before), count($after));

            $newFiles = array_diff($after, $before);
            foreach ($newFiles as $newFile) {
                @unlink($newFile);
                @unlink($newFile . '.manifest.json');
                @unlink($newFile . '.manifest.sig');
            }
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteBuildsPatchPackageWithBaseRoot(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $defaultBuildDir = $root . '/default-build';
        $baseRoot = $root . '/base';
        $targetRoot = $root . '/target';
        mkdir($defaultBuildDir, 0777, true);
        mkdir($baseRoot . '/src', 0777, true);
        mkdir($targetRoot . '/src', 0777, true);
        mkdir($baseRoot . '/public', 0777, true);
        mkdir($targetRoot . '/public', 0777, true);
        file_put_contents($baseRoot . '/composer.lock', "{}\n");
        file_put_contents($targetRoot . '/composer.lock', "{}\n");
        file_put_contents($baseRoot . '/src/App.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/src/App.php', "<?php\nreturn 2;\n");
        file_put_contents($baseRoot . '/public/index.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/public/index.php', "<?php\nreturn 1;\n");

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildReleasePackageCommand($defaultBuildDir);
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--private-key' => $privateKeyPath,
                '--source-root' => $targetRoot,
                '--type' => 'patch',
                '--base-root' => $baseRoot,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('Package built successfully:', $tester->getDisplay());
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteReturnsFailureWhenBuilderThrows(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/composer.lock', "{}\n");
        file_put_contents($root . '/src/App.php', "<?php\nreturn 1;\n");

        try {
            $command = new BuildReleasePackageCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--private-key' => $root . '/missing-private.key',
                '--source-root' => $root,
                '--type' => 'full',
            ]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('private key not found', strtolower($tester->getDisplay()));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteAcceptsExplicitPackageFilePath(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $outputDir = $root . '/output';
        mkdir($root . '/src', 0777, true);
        mkdir($outputDir, 0777, true);
        file_put_contents($root . '/composer.lock', "{}\n");
        file_put_contents($root . '/src/App.php', "<?php\nreturn 1;\n");

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildReleasePackageCommand();
            $tester = new CommandTester($command);
            $packagePath = $outputDir . '/explicit-package.zip';

            $exitCode = $tester->execute([
                'package-path' => $packagePath,
                '--private-key' => $privateKeyPath,
                '--source-root' => $root,
                '--type' => 'full',
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertFileExists($packagePath);
            $this->assertFileExists($packagePath . '.manifest.json');
            $this->assertFileExists($packagePath . '.manifest.sig');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteUsesIncrementingPatchNumbersInDirectory(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $buildDir = $root . '/build';
        $baseRoot = $root . '/base';
        $targetRoot = $root . '/target';
        mkdir($buildDir, 0777, true);
        mkdir($baseRoot . '/src', 0777, true);
        mkdir($targetRoot . '/src', 0777, true);
        mkdir($baseRoot . '/public', 0777, true);
        mkdir($targetRoot . '/public', 0777, true);
        file_put_contents($buildDir . '/sparkinsight-patch-000001.zip', 'x');
        file_put_contents($buildDir . '/sparkinsight-patch-000009.zip', 'x');
        file_put_contents($baseRoot . '/composer.lock', "{}\n");
        file_put_contents($targetRoot . '/composer.lock', "{}\n");
        file_put_contents($baseRoot . '/src/App.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/src/App.php', "<?php\nreturn 2;\n");
        file_put_contents($baseRoot . '/public/index.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/public/index.php', "<?php\nreturn 1;\n");

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildReleasePackageCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'package-path' => $buildDir,
                '--private-key' => $privateKeyPath,
                '--source-root' => $targetRoot,
                '--type' => 'patch',
                '--base-root' => $baseRoot,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertFileExists($buildDir . '/sparkinsight-patch-000010.zip');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteBuildsPatchPackageWithDefaultBuildDirectoryWhenPathOmitted(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $defaultBuildDir = $root . '/default-build';
        $baseRoot = $root . '/base';
        $targetRoot = $root . '/target';
        mkdir($defaultBuildDir, 0777, true);
        mkdir($baseRoot . '/src', 0777, true);
        mkdir($targetRoot . '/src', 0777, true);
        mkdir($baseRoot . '/public', 0777, true);
        mkdir($targetRoot . '/public', 0777, true);
        file_put_contents($baseRoot . '/composer.lock', "{}\n");
        file_put_contents($targetRoot . '/composer.lock', "{}\n");
        file_put_contents($baseRoot . '/src/App.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/src/App.php', "<?php\nreturn 2;\n");
        file_put_contents($baseRoot . '/public/index.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/public/index.php', "<?php\nreturn 1;\n");

        $before = glob($defaultBuildDir . '/sparkinsight-patch-*.zip') ?: [];

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildReleasePackageCommand($defaultBuildDir);
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--private-key' => $privateKeyPath,
                '--source-root' => $targetRoot,
                '--type' => 'patch',
                '--base-root' => $baseRoot,
            ]);

            $this->assertSame(0, $exitCode);
            $after = glob($defaultBuildDir . '/sparkinsight-patch-*.zip') ?: [];
            $this->assertGreaterThan(count($before), count($after));

            $newFiles = array_diff($after, $before);
            foreach ($newFiles as $newFile) {
                @unlink($newFile);
                @unlink($newFile . '.manifest.json');
                @unlink($newFile . '.manifest.sig');
            }
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecutePatchUsesLatestLocalPatchAsIncrementalBaseAutomatically(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $buildDir = $root . '/build';
        $baseRoot = $root . '/base';
        $targetRoot = $root . '/target';
        mkdir($buildDir, 0777, true);
        mkdir($baseRoot . '/src', 0777, true);
        mkdir($targetRoot . '/src', 0777, true);
        mkdir($baseRoot . '/public', 0777, true);
        mkdir($targetRoot . '/public', 0777, true);
        file_put_contents($baseRoot . '/composer.lock', "{}\n");
        file_put_contents($targetRoot . '/composer.lock', "{}\n");
        file_put_contents($baseRoot . '/src/App.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/src/App.php', "<?php\nreturn 2;\n");
        file_put_contents($baseRoot . '/public/index.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/public/index.php', "<?php\nreturn 1;\n");

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildReleasePackageCommand();
            $tester = new CommandTester($command);

            $exitCodeOne = $tester->execute([
                'package-path' => $buildDir,
                '--private-key' => $privateKeyPath,
                '--source-root' => $targetRoot,
                '--type' => 'patch',
                '--base-root' => $baseRoot,
            ]);

            $this->assertSame(0, $exitCodeOne);
            $this->assertFileExists($buildDir . '/sparkinsight-patch-000001.zip');

            file_put_contents($targetRoot . '/src/Feature.php', "<?php\nreturn 3;\n");

            $exitCodeTwo = $tester->execute([
                'package-path' => $buildDir,
                '--private-key' => $privateKeyPath,
                '--source-root' => $targetRoot,
                '--type' => 'patch',
                '--base-package' => $buildDir . '/sparkinsight-full.zip',
            ]);

            $this->assertSame(0, $exitCodeTwo);
            $this->assertFileExists($buildDir . '/sparkinsight-patch-000002.zip');
            $expectedBasePath = str_replace('/', DIRECTORY_SEPARATOR, $buildDir . '/sparkinsight-patch-000001.zip');
            $this->assertStringContainsString('Patch base package: ' . $expectedBasePath, $tester->getDisplay());
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteFromScratchClearsOldPatchArtifactsAndRestartsNumbering(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $buildDir = $root . '/build';
        $baseRoot = $root . '/base';
        $targetRoot = $root . '/target';
        mkdir($buildDir, 0777, true);
        mkdir($baseRoot . '/src', 0777, true);
        mkdir($targetRoot . '/src', 0777, true);
        mkdir($baseRoot . '/public', 0777, true);
        mkdir($targetRoot . '/public', 0777, true);
        file_put_contents($baseRoot . '/composer.lock', "{}\n");
        file_put_contents($targetRoot . '/composer.lock', "{}\n");
        file_put_contents($baseRoot . '/src/App.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/src/App.php', "<?php\nreturn 2;\n");
        file_put_contents($baseRoot . '/public/index.php', "<?php\nreturn 1;\n");
        file_put_contents($targetRoot . '/public/index.php', "<?php\nreturn 1;\n");

        file_put_contents($buildDir . '/sparkinsight-patch-000001.zip', 'x');
        file_put_contents($buildDir . '/sparkinsight-patch-000001.zip.manifest.json', '{}');
        file_put_contents($buildDir . '/sparkinsight-patch-000001.zip.manifest.sig', 'sig');
        file_put_contents($buildDir . '/sparkinsight-patch-000009.zip', 'x');

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildReleasePackageCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'package-path' => $buildDir,
                '--private-key' => $privateKeyPath,
                '--source-root' => $targetRoot,
                '--type' => 'patch',
                '--base-root' => $baseRoot,
                '--from-scratch' => true,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertFileExists($buildDir . '/sparkinsight-patch-000001.zip');
            $this->assertFileDoesNotExist($buildDir . '/sparkinsight-patch-000009.zip');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteFromScratchFailsForExplicitPatchFilePath(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $command = new BuildReleasePackageCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'package-path' => $root . '/sparkinsight-patch.zip',
                '--type' => 'patch',
                '--from-scratch' => true,
            ]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('requires a directory package-path', $tester->getDisplay());
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteFromScratchFailsForNonPatchBuilds(): void
    {
        $command = new BuildReleasePackageCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--type' => 'full',
            '--from-scratch' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('only supported for --type patch', $tester->getDisplay());
    }

    public function testExecuteFullThrowsWhenDefaultBuildPathIsFile(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $defaultBuildDir = $root . '/blocked-build';
        mkdir($root, 0777, true);

        file_put_contents($defaultBuildDir, 'blocked');

        try {
            $command = new BuildReleasePackageCommand($defaultBuildDir);
            $tester = new CommandTester($command);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not create output directory:');
            $tester->execute([
                '--type' => 'full',
            ]);
        } finally {
            restore_error_handler();
            @unlink($defaultBuildDir);
            $this->deleteDirectory($root);
        }
    }

    public function testExecutePatchThrowsWhenDefaultBuildPathIsFile(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $defaultBuildDir = $root . '/blocked-build';
        mkdir($root, 0777, true);

        file_put_contents($defaultBuildDir, 'blocked');

        try {
            $command = new BuildReleasePackageCommand($defaultBuildDir);
            $tester = new CommandTester($command);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not create output directory:');
            $tester->execute([
                '--type' => 'patch',
            ]);
        } finally {
            restore_error_handler();
            @unlink($defaultBuildDir);
            $this->deleteDirectory($root);
        }
    }

    public function testResolvePatchOutputDirectoryReturnsNullForExplicitFilePath(): void
    {
        $command = new BuildReleasePackageCommand();

        $result = $this->invokePrivate($command, 'resolvePatchOutputDirectory', 'build/sparkinsight-patch.zip', 'patch');

        $this->assertNull($result);
    }

    public function testResolvePatchOutputDirectoryReturnsNullWhenNotPatch(): void
    {
        $command = new BuildReleasePackageCommand();

        $result = $this->invokePrivate($command, 'resolvePatchOutputDirectory', '', 'full');

        $this->assertNull($result);
    }

    public function testResolveIncrementalPatchBasePackageReturnsLatestPatch(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        $buildDir = $root . '/build';
        mkdir($buildDir, 0777, true);
        file_put_contents($buildDir . '/sparkinsight-patch-000001.zip', 'x');
        file_put_contents($buildDir . '/sparkinsight-patch-000007.zip', 'x');

        try {
            $command = new BuildReleasePackageCommand();
            $result = $this->invokePrivate(
                $command,
                'resolveIncrementalPatchBasePackage',
                $buildDir,
                'patch',
                '',
                $buildDir . '/fallback.zip',
                false,
            );

            $expected = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $buildDir . '/sparkinsight-patch-000007.zip');
            $actual = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $result);
            $this->assertSame($expected, $actual);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testResolveIncrementalPatchBasePackageReturnsProvidedBaseWhenBaseRootSet(): void
    {
        $command = new BuildReleasePackageCommand();

        $result = $this->invokePrivate(
            $command,
            'resolveIncrementalPatchBasePackage',
            '',
            'patch',
            'explicit-base-root',
            'provided-base.zip',
            false,
        );

        $this->assertSame('provided-base.zip', $result);
    }

    public function testResolveIncrementalPatchBasePackageReturnsProvidedBaseWhenFromScratch(): void
    {
        $command = new BuildReleasePackageCommand();

        $result = $this->invokePrivate(
            $command,
            'resolveIncrementalPatchBasePackage',
            '',
            'patch',
            '',
            'provided-base.zip',
            true,
        );

        $this->assertSame('provided-base.zip', $result);
    }

    public function testClearPatchArtifactsRemovesPackageAndManifestFiles(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-build-command-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        file_put_contents($root . '/sparkinsight-patch-000001.zip', 'x');
        file_put_contents($root . '/sparkinsight-patch-000001.zip.manifest.json', '{}');
        file_put_contents($root . '/sparkinsight-patch-000001.zip.manifest.sig', 'sig');

        try {
            $command = new BuildReleasePackageCommand();
            $removed = $this->invokePrivate($command, 'clearPatchArtifacts', $root);

            $this->assertSame(1, $removed);
            $this->assertFileDoesNotExist($root . '/sparkinsight-patch-000001.zip');
            $this->assertFileDoesNotExist($root . '/sparkinsight-patch-000001.zip.manifest.json');
            $this->assertFileDoesNotExist($root . '/sparkinsight-patch-000001.zip.manifest.sig');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testClearPatchArtifactsReturnsZeroForMissingDirectory(): void
    {
        $command = new BuildReleasePackageCommand();

        $removed = $this->invokePrivate($command, 'clearPatchArtifacts', sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(8)));

        $this->assertSame(0, $removed);
    }

    public function testRemoveIfExistsReturnsWhenFileIsMissing(): void
    {
        $command = new BuildReleasePackageCommand();

        $this->invokePrivate($command, 'removeIfExists', sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(8)));

        $this->assertTrue(true);
    }

    private function createPrivateKeyFile(string $root): string
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $privateKeyPath = $root . '/private.key';
        file_put_contents($privateKeyPath, $secretKey);

        return $privateKeyPath;
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
