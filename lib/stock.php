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
    $changes = [];
    $processed = 0;
    foreach ($items as $it) {
        if ($limit > 0 && $processed >= $limit) { break; }
        if (!is_array($it)) { $skipped++; continue; }
        $keyVal = isset($it[$matchOn]) ? (string)$it[$matchOn] : '';
        if ($keyVal === '') { $skipped++; continue; }
        $statusVal = isset($it[$statusField]) ? (string)$it[$statusField] : '';
        $stock = 'unknown';
        if ($statusVal !== '') {
            if (strcasecmp($statusVal, $inVal) === 0) { $stock = 'in'; }
            elseif (strcasecmp($statusVal, $outVal) === 0) { $stock = 'out'; }
            else { $unknown++; }
        } else { $unknown++; }

        if ($matchOn === 'url') {
            if ($dryRun) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM plans WHERE order_url=:k');
                $stmt->execute([':k'=>$keyVal]);
                $updated += (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare('UPDATE plans SET stock_status=:s, stock_checked_at=:t WHERE order_url=:k');
                $stmt->execute([':s'=>$stock, ':t'=>$now, ':k'=>$keyVal]);
                $updated += $stmt->rowCount();
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
