<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

// Require admin login to avoid exposing debug info publicly
auth_require_login();

header('Content-Type: text/plain; charset=utf-8');

function http_request(string $url, string $method, array $headers = [], ?string $content = null, int $timeout = 20): array {
    $resp = null; $httpCode = 0; $statusLine = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'VPS-Deals/1.0 (+stock-debug)');
        if ($method === 'POST' && $content !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $content); }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            if (stripos($err, 'SSL') !== false || stripos($err, 'certificate') !== false) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                $resp = curl_exec($ch);
            }
            if ($resp === false) { $statusLine = $err; }
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $opts = [ 'http' => [ 'method'=>$method, 'header'=>implode("\r\n", $headers).(count($headers)?"\r\n":""), 'timeout'=>$timeout, 'ignore_errors'=>true ] ];
        if ($method === 'POST' && $content !== null) { $opts['http']['content'] = $content; }
        $ctx = stream_context_create($opts);
        $resp = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0])) { $statusLine = (string)$http_response_header[0]; if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) { $httpCode = (int)$m[1]; } }
    }
    return [$httpCode, $statusLine, $resp];
}

$pdo = get_pdo();

$endpoint = db_get_setting('stock_endpoint', '');
$method = strtoupper(db_get_setting('stock_method', 'GET'));
$auth = db_get_setting('stock_auth_header', '');
$query = db_get_setting('stock_query', '');
$mapJson = db_get_setting('stock_map', '{"match_on":"url","status_field":"status","in":"In Stock","out":"Out of Stock"}');
$map = json_decode($mapJson, true) ?: [];

$matchOn = (string)($map['match_on'] ?? 'url');
$statusField = (string)($map['status_field'] ?? 'status');
$inLabel = (string)($map['in'] ?? 'In Stock');
$outLabel = (string)($map['out'] ?? 'Out of Stock');

$targetUrl = isset($_GET['url']) ? (string)$_GET['url'] : '';
if ($targetUrl === '') {
    echo "Usage: /scripts/stock_debug.php?url=<order_url>\n";
    exit;
}

$headers = [];
if ($method === 'POST' && $query !== '') { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
if ($auth) {
    $auth = str_replace(["\r\n", "\r"], "\n", $auth);
    $authLines = explode("\n", $auth);
    foreach ($authLines as $line) { $line = trim($line); if ($line === '') { continue; } if (preg_match('/^[A-Za-z0-9-]+\s*:/', $line)) { $headers[] = $line; } else { $headers[] = 'Authorization: ' . $line; } }
}

$url = $endpoint;
if ($method === 'GET' && $query) { $url .= (strpos($url, '?') === false ? '?' : '&') . $query; }

list($code, $statusLine, $resp) = http_request($url, $method, $headers, $method==='POST' ? $query : null);

echo "Request: $method $url\n";
if ($headers) { echo "Headers:\n" . implode("\n", $headers) . "\n"; }
if ($method === 'POST' && $query) { echo "Body: $query\n"; }

echo "HTTP: $code $statusLine\n";
if ($code >= 400) {
    echo "Response (first 400):\n" . substr((string)$resp, 0, 400) . "\n";
    exit;
}

$json = json_decode((string)$resp, true);
if (!is_array($json)) {
    echo "Invalid JSON (first 400):\n" . substr((string)$resp, 0, 400) . "\n";
    exit;
}

$items = [];
if (isset($json['data']['items']) && is_array($json['data']['items'])) { $items = $json['data']['items']; }
elseif (isset($json['items']) && is_array($json['items'])) { $items = $json['items']; }
elseif (is_array($json) && array_keys($json) === range(0, count($json)-1)) { $items = $json; }

$arrayGetByPath = function(array $arr, string $path) {
    if ($path === '') { return null; }
    if (array_key_exists($path, $arr)) { return $arr[$path]; }
    $segments = explode('.', $path);
    $node = $arr;
    foreach ($segments as $seg) { if (!is_array($node) || !array_key_exists($seg, $node)) { return null; } $node = $node[$seg]; }
    return $node;
};

$normalizeStock = function($raw, string $inL, string $outL) {
    if (is_string($raw)) {
        $val = trim($raw);
        if ($val !== '') { if (strcasecmp($val, $inL) === 0) { return 'in'; } if (strcasecmp($val, $outL) === 0) { return 'out'; } }
        $lc = strtolower($val);
        $truthy = ['in','available','in stock','instock','yes','true','1','有货','在售','现货','up','online','running','active'];
        $falsy  = ['out','unavailable','out of stock','sold out','no','false','0','无货','缺货','down','offline','stopped','inactive'];
        if (in_array($lc, $truthy, true)) { return 'in'; }
        if (in_array($lc, $falsy, true)) { return 'out'; }
    } elseif (is_bool($raw)) { return $raw ? 'in' : 'out'; }
    elseif (is_int($raw) || is_float($raw)) { return ((float)$raw) > 0 ? 'in' : 'out'; }
    return 'unknown';
};

$buildUrlCandidates = function(string $url) {
    $candidates = [];
    $url = trim($url);
    if ($url === '') { return $candidates; }
    $candidates[] = $url;
    $parts = @parse_url($url);
    if (is_array($parts)) {
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        $query = isset($parts['query']) ? $parts['query'] : '';
        parse_str($query, $params);
        $filtered = [];
        foreach ($params as $k => $v) {
            $kl = strtolower((string)$k);
            if (in_array($kl, ['currency','lang','locale','utm_source','utm_medium','utm_campaign','ref','refid','aff','affid'], true)) { continue; }
            $filtered[$k] = $v;
        }
        ksort($filtered);
        $normQuery = http_build_query($filtered);
        $base = $scheme . '://' . $host . $path;
        $norm = $normQuery !== '' ? ($base . '?' . $normQuery) : $base;
        if (!in_array($norm, $candidates, true)) { $candidates[] = $norm; }
        if (!in_array($base, $candidates, true)) { $candidates[] = $base; }
    }
    return $candidates;
};

echo "Settings:\nmatch_on=$matchOn\nstatus_field=$statusField\nin=$inLabel\nout=$outLabel\n\n";

echo "Target URL: $targetUrl\n\n";
$targetCands = $buildUrlCandidates($targetUrl);
echo "Target candidates:\n" . implode("\n", $targetCands) . "\n\n";

if (!$items) {
    echo "No items parsed from API.\n";
    exit;
}

$found = [];
foreach ($items as $i => $it) {
    if (!is_array($it)) { continue; }
    $keyRaw = $arrayGetByPath($it, $matchOn);
    $keyVal = is_scalar($keyRaw) ? (string)$keyRaw : '';
    if ($keyVal === '' && $matchOn === 'url') {
        foreach (['url','order_url','href','link'] as $alt) { if (isset($it[$alt]) && is_scalar($it[$alt]) && (string)$it[$alt] !== '') { $keyVal = (string)$it[$alt]; break; } }
    }
    if ($keyVal === '') { continue; }
    $itemCands = $buildUrlCandidates($keyVal);
    $hit = false;
    foreach ($itemCands as $c1) {
        foreach ($targetCands as $c2) { if ($c1 === $c2) { $hit = true; break 2; } }
    }
    if (!$hit) {
        $p1 = @parse_url($keyVal); $p2 = @parse_url($targetUrl);
        if (is_array($p1) && is_array($p2)) {
            $hp1 = strtolower(($p1['host'] ?? '') . ($p1['path'] ?? ''));
            $hp2 = strtolower(($p2['host'] ?? '') . ($p2['path'] ?? ''));
            if ($hp1 !== '' && $hp1 === $hp2) { $hit = true; }
        }
    }
    if ($hit) {
        $statusRaw = $arrayGetByPath($it, $statusField);
        $stock = $normalizeStock($statusRaw, $inLabel, $outLabel);
        $found[] = [ 'idx'=>$i, 'key'=>$keyVal, 'stock'=>$stock, 'status_raw'=>$statusRaw, 'item'=>$it ];
    }
}

if (!$found) {
    echo "No matching item found for target URL.\n";
    exit;
}

foreach ($found as $f) {
    echo "Matched item index: {$f['idx']}\n";
    echo "Item URL: {$f['key']}\n";
    echo "Parsed stock: {$f['stock']}\n";
    echo "Raw status value: "; var_export($f['status_raw']); echo "\n";
    echo "DB match preview:\n";
    $cands = $buildUrlCandidates($f['key']);
    $likeHostPath = null; $pid = null; $parts = @parse_url($f['key']);
    if (is_array($parts)) {
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        if ($host || $path) { $likeHostPath = '%' . $host . $path . '%'; }
        if (isset($parts['query'])) { parse_str($parts['query'], $q); if (isset($q['pid'])) { $pid = (string)$q['pid']; } }
    }
    $conds = []; $params = []; $i = 0;
    foreach ($cands as $c) { $i++; $conds[] = 'order_url = :u' . $i; $params[':u'.$i] = $c; }
    if ($likeHostPath) { $conds[] = 'order_url LIKE :likehp'; $params[':likehp'] = $likeHostPath; }
    if ($pid !== null && $pid !== '') { $conds[] = 'order_url LIKE :likepid'; $params[':likepid'] = '%pid=' . $pid . '%'; }
    if ($conds) {
        $sqlSel = 'SELECT id, vendor_id, title, price, price_duration, order_url, stock_status FROM plans WHERE ' . implode(' OR ', $conds);
        $sel = $pdo->prepare($sqlSel);
        $sel->execute($params);
        $rows = $sel->fetchAll();
        if ($rows) {
            foreach ($rows as $r) {
                echo ' - Plan id='.(int)$r['id'].' title='.(string)$r['title'].' price='.(float)$r['price'].' duration='.(string)$r['price_duration'].' stock_prev='.(string)($r['stock_status'] ?? '')."\n";
            }
        } else {
            echo " - No DB plan matched by URL heuristics.\n";
        }
    }
    echo "---\n";
}
