<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Doctrine\DBAL\DriverManager;
use SparkInsight\Config\Config;
use SparkInsight\Controller\AdminController;
use SparkInsight\Controller\AuthController;
use SparkInsight\Controller\DashboardController;
use SparkInsight\Service\AppSettingsService;
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\OAuthProviderFactory;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;

require __DIR__ . '/../vendor/autoload.php';

$config = Config::fromEnvironment();
$view = new PhpRenderer(__DIR__ . '/../templates');
$session = new UserSession();
$providerFactory = new OAuthProviderFactory($config);

// Setup database connection
$dbConfig = $config->getDatabaseConfig();
$connection = DriverManager::getConnection([
    'driver' => $dbConfig['driver'],
    'host' => $dbConfig['host'],
    'port' => $dbConfig['port'],
    'dbname' => $dbConfig['dbname'],
    'user' => $dbConfig['user'],
    'password' => $dbConfig['password'],
    'charset' => $dbConfig['charset'],
]);

$invitationService = new InvitationService($connection);
$settingsService = new AppSettingsService($connection);
$userService = new UserService($connection);

$app = AppFactory::create();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$dashboardController = new DashboardController($view, $session, $connection);
$authController = new AuthController($view, $providerFactory, $session, $invitationService, $userService);
$adminController = new AdminController($view, $session, $userService, $invitationService, $settingsService, $config, $connection);

$app->get('/style.css', function ($request, $response) {
    $file = __DIR__ . '/style.css';
    if (file_exists($file)) {
        $response->getBody()->write(file_get_contents($file));
        return $response->withHeader('Content-Type', 'text/css');
    }
    return $response->withStatus(404);
});

$app->get('/assets/sparkinsight-logo.png', function (Request $request, Response $response): Response {
    $file = __DIR__ . '/sparkinsight-logo.png';
    if (!file_exists($file)) {
        return $response->withStatus(404);
    }

    $response->getBody()->write(file_get_contents($file));

    return $response
        ->withHeader('Content-Type', 'image/png')
        ->withHeader('Cache-Control', 'public, max-age=3600');
});

$app->get('/favicon.ico', function ($request, $response) {
    return $response->withStatus(204);
});

$app->get('/', [$dashboardController, '__invoke']);
$app->get('/dashboard', [$dashboardController, '__invoke']);
$app->get('/dashboard/review', [$dashboardController, 'review']);
$app->get('/dashboard/review/{id:[0-9]+}', [$dashboardController, 'reviewItem']);
$app->post('/dashboard/review/{id:[0-9]+}', [$dashboardController, 'submitReview']);
$app->get('/dashboard/author', [$dashboardController, 'author']);
$app->get('/dashboard/author/{id:[0-9]+}', [$dashboardController, 'authorItem']);
$app->post('/dashboard/author/{id:[0-9]+}/review/{reviewId:[0-9]+}/resolve', [$dashboardController, 'resolveAuthorReview']);
$app->post('/dashboard/author/export', [$dashboardController, 'exportAuthorPdf']);
$app->get('/login', [$authController, 'showLogin']);
$app->get('/signup', [$authController, 'showSignUp']);
$app->get('/logout', [$authController, 'logout']);
$app->get('/demo', [$authController, 'demo']);
$app->get('/auth/{provider}', [$authController, 'login']);
$app->get('/callback/{provider}', [$authController, 'callback']);

$app->get('/dashboard/admin', [$adminController, 'dashboard']);
$app->get('/dashboard/admin/users', [$adminController, 'users']);
$app->get('/dashboard/admin/invitations', [$adminController, 'invitations']);
$app->post('/dashboard/admin/invitations', [$adminController, 'createInvitation']);
$app->get('/dashboard/admin/settings', [$adminController, 'settings']);
$app->post('/dashboard/admin/settings', [$adminController, 'saveSettings']);
$app->post('/dashboard/admin/users/{id}/status', [$adminController, 'updateUserStatus']);
$app->post('/dashboard/admin/users/{id}/roles', [$adminController, 'updateUserRoles']);
$app->post('/dashboard/admin/users/{id}/display-name', [$adminController, 'updateUserDisplayName']);
$app->post('/dashboard/admin/invitations/{code:[a-f0-9]{64}}/delete', [$adminController, 'deleteInvitation']);

$app->run();
