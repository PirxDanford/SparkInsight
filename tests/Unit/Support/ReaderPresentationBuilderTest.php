<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Support;

use PHPUnit\Framework\TestCase;
use SparkInsight\Support\ReaderPresentationBuilder;

final class ReaderPresentationBuilderTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkinsight-reader-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testBuildPresentationLoadsScrivenerSourceFromMetadataAndRestoresLinks(): void
    {
        $uuid = 'ABCDEF12-3456-7890-ABCD-EF1234567890';
        $contentPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'scrivener' . DIRECTORY_SEPARATOR . 'Files' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . $uuid;
        mkdir($contentPath, 0777, true);

        $rtf = <<<'RTF'
{\rtf1\ansi
    {\fonttbl{\f0\fnil\fcharset0 Sitka Text;}}
{\field{\*\fldinst HYPERLINK "https://example.com"}{\fldrslt Example Link}}\par
First paragraph of imported content.\par
Second paragraph follows.
}
RTF;

        file_put_contents($contentPath . DIRECTORY_SEPARATOR . 'content.rtf', $rtf);

        $metadata = json_encode([
            'scrivener' => [
                'project_file' => 'scrivener/project.scrivx',
                'uuid' => $uuid,
            ],
        ], JSON_THROW_ON_ERROR);

        $builder = new ReaderPresentationBuilder($this->projectRoot);

        $presentation = $builder->buildPresentation('', '', '', $metadata);

        $this->assertTrue((bool) $presentation['available']);
        $this->assertSame('content.rtf', $presentation['source_label']);
        $this->assertSame(3, $presentation['paragraph_count']);
        $this->assertGreaterThanOrEqual(6, (int) $presentation['word_count']);
        $this->assertCount(3, $presentation['sections']);
        $this->assertSame('Example Link', $presentation['sections'][0]['text']);
        $this->assertSame('First paragraph of imported content.', $presentation['sections'][1]['text']);
        $this->assertStringNotContainsString('Sitka Text', implode(' ', array_map(static fn (array $section): string => (string) ($section['text'] ?? ''), $presentation['sections'])));
        $this->assertStringContainsString('<a href="https://example.com"', (string) $presentation['html']);
        $this->assertStringContainsString('Example Link', (string) $presentation['html']);
    }

    public function testBuildPresentationUsesStoredPlainTextWhenAvailable(): void
    {
        $builder = new ReaderPresentationBuilder($this->projectRoot);

        $presentation = $builder->buildPresentation(" First paragraph\n\nSecond paragraph ", '', '', null);

        $this->assertTrue((bool) $presentation['available']);
        $this->assertSame('Stored import content', $presentation['source_label']);
        $this->assertNull($presentation['html']);
        $this->assertSame(2, $presentation['paragraph_count']);
        $this->assertSame(4, $presentation['word_count']);
        $this->assertCount(2, $presentation['sections']);
        $this->assertSame('First paragraph', $presentation['sections'][0]['text']);
        $this->assertSame('Second paragraph', $presentation['sections'][1]['text']);
    }

    public function testBuildPresentationReturnsUnavailableWhenSourceCannotBeResolved(): void
    {
        $builder = new ReaderPresentationBuilder($this->projectRoot);

        $presentation = $builder->buildPresentation('', '', '../outside.rtf', null);

        $this->assertFalse((bool) $presentation['available']);
        $this->assertSame('../outside.rtf', $presentation['source_label']);
        $this->assertNull($presentation['html']);
        $this->assertSame([], $presentation['sections']);
        $this->assertSame(0, $presentation['paragraph_count']);
        $this->assertSame(0, $presentation['word_count']);
        $this->assertSame('Original source file is currently unavailable for this item.', $presentation['notice']);
    }

    public function testBuildPresentationLoadsSectionTextFromXmlParagraphNodes(): void
    {
        $sourcePath = $this->projectRoot . DIRECTORY_SEPARATOR . 'scrivener' . DIRECTORY_SEPARATOR . 'Draft' . DIRECTORY_SEPARATOR . 'chapter.xml';
        mkdir(dirname($sourcePath), 0777, true);
        file_put_contents(
            $sourcePath,
            '<Root><Paragraph Type="quote"><TEXT>  Hello world  </TEXT></Paragraph><Paragraph><TEXT>Second</TEXT><TEXT>line</TEXT></Paragraph></Root>',
        );

        $builder = new ReaderPresentationBuilder($this->projectRoot);
        $presentation = $builder->buildPresentation('', '', 'chapter.xml', null);

        $this->assertTrue((bool) $presentation['available']);
        $this->assertSame('chapter.xml', $presentation['source_label']);
        $this->assertNull($presentation['html']);
        $this->assertSame(2, $presentation['paragraph_count']);
        $this->assertSame('Hello world', $presentation['sections'][0]['text']);
        $this->assertSame('quote', $presentation['sections'][0]['type']);
        $this->assertSame('Second line', $presentation['sections'][1]['text']);
    }

    public function testBuildPresentationFallsBackToTopLevelTextNodesWhenNoParagraphNodesExist(): void
    {
        $sourcePath = $this->projectRoot . DIRECTORY_SEPARATOR . 'scrivener' . DIRECTORY_SEPARATOR . 'Notes' . DIRECTORY_SEPARATOR . 'summary.xml';
        mkdir(dirname($sourcePath), 0777, true);
        file_put_contents($sourcePath, '<Root><text>Alpha section</text><TEXT>Beta summary</TEXT></Root>');

        $builder = new ReaderPresentationBuilder($this->projectRoot);
        $presentation = $builder->buildPresentation('', '', 'Notes/summary.xml', null);

        $this->assertTrue((bool) $presentation['available']);
        $this->assertSame('Notes/summary.xml', $presentation['source_label']);
        $this->assertSame(2, $presentation['paragraph_count']);
        $this->assertSame('Alpha section', $presentation['sections'][0]['text']);
        $this->assertSame('Beta summary', $presentation['sections'][1]['text']);
    }

    public function testBuildPresentationReturnsUnavailableWhenSourceContainsNoReadableContent(): void
    {
        $sourcePath = $this->projectRoot . DIRECTORY_SEPARATOR . 'scrivener' . DIRECTORY_SEPARATOR . 'empty.xml';
        mkdir(dirname($sourcePath), 0777, true);
        file_put_contents($sourcePath, '<Root><Paragraph/></Root>');

        $builder = new ReaderPresentationBuilder($this->projectRoot);
        $presentation = $builder->buildPresentation('', '', 'empty.xml', null);

        $this->assertFalse((bool) $presentation['available']);
        $this->assertSame('empty.xml', $presentation['source_label']);
        $this->assertSame([], $presentation['sections']);
        $this->assertSame('No readable text blocks were found in this source file.', $presentation['notice']);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}