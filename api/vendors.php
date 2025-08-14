<?php
define('EXPECT_JSON', true);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/headers.php';

db_init_schema();
$pdo = get_pdo();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (defined('CORS_ALLOW_ORIGIN') ? CORS_ALLOW_ORIGIN : '*'));
send_common_security_headers();

$vendors = $pdo->query('SELECT id, name, website, logo_url, description FROM vendors ORDER BY name ASC')->fetchAll();

echo json_encode([
    'code' => 0,
    'data' => $vendors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
