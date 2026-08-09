<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DeployMirrorTest extends TestCase
{
    public function testSyncWritesManifestAndTimestampedChangesHistory(): void
    {
        $tempRoot = sys_get_temp_dir() . '/sparkinsight-deploy-test-' . uniqid('', true);
        $repoRoot = $tempRoot . '/repo';
        $targetDir = $tempRoot . '/target';
        $stateDir = $tempRoot . '/state';

        $this->createFixtureRepo($repoRoot);
        mkdir($targetDir, 0777, true);
        mkdir($stateDir, 0777, true);

        file_put_contents($stateDir . '/deploy-status.json', '{"legacy":true}');
        file_put_contents($stateDir . '/deploy-manifest.json', '{"legacy":true}');
        file_put_contents($stateDir . '/deploy-state.json', '{"legacy":true}');
        file_put_contents($stateDir . '/deploy-changes.txt', 'legacy');

        require_once dirname(__DIR__, 2) . '/tools/deploy/deploy.php';

        $deploy = new DeployMirror($repoRoot, $targetDir, $stateDir, false);
        $exitCode = $deploy->run('sync');

        $this->assertSame(0, $exitCode);

        $this->assertFileExists($stateDir . '/manifest.json');
        $manifest = json_decode((string) file_get_contents($stateDir . '/manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('files', $manifest);
        $this->assertArrayHasKey('report', $manifest);
        $this->assertArrayHasKey('is_clean', $manifest['report']);
        $this->assertIsBool($manifest['report']['is_clean']);

        $changeFiles = glob($stateDir . '/changes-*.json');
        $this->assertNotEmpty($changeFiles);

        $history = json_decode((string) file_get_contents($changeFiles[0]), true);
        $this->assertIsArray($history);
        $this->assertArrayHasKey('changes', $history);
        $this->assertArrayHasKey('report', $history);

        $this->assertFileDoesNotExist($stateDir . '/deploy-status.json');
        $this->assertFileDoesNotExist($stateDir . '/deploy-manifest.json');
        $this->assertFileDoesNotExist($stateDir . '/deploy-state.json');
        $this->assertFileDoesNotExist($stateDir . '/deploy-changes.txt');
    }

    public function testNoOpSyncDoesNotWriteChangesHistoryAndKeepsMetadataNonEmpty(): void
    {
        $tempRoot = sys_get_temp_dir() . '/sparkinsight-deploy-test-' . uniqid('', true);
        $repoRoot = $tempRoot . '/repo';
        $targetDir = $tempRoot . '/target';
        $stateDir = $tempRoot . '/state';

        $this->createFixtureRepo($repoRoot);
        mkdir($targetDir, 0777, true);
        mkdir($stateDir, 0777, true);

        require_once dirname(__DIR__, 2) . '/tools/deploy/deploy.php';

        $deploy = new DeployMirror($repoRoot, $targetDir, $stateDir, false);
        $this->assertSame(0, $deploy->run('sync'));

        $initialChangeFiles = glob($stateDir . '/changes-*.json');
        $this->assertNotEmpty($initialChangeFiles);

        $this->assertSame(0, $deploy->run('sync'));

        $changeFilesAfterSecondSync = glob($stateDir . '/changes-*.json');
        $this->assertSame([], $changeFilesAfterSecondSync);

        $manifest = json_decode((string) file_get_contents($stateDir . '/manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertNotSame('', (string) ($manifest['source_lock_hash'] ?? ''));
        $this->assertNotSame('', (string) ($manifest['report']['source_lock_hash'] ?? ''));
        $this->assertNotSame('', (string) ($manifest['report']['target_lock_hash'] ?? ''));
        $this->assertNotSame('', (string) ($manifest['report']['source_commit'] ?? ''));
        $this->assertNotSame('', (string) ($manifest['report']['target_commit'] ?? ''));
    }

    private function createFixtureRepo(string $repoRoot): void
    {
        $this->createDirectory($repoRoot . '/public');
        $this->createDirectory($repoRoot . '/src');
        $this->createDirectory($repoRoot . '/templates');
        $this->createDirectory($repoRoot . '/resources');
        $this->createDirectory($repoRoot . '/database');

        file_put_contents($repoRoot . '/index.php', "<?php\n");
        file_put_contents($repoRoot . '/si.php', "<?php\n");
        file_put_contents($repoRoot . '/LICENSE', "fixture\n");
        file_put_contents($repoRoot . '/composer.json', "{}\n");
        file_put_contents($repoRoot . '/composer.lock', "{}\n");
        file_put_contents($repoRoot . '/public/index.php', "<?php\n");
        file_put_contents($repoRoot . '/src/Demo.php', "<?php\n\nfinal class Demo {}\n");
        file_put_contents($repoRoot . '/templates/demo.php', "<p>demo</p>\n");
    }

    private function createDirectory(string $path): void
    {
        mkdir($path, 0777, true);
    }
}
