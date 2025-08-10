<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function auth_start_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            // Older PHP: no SameSite support in API
            session_set_cookie_params(0, '/', '', $secure, true);
        }
        session_start();
    }
}

function auth_is_logged_in(): bool {
    auth_start_session();
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function auth_require_login(): void {
    if (!auth_is_logged_in()) {
        // Redirect to a fixed absolute admin login path to avoid nested directories
        header('Location: /admin/login.php');
        exit;
    }
}

function auth_login(string $username, string $password): bool {
    auth_start_session();
    // Throttle simple: 5 attempts within 5 minutes per IP
    if (auth_is_login_throttled()) {
        return false;
    }
    // 1) DB overrides (if set via settings)
    $dbUsername = db_get_setting('admin_username', ADMIN_USERNAME);
    $dbPasswordHash = db_get_setting('admin_password_hash', ADMIN_PASSWORD_HASH);
    $hashOk = false;
    if ($dbPasswordHash !== '') {
        $hashOk = password_verify($password, $dbPasswordHash);
    } elseif (ADMIN_PASSWORD_HASH !== '') {
        $hashOk = password_verify($password, ADMIN_PASSWORD_HASH);
    }
    $plainOk = (ADMIN_PASSWORD_HASH === '' && defined('ADMIN_PASSWORD') && ADMIN_PASSWORD !== '' && hash_equals(ADMIN_PASSWORD, $password));
    if ($username === $dbUsername && ($hashOk || $plainOk)) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $dbUsername;
        if (function_exists('session_regenerate_id')) {
            session_regenerate_id(true);
        }
        auth_clear_attempts('login:' . auth_client_ip());
        return true;
    }
    auth_add_attempt('login:' . auth_client_ip());
    return false;
}

function auth_logout(): void {
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function auth_get_csrf_token(): string {
    auth_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function auth_validate_csrf_token(?string $token): bool {
    auth_start_session();
    if (!isset($_SESSION['csrf_token']) || !is_string($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function auth_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return is_string($ip) ? $ip : '0.0.0.0';
}

function auth_add_attempt(string $key): void {
    auth_start_session();
    if (!isset($_SESSION['rate_limit'])) { $_SESSION['rate_limit'] = []; }
    if (!isset($_SESSION['rate_limit'][$key])) { $_SESSION['rate_limit'][$key] = []; }
    $_SESSION['rate_limit'][$key][] = time();
}

function auth_clear_attempts(string $key): void {
    auth_start_session();
    if (isset($_SESSION['rate_limit'][$key])) {
        unset($_SESSION['rate_limit'][$key]);
    }
}

function auth_is_login_throttled(): bool {
    auth_start_session();
    $key = 'login:' . auth_client_ip();
    // max 5 attempts in 5 minutes
    $max = 5; $window = 5 * 60; $now = time();
    $arr = $_SESSION['rate_limit'][$key] ?? [];
    // prune
    $arr = array_values(array_filter($arr, function($t) use ($now, $window) { return ($now - (int)$t) <= $window; }));
    $_SESSION['rate_limit'][$key] = $arr;
    return count($arr) >= $max;
}
