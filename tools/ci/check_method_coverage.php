<?php

declare(strict_types=1);

/**
 * Enforce method-level test coverage with explicit, documented exceptions.
 *
 * Usage:
 *   php tools/ci/check_method_coverage.php [coverage-input] [exceptions.json] [current-version]
 *
 * coverage-input can be either:
 * - a Clover XML file (legacy fallback)
 * - a PHPUnit XML coverage directory (strict mode; recommended)
 */
final class MethodCoverageGuardrail
{
    private string $coverageInput;

    private string $exceptionsFile;

    private string $currentVersion;

    private string $exceptionsAllowedBeforeVersion = '1.0.0';

    /** @var array<string, array{reason:string,owner:string}> */
    private array $exceptionsByMethod = [];

    public function __construct(string $coverageInput, string $exceptionsFile, string $currentVersion)
    {
        $this->coverageInput = $coverageInput;
        $this->exceptionsFile = $exceptionsFile;
        $this->currentVersion = $this->normalizeVersion($currentVersion);
    }

    public function run(): int
    {
        $uncoveredMethods = $this->extractUncoveredMethods();
        $this->exceptionsByMethod = $this->loadAndValidateExceptions();

        $uncoveredSet = array_fill_keys($uncoveredMethods, true);
        $exceptionSet = array_fill_keys(array_keys($this->exceptionsByMethod), true);

        $notAllowed = array_values(array_diff(array_keys($uncoveredSet), array_keys($exceptionSet)));
        sort($notAllowed);

        $stale = array_values(array_diff(array_keys($exceptionSet), array_keys($uncoveredSet)));
        sort($stale);

        $exceptionsForbiddenByVersion = $this->isExceptionWindowClosed() && count($this->exceptionsByMethod) > 0;

        if ($notAllowed !== [] || $stale !== [] || $exceptionsForbiddenByVersion) {
            if ($notAllowed !== []) {
                fwrite(STDERR, "\nUncovered methods without approved exception:\n");
                foreach ($notAllowed as $method) {
                    fwrite(STDERR, ' - ' . $method . PHP_EOL);
                }
            }

            if ($stale !== []) {
                fwrite(STDERR, "\nStale exceptions (method is now covered, remove from exception ledger):\n");
                foreach ($stale as $method) {
                    fwrite(STDERR, ' - ' . $method . PHP_EOL);
                }
            }

            if ($exceptionsForbiddenByVersion) {
                fwrite(
                    STDERR,
                    "\nExceptions are not allowed for version {$this->currentVersion} (allowed only before {$this->exceptionsAllowedBeforeVersion}):\n"
                );
                foreach (array_keys($this->exceptionsByMethod) as $method) {
                    fwrite(STDERR, ' - ' . $method . PHP_EOL);
                }
            }

            fwrite(
                STDERR,
                "\nMethod coverage guardrail failed. Cover these methods or document valid exceptions in {$this->exceptionsFile}.\n"
            );

            return 1;
        }

        $totalUncovered = count($uncoveredMethods);
        $totalExceptions = count($this->exceptionsByMethod);
        fwrite(
            STDOUT,
            "Method coverage guardrail passed for version {$this->currentVersion}. Uncovered methods: {$totalUncovered}. Active exceptions: {$totalExceptions}.\n"
        );

        return 0;
    }

    /** @return list<string> */
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

    /** @return list<string> */
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

    /** @return list<string> */
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
                        $methodName = trim((string) ($methodNode['name'] ?? ''));
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

    /** @return array<string, array{reason:string,owner:string}> */
    private function loadAndValidateExceptions(): array
    {
        if (!is_file($this->exceptionsFile)) {
            throw new RuntimeException('Exceptions file not found: ' . $this->exceptionsFile);
        }

        $decoded = json_decode((string) file_get_contents($this->exceptionsFile), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON in exceptions file: ' . $this->exceptionsFile);
        }

        $entries = $decoded['exceptions'] ?? null;
        if (!is_array($entries)) {
            throw new RuntimeException('Exceptions file must contain an "exceptions" array.');
        }

        $versionPolicy = $decoded['versionPolicy'] ?? null;
        if (!is_array($versionPolicy)) {
            throw new RuntimeException('Exceptions file must contain a "versionPolicy" object.');
        }

        $allowedBeforeRaw = isset($versionPolicy['allowedBeforeVersion'])
            ? trim((string) $versionPolicy['allowedBeforeVersion'])
            : '';

        if ($allowedBeforeRaw === '') {
            throw new RuntimeException('versionPolicy.allowedBeforeVersion must be a non-empty semantic version string.');
        }

        $this->exceptionsAllowedBeforeVersion = $this->normalizeVersion($allowedBeforeRaw);

        $out = [];

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException("Exception entry #{$index} must be an object.");
            }

            $method = isset($entry['method']) ? trim((string) $entry['method']) : '';
            $reason = isset($entry['reason']) ? trim((string) $entry['reason']) : '';
            $owner = isset($entry['owner']) ? trim((string) $entry['owner']) : '';

            if ($method === '' || $reason === '' || $owner === '') {
                throw new RuntimeException(
                    "Exception entry #{$index} must define non-empty method, reason, and owner fields."
                );
            }

            if (strlen($reason) < 20) {
                throw new RuntimeException(
                    "Exception entry for {$method} has an insufficient reason; provide a specific justification."
                );
            }

            if (isset($out[$method])) {
                throw new RuntimeException("Duplicate exception entry for method {$method}.");
            }

            $out[$method] = [
                'reason' => $reason,
                'owner' => $owner,
            ];
        }

        return $out;
    }

    private function isExceptionWindowClosed(): bool
    {
        return version_compare($this->currentVersion, $this->exceptionsAllowedBeforeVersion, '>=');
    }

    private function normalizeVersion(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new RuntimeException('Version value cannot be empty.');
        }

        $lower = strtolower($trimmed);
        if (
            $lower === 'dev'
            || $lower === 'development'
            || $lower === 'main'
            || $lower === 'master'
            || str_starts_with($lower, 'dev-')
            || str_starts_with($lower, 'dev/')
        ) {
            return '0.0.0-dev';
        }

        if (preg_match('/^release\/v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/i', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        throw new RuntimeException(
            "Invalid version '{$value}'. Use semantic version (for example 1.0.0) or a dev marker such as dev-main."
        );
    }

    private function toRepoRelativePath(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);

        $needle = '/src/';
        $position = stripos($normalized, $needle);
        if ($position === false) {
            return basename($normalized);
        }

        return ltrim(substr($normalized, $position + 1), '/');
    }

    private function toRepoRelativePathFromPhpUnitFileNode(SimpleXMLElement $fileNode): string
    {
        $path = trim((string) ($fileNode['path'] ?? ''));
        $name = trim((string) ($fileNode['name'] ?? ''));

        if ($name === '') {
            return '';
        }

        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '') {
            return 'src/' . $name;
        }

        return 'src/' . $path . '/' . $name;
    }
}

try {
    $coverageInput = $argv[1] ?? 'coverage.xml';
    $exceptionsFile = $argv[2] ?? 'docs/coverage-method-exceptions.json';
    $currentVersion = $argv[3] ?? 'dev-main';

    $guardrail = new MethodCoverageGuardrail($coverageInput, $exceptionsFile, $currentVersion);
    exit($guardrail->run());
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Method coverage guardrail failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
