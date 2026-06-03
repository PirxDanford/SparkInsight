<?php

declare(strict_types=1);

namespace SparkInsight\Service;

final class UserSession implements UserSessionInterface
{
    public function __construct()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.use_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');

            session_name('sparkinsight_session');

            $secure = false;
            if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
                $secure = true;
            }
            if (!$secure && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
                $secure = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
            }
            if (!$secure && !empty($_SERVER['HTTP_X_FORWARDED_SSL'])) {
                $secure = strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) !== 'off';
            }

            ini_set('session.cookie_secure', $secure ? '1' : '0');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public function setUser(array $user): void
    {
        $_SESSION['user'] = $user;
    }

    public function getUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function setState(string $state): void
    {
        $_SESSION['oauth_state'] = $state;
    }

    public function getState(): ?string
    {
        return $_SESSION['oauth_state'] ?? null;
    }

    public function clear(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'] ?? '/',
                    'domain' => $params['domain'] ?? '',
                    'secure' => $params['secure'] ?? false,
                    'httponly' => $params['httponly'] ?? true,
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['user']);
    }

    public function setData(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function getData(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function setFlash(string $type, string $message): void
    {
        $_SESSION['_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    public function getFlash(): ?array
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        if (!is_array($flash)) {
            return null;
        }

        return $flash;
    }

    public function getCsrfToken(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(16));
        }

        return $_SESSION['_csrf_token'];
    }

    public function validateCsrfToken(string $token): bool
    {
        if (empty($_SESSION['_csrf_token']) || !is_string($token)) {
            return false;
        }

        return hash_equals($_SESSION['_csrf_token'], $token);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
