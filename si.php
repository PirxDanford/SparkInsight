<?php

declare(strict_types=1);

use SparkInsight\Command\CheckEnvironmentCommand;
use SparkInsight\Command\GenerateInvitationCommand;
use SparkInsight\Command\InitializeDbCommand;
use SparkInsight\Command\MigrateDbCommand;
use SparkInsight\Command\ScrivenerImportCommand;
use Symfony\Component\Console\Application;

require __DIR__ . '/vendor/autoload.php';

$app = new Application('SparkInsight CLI', '1.0.0');
$app->add(new CheckEnvironmentCommand());
$app->add(new InitializeDbCommand());
$app->add(new MigrateDbCommand());
$app->add(new GenerateInvitationCommand());
$app->add(new ScrivenerImportCommand());
$app->run();