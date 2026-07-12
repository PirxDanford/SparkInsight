<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Service;

use PHPUnit\Framework\TestCase;
use SparkInsight\Service\AuthorPdfExportService;

final class AuthorPdfExportServiceTest extends TestCase
{
    public function testGenerateProducesPdfBinaryForValidItem(): void
    {
        $service = new AuthorPdfExportService(dirname(__DIR__, 3));

        $pdf = $service->generate([
            [
                'id' => 101,
                'title' => 'Export Smoke Test',
                'book_title' => 'PDF QA Book',
                'content_text' => implode("\n\n", [
                    'This is a smoke-test paragraph for PDF export.',
                    'It ensures the full generate() path executes without runtime API errors.',
                    str_repeat('Additional content line for wrapping behavior. ', 12),
                ]),
                'content_rtf' => '',
                'source' => 'tests/smoke-export.txt',
                'metadata' => null,
            ],
        ], 'standard', null);

        $this->assertNotSame('', $pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Type /Catalog', $pdf);
    }

    public function testBuildItemHtmlUsesSharedReaderHtmlForPdfOutput(): void
    {
        $service = new AuthorPdfExportService(getcwd());
        $method = new \ReflectionMethod($service, 'buildItemHtml');

        $rtf = <<<'RTF'
{\rtf1\ansi
{\fonttbl{\f0\fnil\fcharset0 Sitka Text;}}
{\field{\*\fldinst HYPERLINK "https://example.com"}{\fldrslt Example Link}}\par
First paragraph of imported content.\par
Second paragraph follows.
}
RTF;

        $html = (string) $method->invoke($service, [
            'title' => 'Test Chapter',
            'book_title' => 'Test Book',
            'content_text' => '',
            'content_rtf' => $rtf,
            'source' => 'scrivener/test.rtf',
        ]);

        $this->assertStringNotContainsString('Sitka Text', $html);
        $this->assertStringContainsString('<a href="https://example.com"', $html);
        $this->assertStringContainsString('Example Link', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertStringNotContainsString('rel="noopener noreferrer"', $html);
    }

    public function testPdfSanitizerPreservesReadableLinks(): void
    {
        $service = new AuthorPdfExportService(getcwd());

        $method = new \ReflectionMethod($service, 'sanitizeReaderHtmlForPdf');

        $html = (string) $method->invoke(
            $service,
            '<p>According to <a href="https://en.wikipedia.org/wiki/Community" target="_blank" rel="noopener noreferrer">Wikipedia</a>, a community is a social unit.</p>',
        );

        $this->assertStringContainsString('<a href="https://en.wikipedia.org/wiki/Community"', $html);
        $this->assertStringContainsString('Wikipedia</a>, a community is a social unit.', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertStringNotContainsString('rel="noopener noreferrer"', $html);
    }

    public function testTypographyCssIncludesReaderMarginsAndBreakControls(): void
    {
        $service = new AuthorPdfExportService(getcwd());
        $method = new \ReflectionMethod($service, 'buildPdfTypographyCss');

        $css = (string) $method->invoke($service);

        $this->assertStringContainsString('.reader-text-content { margin: 1.5mm 0 7mm 0; }', $css);
        $this->assertStringContainsString('orphans: 2; widows: 2;', $css);
        $this->assertStringContainsString('page-break-inside: avoid;', $css);
    }

    public function testParagraphBreakHintsOnlyKeepShortParagraphsTogether(): void
    {
        $service = new AuthorPdfExportService(getcwd());
        $method = new \ReflectionMethod($service, 'applyParagraphBreakHints');

        $shortParagraph = '<p>Short paragraph with a couple of lines and a clear end.</p>';
        $longText = str_repeat('This paragraph is intentionally very long to exceed the eight-line threshold in the PDF renderer. ', 12);
        $longParagraph = '<p>' . $longText . '</p>';

        $shortResult = (string) $method->invoke($service, $shortParagraph);
        $longResult = (string) $method->invoke($service, $longParagraph);

        $this->assertStringContainsString('style="page-break-inside: avoid;"', $shortResult);
        $this->assertStringNotContainsString('style="page-break-inside: avoid;"', $longResult);
    }
}