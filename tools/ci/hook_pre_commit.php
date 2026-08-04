<?php

declare(strict_types=1);

if ((string) getenv('SKIP_GIT_HOOKS') === '1') {
    fwrite(STDOUT, "[pre-commit] SKIP_GIT_HOOKS=1 set, skipping checks.\n");
    exit(0);
}

$stagedFiles = [];
$exitCode = 0;
exec('git diff --cached --name-only --diff-filter=ACMR', $stagedFiles, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "[pre-commit] Failed to detect staged files.\n");
    exit(1);
}

$phpFiles = array_values(array_filter(
    $stagedFiles,
    static fn (string $path): bool => preg_match('/\.php$/', $path) === 1,
));

if ($phpFiles === []) {
    fwrite(STDOUT, "[pre-commit] No staged PHP files.\n");
    exit(0);
}

$escapedFiles = array_map(static fn (string $path): string => escapeshellarg($path), $phpFiles);
$command = implode(' ', [
    'php',
    'vendor/bin/php-cs-fixer',
    'fix',
    '--config=.php-cs-fixer.php',
    '--path-mode=intersection',
    '--dry-run',
    '--allow-unsupported-php-version=1',
    ...$escapedFiles,
]);

fwrite(STDOUT, "[pre-commit] Running PHP CS Fixer dry-run on staged PHP files...\n");
passthru($command, $exitCode);
exit($exitCode);
