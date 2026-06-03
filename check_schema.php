<?php

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'];
$pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD']);

// Check schema version
$result = $pdo->query('SELECT MAX(version) as version FROM schema_version');
$row = $result->fetch(PDO::FETCH_ASSOC);
echo 'Schema version: ' . $row['version'] . PHP_EOL;

// Check if status column exists
$result = $pdo->query('SHOW COLUMNS FROM users LIKE "status"');
$row = $result->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo 'Status column: EXISTS' . PHP_EOL;
} else {
    echo 'Status column: MISSING' . PHP_EOL;
}

// Check invitation_used column
$result = $pdo->query('SHOW COLUMNS FROM users LIKE "invitation_used"');
$row = $result->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo 'Invitation_used column: EXISTS' . PHP_EOL;
} else {
    echo 'Invitation_used column: MISSING' . PHP_EOL;
}
