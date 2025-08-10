<?php
// Simple health check endpoint
// Returns DB connectivity status and app version info if needed

define('EXPECT_JSON', true);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/headers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (defined('CORS_ALLOW_ORIGIN')) {
    header('Access-Control-Allow-Origin: ' . CORS_ALLOW_ORIGIN);
}
send_common_security_headers();

$ok = true; $dbOk = false; $error = '';
try {
    db_init_schema();
    $pdo = get_pdo();
    $pdo->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    $ok = false; $dbOk = false; $error = $e->getMessage();
}

echo json_encode([
    'code' => $ok ? 0 : 500,
    'data' => [
        'status' => $ok ? 'ok' : 'degraded',
        'db' => $dbOk ? 'ok' : 'error',
    ],
    'error' => $error !== '' ? $error : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
