<?php

declare(strict_types=1);

namespace SparkInsight\Controller;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
use SparkInsight\Config\Config;
use SparkInsight\Service\AppSettingsService;
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;

final class AdminController
{
    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly UserSession $session,
        private readonly UserService $userService,
        private readonly InvitationService $invitationService,
        private readonly AppSettingsService $settingsService,
        private readonly Config $config,
        private readonly Connection $connection,
    ) {
    }

    public function users(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [])) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $queryParams = $request->getQueryParams();
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = [];
        if (!empty($queryParams['status'])) {
            $filters['status'] = $queryParams['status'];
        }
        if (!empty($queryParams['role'])) {
            $filters['role'] = $queryParams['role'];
        }
        if (!empty($queryParams['search'])) {
            $filters['search'] = $queryParams['search'];
        }

        $sort = ['created_at' => 'DESC'];
        if (!empty($queryParams['sort'])) {
            $sortField = $queryParams['sort'];
            $sortDir = $queryParams['dir'] ?? 'ASC';
            if (in_array($sortField, ['name', 'email', 'created_at', 'last_login', 'status'])) {
                $sort = [$sortField => $sortDir];
            }
        }

        $users = $this->userService->getAllUsers($filters, $sort, $limit, $offset);
        $totalUsers = $this->userService->getUserCount($filters);
        $totalPages = ceil($totalUsers / $limit);

        return $this->renderer->render($response, 'admin/users.php', [
            'title' => 'User Management',
            'user' => $user,
            'users' => $users,
            'filters' => $filters,
            'sort' => $sort,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalUsers' => $totalUsers,
            'csrf_token' => $this->session->getCsrfToken(),
        ]);
    }

    public function invitations(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [])) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $queryParams = $request->getQueryParams();
        $filterEmail = trim((string) ($queryParams['filter_email'] ?? ''));
        $filterRole = trim((string) ($queryParams['filter_role'] ?? ''));
        $filterStatus = trim((string) ($queryParams['filter_status'] ?? ''));
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $limit = 50;

        $filters = [];
        if ($filterEmail !== '') {
            $filters['email'] = $filterEmail;
        }
        if ($filterRole !== '') {
            $filters['role'] = $filterRole;
        }
        if (in_array($filterStatus, ['pending', 'used', 'expired'], true)) {
            $filters['status'] = $filterStatus;
        }

        $flashMessage = $this->session->getFlash();
        $invitationCode = $this->session->getData('invitation_code');
        $savedEmail = $this->session->getData('invitation_email');
        $savedRoles = $this->session->getData('invitation_roles');
        $savedHours = $this->session->getData('invitation_hours');
        $settings = $this->settingsService->getAll();

        if ($invitationCode !== null) {
            $this->session->setData('invitation_code', null);
            $this->session->setData('invitation_email', null);
            $this->session->setData('invitation_roles', null);
            $this->session->setData('invitation_hours', null);
        }

        $totalInvitations = $this->invitationService->getInvitationCount($filters);
        $totalPages = max(1, (int) ceil($totalInvitations / $limit));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $limit;

        $filterToken = $this->session->getCsrfToken();
        $invitations = $this->invitationService->getInvitations($filters, $limit, $offset);

        return $this->renderer->render($response, 'admin/invitations.php', [
            'title' => 'Invitations',
            'user' => $user,
            'invitations' => $invitations,
            'email' => $savedEmail ?? null,
            'roles' => is_array($savedRoles) ? $savedRoles : ($settings['invitation_default_roles'] ?? ['reviewer']),
            'hours' => $savedHours !== null ? (int) $savedHours : (int) ($settings['invitation_default_hours'] ?? 168),
            'invitationCode' => $invitationCode,
            'appUrl' => $this->config->get('app_url'),
            'flash_message' => $flashMessage,
            'totalInvitations' => $totalInvitations,
            'csrf_token' => $filterToken,
            'filter_email' => $filterEmail,
            'filter_role' => $filterRole,
            'filter_status' => $filterStatus,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ]);
    }

    public function createInvitation(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [])) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = $request->getParsedBody();
        $csrfToken = trim((string) ($data['_csrf'] ?? ''));
        if (!$this->session->validateCsrfToken($csrfToken)) {
            return $this->renderer->render($response, 'admin/invitations.php', [
                'title' => 'Invitations',
                'user' => $user,
                'invitations' => $this->invitationService->getInvitations([], 50, 0),
                'email' => trim((string) ($data['email'] ?? '')),
                'roles' => array_values(array_filter((array) ($data['roles'] ?? $this->settingsService->getInvitationDefaultRoles()), static fn ($role) => in_array($role, ['reviewer', 'author', 'admin']))),
                'hours' => max(1, (int) ($data['hours'] ?? $this->settingsService->getInvitationDefaultHours())),
                'invitationCode' => null,
                'appUrl' => $this->config->get('app_url'),
                'flash_message' => [
                    'type' => 'error',
                    'message' => 'Invalid form submission. Please try again.',
                ],
                'totalInvitations' => $this->invitationService->getInvitationCount(),
                'csrf_token' => $this->session->getCsrfToken(),
                'filter_email' => '',
                'filter_role' => '',
                'filter_status' => '',
            ]);
        }

        $email = trim((string) ($data['email'] ?? '')) ?: null;
        $roles = array_values(array_filter((array) ($data['roles'] ?? $this->settingsService->getInvitationDefaultRoles()), static fn ($role) => in_array($role, ['reviewer', 'author', 'admin'])));
        if (empty($roles)) {
            $roles = $this->settingsService->getInvitationDefaultRoles();
        }

        $hours = (int) ($data['hours'] ?? $this->settingsService->getInvitationDefaultHours());
        if ($hours < 1) {
            $hours = $this->settingsService->getInvitationDefaultHours();
        }

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->renderer->render($response, 'admin/invitations.php', [
                'title' => 'Invitations',
                'user' => $user,
                'invitations' => $this->invitationService->getInvitations([], 50, 0),
                'email' => $email,
                'roles' => $roles,
                'hours' => $hours,
                'invitationCode' => null,
                'appUrl' => $this->config->get('app_url'),
                'flash_message' => [
                    'type' => 'error',
                    'message' => 'Email address is invalid. Please use a valid email or leave it blank.',
                ],
                'totalInvitations' => $this->invitationService->getInvitationCount(),
                'csrf_token' => $this->session->getCsrfToken(),
                'filter_email' => '',
                'filter_role' => '',
                'filter_status' => '',
            ]);
        }

        $invitationCode = $this->invitationService->generateInvitation($roles, $email, $hours);
        $this->session->setFlash('success', 'Invitation created successfully.');
        $this->session->setData('invitation_code', $invitationCode);
        $this->session->setData('invitation_email', $email);
        $this->session->setData('invitation_roles', $roles);
        $this->session->setData('invitation_hours', $hours);

        return $response->withHeader('Location', '/admin/invitations')->withStatus(302);
    }

    public function settings(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [])) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $settings = $this->settingsService->getAll();
        $flashMessage = $this->session->getFlash();
        $appPath = realpath(__DIR__ . '/../../');
        $phpBinary = PHP_BINARY;

        $importDirectory = $appPath !== false ? $appPath . DIRECTORY_SEPARATOR . 'scrivener' : './scrivener';
        $cron = [
            'import' => sprintf(
                '%s cd %s && %s si.php content:import-scrivener --directory="%s"',
                $settings['import_cron_schedule'] ?? '0 2 * * *',
                $appPath !== false ? $appPath : '.',
                $phpBinary,
                $importDirectory
            ),
            'invitation_cleanup' => sprintf(
                '%s cd %s && %s si.php invite:purge-expired --force',
                $settings['invitation_cleanup_cron_schedule'] ?? '30 2 * * *',
                $appPath !== false ? $appPath : '.',
                $phpBinary
            ),
        ];

        return $this->renderer->render($response, 'admin/settings.php', [
            'title' => 'Admin Settings',
            'user' => $user,
            'settings' => $settings,
            'cron_snippets' => $cron,
            'flash_message' => $flashMessage,
            'csrf_token' => $this->session->getCsrfToken(),
        ]);
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [])) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->validateCsrfData((array) $data)) {
            $this->session->setFlash('error', 'Invalid form submission. Please try again.');

            return $response->withHeader('Location', '/admin/settings')->withStatus(302);
        }

        try {
            $this->settingsService->saveFromAdminInput((array) $data);
            $this->session->setFlash('success', 'Settings saved.');
        } catch (\Throwable $e) {
            $this->session->setFlash('error', 'Settings could not be saved: ' . $e->getMessage());
        }

        return $response->withHeader('Location', '/admin/settings')->withStatus(302);
    }

    private function validateCsrfData(array $data): bool
    {
        $csrfToken = trim((string) ($data['_csrf'] ?? ''));

        return $this->session->validateCsrfToken($csrfToken);
    }

    public function updateUserStatus(Request $request, Response $response, array $args): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [])) {
            return $response->withStatus(403)->write('Forbidden');
        }

        $data = $request->getParsedBody();
        if (!$this->validateCsrfData($data)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Invalid CSRF token.',
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $userId = (int) ($args['id'] ?? 0);
        $status = $data['status'] ?? '';

        if (!$userId || !in_array($status, ['active', 'disabled'])) {
            return $response->withStatus(400)->write('Invalid request');
        }

        $success = $this->userService->updateUserStatus($userId, $status);

        return $response->withJson(['success' => $success]);
    }

    public function updateUserRoles(Request $request, Response $response, array $args): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [])) {
            return $response->withStatus(403)->write('Forbidden');
        }

        $data = $request->getParsedBody();
        if (!$this->validateCsrfData($data)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Invalid CSRF token.',
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $userId = (int) ($args['id'] ?? 0);
        $roles = $data['roles'] ?? [];

        if (!$userId || !is_array($roles)) {
            return $response->withStatus(400)->write('Invalid request');
        }

        $success = $this->userService->updateUserRoles($userId, $roles);

        return $response->withJson(['success' => $success]);
    }
}