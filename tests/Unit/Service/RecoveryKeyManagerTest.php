<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\RecoveryKeyManager;

final class RecoveryKeyManagerTest extends TestCase
{
    public function testEnsureInitializedCreatesKeyOnlyOnce(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $manager = new RecoveryKeyManager($root);
            $first = $manager->ensureInitialized();
            $second = $manager->ensureInitialized();

            $this->assertIsString($first);
            $this->assertNotSame('', $first);
            $this->assertNull($second);
            $this->assertFileExists($root . '/.deploy/recovery.json');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testVerifyReturnsFalseWhenStateIsMissing(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $manager = new RecoveryKeyManager($root);
            $this->assertFalse($manager->verify('anything'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testVerifyReturnsTrueForValidKeyAndIncrementsAttempts(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $manager = new RecoveryKeyManager($root);
            $plain = (string) $manager->ensureInitialized();

            $this->assertTrue($manager->verify($plain));
            $this->assertFalse($manager->verify($plain . '-invalid'));

            $state = $manager->readState();
            $this->assertIsArray($state);
            $this->assertSame(2, (int) ($state['attempts'] ?? 0));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testReadStateReturnsNullForInvalidJson(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy', 0777, true);
        file_put_contents($root . '/.deploy/recovery.json', '{bad json');

        try {
            $manager = new RecoveryKeyManager($root);
            $this->assertNull($manager->readState());
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testVerifyReturnsFalseWhenStateShapeIsInvalid(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy', 0777, true);
        file_put_contents($root . '/.deploy/recovery.json', json_encode([
            'format' => 1,
            'attempts' => 2,
            'updated_at' => date(DATE_ATOM),
        ]));

        try {
            $manager = new RecoveryKeyManager($root);
            $this->assertFalse($manager->verify('anything'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteStatePersistsProvidedValues(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $manager = new RecoveryKeyManager($root);
            $hash = password_hash('my-key', PASSWORD_DEFAULT);
            $manager->writeState($hash, 7);

            $state = $manager->readState();
            $this->assertIsArray($state);
            $this->assertSame($hash, (string) ($state['key_hash'] ?? ''));
            $this->assertSame(7, (int) ($state['attempts'] ?? -1));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteStateThrowsWhenDeployDirectoryCannotBeCreated(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        file_put_contents($root . '/.deploy', 'blocking file');

        try {
            $manager = new RecoveryKeyManager($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not create directory:');
            $manager->writeState('hash', 1);
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testWriteStateThrowsWhenStateCannotBeJsonEncoded(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $manager = new RecoveryKeyManager($root);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not encode recovery state.');
            $manager->writeState("\xB1\x31", 1);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteStateThrowsWhenRecoveryTargetPathIsDirectory(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/recovery.json', 0777, true);

        try {
            $manager = new RecoveryKeyManager($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not store recovery state.');
            $manager->writeState('hash', 1);
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testWriteStateThrowsWhenTemporaryFileCannotBeWritten(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-recovery-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/recovery.json.tmp', 0777, true);

        try {
            $manager = new RecoveryKeyManager($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not write recovery state.');
            $manager->writeState('hash', 1);
        } finally {
            restore_error_handler();
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
