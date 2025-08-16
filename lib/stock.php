<?php
require_once __DIR__ . '/db.php';

/**
 * Run stock synchronization based on settings stored in DB.
 * Returns: ['code'=>0|400|500, 'message'=>string, 'data'=>['updated'=>int,'unknown'=>int,'skipped'=>int]]
 */
function stock_sync_run(PDO $pdo, array $opts = []): array {
    $t0 = microtime(true);
    $dryRun = !empty($opts['dry_run']);
    $limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;
    $endpoint = db_get_setting('stock_endpoint', '');
    $method = strtoupper(db_get_setting('stock_method', 'GET'));
    $auth = db_get_setting('stock_auth_header', '');
    $query = db_get_setting('stock_query', '');
    $mapJson = db_get_setting('stock_map', '{"match_on":"url","status_field":"status","in":"In Stock","out":"Out of Stock"}');
    $map = json_decode($mapJson, true);
    $webhookEnabled = (int)db_get_setting('stock_webhook_enabled', '0') ? true : false;
    $webhookUrl = db_get_setting('stock_webhook_url', '');
    $webhookAuth = db_get_setting('stock_webhook_auth_header', '');
    if (!$endpoint || !is_array($map)) {
        return ['code'=>400,'message'=>'stock settings missing or invalid','data'=>['updated'=>0,'unknown'=>0,'skipped'=>0]];
    }

    // Build headers
    $headers = [];
    if ($method === 'POST' && $query !== '') { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    if ($auth) {
        $auth = str_replace(["\r\n", "\r"], "\n", $auth);
        $authLines = explode("\n", $auth);
        foreach ($authLines as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            if (preg_match('/^[A-Za-z0-9-]+\s*:/', $line)) { $headers[] = $line; }
            else { $headers[] = 'Authorization: ' . $line; }
        }
    }

    // Build URL/content
    $url = $endpoint; $content = null;
    if ($method === 'GET' && $query) { $url .= (strpos($url, '?') === false ? '?' : '&') . $query; }
    elseif ($method === 'POST' && $query) { $content = $query; }

    // Request
    $resp = null; $httpCode = 0; $statusLine = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'VPS-Deals/1.0 (+stock-sync)');
        if ($method === 'POST' && $content !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $content); }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            if (stripos($err, 'SSL') !== false || stripos($err, 'certificate') !== false) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                $resp = curl_exec($ch);
            }
            if ($resp === false) { curl_close($ch); return ['code'=>500,'message'=>'request failed: '.$err,'data'=>['updated'=>0,'unknown'=>0,'skipped'=>0]]; }
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 400) {
            return ['code'=>500,'message'=>'http error '.$httpCode.': '.substr((string)$resp,0,200),'data'=>['updated'=>0,'unknown'=>0,'skipped'=>0]];
        }
    } else {
        $opts = [ 'http' => [ 'method'=>$method, 'header'=>implode("\r\n", $headers).(count($headers)?"\r\n":""), 'timeout'=>20, 'ignore_errors'=>true ] ];
        if ($method === 'POST' && $content !== null) { $opts['http']['content'] = $content; }
        $ctx = stream_context_create($opts);
        $resp = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0])) { $statusLine = (string)$http_response_header[0]; if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) { $httpCode = (int)$m[1]; } }
        if ($resp === false) { $err = error_get_last(); return ['code'=>500,'message'=>'request failed: '.($statusLine?:($err['message']??'unknown')),'data'=>['updated'=>0,'unknown'=>0,'skipped'=>0]]; }
        if ($httpCode >= 400) { return ['code'=>500,'message'=>'http error '.$httpCode.': '.substr((string)$resp,0,200),'data'=>['updated'=>0,'unknown'=>0,'skipped'=>0]]; }
    }

    $json = json_decode((string)$resp, true);
    if (!is_array($json)) { return ['code'=>500,'message'=>'invalid json: '.substr((string)$resp,0,200),'data'=>['updated'=>0,'unknown'=>0,'skipped'=>0]]; }

    $items = [];
    if (isset($json['data']['items']) && is_array($json['data']['items'])) { $items = $json['data']['items']; }
    elseif (isset($json['items']) && is_array($json['items'])) { $items = $json['items']; }
    elseif (is_array($json) && array_keys($json) === range(0, count($json)-1)) { $items = $json; }
    if (!$items) { return ['code'=>400,'message'=>'no items in response','data'=>['updated'=>0,'unknown'=>0,'skipped'=>0]]; }

    $matchOn = (string)($map['match_on'] ?? 'url');
    $statusField = (string)($map['status_field'] ?? 'status');
    $inVal = (string)($map['in'] ?? 'In Stock');
    $outVal = (string)($map['out'] ?? 'Out of Stock');

    $updated = 0; $unknown = 0; $skipped = 0; $now = date('Y-m-d H:i:s');
    // Matching safeguards: require same vendor host for non-exact matches by default
    $requireVendorHostForFallback = (int)db_get_setting('stock_match_require_vendor', '1') ? true : false;
    $changes = [];
    $processed = 0;
    // Helpers
    $arrayGetByPath = function(array $arr, string $path) {
        if ($path === '') { return null; }
        if (array_key_exists($path, $arr)) { return $arr[$path]; }
        $segments = explode('.', $path);
        $node = $arr;
        foreach ($segments as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) { return null; }
            $node = $node[$seg];
        }
        return $node;
    };
    $normalizeStock = function($raw, string $inLabel, string $outLabel) {
        // Exact label match (case-insensitive)
        if (is_string($raw)) {
            $val = trim($raw);
            if ($val !== '') {
                if (strcasecmp($val, $inLabel) === 0) { return 'in'; }
                if (strcasecmp($val, $outLabel) === 0) { return 'out'; }
            }
            $lc = strtolower($val);
            // Heuristics & synonyms
            $truthy = ['in','available','in stock','instock','yes','true','1','有货','在售','现货','up','online','running','active'];
            $falsy  = ['out','unavailable','out of stock','sold out','no','false','0','无货','缺货','down','offline','stopped','inactive'];
            if (in_array($lc, $truthy, true)) { return 'in'; }
            if (in_array($lc, $falsy, true)) { return 'out'; }
        } elseif (is_bool($raw)) {
            return $raw ? 'in' : 'out';
        } elseif (is_int($raw) || is_float($raw)) {
            return ((float)$raw) > 0 ? 'in' : 'out';
        }
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
            // Remove noisy params
            $filtered = [];
            foreach ($params as $k => $v) {
                $kl = strtolower((string)$k);
                if ($kl === 'currency' || $kl === 'lang' || $kl === 'locale' || $kl === 'utm_source' || $kl === 'utm_medium' || $kl === 'utm_campaign' || $kl === 'ref' || $kl === 'refid' || $kl === 'aff' || $kl === 'affid') {
                    continue;
                }
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

    // Helper: robust URL-to-plan matching with precedence
    $findPlanRowsForUrl = function(PDO $pdo, string $keyVal, array $it) use ($buildUrlCandidates, $requireVendorHostForFallback) {
        $rows = [];
        $cands = $buildUrlCandidates($keyVal);
        $likeHostPath = null; $pid = null;
        $parts = @parse_url($keyVal);
        $keyHost = '';
        if (is_array($parts)) {
            $host = isset($parts['host']) ? strtolower($parts['host']) : '';
            $keyHost = $host;
            $path = isset($parts['path']) ? $parts['path'] : '';
            if ($host || $path) { $likeHostPath = '%' . $host . $path . '%'; }
            if (isset($parts['query'])) { parse_str($parts['query'], $q); if (isset($q['pid'])) { $pid = (string)$q['pid']; } }
        }
        $hostsEqual = function(string $a, string $b): bool {
            $a = strtolower(preg_replace('/^www\./i', '', trim($a)) ?: '');
            $b = strtolower(preg_replace('/^www\./i', '', trim($b)) ?: '');
            return $a !== '' && $a === $b;
        };
        // 1) Exact URL match (normalized variants) — no vendor check needed
        if (!empty($cands)) {
            $conds = []; $params = []; $i = 0;
            foreach ($cands as $c) { $i++; $conds[] = 'p.order_url = :u'.$i; $params[':u'.$i] = $c; }
            $sql = 'SELECT p.id, p.title, p.order_url, p.stock_status, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE ' . implode(' OR ', $conds);
            $sel = $pdo->prepare($sql); $sel->execute($params); $rows = $sel->fetchAll();
            if ($rows) { return $rows; }
        }
        // 2) Host+path AND pid when pid exists
        if ($likeHostPath && $pid !== null && $pid !== '') {
            $sel = $pdo->prepare('SELECT p.id, p.title, p.order_url, p.stock_status, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE p.order_url LIKE :likehp AND p.order_url LIKE :likepid');
            $sel->execute([':likehp' => $likeHostPath, ':likepid' => '%pid=' . $pid . '%']);
            $rows = array_values(array_filter($sel->fetchAll(), function($r) use ($requireVendorHostForFallback, $hostsEqual, $keyHost) {
                if (!$requireVendorHostForFallback) { return true; }
                $vw = (string)($r['vendor_website'] ?? '');
                $vh = parse_url($vw, PHP_URL_HOST) ?: '';
                return $hostsEqual($keyHost, (string)$vh);
            }));
            if ($rows) { return $rows; }
        }
        // 3) Host+path only
        if ($likeHostPath) {
            $sel = $pdo->prepare('SELECT p.id, p.title, p.order_url, p.stock_status, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE p.order_url LIKE :likehp');
            $sel->execute([':likehp' => $likeHostPath]);
            $rows = array_values(array_filter($sel->fetchAll(), function($r) use ($requireVendorHostForFallback, $hostsEqual, $keyHost) {
                if (!$requireVendorHostForFallback) { return true; }
                $vw = (string)($r['vendor_website'] ?? '');
                $vh = parse_url($vw, PHP_URL_HOST) ?: '';
                return $hostsEqual($keyHost, (string)$vh);
            }));
            if ($rows) { return $rows; }
        }
        // 4) Title fallback
        $titleKey = (string)($it['title'] ?? ($it['name'] ?? ''));
        if ($titleKey !== '') {
            $sel = $pdo->prepare('SELECT p.id, p.title, p.order_url, p.stock_status, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE p.title = :k');
            $sel->execute([':k' => $titleKey]);
            $rows = array_values(array_filter($sel->fetchAll(), function($r) use ($requireVendorHostForFallback, $hostsEqual, $keyHost) {
                if (!$requireVendorHostForFallback) { return true; }
                $vw = (string)($r['vendor_website'] ?? '');
                $vh = parse_url($vw, PHP_URL_HOST) ?: '';
                return $hostsEqual($keyHost, (string)$vh);
            }));
            if ($rows) { return $rows; }
        }
        return [];
    };

    foreach ($items as $it) {
        if ($limit > 0 && $processed >= $limit) { break; }
        if (!is_array($it)) { $skipped++; continue; }
        // Resolve key by configured path; fallback to common url fields
        $keyRaw = $arrayGetByPath(is_array($it)?$it:[], $matchOn);
        $keyVal = is_scalar($keyRaw) ? (string)$keyRaw : '';
        if ($keyVal === '' && $matchOn === 'url') {
            foreach (['url','order_url','href','link'] as $alt) {
                if (isset($it[$alt]) && is_scalar($it[$alt]) && (string)$it[$alt] !== '') { $keyVal = (string)$it[$alt]; break; }
            }
        }
        if ($keyVal === '') { $skipped++; continue; }
        // Read status via dot-path, allow non-string values
        $statusRaw = $arrayGetByPath(is_array($it)?$it:[], $statusField);
        $stock = $normalizeStock($statusRaw, $inVal, $outVal);
        if ($stock === 'unknown') { $unknown++; }

        if ($matchOn === 'url') {
            if ($dryRun) {
                $rows = $findPlanRowsForUrl($pdo, $keyVal, $it);
                $updated += count($rows);
            } else {
                $rows = $findPlanRowsForUrl($pdo, $keyVal, $it);
                $aff = 0;
                foreach ($rows as $row) {
                    $prev = (string)($row['stock_status'] ?? '');
                    if ($prev !== $stock) {
                        $upd = $pdo->prepare('UPDATE plans SET stock_status=:s, stock_checked_at=:t WHERE id=:id');
                        $upd->execute([':s'=>$stock, ':t'=>$now, ':id'=>(int)$row['id']]);
                        $aff += $upd->rowCount();
                        if ($upd->rowCount() > 0) {
                            $changes[] = [
                                'plan_id' => (int)$row['id'],
                                'title' => (string)$row['title'],
                                'order_url' => (string)$row['order_url'],
                                'prev' => $prev ?: 'unknown',
                                'curr' => $stock,
                                'checked_at' => $now,
                            ];
                        }
                    }
                }
                $updated += $aff;
            }
        } elseif ($matchOn === 'name' || $matchOn === 'title') {
            if ($dryRun) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM plans WHERE title=:k');
                $stmt->execute([':k'=>$keyVal]);
                $updated += (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare('UPDATE plans SET stock_status=:s, stock_checked_at=:t WHERE title=:k');
                $stmt->execute([':s'=>$stock, ':t'=>$now, ':k'=>$keyVal]);
                $updated += $stmt->rowCount();
            }
        } else {
            if ($dryRun) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM plans WHERE order_url=:k');
                $stmt->execute([':k'=>$keyVal]);
                $cnt = (int)$stmt->fetchColumn();
                if ($cnt === 0) {
                    $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM plans WHERE title=:k');
                    $stmt2->execute([':k'=>$keyVal]);
                    $cnt = (int)$stmt2->fetchColumn();
                }
                $updated += $cnt;
            } else {
                $sel = $pdo->prepare('SELECT id,title,order_url,stock_status FROM plans WHERE order_url=:k');
                $sel->execute([':k'=>$keyVal]);
                $rows = $sel->fetchAll();
                if (!$rows) {
                    $sel = $pdo->prepare('SELECT id,title,order_url,stock_status FROM plans WHERE title=:k');
                    $sel->execute([':k'=>$keyVal]);
                    $rows = $sel->fetchAll();
                }
                foreach ($rows as $row) {
                    $prev = (string)($row['stock_status'] ?? '');
                    if ($prev !== $stock) {
                        $upd = $pdo->prepare('UPDATE plans SET stock_status=:s, stock_checked_at=:t WHERE id=:id');
                        $upd->execute([':s'=>$stock, ':t'=>$now, ':id'=>(int)$row['id']]);
                        $updated += $upd->rowCount();
                        if ($upd->rowCount() > 0) {
                            $changes[] = [
                                'plan_id' => (int)$row['id'],
                                'title' => (string)$row['title'],
                                'order_url' => (string)$row['order_url'],
                                'prev' => $prev ?: 'unknown',
                                'curr' => $stock,
                                'checked_at' => $now,
                            ];
                        }
                    }
                }
            }
        }
        $processed++;
    }
    $durationMs = (int)round((microtime(true) - $t0) * 1000);
    // persist log
    try {
        $stmt = $pdo->prepare('INSERT INTO stock_logs (code,updated,unknown,skipped,duration_ms,message) VALUES (:c,:u,:un,:s,:d,:m)');
        $stmt->execute([
            ':c' => 0,
            ':u' => $updated,
            ':un'=> $unknown,
            ':s' => $skipped,
            ':d' => $durationMs,
            ':m' => $dryRun ? 'dry-run' : 'ok',
        ]);
    } catch (Throwable $e) { @error_log('[stock] log insert failed: '.$e->getMessage()); }
    // webhook notify
    if ($webhookEnabled && !$dryRun && $webhookUrl && !empty($changes)) {
        $payload = json_encode(['events' => $changes], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (function_exists('curl_init')) {
            $ch = curl_init();
            $hdrs = ['Content-Type: application/json'];
            if ($webhookAuth) {
                $authLines = preg_split('/\r?\n/', $webhookAuth);
                foreach ($authLines as $line) { $line = trim($line); if ($line !== '') { $hdrs[] = $line; } }
            }
            curl_setopt_array($ch, [
                CURLOPT_URL => $webhookUrl,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $hdrs,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $respW = curl_exec($ch);
            if ($respW === false) { @error_log('[stock] webhook failed: '.curl_error($ch)); }
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" . ($webhookAuth ? ($webhookAuth."\r\n") : ''),
                'content' => $payload,
                'timeout' => 10,
            ]]);
            @file_get_contents($webhookUrl, false, $ctx);
        }
    }

    return ['code'=>0,'message'=>$dryRun ? 'dry-run' : 'ok','data'=>['updated'=>$updated,'unknown'=>$unknown,'skipped'=>$skipped,'duration_ms'=>$durationMs]];
}
