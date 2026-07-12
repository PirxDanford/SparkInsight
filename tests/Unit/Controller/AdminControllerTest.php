<?php

declare(strict_types=1);

namespace SparkInsightTest\Unit\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\PhpRenderer;
use SparkInsight\Config\Config;
use SparkInsight\Controller\AdminController;
use SparkInsight\Service\AppSettingsService;
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;

class AdminControllerTest extends TestCase
{
    private PhpRenderer $renderer;
    private UserSession $session;
    private UserService $userService;
    private InvitationService $invitationService;
    private AppSettingsService $settingsService;
    private Connection $connection;
    private Config $config;
    private AdminController $controller;

    protected function setUp(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $this->renderer = $this->createMock(PhpRenderer::class);
        $this->session = new UserSession();
        $this->connection = $this->createMock(Connection::class);
        $this->userService = new UserService($this->connection);
        $this->invitationService = new InvitationService($this->connection);
        $this->settingsService = new AppSettingsService($this->connection);
        $this->config = Config::fromEnvironment();

        $this->controller = new AdminController(
            $this->renderer,
            $this->session,
            $this->userService,
            $this->invitationService,
            $this->settingsService,
            $this->config,
            $this->connection,
        );
    }

    protected function tearDown(): void
    {
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
                        && is_array($data['maintenance_once_cron_examples'])
                        && count($data['maintenance_once_cron_examples']) >= 5;
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
        $this->assertStringContainsString('Admin Dashboard', $body);
        $this->assertStringContainsString('User Management', $body);
        $this->assertStringContainsString('Display name saved.', $body);
        $this->assertStringContainsString('Invitation Management', $body);
        $this->assertStringContainsString('Admin Settings', $body);
        $this->assertStringContainsString('One-time Cronjob Examples', $body);
        $this->assertStringContainsString(' db:migrate', $body);
    }

    public static function dashboardSectionProvider(): array
    {
        return [
            ['users', 'User Management'],
            ['invitations', 'Invitation Management'],
            ['settings', 'Admin Settings'],
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
        $this->assertStringContainsString('Admin Dashboard', $body);
        $this->assertStringContainsString($expectedHeading, $body);
    }

    public function testUpdateUserStatusForbiddenWhenNotAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);

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

    public function testUpdateUserRolesSuccess(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
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

