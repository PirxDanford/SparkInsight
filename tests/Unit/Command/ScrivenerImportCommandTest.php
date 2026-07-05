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
    private string $projectDir;

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
        $this->projectDir = $this->scrivenerDir . '/book.scriv';
        mkdir($this->projectDir . '/Files/Data/A1111111-1111-1111-1111-111111111111', 0777, true);

        file_put_contents($this->projectDir . '/book.scrivx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ScrivenerProject>
    <Binder>
        <BinderItem UUID="ROOT-BOOK" Type="Folder">
            <Title>The Book</Title>
            <Children>
                <BinderItem UUID="F1111111-1111-1111-1111-111111111111" Type="Folder">
                    <Title>Front Matter</Title>
                    <Children>
                        <BinderItem UUID="A1111111-1111-1111-1111-111111111111" Type="Text">
                            <Title>Title page</Title>
                        </BinderItem>
                    </Children>
                </BinderItem>
            </Children>
        </BinderItem>
    </Binder>
</ScrivenerProject>
XML
        );

        file_put_contents(
            $this->projectDir . '/Files/Data/A1111111-1111-1111-1111-111111111111/content.rtf',
            '{\\rtf1\\ansi\\deff0 Title page text\\par Import paragraph}'
        );

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    book_title TEXT,
    version_label TEXT NOT NULL,
    source TEXT,
    content_rtf TEXT,
    content_text TEXT,
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
                if (is_file($file) || is_link($file)) {
                    unlink($file);
                    continue;
                }

                if (is_dir($file)) {
                    $this->deleteDirectoryRecursively($file);
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
            '--book-title' => 'My Test Book',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Book title: My Test Book', $tester->getDisplay());
        $this->assertStringContainsString('Scanned projects: 1', $tester->getDisplay());
        $this->assertStringContainsString('Imported items: 2', $tester->getDisplay());
        $this->assertStringContainsString('Scrivener backup import completed successfully.', $tester->getDisplay());

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM content_versions')->fetchColumn();
        $this->assertSame(2, $count);

        $versionLabel = (string) $pdo->query("SELECT version_label FROM content_versions WHERE title = 'Title page' LIMIT 1")->fetchColumn();
        $this->assertStringContainsString('The Book/Front Matter/Title page', $versionLabel);

        $bookTitle = $pdo->query("SELECT book_title FROM content_versions LIMIT 1")->fetchColumn();
        $this->assertSame('My Test Book', $bookTitle);
    }

    private function deleteDirectoryRecursively(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteDirectoryRecursively($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
