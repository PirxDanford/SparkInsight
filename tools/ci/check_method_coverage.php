<?php

declare(strict_types=1);

/**
 * Enforce method-level test coverage without exception ledgers.
 *
 * Usage:
 *   php tools/ci/check_method_coverage.php [coverage-input]
 *
 * coverage-input can be either:
 * - a Clover XML file (legacy fallback)
 * - a PHPUnit XML coverage directory (strict mode; recommended)
 */
final class MethodCoverageGuardrail
{
    private string $coverageInput;

    public function __construct(string $coverageInput)
    {
        $this->coverageInput = $coverageInput;
    }

    public function run(): int
    {
        $uncoveredMethods = $this->extractUncoveredMethods();

        if ($uncoveredMethods !== []) {
            fwrite(STDERR, "\nUncovered methods:\n");
            foreach ($uncoveredMethods as $method) {
                fwrite(STDERR, ' - ' . $method . PHP_EOL);
            }

            fwrite(
                STDERR,
                "\nMethod coverage guardrail failed. Cover these methods before merging.\n",
            );

            return 1;
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

                        if ($methodName === '' || $executable <= 0 || $coverage >= 100.0) {
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

    $guardrail = new MethodCoverageGuardrail($coverageInput);
    exit($guardrail->run());
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Method coverage guardrail failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
