<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use SparkInsight\Command\BuildBookPackageCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildBookPackageCommandTest extends TestCase
{
    private function invokePrivate(BuildBookPackageCommand $command, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($command, $method);

        return $reflection->invoke($command, ...$args);
    }

    public function testCommandNameAndDescription(): void
    {
        $command = new BuildBookPackageCommand();

        $this->assertSame('book:package', $command->getName());
        $this->assertStringContainsString('Build a signed book import package', (string) $command->getDescription());
    }

    public function testExecuteFailsWithoutPrivateKey(): void
    {
        $command = new BuildBookPackageCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The --private-key option is required.', $tester->getDisplay());
    }

    public function testExecuteBuildsPackageSuccessfully(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-book-package-command-' . bin2hex(random_bytes(8));
        $sourceRoot = $root . '/sample.scriv';
        $outputDir = $root . '/build';
        mkdir($sourceRoot . '/Files/Data/ROOT-BOOK', 0777, true);
        mkdir($outputDir, 0777, true);

        file_put_contents($sourceRoot . '/book.scrivx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ScrivenerProject>
  <Binder>
    <BinderItem UUID="ROOT-BOOK" Type="Folder">
      <Title>The Book</Title>
      <Children>
        <BinderItem UUID="CH01" Type="Text">
          <Title>Chapter 1</Title>
        </BinderItem>
      </Children>
    </BinderItem>
  </Binder>
</ScrivenerProject>
XML
        );
        file_put_contents($sourceRoot . '/Files/Data/ROOT-BOOK/content.rtf', '{\\rtf1\\ansi\\deff0 Hello}');

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildBookPackageCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'package-path' => $outputDir,
                '--source-root' => $sourceRoot,
                '--private-key' => $privateKeyPath,
                '--book-title' => 'Test Book',
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('Book package built successfully:', $tester->getDisplay());

            $files = glob($outputDir . '/book-package-*.zip') ?: [];
            $this->assertCount(1, $files);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testExecuteReturnsFailureWhenBuilderThrows(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium support is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-book-package-command-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $privateKeyPath = $this->createPrivateKeyFile($root);
            $command = new BuildBookPackageCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--private-key' => $privateKeyPath,
                '--source-root' => $root . '/missing-source',
            ]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Book source root does not exist:', $tester->getDisplay());
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testResolvePackagePathReturnsNormalizedExplicitFilePath(): void
    {
        $command = new BuildBookPackageCommand();
        $path = $this->invokePrivate($command, 'resolvePackagePath', 'folder/sub/explicit.zip');

        $this->assertStringEndsWith('folder' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'explicit.zip', $path);
    }

    public function testResolvePackagePathReturnsDefaultBuildPathWhenArgumentEmpty(): void
    {
        $command = new BuildBookPackageCommand();

        $path = $this->invokePrivate($command, 'resolvePackagePath', '');

        $this->assertStringContainsString('build' . DIRECTORY_SEPARATOR . 'book-package-', $path);
        $this->assertStringEndsWith('.zip', $path);
    }

    public function testResolvePackagePathThrowsWhenDefaultBuildPathCannotBeCreated(): void
    {
        $command = new BuildBookPackageCommand();

        $root = sys_get_temp_dir() . '/sparkinsight-book-package-command-blocked-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $expectedBuildDir = realpath(__DIR__ . '/../../../src/Command/../../build');
        if ($expectedBuildDir !== false && is_dir($expectedBuildDir)) {
            $this->markTestSkipped('Repository build directory exists; cannot force default build creation failure safely.');
        }

        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not create output directory:');
            $this->invokePrivate($command, 'resolvePackagePath', '');
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
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