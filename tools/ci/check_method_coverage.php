<?php

declare(strict_types=1);

/**
 * Report untested methods for CI visibility (method coverage, not line coverage).
 *
 * A method counts as covered when at least one of its executable lines ran,
 * i.e. the method was called by a test. Methods with 0% coverage are flagged.
 *
 * Usage:
 *   php tools/ci/check_method_coverage.php [coverage-input] [--warn-only]
 *
 * coverage-input can be either:
 * - a Clover XML file (legacy fallback)
 * - a PHPUnit XML coverage directory (recommended)
 */
final class MethodCoverageGuardrail
{
    private string $coverageInput;

    private bool $warnOnly;

    public function __construct(string $coverageInput, bool $warnOnly = false)
    {
        $this->coverageInput = $coverageInput;
        $this->warnOnly = $warnOnly;
    }

    public function run(): int
    {
        $uncoveredMethods = $this->extractUncoveredMethods();

        if ($uncoveredMethods !== []) {
            fwrite(STDOUT, "\nUncovered methods:\n");
            foreach ($uncoveredMethods as $method) {
                fwrite(STDOUT, ' - ' . $method . PHP_EOL);
            }

            fwrite(
                STDOUT,
                $this->warnOnly
                    ? "\nMethod coverage guardrail warning: uncovered methods found (warn-only mode).\n"
                    : "\nMethod coverage guardrail failed: uncovered methods are not allowed.\n",
            );

            return $this->warnOnly ? 0 : 1;
        }

        fwrite(STDOUT, "Method coverage guardrail passed. Uncovered methods: 0.\n");

        return 0;
    }

    /**
     * @return list<string>
     */
    private function extractUncoveredMethods(): array
    {
        if (is_dir($this->coverageInput)) {
            return $this->extractUncoveredMethodsFromPhpUnitXmlDirectory($this->coverageInput);
        }

        if (!is_file($this->coverageInput)) {
            throw new RuntimeException('Coverage input not found: ' . $this->coverageInput);
        }

        return $this->extractUncoveredMethodsFromCloverFile($this->coverageInput);
    }

    /**
     * @return list<string>
     */
    private function extractUncoveredMethodsFromCloverFile(string $coverageFile): array
    {
        $xml = simplexml_load_file($coverageFile);
        if ($xml === false || !isset($xml->project)) {
            throw new RuntimeException('Could not parse Clover XML: ' . $coverageFile);
        }

        $methods = [];
        foreach ($xml->project->file as $fileNode) {
            $absolutePath = (string) ($fileNode['name'] ?? '');
            if ($absolutePath === '') {
                continue;
            }

            $relativePath = $this->toRepoRelativePath($absolutePath);

            foreach ($fileNode->line as $lineNode) {
                $lineType = (string) ($lineNode['type'] ?? '');
                if ($lineType !== 'method') {
                    continue;
                }

                $methodName = (string) ($lineNode['name'] ?? '');
                $count = (int) ($lineNode['count'] ?? 0);

                if ($methodName === '' || $count > 0) {
                    continue;
                }

                $methods[] = $relativePath . '::' . $methodName;
            }
        }

        $methods = array_values(array_unique($methods));
        sort($methods);

        return $methods;
    }

    /**
     * @return list<string>
     */
    private function extractUncoveredMethodsFromPhpUnitXmlDirectory(string $coverageDirectory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($coverageDirectory, FilesystemIterator::SKIP_DOTS),
        );

        $methods = [];

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || mb_strtolower($fileInfo->getExtension()) !== 'xml') {
                continue;
            }

            if (mb_strtolower($fileInfo->getFilename()) === 'index.xml') {
                continue;
            }

            $xml = simplexml_load_file($fileInfo->getPathname());
            if ($xml === false) {
                continue;
            }

            $fileNodes = $xml->xpath('/*[local-name()="phpunit"]/*[local-name()="file"]');
            if (!is_array($fileNodes) || $fileNodes === []) {
                continue;
            }

            foreach ($fileNodes as $fileNode) {
                $relativePath = $this->toRepoRelativePathFromPhpUnitFileNode($fileNode);
                if ($relativePath === '') {
                    continue;
                }

                $classNodes = $fileNode->xpath('./*[local-name()="class"]');
                if (!is_array($classNodes)) {
                    continue;
                }

                foreach ($classNodes as $classNode) {
                    $methodNodes = $classNode->xpath('./*[local-name()="method"]');
                    if (!is_array($methodNodes)) {
                        continue;
                    }

                    foreach ($methodNodes as $methodNode) {
                        $methodName = mb_trim((string) ($methodNode['name'] ?? ''));
                        $executable = (int) ($methodNode['executable'] ?? 0);
                        $coverage = (float) ($methodNode['coverage'] ?? 0.0);

                        // Method coverage: flag only methods that were never executed at all.
                        // coverage > 0 means at least one line ran, i.e. the method is tested.
                        if ($methodName === '' || $executable <= 0 || $coverage > 0.0) {
                            continue;
                        }

                        $methods[] = $relativePath . '::' . $methodName;
                    }
                }
            }
        }

        $methods = array_values(array_unique($methods));
        sort($methods);

        return $methods;
    }

    private function toRepoRelativePath(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);

        $needle = '/src/';
        $position = mb_stripos($normalized, $needle);
        if ($position === false) {
            return basename($normalized);
        }

        return mb_ltrim(mb_substr($normalized, $position + 1), '/');
    }

    private function toRepoRelativePathFromPhpUnitFileNode(SimpleXMLElement $fileNode): string
    {
        $path = mb_trim((string) ($fileNode['path'] ?? ''));
        $name = mb_trim((string) ($fileNode['name'] ?? ''));

        if ($name === '') {
            return '';
        }

        $path = mb_trim(str_replace('\\', '/', $path), '/');

        if ($path === '') {
            return 'src/' . $name;
        }

        return 'src/' . $path . '/' . $name;
    }
}

try {
    $coverageInput = $argv[1] ?? 'coverage.xml';
    $warnOnly = in_array('--warn-only', $argv, true);

    $guardrail = new MethodCoverageGuardrail($coverageInput, $warnOnly);
    exit($guardrail->run());
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Method coverage guardrail failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
