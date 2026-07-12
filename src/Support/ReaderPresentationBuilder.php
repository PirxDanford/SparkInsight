<?php

declare(strict_types=1);

namespace SparkInsight\Support;

use RtfHtmlPhp\Document as RtfDocument;
use Throwable;

final class ReaderPresentationBuilder
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPresentation(string $contentText, string $contentRtf, string $source, mixed $rawMetadata = null): array
    {
        $html = $this->convertRtfToHtml($contentRtf);
        $storedSections = $this->buildReaderSectionsFromPlainText($contentText);
        if ($html !== null || $storedSections !== []) {
            return $this->buildAvailablePresentation(
                sourceLabel: $source !== '' ? $source : 'Stored import content',
                html: $html,
                sections: $storedSections,
            );
        }

        $resolvedPath = $this->resolveReadableSourcePath($source);
        if ($resolvedPath === null) {
            $resolvedPath = $this->resolveReadableSourcePathFromMetadata($rawMetadata);
        }

        if ($resolvedPath === null) {
            return [
                'available' => false,
                'source_label' => $source !== '' ? $source : 'Unavailable',
                'html' => null,
                'sections' => [],
                'paragraph_count' => 0,
                'word_count' => 0,
                'notice' => 'Original source file is currently unavailable for this item.',
            ];
        }

        $rawXml = @file_get_contents($resolvedPath);
        if ($rawXml === false) {
            return [
                'available' => false,
                'source_label' => $source !== '' ? $source : basename($resolvedPath),
                'html' => null,
                'sections' => [],
                'paragraph_count' => 0,
                'word_count' => 0,
                'notice' => 'Source file could not be read. Please try re-importing this item.',
            ];
        }

        $html = $this->convertRtfToHtml($rawXml);
        $sections = $this->extractReaderSections($rawXml);
        if ($sections === []) {
            $sections = $this->extractReaderSectionsFromRtf($rawXml);
        }

        if ($sections === [] && $html === null) {
            return [
                'available' => false,
                'source_label' => $source !== '' ? $source : basename($resolvedPath),
                'html' => null,
                'sections' => [],
                'paragraph_count' => 0,
                'word_count' => 0,
                'notice' => 'No readable text blocks were found in this source file.',
            ];
        }

        return $this->buildAvailablePresentation(
            sourceLabel: $source !== '' ? $source : basename($resolvedPath),
            html: $html,
            sections: $sections,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    private function buildAvailablePresentation(string $sourceLabel, ?string $html, array $sections): array
    {
        $wordCount = 0;
        foreach ($sections as $section) {
            $wordCount += str_word_count((string) ($section['text'] ?? ''));
        }

        return [
            'available' => true,
            'source_label' => $sourceLabel,
            'html' => $html,
            'sections' => $sections,
            'paragraph_count' => count($sections),
            'word_count' => $wordCount,
            'notice' => null,
        ];
    }

    private function convertRtfToHtml(string $rawRtf): ?string
    {
        $rawRtf = mb_trim($rawRtf);
        if ($rawRtf === '' || !str_starts_with($rawRtf, '{\rtf')) {
            return null;
        }

        $cleanedRtf = preg_replace('/<!?\$Scr_[^>]+>/u', '', $rawRtf);
        if (!is_string($cleanedRtf) || $cleanedRtf === '') {
            return null;
        }

        try {
            $document = new RtfDocument($cleanedRtf);
            $formatter = new ScrivenerHtmlFormatter('UTF-8');
            $html = mb_trim($formatter->Format($document));
        } catch (Throwable) {
            return null;
        }

        if ($html === '') {
            return null;
        }

        $html = preg_replace('/<!?\$Scr_[^>]+>/u', '', $html) ?? $html;
        $html = $this->restoreRtfHyperlinks($html, $rawRtf);

        return mb_trim($html) !== '' ? mb_trim($html) : null;
    }

    private function restoreRtfHyperlinks(string $html, string $rawRtf): string
    {
        $segments = explode('{\field', $rawRtf);
        if (count($segments) < 2) {
            return $html;
        }

        foreach (array_slice($segments, 1) as $segment) {
            if (!preg_match('/HYPERLINK\s+"([^"]+)"/i', $segment, $urlMatch)) {
                continue;
            }

            $url = mb_trim((string) ($urlMatch[1] ?? ''));
            $fldrsltPosition = mb_stripos($segment, '\fldrslt');
            if ($url === '' || $fldrsltPosition === false) {
                continue;
            }

            $labelChunk = mb_substr($segment, $fldrsltPosition + mb_strlen('\fldrslt'));
            if (!is_string($labelChunk) || $labelChunk === '') {
                continue;
            }

            $closingPosition = mb_strpos($labelChunk, '}}');
            if ($closingPosition !== false) {
                $labelChunk = mb_substr($labelChunk, 0, $closingPosition);
            }

            $label = mb_trim($this->extractPlainTextFromRtf('{' . $labelChunk . '}'));
            if ($label === '') {
                continue;
            }

            $quotedLabel = preg_quote($label, '/');
            $replacement = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
            $updatedHtml = preg_replace('/' . $quotedLabel . '/u', $replacement, $html, 1);
            if (is_string($updatedHtml)) {
                $html = $updatedHtml;
            }
        }

        return $html;
    }

    private function resolveReadableSourcePath(string $source): ?string
    {
        $normalized = mb_trim(str_replace('\\', '/', $source));
        $normalized = mb_ltrim($normalized, '/');

        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        $candidates = [
            $this->projectRoot . '/' . $normalized,
            $this->projectRoot . '/scrivener/' . $normalized,
            $this->projectRoot . '/scrivener/Draft/' . $normalized,
            $this->projectRoot . '/scrivener/Notes/' . $normalized,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractReaderSections(string $rawXml): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(mb_trim($rawXml));
        if ($xml === false) {
            libxml_clear_errors();

            return [];
        }

        $sections = [];
        $paragraphNodes = $xml->xpath('//Paragraph | //paragraph');
        if ($paragraphNodes !== false) {
            $sections = $this->buildSectionsFromParagraphNodes($paragraphNodes);
        }

        if ($sections !== []) {
            return $sections;
        }

        $textNodes = $xml->xpath('//TEXT | //Text | //text');
        if ($textNodes === false) {
            return [];
        }

        $index = 1;
        foreach ($textNodes as $textNode) {
            $text = mb_trim((string) preg_replace('/\s+/u', ' ', (string) $textNode));
            if ($text === '') {
                continue;
            }

            $sections[] = [
                'anchor' => 'section-' . $index,
                'label' => $this->buildReaderSectionLabel($text, $index),
                'type' => null,
                'text' => $text,
            ];
            $index++;
        }

        return $sections;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractReaderSectionsFromRtf(string $rawRtf): array
    {
        $plainText = $this->extractPlainTextFromRtf($rawRtf);

        return $this->buildReaderSectionsFromPlainText($plainText);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildReaderSectionsFromPlainText(string $plainText): array
    {
        if ($plainText === '') {
            return [];
        }

        $sections = [];
        $lines = preg_split('/(?:\r\n|\r|\n)+/', $plainText) ?: [];
        $index = 1;

        foreach ($lines as $line) {
            $text = mb_trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($text === '') {
                continue;
            }

            $sections[] = [
                'anchor' => 'section-' . $index,
                'label' => $this->buildReaderSectionLabel($text, $index),
                'type' => null,
                'text' => $text,
            ];
            $index++;
        }

        return $sections;
    }

    private function extractPlainTextFromRtf(string $rtf): string
    {
        $rtf = $this->stripRtfDestinationGroups($rtf, ['fonttbl', 'colortbl', 'stylesheet', 'info']);
        $rtf = preg_replace('/\{\\\\\*\\\fldinst[^{}]*\}/i', '', $rtf) ?? $rtf;
        $rtf = str_ireplace(['\pard', '\par', '\tab'], ["\n", "\n", "\t"], $rtf);
        $rtf = str_ireplace('\fldrslt', '', $rtf);
        $rtf = preg_replace('/\\\[a-z]+-?\d*\s?/i', '', $rtf) ?? $rtf;
        $rtf = preg_replace('/\\\\\'[0-9a-f]{2}/i', '', $rtf) ?? $rtf;
        $rtf = str_replace(['{', '}'], '', $rtf);
        $rtf = preg_replace('/[ \t]+/u', ' ', $rtf) ?? $rtf;
        $rtf = preg_replace('/\n{3,}/', "\n\n", $rtf) ?? $rtf;

        return mb_trim($rtf);
    }

    /**
     * @param array<int, string> $destinations
     */
    private function stripRtfDestinationGroups(string $rtf, array $destinations): string
    {
        foreach ($destinations as $destination) {
            $needle = '{\\' . $destination;
            $offset = 0;

            while (($start = mb_stripos($rtf, $needle, $offset)) !== false) {
                $depth = 0;
                $length = mb_strlen($rtf);
                $end = null;

                for ($index = $start; $index < $length; $index++) {
                    $char = $rtf[$index];
                    if ($char === '{') {
                        $depth++;
                    } elseif ($char === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $index;
                            break;
                        }
                    }
                }

                if ($end === null) {
                    break;
                }

                $rtf = mb_substr($rtf, 0, $start) . mb_substr($rtf, $end + 1);
                $offset = $start;
            }
        }

        return $rtf;
    }

    private function resolveReadableSourcePathFromMetadata(mixed $rawMetadata): ?string
    {
        $metadata = $this->extractScrivenerQueueMetadata($rawMetadata);
        if ($metadata === []) {
            return null;
        }

        $projectFile = mb_trim((string) ($metadata['project_file'] ?? ''));
        $uuid = mb_trim((string) ($metadata['uuid'] ?? ''));
        if ($projectFile === '' || $uuid === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uuid)) {
            return null;
        }

        $projectFile = str_replace('\\', '/', $projectFile);
        if (str_starts_with($projectFile, '/') || preg_match('/^[A-Za-z]:\//', $projectFile)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $projectFile), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return null;
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        $projectFile = implode('/', $segments);
        $projectDirectory = dirname($projectFile);
        if ($projectDirectory === '.' || $projectDirectory === '') {
            return null;
        }

        $candidate = $this->projectRoot . '/' . $projectDirectory . '/Files/Data/' . $uuid . '/content.rtf';
        if (!is_file($candidate)) {
            return null;
        }

        $candidateRealPath = realpath($candidate);
        $projectRootRealPath = realpath($this->projectRoot);
        if (!is_string($candidateRealPath) || !is_string($projectRootRealPath)) {
            return null;
        }

        $normalizedCandidate = mb_strtolower(str_replace('\\', '/', $candidateRealPath));
        $normalizedRoot = mb_rtrim(mb_strtolower(str_replace('\\', '/', $projectRootRealPath)), '/');
        if (!str_starts_with($normalizedCandidate, $normalizedRoot . '/')) {
            return null;
        }

        return $candidateRealPath;
    }

    /**
     * @param array<int, mixed> $paragraphNodes
     * @return array<int, array<string, mixed>>
     */
    private function buildSectionsFromParagraphNodes(array $paragraphNodes): array
    {
        $sections = [];
        $index = 1;

        foreach ($paragraphNodes as $paragraphNode) {
            $textParts = [];
            $embeddedTextNodes = $paragraphNode->xpath('.//TEXT | .//Text | .//text');
            if ($embeddedTextNodes !== false) {
                foreach ($embeddedTextNodes as $textNode) {
                    $chunk = mb_trim((string) preg_replace('/\s+/u', ' ', (string) $textNode));
                    if ($chunk !== '') {
                        $textParts[] = $chunk;
                    }
                }
            }

            $text = mb_trim((string) preg_replace('/\s+/u', ' ', implode(' ', $textParts)));
            if ($text === '') {
                $text = mb_trim((string) preg_replace('/\s+/u', ' ', (string) $paragraphNode));
            }

            if ($text === '') {
                continue;
            }

            $type = mb_trim((string) ($paragraphNode['Type'] ?? $paragraphNode['type'] ?? ''));

            $sections[] = [
                'anchor' => 'section-' . $index,
                'label' => $this->buildReaderSectionLabel($text, $index),
                'type' => $type !== '' ? $type : null,
                'text' => $text,
            ];
            $index++;
        }

        return $sections;
    }

    private function buildReaderSectionLabel(string $text, int $index): string
    {
        $normalized = mb_trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return 'Section ' . $index;
        }

        $words = preg_split('/\s+/u', $normalized);
        if (!is_array($words) || $words === []) {
            return 'Section ' . $index;
        }

        $previewWords = array_slice($words, 0, 8);
        $preview = mb_trim(implode(' ', $previewWords));

        if (count($words) > 8) {
            $preview .= '...';
        }

        return $preview !== '' ? $preview : 'Section ' . $index;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractScrivenerQueueMetadata(mixed $rawMetadata): array
    {
        if (is_array($rawMetadata)) {
            $scrivener = $rawMetadata['scrivener'] ?? null;

            return is_array($scrivener) ? $scrivener : [];
        }

        if (!is_string($rawMetadata) || mb_trim($rawMetadata) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawMetadata, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $scrivener = $decoded['scrivener'] ?? null;

        return is_array($scrivener) ? $scrivener : [];
    }
}
