<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\DeploymentStateStore;

final class DeploymentStateStoreTest extends TestCase
{
    public function testReadReturnsNullWhenStateIsMissing(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $store = new DeploymentStateStore($root);
            $this->assertNull($store->read('op-001'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteReadAndAppendAuditEvents(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $store = new DeploymentStateStore($root);
            $operationId = 'op-001';

            $store->write($operationId, [
                'operation_id' => $operationId,
                'state' => 'uploaded',
                'progress' => 1,
            ]);
            $store->appendAuditEvent($operationId, [
                'event' => 'state_written',
                'operation_id' => $operationId,
            ]);

            $store->write($operationId, [
                'operation_id' => $operationId,
                'state' => 'deployed',
                'progress' => 2,
            ]);

            $state = $store->read($operationId);
            $this->assertIsArray($state);
            $this->assertSame('deployed', $state['state']);
            $this->assertSame(2, $state['progress']);

            $statePath = $root . '/.deploy/state/' . $operationId . '.json';
            $auditPath = $root . '/.deploy/logs/' . $operationId . '.jsonl';

            $this->assertFileExists($statePath);
            $this->assertFileExists($auditPath);
            $this->assertStringContainsString('state_written', (string) file_get_contents($auditPath));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testReadThrowsWhenStateJsonIsInvalid(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/state', 0777, true);
        file_put_contents($root . '/.deploy/state/op-001.json', '{bad json');

        try {
            $store = new DeploymentStateStore($root);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Stored deployment state is invalid');
            $store->read('op-001');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testRejectsInvalidOperationIds(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $store = new DeploymentStateStore($root);

            $this->expectException(\RuntimeException::class);
            $store->write('../evil', ['state' => 'uploaded']);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testRejectsEmptyOperationId(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $store = new DeploymentStateStore($root);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Operation ID must not be empty.');
            $store->read('   ');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteThrowsWhenStateCannotBeEncoded(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $store = new DeploymentStateStore($root);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not encode deployment state.');
            $store->write('op-001', ['bad' => "\xB1\x31"]);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteThrowsWhenTemporaryStateCannotBeWritten(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/state/op-001.json.tmp', 0777, true);

        try {
            $store = new DeploymentStateStore($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not write temporary deployment state:');
            $store->write('op-001', ['state' => 'uploaded']);
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testWriteThrowsWhenStateCannotBeMovedIntoPlace(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/state/op-001.json', 0777, true);

        try {
            $store = new DeploymentStateStore($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not move deployment state into place:');
            $store->write('op-001', ['state' => 'uploaded']);
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testAppendAuditEventThrowsWhenEventCannotBeEncoded(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $store = new DeploymentStateStore($root);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not encode audit event.');
            $store->appendAuditEvent('op-001', ['event' => "\xB1\x31"]);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testAppendAuditEventThrowsWhenAuditFileCannotBeWritten(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/logs/op-001.jsonl', 0777, true);

        try {
            $store = new DeploymentStateStore($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not append deployment audit event:');
            $store->appendAuditEvent('op-001', ['event' => 'state_written']);
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testWriteThrowsWhenStateDirectoryCannotBeCreated(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-deploy-state-' . bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root . '/.deploy', 'blocked');

        try {
            $store = new DeploymentStateStore($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not create directory:');
            $store->write('op-001', ['state' => 'uploaded']);
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