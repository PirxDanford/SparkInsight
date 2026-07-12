<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

$projectRoot = dirname(__DIR__);
$envPath = $projectRoot . '/.env';
$vendorAutoloadPath = $projectRoot . '/vendor/autoload.php';
$composerLockPath = $projectRoot . '/composer.lock';

/**
 * @return array<string, string>
 */
function parseEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $result = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $trimmed = mb_trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = mb_trim($parts[0]);
        $value = mb_trim($parts[1]);
        $result[$key] = mb_trim($value, "\"'");
    }

    return $result;
}

function printCheck(string $label, bool $ok, string $details): void
{
    $status = $ok ? 'OK' : 'FAIL';
    echo sprintf('[%s] %s: %s', $status, $label, $details) . PHP_EOL;
}

function shortValue(string $value): string
{
    return $value === '' ? '(empty)' : 'set';
}

$env = parseEnvFile($envPath);
$token = $env['DIAG_ACCESS_TOKEN'] ?? '';
$providedToken = $_GET['token'] ?? '';

if ($token === '') {
    http_response_code(403);
    echo 'SparkInsight install diagnostics' . PHP_EOL;
    echo 'Access denied: set DIAG_ACCESS_TOKEN in .env and call this URL with ?token=...' . PHP_EOL;
    exit(1);
}

if (!hash_equals($token, (string) $providedToken)) {
    http_response_code(403);
    echo 'SparkInsight install diagnostics' . PHP_EOL;
    echo 'Access denied: invalid token.' . PHP_EOL;
    exit(1);
}

$hasVendorAutoload = is_file($vendorAutoloadPath);
if ($hasVendorAutoload) {
    require_once $vendorAutoloadPath;
}

echo 'SparkInsight install diagnostics' . PHP_EOL;
echo 'Generated at: ' . date(DATE_ATOM) . PHP_EOL;
echo PHP_EOL;

$phpVersionOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$phpRecommended = version_compare(PHP_VERSION, '8.5.0', '>=');
printCheck('PHP version', $phpVersionOk, PHP_VERSION . ($phpRecommended ? ' (recommended level met)' : ' (8.5+ recommended)'));

$requiredExtensions = [
    'json',
    'openssl',
    'pdo',
    'session',
    'tokenizer',
];

foreach ($requiredExtensions as $ext) {
    printCheck('Extension ' . $ext, extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing');
}

$dbDriver = $env['DB_DRIVER'] ?? '';
$dbExtensionOk = true;
if ($dbDriver === 'pdo_mysql') {
    $dbExtensionOk = extension_loaded('pdo_mysql');
    printCheck('DB driver extension', $dbExtensionOk, 'DB_DRIVER=pdo_mysql requires pdo_mysql');
} elseif ($dbDriver === 'pdo_pgsql') {
    $dbExtensionOk = extension_loaded('pdo_pgsql');
    printCheck('DB driver extension', $dbExtensionOk, 'DB_DRIVER=pdo_pgsql requires pdo_pgsql');
} else {
    printCheck('DB driver extension', $dbDriver !== '', $dbDriver === '' ? 'DB_DRIVER not set in .env' : 'unsupported DB_DRIVER value: ' . $dbDriver);
}

printCheck('.env file', is_file($envPath), is_file($envPath) ? '.env found' : '.env missing');
printCheck('vendor/autoload.php', $hasVendorAutoload, $hasVendorAutoload ? 'vendor tree present' : 'missing vendor tree (upload prepared build)');
printCheck('composer.lock', is_file($composerLockPath), is_file($composerLockPath) ? 'lockfile present' : 'lockfile missing');

$composerLockOk = false;
if (is_file($composerLockPath)) {
    $raw = file_get_contents($composerLockPath);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $composerLockOk = is_array($decoded);
    printCheck('composer.lock parse', $composerLockOk, $composerLockOk ? 'valid JSON lockfile' : 'invalid JSON lockfile');

    if ($composerLockOk) {
        $packageCount = isset($decoded['packages']) && is_array($decoded['packages']) ? count($decoded['packages']) : 0;
        $packageDevCount = isset($decoded['packages-dev']) && is_array($decoded['packages-dev']) ? count($decoded['packages-dev']) : 0;
        $contentHash = isset($decoded['content-hash']) ? (string) $decoded['content-hash'] : 'n/a';

        echo 'Lockfile content-hash: ' . $contentHash . PHP_EOL;
        echo 'Lockfile packages: ' . $packageCount . ' prod, ' . $packageDevCount . ' dev' . PHP_EOL;
    }
}

$autoloadReady = class_exists('SparkInsight\Config\Config');
printCheck('Application autoload', $autoloadReady, $autoloadReady ? 'SparkInsight classes available' : 'autoload failed or dependencies missing');

$requiredEnvKeys = [
    'APP_URL',
    'DB_DRIVER',
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'OAUTH_GITHUB_CLIENT_ID',
    'OAUTH_GITHUB_CLIENT_SECRET',
    'OAUTH_GOOGLE_CLIENT_ID',
    'OAUTH_GOOGLE_CLIENT_SECRET',
];

echo PHP_EOL;
echo 'Environment key presence:' . PHP_EOL;
foreach ($requiredEnvKeys as $key) {
    $value = $env[$key] ?? '';
    $ok = $value !== '';
    printCheck($key, $ok, shortValue($value));
}

echo PHP_EOL;
echo 'Note: Remove install_diagnose.php or clear DIAG_ACCESS_TOKEN after validation.' . PHP_EOL;
