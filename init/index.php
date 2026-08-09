<?php

declare(strict_types=1);

const MANIFEST_FORMAT = 'sparkinsight-release-v1';
const MANIFEST_APPLICATION = 'sparkinsight/sparkinsight';

$projectRoot = dirname(__DIR__);
$deployRoot = $projectRoot . '/.deploy';
$tokenPath = $deployRoot . '/init-token.txt';
$hasTokenProtection = is_file($tokenPath);
$expectedToken = $hasTokenProtection ? trim((string) file_get_contents($tokenPath)) : '';
$providedToken = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if ($hasTokenProtection && $expectedToken !== '' && !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    renderTokenGate($providedToken === '' ? null : 'Invalid init token.');
    exit(0);
}

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'deploy-package') {
    try {
        $result = deployPackageFromUpload($projectRoot, $deployRoot);
        $messages[] = 'Release activated: ' . $result['release_id'];
        $messages[] = 'Operation id: ' . $result['operation_id'];
        if (is_file($tokenPath)) {
            @unlink($tokenPath);
            $messages[] = 'Init token was removed after successful activation.';
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

$checks = collectChecks($projectRoot, $deployRoot);
renderWizard($checks, $messages, $errors, $providedToken, $hasTokenProtection && is_file($tokenPath));

/**
 * @return array{release_id: string, operation_id: string}
 */
function deployPackageFromUpload(string $projectRoot, string $deployRoot): array
{
    $envPath = $projectRoot . '/.env';
    $envSnapshot = captureEnvSnapshot($envPath);

    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive extension is required.');
    }

    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        throw new RuntimeException('Sodium extension is required.');
    }

    if (!isset($_FILES['package']) || !is_array($_FILES['package'])) {
        throw new RuntimeException('No package upload was received.');
    }

    $upload = $_FILES['package'];
    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Package upload failed with code: ' . $uploadError);
    }

    $tmpName = (string) ($upload['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Uploaded package payload is not available.');
    }

    $publicKeyPath = $deployRoot . '/trusted-release-key.pub';
    if (!is_file($publicKeyPath)) {
        throw new RuntimeException('Trusted release key missing at .deploy/trusted-release-key.pub');
    }

    $publicKey = (string) file_get_contents($publicKeyPath);
    if ($publicKey === '') {
        throw new RuntimeException('Trusted release key file is empty.');
    }

    $operationId = 'init-web-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
    $uploadsDir = $deployRoot . '/uploads';
    ensureDirectory($uploadsDir);

    $storedPackagePath = $uploadsDir . '/' . $operationId . '.zip';
    if (!move_uploaded_file($tmpName, $storedPackagePath)) {
        throw new RuntimeException('Failed to store uploaded package.');
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($storedPackagePath);
    if ($openResult !== true) {
        throw new RuntimeException('Could not open ZIP package; code ' . (string) $openResult);
    }

    try {
        $manifestJson = getZipEntry($zip, 'manifest.json');
        $signature = getZipEntry($zip, 'manifest.sig');

        if (!sodium_crypto_sign_verify_detached($signature, $manifestJson, $publicKey)) {
            throw new RuntimeException('Manifest signature verification failed.');
        }

        $manifest = decodeAndValidateManifest($manifestJson);
        validateZipAgainstManifest($zip, $manifest);
        $manifestSha256 = hash('sha256', $manifestJson);

        $releaseId = (string) $manifest['release_id'];
        $packageType = (string) $manifest['package_type'];

        $pointer = readPointer($deployRoot . '/current.json');
        $currentRelease = $pointer['current'];
        $liveManifestSha256Path = $deployRoot . '/live-manifest-sha256.txt';

        $stagingDir = $deployRoot . '/staging/' . $releaseId . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
        ensureDirectory($stagingDir);

        if ($packageType === 'patch') {
            $expectedBaseManifestSha256 = strtolower(trim((string) ($manifest['base_manifest_sha256'] ?? '')));
            if ($expectedBaseManifestSha256 === '' || preg_match('/^[a-f0-9]{64}$/i', $expectedBaseManifestSha256) !== 1) {
                throw new RuntimeException('Patch package is missing a valid base_manifest_sha256.');
            }

            $liveManifestSha256 = readLiveManifestSha256($liveManifestSha256Path);
            if ($liveManifestSha256 === null) {
                throw new RuntimeException('Patch package requires a previously deployed base manifest hash. Deploy a full package first.');
            }

            if (!hash_equals($expectedBaseManifestSha256, strtolower($liveManifestSha256))) {
                throw new RuntimeException('Patch base does not match the currently live deployment. Build patch against the currently live package manifest.');
            }

            copyManagedProjectPathsToStaging($projectRoot, $stagingDir);
        }

        $hashByPath = [];
        foreach ($manifest['files'] as $file) {
            $hashByPath[$file['path']] = $file['sha256'];
        }

        foreach ($manifest['payload_files'] as $payloadPath) {
            $entry = 'payload/' . $payloadPath;
            $contents = $zip->getFromName($entry);
            if ($contents === false) {
                throw new RuntimeException('Missing payload ZIP entry: ' . $entry);
            }

            $target = $stagingDir . '/' . str_replace('\\', '/', $payloadPath);
            ensureDirectory(dirname($target));
            if (file_put_contents($target, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Failed to write staged file: ' . $payloadPath);
            }

            $actual = hash_file('sha256', $target);
            $expected = $hashByPath[$payloadPath] ?? null;
            if (!is_string($expected) || $actual === false || !hash_equals($expected, $actual)) {
                throw new RuntimeException('SHA-256 mismatch for payload file: ' . $payloadPath);
            }
        }

        foreach ($manifest['delete'] as $deletePath) {
            $target = $stagingDir . '/' . str_replace('\\', '/', $deletePath);
            if (is_file($target)) {
                @unlink($target);
            }
        }

        foreach ($manifest['files'] as $file) {
            $target = $stagingDir . '/' . str_replace('\\', '/', $file['path']);
            if (!is_file($target)) {
                throw new RuntimeException('Staged file missing after materialization: ' . $file['path']);
            }
            $actual = hash_file('sha256', $target);
            if ($actual === false || !hash_equals($file['sha256'], $actual)) {
                throw new RuntimeException('Final SHA-256 mismatch for staged file: ' . $file['path']);
            }
        }

        $targetReleaseDir = $deployRoot . '/releases/' . $releaseId;
        if (is_dir($targetReleaseDir)) {
            throw new RuntimeException('Release already exists on target host: ' . $releaseId);
        }

        ensureDirectory(dirname($targetReleaseDir));
        if (!rename($stagingDir, $targetReleaseDir)) {
            throw new RuntimeException('Failed to move staged release into final location.');
        }

        publishReleaseToProjectRoot($targetReleaseDir, $projectRoot);

        writeLiveManifestSha256($liveManifestSha256Path, strtolower($manifestSha256));

        writePointer(
            $deployRoot . '/current.json',
            $releaseId,
            is_string($currentRelease) && $currentRelease !== '' ? $currentRelease : null,
            $operationId,
        );

        clearReleasesDirectory($deployRoot . '/releases');

        return [
            'release_id' => $releaseId,
            'operation_id' => $operationId,
        ];
    } finally {
        $zip->close();
        restoreEnvIfChanged($envPath, $envSnapshot);
    }
}

/**
 * @return array<int, array{label: string, ok: bool, detail: string}>
 */
function collectChecks(string $projectRoot, string $deployRoot): array
{
    $checks = [];

    $checks[] = [
        'label' => 'PHP version >= 8.1',
        'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'detail' => PHP_VERSION,
    ];

    $checks[] = [
        'label' => 'Sodium extension loaded',
        'ok' => function_exists('sodium_crypto_sign_verify_detached'),
        'detail' => function_exists('sodium_crypto_sign_verify_detached') ? 'loaded' : 'missing',
    ];

    $checks[] = [
        'label' => 'ZipArchive extension loaded',
        'ok' => class_exists(ZipArchive::class),
        'detail' => class_exists(ZipArchive::class) ? 'loaded' : 'missing',
    ];

    $checks[] = [
        'label' => 'Trusted key present (.deploy/trusted-release-key.pub)',
        'ok' => is_file($deployRoot . '/trusted-release-key.pub'),
        'detail' => is_file($deployRoot . '/trusted-release-key.pub') ? 'present' : 'missing',
    ];

    $checks[] = [
        'label' => 'Writable deploy root (.deploy)',
        'ok' => is_dir($deployRoot) ? is_writable($deployRoot) : is_writable($projectRoot),
        'detail' => is_dir($deployRoot) ? $deployRoot : $projectRoot,
    ];

    return $checks;
}

function renderTokenGate(?string $error): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>SparkInsight Init</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#111;padding:2rem}';
    echo '.card{max-width:680px;margin:2rem auto;background:#fff;border:1px solid #d7deea;border-radius:10px;padding:1.25rem}';
    echo 'input,button{padding:.55rem .7rem;font-size:1rem}button{cursor:pointer}';
    echo '.err{color:#8a1c1c;font-weight:600}</style></head><body><div class="card">';
    echo '<h1>SparkInsight Init</h1>';
    echo '<p>This endpoint is token-protected. Provide the token generated by <code>composer release:wizard</code>.</p>';
    if ($error !== null) {
        echo '<p class="err">' . h($error) . '</p>';
    }
    echo '<form method="get"><label for="token">Init token</label><br><input id="token" name="token" type="text" style="width:100%" required><br><br><button type="submit">Unlock</button></form>';
    echo '</div></body></html>';
}

/**
 * @param array<int, array{label: string, ok: bool, detail: string}> $checks
 * @param array<int, string> $messages
 * @param array<int, string> $errors
 */
function renderWizard(array $checks, array $messages, array $errors, string $token, bool $tokenStillEnabled): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>SparkInsight Init Wizard</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#111;padding:1.2rem}';
    echo '.wrap{max-width:980px;margin:0 auto}.card{background:#fff;border:1px solid #d7deea;border-radius:10px;padding:1rem 1.1rem;margin-bottom:1rem}';
    echo 'table{width:100%;border-collapse:collapse}th,td{padding:.55rem;border-bottom:1px solid #edf1f8;text-align:left}';
    echo '.ok{color:#0c6a2c;font-weight:700}.bad{color:#8a1c1c;font-weight:700}';
    echo 'input[type=file],button{padding:.55rem .7rem;font-size:1rem}button{cursor:pointer}';
    echo '.row{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}';
    echo '.progress{height:10px;background:#e9eef7;border-radius:999px;overflow:hidden;margin-top:.6rem;display:none}';
    echo '.progress > span{display:block;height:100%;width:0;background:#2b6cb0;transition:width .2s ease}';
    echo '.status{margin-top:.5rem;color:#334155;font-size:.95rem;display:none}';
    echo '.msg{padding:.6rem .75rem;border-radius:8px;margin:.4rem 0}.msg.ok{background:#e8f7ec;border:1px solid #b8e1c2;color:#0f5121}.msg.bad{background:#fbeaea;border:1px solid #f2b8b8;color:#842029}';
    echo 'code{background:#f3f6fb;padding:.1rem .3rem;border-radius:4px}</style></head><body><div class="wrap">';
    echo '<div class="card"><h1>SparkInsight Init Wizard</h1>';
    echo '<p>Use this installer for first-time activation when the full app is not yet runnable.</p>';
    if ($tokenStillEnabled) {
        echo '<p><strong>Token protection:</strong> active for this session.</p>';
    } else {
        echo '<p><strong>Token protection:</strong> disabled (token file not present).</p>';
    }
    echo '</div>';

    if ($messages !== []) {
        echo '<div class="card">';
        foreach ($messages as $message) {
            echo '<div class="msg ok">' . h($message) . '</div>';
        }
        echo '<p>Next: open <code>/</code> to load the activated release.</p>';
        echo '</div>';
    }

    if ($errors !== []) {
        echo '<div class="card">';
        foreach ($errors as $error) {
            echo '<div class="msg bad">' . h($error) . '</div>';
        }
        echo '</div>';
    }

    echo '<div class="card"><h2>Environment checks</h2><table><thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
    foreach ($checks as $check) {
        echo '<tr><td>' . h($check['label']) . '</td><td>' . ($check['ok'] ? '<span class="ok">OK</span>' : '<span class="bad">FAIL</span>') . '</td><td>' . h($check['detail']) . '</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="card"><h2>Upload and activate a signed package</h2>';
    echo '<p>Upload a <strong>full</strong> package for first activation. Patch packages require an already active release.</p>';
    echo '<form id="deploy-form" method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="deploy-package">';
    if ($token !== '') {
        echo '<input type="hidden" name="token" value="' . h($token) . '">';
    }
    echo '<div class="row">';
    echo '<input id="package-file" type="file" name="package" accept=".zip,application/zip" required>';
    echo '<button id="deploy-submit" type="submit">Verify, materialize, and activate</button>';
    echo '</div>';
    echo '<div id="deploy-progress" class="progress" aria-hidden="true"><span id="deploy-progress-bar"></span></div>';
    echo '<div id="deploy-status" class="status" aria-live="polite"></div>';
    echo '</form></div>';

    echo '<script>';
    echo '(function(){';
    echo 'var form=document.getElementById("deploy-form");';
    echo 'if(!form){return;}';
    echo 'var fileInput=document.getElementById("package-file");';
    echo 'var submitButton=document.getElementById("deploy-submit");';
    echo 'var progress=document.getElementById("deploy-progress");';
    echo 'var progressBar=document.getElementById("deploy-progress-bar");';
    echo 'var status=document.getElementById("deploy-status");';
    echo 'var done=false;';
    echo 'function showStatus(text){status.style.display="block";status.textContent=text;}';
    echo 'function setProgress(percent){progress.style.display="block";progressBar.style.width=percent+"%";}';
    echo 'form.addEventListener("submit",function(event){';
    echo 'if(done){return;}';
    echo 'event.preventDefault();';
    echo 'if(!fileInput || !fileInput.files || fileInput.files.length===0){showStatus("Choose a ZIP package first.");return;}';
    echo 'submitButton.disabled=true;';
    echo 'showStatus("Uploading package...");';
    echo 'setProgress(1);';
    echo 'var formData=new FormData(form);';
    echo 'var xhr=new XMLHttpRequest();';
    echo 'xhr.open("POST", window.location.href, true);';
    echo 'xhr.upload.addEventListener("progress", function(ev){';
    echo 'if(ev.lengthComputable){';
    echo 'var pct=Math.max(1, Math.min(99, Math.round((ev.loaded/ev.total)*100)));';
    echo 'setProgress(pct);';
    echo 'showStatus("Uploading package... " + pct + "%");';
    echo '}';
    echo '});';
    echo 'xhr.addEventListener("load", function(){';
    echo 'setProgress(100);';
    echo 'showStatus("Upload complete. Verifying and activating package...");';
    echo 'document.open();document.write(xhr.responseText);document.close();';
    echo 'done=true;';
    echo '});';
    echo 'xhr.addEventListener("error", function(){';
    echo 'submitButton.disabled=false;';
    echo 'showStatus("Upload failed. Check connection and try again.");';
    echo '});';
    echo 'xhr.send(formData);';
    echo '});';
    echo '})();';
    echo '</script>';

    echo '</div></body></html>';
}

/**
 * @return array<string, mixed>
 */
function decodeAndValidateManifest(string $manifestJson): array
{
    $decoded = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Manifest JSON must decode to an object.');
    }

    if (($decoded['format'] ?? null) !== MANIFEST_FORMAT) {
        throw new RuntimeException('Invalid manifest format identifier.');
    }

    if (($decoded['application'] ?? null) !== MANIFEST_APPLICATION) {
        throw new RuntimeException('Invalid manifest application identifier.');
    }

    $packageType = (string) ($decoded['package_type'] ?? '');
    if (!in_array($packageType, ['full', 'patch'], true)) {
        throw new RuntimeException('Manifest package_type must be full or patch.');
    }

    $releaseId = trim((string) ($decoded['release_id'] ?? ''));
    if ($releaseId === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/', $releaseId) !== 1) {
        throw new RuntimeException('Manifest release_id is invalid.');
    }

    $minimumPhp = trim((string) ($decoded['minimum_php'] ?? ''));
    if ($minimumPhp === '' || !version_compare(PHP_VERSION, $minimumPhp, '>=')) {
        throw new RuntimeException('This package requires PHP ' . $minimumPhp . ' or newer.');
    }

    $requiredExtensions = $decoded['required_extensions'] ?? [];
    if (!is_array($requiredExtensions)) {
        throw new RuntimeException('Manifest required_extensions must be an array.');
    }

    foreach ($requiredExtensions as $extension) {
        if (!is_string($extension) || $extension === '' || !extension_loaded($extension)) {
            throw new RuntimeException('Required PHP extension missing: ' . (string) $extension);
        }
    }

    $files = $decoded['files'] ?? null;
    $payloadFiles = $decoded['payload_files'] ?? null;
    $deletePaths = $decoded['delete'] ?? [];

    if (!is_array($files) || !is_array($payloadFiles) || !is_array($deletePaths)) {
        throw new RuntimeException('Manifest files/payload_files/delete fields are invalid.');
    }

    $normalizedFiles = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            throw new RuntimeException('Manifest files entry is invalid.');
        }

        $path = normalizePayloadPath((string) ($file['path'] ?? ''));
        $size = $file['size'] ?? null;
        $sha256 = strtolower((string) ($file['sha256'] ?? ''));
        if (!is_int($size) || $size < 0) {
            throw new RuntimeException('Manifest file size is invalid for: ' . $path);
        }

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException('Manifest file hash is invalid for: ' . $path);
        }

        $normalizedFiles[] = ['path' => $path, 'size' => $size, 'sha256' => $sha256];
    }

    $normalizedPayload = [];
    foreach ($payloadFiles as $path) {
        if (!is_string($path)) {
            throw new RuntimeException('Manifest payload_files entry is invalid.');
        }
        $normalizedPayload[] = normalizePayloadPath($path);
    }

    $normalizedDelete = [];
    foreach ($deletePaths as $path) {
        if (!is_string($path)) {
            throw new RuntimeException('Manifest delete entry is invalid.');
        }
        $normalizedDelete[] = normalizePayloadPath($path);
    }

    $filePathSet = [];
    foreach ($normalizedFiles as $file) {
        $filePathSet[$file['path']] = true;
    }

    foreach ($normalizedPayload as $path) {
        if (!isset($filePathSet[$path])) {
            throw new RuntimeException('payload_files contains non-file path: ' . $path);
        }
    }

    if ($packageType === 'full') {
        $allFilePaths = array_keys($filePathSet);
        sort($allFilePaths);
        $payloadSorted = array_values(array_unique($normalizedPayload));
        sort($payloadSorted);

        if ($allFilePaths !== $payloadSorted) {
            throw new RuntimeException('Full package payload_files must contain all files.');
        }

        if ($normalizedDelete !== []) {
            throw new RuntimeException('Full package must not include delete paths.');
        }
    }

    $decoded['files'] = $normalizedFiles;
    $decoded['payload_files'] = array_values(array_unique($normalizedPayload));
    $decoded['delete'] = array_values(array_unique($normalizedDelete));

    return $decoded;
}

function validateZipAgainstManifest(ZipArchive $zip, array $manifest): void
{
    $allowed = [
        'manifest.json' => true,
        'manifest.sig' => true,
    ];

    foreach ($manifest['payload_files'] as $path) {
        $allowed['payload/' . $path] = true;
    }

    $seen = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat) || !isset($stat['name'])) {
            throw new RuntimeException('Could not inspect ZIP entries.');
        }

        $entry = (string) $stat['name'];
        $lower = strtolower($entry);
        if (isset($seen[$lower])) {
            throw new RuntimeException('Duplicate ZIP entry detected: ' . $entry);
        }
        $seen[$lower] = true;

        if (str_ends_with($entry, '/')) {
            continue;
        }

        if (!isset($allowed[$entry])) {
            throw new RuntimeException('Unexpected ZIP entry not declared in manifest: ' . $entry);
        }
    }

    foreach ($manifest['payload_files'] as $path) {
        $entry = 'payload/' . $path;
        if ($zip->locateName($entry) === false) {
            throw new RuntimeException('Missing payload entry: ' . $entry);
        }
    }
}

function getZipEntry(ZipArchive $zip, string $name): string
{
    $contents = $zip->getFromName($name);
    if ($contents === false) {
        throw new RuntimeException('Required ZIP entry missing: ' . $name);
    }

    return $contents;
}

/**
 * @return array{format: int, current: ?string, previous: ?string, activated_at: ?string, operation_id: ?string}
 */
function readPointer(string $path): array
{
    if (!is_file($path)) {
        return [
            'format' => 1,
            'current' => null,
            'previous' => null,
            'activated_at' => null,
            'operation_id' => null,
        ];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('current.json is invalid.');
    }

    return [
        'format' => (int) ($decoded['format'] ?? 1),
        'current' => isset($decoded['current']) && is_string($decoded['current']) ? $decoded['current'] : null,
        'previous' => isset($decoded['previous']) && is_string($decoded['previous']) ? $decoded['previous'] : null,
        'activated_at' => isset($decoded['activated_at']) && is_string($decoded['activated_at']) ? $decoded['activated_at'] : null,
        'operation_id' => isset($decoded['operation_id']) && is_string($decoded['operation_id']) ? $decoded['operation_id'] : null,
    ];
}

function writePointer(string $path, ?string $current, ?string $previous, ?string $operationId): void
{
    ensureDirectory(dirname($path));

    $payload = [
        'format' => 1,
        'current' => $current,
        'previous' => $previous,
        'activated_at' => date(DATE_ATOM),
        'operation_id' => $operationId,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Failed to encode current pointer document.');
    }

    $tmpPath = $path . '.tmp';
    if (file_put_contents($tmpPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write current pointer temporary file.');
    }

    if (is_file($path) && !unlink($path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Could not replace existing current pointer file.');
    }

    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException('Could not move new current pointer file into place.');
    }
}

function normalizePayloadPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        throw new RuntimeException('Path must not be empty.');
    }

    if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:\\//', $path) === 1) {
        throw new RuntimeException('Absolute paths are not allowed: ' . $path);
    }

    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('Invalid path traversal segment in: ' . $path);
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
            throw new RuntimeException('Invalid path segment: ' . $segment);
        }
    }

    if (isReservedPath($path)) {
        throw new RuntimeException('Reserved path is not allowed in package: ' . $path);
    }

    return $path;
}

function isReservedPath(string $path): bool
{
    $lower = strtolower($path);
    $reservedExact = [
        '.env',
        '.deploy',
        '.htaccess',
        'index.php',
        'recovery.php',
        'manifest.json',
        'manifest.sig',
    ];

    if (in_array($lower, $reservedExact, true)) {
        return true;
    }

    if (str_starts_with($lower, '.deploy/')) {
        return true;
    }

    if (preg_match('#(^|/)\.deploy(/|$)#', $lower) === 1) {
        return true;
    }

    return preg_match('#(^|/)\.env(/|$)#', $lower) === 1;
}

function ensureDirectory(string $directory): void
{
    if ($directory === '' || is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create directory: ' . $directory);
    }
}

function copyDirectory(string $source, string $target): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen(rtrim($source, '/\\')) + 1);
        $targetPath = rtrim($target, '/\\') . '/' . str_replace('\\', '/', $relative);

        if ($item->isDir()) {
            ensureDirectory($targetPath);
            continue;
        }

        ensureDirectory(dirname($targetPath));
        if (!copy($item->getPathname(), $targetPath)) {
            throw new RuntimeException('Failed to copy base release file: ' . $relative);
        }
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function publishReleaseToProjectRoot(string $releaseRoot, string $projectRoot): void
{
    $managedPaths = [
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

    foreach ($managedPaths as $relativePath) {
        $targetPath = rtrim($projectRoot, '/\\') . '/' . $relativePath;
        removePathRecursive($targetPath);

        $sourcePath = rtrim($releaseRoot, '/\\') . '/' . $relativePath;
        if (is_dir($sourcePath)) {
            copyDirectory($sourcePath, $targetPath);
            continue;
        }

        if (is_file($sourcePath)) {
            ensureDirectory(dirname($targetPath));
            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException('Failed to publish file to project root: ' . $relativePath);
            }
        }
    }

    if (!is_file(rtrim($projectRoot, '/\\') . '/public/index.php')) {
        throw new RuntimeException('Published project root is missing public/index.php.');
    }
}

function removePathRecursive(string $path): void
{
    if (is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Failed to remove file before publish: ' . $path);
        }

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            if (!rmdir($item->getPathname())) {
                throw new RuntimeException('Failed to remove directory before publish: ' . $item->getPathname());
            }
            continue;
        }

        if (!unlink($item->getPathname())) {
            throw new RuntimeException('Failed to remove file before publish: ' . $item->getPathname());
        }
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Failed to remove directory before publish: ' . $path);
    }
}

/**
 * @return array{exists: bool, hash: string|null, contents: string|null}
 */
function captureEnvSnapshot(string $envPath): array
{
    if (!is_file($envPath)) {
        return [
            'exists' => false,
            'hash' => null,
            'contents' => null,
        ];
    }

    $contents = file_get_contents($envPath);
    if ($contents === false) {
        throw new RuntimeException('Could not read existing .env file before deployment guard.');
    }

    return [
        'exists' => true,
        'hash' => hash('sha256', $contents),
        'contents' => $contents,
    ];
}

/**
 * @param array{exists: bool, hash: string|null, contents: string|null} $snapshot
 */
function restoreEnvIfChanged(string $envPath, array $snapshot): void
{
    if ($snapshot['exists'] === false) {
        if (is_file($envPath)) {
            @unlink($envPath);
            throw new RuntimeException('Root .env was unexpectedly created during deployment and has been removed.');
        }

        return;
    }

    if (!is_file($envPath)) {
        if (!is_string($snapshot['contents']) || file_put_contents($envPath, $snapshot['contents'], LOCK_EX) === false) {
            throw new RuntimeException('Root .env changed unexpectedly and could not be restored.');
        }

        throw new RuntimeException('Root .env was unexpectedly removed during deployment and has been restored.');
    }

    $currentContents = file_get_contents($envPath);
    if ($currentContents === false) {
        throw new RuntimeException('Could not verify root .env after deployment.');
    }

    $currentHash = hash('sha256', $currentContents);
    $expectedHash = $snapshot['hash'];
    if (!is_string($expectedHash) || hash_equals($expectedHash, $currentHash)) {
        return;
    }

    if (!is_string($snapshot['contents']) || file_put_contents($envPath, $snapshot['contents'], LOCK_EX) === false) {
        throw new RuntimeException('Root .env changed unexpectedly and could not be restored.');
    }

    throw new RuntimeException('Root .env changed unexpectedly during deployment and has been restored.');
}

function readLiveManifestSha256(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }

    $value = strtolower(trim((string) file_get_contents($path)));
    if ($value === '' || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
        return null;
    }

    return $value;
}

function writeLiveManifestSha256(string $path, string $sha256): void
{
    if (preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
        throw new RuntimeException('Invalid manifest hash value for live hash tracking.');
    }

    if (file_put_contents($path, strtolower($sha256) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not write live manifest hash file.');
    }
}

function copyManagedProjectPathsToStaging(string $projectRoot, string $stagingDir): void
{
    $managedPaths = [
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

    foreach ($managedPaths as $relativePath) {
        $sourcePath = $projectRoot . '/' . $relativePath;
        $targetPath = $stagingDir . '/' . $relativePath;

        if (is_dir($sourcePath)) {
            copyDirectory($sourcePath, $targetPath);
            continue;
        }

        if (is_file($sourcePath)) {
            ensureDirectory(dirname($targetPath));
            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException('Failed to copy base project file: ' . $relativePath);
            }
        }
    }
}

function clearReleasesDirectory(string $releasesDir): void
{
    if (!is_dir($releasesDir)) {
        return;
    }

    $items = scandir($releasesDir);
    if ($items === false) {
        throw new RuntimeException('Could not scan releases directory for cleanup.');
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        deletePathRecursive($releasesDir . '/' . $item);
    }
}

function deletePathRecursive(string $path): void
{
    if (is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Failed to remove file: ' . $path);
        }

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException('Failed to scan directory: ' . $path);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        deletePathRecursive($path . '/' . $item);
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Failed to remove directory: ' . $path);
    }
}