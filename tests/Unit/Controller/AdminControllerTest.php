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
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;

class AdminControllerTest extends TestCase
{
    private PhpRenderer $renderer;
    private UserSession $session;
    private UserService $userService;
    private InvitationService $invitationService;
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
        $this->config = Config::fromEnvironment();

        $this->controller = new AdminController(
            $this->renderer,
            $this->session,
            $this->userService,
            $this->invitationService,
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

    public function testUsersRendersPageWithFiltersAndSort(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([
                'status' => 'active',
                'role' => 'reviewer',
                'search' => 'test',
                'sort' => 'email',
                'dir' => 'ASC',
                'page' => '2',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $dbRows = [
            [
                'id' => 1,
                'provider' => null,
                'provider_id' => null,
                'email' => null,
                'name' => 'Test User',
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
                'avatar' => null,
                'roles' => ['reviewer'],
                'status' => 'active',
                'invitation_used' => null,
                'last_login' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
        ];

        $callIndex = 0;
        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql, $params = []) use (&$callIndex, $dbRows) {
                if ($callIndex === 0) {
                    $this->assertStringContainsString('SELECT * FROM users', $sql);
                    $this->assertSame('active', $params[0]);
                    $this->assertSame(json_encode('reviewer'), $params[1]);
                    $this->assertSame('%test%', $params[2]);
                    $this->assertSame('%test%', $params[3]);
                    $this->assertSame(20, $params[4]);
                    $this->assertSame(20, $params[5]);
                    $callIndex++;

                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAllAssociative' => $dbRows,
                    ]);
                }

                $this->assertStringContainsString('SELECT COUNT(*) as count FROM users', $sql);
                $this->assertSame('active', $params[0]);
                $this->assertSame(json_encode('reviewer'), $params[1]);
                $this->assertSame('%test%', $params[2]);
                $this->assertSame('%test%', $params[3]);

                return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAssociative' => ['count' => 30],
                ]);
            });

        $this->renderer->expects($this->once())
            ->method('render')
            ->with(
                $response,
                'admin/users.php',
                [
                    'title' => 'User Management',
                    'user' => ['id' => 1, 'roles' => ['admin']],
                    'users' => $expectedUsers,
                    'filters' => ['status' => 'active', 'role' => 'reviewer', 'search' => 'test'],
                    'sort' => ['email' => 'ASC'],
                    'page' => 2,
                    'totalPages' => 2.0,
                    'totalUsers' => 30,
                    'csrf_token' => $this->session->getCsrfToken(),
                ]
            )
            ->willReturn($response);

        $result = $this->controller->users($request, $response);

        $this->assertSame($response, $result);
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

        $this->assertSame($response, $result);
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

        $this->assertSame($response, $result);
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

    public function testInvitationsRendersPageForAdmin(): void
    {
        $this->session->setUser(['id' => 1, 'roles' => ['admin']]);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['page' => '2']);

        $response = $this->createMock(ResponseInterface::class);

        $resultSet = [
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
        ];

        $callIndex = 0;
        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql, $params = []) use (&$callIndex, $resultSet) {
                if ($callIndex === 0) {
                    $this->assertStringContainsString('SELECT COUNT(*) as count FROM invitations', $sql);
                    $callIndex++;

                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAssociative' => ['count' => 1],
                    ]);
                }

                $this->assertStringContainsString('SELECT invitations.*, u.name AS used_by_name, u.email AS used_by_email FROM invitations LEFT JOIN users u ON invitations.used_by = u.id ORDER BY created_at DESC LIMIT ? OFFSET ?', $sql);
                $this->assertSame([50, 0], $params);

                return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAllAssociative' => $resultSet,
                ]);
            });

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'admin/invitations.php', $this->callback(function ($data) {
                return $data['title'] === 'Invitations'
                    && is_array($data['invitations'])
                    && $data['invitations'][0]['code'] === 'abc123'
                    && $data['totalInvitations'] === 1
                    && $data['page'] === 1
                    && $data['totalPages'] === 1;
            }))
            ->willReturn($response);

        $result = $this->controller->invitations($request, $response);

        $this->assertSame($response, $result);
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
        $this->assertSame(['/admin/invitations'], $result->getHeader('Location'));
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

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql, $params = []) {
                if ($sql === 'SELECT invitations.*, u.name AS used_by_name, u.email AS used_by_email FROM invitations LEFT JOIN users u ON invitations.used_by = u.id ORDER BY created_at DESC LIMIT ? OFFSET ?') {
                    $this->assertSame([50, 0], $params);
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAllAssociative' => [],
                    ]);
                }

                $this->assertSame('SELECT COUNT(*) as count FROM invitations', $sql);
                $this->assertSame([], $params);

                return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAssociative' => ['count' => 0],
                ]);
            });

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'admin/invitations.php', $this->callback(function ($data) {
                return $data['flash_message']['type'] === 'error'
                    && str_contains($data['flash_message']['message'], 'Invalid form submission')
                    && $data['email'] === 'test@example.com';
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
                'hours' => '24',
            ]);

        $response = $this->createMock(ResponseInterface::class);

        $this->connection->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function ($sql, $params = []) {
                if ($sql === 'SELECT invitations.*, u.name AS used_by_name, u.email AS used_by_email FROM invitations LEFT JOIN users u ON invitations.used_by = u.id ORDER BY created_at DESC LIMIT ? OFFSET ?') {
                    $this->assertSame([50, 0], $params);
                    return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                        'fetchAllAssociative' => [],
                    ]);
                }

                $this->assertSame('SELECT COUNT(*) as count FROM invitations', $sql);
                $this->assertSame([], $params);

                return $this->createConfiguredMock(\Doctrine\DBAL\Result::class, [
                    'fetchAssociative' => ['count' => 0],
                ]);
            });

        $this->renderer->expects($this->once())
            ->method('render')
            ->with($response, 'admin/invitations.php', $this->callback(function ($data) {
                return $data['flash_message']['type'] === 'error'
                    && str_contains($data['flash_message']['message'], 'Email address is invalid')
                    && $data['email'] === 'not-an-email';
            }))
            ->willReturn($response);

        $result = $this->controller->createInvitation($request, $response);

        $this->assertSame($response, $result);
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

