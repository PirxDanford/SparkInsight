<?php

declare(strict_types=1);

/**
 * SparkInsight production preparation helper.
 *
 * Validates local runtime against a target profile and writes
 * a report + composer.lock snapshot for FTP deployments.
 */

$rootDir = dirname(__DIR__, 2);
$defaultProfile = __DIR__ . '/profiles/shared-hosting.example.json';

$options = getopt('', ['profile::', 'output::']);
$profilePathInput = isset($options['profile']) ? (string) $options['profile'] : $defaultProfile;
$outputBaseInput = isset($options['output']) ? (string) $options['output'] : __DIR__ . '/output';

$profilePath = $profilePathInput;
if (!str_starts_with($profilePathInput, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:\\\\/', $profilePathInput)) {
    $profilePath = $rootDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $profilePathInput);
}

$outputBase = $outputBaseInput;
if (!str_starts_with($outputBaseInput, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:\\\\/', $outputBaseInput)) {
    $outputBase = $rootDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputBaseInput);
}

$composerLockPath = $rootDir . DIRECTORY_SEPARATOR . 'composer.lock';
$vendorAutoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!is_file($profilePath)) {
    fwrite(STDERR, 'Profile not found: ' . $profilePath . PHP_EOL);
    exit(2);
}

$profileRaw = file_get_contents($profilePath);
if ($profileRaw === false) {
    fwrite(STDERR, 'Unable to read profile: ' . $profilePath . PHP_EOL);
    exit(2);
}

$profile = json_decode($profileRaw, true);
if (!is_array($profile)) {
    fwrite(STDERR, 'Invalid profile JSON: ' . $profilePath . PHP_EOL);
    exit(2);
}

$profileName = isset($profile['name']) && is_string($profile['name']) && $profile['name'] !== ''
    ? preg_replace('/[^a-zA-Z0-9._-]+/', '-', $profile['name'])
    : 'default';

$phpMin = isset($profile['php_min']) && is_string($profile['php_min']) ? $profile['php_min'] : '8.1.0';
$phpRecommended = isset($profile['php_recommended']) && is_string($profile['php_recommended']) ? $profile['php_recommended'] : '8.5.0';
$dbDriver = isset($profile['db_driver']) && is_string($profile['db_driver']) ? $profile['db_driver'] : 'pdo_mysql';

$requiredExtensions = [];
if (isset($profile['required_extensions']) && is_array($profile['required_extensions'])) {
    foreach ($profile['required_extensions'] as $ext) {
        if (is_string($ext) && $ext !== '') {
            $requiredExtensions[] = $ext;
        }
    }
}

$checks = [];

$addCheck = static function (string $name, bool $ok, string $details) use (&$checks): void {
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'details' => $details,
    ];
};

$phpMinOk = version_compare(PHP_VERSION, $phpMin, '>=');
$phpRecommendedOk = version_compare(PHP_VERSION, $phpRecommended, '>=');

$addCheck('php_min', $phpMinOk, 'current=' . PHP_VERSION . ', required>=' . $phpMin);
$addCheck('php_recommended', $phpRecommendedOk, 'current=' . PHP_VERSION . ', recommended>=' . $phpRecommended);

$composerLockExists = is_file($composerLockPath);
$vendorAutoloadExists = is_file($vendorAutoloadPath);

$addCheck('composer_lock_exists', $composerLockExists, $composerLockPath);
$addCheck('vendor_autoload_exists', $vendorAutoloadExists, $vendorAutoloadPath);

if ($dbDriver === 'pdo_mysql') {
    $addCheck('db_extension', extension_loaded('pdo_mysql'), 'DB driver is pdo_mysql');
} elseif ($dbDriver === 'pdo_pgsql') {
    $addCheck('db_extension', extension_loaded('pdo_pgsql'), 'DB driver is pdo_pgsql');
} else {
    $addCheck('db_extension', false, 'Unsupported db_driver in profile: ' . $dbDriver);
}

foreach ($requiredExtensions as $ext) {
    $addCheck('extension_' . $ext, extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing');
}

$allOk = true;
foreach ($checks as $check) {
    if ($check['ok'] !== true && $check['name'] !== 'php_recommended') {
        $allOk = false;
    }
}

$outputDir = rtrim($outputBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $profileName;
if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, 'Unable to create output directory: ' . $outputDir . PHP_EOL);
    exit(2);
}

$report = [
    'generated_at' => date(DATE_ATOM),
    'profile_path' => $profilePath,
    'profile_name' => $profileName,
    'project_root' => $rootDir,
    'php_version' => PHP_VERSION,
    'db_driver' => $dbDriver,
    'required_extensions' => $requiredExtensions,
    'checks' => $checks,
    'all_required_checks_passed' => $allOk,
    'note' => 'php_recommended is advisory and does not fail the run by itself.',
];

$reportPath = $outputDir . DIRECTORY_SEPARATOR . 'prep-report.json';
$reportJson = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($reportJson === false || file_put_contents($reportPath, $reportJson . PHP_EOL) === false) {
    fwrite(STDERR, 'Unable to write report: ' . $reportPath . PHP_EOL);
    exit(2);
}

$snapshotPath = $outputDir . DIRECTORY_SEPARATOR . 'composer.lock.snapshot';
if ($composerLockExists) {
    $lockRaw = file_get_contents($composerLockPath);
    if (!is_string($lockRaw) || file_put_contents($snapshotPath, $lockRaw) === false) {
        fwrite(STDERR, 'Unable to write lock snapshot: ' . $snapshotPath . PHP_EOL);
        exit(2);
    }
}

echo 'SparkInsight production preparation' . PHP_EOL;
echo 'Profile: ' . $profileName . PHP_EOL;
echo 'Report: ' . $reportPath . PHP_EOL;
if ($composerLockExists) {
    echo 'Lock snapshot: ' . $snapshotPath . PHP_EOL;
}
echo PHP_EOL;

foreach ($checks as $check) {
    $status = $check['ok'] ? 'OK' : 'FAIL';
    echo '[' . $status . '] ' . $check['name'] . ' - ' . $check['details'] . PHP_EOL;
}

echo PHP_EOL;
echo $allOk
    ? 'Result: READY for FTP packaging.' . PHP_EOL
    : 'Result: NOT READY. Resolve failed checks and run again.' . PHP_EOL;

exit($allOk ? 0 : 1);
