<?php
define('EXPECT_JSON', true);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ratelimit.php';
require_once __DIR__ . '/../lib/headers.php';

db_init_schema();
$pdo = get_pdo();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (defined('CORS_ALLOW_ORIGIN') ? CORS_ALLOW_ORIGIN : '*'));
send_common_security_headers();
header('Cache-Control: public, max-age=30, stale-while-revalidate=60');

// Simple IP-based rate limit (read-only API): 120 req/min
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!rate_limit_allow('api_plans:' . $ip, 120, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['code'=>429,'message'=>'Too Many Requests']);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$vendorId = isset($_GET['vendor']) ? (int)$_GET['vendor'] : 0;
$billing = isset($_GET['billing']) ? trim($_GET['billing']) : '';
$stock = isset($_GET['stock']) ? trim($_GET['stock']) : '';
$minPrice = isset($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
$maxPrice = isset($_GET['max_price']) ? (float)$_GET['max_price'] : 0;
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'default';
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int)($_GET['page_size'] ?? 20)));
$offset = ($page - 1) * $pageSize;
$recent = max(0, (int)($_GET['recent'] ?? 0));
$minCpu = isset($_GET['min_cpu']) ? (float)$_GET['min_cpu'] : 0;
$minRamGb = isset($_GET['min_ram_gb']) ? (float)$_GET['min_ram_gb'] : 0;
$minStorageGb = isset($_GET['min_storage_gb']) ? (int)$_GET['min_storage_gb'] : 0;

$allowedSort = [
  'default' => 'p.sort_order ASC, p.id DESC',
  'price_asc' => 'p.price ASC',
  'price_desc' => 'p.price DESC',
  'newest' => 'p.id DESC',
  // emulate nulls last/first
  'cpu_desc' => 'p.cpu_cores IS NULL ASC, p.cpu_cores DESC, p.id DESC',
  'cpu_asc' => 'p.cpu_cores IS NULL ASC, p.cpu_cores ASC, p.id DESC',
  'ram_desc' => 'p.ram_mb IS NULL ASC, p.ram_mb DESC, p.id DESC',
  'ram_asc' => 'p.ram_mb IS NULL ASC, p.ram_mb ASC, p.id DESC',
  'storage_desc' => 'p.storage_gb IS NULL ASC, p.storage_gb DESC, p.id DESC',
  'storage_asc' => 'p.storage_gb IS NULL ASC, p.storage_gb ASC, p.id DESC',
];
$orderBy = $allowedSort[$sort] ?? $allowedSort['default'];
if ($recent > 0) {
    $orderBy = 'p.updated_at DESC';
    $page = 1; $pageSize = min(100, $recent); $offset = 0;
}

$sql = 'SELECT p.*, v.name AS vendor_name, v.logo_url, v.website FROM plans p INNER JOIN vendors v ON v.id = p.vendor_id';
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.title LIKE :kw OR p.subtitle LIKE :kw OR v.name LIKE :kw)';
    $params[':kw'] = "%$q%";
}
if ($vendorId > 0) {
    $where[] = 'p.vendor_id = :vendor_id';
    $params[':vendor_id'] = $vendorId;
}
if ($billing !== '' && in_array($billing, ['per month','per year','one-time'], true)) {
    $where[] = 'p.price_duration = :billing';
    $params[':billing'] = $billing;
}
if ($stock !== '' && in_array($stock, ['in','out','unknown'], true)) {
    $where[] = 'p.stock_status = :stock';
    $params[':stock'] = $stock;
}
if ($minPrice > 0) {
    $where[] = 'p.price >= :min_price';
    $params[':min_price'] = $minPrice;
}
if ($maxPrice > 0 && ($minPrice === 0 || $maxPrice >= $minPrice)) {
    $where[] = 'p.price <= :max_price';
    $params[':max_price'] = $maxPrice;
}
if ($location !== '') {
    $where[] = 'p.location LIKE :loc';
    $params[':loc'] = "%$location%";
}
if ($minCpu > 0) {
    $where[] = 'p.cpu_cores IS NOT NULL AND p.cpu_cores >= :min_cpu';
    $params[':min_cpu'] = $minCpu;
}
if ($minRamGb > 0) {
    $where[] = 'p.ram_mb IS NOT NULL AND p.ram_mb >= :min_ram_mb';
    $params[':min_ram_mb'] = (int)round($minRamGb * 1024);
}
if ($minStorageGb > 0) {
    $where[] = 'p.storage_gb IS NOT NULL AND p.storage_gb >= :min_storage_gb';
    $params[':min_storage_gb'] = $minStorageGb;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sqlCount = 'SELECT COUNT(*) FROM plans p INNER JOIN vendors v ON v.id = p.vendor_id' . ($where ? (' WHERE ' . implode(' AND ', $where)) : '');
$totalStmt = $pdo->prepare($sqlCount);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

// Last-Modified for matching dataset
$sqlLM = 'SELECT MAX(p.updated_at) AS lm FROM plans p INNER JOIN vendors v ON v.id = p.vendor_id' . ($where ? (' WHERE ' . implode(' AND ', $where)) : '');
$lmStmt = $pdo->prepare($sqlLM);
$lmStmt->execute($params);
$lastModifiedStr = (string)$lmStmt->fetchColumn();
$lastModifiedTs = $lastModifiedStr ? strtotime($lastModifiedStr) : time();
$lastModHttp = gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT';

// Build a weak ETag from query, total and last modified
$etag = 'W/"' . sha1(json_encode([
    'q'=>$q,'vendor'=>$vendorId,'billing'=>$billing,'min'=>$minPrice,'max'=>$maxPrice,'loc'=>$location,
    'sort'=>$sort,'recent'=>$recent,'page'=>$page,'size'=>$pageSize,'total'=>$total,'lm'=>$lastModifiedStr
])) . '"';

// Conditional requests
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModHttp);
    http_response_code(304);
    exit;
}
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $ims = strtotime((string)$_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($ims !== false && $ims >= $lastModifiedTs) {
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastModHttp);
        http_response_code(304);
        exit;
    }
}

$sql .= ' ORDER BY ' . $orderBy . ' LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) { $stmt->bindValue($k, $v); }
$stmt->execute();
$plans = $stmt->fetchAll();

// Helper to derive cpu/ram/storage from features text list
/**
 * @param array<int,string>|string|null $features
 * @return array{cpu:string,ram:string,storage:string}
 */
function derive_specs_from_features($features): array {
    $list = [];
    if (is_array($features)) { $list = $features; }
    elseif (is_string($features)) {
        $decoded = json_decode($features, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) { $list = $decoded; }
        else { $list = [$features]; }
    }
    $cpu = '';$ram='';$storage='';
    foreach ($list as $item) {
        $txt = (string)$item;
        if ($cpu === '' && preg_match('/\b(?:[0-9]+(?:\.[0-9]+)?\s*)?v?CPU\b|核心/i', $txt)) { $cpu = $txt; }
        if ($ram === '' && preg_match('/\b[0-9]+(?:\.[0-9]+)?\s*(?:GB|MB)\b.*(?:RAM|内存)|\bRAM\b|内存/i', $txt)) { $ram = $txt; }
        if ($storage === '' && preg_match('/(?:NVMe|SSD|HDD|存储|Storage)/i', $txt)) { $storage = $txt; }
    }
    return ['cpu'=>$cpu,'ram'=>$ram,'storage'=>$storage];
}

// Sanitize: ensure features is array when JSON stored and derive structured specs
foreach ($plans as &$plan) {
    if (isset($plan['features']) && is_string($plan['features'])) {
        $decoded = json_decode($plan['features'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $plan['features'] = $decoded;
        }
    }
    // Prefer stored structured, fallback to derive
    $storedCpu = (string)($plan['cpu'] ?? '');
    $storedRam = (string)($plan['ram'] ?? '');
    $storedStorage = (string)($plan['storage'] ?? '');
    if ($storedCpu === '' || $storedRam === '' || $storedStorage === '') {
        $specs = derive_specs_from_features($plan['features'] ?? []);
        $plan['cpu'] = $storedCpu !== '' ? $storedCpu : $specs['cpu'];
        $plan['ram'] = $storedRam !== '' ? $storedRam : $specs['ram'];
        $plan['storage'] = $storedStorage !== '' ? $storedStorage : $specs['storage'];
    }
    // Ensure numeric fields are included even if not stored
    if (!isset($plan['cpu_cores']) || $plan['cpu_cores'] === null) {
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*v?CPU/i', (string)($plan['cpu'] ?? ''), $m)) {
            $plan['cpu_cores'] = (float)$m[1];
        }
    }
    if (!isset($plan['ram_mb']) || $plan['ram_mb'] === null) {
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\b/i', (string)($plan['ram'] ?? ''), $m)) {
            $plan['ram_mb'] = (int)round((float)$m[1] * (strtoupper($m[2])==='GB' ? 1024 : 1));
        }
    }
    if (!isset($plan['storage_gb']) || $plan['storage_gb'] === null) {
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\b/i', (string)($plan['storage'] ?? ''), $m)) {
            $val = (float)$m[1]; $unit = strtoupper($m[2]);
            $plan['storage_gb'] = (int)round($val * ($unit==='TB'?1024:($unit==='GB'?1:1/1024)));
        }
    }
}
unset($plan);

header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModHttp);
echo json_encode([
    'code' => 0,
    'data' => [
        'items' => $plans,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
