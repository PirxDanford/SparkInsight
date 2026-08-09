<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\CurrentPointerStore;

final class CurrentPointerStoreTest extends TestCase
{
    public function testReadDefaultsWhenPointerMissing(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-pointer-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $store = new CurrentPointerStore($root);
            $pointer = $store->read();

            $this->assertNull($pointer['current']);
            $this->assertNull($pointer['previous']);
            $this->assertSame(1, $pointer['format']);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteAndReadPointerState(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-pointer-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $store = new CurrentPointerStore($root);
            $store->write('release-new', 'release-old', 'op-42');
            $pointer = $store->read();

            $this->assertSame('release-new', $pointer['current']);
            $this->assertSame('release-old', $pointer['previous']);
            $this->assertSame('op-42', $pointer['operation_id']);

            $this->assertFileExists($root . '/.deploy/current.json');
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteReplacesExistingPointerState(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-pointer-' . bin2hex(random_bytes(8));
        mkdir($root);

        try {
            $store = new CurrentPointerStore($root);
            $store->write('release-a', null, 'op-1');
            $store->write('release-b', 'release-a', 'op-2');

            $pointer = $store->read();
            $this->assertSame('release-b', $pointer['current']);
            $this->assertSame('release-a', $pointer['previous']);
            $this->assertSame('op-2', $pointer['operation_id']);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testReadThrowsForInvalidPointerDocument(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-pointer-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy', 0777, true);
        file_put_contents($root . '/.deploy/current.json', '{invalid json');

        try {
            $store = new CurrentPointerStore($root);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid current pointer document');
            $store->read();
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteThrowsWhenPointerCannotBeEncoded(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-pointer-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        try {
            $store = new CurrentPointerStore($root);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not encode current pointer document.');
            $store->write("\xB1\x31", null, null);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function testWriteThrowsWhenTemporaryPointerDocumentCannotBeWritten(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-pointer-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/current.json.tmp', 0777, true);

        try {
            $store = new CurrentPointerStore($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not write temporary current pointer document:');
            $store->write('release-a', null, 'op-1');
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testWriteThrowsWhenPointerDocumentCannotBeMovedIntoPlace(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-pointer-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy/current.json', 0777, true);

        try {
            $store = new CurrentPointerStore($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not move current pointer document into place:');
            $store->write('release-a', null, 'op-1');
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testWriteThrowsWhenDeployDirectoryCannotBeCreated(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-pointer-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        file_put_contents($root . '/.deploy', 'blocked');

        try {
            $store = new CurrentPointerStore($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not create directory:');
            $store->write('release-a', null, 'op-1');
        } finally {
            restore_error_handler();
            $this->deleteDirectory($root);
        }
    }

    public function testWriteThrowsWhenExistingPointerCannotBeRemoved(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-pointer-' . bin2hex(random_bytes(8));
        mkdir($root . '/.deploy', 0777, true);
        $pointerPath = $root . '/.deploy/current.json';
        file_put_contents($pointerPath, '{"format":1}');
        chmod($pointerPath, 0444);

        try {
            $store = new CurrentPointerStore($root);
            set_error_handler(static fn (): bool => true);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not replace current pointer document:');
            $store->write('release-a', null, 'op-1');
        } finally {
            restore_error_handler();
            @chmod($pointerPath, 0666);
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