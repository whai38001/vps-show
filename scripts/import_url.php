<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ratelimit.php';

// Web requires admin login; CLI usage skips login for automation
if (PHP_SAPI !== 'cli') {
  auth_require_login();
}

db_init_schema();
$pdo = get_pdo();

// Allow CLI usage: php scripts/import_url.php "https://..."
if (PHP_SAPI === 'cli') {
  $arg = $argv[1] ?? '';
  if ($arg === '') {
    fwrite(STDERR, "Usage: php scripts/import_url.php <url> [more_urls...]\n");
    exit(1);
  }
  // Support multiple URLs as args
  $urlsFromArgs = [];
  foreach (array_slice($argv, 1) as $a) {
    $a = trim((string)$a);
    if ($a !== '') { $urlsFromArgs[] = $a; }
  }
  // Bridge into existing flow
  $_GET['urls'] = implode("\n", $urlsFromArgs);
}

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';

if ($url === '') {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><meta charset="utf-8"><title>导入 URL</title>';
  echo '<div style="font:14px/1.6 system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:18px;max-width:740px;margin:0 auto;">';
  echo '<h2>从 URL 导入套餐</h2>';
  echo '<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;">';
  echo '<textarea name="urls" rows="6" placeholder="每行一个链接，可粘贴多个\nhttps://portal.orangevps.com/aff.php?aff=171&pid=271\nhttps://app.cloudcone.com/vps/...\nhttps://my.racknerd.com/...\nhttps://buyvm.net/..." style="flex:1;min-width:420px;padding:8px 10px;"></textarea>';
  echo '<button class="btn" type="submit" style="padding:8px 14px;border:1px solid #4b5563;border-radius:8px;background:#111827;color:#e5e7eb;">批量导入</button>';
  echo '</form>';
  echo '<p style="opacity:.75;margin-top:10px;">目前支持：CloudCone、RackNerd、BuyVM、OrangeVPS（尽力解析）。</p>';
  echo '<p style="opacity:.75;margin-top:10px;">或使用单个链接参数：?url=...</p>';
  echo '</div>';
  exit;
}

if (PHP_SAPI !== 'cli') {
  header('Content-Type: text/plain; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
}

// Simple IP-based rate limit: 10 req/min (skip for CLI)
if (PHP_SAPI !== 'cli') {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  if (!rate_limit_allow('import_url:' . $ip, 10, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo "Rate limit exceeded. Please retry later.\n";
    exit;
  }
}

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

function normalize_text(string $html): string {
  $text = strip_tags($html);
  $text = preg_replace('/[\r\n\t]+/u', ' ', $text);
  $text = preg_replace('/\s{2,}/u', ' ', $text);
  return $text;
}

function parse_prices_multi(string $text): array {
  $plans = [];
  if (preg_match_all('/\$\s*([0-9]+(?:\.[0-9]{1,2})?)\s*(?:\/|per\s*)?(mo|yr|year|month)?/i', $text, $m, PREG_OFFSET_CAPTURE)) {
    $len = strlen($text);
    for ($i = 0; $i < count($m[0]); $i++) {
      $price = (float)$m[1][$i][0];
      $unit = strtolower($m[2][$i][0] ?? '');
      $duration = ($unit === 'mo' || $unit === 'month') ? 'per month' : (($unit==='yr'||$unit==='year')?'per year':'per year');
      $offset = (int)$m[0][$i][1];
      $seg = substr($text, max(0, $offset-400), 1000);
      $title = 'VPS Plan';
      if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\s*GB\b.*?RAM/i', $seg, $mm)) { $title = $mm[1] . 'GB'; }
      elseif (preg_match('/\b([0-9]+)\s*v?CPU/i', $seg, $mm)) { $title = $mm[1] . ' vCPU VPS'; }
      $features = [];
      if (preg_match('/([0-9]+)\s*v?CPU/i', $seg, $mm)) { $features[] = $mm[1] . ' vCPU Cores'; }
      if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\s*RAM/i', $seg, $mm)) { $features[] = $mm[1] . ' ' . strtoupper($mm[2]) . ' RAM'; }
      if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\s*(SSD|Storage)/i', $seg, $mm)) { $features[] = $mm[1] . ' ' . strtoupper($mm[2]) . ' ' . ($mm[3] ?: 'Storage'); }
      if (preg_match('/([0-9,.]+)\s*GB\s*(?:Monthly\s*)?(?:Transfer|Bandwidth)/i', $seg, $mm)) { $features[] = str_replace(',', '', $mm[1]) . ' GB Monthly Transfer'; }
      if (preg_match('/([0-9.]+)\s*Gbps/i', $seg, $mm)) { $features[] = $mm[1] . ' Gbps Network Port'; }
      elseif (preg_match('/([0-9]+)\s*Mbps/i', $seg, $mm)) { $features[] = $mm[1] . ' Mbps Network Port'; }
      $plans[] = [
        'title' => $title,
        'price' => $price,
        'duration' => $duration,
        'features' => $features,
      ];
    }
  }
  return $plans;
}

// Support batch list or single url
$urls = [];
if (isset($_GET['urls']) && trim((string)$_GET['urls']) !== '') {
  $urls = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$_GET['urls']))));
} elseif ($url !== '') {
  $urls = [$url];
}

$results = [];
foreach ($urls as $urlItem) {
  $parts = parse_url($urlItem);
  $host = strtolower($parts['host'] ?? '');
  $website = ($parts['scheme'] ?? 'https') . '://' . $host;

  $vendorName = $host;
  $logo = '';
  $desc = 'Imported via URL';
  $highlights = '';
  if (strpos($host, 'cloudcone') !== false) { $vendorName = 'CloudCone'; $logo = 'https://www.cloudcone.com/wp-content/uploads/2018/05/cloudcone-logo.png'; $desc = 'CloudCone campaign'; if (strpos($urlItem, 'token=flash')!==false) $highlights='Flash Sale'; }
  if (strpos($host, 'racknerd') !== false)  { $vendorName = 'RackNerd'; $logo = 'https://www.racknerd.com/assets/images/logo.png'; $desc = 'RackNerd promotion'; }
  if (strpos($host, 'buyvm') !== false)     { $vendorName = 'BuyVM'; $logo = 'https://buyvm.net/assets/images/logo.png'; $desc = 'BuyVM promotion'; }
  if (strpos($host, 'orangevps') !== false) { $vendorName = 'OrangeVPS'; $logo = 'https://portal.orangevps.com/templates/six/img/logo.png'; $desc = 'OrangeVPS promotion'; }

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

  // Fetch and parse
  $html = '';
  $parsedPlans = [];
  try {
    $html = http_fetch_string($urlItem, 12);
    $text = normalize_text($html);

    if (strpos($host, 'cloudcone') !== false) {
      $parsedPlans = parse_prices_multi($text);
    } elseif (strpos($host, 'racknerd') !== false) {
      $parsedPlans = parse_prices_multi($text);
    } elseif (strpos($host, 'buyvm') !== false) {
      $parsedPlans = parse_prices_multi($text);
    } else {
      $parsedPlans = parse_prices_multi($text);
    }
  } catch (Throwable $e) {
    $parsedPlans = [];
  }

  if (!$parsedPlans) {
    $parsedPlans = [[
      'title' => $vendorName . ' VPS',
      'price' => 0.00,
      'duration' => 'per month',
      'features' => ['Imported placeholder']
    ]];
  }

  // De-duplicate by exact URL
  $pdo->prepare('DELETE FROM plans WHERE vendor_id = :vid AND order_url = :url')->execute([
    ':vid' => $vendorId,
    ':url' => $urlItem,
  ]);

$stmt = $pdo->prepare('INSERT INTO plans (vendor_id, title, subtitle, price, price_duration, order_url, location, features, cpu, ram, storage, cpu_cores, ram_mb, storage_gb, highlights, sort_order)
    VALUES (:vendor_id, :title, :subtitle, :price, :duration, :order_url, :location, :features, :cpu, :ram, :storage, :cpu_cores, :ram_mb, :storage_gb, :highlights, :sort_order)');
  $sort = 0; $count = 0;
  foreach ($parsedPlans as $p) {
    // derive specs
    $cpu='';$ram='';$storage='';
    foreach (($p['features'] ?? []) as $f) {
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
      ':title' => (string)($p['title'] ?? ($vendorName . ' VPS')),
      ':subtitle' => 'KVM VPS',
      ':price' => (float)($p['price'] ?? 0),
      ':duration' => (string)($p['duration'] ?? 'per month'),
      ':order_url' => $urlItem,
      ':location' => 'Multiple Locations',
      ':features' => json_encode($p['features'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      ':cpu' => $cpu,
      ':ram' => $ram,
      ':storage' => $storage,
      ':cpu_cores' => $cpu_cores,
      ':ram_mb' => $ram_mb,
      ':storage_gb' => $storage_gb,
      ':highlights' => $highlights,
      ':sort_order' => $sort++,
    ]);
    $count++;
  }

  $pdo->commit();
  $results[] = [
    'vendorId' => $vendorId,
    'vendorName' => $vendorName,
    'url' => $urlItem,
    'count' => $count,
    'plans' => $parsedPlans,
    'ok' => true,
  ];
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  $results[] = [
    'vendorId' => null,
    'vendorName' => $vendorName,
    'url' => $urlItem,
    'count' => 0,
    'plans' => [],
    'ok' => false,
    'error' => $e->getMessage(),
  ];
}
}

// Render result summary
echo 'Batch import completed. ' . count($results) . " URL(s) processed\n\n";
foreach ($results as $r) {
  $ok = $r['ok'] ?? true;
  if (!$ok) {
    echo '[ERROR] ' . ($r['vendorName'] ?? 'unknown') . ' - ' . ($r['url'] ?? '') . ': ' . ($r['error'] ?? 'unknown error') . "\n";
    continue;
  }
  echo 'Vendor #' . $r['vendorId'] . ' (' . $r['vendorName'] . '): created ' . $r['count'] . " plan(s)\n";
  echo $r['url'] . "\n";
  foreach ($r['plans'] as $p) {
    $price = number_format((float)($p['price'] ?? 0), 2);
    $dur = $p['duration'] ?? '';
    $title = $p['title'] ?? 'VPS Plan';
    echo '- ' . $title . ': $' . $price . ' ' . $dur . "\n";
  }
  echo "\n";
}
