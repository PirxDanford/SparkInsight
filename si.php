<?php

declare(strict_types=1);

use SparkInsight\Command\CheckEnvironmentCommand;
use SparkInsight\Command\BootstrapAdminCommand;
use SparkInsight\Command\GenerateInvitationCommand;
use SparkInsight\Command\InitializeDbCommand;
use SparkInsight\Command\ListUsersCommand;
use SparkInsight\Command\MigrateDbCommand;
use SparkInsight\Command\PurgeContentImportsCommand;
use SparkInsight\Command\ScrivenerImportCommand;
use Symfony\Component\Console\Application;

require __DIR__ . '/vendor/autoload.php';

$app = new Application('SparkInsight CLI', '1.0.0');
$app->add(new CheckEnvironmentCommand());
$app->add(new InitializeDbCommand());
$app->add(new BootstrapAdminCommand());
$app->add(new ListUsersCommand());
$app->add(new MigrateDbCommand());
$app->add(new PurgeContentImportsCommand());
$app->add(new GenerateInvitationCommand());
$app->add(new ScrivenerImportCommand());
$app->run();