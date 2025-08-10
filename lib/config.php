<?php
// Database configuration
// Primary: container/compose network (host: mysql)
// Fallback: external connection (host: 127.0.0.1)

// Read DB hosts from env (comma-separated), fallback to defaults
$__dbHostsEnv = getenv('DB_HOSTS');
$__dbHosts = $__dbHostsEnv ? array_values(array_filter(array_map('trim', explode(',', $__dbHostsEnv)))) : ['mysql', '127.0.0.1', 'localhost'];
define('DB_HOSTS', $__dbHosts);
unset($__dbHostsEnv, $__dbHosts);

define('DB_PORT', getenv('DB_PORT') !== false ? (int)getenv('DB_PORT') : 3306);
define('DB_NAME', getenv('DB_NAME') !== false ? (string)getenv('DB_NAME') : 'vps');
define('DB_USER', getenv('DB_USER') !== false ? (string)getenv('DB_USER') : 'vps');
// Default password kept for backward compatibility; override via ENV in production
define('DB_PASS', getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : '');

define('DB_CHARSET', getenv('DB_CHARSET') !== false ? (string)getenv('DB_CHARSET') : 'utf8mb4');

// Optional UNIX socket for MySQL/MariaDB, e.g. /var/run/mysqld/mysqld.sock
define('DB_SOCKET', getenv('DB_SOCKET') !== false ? (string)getenv('DB_SOCKET') : '');

define('SITE_NAME', getenv('SITE_NAME') !== false ? (string)getenv('SITE_NAME') : 'VPS Deals');

// CORS for API endpoints (default allow all). Set to specific origin in production.
if (!defined('CORS_ALLOW_ORIGIN')) {
    $cors = getenv('CORS_ALLOW_ORIGIN');
    define('CORS_ALLOW_ORIGIN', $cors !== false ? (string)$cors : '*');
}

// Security: Prefer hashed admin password; leave ADMIN_PASSWORD empty in production
// Example to generate:
//   php -r "echo password_hash('StrongPass123', PASSWORD_DEFAULT), \"\n\";"

define('BASE_PATH', dirname(__DIR__));

define('BASE_URL', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')); // relative base

// Admin auth configuration
// For production, set ADMIN_PASSWORD_HASH using password_hash() and leave ADMIN_PASSWORD empty
if (!defined('ADMIN_USERNAME')) {
    $envUser = getenv('ADMIN_USERNAME');
    define('ADMIN_USERNAME', $envUser !== false ? (string)$envUser : 'admin');
}
if (!defined('ADMIN_PASSWORD_HASH')) {
    // Empty by default; provide a real hash in production
    $envHash = getenv('ADMIN_PASSWORD_HASH');
    define('ADMIN_PASSWORD_HASH', $envHash !== false ? (string)$envHash : '');
}
if (!defined('ADMIN_PASSWORD')) {
    // Plaintext fallback for convenience; change immediately in production
    $envPass = getenv('ADMIN_PASSWORD');
    define('ADMIN_PASSWORD', $envPass !== false ? (string)$envPass : 'admin123');
}

// Error logging: write PHP errors to site-specific log
// Do not display errors to end users
if (function_exists('ini_set')) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    // Ensure log directory exists
    $logDir = dirname(__DIR__) . '/log';
    if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
    ini_set('error_log', $logDir . '/php-error.log');
}
if (function_exists('error_reporting')) {
    error_reporting(E_ALL);
}
