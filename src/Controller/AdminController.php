<?php

declare(strict_types=1);

namespace SparkInsight\Controller;

use Doctrine\DBAL\Connection;
use FilesystemIterator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Slim\Views\PhpRenderer;
use SparkInsight\Command\ReleaseDeployCommand;
use SparkInsight\Config\Config;
use SparkInsight\Service\AppSettingsService;
use SparkInsight\Service\CurrentPointerStore;
use SparkInsight\Service\InvitationService;
use SparkInsight\Service\ReleaseManifest;
use SparkInsight\Service\ReleasePackageIntakeService;
use SparkInsight\Service\UserService;
use SparkInsight\Service\UserSession;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

final class AdminController
{
    private const ALLOWED_ADMIN_SECTIONS = ['users', 'invitations', 'settings', 'actions'];

    private const RELEASE_INCLUDE_PATHS = [
        'public',
        'src',
        'templates',
        'resources',
        'database',
        'vendor',
        'si.php',
        'composer.json',
        'composer.lock',
        'LICENSE',
    ];

    public function __construct(
        private readonly PhpRenderer $renderer,
        private readonly UserSession $session,
        private readonly UserService $userService,
        private readonly InvitationService $invitationService,
        private readonly ReleasePackageIntakeService $packageIntakeService,
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
        $csrfToken = mb_trim((string) ($data['_csrf'] ?? ''));
        if (!$this->session->validateCsrfToken($csrfToken)) {
            return $this->renderAdminDashboard($response, $user, ['section' => 'invitations'], [
                'flash_message' => [
                    'type' => 'error',
                    'message' => 'Invalid form submission. Please try again.',
                ],
                'email' => mb_trim((string) ($data['email'] ?? '')),
                'roles' => array_values(array_filter((array) ($data['roles'] ?? $this->settingsService->getInvitationDefaultRoles()), static fn ($role) => in_array($role, ['reviewer', 'author', 'admin'], true))),
                'hours' => max(1, (int) ($data['hours'] ?? $this->settingsService->getInvitationDefaultHours())),
                'invitationCode' => null,
            ]);
        }

        $email = mb_trim((string) ($data['email'] ?? '')) ?: null;
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

        $code = mb_trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            $this->session->setFlash('error', 'Invitation code is required.');

            return $response->withHeader('Location', '/dashboard/admin?section=invitations')->withStatus(302);
        }

        $invitation = $this->invitationService->getInvitationByCode($code);
        if ($invitation !== null && ($invitation['status'] ?? '') === 'used') {
            $this->session->setFlash('error', 'Used invitations cannot be deleted.');

            return $response->withHeader('Location', '/dashboard/admin?section=invitations')->withStatus(302);
        }

        $deleted = $this->invitationService->deleteInvitation($code);
        $this->session->setFlash(
            $deleted ? 'success' : 'error',
            $deleted ? 'Invitation deleted.' : 'Invitation could not be deleted.',
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
        } catch (Throwable $e) {
            $this->session->setFlash('error', 'Settings could not be saved: ' . $e->getMessage());
        }

        return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
    }

    public function uploadReleasePackage(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        if (!$this->validateCsrfData($data)) {
            $this->session->setFlash('error', 'Invalid form submission. Please try again.');

            return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $packageFile = $uploadedFiles['release_package'] ?? null;
        if (!$packageFile instanceof UploadedFileInterface || $packageFile->getError() !== UPLOAD_ERR_OK) {
            $this->session->setFlash('error', 'Select a valid release package ZIP before uploading.');

            return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
        }

        $projectRoot = realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../';
        $publicKeyPath = $projectRoot . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'trusted-release-key.pub';
        $tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sparkinsight-package-upload-' . bin2hex(random_bytes(8));
        $tempPackagePath = $tempDirectory . DIRECTORY_SEPARATOR . 'uploaded-package.zip';

        try {
            if (!mkdir($tempDirectory, 0o700, true) && !is_dir($tempDirectory)) {
                throw new RuntimeException('Could not create a temporary upload directory.');
            }

            $packageFile->moveTo($tempPackagePath);
            $result = $this->packageIntakeService->intake($tempPackagePath, $publicKeyPath);

            $deployResult = $this->executeReleaseDeployOperation((string) $result['operation_id']);

            $this->session->setData('release_package_result', [
                'operation_id' => $result['operation_id'],
                'package_path' => $result['package_path'],
                'package_hash' => $result['package_hash'],
                'package_id' => $result['manifest']->packageId(),
                'package_type' => $result['manifest']->packageType(),
                'release_id' => $result['manifest']->releaseId(),
                'deploy_exit_code' => $deployResult['exit_code'],
            ]);
            $this->session->setData('release_deploy_result', $deployResult);

            $this->session->setFlash(
                $deployResult['exit_code'] === Command::SUCCESS ? 'success' : 'error',
                $deployResult['exit_code'] === Command::SUCCESS ? 'Release package uploaded and deployed successfully.' : 'Release package uploaded, but deployment failed. See deployment output below.',
            );
        } catch (Throwable $e) {
            $this->session->setData('release_package_result', [
                'error' => $e->getMessage(),
            ]);
            $this->session->setData('release_deploy_result', [
                'operation_id' => null,
                'exit_code' => Command::FAILURE,
                'output' => $e->getMessage(),
            ]);
            $this->session->setFlash('error', 'Release package upload failed: ' . $e->getMessage());
        } finally {
            if (is_dir($tempDirectory)) {
                @unlink($tempPackagePath);
                @rmdir($tempDirectory);
            }
        }

        return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
    }

    public function runMaintenanceAction(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        if (!$this->validateCsrfData($data)) {
            $this->session->setFlash('error', 'Invalid form submission. Please try again.');

            return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
        }

        $action = mb_trim((string) ($data['maintenance_action'] ?? ''));

        try {
            $result = $this->executeMaintenanceAction($action, $data);
            $this->session->setData('maintenance_action_result', $result);
            $this->session->setFlash(
                $result['exit_code'] === Command::SUCCESS ? 'success' : 'error',
                ($result['exit_code'] === Command::SUCCESS ? 'Maintenance action succeeded: ' : 'Maintenance action failed: ') . $result['label'],
            );
        } catch (Throwable $e) {
            $this->session->setData('maintenance_action_result', [
                'label' => 'Unknown action',
                'command' => $action,
                'exit_code' => Command::FAILURE,
                'output' => $e->getMessage(),
            ]);
            $this->session->setFlash('error', 'Maintenance action could not be executed: ' . $e->getMessage());
        }

        return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
    }

    public function deployReleasePackage(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        if (!$this->validateCsrfData($data)) {
            $this->session->setFlash('error', 'Invalid form submission. Please try again.');

            return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
        }

        $operationId = mb_trim((string) ($data['operation_id'] ?? ''));
        if ($operationId === '') {
            $this->session->setFlash('error', 'Operation id is required to deploy a package.');

            return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
        }

        try {
            $deployResult = $this->executeReleaseDeployOperation($operationId);
            $this->session->setData('release_deploy_result', $deployResult);
            $this->session->setFlash(
                $deployResult['exit_code'] === Command::SUCCESS ? 'success' : 'error',
                $deployResult['exit_code'] === Command::SUCCESS ? 'Release deployment completed.' : 'Release deployment failed. See deployment output below.',
            );
        } catch (Throwable $e) {
            $this->session->setData('release_deploy_result', [
                'operation_id' => $operationId,
                'exit_code' => Command::FAILURE,
                'output' => $e->getMessage(),
            ]);
            $this->session->setFlash('error', 'Release deployment failed: ' . $e->getMessage());
        }

        return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
    }

    public function exportLiveManifest(Request $request, Response $response): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        if (!$this->validateCsrfData($data)) {
            $this->session->setFlash('error', 'Invalid form submission. Please try again.');

            return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
        }

        try {
            $projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
            $manifest = $this->buildLiveBaseManifest($projectRoot);
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;

            $releaseIdForFile = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) ($manifest['release_id'] ?? 'live'));
            $fileName = sprintf('sparkinsight-live-base-%s-%s.manifest.json', $releaseIdForFile ?: 'live', date('Ymd-His'));

            $response->getBody()->write($manifestJson);

            return $response
                ->withHeader('Content-Type', 'application/json; charset=UTF-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (Throwable $e) {
            $this->session->setFlash('error', 'Could not export live manifest: ' . $e->getMessage());

            return $response->withHeader('Location', '/dashboard/admin?section=settings')->withStatus(302);
        }
    }

    /**
     * @return array{operation_id: ?string, exit_code: int, output: string}
     */
    private function executeReleaseDeployOperation(string $operationId): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/', $operationId) !== 1) {
            throw new RuntimeException('Operation id contains invalid characters.');
        }

        $projectDirectory = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');

        $app = new Application('SparkInsight deployment', '1.0.0');
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);
        $app->add(new ReleaseDeployCommand());

        $input = new ArrayInput([
            'command' => 'release:deploy',
            '--operation-id' => $operationId,
            '--project-root' => $projectDirectory,
            '--public-key' => $projectDirectory . DIRECTORY_SEPARATOR . '.deploy' . DIRECTORY_SEPARATOR . 'trusted-release-key.pub',
        ]);
        $output = new BufferedOutput();
        $exitCode = $app->run($input, $output);

        return [
            'operation_id' => $operationId,
            'exit_code' => $exitCode,
            'output' => $output->fetch(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLiveBaseManifest(string $projectRoot): array
    {
        $files = [];
        foreach (self::RELEASE_INCLUDE_PATHS as $relativePath) {
            $absolutePath = mb_rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;

            if (is_dir($absolutePath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST,
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }

                    $files[] = $this->buildManifestFileRecord($projectRoot, $fileInfo->getPathname());
                }

                continue;
            }

            if (is_file($absolutePath)) {
                $files[] = $this->buildManifestFileRecord($projectRoot, $absolutePath);
            }
        }

        usort($files, static fn (array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));

        $payloadFiles = array_map(static fn (array $file): string => (string) $file['path'], $files);
        $expandedSize = array_reduce($files, static fn (int $carry, array $file): int => $carry + (int) $file['size'], 0);

        $pointer = (new CurrentPointerStore($projectRoot))->read();
        $releaseId = is_string($pointer['current'] ?? null) && (string) $pointer['current'] !== ''
            ? (string) $pointer['current']
            : $this->resolveReleaseIdFromRoot($projectRoot);

        return [
            'format' => ReleaseManifest::FORMAT,
            'application' => ReleaseManifest::APPLICATION,
            'package_id' => 'live-base-' . bin2hex(random_bytes(8)),
            'package_type' => 'full',
            'release_id' => $releaseId,
            'created_at' => date(DATE_ATOM),
            'minimum_php' => $this->determineMinimumPhpVersion($projectRoot),
            'required_extensions' => ['json', 'openssl', 'pdo', 'session', 'tokenizer', 'zip'],
            'composer_lock_sha256' => $this->hashFile($projectRoot . DIRECTORY_SEPARATOR . 'composer.lock') ?? str_repeat('0', 64),
            'expanded_size' => $expandedSize,
            'file_count' => count($files),
            'files' => $files,
            'payload_files' => $payloadFiles,
            'delete' => [],
        ];
    }

    /**
     * @return array{path: string, size: int, sha256: string}
     */
    private function buildManifestFileRecord(string $projectRoot, string $absolutePath): array
    {
        $relativePath = str_replace('\\', '/', mb_substr($absolutePath, mb_strlen(mb_rtrim($projectRoot, DIRECTORY_SEPARATOR)) + 1));
        $hash = hash_file('sha256', $absolutePath);

        return [
            'path' => $relativePath,
            'size' => (int) (filesize($absolutePath) ?: 0),
            'sha256' => $hash === false ? '' : $hash,
        ];
    }

    private function resolveReleaseIdFromRoot(string $root): string
    {
        $resolvedRoot = realpath($root);
        $normalizedRoot = $resolvedRoot !== false ? $resolvedRoot : $root;
        $releaseId = basename(mb_rtrim($normalizedRoot, DIRECTORY_SEPARATOR));

        if ($releaseId === '' || $releaseId === '.' || $releaseId === '..' || $releaseId === DIRECTORY_SEPARATOR) {
            return 'release-' . bin2hex(random_bytes(4));
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/', $releaseId) === 1 ? $releaseId : 'release-' . bin2hex(random_bytes(4));
    }

    private function determineMinimumPhpVersion(string $projectRoot): string
    {
        $composerJsonPath = $projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerJsonPath)) {
            return '8.1.0';
        }

        $decoded = json_decode((string) file_get_contents($composerJsonPath), true);
        if (!is_array($decoded)) {
            return '8.1.0';
        }

        $constraint = $decoded['require']['php'] ?? null;
        if (!is_string($constraint) || mb_trim($constraint) === '') {
            return '8.1.0';
        }

        preg_match_all('/(\d+)\.(\d+)(?:\.(\d+))?/', $constraint, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return '8.1.0';
        }

        $lowest = null;
        foreach ($matches as $match) {
            $major = (int) $match[1];
            $minor = (int) $match[2];
            $patch = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 0;
            $candidate = sprintf('%d.%d.%d', $major, $minor, $patch);

            if ($lowest === null || version_compare($candidate, $lowest, '<')) {
                $lowest = $candidate;
            }
        }

        return $lowest ?? '8.1.0';
    }

    private function hashFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    private function renderAdminDashboard(
        Response $response,
        array $user,
        array $queryParams = [],
        array $invitationOverrides = [],
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
                $section === 'invitations' ? $flashMessage : null,
            ),
            $this->buildSettingsSectionData($section === 'settings' ? $flashMessage : null),
            $this->buildActionsSectionData($section === 'actions' ? $flashMessage : null),
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
        $filterEmail = mb_trim((string) ($queryParams['filter_email'] ?? ''));
        $filterRole = mb_trim((string) ($queryParams['filter_role'] ?? ''));
        $filterStatus = mb_trim((string) ($queryParams['filter_status'] ?? ''));
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
        $releasePackageResult = $this->session->getData('release_package_result');
        if ($releasePackageResult !== null) {
            $this->session->setData('release_package_result', null);
        }

        $releaseDeployResult = $this->session->getData('release_deploy_result');
        if ($releaseDeployResult !== null) {
            $this->session->setData('release_deploy_result', null);
        }

        return [
            'settings' => $settings,
            'release_package_result' => is_array($releasePackageResult) ? $releasePackageResult : null,
            'release_deploy_result' => is_array($releaseDeployResult) ? $releaseDeployResult : null,
            'settings_flash_message' => $flashMessage,
        ];
    }

    private function buildActionsSectionData(?array $flashMessage = null): array
    {
        $maintenanceActionResult = $this->session->getData('maintenance_action_result');
        if ($maintenanceActionResult !== null) {
            $this->session->setData('maintenance_action_result', null);
        }

        return [
            'maintenance_action_result' => is_array($maintenanceActionResult) ? $maintenanceActionResult : null,
            'actions_flash_message' => $flashMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{label: string, command: string, exit_code: int, output: string}
     */
    private function executeMaintenanceAction(string $action, array $data): array
    {
        $projectDirectory = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
        $importDirectory = $projectDirectory . DIRECTORY_SEPARATOR . 'scrivener';

        $commands = [
            'db_migrate_status' => [
                'label' => 'Run migration status',
                'input' => ['command' => 'db:migrate', '--status' => true],
            ],
            'db_migrate' => [
                'label' => 'Run pending migrations',
                'input' => ['command' => 'db:migrate'],
            ],
            'check_environment' => [
                'label' => 'Run environment check',
                'input' => ['command' => 'check-environment'],
            ],
            'invite_purge_expired' => [
                'label' => 'Purge expired invitations',
                'input' => ['command' => 'invite:purge-expired', '--force' => true],
            ],
            'content_list_imports' => [
                'label' => 'List import batches',
                'input' => ['command' => 'content:list-imports', '--limit' => '100'],
            ],
            'content_import_dry_run' => [
                'label' => 'Import dry-run (Scrivener)',
                'input' => [
                    'command' => 'content:import-scrivener',
                    '--directory' => mb_trim((string) ($data['import_directory'] ?? $importDirectory)),
                    '--dry-run' => true,
                ],
            ],
            'content_purge_import' => [
                'label' => 'Purge one import batch (destructive)',
                'input' => [
                    'command' => 'content:purge-imports',
                    '--force' => true,
                    '--id' => [mb_trim((string) ($data['import_batch_id'] ?? ''))],
                ],
            ],
            'invite_generate' => [
                'label' => 'Generate invitation (CLI path)',
                'input' => [
                    'command' => 'invite:generate',
                ],
            ],
        ];

        if (!isset($commands[$action])) {
            throw new RuntimeException('Unsupported maintenance action: ' . $action);
        }

        if ($action === 'content_purge_import') {
            $confirmation = mb_strtoupper(mb_trim((string) ($data['confirm_purge'] ?? '')));
            $batchId = mb_trim((string) ($data['import_batch_id'] ?? ''));
            if ($batchId === '') {
                throw new RuntimeException('Import batch ID is required for purge.');
            }

            if ($confirmation !== 'PURGE') {
                throw new RuntimeException('Type PURGE to confirm destructive import purge.');
            }
        }

        $commandConfig = $commands[$action];
        $commandInput = (array) $commandConfig['input'];
        $commandName = (string) $commandInput['command'];

        $commandArgs = [PHP_BINARY, $projectDirectory . DIRECTORY_SEPARATOR . 'si.php', '--no-ansi'];
        $commandArgs[] = $commandName;

        foreach ($commandInput as $name => $value) {
            if ($name === 'command') {
                continue;
            }

            if (is_bool($value) && $value) {
                $commandArgs[] = '--' . mb_ltrim((string) $name, '-');
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $commandArgs[] = '--' . mb_ltrim((string) $name, '-');
                    if ($item !== '') {
                        $commandArgs[] = (string) $item;
                    }
                }

                continue;
            }

            $commandArgs[] = '--' . mb_ltrim((string) $name, '-');
            $commandArgs[] = (string) $value;
        }

        $commandLine = implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $commandArgs));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($commandLine, $descriptors, $pipes, $projectDirectory);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start maintenance command process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $combinedOutput = mb_trim((string) $stdout . ($stderr !== '' ? PHP_EOL . $stderr : ''));

        return [
            'label' => (string) $commandConfig['label'],
            'command' => $commandName,
            'exit_code' => $exitCode,
            'output' => $combinedOutput,
        ];
    }

    private function validateCsrfData(array $data): bool
    {
        $csrfToken = mb_trim((string) ($data['_csrf'] ?? ''));

        return $this->session->validateCsrfToken($csrfToken);
    }

    public function updateUserStatus(Request $request, Response $response, array $args): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $this->respondAdminActionError($request, $response, 'Forbidden', 403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        if (!$this->validateCsrfData($data)) {
            return $this->respondAdminActionError($request, $response, 'Invalid CSRF token.', 400);
        }

        $userId = (int) ($args['id'] ?? 0);
        $status = is_string($data['status'] ?? null) ? $data['status'] : '';

        if (!$userId || !in_array($status, ['active', 'disabled'], true)) {
            return $this->respondAdminActionError($request, $response, 'Invalid request', 400);
        }

        $success = $this->userService->updateUserStatus($userId, $status);
        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode(['success' => $success]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }

        $this->session->setFlash($success ? 'success' : 'error', $success ? 'User status updated.' : 'User status update failed.');

        return $response->withHeader('Location', '/dashboard/admin?section=users')->withStatus(302);
    }

    public function updateUserRoles(Request $request, Response $response, array $args): Response
    {
        $user = $this->session->getUser();
        if (!$user || !in_array('admin', $user['roles'] ?? [], true)) {
            return $this->respondAdminActionError($request, $response, 'Forbidden', 403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        if (!$this->validateCsrfData($data)) {
            return $this->respondAdminActionError($request, $response, 'Invalid CSRF token.', 400);
        }

        $userId = (int) ($args['id'] ?? 0);
        $roles = $data['roles'] ?? [];

        if (!$userId || !is_array($roles)) {
            return $this->respondAdminActionError($request, $response, 'Invalid request', 400);
        }

        $success = $this->userService->updateUserRoles($userId, $roles);
        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode(['success' => $success]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        }

        $this->session->setFlash($success ? 'success' : 'error', $success ? 'User roles updated.' : 'User roles update failed.');

        return $response->withHeader('Location', '/dashboard/admin?section=users')->withStatus(302);
    }

    private function isAjaxRequest(Request $request): bool
    {
        return mb_strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    private function respondAdminActionError(Request $request, Response $response, string $message, int $status): Response
    {
        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => $message,
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        }

        $this->session->setFlash('error', $message);

        return $response->withHeader('Location', '/dashboard/admin?section=users')->withStatus(302);
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

        $displayName = array_key_exists('display_name', (array) $data) ? mb_trim((string) $data['display_name']) : null;
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
            $success ? 'Display name saved.' : 'Display name could not be saved.',
        );

        return $response->withHeader('Location', '/dashboard/admin?section=users')->withStatus(302);
    }
}
