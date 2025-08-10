<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ratelimit.php';

db_init_schema();
$pdo = get_pdo();

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    auth_require_login();
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!rate_limit_allow('import_orangevps:' . $ip, 6, 60)) {
        http_response_code(429);
        header('Retry-After: 60');
        echo "Rate limit exceeded. Please retry later.\n";
        exit;
    }
}

// --- Input ---
$defaultAff = '171';
$defaultPid = '271';
$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
$aff = isset($_GET['aff']) ? trim((string)$_GET['aff']) : $defaultAff;
$pid = isset($_GET['pid']) ? trim((string)$_GET['pid']) : $defaultPid;

if ($isCli) {
    // CLI: first arg as URL or parse --aff= --pid=
    $args = array_slice($argv, 1);
    foreach ($args as $a) {
        if (strpos($a, '--aff=') === 0) { $aff = substr($a, 6); }
        elseif (strpos($a, '--pid=') === 0) { $pid = substr($a, 6); }
        elseif (preg_match('~^https?://~i', $a)) { $url = $a; }
    }
}

if ($url === '') {
    $url = 'https://portal.orangevps.com/aff.php?aff=' . rawurlencode($aff) . '&pid=' . rawurlencode($pid);
}

$vendorName = 'OrangeVPS';
$website = 'https://portal.orangevps.com';
$logo = 'https://portal.orangevps.com/templates/six/img/logo.png';
$desc = 'OrangeVPS promotion';

$planTitle = 'SG60';
$planSubtitle = 'VPS BUDGET (ALWAYS PROMO)';
$planPrice = 60.00; // USD
$planDuration = 'per year';
$planLocation = 'Singapore, Hong Kong';
$planHighlights = 'Always Promo';
$planFeatures = [
    '60 GB NVMe SSD Storage',
    '9 GB RAM DDR4',
    '8 vCPU (Dual Intel Xeon E5-2699 v4)',
    '1965 GB Transfer Data',
    'KVM Virtualization',
    '1 Gbps Bandwidth',
    'IP Addresses',
    'Operating System: AlmaLinux 8 (option)',
    'Hong Kong Test IP: 202.155.141.19'
];

try {
    $pdo->beginTransaction();

    // Upsert vendor
    $stmt = $pdo->prepare('INSERT INTO vendors (name, website, logo_url, description)
        VALUES (:name, :website, :logo, :desc)
        ON DUPLICATE KEY UPDATE website = VALUES(website), logo_url = VALUES(logo_url), description = VALUES(description), updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([
        ':name' => $vendorName,
        ':website' => $website,
        ':logo' => $logo,
        ':desc' => $desc,
    ]);

    $vendorId = (int)$pdo->lastInsertId();
    if ($vendorId === 0) {
        $vendorId = (int)$pdo->query('SELECT id FROM vendors WHERE name = ' . $pdo->quote($vendorName))->fetchColumn();
    }

    // De-duplicate same order_url
    $pdo->prepare('DELETE FROM plans WHERE vendor_id = :vid AND order_url = :url')->execute([
        ':vid' => $vendorId,
        ':url' => $url,
    ]);

    // Insert plan
    $stmt = $pdo->prepare('INSERT INTO plans (vendor_id, title, subtitle, price, price_duration, order_url, location, features, highlights, sort_order)
        VALUES (:vendor_id, :title, :subtitle, :price, :duration, :order_url, :location, :features, :highlights, :sort_order)');
    $stmt->execute([
        ':vendor_id' => $vendorId,
        ':title' => $planTitle,
        ':subtitle' => $planSubtitle,
        ':price' => $planPrice,
        ':duration' => $planDuration,
        ':order_url' => $url,
        ':location' => $planLocation,
        ':features' => json_encode($planFeatures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':highlights' => $planHighlights,
        ':sort_order' => 0,
    ]);

    $pdo->commit();
    echo "OrangeVPS plan imported successfully.\n";
    echo "Vendor #$vendorId\n";
    echo "URL: $url\n";
    echo "Plan: $planTitle - $" . number_format($planPrice, 2) . " $planDuration\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo 'Import failed: ' . $e->getMessage();
}
