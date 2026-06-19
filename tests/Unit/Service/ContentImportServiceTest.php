<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use SparkInsight\Service\ContentImportService;
use SparkInsight\Service\ImportValidationException;

final class ContentImportServiceTest extends TestCase
{
    private Connection $connection;
    private ContentImportService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new ContentImportService($this->connection);
    }

    public function testValidateFdxContentReturnsErrorsForEmptyContent(): void
    {
        $errors = $this->service->validateFdxContent('');

        $this->assertSame(['Import content is empty.'], $errors);
    }

    public function testValidateFdxContentReturnsErrorsForInvalidXml(): void
    {
        $errors = $this->service->validateFdxContent('<FDX><PROJECT></FDX>');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Invalid XML import content:', $errors[0]);
    }

    public function testValidateFdxContentReturnsErrorsForMissingTitle(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <TEXT>Some content</TEXT>
</FDX>
XML;

        $errors = $this->service->validateFdxContent($xml);

        $this->assertSame([
            'Import content must include a document title.',
        ], $errors);
    }

    public function testValidateFdxContentAcceptsFinalDraftRootAndParagraphNodes(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FinalDraft>
    <Content>
        <Paragraph Type="General"/>
    </Content>
</FinalDraft>
XML;

        $errors = $this->service->validateFdxContent($xml, 'Notes/Example.fdx');

        $this->assertSame([], $errors);
    }

    public function testImportFdxContentInsertsContentVersionAndReturnsId(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>My Imported Document</NAME>
    </PROJECT>
    <TEXT>First paragraph</TEXT>
</FDX>
XML;

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('0');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT COUNT(*) FROM content_versions WHERE title = ? AND version_label = ?', ['My Imported Document', 'v1'])
            ->willReturn($resultMock);

        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(static function (string $sql) {
                    return str_contains($sql, 'INSERT INTO content_versions') && str_contains($sql, 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                }),
                $this->callback(static function (array $params) {
                    return $params[0] === 'My Imported Document'
                        && $params[1] === 'v1'
                        && $params[3] === 123
                        && $params[4] === 'ready';
                })
            )
            ->willReturn(1);

        $this->connection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('42');

        $this->connection->expects($this->once())
            ->method('commit');

        $contentVersionId = $this->service->importFdxContent($xml, 'v1', 123, 'fdx-import');

        $this->assertSame(42, $contentVersionId);
    }

    public function testImportFdxContentThrowsExceptionForDuplicateVersion(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>My Imported Document</NAME>
    </PROJECT>
    <TEXT>First paragraph</TEXT>
</FDX>
XML;

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('1');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($resultMock);

        $this->expectException(ImportValidationException::class);
        $this->expectExceptionMessage('Import validation failed.');

        try {
            $this->service->importFdxContent($xml, 'v1', 123, 'fdx-import');
        } catch (ImportValidationException $exception) {
            $this->assertSame(['A content version with this title and version label already exists.'], $exception->getErrors());
            throw $exception;
        }
    }

    public function testImportFdxContentUsesSourceAsTitleFallbackWhenMissingTitle(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FinalDraft>
    <Content>
        <Paragraph Type="General"/>
    </Content>
</FinalDraft>
XML;

        $resultMock = $this->createMock(Result::class);
        $resultMock->method('fetchOne')->willReturn('0');

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT COUNT(*) FROM content_versions WHERE title = ? AND version_label = ?', ['Sample Chapter', 'v1'])
            ->willReturn($resultMock);

        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(static function (string $sql) {
                    return str_contains($sql, 'INSERT INTO content_versions');
                }),
                $this->callback(static function (array $params) {
                    return $params[0] === 'Sample Chapter'
                        && $params[1] === 'v1'
                        && $params[3] === 123;
                })
            )
            ->willReturn(1);

        $this->connection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('9');

        $this->connection->expects($this->once())
            ->method('commit');

        $id = $this->service->importFdxContent($xml, 'v1', 123, 'Notes/Sample Chapter.fdx');

        $this->assertSame(9, $id);
    }

    public function testRollbackImportArchivesExistingVersion(): void
    {
        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE content_versions SET status = ?, updated_at = ? WHERE id = ? AND status != ?',
                $this->callback(static function (array $params) {
                    return $params[0] === 'archived'
                        && is_string($params[1])
                        && $params[2] === 42
                        && $params[3] === 'archived';
                })
            )
            ->willReturn(1);

        $this->connection->expects($this->once())
            ->method('commit');

        $result = $this->service->rollbackImport(42);

        $this->assertTrue($result);
    }

    public function testRollbackImportReturnsFalseWhenContentVersionDoesNotExist(): void
    {
        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE content_versions SET status = ?, updated_at = ? WHERE id = ? AND status != ?',
                $this->callback(static function (array $params) {
                    return $params[0] === 'archived'
                        && is_string($params[1])
                        && $params[2] === 99
                        && $params[3] === 'archived';
                })
            )
            ->willReturn(0);

        $this->connection->expects($this->once())
            ->method('commit');

        $result = $this->service->rollbackImport(99);

        $this->assertFalse($result);
    }

    public function testImportFdxDirectoryImportsAllFdxFilesRecursively(): void
    {
        $directory = sys_get_temp_dir() . '/sparkinsight_scrivener_' . bin2hex(random_bytes(8));
        mkdir($directory . '/Notes', 0777, true);

        file_put_contents($directory . '/RootDoc.fdx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>Root Document</NAME>
    </PROJECT>
    <TEXT>Root paragraph</TEXT>
</FDX>
XML
        );

        file_put_contents($directory . '/Notes/ChildDoc.fdx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FDX>
    <PROJECT>
        <NAME>Child Document</NAME>
    </PROJECT>
    <TEXT>Child paragraph</TEXT>
</FDX>
XML
        );

        $dbPath = sys_get_temp_dir() . '/sparkinsight_import_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(<<<'SQL'
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

        $service = new ContentImportService($connection);
        $result = $service->importFdxDirectory($directory, 123);

        $this->assertSame(2, $result['scanned']);
        $this->assertCount(2, $result['imported']);
        $this->assertEmpty($result['failed']);
        $this->assertSame('RootDoc', $result['imported']['RootDoc.fdx']['version_label']);
        $this->assertSame('Notes/ChildDoc', $result['imported']['Notes/ChildDoc.fdx']['version_label']);
        $this->assertContains($result['imported']['RootDoc.fdx']['id'], [1, 2]);
        $this->assertContains($result['imported']['Notes/ChildDoc.fdx']['id'], [1, 2]);
        $this->assertNotSame($result['imported']['RootDoc.fdx']['id'], $result['imported']['Notes/ChildDoc.fdx']['id']);

        $row = $connection->fetchOne('SELECT COUNT(*) FROM content_versions');
        $this->assertSame(2, (int) $row);

        $connection->close();
        unlink($dbPath);
        unlink($directory . '/RootDoc.fdx');
        unlink($directory . '/Notes/ChildDoc.fdx');
        rmdir($directory . '/Notes');
        rmdir($directory);
    }

    public function testImportFdxDirectoryReportsFailedFiles(): void
    {
        $directory = sys_get_temp_dir() . '/sparkinsight_scrivener_' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);

        file_put_contents($directory . '/InvalidDoc.fdx', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<NotFdx>
    <Body>Unsupported structure</Body>
</NotFdx>
XML
        );

        $dbPath = sys_get_temp_dir() . '/sparkinsight_import_' . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $connection->executeStatement(<<<'SQL'
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

        $service = new ContentImportService($connection);
        $result = $service->importFdxDirectory($directory, 123);

        $this->assertSame(1, $result['scanned']);
        $this->assertEmpty($result['imported']);
        $this->assertArrayHasKey('InvalidDoc.fdx', $result['failed']);
        $this->assertContains('Import content must be a Scrivener/Final Draft XML file with a root <FDX> or <FinalDraft> element.', $result['failed']['InvalidDoc.fdx']);

        $connection->close();
        unlink($dbPath);
        unlink($directory . '/InvalidDoc.fdx');
        rmdir($directory);
    }
}
