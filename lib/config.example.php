<?php
// Example configuration for vps-show
// Copy this file to lib/config.php and adjust values for your environment.

// Database
if (!defined('DB_HOSTS')) { define('DB_HOSTS', ['mysql','127.0.0.1','localhost']); }
if (!defined('DB_PORT')) { define('DB_PORT', 3306); }
if (!defined('DB_NAME')) { define('DB_NAME', 'vps-show'); }
if (!defined('DB_USER')) { define('DB_USER', 'vps-show'); }
if (!defined('DB_PASS')) { define('DB_PASS', 'change-me'); }
if (!defined('DB_CHARSET')) { define('DB_CHARSET', 'utf8mb4'); }
if (!defined('DB_SOCKET')) { define('DB_SOCKET', ''); }

// Site
if (!defined('SITE_NAME')) { define('SITE_NAME', 'VPS Deals'); }

// CORS (public APIs)
if (!defined('CORS_ALLOW_ORIGIN')) { define('CORS_ALLOW_ORIGIN', '*'); }

// Timezone (affects PHP date/time functions and logs)
if (!defined('TIMEZONE')) { define('TIMEZONE', 'Asia/Shanghai'); }
if (function_exists('date_default_timezone_set')) { @date_default_timezone_set(TIMEZONE); }

// Base path/url helpers
if (!defined('BASE_PATH')) { define('BASE_PATH', dirname(__DIR__)); }
if (!defined('BASE_URL')) {
  $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
  $base = rtrim(str_replace(basename($scriptName), '', $scriptName), '/');
  define('BASE_URL', $base === '' ? '/' : $base);
}

// Admin credentials (for production set ADMIN_PASSWORD_HASH, leave ADMIN_PASSWORD empty)
if (!defined('ADMIN_USERNAME')) { define('ADMIN_USERNAME', 'admin'); }
if (!defined('ADMIN_PASSWORD_HASH')) { define('ADMIN_PASSWORD_HASH', ''); }
if (!defined('ADMIN_PASSWORD')) { define('ADMIN_PASSWORD', 'admin123'); }

// Error logging
if (function_exists('ini_set')) {
  ini_set('display_errors', '0');
  ini_set('log_errors', '1');
  $logDir = __DIR__ . '/../log';
  if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
  ini_set('error_log', $logDir . '/php-error.log');
}
if (function_exists('error_reporting')) { error_reporting(E_ALL); }
