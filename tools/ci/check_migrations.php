<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$migrationsDir = $projectRoot . '/database/migrations';

$files = glob($migrationsDir . '/*.sql');
if ($files === false || $files === []) {
    fwrite(STDERR, "No migration files found in database/migrations.\n");
    exit(1);
}

sort($files, SORT_STRING);

$errors = [];
$versions = [];
$baselineSeen = false;

$insertPattern = '/INSERT\s+INTO\s+schema_version\s*\(\s*version\s*,\s*applied_at\s*\)\s*VALUES\s*\(\s*(\d+)\s*,/i';

foreach ($files as $file) {
    $name = basename($file);
    $content = file_get_contents($file);

    if ($content === false) {
        $errors[] = $name . ': could not read file content';
        continue;
    }

    $isNumbered = preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $name, $numberedMatch) === 1;
    $isDevOnly = preg_match('/^dev_only_[a-z0-9_]+\.sql$/', $name) === 1;

    if ($name === '001_initial_schema.sql') {
        $baselineSeen = true;
    }

    if (!$isNumbered && !$isDevOnly) {
        $errors[] = $name . ': invalid file name. Use 001_initial_schema.sql or dev_only_description.sql';
        continue;
    }

    if ($isNumbered && $name !== '001_initial_schema.sql') {
        $errors[] = $name . ': numbered migration files are reserved for release upgrades. Use dev_only_*.sql for chapter-era development snapshots until the release branch is cut.';
        continue;
    }

    if ($isNumbered) {
        $version = (int) $numberedMatch[1];

        if (isset($versions[$version])) {
            $errors[] = $name . ': duplicate migration version ' . $version . ' (already used by ' . $versions[$version] . ')';
        } else {
            $versions[$version] = $name;
        }

        continue;
    }

    if (preg_match('/^--\s*DOWN\s*$/mi', $content) !== 1) {
        $errors[] = $name . ': missing -- DOWN section';
    }

    if (preg_match($insertPattern, $content, $insertMatch) !== 1) {
        $errors[] = $name . ': missing schema_version insert marker in UP section';
        continue;
    }

    $version = (int) $insertMatch[1];

    if (isset($versions[$version])) {
        $errors[] = $name . ': duplicate migration version ' . $version . ' (already used by ' . $versions[$version] . ')';
        continue;
    }

    $versions[$version] = $name;

}

if (!$baselineSeen) {
    $errors[] = 'Missing required baseline migration: 001_initial_schema.sql';
}

if ($errors !== []) {
    fwrite(STDERR, "Migration validation failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, '- ' . $error . "\n");
    }

    exit(1);
}

echo 'Migration validation passed for ' . count($files) . " files.\n";
