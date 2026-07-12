<?php

declare(strict_types=1);

namespace SparkInsight\Service;

use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Tcpdf;
use InvalidArgumentException;
use RuntimeException;
use SparkInsight\Support\ReaderPresentationBuilder;
use Throwable;

class AuthorPdfExportService
{
    private const MAX_KEEP_PARAGRAPH_LINES = 8;

    private const CHARS_PER_PDF_LINE_ESTIMATE = 95;

    private string $projectRoot;

    private ?ReaderPresentationBuilder $readerPresentationBuilder = null;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot !== null && $projectRoot !== ''
            ? $projectRoot
            : dirname(__DIR__, 2);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function generate(array $items, string $profile, ?string $password): string
    {
        if ($items === []) {
            throw new InvalidArgumentException('At least one content item must be selected.');
        }

        $password = mb_trim((string) $password);
        if ($password !== '' && mb_strlen($password) < 12) {
            throw new InvalidArgumentException('Password-protected export requires a password with at least 12 characters.');
        }

        $this->ensureFontPath();

        $encrypt = null;
        if ($password !== '') {
            $encrypt = new Encrypt(
                enabled: true,
                file_id: md5('sparkinsight-export-' . microtime(true) . '-' . random_int(1, PHP_INT_MAX)),
                mode: 2,
                permissions: [],
                user_pass: $password,
                owner_pass: bin2hex(random_bytes(16)),
            );
        }

        $pdf = new Tcpdf(
            unit: 'mm',
            isunicode: true,
            subsetfont: false,
            compress: true,
            mode: '',
            objEncrypt: $encrypt,
        );

        $pdf->setCreator('SparkInsight');
        $pdf->setAuthor('SparkInsight');
        $pdf->setSubject('Author export package');
        $pdf->setTitle('SparkInsight Author Export');
        $pdf->setKeywords('SparkInsight author export pdf');
        $pdf->setViewerPreferences(['DisplayDocTitle' => true]);
        $pdf->enableDefaultPageContent();

        $baseFont = $pdf->font->insert($pdf->pon, 'helvetica', '', 11);
        $pageLayout = [
            'margin' => [
                'PL' => 15.0,
                'PR' => 15.0,
                'PT' => 10.0,
                'PB' => 12.0,
                'CT' => 10.0,
                'CB' => 12.0,
            ],
        ];

        foreach ($items as $item) {
            $pdf->addPage($pageLayout);
            $pdf->page->addContent($baseFont['out']);
            try {
                $pdf->addHTMLCell(
                    html: $this->buildItemHtml($item),
                    posx: 15,
                    posy: 20,
                    width: 180,
                );
            } catch (Throwable) {
                $pdf->addHTMLCell(
                    html: $this->buildItemFallbackHtml($item),
                    posx: 15,
                    posy: 20,
                    width: 180,
                );
            }
        }

        return $pdf->getOutPDFString();
    }

    private function ensureFontPath(): void
    {
        if (defined('K_PATH_FONTS')) {
            return;
        }

        $fontPath = $this->projectRoot . '/resources/pdf-fonts';
        if (!is_dir($fontPath)) {
            throw new RuntimeException('PDF font assets are missing at resources/pdf-fonts.');
        }

        define('K_PATH_FONTS', $this->prepareRuntimeFontPath($fontPath));
    }

    private function prepareRuntimeFontPath(string $sourceFontPath): string
    {
        $runtimeFontPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkinsight-pdf-fonts';
        if (!is_dir($runtimeFontPath) && !mkdir($runtimeFontPath, 0o777, true) && !is_dir($runtimeFontPath)) {
            throw new RuntimeException('Unable to prepare a runtime font directory for PDF export.');
        }

        $fontAliases = [
            'helvetica.json' => 'pdfahelvetica.json',
            'helveticab.json' => 'pdfahelveticab.json',
            'helveticai.json' => 'pdfahelveticai.json',
            'helveticabi.json' => 'pdfahelveticabi.json',
        ];

        foreach ($fontAliases as $sourceFile => $aliasFile) {
            $sourceFilePath = $sourceFontPath . DIRECTORY_SEPARATOR . $sourceFile;
            $runtimeSourcePath = $runtimeFontPath . DIRECTORY_SEPARATOR . $sourceFile;
            $runtimeAliasPath = $runtimeFontPath . DIRECTORY_SEPARATOR . $aliasFile;

            if (!is_file($sourceFilePath)) {
                throw new RuntimeException('Missing required PDF font asset: ' . $sourceFile . '.');
            }

            if (!is_file($runtimeSourcePath) && !copy($sourceFilePath, $runtimeSourcePath)) {
                throw new RuntimeException('Unable to stage PDF font asset: ' . $sourceFile . '.');
            }

            if (!is_file($runtimeAliasPath) && !copy($runtimeSourcePath, $runtimeAliasPath)) {
                throw new RuntimeException('Unable to stage PDF/A font alias: ' . $aliasFile . '.');
            }
        }

        return realpath($runtimeFontPath) ?: $runtimeFontPath;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function buildItemHtml(array $item): string
    {
        $title = $this->escape((string) ($item['title'] ?? 'Untitled'));
        $bookTitle = mb_trim((string) ($item['book_title'] ?? ''));
        if ($bookTitle === '') {
            $bookTitle = 'Enterprise Community Management';
        }
        $bookTitleHtml = $this->escape($bookTitle);

        $reader = $this->readerPresentationBuilder()->buildPresentation(
            (string) ($item['content_text'] ?? ''),
            (string) ($item['content_rtf'] ?? ''),
            (string) ($item['source'] ?? ''),
            $item['metadata'] ?? null,
        );

        return $this->buildPdfDocumentHtml($item, $reader, true, $bookTitleHtml, $title);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function buildItemFallbackHtml(array $item): string
    {
        $title = $this->escape((string) ($item['title'] ?? 'Untitled'));
        $bookTitle = mb_trim((string) ($item['book_title'] ?? ''));
        if ($bookTitle === '') {
            $bookTitle = 'Enterprise Community Management';
        }

        $reader = $this->readerPresentationBuilder()->buildPresentation(
            (string) ($item['content_text'] ?? ''),
            (string) ($item['content_rtf'] ?? ''),
            (string) ($item['source'] ?? ''),
            $item['metadata'] ?? null,
        );

        return $this->buildPdfDocumentHtml($item, $reader, false, $this->escape($bookTitle), $title);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $reader
     */
    private function buildPdfDocumentHtml(array $item, array $reader, bool $preferRenderedHtml, string $bookTitleHtml, string $titleHtml): string
    {
        $contentHtml = $this->buildPdfReaderContentHtml($reader, $preferRenderedHtml);
        $typographyCss = $this->buildPdfTypographyCss();

        return '<style>' . $typographyCss . '</style>'
            . '<div style="font-family: helvetica; font-size: 11pt; line-height: 150%; color: #172330;">'
            . '<div style="margin: 0 0 7mm 0;">'
            . '<p style="font-size: 10pt; color: #52606d; margin: 0 0 2mm 0;">' . $bookTitleHtml . '</p>'
            . '<h1 style="font-size: 18pt; font-weight: bold; margin: 0; color: #102a43;">' . $titleHtml . '</h1>'
            . '</div>'
            . $contentHtml
            . '</div>';
    }

    /**
     * @param array<string, mixed> $reader
     */
    private function buildPdfReaderContentHtml(array $reader, bool $preferRenderedHtml): string
    {
        $sections = is_array($reader['sections'] ?? null) ? $reader['sections'] : [];
        $renderedHtml = mb_trim((string) ($reader['html'] ?? ''));
        $pdfSafeHtml = $renderedHtml !== '' ? $this->sanitizeReaderHtmlForPdf($renderedHtml) : '';
        if ($pdfSafeHtml !== '') {
            $pdfSafeHtml = $this->applyParagraphBreakHints($pdfSafeHtml);
        }

        if ($preferRenderedHtml && $pdfSafeHtml !== '') {
            return '<article class="reader-text-content"><div class="reader-rendered-html">' . $pdfSafeHtml . '</div></article>';
        }

        if ($pdfSafeHtml !== '') {
            return '<article class="reader-text-content"><div class="reader-rendered-html">' . $pdfSafeHtml . '</div></article>';
        }

        if ($sections === []) {
            return '<article class="reader-text-content"><p><em>' . $this->escape((string) ($reader['notice'] ?? 'No content text is available for this item.')) . '</em></p></article>';
        }

        $contentHtml = '<article class="reader-text-content">';

        foreach ($sections as $section) {
            $sectionText = (string) ($section['text'] ?? '');
            $paragraphStyle = 'margin: 0 0 4mm 0; line-height: 150%;';
            if ($this->shouldKeepParagraphTogether($sectionText)) {
                $paragraphStyle .= ' page-break-inside: avoid;';
            }
            $contentHtml .= '<p style="' . $paragraphStyle . '">' . nl2br($this->escape($sectionText)) . '</p>';
        }

        return $contentHtml . '</article>';
    }

    private function buildPdfTypographyCss(): string
    {
        return 'p, li, div, blockquote { line-height: 150%; }'
            . ' .reader-text-content { margin: 1.5mm 0 7mm 0; }'
            . ' p { margin: 0 0 4mm 0; orphans: 2; widows: 2; }'
            . ' ul, ol { margin: 0 0 4mm 5mm; padding: 0; }'
            . ' li { margin: 0 0 1.5mm 0; }'
            . ' h1 { margin: 0 0 4mm 0; line-height: 120%; }'
            . ' h1, h2, h3, h4, h5, h6 { page-break-after: avoid; }'
            . ' h2, h3, h4, h5, h6 { margin: 0 0 3mm 0; line-height: 125%; }'
            . ' blockquote, ul, ol, table { page-break-inside: avoid; }'
            . ' a { color: #102a43; text-decoration: underline; }'
            . ' br { line-height: 150%; }';
    }

    private function applyParagraphBreakHints(string $html): string
    {
        return preg_replace_callback('/<p\b([^>]*)>(.*?)<\/p>/is', function (array $matches): string {
            $attributes = (string) ($matches[1] ?? '');
            $content = (string) ($matches[2] ?? '');

            if (!$this->shouldKeepParagraphTogether($content, true)) {
                return '<p' . $attributes . '>' . $content . '</p>';
            }

            return '<p' . $this->appendStyleAttribute($attributes, 'page-break-inside: avoid;') . '>' . $content . '</p>';
        }, $html) ?? $html;
    }

    private function shouldKeepParagraphTogether(string $value, bool $isHtml = false): bool
    {
        if ($isHtml) {
            $value = preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;
            $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $value = mb_trim($value);
        if ($value === '') {
            return false;
        }

        return $this->estimateParagraphLineCount($value) <= self::MAX_KEEP_PARAGRAPH_LINES;
    }

    private function estimateParagraphLineCount(string $text): int
    {
        $normalized = preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r"], "\n", $text)) ?? $text;
        $normalized = mb_trim($normalized);

        if ($normalized === '') {
            return 0;
        }

        $explicitLineCount = max(1, mb_substr_count($normalized, "\n") + 1);
        $wrappedLineCount = max(1, (int) ceil(mb_strlen($normalized) / self::CHARS_PER_PDF_LINE_ESTIMATE));

        return max($explicitLineCount, $wrappedLineCount);
    }

    private function appendStyleAttribute(string $attributes, string $style): string
    {
        if (preg_match('/\sstyle="([^"]*)"/i', $attributes, $matches) === 1) {
            $existingStyle = mb_trim((string) ($matches[1] ?? ''));
            $mergedStyle = mb_trim($existingStyle . ' ' . $style);

            return preg_replace('/\sstyle="[^"]*"/i', ' style="' . $mergedStyle . '"', $attributes, 1) ?? $attributes;
        }

        return $attributes . ' style="' . mb_trim($style) . '"';
    }

    private function sanitizeReaderHtmlForPdf(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/\sclass="[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace('/\sid="[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace('/\sdata-[a-z0-9_-]+="[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace('/\sstyle="[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace('/<\/?(section|article|header|footer|main|aside|figure|figcaption|span)\b[^>]*>/i', '', $html) ?? $html;

        $html = preg_replace('/<a\b([^>]*)target="[^"]*"([^>]*)>/i', '<a$1$2>', $html) ?? $html;
        $html = preg_replace('/<a\b([^>]*)rel="[^"]*"([^>]*)>/i', '<a$1$2>', $html) ?? $html;

        $html = preg_replace('/<br\s*\/?\s*>/i', '<br />', $html) ?? $html;
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        return mb_trim($html);
    }

    private function readerPresentationBuilder(): ReaderPresentationBuilder
    {
        return $this->readerPresentationBuilder ??= new ReaderPresentationBuilder($this->projectRoot);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
