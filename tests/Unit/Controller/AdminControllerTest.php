<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\PhpRenderer;
use SparkInsight\Config\Config;
use SparkInsight\Controller\AdminController;
use SparkInsight\Service\AppSettingsService;
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\ReleasePackageIntakeService;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;

class AdminControllerTest extends TestCase
{
    private PhpRenderer $renderer;
    private UserSession $session;
    private UserService $userService;
    private InvitationService $invitationService;
    private ReleasePackageIntakeService $packageIntakeService;
    private AppSettingsService $settingsService;
    private Connection $connection;
    private Config $config;
    private AdminController $controller;

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($this->controller, $method);
        return $reflection->invoke($this->controller, ...$args);
    }

    protected function setUp(): void
    {
        AdminControllerTestHooks::$overrideSysTempDir = null;
        AdminControllerTestHooks::$forcedRealpathInput = null;
        AdminControllerTestHooks::$forcedRealpathResult = null;

        $_ENV['APP_URL'] = 'http://localhost:8000';
        $this->renderer = $this->createMock(PhpRenderer::class);
        $this->session = new UserSession();
        $this->connection = $this->createMock(Connection::class);
        $this->userService = new UserService($this->connection);
        $this->invitationService = new InvitationService($this->connection);
        $this->packageIntakeService = new ReleasePackageIntakeService(sys_get_temp_dir() . '/sparkinsight-admin-tests-' . bin2hex(random_bytes(8)));
        $this->settingsService = new AppSettingsService($this->connection);
        $this->config = Config::fromEnvironment();

        $this->controller = new AdminController(
            $this->renderer,
            $this->session,
            $this->userService,
            $this->invitationService,
            $this->packageIntakeService,
            $this->settingsService,
            $this->config,
            $this->connection,
        );
    }

    protected function tearDown(): void
    {
        AdminControllerTestHooks::$overrideSysTempDir = null;
        AdminControllerTestHooks::$forcedRealpathInput = null;
        AdminControllerTestHooks::$forcedRealpathResult = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];

        unset($_ENV['APP_URL']);
    }

    public function testUsersRedirectsWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->users($request, $response);

        $this->assertSame($response, $result);
    }

    public function testDashboardRendersUnifiedAdminPage(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([
                'section' => 'users',
                'status' => 'active',
                'role' => 'reviewer',
                'search' => 'test',
                'sort' => 'email',
                'dir' => 'ASC',
                'users_page' => '2',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $dbRows = [
            [
                'id' => 1,
                'provider' => null,
                'provider_id' => null,
                'email' => null,
                'name' => 'Test User',
                'display_name' => 'Public Test User',
                'avatar' => null,
                'roles' => json_encode(['reviewer']),
                'status' => 'active',
                'invitation_used' => null,
                'last_login' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
        ];

        $expectedUsers = [
            [
                'id' => 1,
                'provider' => null,
                'provider_id' => null,
                'email' => null,
                'name' => 'Test User',
                'display_name' => 'Public Test User',
                'avatar' => null,
                'roles' => ['reviewer'],
                'status' => 'active',
                'invitation_used' => null,
                'last_login' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
        ];

        $this->connection->expects($this->exactly(6))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls(
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => $dbRows,
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAssociative' => ['count' => 30],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [
                        ['setting_key' => 'invitation_default_hours', 'setting_value' => '168', 'value_type' => 'int'],
                        ['setting_key' => 'invitation_default_roles', 'setting_value' => '["reviewer"]', 'value_type' => 'json'],
                        ['setting_key' => 'import_cron_schedule', 'setting_value' => '0 2 * * *', 'value_type' => 'string'],
                        ['setting_key' => 'invitation_cleanup_cron_schedule', 'setting_value' => '30 2 * * *', 'value_type' => 'string'],
                    ],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAssociative' => ['count' => 1],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [
                        [
                            'id' => 1,
                            'code' => 'abc123',
                            'email' => 'test@example.com',
                            'roles' => json_encode(['reviewer']),
                            'used_by' => null,
                            'used_at' => null,
                            'created_at' => '2026-01-01 00:00:00',
                            'expires_at' => '2026-01-02 00:00:00',
                        ],
                    ],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [
                        ['setting_key' => 'invitation_default_hours', 'setting_value' => '168', 'value_type' => 'int'],
                        ['setting_key' => 'invitation_default_roles', 'setting_value' => '["reviewer"]', 'value_type' => 'json'],
                        ['setting_key' => 'import_cron_schedule', 'setting_value' => '0 2 * * *', 'value_type' => 'string'],
                        ['setting_key' => 'invitation_cleanup_cron_schedule', 'setting_value' => '30 2 * * *', 'value_type' => 'string'],
                    ],
                ])
            );

        $this->renderer->expects($this->once())
            ->method('render')
            ->with(
                $response,
                'admin/dashboard.php',
                $this->callback(function ($data) use ($expectedUsers) {
                    return $data['title'] === 'Admin'
                        && $data['admin_section'] === 'users'
                        && $data['users'] === $expectedUsers
                        && $data['users_filters'] === ['status' => 'active', 'role' => 'reviewer', 'search' => 'test']
                        && $data['users_sort'] === ['email' => 'ASC']
                        && $data['users_page'] === 2
                        && $data['users_total_pages'] === 2.0
                        && $data['users_total'] === 30
                        && is_array($data['invitations'])
                        && is_array($data['settings'])
                        && array_key_exists('maintenance_action_result', $data)
                        && array_key_exists('actions_flash_message', $data);
                })
            )
            ->willReturn($response);

        $result = $this->controller->dashboard($request, $response);

        $this->assertSame($response, $result);
    }

    public function testUpdateUserDisplayNameSuccess(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'display_name' => 'Public Name',
                '_csrf' => $this->session->getCsrfToken(),
            ]);

        $response = new TestResponse();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?',
                $this->callback(function ($params) {
                    return is_array($params)
                        && $params[0] === 'Public Name'
                        && $params[2] === 1;
                })
            )
            ->willReturn(1);

        $result = $this->controller->updateUserDisplayName($request, $response, ['id' => 1]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=users', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'success', 'message' => 'Display name saved.'], $this->session->getFlash());
    }

    public function testUpdateUserDisplayNameInvalidCsrfReturnsBadRequest(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'display_name' => 'Public Name',
                '_csrf' => 'invalid-token',
            ]);

        $response = new TestResponse();

        $result = $this->controller->updateUserDisplayName($request, $response, ['id' => 1]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=users', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Invalid form submission. Please try again.'], $this->session->getFlash());
    }

    public function testDashboardPageAvailabilityRendersWithRealTemplate(): void
    {
        $this->session->setUser(['id' => 1, 'name' => 'Admin', 'roles' => ['admin']]);
        $this->session->setFlash('success', 'Display name saved.');

        $realRenderer = new PhpRenderer(__DIR__ . '/../../../templates');
        $realController = new AdminController(
            $realRenderer,
            $this->session,
            $this->userService,
            $this->invitationService,
            $this->packageIntakeService,
            $this->settingsService,
            $this->config,
            $this->connection,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $response = new TestResponse();

        $this->connection->expects($this->exactly(6))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls(
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAssociative' => ['count' => 0],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [
                        ['setting_key' => 'invitation_default_hours', 'setting_value' => '168', 'value_type' => 'int'],
                        ['setting_key' => 'invitation_default_roles', 'setting_value' => '["reviewer"]', 'value_type' => 'json'],
                        ['setting_key' => 'import_cron_schedule', 'setting_value' => '0 2 * * *', 'value_type' => 'string'],
                        ['setting_key' => 'invitation_cleanup_cron_schedule', 'setting_value' => '30 2 * * *', 'value_type' => 'string'],
                    ],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAssociative' => ['count' => 0],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [
                        ['setting_key' => 'invitation_default_hours', 'setting_value' => '168', 'value_type' => 'int'],
                        ['setting_key' => 'invitation_default_roles', 'setting_value' => '["reviewer"]', 'value_type' => 'json'],
                        ['setting_key' => 'import_cron_schedule', 'setting_value' => '0 2 * * *', 'value_type' => 'string'],
                        ['setting_key' => 'invitation_cleanup_cron_schedule', 'setting_value' => '30 2 * * *', 'value_type' => 'string'],
                    ],
                ])
            );

        $result = $realController->dashboard($request, $response);

        $body = (string) $result->getBody();
        $this->assertStringContainsString('SparkInsight - Admin', $body);
        $this->assertStringContainsString('User Management', $body);
        $this->assertStringContainsString('Display name saved.', $body);
        $this->assertStringContainsString('Invitation Management', $body);
        $this->assertStringContainsString('Admin Settings', $body);
        $this->assertStringContainsString('Admin Actions', $body);
        $this->assertStringContainsString('Maintenance Actions', $body);
    }

    public static function dashboardSectionProvider(): array
    {
        return [
            ['users', 'User Management'],
            ['invitations', 'Invitation Management'],
            ['settings', 'Admin Settings'],
            ['actions', 'Admin Actions'],
        ];
    }

    /**
     * @dataProvider dashboardSectionProvider
     */
    public function testDashboardPageAvailabilityRendersEachSection(string $section, string $expectedHeading): void
    {
        $this->session->setUser(['id' => 1, 'name' => 'Admin', 'roles' => ['admin']]);

        $realRenderer = new PhpRenderer(__DIR__ . '/../../../templates');
        $realController = new AdminController(
            $realRenderer,
            $this->session,
            $this->userService,
            $this->invitationService,
            $this->packageIntakeService,
            $this->settingsService,
            $this->config,
            $this->connection,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['section' => $section]);

        $response = new TestResponse();

        $this->connection->expects($this->exactly(6))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql) {
                if ($sql === 'SELECT setting_key, setting_value, value_type FROM app_settings') {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAllAssociative' => [
                            ['setting_key' => 'invitation_default_hours', 'setting_value' => '168', 'value_type' => 'int'],
                            ['setting_key' => 'invitation_default_roles', 'setting_value' => '["reviewer"]', 'value_type' => 'json'],
                            ['setting_key' => 'import_cron_schedule', 'setting_value' => '0 2 * * *', 'value_type' => 'string'],
                            ['setting_key' => 'invitation_cleanup_cron_schedule', 'setting_value' => '30 2 * * *', 'value_type' => 'string'],
                        ],
                    ]);
                }

                if (str_contains($sql, 'SELECT COUNT(*) as count FROM users')) {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAssociative' => ['count' => 0],
                    ]);
                }

                if (str_contains($sql, 'SELECT COUNT(*) as count FROM invitations')) {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAssociative' => ['count' => 0],
                    ]);
                }

                return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [],
                ]);
            });

        $result = $realController->dashboard($request, $response);

        $body = (string) $result->getBody();
        $this->assertStringContainsString('SparkInsight - Admin', $body);
        $this->assertStringContainsString($expectedHeading, $body);
    }

    public function testUpdateUserStatusForbiddenWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');

        $response = new TestResponse();

        $result = $this->controller->updateUserStatus($request, $response, ['id' => 1]);

        $this->assertNotSame($response, $result);
        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('Forbidden', (string) $result->getBody());
    }

    public function testUpdateUserStatusReturnsBadRequestForInvalidStatus(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'status' => 'invalid',
                '_csrf' => $this->session->getCsrfToken(),
            ]);

        $response = new TestResponse();

        $result = $this->controller->updateUserStatus($request, $response, ['id' => 1]);

        $this->assertNotSame($response, $result);
        $this->assertSame(400, $result->getStatusCode());
        $this->assertStringContainsString('Invalid request', (string) $result->getBody());
    }

    public function testUpdateUserStatusSuccess(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'status' => 'active',
                '_csrf' => $this->session->getCsrfToken(),
            ]);

        $response = new TestResponse();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE users SET status = ?, updated_at = ? WHERE id = ?',
                $this->callback(function ($params) {
                    return is_array($params)
                        && $params[0] === 'active'
                        && $params[2] === 1;
                })
            )
            ->willReturn(1);

        $result = $this->controller->updateUserStatus($request, $response, ['id' => 1]);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertJsonStringEqualsJsonString('{"success":true}', (string) $result->getBody());
    }

    public function testUpdateUserStatusForbiddenWithoutAjaxRedirects(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('');

        $response = new TestResponse();
        $result = $this->controller->updateUserStatus($request, $response, ['id' => 1]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=users', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Forbidden'], $this->session->getFlash());
    }

    public function testUpdateUserStatusInvalidCsrfWithoutAjaxRedirects(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('');
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'status' => 'active',
                '_csrf' => 'invalid-token',
            ]);

        $response = new TestResponse();
        $result = $this->controller->updateUserStatus($request, $response, ['id' => 1]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=users', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Invalid CSRF token.'], $this->session->getFlash());
    }

    public function testUpdateUserRolesSuccess(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'roles' => ['reviewer'],
                '_csrf' => $this->session->getCsrfToken(),
            ]);

        $response = new TestResponse();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE users SET roles = ?, updated_at = ? WHERE id = ?',
                $this->callback(function ($params) {
                    return is_array($params)
                        && json_decode($params[0], true) === ['reviewer']
                        && $params[2] === 1;
                })
            )
            ->willReturn(1);

        $result = $this->controller->updateUserRoles($request, $response, ['id' => 1]);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertJsonStringEqualsJsonString('{"success":true}', (string) $result->getBody());
    }

    public function testUpdateUserStatusWithInvalidCsrfReturnsBadRequest(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'status' => 'active',
                '_csrf' => 'invalid-token',
            ]);

        $response = new TestResponse();

        $result = $this->controller->updateUserStatus($request, $response, ['id' => 1]);

        $this->assertNotSame($response, $result);
        $this->assertSame(400, $result->getStatusCode());
        $this->assertStringContainsString('Invalid CSRF token', (string) $result->getBody());
    }

    public function testInvitationsRedirectWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->invitations($request, $response);

        $this->assertSame($response, $result);
    }

    public function testInvitationsRouteRedirectsToUnifiedAdminSection(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['page' => '2']);

        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/admin?page=2&section=invitations')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->invitations($request, $response);

        $this->assertSame($response, $result);
    }

    public function testSettingsRouteRedirectsToUnifiedAdminSection(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/dashboard/admin?section=settings')
            ->willReturn($response);

        $response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturn($response);

        $result = $this->controller->settings($request, $response);

        $this->assertSame($response, $result);
    }

    public function testSaveSettingsRedirectsForInvalidCsrf(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => 'invalid-token',
                'invitation_default_hours' => '168',
            ]);

        $response = new TestResponse();
        $result = $this->controller->saveSettings($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=settings'], $result->getHeader('Location'));
    }

    public function testSaveSettingsPersistsData(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
                'invitation_default_hours' => '200',
                'invitation_default_roles' => ['reviewer', 'author'],
                'import_cron_schedule' => '0 3 * * *',
                'invitation_cleanup_cron_schedule' => '30 3 * * *',
            ]);

        $response = new TestResponse();

        $this->connection->expects($this->exactly(4))
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO app_settings'),
                $this->callback(static function ($params): bool {
                    return is_array($params) && count($params) === 3;
                })
            )
            ->willReturn(1);

        $result = $this->controller->saveSettings($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=settings'], $result->getHeader('Location'));
    }

    public function testCreateInvitationForAdmin(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
                'email' => 'test@example.com',
                'roles' => ['reviewer'],
                'hours' => '48',
            ]);

        $response = new TestResponse();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'INSERT INTO invitations (code, email, roles, created_at, expires_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)',
                $this->callback(function ($params) {
                    return $params[1] === 'test@example.com' && $params[2] === json_encode(['reviewer']);
                })
            )
            ->willReturn(1);

        $result = $this->controller->createInvitation($request, $response);

        $this->assertNotSame($response, $result);
        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=invitations'], $result->getHeader('Location'));
    }

    public function testCreateInvitationWithInvalidCsrfShowsError(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => 'invalid-token',
                'email' => 'test@example.com',
                'roles' => ['reviewer'],
                'hours' => '24',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $this->connection->expects($this->exactly(6))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql) {
                if ($sql === 'SELECT setting_key, setting_value, value_type FROM app_settings') {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAllAssociative' => [
                            ['setting_key' => 'invitation_default_hours', 'setting_value' => '168', 'value_type' => 'int'],
                            ['setting_key' => 'invitation_default_roles', 'setting_value' => '["reviewer"]', 'value_type' => 'json'],
                            ['setting_key' => 'import_cron_schedule', 'setting_value' => '0 2 * * *', 'value_type' => 'string'],
                            ['setting_key' => 'invitation_cleanup_cron_schedule', 'setting_value' => '30 2 * * *', 'value_type' => 'string'],
                        ],
                    ]);
                }

                if (str_contains($sql, 'SELECT * FROM users')) {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAllAssociative' => [],
                    ]);
                }

                if (str_contains($sql, 'SELECT COUNT(*) as count FROM users')) {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAssociative' => ['count' => 0],
                    ]);
                }

                if (str_contains($sql, 'SELECT COUNT(*) as count FROM invitations')) {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAssociative' => ['count' => 0],
                    ]);
                }

                return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [],
                ]);
            });

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'admin/dashboard.php', $this->callback(function ($data) {
                return $data['invitation_flash_message']['type'] === 'error'
                    && str_contains($data['invitation_flash_message']['message'], 'Invalid form submission')
                    && $data['invitation_email'] === 'test@example.com'
                    && $data['admin_section'] === 'invitations';
            }))
            ->willReturn($response);

        $result = $this->controller->createInvitation($request, $response);

        $this->assertSame($response, $result);
    }

    public function testCreateInvitationWithInvalidEmailShowsError(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
                'email' => 'not-an-email',
                'roles' => ['reviewer'],
                'hours' => '168',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $this->connection->expects($this->exactly(6))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql) {
                if ($sql === 'SELECT setting_key, setting_value, value_type FROM app_settings') {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAllAssociative' => [
                            ['setting_key' => 'invitation_default_hours', 'setting_value' => '168', 'value_type' => 'int'],
                            ['setting_key' => 'invitation_default_roles', 'setting_value' => '["reviewer"]', 'value_type' => 'json'],
                            ['setting_key' => 'import_cron_schedule', 'setting_value' => '0 2 * * *', 'value_type' => 'string'],
                            ['setting_key' => 'invitation_cleanup_cron_schedule', 'setting_value' => '30 2 * * *', 'value_type' => 'string'],
                        ],
                    ]);
                }

                if (str_contains($sql, 'SELECT * FROM users')) {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAllAssociative' => [],
                    ]);
                }

                if (str_contains($sql, 'SELECT COUNT(*) as count FROM users')) {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAssociative' => ['count' => 0],
                    ]);
                }

                if (str_contains($sql, 'SELECT COUNT(*) as count FROM invitations')) {
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAssociative' => ['count' => 0],
                    ]);
                }

                return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [],
                ]);
            });

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'admin/dashboard.php', $this->callback(function ($data) {
                return $data['invitation_flash_message']['type'] === 'error'
                    && str_contains($data['invitation_flash_message']['message'], 'Email address is invalid')
                    && $data['invitation_email'] === 'not-an-email'
                    && $data['admin_section'] === 'invitations';
            }))
            ->willReturn($response);

        $result = $this->controller->createInvitation($request, $response);

        $this->assertSame($response, $result);
    }

    public function testDeleteInvitationSuccess(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
            ]);

        $response = new TestResponse();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with('DELETE FROM invitations WHERE code = ?', ['abc123'])
            ->willReturn(1);

        $result = $this->controller->deleteInvitation($request, $response, ['code' => 'abc123']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=invitations'], $result->getHeader('Location'));
    }

    public function testDeleteInvitationWithInvalidCsrfRedirects(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => 'invalid-token',
            ]);

        $response = new TestResponse();

        $this->connection->expects($this->never())->method('executeStatement');

        $result = $this->controller->deleteInvitation($request, $response, ['code' => 'abc123']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=invitations'], $result->getHeader('Location'));
    }

    public function testUpdateUserStatusSuccessWithoutAjaxRedirects(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('');
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
                'status' => 'active',
            ]);

        $response = new TestResponse();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'UPDATE users SET status = ?, updated_at = ? WHERE id = ?',
                $this->callback(function (array $params): bool {
                    return $params[0] === 'active'
                        && is_string($params[1])
                        && $params[2] === 1;
                })
            )
            ->willReturn(1);

        $result = $this->controller->updateUserStatus($request, $response, ['id' => 1]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=users'], $result->getHeader('Location'));
        $this->assertSame(['type' => 'success', 'message' => 'User status updated.'], $this->session->getFlash());
    }

    public function testUploadReleasePackageWithMissingFileShowsError(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['_csrf' => $csrfToken]);
        $request->expects($this->once())
            ->method('getUploadedFiles')
            ->willReturn([]);

        $response = new TestResponse();
        $result = $this->controller->uploadReleasePackage($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=settings'], $result->getHeader('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Select a valid release package ZIP before uploading.'], $this->session->getFlash());
    }

    public function testRunMaintenanceActionWithUnsupportedActionStoresFailure(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
                'maintenance_action' => 'not_supported',
            ]);

        $response = new TestResponse();
        $result = $this->controller->runMaintenanceAction($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=settings'], $result->getHeader('Location'));
        $stored = $this->session->getData('maintenance_action_result');
        $this->assertIsArray($stored);
        $this->assertSame(1, $stored['exit_code']);
        $this->assertStringContainsString('Unsupported maintenance action', (string) $stored['output']);
    }

    public function testRunMaintenanceActionCheckEnvironmentStoresResult(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
                'maintenance_action' => 'check_environment',
            ]);

        $response = new TestResponse();
        $result = $this->controller->runMaintenanceAction($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=settings'], $result->getHeader('Location'));
        $stored = $this->session->getData('maintenance_action_result');
        $this->assertIsArray($stored);
        $this->assertSame('check-environment', $stored['command']);
    }

    public function testDeployReleasePackageRequiresOperationId(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
            ]);

        $response = new TestResponse();
        $result = $this->controller->deployReleasePackage($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=settings'], $result->getHeader('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Operation id is required to deploy a package.'], $this->session->getFlash());
    }

    public function testDeployReleasePackageRunsDeployOperation(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
                'operation_id' => 'op-123',
            ]);

        $response = new TestResponse();
        $result = $this->controller->deployReleasePackage($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame(['/dashboard/admin?section=settings'], $result->getHeader('Location'));
        $deploy = $this->session->getData('release_deploy_result');
        $this->assertIsArray($deploy);
        $this->assertSame('op-123', $deploy['operation_id']);
    }

    public function testExportLiveManifestSuccessResponse(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $csrfToken,
            ]);

        $response = new TestResponse();
        $result = $this->controller->exportLiveManifest($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="sparkinsight-live-base-', $result->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('"format":', (string) $result->getBody());
    }

    public function testExportLiveManifestUsesFallbackReleaseIdAndDefaultPhpWhenComposerMissing(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $projectRoot = dirname(__DIR__, 3);
        $pointerPath = $projectRoot . '/.deploy/current.json';
        $composerPath = $projectRoot . '/composer.json';
        $tmpDir = $projectRoot . '/tmp';
        $tmpFile = $tmpDir . '/admin-export-fallback.txt';

        @mkdir($tmpDir, 0777, true);
        file_put_contents($tmpFile, 'fallback-export');

        $pointerOriginal = is_file($pointerPath) ? (string) file_get_contents($pointerPath) : null;
        $composerOriginal = is_file($composerPath) ? (string) file_get_contents($composerPath) : null;

        @mkdir(dirname($pointerPath), 0777, true);
        file_put_contents($pointerPath, json_encode([
            'format' => 1,
            'current' => null,
            'previous' => null,
            'activated_at' => null,
            'operation_id' => null,
        ], JSON_PRETTY_PRINT));

        if ($composerOriginal !== null) {
            @unlink($composerPath);
        }

        try {
            $request = $this->createMock(ServerRequestInterface::class);
            $request->expects($this->once())
                ->method('getParsedBody')
                ->willReturn([
                    '_csrf' => $csrfToken,
                ]);

            $response = new TestResponse();
            $result = $this->controller->exportLiveManifest($request, $response);

            $this->assertSame(200, $result->getStatusCode());
            $manifest = json_decode((string) $result->getBody(), true);
            $this->assertIsArray($manifest);
            $this->assertSame('8.1.0', $manifest['minimum_php'] ?? null);
            $this->assertSame('SparkInsight', $manifest['release_id'] ?? null);
            $composerLockHash = (string) ($manifest['composer_lock_sha256'] ?? '');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $composerLockHash);
        } finally {
            @unlink($tmpFile);
            if ($pointerOriginal === null) {
                @unlink($pointerPath);
            } else {
                file_put_contents($pointerPath, $pointerOriginal);
            }

            if ($composerOriginal !== null) {
                file_put_contents($composerPath, $composerOriginal);
            }
        }
    }

    public function testExportLiveManifestUsesDefaultPhpWhenComposerJsonIsInvalid(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $projectRoot = dirname(__DIR__, 3);
        $composerPath = $projectRoot . '/composer.json';
        $composerOriginal = is_file($composerPath) ? (string) file_get_contents($composerPath) : null;

        file_put_contents($composerPath, '{invalid-json');

        try {
            $request = $this->createMock(ServerRequestInterface::class);
            $request->expects($this->once())
                ->method('getParsedBody')
                ->willReturn([
                    '_csrf' => $csrfToken,
                ]);

            $response = new TestResponse();
            $result = $this->controller->exportLiveManifest($request, $response);

            $this->assertSame(200, $result->getStatusCode());
            $manifest = json_decode((string) $result->getBody(), true);
            $this->assertIsArray($manifest);
            $this->assertSame('8.1.0', $manifest['minimum_php'] ?? null);
        } finally {
            if ($composerOriginal !== null) {
                file_put_contents($composerPath, $composerOriginal);
            }
        }
    }

    public function testDashboardRedirectsWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->dashboard($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testUsersRouteRedirectsToUnifiedAdminSectionWithParams(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['page' => '3', 'sort' => 'name']);

        $response = new TestResponse();
        $result = $this->controller->users($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?page=3&sort=name&section=users', $result->getHeaderLine('Location'));
    }

    public function testCreateInvitationRedirectsWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->createInvitation($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testDeleteInvitationRedirectsWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->deleteInvitation($request, $response, ['code' => 'abc']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testDeleteInvitationRequiresCode(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['_csrf' => $this->session->getCsrfToken()]);

        $response = new TestResponse();
        $result = $this->controller->deleteInvitation($request, $response, ['code' => '']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=invitations', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Invitation code is required.'], $this->session->getFlash());
    }

    public function testDeleteInvitationRejectsUsedInvitation(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['_csrf' => $this->session->getCsrfToken()]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                'fetchAssociative' => [
                    'id' => 1,
                    'code' => 'abc123',
                    'email' => null,
                    'roles' => '[]',
                    'used_by' => 1,
                    'used_by_name' => 'Admin',
                    'used_by_email' => 'admin@example.com',
                    'used_at' => '2026-01-01 00:00:00',
                    'created_at' => '2026-01-01 00:00:00',
                    'expires_at' => '2026-02-01 00:00:00',
                ],
            ]));

        $response = new TestResponse();
        $result = $this->controller->deleteInvitation($request, $response, ['code' => 'abc123']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=invitations', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Used invitations cannot be deleted.'], $this->session->getFlash());
    }

    public function testSaveSettingsRedirectsWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->saveSettings($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testSaveSettingsShowsErrorWhenPersistenceFails(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'invitation_default_hours' => '200',
                'invitation_default_roles' => ['reviewer'],
                'import_cron_schedule' => '* * * * *',
                'invitation_cleanup_cron_schedule' => '* * * * *',
            ]);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willThrowException(new \RuntimeException('db failure'));

        $response = new TestResponse();
        $result = $this->controller->saveSettings($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
        $flash = $this->session->getFlash();
        $this->assertNotNull($flash);
        $this->assertStringContainsString('Settings could not be saved', (string) $flash['message']);
    }

    public function testUploadReleasePackageRedirectsWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->uploadReleasePackage($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testUploadReleasePackageRedirectsForInvalidCsrf(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['_csrf' => 'invalid']);

        $response = new TestResponse();
        $result = $this->controller->uploadReleasePackage($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Invalid form submission. Please try again.'], $this->session->getFlash());
    }

    public function testUploadReleasePackageStoresFailureWhenMoveFails(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['_csrf' => $this->session->getCsrfToken()]);
        $request->expects($this->once())
            ->method('getUploadedFiles')
            ->willReturn([
                'release_package' => new TestUploadedFile(UPLOAD_ERR_OK, true),
            ]);

        $response = new TestResponse();
        $result = $this->controller->uploadReleasePackage($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
        $stored = $this->session->getData('release_package_result');
        $this->assertIsArray($stored);
        $this->assertArrayHasKey('error', $stored);
        $this->assertStringContainsString('simulated move failure', (string) $stored['error']);
    }

    public function testUploadReleasePackageStoresFailureWhenTempDirectoryCannotBeCreated(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $blockedRoot = sys_get_temp_dir() . '/sparkinsight-upload-blocked-' . bin2hex(random_bytes(8));
        file_put_contents($blockedRoot, 'blocked-temp-root');
        AdminControllerTestHooks::$overrideSysTempDir = $blockedRoot;

        try {
            $request = $this->createMock(ServerRequestInterface::class);
            $request->expects($this->once())
                ->method('getParsedBody')
                ->willReturn(['_csrf' => $this->session->getCsrfToken()]);
            $request->expects($this->once())
                ->method('getUploadedFiles')
                ->willReturn([
                    'release_package' => new TestUploadedFile(UPLOAD_ERR_OK),
                ]);

            $response = new TestResponse();
            set_error_handler(static fn (): bool => true);
            $result = $this->controller->uploadReleasePackage($request, $response);
            restore_error_handler();

            $this->assertSame(302, $result->getStatusCode());
            $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
            $stored = $this->session->getData('release_package_result');
            $this->assertIsArray($stored);
            $this->assertStringContainsString('temporary upload directory', (string) ($stored['error'] ?? ''));
        } finally {
            restore_error_handler();
            AdminControllerTestHooks::$overrideSysTempDir = null;
            @unlink($blockedRoot);
        }
    }

    public function testUploadReleasePackageProcessesValidSignedPackage(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Required ZIP or Sodium support is unavailable.');
        }

        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $projectRoot = dirname(__DIR__, 3);
        $trustedKeyPath = $projectRoot . '/.deploy/trusted-release-key.pub';
        $trustedKeyOriginal = is_file($trustedKeyPath) ? (string) file_get_contents($trustedKeyPath) : null;
        @mkdir(dirname($trustedKeyPath), 0777, true);

        $tempDir = sys_get_temp_dir() . '/sparkinsight-upload-valid-' . bin2hex(random_bytes(8));
        @mkdir($tempDir, 0777, true);
        $packagePath = $tempDir . '/release.zip';

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        file_put_contents($trustedKeyPath, $publicKey);

        $manifest = [
            'format' => \SparkInsight\Service\ReleaseManifest::FORMAT,
            'application' => \SparkInsight\Service\ReleaseManifest::APPLICATION,
            'package_id' => 'package-upload-001',
            'package_type' => 'full',
            'release_id' => 'release-upload-001',
            'created_at' => '2026-08-08T16:00:00+00:00',
            'minimum_php' => '8.1.0',
            'required_extensions' => ['json', 'openssl', 'pdo'],
            'composer_lock_sha256' => str_repeat('a', 64),
            'expanded_size' => 5,
            'file_count' => 1,
            'files' => [
                ['path' => 'src/Controller/HomeController.php', 'size' => 5, 'sha256' => hash('sha256', 'hello')],
            ],
            'payload_files' => ['src/Controller/HomeController.php'],
            'delete' => [],
        ];
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($manifestJson, $secretKey);

        $zip = new \ZipArchive();
        $zip->open($packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('manifest.sig', $signature);
        $zip->addFromString('payload/src/Controller/HomeController.php', 'hello');
        $zip->close();

        try {
            $request = $this->createMock(ServerRequestInterface::class);
            $request->expects($this->once())
                ->method('getParsedBody')
                ->willReturn(['_csrf' => $this->session->getCsrfToken()]);
            $request->expects($this->once())
                ->method('getUploadedFiles')
                ->willReturn([
                    'release_package' => new TestUploadedFile(UPLOAD_ERR_OK, false, $packagePath),
                ]);

            $response = new TestResponse();
            $result = $this->controller->uploadReleasePackage($request, $response);

            $this->assertSame(302, $result->getStatusCode());
            $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
            $stored = $this->session->getData('release_package_result');
            $this->assertIsArray($stored);
            $this->assertSame('package-upload-001', $stored['package_id'] ?? null);
            $this->assertSame('full', $stored['package_type'] ?? null);
            $this->assertSame('release-upload-001', $stored['release_id'] ?? null);
            $this->assertArrayHasKey('deploy_exit_code', $stored);
        } finally {
            @unlink($packagePath);
            @rmdir($tempDir);

            if ($trustedKeyOriginal === null) {
                @unlink($trustedKeyPath);
            } else {
                file_put_contents($trustedKeyPath, $trustedKeyOriginal);
            }
        }
    }

    public function testRunMaintenanceActionRedirectsWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->runMaintenanceAction($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testRunMaintenanceActionRedirectsForInvalidCsrf(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => 'bad-token',
                'maintenance_action' => 'check_environment',
            ]);

        $response = new TestResponse();
        $result = $this->controller->runMaintenanceAction($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Invalid form submission. Please try again.'], $this->session->getFlash());
    }

    public function testRunMaintenanceActionPurgeRequiresBatchId(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'maintenance_action' => 'content_purge_import',
                'confirm_purge' => 'PURGE',
                'import_batch_id' => '',
            ]);

        $response = new TestResponse();
        $result = $this->controller->runMaintenanceAction($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $stored = $this->session->getData('maintenance_action_result');
        $this->assertIsArray($stored);
        $this->assertStringContainsString('Import batch ID is required', (string) $stored['output']);
    }

    public function testRunMaintenanceActionPurgeRequiresConfirmation(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'maintenance_action' => 'content_purge_import',
                'confirm_purge' => 'NOPE',
                'import_batch_id' => 'batch-1',
            ]);

        $response = new TestResponse();
        $result = $this->controller->runMaintenanceAction($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $stored = $this->session->getData('maintenance_action_result');
        $this->assertIsArray($stored);
        $this->assertStringContainsString('Type PURGE', (string) $stored['output']);
    }

    public function testDeployReleasePackageRedirectsWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->deployReleasePackage($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testDeployReleasePackageRedirectsForInvalidCsrf(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => 'bad-token',
                'operation_id' => 'op-1',
            ]);

        $response = new TestResponse();
        $result = $this->controller->deployReleasePackage($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Invalid form submission. Please try again.'], $this->session->getFlash());
    }

    public function testDeployReleasePackageStoresFailureForInvalidOperationIdPattern(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'operation_id' => 'invalid id with spaces',
            ]);

        $response = new TestResponse();
        $result = $this->controller->deployReleasePackage($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
        $deploy = $this->session->getData('release_deploy_result');
        $this->assertIsArray($deploy);
        $this->assertSame(1, $deploy['exit_code']);
        $this->assertStringContainsString('invalid characters', (string) $deploy['output']);
        $flash = $this->session->getFlash();
        $this->assertIsArray($flash);
        $this->assertStringContainsString('Release deployment failed:', (string) $flash['message']);
    }

    public function testUpdateUserRolesForbiddenWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');
        $response = new TestResponse();

        $result = $this->controller->updateUserRoles($request, $response, ['id' => 1]);

        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('Forbidden', (string) $result->getBody());
    }

    public function testUpdateUserRolesInvalidCsrfReturnsBadRequest(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'roles' => ['reviewer'],
                '_csrf' => 'invalid-token',
            ]);
        $response = new TestResponse();

        $result = $this->controller->updateUserRoles($request, $response, ['id' => 1]);

        $this->assertSame(400, $result->getStatusCode());
        $this->assertStringContainsString('Invalid CSRF token', (string) $result->getBody());
    }

    public function testUpdateUserRolesInvalidRequestReturnsBadRequest(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'roles' => 'reviewer',
                '_csrf' => $this->session->getCsrfToken(),
            ]);
        $response = new TestResponse();

        $result = $this->controller->updateUserRoles($request, $response, ['id' => 1]);

        $this->assertSame(400, $result->getStatusCode());
        $this->assertStringContainsString('Invalid request', (string) $result->getBody());
    }

    public function testUpdateUserRolesSuccessWithoutAjaxRedirects(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('X-Requested-With')
            ->willReturn('');
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'roles' => ['reviewer', 'author'],
                '_csrf' => $this->session->getCsrfToken(),
            ]);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $response = new TestResponse();
        $result = $this->controller->updateUserRoles($request, $response, ['id' => 1]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=users', $result->getHeaderLine('Location'));
    }

    public function testUpdateUserDisplayNameRedirectsWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->updateUserDisplayName($request, $response, ['id' => 1]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testUpdateUserDisplayNameRejectsMissingUserId(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'display_name' => 'Name',
            ]);
        $response = new TestResponse();

        $result = $this->controller->updateUserDisplayName($request, $response, []);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=users', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Invalid user selection.'], $this->session->getFlash());
    }

    public function testUpdateUserDisplayNameRejectsLongName(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                '_csrf' => $this->session->getCsrfToken(),
                'display_name' => str_repeat('a', 256),
            ]);
        $response = new TestResponse();

        $result = $this->controller->updateUserDisplayName($request, $response, ['id' => 2]);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=users', $result->getHeaderLine('Location'));
        $this->assertSame(['type' => 'error', 'message' => 'Display name is too long.'], $this->session->getFlash());
    }

    public function testSettingsRouteRedirectsWhenLoggedInUserIsNotAdmin(): void
    {
        $this->session->setUser(['id' => 2, 'roles' => ['reviewer']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->settings($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testBuildSectionsConsumeSessionStoredResults(): void
    {
        $this->session->setData('release_package_result', ['ok' => true]);
        $this->session->setData('release_deploy_result', ['ok' => true]);
        $this->session->setData('maintenance_action_result', ['ok' => true]);

        $settings = $this->invokePrivate('buildSettingsSectionData', null);
        $actions = $this->invokePrivate('buildActionsSectionData', null);

        $this->assertSame(['ok' => true], $settings['release_package_result']);
        $this->assertSame(['ok' => true], $settings['release_deploy_result']);
        $this->assertSame(['ok' => true], $actions['maintenance_action_result']);
        $this->assertNull($this->session->getData('release_package_result'));
        $this->assertNull($this->session->getData('release_deploy_result'));
        $this->assertNull($this->session->getData('maintenance_action_result'));
    }

    public function testBuildInvitationsSectionDataClearsSavedInvitationStateWithoutOverrides(): void
    {
        $this->session->setData('invitation_code', 'code-1');
        $this->session->setData('invitation_email', 'saved@example.com');
        $this->session->setData('invitation_roles', ['author']);
        $this->session->setData('invitation_hours', 12);

        $this->connection->expects($this->exactly(3))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls(
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [
                        ['setting_key' => 'invitation_default_hours', 'setting_value' => '168', 'value_type' => 'int'],
                        ['setting_key' => 'invitation_default_roles', 'setting_value' => '["reviewer"]', 'value_type' => 'json'],
                    ],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAssociative' => ['count' => 0],
                ]),
                $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => [],
                ]),
            );

        $result = $this->invokePrivate('buildInvitationsSectionData', ['invitations_page' => 999], [], null);

        $this->assertSame('code-1', $result['invitation_code']);
        $this->assertSame(1, $result['invitations_page']);
        $this->assertNull($this->session->getData('invitation_code'));
        $this->assertNull($this->session->getData('invitation_email'));
        $this->assertNull($this->session->getData('invitation_roles'));
        $this->assertNull($this->session->getData('invitation_hours'));
    }

    public function testPrivateExportHelpersCoverFallbackBranches(): void
    {
        $root = sys_get_temp_dir() . '/sparkinsight-admin-private-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/file.php', '<?php echo 1;');
        file_put_contents($root . '/composer.json', json_encode(['require' => ['php' => '^8.3 || >=8.2.4']], JSON_THROW_ON_ERROR));

        try {
            $releaseId = $this->invokePrivate('resolveReleaseIdFromRoot', DIRECTORY_SEPARATOR);
            $this->assertStringStartsWith('release-', $releaseId);

            $invalidId = $this->invokePrivate('resolveReleaseIdFromRoot', $root . '/bad release id');
            $this->assertStringStartsWith('release-', $invalidId);

            $minimumPhp = $this->invokePrivate('determineMinimumPhpVersion', $root);
            $this->assertSame('8.2.4', $minimumPhp);

            file_put_contents($root . '/composer.json', json_encode(['require' => ['php' => 'not-a-version']], JSON_THROW_ON_ERROR));
            $this->assertSame('8.1.0', $this->invokePrivate('determineMinimumPhpVersion', $root));

            file_put_contents($root . '/composer.json', json_encode(['require' => ['php' => '']], JSON_THROW_ON_ERROR));
            $this->assertSame('8.1.0', $this->invokePrivate('determineMinimumPhpVersion', $root));

            $this->assertNull($this->invokePrivate('hashFile', $root . '/missing.lock'));

            @mkdir($root . '/.deploy', 0777, true);
            file_put_contents($root . '/.deploy/current.json', json_encode([
                'format' => 1,
                'current' => 'from-pointer-release',
                'previous' => null,
                'activated_at' => null,
                'operation_id' => null,
            ], JSON_THROW_ON_ERROR));

            $manifest = $this->invokePrivate('buildLiveBaseManifest', $root);
            $this->assertSame('full', $manifest['package_type']);
            $this->assertSame('from-pointer-release', $manifest['release_id']);
            $this->assertIsArray($manifest['files']);
            $this->assertIsArray($manifest['payload_files']);
        } finally {
            @unlink($root . '/.deploy/current.json');
            @rmdir($root . '/.deploy');
            @unlink($root . '/src/file.php');
            @unlink($root . '/composer.json');
            @rmdir($root . '/src');
            @rmdir($root);
        }
    }

    public function testExportLiveManifestHandlesPointerReadFailure(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $csrfToken = $this->session->getCsrfToken();

        $projectRoot = dirname(__DIR__, 3);
        $pointerPath = $projectRoot . '/.deploy/current.json';
        $pointerOriginal = is_file($pointerPath) ? (string) file_get_contents($pointerPath) : null;
        @mkdir(dirname($pointerPath), 0777, true);
        file_put_contents($pointerPath, '{invalid-json');

        try {
            $request = $this->createMock(ServerRequestInterface::class);
            $request->expects($this->once())
                ->method('getParsedBody')
                ->willReturn(['_csrf' => $csrfToken]);

            $response = new TestResponse();
            $result = $this->controller->exportLiveManifest($request, $response);

            $this->assertSame(302, $result->getStatusCode());
            $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
            $flash = $this->session->getFlash();
            $this->assertNotNull($flash);
            $this->assertStringContainsString('Could not export live manifest', (string) $flash['message']);
        } finally {
            if ($pointerOriginal === null) {
                @unlink($pointerPath);
            } else {
                file_put_contents($pointerPath, $pointerOriginal);
            }
        }
    }

    public function testExportLiveManifestRedirectsWhenLoggedInUserIsNotAdmin(): void
    {
        $this->session->setUser(['id' => 2, 'roles' => ['reviewer']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $response = new TestResponse();

        $result = $this->controller->exportLiveManifest($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }

    public function testExportLiveManifestRedirectsForInvalidCsrf(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['_csrf' => 'invalid']);
        $response = new TestResponse();

        $result = $this->controller->exportLiveManifest($request, $response);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/dashboard/admin?section=settings', $result->getHeaderLine('Location'));
    }

    public function testResolveReleaseIdFromRootHandlesUnresolvedPath(): void
    {
        $value = $this->invokePrivate('resolveReleaseIdFromRoot', sys_get_temp_dir() . '/definitely-missing-' . bin2hex(random_bytes(6)));
        $this->assertIsString($value);
        $this->assertNotSame('', $value);
    }

    public function testResolveReleaseIdFromRootFallsBackWhenResolvedRootBasenameIsEmpty(): void
    {
        $input = 'sparkinsight-mock-root-' . bin2hex(random_bytes(4));
        AdminControllerTestHooks::$forcedRealpathInput = $input;
        AdminControllerTestHooks::$forcedRealpathResult = DIRECTORY_SEPARATOR;

        try {
            $value = $this->invokePrivate('resolveReleaseIdFromRoot', $input);
            $this->assertStringStartsWith('release-', $value);
        } finally {
            AdminControllerTestHooks::$forcedRealpathInput = null;
            AdminControllerTestHooks::$forcedRealpathResult = null;
        }
    }
}

final class AdminControllerTestHooks
{
    public static ?string $overrideSysTempDir = null;
    public static ?string $forcedRealpathInput = null;
    public static string|false|null $forcedRealpathResult = null;
}

final class TestUploadedFile implements UploadedFileInterface
{
    public function __construct(
        private readonly int $error,
        private readonly bool $throwOnMove = false,
        private readonly ?string $sourcePath = null,
    ) {
    }

    public function getStream(): \Psr\Http\Message\StreamInterface
    {
        return new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
    }

    public function moveTo($targetPath): void
    {
        if ($this->throwOnMove) {
            throw new \RuntimeException('simulated move failure');
        }

        if ($this->sourcePath !== null) {
            copy($this->sourcePath, (string) $targetPath);
            return;
        }

        file_put_contents((string) $targetPath, 'uploaded-content');
    }

    public function getSize(): ?int
    {
        return 16;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return 'release.zip';
    }

    public function getClientMediaType(): ?string
    {
        return 'application/zip';
    }
}

class TestResponse implements ResponseInterface
{
    private \Psr\Http\Message\StreamInterface $body;
    private int $status = 200;
    private string $reasonPhrase = '';
    private string $protocolVersion = '1.1';
    private array $headers = [];

    public function __construct()
    {
        $this->body = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
    }

    public function __toString(): string
    {
        return (string) $this->body;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): static
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader($name): bool
    {
        return isset($this->headers[$name]);
    }

    public function getHeader($name): array
    {
        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader($name, $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = is_array($value) ? $value : [$value];
        return $clone;
    }

    public function withAddedHeader($name, $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = array_merge($clone->headers[$name] ?? [], is_array($value) ? $value : [$value]);
        return $clone;
    }

    public function withoutHeader($name): static
    {
        $clone = clone $this;
        unset($clone->headers[$name]);
        return $clone;
    }

    public function getBody(): \Psr\Http\Message\StreamInterface
    {
        return $this->body;
    }

    public function withBody(\Psr\Http\Message\StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus($code, $reasonPhrase = ''): static
    {
        $clone = clone $this;
        $clone->status = $code;
        $clone->reasonPhrase = $reasonPhrase;
        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function write(string $data): static
    {
        $this->body->write($data);
        return $this;
    }

    public function withJson($data): static
    {
        return $this;
    }
}

namespace SparkInsight\Controller;

function sys_get_temp_dir(): string
{
    $override = \SparkInsightTest\Unit\Controller\AdminControllerTestHooks::$overrideSysTempDir;
    if ($override !== null) {
        return $override;
    }

    return \sys_get_temp_dir();
}

function realpath(string $path): string|false
{
    if (\SparkInsightTest\Unit\Controller\AdminControllerTestHooks::$forcedRealpathInput === $path) {
        $forced = \SparkInsightTest\Unit\Controller\AdminControllerTestHooks::$forcedRealpathResult;
        return $forced === null ? false : $forced;
    }

    return \realpath($path);
}

