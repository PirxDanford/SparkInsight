<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\BookPackageBuilder;

final class BookPackageBuilderTest extends TestCase
{
  private function invokePrivate(BookPackageBuilder $builder, string $method, mixed ...$args): mixed
  {
    $reflection = new \ReflectionMethod($builder, $method);

    return $reflection->invoke($builder, ...$args);
  }

    public function testBuildBookPackageCreatesSignedArchiveWithImportManifest(): void
    {
        $tempRoot = sys_get_temp_dir() . '/sparkinsight-book-package-test-' . uniqid('', true);
        $projectDir = $tempRoot . '/sample.scriv';
        mkdir($projectDir . '/Files/Data/ROOT-BOOK', 0o755, true);
        file_put_contents($projectDir . '/book.scrivx', <<<'XML'
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
        file_put_contents($projectDir . '/Files/Data/ROOT-BOOK/content.rtf', '{\\rtf1\\ansi\\deff0 {\\colortbl ;}\nHello world\n}');

        $packagePath = $tempRoot . '/book-package.zip';
        $privateKeyPath = $tempRoot . '/private.key';
        $keyPair = sodium_crypto_sign_keypair();
        file_put_contents($privateKeyPath, sodium_crypto_sign_secretkey($keyPair));

        $builder = new BookPackageBuilder();
        $result = $builder->buildBookPackage($packagePath, $projectDir, $privateKeyPath, 'Test Book');

        $this->assertFileExists($packagePath);
        $this->assertFileExists($result['manifest_path']);
        $this->assertFileExists($result['signature_path']);

        $manifestJson = (string) file_get_contents($result['manifest_path']);
        $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('book', $manifest['package_type']);
        $this->assertSame('Test Book', $manifest['book_title']);
        $this->assertArrayNotHasKey('version_label_prefix', $manifest);
        $this->assertSame('scrivener', $manifest['source_format']);
        $this->assertNotEmpty($manifest['import_preview']['imported']);
        $this->assertSame('The Book/Chapter 1', $manifest['import_preview']['imported'][0]['path']);
        $this->assertSame([
            'payload/book-source/Files/Data/ROOT-BOOK/content.rtf',
            'payload/book-source/book.scrivx',
        ], $manifest['payload_files']);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($packagePath) === true);
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertNotFalse($zip->locateName('payload/book-source/book.scrivx'));
        $zip->close();

        @unlink($privateKeyPath);
        @unlink($packagePath);
        @unlink($result['manifest_path']);
        @unlink($result['signature_path']);
        @unlink($projectDir . '/book.scrivx');
        @unlink($projectDir . '/Files/Data/ROOT-BOOK/content.rtf');
        @rmdir($projectDir . '/Files/Data/ROOT-BOOK');
        @rmdir($projectDir . '/Files/Data');
        @rmdir($projectDir . '/Files');
        @rmdir($projectDir);
        @rmdir($tempRoot);
    }

      public function testBuildBookPackageThrowsWhenSourceDirectoryMissing(): void
      {
        $builder = new BookPackageBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Book source root does not exist:');
        $builder->buildBookPackage(
          sys_get_temp_dir() . '/book-package.zip',
          sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(4)),
          sys_get_temp_dir() . '/private.key',
          'Book',
        );
      }

      public function testExtractRootTitleReturnsNullWhenBookNodeMissing(): void
      {
        $builder = new BookPackageBuilder();
        $xml = simplexml_load_string('<ScrivenerProject><Binder></Binder></ScrivenerProject>');

        $this->assertNull($this->invokePrivate($builder, 'extractRootTitle', $xml));
      }

      public function testFindScrivxFindsNestedScrivxFile(): void
      {
        $root = sys_get_temp_dir() . '/sparkinsight-book-builder-' . bin2hex(random_bytes(8));
        mkdir($root . '/nested/deeper', 0777, true);
        file_put_contents($root . '/nested/deeper/project.scrivx', '<ScrivenerProject/>');

        try {
          $builder = new BookPackageBuilder();
          $found = $this->invokePrivate($builder, 'findScrivx', $root);

          $this->assertSame($root . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'deeper' . DIRECTORY_SEPARATOR . 'project.scrivx', $found);
        } finally {
          @unlink($root . '/nested/deeper/project.scrivx');
          @rmdir($root . '/nested/deeper');
          @rmdir($root . '/nested');
          @rmdir($root);
        }
      }

      public function testReadBinaryFileThrowsWhenFileMissing(): void
      {
        $builder = new BookPackageBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Private key not found:');
        $this->invokePrivate($builder, 'readBinaryFile', sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(4)), 'private key');
      }
}
