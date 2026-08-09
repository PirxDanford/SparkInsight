<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\BookPackageBuilder;
use SparkInsight\Service\BookPackageImportService;

final class BookPackageImportServiceTest extends TestCase
{
    public function testImportFromPackageImportsScrivenerHierarchyFromPackagePayload(): void
    {
        $tempRoot = sys_get_temp_dir() . '/sparkinsight-book-import-test-' . uniqid('', true);
        $projectDir = $tempRoot . '/sample.scriv';
        mkdir($projectDir . '/Files/Data/ROOT-BOOK', 0o755, true);
        mkdir($projectDir . '/Files/Data/CH01', 0o755, true);
        file_put_contents($projectDir . '/book.scrivx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ScrivenerProject>
  <Binder>
    <BinderItem UUID="ROOT-BOOK" Type="Folder">
      <Title>The Book</Title>
      <Children>
        <BinderItem UUID="FRONT-MATTER" Type="Folder">
          <Title>Front Matter</Title>
          <Children>
            <BinderItem UUID="ROOT-BOOK" Type="Text">
              <Title>Title page</Title>
            </BinderItem>
          </Children>
        </BinderItem>
        <BinderItem UUID="CH01" Type="Text">
          <Title>Chapter 1</Title>
        </BinderItem>
      </Children>
    </BinderItem>
  </Binder>
</ScrivenerProject>
XML
        );
        file_put_contents($projectDir . '/Files/Data/ROOT-BOOK/content.rtf', '{\\rtf1\\ansi\\deff0 Title page text}');
        file_put_contents($projectDir . '/Files/Data/CH01/content.rtf', '{\\rtf1\\ansi\\deff0 Chapter content}');

        $packagePath = $tempRoot . '/book-package.zip';
        $privateKeyPath = $tempRoot . '/private.key';
        $keyPair = sodium_crypto_sign_keypair();
        file_put_contents($privateKeyPath, sodium_crypto_sign_secretkey($keyPair));

        $builder = new BookPackageBuilder();
        $builder->buildBookPackage($packagePath, $projectDir, $privateKeyPath, 'Imported Book');

        $dbPath = $tempRoot . '/content.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(<<<'SQL'
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
    import_batch_id TEXT,
    imported_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL
        );

        $service = new BookPackageImportService($connection);
        $result = $service->importFromPackage($packagePath, 123);

        $this->assertSame('Imported Book', $result['book_title']);
        $this->assertSame(3, $result['imported']);

        $rows = $connection->fetchAllAssociative('SELECT title, status FROM content_versions ORDER BY title ASC');
        $this->assertCount(3, $rows);
        $this->assertSame('Chapter 1', $rows[0]['title']);
        $this->assertSame('ready', $rows[0]['status']);
        $this->assertSame('Front Matter', $rows[1]['title']);
        $this->assertSame('placeholder', $rows[1]['status']);
        $this->assertSame('Title page', $rows[2]['title']);
        $this->assertSame('ready', $rows[2]['status']);

        $connection->close();
        @unlink($dbPath);
        @unlink($packagePath);
        @unlink($privateKeyPath);
        @unlink($tempRoot . '/book-package.zip.manifest.json');
        @unlink($tempRoot . '/book-package.zip.manifest.sig');
        @unlink($projectDir . '/book.scrivx');
        @unlink($projectDir . '/Files/Data/ROOT-BOOK/content.rtf');
        @unlink($projectDir . '/Files/Data/CH01/content.rtf');
        @rmdir($projectDir . '/Files/Data/ROOT-BOOK');
        @rmdir($projectDir . '/Files/Data/CH01');
        @rmdir($projectDir . '/Files/Data');
        @rmdir($projectDir . '/Files');
        @rmdir($projectDir);
        @rmdir($tempRoot);
    }

      public function testImportFromPackageThrowsWhenPackageMissing(): void
      {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $service = new BookPackageImportService($connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Book package file not found:');
        $service->importFromPackage(sys_get_temp_dir() . '/missing-package-' . bin2hex(random_bytes(8)) . '.zip', 1);
      }

      public function testImportFromPackageThrowsWhenManifestIsMissing(): void
      {
        if (!class_exists(\ZipArchive::class)) {
          $this->markTestSkipped('ZipArchive is unavailable.');
        }

        $root = sys_get_temp_dir() . '/sparkinsight-book-import-test-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $packagePath = $root . '/broken.zip';

        $zip = new \ZipArchive();
        $zip->open($packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('payload/book-source/book.scrivx', '<ScrivenerProject/>');
        $zip->close();

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $service = new BookPackageImportService($connection);

        try {
          $this->expectException(\RuntimeException::class);
          $this->expectExceptionMessage('Book package is missing manifest.json.');
          $service->importFromPackage($packagePath, 1);
        } finally {
          @unlink($packagePath);
          @rmdir($root);
        }
      }
}
