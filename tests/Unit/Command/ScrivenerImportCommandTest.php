<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Command;

use PHPUnit\Framework\TestCase;
use SparkInsight\Command\ScrivenerImportCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class ScrivenerImportCommandTest extends TestCase
{
    private string $dbFile;
    private string $scrivenerDir;

    protected function setUp(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_USER'] = '';
        $_ENV['DB_PASSWORD'] = '';
        $_ENV['DB_CHARSET'] = 'utf8mb4';

        $this->dbFile = sys_get_temp_dir() . '/sparkinsight_db_' . bin2hex(random_bytes(8)) . '.sqlite';
        $_ENV['DB_NAME'] = $this->dbFile;

        $this->scrivenerDir = sys_get_temp_dir() . '/sparkinsight_scrivener_' . bin2hex(random_bytes(8));
        mkdir($this->scrivenerDir, 0777, true);

        file_put_contents($this->scrivenerDir . '/ImportDoc.fdx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>Imported Document</NAME>
    </PROJECT>
    <TEXT>Import paragraph</TEXT>
</FDX>
XML
        );

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    version_label TEXT NOT NULL,
    source TEXT,
    author_id INTEGER,
    status TEXT NOT NULL,
    metadata TEXT,
    imported_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL
        );
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV']);
        unset($_ENV['APP_URL']);
        unset($_ENV['DB_DRIVER']);
        unset($_ENV['DB_NAME']);
        unset($_ENV['DB_USER']);
        unset($_ENV['DB_PASSWORD']);
        unset($_ENV['DB_CHARSET']);

        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }

        $files = glob($this->scrivenerDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        if (is_dir($this->scrivenerDir)) {
            rmdir($this->scrivenerDir);
        }
    }

    public function testCommandImportsScrivenerFdxFiles(): void
    {
        $command = new ScrivenerImportCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--directory' => $this->scrivenerDir,
            '--author-id' => '1',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Imported files: 1', $tester->getDisplay());
        $this->assertStringContainsString('Scrivener FDX import completed successfully.', $tester->getDisplay());

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM content_versions')->fetchColumn();
        $this->assertSame(1, $count);

        $versionLabel = $pdo->query("SELECT version_label FROM content_versions LIMIT 1")->fetchColumn();
        $this->assertSame('ImportDoc', $versionLabel);
    }
}
