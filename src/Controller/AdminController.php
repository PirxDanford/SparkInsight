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
    private const ALLOWED_ADMIN_SECTIONS = ['users', 'invitations', 'settings'];

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

    public function dashboard(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        return $this->renderAdminDashboard($response, $user, (array) $request->getQueryParams());
    }

    public function users(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $queryParams = (array) $request->getQueryParams();
        $queryParams['section'] = 'users';

        return $response->withHeader('Location', '/dashboard/admin?' . http_build_query($queryParams))->withStatus(302);
    }

    public function invitations(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $queryParams = (array) $request->getQueryParams();
        $queryParams['section'] = 'invitations';

        return $response->withHeader('Location', '/dashboard/admin?' . http_build_query($queryParams))->withStatus(302);
    }

    public function createInvitation(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $csrfToken = trim((string) ($data['_csrf'] ?? ''));
        if (!$this->session->validateCsrfToken($csrfToken)) {
            return $this->renderAdminDashboard($response, $user, ['section' => 'invitations'], [
                'flash_message' => [
                    'type' => 'error',
                    'message' => 'Invalid form submission. Please try again.',
                ],
                'email' => trim((string) ($data['email'] ?? '')),
                'roles' => array_values(array_filter((array) ($data['roles'] ?? $this->settingsService->getInvitationDefaultRoles()), static fn ($role) => in_array($role, ['reviewer', 'author', 'admin'], true))),
                'hours' => max(1, (int) ($data['hours'] ?? $this->settingsService->getInvitationDefaultHours())),
                'invitationCode' => null,
            ]);
        }

        $email = trim((string) ($data['email'] ?? '')) ?: null;
        $roles = array_values(array_filter((array) ($data['roles'] ?? $this->settingsService->getInvitationDefaultRoles()), static fn ($role) => in_array($role, ['reviewer', 'author', 'admin'], true)));
        if (empty($roles)) {
            $roles = $this->settingsService->getInvitationDefaultRoles();
        }

        $hours = (int) ($data['hours'] ?? $this->settingsService->getInvitationDefaultHours());
        if ($hours < 1) {
            $hours = $this->settingsService->getInvitationDefaultHours();
        }

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->renderAdminDashboard($response, $user, ['section' => 'invitations'], [
                'flash_message' => [
                    'type' => 'error',
                    'message' => 'Email address is invalid. Please use a valid email or leave it blank.',
                ],
                'email' => $email,
                'roles' => $roles,
                'hours' => $hours,
                'invitationCode' => null,
            ]);
        }

        $invitationCode = $this->invitationService->generateInvitation($roles, $email, $hours);
        $this->session->setFlash('success', 'Invitation created successfully.');
        $this->session->setData('invitation_code', $invitationCode);
        $this->session->setData('invitation_email', $email);
        $this->session->setData('invitation_roles', $roles);
        $this->session->setData('invitation_hours', $hours);

        return $response->withHeader('Location', '/dashboard/admin?section=invitations')->withStatus(302);
    }

    public function deleteInvitation(Request $request, Response $response, array $args): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->validateCsrfData($data)) {
            $this->session->setFlash('error', 'Invalid form submission. Please try again.');

            return $response->withHeader('Location', '/dashboard/admin?section=invitations')->withStatus(302);
        }

        $code = trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            $this->session->setFlash('error', 'Invitation code is required.');

            return $response->withHeader('Location', '/dashboard/admin?section=invitations')->withStatus(302);
        }

        $deleted = $this->invitationService->deleteInvitation($code);
        $this->session->setFlash(
            $deleted ? 'success' : 'error',
            $deleted ? 'Invitation deleted.' : 'Invitation could not be deleted.'
        );

        return $response->withHeader('Location', '/dashboard/admin?section=invitations')->withStatus(302);
    }

    public function settings(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $queryParams = (array) $request->getQueryParams();
        $queryParams['section'] = 'settings';

        return $response->withHeader('Location', '/dashboard/admin?' . http_build_query($queryParams))->withStatus(302);
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->validateCsrfData((array) $data)) {
            $this->session->setFlash('error', 'Invalid form submission. Please try again.');

            return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
        }

        try {
            $this->settingsService->saveFromAdminInput((array) $data);
            $this->session->setFlash('success', 'Settings saved.');
        } catch (\Throwable $e) {
            $this->session->setFlash('error', 'Settings could not be saved: ' . $e->getMessage());
        }

        return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
    }

    private function renderAdminDashboard(
        Response $response,
        array $user,
        array $queryParams = [],
        array $invitationOverrides = []
    ): Response {
        $section = $this->normalizeAdminSection((string) ($queryParams['section'] ?? 'users'));
        $flashMessage = $this->session->getFlash();

        return $this->renderer->render($response, 'admin/dashboard.php', array_merge(
            [
                'title' => 'Admin',
                'user' => $user,
                'admin_section' => $section,
                'csrf_token' => $this->session->getCsrfToken(),
                'appUrl' => $this->config->get('app_url'),
            ],
            $this->buildUsersSectionData($queryParams),
            ['users_flash_message' => $section === 'users' ? $flashMessage : null],
            $this->buildInvitationsSectionData(
                $queryParams,
                $invitationOverrides,
                $section === 'invitations' ? $flashMessage : null
            ),
            $this->buildSettingsSectionData($section === 'settings' ? $flashMessage : null)
        ));
    }

    private function normalizeAdminSection(string $section): string
    {
        return in_array($section, self::ALLOWED_ADMIN_SECTIONS, true) ? $section : 'users';
    }

    private function buildUsersSectionData(array $queryParams): array
    {
        $page = max(1, (int) ($queryParams['users_page'] ?? ($queryParams['page'] ?? 1)));
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
            if (in_array($sortField, ['name', 'email', 'created_at', 'last_login', 'status'], true)) {
                $sort = [$sortField => $sortDir];
            }
        }

        $users = $this->userService->getAllUsers($filters, $sort, $limit, $offset);
        $totalUsers = $this->userService->getUserCount($filters);

        return [
            'users' => $users,
            'users_filters' => $filters,
            'users_sort' => $sort,
            'users_page' => $page,
            'users_total_pages' => (float) ceil($totalUsers / $limit),
            'users_total' => $totalUsers,
        ];
    }

    private function buildInvitationsSectionData(array $queryParams, array $overrides = [], ?array $flashMessage = null): array
    {
        $filterEmail = trim((string) ($queryParams['filter_email'] ?? ''));
        $filterRole = trim((string) ($queryParams['filter_role'] ?? ''));
        $filterStatus = trim((string) ($queryParams['filter_status'] ?? ''));
        $page = max(1, (int) ($queryParams['invitations_page'] ?? ($queryParams['page'] ?? 1)));
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

        $settings = $this->settingsService->getAll();
        $invitationCode = array_key_exists('invitationCode', $overrides)
            ? $overrides['invitationCode']
            : $this->session->getData('invitation_code');
        $savedEmail = array_key_exists('email', $overrides)
            ? $overrides['email']
            : $this->session->getData('invitation_email');
        $savedRoles = array_key_exists('roles', $overrides)
            ? $overrides['roles']
            : $this->session->getData('invitation_roles');
        $savedHours = array_key_exists('hours', $overrides)
            ? $overrides['hours']
            : $this->session->getData('invitation_hours');

        if (!array_key_exists('invitationCode', $overrides) && $invitationCode !== null) {
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
        $invitations = $this->invitationService->getInvitations($filters, $limit, $offset);

        return [
            'invitations' => $invitations,
            'invitation_email' => $savedEmail ?? null,
            'invitation_roles' => is_array($savedRoles) ? $savedRoles : ($settings['invitation_default_roles'] ?? ['reviewer']),
            'invitation_hours' => $savedHours !== null ? (int) $savedHours : (int) ($settings['invitation_default_hours'] ?? 168),
            'invitation_code' => $invitationCode,
            'invitation_flash_message' => $overrides['flash_message'] ?? $flashMessage,
            'total_invitations' => $totalInvitations,
            'invitation_filters' => [
                'email' => $filterEmail,
                'role' => $filterRole,
                'status' => $filterStatus,
            ],
            'invitations_page' => $page,
            'invitations_total_pages' => $totalPages,
            'invitations_limit' => $limit,
        ];
    }

    private function buildSettingsSectionData(?array $flashMessage = null): array
    {
        $settings = $this->settingsService->getAll();
        $appPath = realpath(__DIR__ . '/../../');
        $projectDirectory = $appPath !== false ? $appPath : '.';
        $phpBinary = PHP_BINARY;
        $siPhpPath = $projectDirectory . DIRECTORY_SEPARATOR . 'si.php';

        $importDirectory = $projectDirectory . DIRECTORY_SEPARATOR . 'scrivener';
        $cron = [
            'import' => sprintf(
                '%s %s %s content:import-scrivener --directory="%s"',
                $settings['import_cron_schedule'] ?? '0 2 * * *',
                $phpBinary,
                $siPhpPath,
                $importDirectory
            ),
            'invitation_cleanup' => sprintf(
                '%s %s %s invite:purge-expired --force',
                $settings['invitation_cleanup_cron_schedule'] ?? '30 2 * * *',
                $phpBinary,
                $siPhpPath
            ),
        ];

        $maintenanceCronExamples = [
            [
                'label' => 'Database migration',
                'description' => 'One-time cron entry example for deployment windows.',
                'command' => sprintf('10 3 21 7 * %s %s db:migrate', $phpBinary, $siPhpPath),
            ],
            [
                'label' => 'Environment check',
                'description' => 'One-time cron entry example for pre/post release validation.',
                'command' => sprintf('20 3 21 7 * %s %s check-environment', $phpBinary, $siPhpPath),
            ],
            [
                'label' => 'Import content',
                'description' => 'One-time cron entry example for a planned import run.',
                'command' => sprintf('30 3 21 7 * %s %s content:import-scrivener --directory="%s"', $phpBinary, $siPhpPath, $importDirectory),
            ],
            [
                'label' => 'Purge expired invitations',
                'description' => 'One-time cron entry example for backlog cleanup.',
                'command' => sprintf('40 3 21 7 * %s %s invite:purge-expired --force', $phpBinary, $siPhpPath),
            ],
            [
                'label' => 'List import batches',
                'description' => 'One-time cron entry example to inspect import IDs before purge.',
                'command' => sprintf('50 3 21 7 * %s %s content:list-imports --limit=100', $phpBinary, $siPhpPath),
            ],
            [
                'label' => 'Purge import batch (destructive)',
                'description' => 'One-time cron entry example. Replace <import-id> before scheduling.',
                'command' => sprintf('0 4 21 7 * %s %s content:purge-imports --id=<import-id> --force', $phpBinary, $siPhpPath),
            ],
            [
                'label' => 'Generate invitation from CLI',
                'description' => 'One-time cron entry example for emergency invitation creation.',
                'command' => sprintf('10 4 21 7 * %s %s invite:generate --hours=168', $phpBinary, $siPhpPath),
            ],
        ];

        return [
            'settings' => $settings,
            'cron_snippets' => $cron,
            'maintenance_once_cron_examples' => $maintenanceCronExamples,
            'settings_flash_message' => $flashMessage,
        ];
    }

    private function validateCsrfData(array $data): bool
    {
        $csrfToken = trim((string) ($data['_csrf'] ?? ''));

        return $this->session->validateCsrfToken($csrfToken);
    }

    public function updateUserStatus(Request $request, Response $response, array $args): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
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

        if (!$userId || !in_array($status, ['active', 'disabled'], true)) {
            $response->getBody()->write('Invalid request');
            return $response->withStatus(400);
        }

        $success = $this->userService->updateUserStatus($userId, $status);
        $response->getBody()->write(json_encode(['success' => $success]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    public function updateUserRoles(Request $request, Response $response, array $args): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
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
            $response->getBody()->write('Invalid request');
            return $response->withStatus(400);
        }

        $success = $this->userService->updateUserRoles($userId, $roles);
        $response->getBody()->write(json_encode(['success' => $success]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    public function updateUserDisplayName(Request $request, Response $response, array $args): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->validateCsrfData((array) $data)) {
            $this->session->setFlash('error', 'Invalid form submission. Please try again.');

            return $response->withHeader('Location', '/dashboard/admin?section=users')->withStatus(302);
        }

        $userId = (int) ($args['id'] ?? 0);
        if (!$userId) {
            $this->session->setFlash('error', 'Invalid user selection.');

            return $response->withHeader('Location', '/dashboard/admin?section=users')->withStatus(302);
        }

        $displayName = array_key_exists('display_name', (array) $data) ? trim((string) $data['display_name']) : null;
        if ($displayName !== null && mb_strlen($displayName) > 255) {
            $this->session->setFlash('error', 'Display name is too long.');

            return $response->withHeader('Location', '/dashboard/admin?section=users')->withStatus(302);
        }

        $success = $this->userService->updateUserDisplayName($userId, $displayName);

        if ($success && isset($user['id']) && (int) $user['id'] === $userId) {
            $user['display_name'] = $displayName !== '' ? $displayName : null;
            $this->session->setUser($user);
        }

        $this->session->setFlash(
            $success ? 'success' : 'error',
            $success ? 'Display name saved.' : 'Display name could not be saved.'
        );

        return $response->withHeader('Location', '/dashboard/admin?section=users')->withStatus(302);
    }
}