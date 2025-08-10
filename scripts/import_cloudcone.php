<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ratelimit.php';

auth_require_login();

db_init_schema();
$pdo = get_pdo();

// Defaults from the provided CloudCone campaign URL
$campaignUrl = 'https://app.cloudcone.com/vps/405/create?ref=12885&token=flash-q3-25-vps-1';
$vendorName = 'CloudCone';
$website = 'https://app.cloudcone.com';
$logo = 'https://www.cloudcone.com/wp-content/uploads/2018/05/cloudcone-logo.png';
$desc = 'CloudCone VPS flash sale plans';

// Allow overriding via GET params
$campaignUrl = isset($_GET['url']) && $_GET['url'] !== '' ? $_GET['url'] : $campaignUrl;
$ref = isset($_GET['ref']) ? trim($_GET['ref']) : '';
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if ($ref !== '' || $token !== '') {
    // Rebuild the campaign URL with provided params
    $parts = parse_url($campaignUrl);
    parse_str($parts['query'] ?? '', $query);
    if ($ref !== '') { $query['ref'] = $ref; }
    if ($token !== '') { $query['token'] = $token; }
    $campaignUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'app.cloudcone.com') . ($parts['path'] ?? '/vps/405/create') . '?' . http_build_query($query);
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Simple IP-based rate limit: 10 req/min
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!rate_limit_allow('import_cloudcone:' . $ip, 10, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo "Rate limit exceeded. Please retry later.\n";
    exit;
}

// --- Helpers ---
function http_fetch_string(string $url, int $timeoutSec = 12): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36 VPSImporter/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8'
            ],
        ]);
        $body = (string)curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === '' && $status >= 400) {
            throw new RuntimeException('HTTP fetch failed status=' . $status . ' error=' . $err);
        }
        return $body;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeoutSec, 'header' => "User-Agent: VPSImporter/1.0\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $s = @file_get_contents($url, false, $ctx);
    if ($s === false) { throw new RuntimeException('HTTP fetch failed via file_get_contents'); }
    return $s;
}

/**
 * Best-effort parse: extract price, duration and a few feature bullets from HTML text
 */
function parse_cloudcone_html(string $html): array {
    $text = strip_tags($html);
    // Normalize whitespace
    $text = preg_replace('/[\r\n\t]+/', ' ', $text);
    $text = preg_replace('/\s{2,}/', ' ', $text);

    $price = 0.00; $duration = 'per year';
    if (preg_match('/\$\s*([0-9]+(?:\.[0-9]{1,2})?)\s*(?:\/|per\s*)(mo|yr|year|month)/i', $text, $m)) {
        $price = (float)$m[1];
        $unit = strtolower($m[2]);
        $duration = ($unit === 'mo' || $unit === 'month') ? 'per month' : 'per year';
    } elseif (preg_match('/\$\s*([0-9]+(?:\.[0-9]{1,2})?)/', $text, $m)) {
        $price = (float)$m[1];
    }

    $features = [];
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\s*(SSD|Storage)/i', $text, $m)) {
        $features[] = trim($m[1] . ' ' . strtoupper($m[2]) . ' ' . ($m[3] ?: 'Storage'));
    }
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\s*RAM/i', $text, $m)) {
        $features[] = trim($m[1] . ' ' . strtoupper($m[2]) . ' RAM');
    }
    if (preg_match('/([0-9]+)\s*v?CPU/i', $text, $m)) {
        $features[] = $m[1] . ' vCPU Cores';
    }
    if (preg_match('/([0-9,.]+)\s*GB\s*(?:Monthly\s*)?(?:Transfer|Bandwidth)/i', $text, $m)) {
        $features[] = str_replace(',', '', $m[1]) . ' GB Monthly Transfer';
    }
    if (preg_match('/([0-9.]+)\s*Gbps/i', $text, $m)) {
        $features[] = $m[1] . ' Gbps Network Port';
    } elseif (preg_match('/([0-9]+)\s*Mbps/i', $text, $m)) {
        $features[] = $m[1] . ' Mbps Network Port';
    }

    // Title heuristic (prefer RAM size)
    $title = 'CloudCone VPS';
    if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\s*GB\b.*?RAM/i', $text, $m)) {
        $title = $m[1] . 'GB';
    }

    return [
        'title' => $title,
        'price' => $price,
        'duration' => $duration,
        'features' => $features,
    ];
}

/**
 * Try to extract multiple plan candidates from one campaign page.
 * Heuristic: find every price token like "$.. /mo|/yr" and parse features near it.
 */
function parse_cloudcone_html_multi(string $html): array {
    $text = strip_tags($html);
    $text = preg_replace('/[\r\n\t]+/', ' ', $text);
    $text = preg_replace('/\s{2,}/', ' ', $text);

    $plans = [];
    if (!preg_match_all('/\$\s*([0-9]+(?:\.[0-9]{1,2})?)\s*(?:\/|per\s*)(mo|yr|year|month)/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
        // Fallback: single parse
        $single = parse_cloudcone_html($html);
        if ($single) { $plans[] = $single; }
        return $plans;
    }

    $seenTitles = [];
    $len = strlen($text);
    for ($i = 0; $i < count($matches[0]); $i++) {
        $priceStr = $matches[1][$i][0];
        $unitStr = strtolower($matches[2][$i][0]);
        $offset  = (int)$matches[0][$i][1];
        $start = max(0, $offset - 400);
        $end   = min($len, $offset + 600);
        $seg   = substr($text, $start, $end - $start);

        $title = 'CloudCone VPS';
        if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\s*GB\b.*?RAM/i', $seg, $m)) {
            $title = $m[1] . 'GB';
        } elseif (preg_match('/\b([0-9]+)\s*v?CPU/i', $seg, $m)) {
            $title = $m[1] . ' vCPU VPS';
        }

        $features = [];
        if (preg_match('/([0-9]+)\s*v?CPU/i', $seg, $m)) { $features[] = $m[1] . ' vCPU Cores'; }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\s*RAM/i', $seg, $m)) { $features[] = $m[1] . ' ' . strtoupper($m[2]) . ' RAM'; }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\s*(SSD|Storage)/i', $seg, $m)) { $features[] = $m[1] . ' ' . strtoupper($m[2]) . ' ' . ($m[3] ?: 'Storage'); }
        if (preg_match('/([0-9,.]+)\s*GB\s*(?:Monthly\s*)?(?:Transfer|Bandwidth)/i', $seg, $m)) { $features[] = str_replace(',', '', $m[1]) . ' GB Monthly Transfer'; }
        if (preg_match('/([0-9.]+)\s*Gbps/i', $seg, $m)) { $features[] = $m[1] . ' Gbps Network Port'; }
        elseif (preg_match('/([0-9]+)\s*Mbps/i', $seg, $m)) { $features[] = $m[1] . ' Mbps Network Port'; }

        $price = (float)$priceStr;
        $duration = ($unitStr === 'mo' || $unitStr === 'month') ? 'per month' : 'per year';

        if (!isset($seenTitles[$title])) {
            $plans[] = [
                'title' => $title,
                'price' => $price,
                'duration' => $duration,
                'features' => $features,
            ];
            $seenTitles[$title] = true;
        }
    }

    if (!$plans) {
        $single = parse_cloudcone_html($html);
        if ($single) { $plans[] = $single; }
    }
    return $plans;
}

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

    // Fetch campaign page and best-effort parse details
    $parsed = null; $html = '';
    try {
        $html = http_fetch_string($campaignUrl, 12);
        $parsed = parse_cloudcone_html($html);
    } catch (Throwable $e) {
        // Keep going with placeholder if fetch fails
        $parsed = null;
    }

    $isFlash = stripos($campaignUrl, 'token=flash') !== false || stripos($token, 'flash') !== false;
    // Build plan list (multi if possible)
    $parsedList = [];
    if ($html !== '') {
        $parsedList = parse_cloudcone_html_multi($html);
    }
    if (!$parsedList) { $parsedList = [ $parsed ?? [] ]; }
    if (!$parsedList[0]) { $parsedList = [[
        'title' => 'Flash VPS', 'price'=>0.00, 'duration'=>'per year', 'features'=>[
            'Varies by flash sale','KVM virtualization','SSD storage','Gigabit network port','Dedicated IPv4']
    ]]; }

    // Normalize into full plan records
    $plans = [];
    $sort = 0;
    foreach ($parsedList as $pi) {
        $plans[] = [
            'title' => $pi['title'] ?? 'CloudCone VPS',
            'subtitle' => 'KVM VPS',
            'price' => isset($pi['price']) ? (float)$pi['price'] : 0.00,
            'duration' => $pi['duration'] ?? 'per year',
            'order_url' => $campaignUrl,
            'location' => 'Multiple Locations',
            'features' => $pi['features'] ?? [],
            'highlights' => $isFlash ? 'Flash Sale' : '',
            'sort' => $sort++,
        ];
    }

    // Remove existing plans for the same campaign path to avoid duplicates
    $parts = parse_url($campaignUrl);
    $path = $parts['path'] ?? '';
    $prefix = 'https://' . ($parts['host'] ?? 'app.cloudcone.com') . $path;
    $pdo->prepare('DELETE FROM plans WHERE vendor_id = :vid AND order_url LIKE :prefix')->execute([
        ':vid' => $vendorId,
        ':prefix' => $prefix . '%',
    ]);

    $stmt = $pdo->prepare('INSERT INTO plans (vendor_id, title, subtitle, price, price_duration, order_url, location, features, cpu, ram, storage, cpu_cores, ram_mb, storage_gb, highlights, sort_order)
        VALUES (:vendor_id, :title, :subtitle, :price, :duration, :order_url, :location, :features, :cpu, :ram, :storage, :cpu_cores, :ram_mb, :storage_gb, :highlights, :sort_order)');
    foreach ($plans as $plan) {
        // derive specs
        $cpu='';$ram='';$storage='';
        foreach (($plan['features'] ?? []) as $f) {
            if ($cpu === '' && preg_match('/\b(?:[0-9]+(?:\.[0-9]+)?\s*)?v?CPU\b|核心/i', $f)) { $cpu = $f; }
            if ($ram === '' && preg_match('/\b[0-9]+(?:\.[0-9]+)?\s*(?:GB|MB)\b.*(?:RAM|内存)|\bRAM\b|内存/i', $f)) { $ram = $f; }
            if ($storage === '' && preg_match('/(?:NVMe|SSD|HDD|存储|Storage)/i', $f)) { $storage = $f; }
        }
        $cpu_cores = null; $ram_mb = null; $storage_gb = null;
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*v?CPU/i', $cpu, $m)) { $cpu_cores = (float)$m[1]; }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\b/i', $ram, $m)) { $ram_mb = (int)round((float)$m[1] * (strtoupper($m[2])==='GB' ? 1024 : 1)); }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\b/i', $storage, $m)) { $storage_gb = (int)round((float)$m[1] * (strtoupper($m[2])==='TB'?1024:(strtoupper($m[2])==='GB'?1:1/1024))); }
        $stmt->execute([
            ':vendor_id' => $vendorId,
            ':title' => $plan['title'],
            ':subtitle' => $plan['subtitle'],
            ':price' => $plan['price'],
            ':duration' => $plan['duration'],
            ':order_url' => $plan['order_url'],
            ':location' => $plan['location'],
            ':features' => json_encode($plan['features'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':cpu' => $cpu,
            ':ram' => $ram,
            ':storage' => $storage,
            ':cpu_cores' => $cpu_cores,
            ':ram_mb' => $ram_mb,
            ':storage_gb' => $storage_gb,
            ':highlights' => $plan['highlights'],
            ':sort_order' => $plan['sort'],
        ]);
    }

    $pdo->commit();
    echo "CloudCone imported: vendor #$vendorId, created " . count($plans) . " plan(s) for campaign\n";
    echo $campaignUrl . "\n\n";
    foreach ($plans as $p) {
        echo "- " . $p['title'] . ": $" . number_format((float)$p['price'], 2) . ' ' . $p['duration'] . "\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo 'Import failed: ' . $e->getMessage();
}
