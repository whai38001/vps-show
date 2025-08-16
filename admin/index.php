<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/headers.php';
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/stock.php';

db_init_schema();
$pdo = get_pdo();
send_common_security_headers();

$forceLogin = isset($_GET['force_login']);
if ($forceLogin) {
    auth_logout();
}
auth_require_login();

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'vendors';

// Helper: highlight keyword in already-escaped text
function admin_highlight(string $escapedText, string $q): string {
    if ($q === '') { return $escapedText; }
    $pat = '/' . preg_quote($q, '/') . '/i';
    return preg_replace($pat, '<mark>$0</mark>', $escapedText);
}

function redirect_same() { header('Location: ./?'.http_build_query($_GET)); exit; }

// Simple flash message helpers
function flash_set(string $type, string $message): void {
  auth_start_session();
  $_SESSION['flash'] = ['type'=>$type,'message'=>$message];
}
function flash_get(): ?array {
  auth_start_session();
  if (!empty($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
  return null;
}

// Handle POST actions: vendor/plan CRUD and account settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_validate_csrf_token($_POST['_csrf'] ?? null)) {
        http_response_code(400);
        echo '<meta charset="utf-8">CSRF 校验失败，请返回重试。';
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'vendor_save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $logo = trim($_POST['logo_url'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name !== '') {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE vendors SET name=:name, website=:website, logo_url=:logo, description=:desc WHERE id=:id');
                $stmt->execute([':name'=>$name, ':website'=>$website, ':logo'=>$logo, ':desc'=>$desc, ':id'=>$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO vendors (name, website, logo_url, description) VALUES (:name,:website,:logo,:desc)');
                $stmt->execute([':name'=>$name, ':website'=>$website, ':logo'=>$logo, ':desc'=>$desc]);
            }
        }
        flash_set('success', '厂商已保存');
        redirect_same();
    }
    if (isset($_POST['action']) && $_POST['action'] === 'vendor_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM vendors WHERE id=:id')->execute([':id'=>$id]);
        }
        flash_set('success', '厂商已删除');
        redirect_same();
    }
    if (isset($_POST['action']) && $_POST['action'] === 'vendors_bulk_delete') {
        $ids = $_POST['vendor_ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $ids = array_values(array_filter(array_map('intval', $ids), function($x){ return $x>0; }));
            if (!empty($ids)) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare('DELETE FROM vendors WHERE id IN (' . $in . ')');
                $stmt->execute($ids);
                flash_set('success', '批量删除厂商完成：共 ' . count($ids) . ' 条（关联套餐已按外键级联删除）');
                redirect_same();
            }
        }
        flash_set('error', '未选择任何厂商');
        redirect_same();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'plan_save') {
        $id = (int)($_POST['id'] ?? 0);
        $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $price_duration = $_POST['price_duration'] ?? 'per year';
        $details_url = trim($_POST['details_url'] ?? '');
        $order_url = trim($_POST['order_url'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $features_raw = trim($_POST['features'] ?? '');
        $highlights = trim($_POST['highlights'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $features = [];
        $normalize_to_zh = isset($_POST['normalize_to_zh']) && $_POST['normalize_to_zh'] === '1';
        if ($features_raw !== '') {
            $features = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $features_raw))));
        }
        if ($normalize_to_zh) {
            require_once __DIR__ . '/../lib/i18n.php';
            $title = i18n_text_to_zh($title);
            if ($subtitle !== '') { $subtitle = i18n_text_to_zh($subtitle); }
            if ($highlights !== '') { $highlights = i18n_text_to_zh($highlights); }
            if ($location !== '') { $location = i18n_text_to_zh($location); }
            if (!empty($features)) {
                foreach ($features as $i => $feat) { $features[$i] = i18n_text_to_zh($feat); }
            }
        }
        // Normalize structured specs from features if not provided
        $cpu = trim($_POST['cpu'] ?? '');
        $ram = trim($_POST['ram'] ?? '');
        $storage = trim($_POST['storage'] ?? '');
        if ($cpu === '' || $ram === '' || $storage === '') {
            // Best-effort derive from features
            foreach ($features as $feat) {
                if ($cpu === '' && preg_match('/\b(?:[0-9]+(?:\.[0-9]+)?\s*)?v?CPU\b|核心/i', $feat)) { $cpu = $feat; }
                if ($ram === '' && preg_match('/\b[0-9]+(?:\.[0-9]+)?\s*(?:GB|MB)\b.*(?:RAM|内存)|\bRAM\b|内存/i', $feat)) { $ram = $feat; }
                if ($storage === '' && preg_match('/(?:NVMe|SSD|HDD|存储|Storage)/i', $feat)) { $storage = $feat; }
            }
        }

        // Normalize numeric specs for future排序/筛选（可留空）
        $cpu_cores = null; $ram_mb = null; $storage_gb = null;
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*v?CPU/i', $cpu, $m)) { $cpu_cores = (float)$m[1]; }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\b/i', $ram, $m)) {
            $ram_mb = (int)round((float)$m[1] * (strtoupper($m[2])==='GB' ? 1024 : 1));
        }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\b/i', $storage, $m)) {
            $val = (float)$m[1]; $unit = strtoupper($m[2]);
            $storage_gb = (int)round($val * ($unit==='TB'?1024:($unit==='GB'?1:1/1024)));
        }

        if ($vendor_id > 0 && $title !== '' && $price > 0) {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE plans SET vendor_id=:vendor_id,title=:title,subtitle=:subtitle,price=:price,price_duration=:d,details_url=:du,order_url=:url,location=:loc,features=:f,cpu=:cpu,ram=:ram,storage=:storage,cpu_cores=:cpu_cores,ram_mb=:ram_mb,storage_gb=:storage_gb,highlights=:h,sort_order=:s WHERE id=:id');
                $stmt->execute([':vendor_id'=>$vendor_id, ':title'=>$title, ':subtitle'=>$subtitle, ':price'=>$price, ':d'=>$price_duration, ':du'=>$details_url, ':url'=>$order_url, ':loc'=>$location, ':f'=>json_encode($features, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ':cpu'=>$cpu, ':ram'=>$ram, ':storage'=>$storage, ':cpu_cores'=>$cpu_cores, ':ram_mb'=>$ram_mb, ':storage_gb'=>$storage_gb, ':h'=>$highlights, ':s'=>$sort_order, ':id'=>$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO plans (vendor_id,title,subtitle,price,price_duration,details_url,order_url,location,features,cpu,ram,storage,cpu_cores,ram_mb,storage_gb,highlights,sort_order) VALUES (:vendor_id,:title,:subtitle,:price,:d,:du,:url,:loc,:f,:cpu,:ram,:storage,:cpu_cores,:ram_mb,:storage_gb,:h,:s)');
                $stmt->execute([':vendor_id'=>$vendor_id, ':title'=>$title, ':subtitle'=>$subtitle, ':price'=>$price, ':d'=>$price_duration, ':du'=>$details_url, ':url'=>$order_url, ':loc'=>$location, ':f'=>json_encode($features, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ':cpu'=>$cpu, ':ram'=>$ram, ':storage'=>$storage, ':cpu_cores'=>$cpu_cores, ':ram_mb'=>$ram_mb, ':storage_gb'=>$storage_gb, ':h'=>$highlights, ':s'=>$sort_order]);
            }
        }
        flash_set('success', '套餐已保存');
        redirect_same();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'plan_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM plans WHERE id=:id')->execute([':id'=>$id]);
        }
        flash_set('success', '套餐已删除');
        redirect_same();
    }
    if (isset($_POST['action']) && $_POST['action'] === 'plans_bulk') {
        $do = $_POST['do'] ?? '';
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) { flash_set('error', '未选择任何记录'); redirect_same(); }
        $ids = array_values(array_filter(array_map('intval', $ids), function($x){ return $x>0; }));
        if (empty($ids)) { flash_set('error', '未选择任何记录'); redirect_same(); }
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($do === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM plans WHERE id IN (' . $in . ')');
            $stmt->execute($ids);
            flash_set('success', '批量删除完成：共 ' . count($ids) . ' 条');
            redirect_same();
        } elseif ($do === 'sort') {
            $mode = $_POST['sort_mode'] ?? 'set';
            $amt = isset($_POST['sort_amount']) ? (int)$_POST['sort_amount'] : 0;
            if ($mode === 'set') {
                $stmt = $pdo->prepare('UPDATE plans SET sort_order = ? WHERE id IN (' . $in . ')');
                $params = array_merge([$amt], $ids);
                $stmt->execute($params);
                flash_set('success', '批量设置排序完成：' . count($ids) . ' 条，值=' . $amt);
                redirect_same();
            } elseif ($mode === 'inc') {
                // Increase (or decrease if amt为负)
                $stmt = $pdo->prepare('UPDATE plans SET sort_order = sort_order + ? WHERE id IN (' . $in . ')');
                $params = array_merge([$amt], $ids);
                $stmt->execute($params);
                $sign = $amt>=0?('+'.$amt):((string)$amt);
                flash_set('success', '批量调整排序完成：' . count($ids) . ' 条，偏移=' . $sign);
                redirect_same();
            } else {
                flash_set('error', '不支持的排序操作');
                redirect_same();
            }
        } elseif ($do === 'billing') {
            $val = $_POST['billing_value'] ?? '';
            if (!in_array($val, ['per month','per year','one-time'], true)) { flash_set('error','计费周期无效'); redirect_same(); }
            $stmt = $pdo->prepare('UPDATE plans SET price_duration = ? WHERE id IN (' . $in . ')');
            $params = array_merge([$val], $ids);
            $stmt->execute($params);
            flash_set('success','批量修改计费周期完成：' . count($ids) . ' 条');
            redirect_same();
        } elseif ($do === 'stock') {
            $val = $_POST['stock_value'] ?? '';
            if (!in_array($val, ['in','out','unknown'], true)) { flash_set('error','库存状态无效'); redirect_same(); }
            $stmt = $pdo->prepare('UPDATE plans SET stock_status = ? WHERE id IN (' . $in . ')');
            $params = array_merge([$val], $ids);
            $stmt->execute($params);
            flash_set('success','批量修改库存状态完成：' . count($ids) . ' 条');
            redirect_same();
        } else {
            flash_set('error', '未选择有效操作');
            redirect_same();
        }
    }
    if (isset($_POST['action']) && $_POST['action'] === 'plan_duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM plans WHERE id=:id');
            $stmt->execute([':id'=>$id]);
            if ($src = $stmt->fetch()) {
                $newTitle = (string)$src['title'] . ' (复制)';
                $ins = $pdo->prepare('INSERT INTO plans (vendor_id,title,subtitle,price,price_duration,order_url,location,features,highlights,sort_order) VALUES (:vendor_id,:title,:subtitle,:price,:d,:url,:loc,:f,:h,:s)');
                $ins->execute([
                    ':vendor_id' => (int)$src['vendor_id'],
                    ':title' => $newTitle,
                    ':subtitle' => (string)$src['subtitle'],
                    ':price' => (float)$src['price'],
                    ':d' => (string)$src['price_duration'],
                    ':url' => (string)$src['order_url'],
                    ':loc' => (string)$src['location'],
                    ':f' => is_string($src['features']) ? $src['features'] : json_encode($src['features'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    ':h' => (string)$src['highlights'],
                    ':s' => (int)$src['sort_order'],
                ]);
            }
        }
        flash_set('success', '套餐已复制');
        redirect_same();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'account_save') {
        // Validate current password with current effective credentials
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newUsername = trim((string)($_POST['admin_username'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');

        // Build the effective current username/hash (DB settings override env/constants)
        $effectiveUsername = db_get_setting('admin_username', ADMIN_USERNAME);
        $effectiveHash = db_get_setting('admin_password_hash', ADMIN_PASSWORD_HASH);

        $okCurrent = false;
        if ($effectiveHash !== '') {
            $okCurrent = password_verify($currentPassword, $effectiveHash);
        } elseif (ADMIN_PASSWORD_HASH !== '') {
            $okCurrent = password_verify($currentPassword, ADMIN_PASSWORD_HASH);
        } else {
            // Fallback to ADMIN_PASSWORD only if no hashes configured
            $okCurrent = (defined('ADMIN_PASSWORD') && ADMIN_PASSWORD !== '' && hash_equals(ADMIN_PASSWORD, $currentPassword));
        }

        if (!$okCurrent) {
            flash_set('error', '当前密码验证失败');
            redirect_same();
        }

        if ($newUsername === '') {
            $newUsername = $effectiveUsername;
        }
        if ($newPassword !== '' || $newPasswordConfirm !== '') {
            if ($newPassword !== $newPasswordConfirm) {
                flash_set('error', '两次输入的新密码不一致');
                redirect_same();
            }
            // Strong password policy: >= 12 chars, contains upper, lower, digit, special
            $len = strlen($newPassword);
            $hasUpper = (bool)preg_match('/[A-Z]/', $newPassword);
            $hasLower = (bool)preg_match('/[a-z]/', $newPassword);
            $hasDigit = (bool)preg_match('/\d/', $newPassword);
            $hasSpecial = (bool)preg_match('/[^a-zA-Z\d]/', $newPassword);
            if ($len < 12 || !$hasUpper || !$hasLower || !$hasDigit || !$hasSpecial) {
                flash_set('error', '新密码不符合强度要求：至少12位，且包含大写/小写/数字/特殊字符');
                redirect_same();
            }
            // Disallow same as current password
            $sameAsCurrent = false;
            if ($effectiveHash !== '') {
                $sameAsCurrent = password_verify($newPassword, $effectiveHash);
            } elseif (ADMIN_PASSWORD_HASH !== '') {
                $sameAsCurrent = password_verify($newPassword, ADMIN_PASSWORD_HASH);
            } elseif (defined('ADMIN_PASSWORD') && ADMIN_PASSWORD !== '') {
                $sameAsCurrent = hash_equals(ADMIN_PASSWORD, $newPassword);
            }
            if ($sameAsCurrent) {
                flash_set('error', '新密码不能与当前密码相同');
                redirect_same();
            }
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            db_set_setting('admin_password_hash', $newHash);
        }
        db_set_setting('admin_username', $newUsername);

        // Update session username for consistency
        auth_start_session();
        $_SESSION['admin_username'] = $newUsername;

        flash_set('success', '账号设置已更新');
        redirect_same();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'stock_save_settings') {
        // These fields may or may not be present depending on which form submitted
        $hasEndpointForm = array_key_exists('endpoint', $_POST) || array_key_exists('method', $_POST) || array_key_exists('auth_header', $_POST) || array_key_exists('query', $_POST) || array_key_exists('map', $_POST);
        if ($hasEndpointForm) {
            $endpoint = trim((string)($_POST['endpoint'] ?? ''));
            $method = strtoupper(trim((string)($_POST['method'] ?? '')));
            $auth = trim((string)($_POST['auth_header'] ?? ''));
            $query = trim((string)($_POST['query'] ?? ''));
            $map = (string)($_POST['map'] ?? '');
            if ($endpoint !== '') {
                if (!preg_match('/^https?:\/\//i', $endpoint)) {
                    flash_set('error', '接口地址无效');
                    redirect_same();
                }
                db_set_setting('stock_endpoint', $endpoint);
            }
            if ($method !== '') {
                if (!in_array($method, ['GET','POST'], true)) { $method = 'GET'; }
                db_set_setting('stock_method', $method);
            }
            // No validation required for headers/query; they are optional
            if (array_key_exists('auth_header', $_POST)) { db_set_setting('stock_auth_header', $auth); }
            if (array_key_exists('query', $_POST)) { db_set_setting('stock_query', $query); }
            // Map JSON validation only when provided
            if ($map !== '') {
                $mapCheck = json_decode($map, true);
                if (!is_array($mapCheck) || !isset($mapCheck['match_on'], $mapCheck['status_field'])) {
                    flash_set('error', '字段映射需为 JSON，且包含 match_on 与 status_field');
                    redirect_same();
                }
                db_set_setting('stock_map', $map);
            }
        }
        // Auto settings (from 自动同步设置表单)
        if (array_key_exists('auto_enabled', $_POST) || array_key_exists('auto_interval_min', $_POST)) {
            $autoEnabledPost = isset($_POST['auto_enabled']) && $_POST['auto_enabled'] === '1' ? 1 : 0;
            $autoIntervalPost = isset($_POST['auto_interval_min']) ? (int)$_POST['auto_interval_min'] : 15;
            db_set_setting('stock_auto_enabled', (string)$autoEnabledPost);
            db_set_setting('stock_auto_interval_min', (string)max(1, $autoIntervalPost));
        }
        // Cron environment (host vs docker)
        if (array_key_exists('stock_cron_mode', $_POST) || array_key_exists('stock_php_path', $_POST) || array_key_exists('stock_docker_container', $_POST) || array_key_exists('stock_docker_bin', $_POST) || array_key_exists('stock_container_base', $_POST)) {
            $mode = $_POST['stock_cron_mode'] ?? '';
            if (!in_array($mode, ['host','docker'], true)) { $mode = 'host'; }
            db_set_setting('stock_cron_mode', $mode);
            if (array_key_exists('stock_php_path', $_POST)) {
                $phpPath = trim((string)($_POST['stock_php_path'] ?? ''));
                if ($phpPath !== '') { db_set_setting('stock_php_path', $phpPath); }
            }
            if (array_key_exists('stock_docker_container', $_POST)) {
                $container = trim((string)($_POST['stock_docker_container'] ?? ''));
                if ($container !== '') { db_set_setting('stock_docker_container', $container); }
            }
            if (array_key_exists('stock_container_base', $_POST)) {
                $containerBasePost = trim((string)($_POST['stock_container_base'] ?? ''));
                if ($containerBasePost !== '') { db_set_setting('stock_container_base', $containerBasePost); }
            }
            if (array_key_exists('stock_docker_bin', $_POST)) {
                $dockerBinPost = trim((string)($_POST['stock_docker_bin'] ?? ''));
                if ($dockerBinPost !== '') { db_set_setting('stock_docker_bin', $dockerBinPost); }
            }
        }
        // Defaults for manual/cron sync extras (treat missing dry_run_default as 0 when this subform is submitted)
        $isAutoDefaultsForm = array_key_exists('dry_run_default', $_POST) || array_key_exists('limit_default', $_POST) || array_key_exists('auto_enabled', $_POST) || array_key_exists('auto_interval_min', $_POST);
        if ($isAutoDefaultsForm) {
            $dryRunDefault = (isset($_POST['dry_run_default']) && $_POST['dry_run_default'] === '1') ? 1 : 0;
            db_set_setting('stock_dry_run_default', (string)$dryRunDefault);
            if (array_key_exists('limit_default', $_POST)) {
                $limitDefault = isset($_POST['limit_default']) ? max(0, (int)$_POST['limit_default']) : 0;
                db_set_setting('stock_limit_default', (string)$limitDefault);
            }
        }
        flash_set('success', '库存接口配置已保存');
        redirect_same();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'stock_save_webhook') {
        $enabled = isset($_POST['webhook_enabled']) && $_POST['webhook_enabled'] === '1' ? '1' : '0';
        $url = trim((string)($_POST['webhook_url'] ?? ''));
        $auth = (string)($_POST['webhook_auth'] ?? '');
        if ($enabled === '1' && ($url === '' || !preg_match('/^https?:\/\//i', $url))) {
            flash_set('error', 'Webhook URL 无效');
            redirect_same();
        }
        db_set_setting('stock_webhook_enabled', $enabled);
        db_set_setting('stock_webhook_url', $url);
        db_set_setting('stock_webhook_auth_header', $auth);
        flash_set('success', 'Webhook 配置已保存');
        redirect_same();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'stock_sync_now') {
        $isAjax = (isset($_POST['ajax']) && $_POST['ajax'] === '1') || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        $jsonError = function(string $msg) use ($isAjax) {
            if ($isAjax) {
                if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
                echo json_encode(['code'=>400,'message'=>$msg], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                exit;
            } else {
                flash_set('error', $msg);
                redirect_same();
            }
        };
        // Read settings
        $endpoint = db_get_setting('stock_endpoint', '');
        $method = strtoupper(db_get_setting('stock_method', 'GET'));
        $auth = db_get_setting('stock_auth_header', '');
        $query = db_get_setting('stock_query', '');
        $mapJson = db_get_setting('stock_map', '{"match_on":"url","status_field":"status","in":"In Stock","out":"Out of Stock"}');
        $map = json_decode($mapJson, true);
        if (!$endpoint || !is_array($map)) { $jsonError('请先在“库存同步”页保存有效配置'); }
        // Build request
        $headers = [];
        // Only send Content-Type for POST with body
        $sendBody = false;
        if ($method === 'POST' && $query !== '') { $sendBody = true; $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        if ($auth) {
            // Support either full header lines or bare token
            $auth = str_replace(["\r\n", "\r"], "\n", $auth);
            $authLines = explode("\n", $auth);
            foreach ($authLines as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                if (preg_match('/^[A-Za-z0-9-]+\s*:/', $line)) { $headers[] = $line; }
                else { $headers[] = 'Authorization: ' . $line; }
            }
        }
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers) . (count($headers)?"\r\n":""),
                'timeout' => 20,
                'ignore_errors' => true, // capture body on 4xx/5xx
            ],
        ];
        $url = $endpoint;
        if ($method === 'GET' && $query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
        } elseif ($method === 'POST' && $query) {
            $opts['http']['content'] = $query;
        }
        $resp = null; $statusLine = '';
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'VPS-Deals/1.0 (+stock-sync)');
            if ($method === 'POST' && isset($opts['http']['content'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['http']['content']);
            }
            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch);
                // Retry once with relaxed TLS if TLS error suspected
                if (stripos($err, 'SSL') !== false || stripos($err, 'certificate') !== false) {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    $resp = curl_exec($ch);
                }
            if ($resp === false) { $msg = '请求库存接口失败：' . $err; curl_close($ch); $jsonError($msg); }
            }
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode >= 400) { $snippet = substr((string)$resp, 0, 200); curl_close($ch); $jsonError('库存接口返回错误（HTTP ' . $httpCode . '）：' . $snippet); }
            curl_close($ch);
        } else {
            $ctx = stream_context_create($opts);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) { $err = error_get_last(); $statusLine = isset($http_response_header[0]) ? (string)$http_response_header[0] : ''; $msg = '请求库存接口失败' . ($statusLine ? '（'.$statusLine.'）' : '') . (($err && isset($err['message'])) ? ('：'.$err['message']) : ''); $jsonError($msg); }
        }

        $json = json_decode((string)$resp, true);
        if (!is_array($json)) { $snippet = substr((string)$resp, 0, 200); $jsonError('库存接口返回格式异常' . ($statusLine ? '（'.$statusLine.'）' : '') . '：' . $snippet); }
        // Extract items: support {data:{items:[...]}} or {items:[...]} or array
        $items = [];
        if (isset($json['data']['items']) && is_array($json['data']['items'])) { $items = $json['data']['items']; }
        elseif (isset($json['items']) && is_array($json['items'])) { $items = $json['items']; }
        elseif (is_array($json) && array_keys($json) === range(0, count($json)-1)) { $items = $json; }
        if (!$items) { $jsonError('未从接口解析到任何记录'); }
        $matchOn = (string)($map['match_on'] ?? 'url');
        $statusField = (string)($map['status_field'] ?? 'status');
        $inVal = (string)($map['in'] ?? 'In Stock');
        $outVal = (string)($map['out'] ?? 'Out of Stock');

        $updated = 0; $unknown = 0; $skipped = 0;
        $requireVendorHostForFallback = (int)db_get_setting('stock_match_require_vendor', '1') ? true : false;
        $dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';
        $limit = isset($_POST['limit']) ? max(0, (int)$_POST['limit']) : 0;
        $now = date('Y-m-d H:i:s');
        $processed = 0;
        // Helpers for dot-path access and normalization
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
            if (is_string($raw)) {
                $val = trim($raw);
                if ($val !== '') {
                    if (strcasecmp($val, $inLabel) === 0) { return 'in'; }
                    if (strcasecmp($val, $outLabel) === 0) { return 'out'; }
                }
                $lc = strtolower($val);
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

        foreach ($items as $it) {
            if ($limit > 0 && $processed >= $limit) { break; }
            if (!is_array($it)) { $skipped++; continue; }
            $keyRaw = $arrayGetByPath(is_array($it)?$it:[], $matchOn);
            $keyVal = is_scalar($keyRaw) ? (string)$keyRaw : '';
            if ($keyVal === '' && $matchOn === 'url') {
                foreach (['url','order_url','href','link'] as $alt) {
                    if (isset($it[$alt]) && is_scalar($it[$alt]) && (string)$it[$alt] !== '') { $keyVal = (string)$it[$alt]; break; }
                }
            }
            if ($keyVal === '') { $skipped++; continue; }
            $statusRaw = $arrayGetByPath(is_array($it)?$it:[], $statusField);
            $stock = $normalizeStock($statusRaw, $inVal, $outVal);
            if ($stock === 'unknown') { $unknown++; }
            // Match plan by order_url (with normalization/heuristics); if not found and title/name present, fallback to title
            if ($matchOn === 'url') {
                if ($dryRun) {
                    $cands = $buildUrlCandidates($keyVal);
                    $likeHostPath = null; $pid = null;
                    $parts = @parse_url($keyVal);
                    if (is_array($parts)) {
                        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
                        $path = isset($parts['path']) ? $parts['path'] : '';
                        if ($host || $path) { $likeHostPath = '%' . $host . $path . '%'; }
                        if (isset($parts['query'])) { parse_str($parts['query'], $q); if (isset($q['pid'])) { $pid = (string)$q['pid']; } }
                    }
                    $conds = [];
                    $paramsSel = [];
                    $i = 0;
                    foreach ($cands as $c) { $i++; $conds[] = 'p.order_url = :u' . $i; $paramsSel[':u'.$i] = $c; }
                    $sqlSel = 'SELECT p.id, p.order_url, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE ' . implode(' OR ', $conds);
                    $stmt = $pdo->prepare($sqlSel);
                    $stmt->execute($paramsSel);
                    $rows = $stmt->fetchAll();
                    // If no exact match, try like queries with vendor host constraint when enabled
                    if (!$rows) {
                        $rows = [];
                        if ($likeHostPath) {
                            if ($pid !== null && $pid !== '') {
                                $stmt2 = $pdo->prepare('SELECT p.id, p.order_url, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE p.order_url LIKE :likehp AND p.order_url LIKE :likepid');
                                $stmt2->execute([':likehp'=>$likeHostPath, ':likepid'=>'%pid=' . $pid . '%']);
                                $rows = $stmt2->fetchAll();
                            }
                            if (!$rows) {
                                $stmt3 = $pdo->prepare('SELECT p.id, p.order_url, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE p.order_url LIKE :likehp');
                                $stmt3->execute([':likehp'=>$likeHostPath]);
                                $rows = $stmt3->fetchAll();
                            }
                        }
                        if ($requireVendorHostForFallback && $rows) {
                            $keyHost = parse_url($keyVal, PHP_URL_HOST) ?: '';
                            $rows = array_values(array_filter($rows, function($r) use ($keyHost) {
                                $vw = (string)($r['vendor_website'] ?? '');
                                $vh = parse_url($vw, PHP_URL_HOST) ?: '';
                                $norm = function($h){ return strtolower(preg_replace('/^www\./i', '', (string)$h)); };
                                return $norm($keyHost) !== '' && $norm($keyHost) === $norm($vh);
                            }));
                        }
                    }
                    $cnt = $rows ? count($rows) : 0;
                    if ($cnt === 0) {
                        $titleKey = (string)($it['title'] ?? ($it['name'] ?? ''));
                        if ($titleKey !== '') {
                            $stmt2 = $pdo->prepare('SELECT p.id, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE p.title=:k');
                            $stmt2->execute([':k'=>$titleKey]);
                            $rows = $stmt2->fetchAll();
                            if ($requireVendorHostForFallback && $rows) {
                                $keyHost = parse_url($keyVal, PHP_URL_HOST) ?: '';
                                $rows = array_values(array_filter($rows, function($r) use ($keyHost) {
                                    $vw = (string)($r['vendor_website'] ?? '');
                                    $vh = parse_url($vw, PHP_URL_HOST) ?: '';
                                    $norm = function($h){ return strtolower(preg_replace('/^www\./i', '', (string)$h)); };
                                    return $norm($keyHost) !== '' && $norm($keyHost) === $norm($vh);
                                }));
                            }
                            $cnt = $rows ? count($rows) : 0;
                        }
                    }
                    $updated += $cnt;
                } else {
                    $cands = $buildUrlCandidates($keyVal);
                    $likeHostPath = null; $pid = null;
                    $parts = @parse_url($keyVal);
                    if (is_array($parts)) {
                        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
                        $path = isset($parts['path']) ? $parts['path'] : '';
                        if ($host || $path) { $likeHostPath = '%' . $host . $path . '%'; }
                        if (isset($parts['query'])) { parse_str($parts['query'], $q); if (isset($q['pid'])) { $pid = (string)$q['pid']; } }
                    }
                    $conds = [];
                    $paramsSel = [];
                    $i = 0;
                    foreach ($cands as $c) { $i++; $conds[] = 'p.order_url = :u' . $i; $paramsSel[':u'.$i] = $c; }
                    $sqlSel = 'SELECT p.id, p.title, p.order_url, p.stock_status, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE ' . implode(' OR ', $conds);
                    $sel = $pdo->prepare($sqlSel);
                    $sel->execute($paramsSel);
                    $rows = $sel->fetchAll();
                    if (!$rows) {
                        if ($likeHostPath) {
                            if ($pid !== null && $pid !== '') {
                                $stmt2 = $pdo->prepare('SELECT p.id, p.title, p.order_url, p.stock_status, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE p.order_url LIKE :likehp AND p.order_url LIKE :likepid');
                                $stmt2->execute([':likehp'=>$likeHostPath, ':likepid'=>'%pid=' . $pid . '%']);
                                $rows = $stmt2->fetchAll();
                            }
                            if (!$rows) {
                                $stmt3 = $pdo->prepare('SELECT p.id, p.title, p.order_url, p.stock_status, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE p.order_url LIKE :likehp');
                                $stmt3->execute([':likehp'=>$likeHostPath]);
                                $rows = $stmt3->fetchAll();
                            }
                        }
                        if ($requireVendorHostForFallback && $rows) {
                            $keyHost = parse_url($keyVal, PHP_URL_HOST) ?: '';
                            $rows = array_values(array_filter($rows, function($r) use ($keyHost) {
                                $vw = (string)($r['vendor_website'] ?? '');
                                $vh = parse_url($vw, PHP_URL_HOST) ?: '';
                                $norm = function($h){ return strtolower(preg_replace('/^www\./i', '', (string)$h)); };
                                return $norm($keyHost) !== '' && $norm($keyHost) === $norm($vh);
                            }));
                        }
                    }
                    $aff = 0;
                    foreach ($rows as $row) {
                        $prev = (string)($row['stock_status'] ?? '');
                        if ($prev !== $stock) {
                            $upd = $pdo->prepare('UPDATE plans SET stock_status=:s, stock_checked_at=:t WHERE id=:id');
                            $upd->execute([':s'=>$stock, ':t'=>$now, ':id'=>(int)$row['id']]);
                            $aff += $upd->rowCount();
                        }
                    }
                    if ($aff === 0) {
                        $titleKey = (string)($it['title'] ?? ($it['name'] ?? ''));
                        if ($titleKey !== '') {
                            $stmt2 = $pdo->prepare('SELECT p.id, v.website AS vendor_website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id WHERE p.title=:k');
                            $stmt2->execute([':k'=>$titleKey]);
                            $rows = $stmt2->fetchAll();
                            if ($requireVendorHostForFallback && $rows) {
                                $keyHost = parse_url($keyVal, PHP_URL_HOST) ?: '';
                                $rows = array_values(array_filter($rows, function($r) use ($keyHost) {
                                    $vw = (string)($r['vendor_website'] ?? '');
                                    $vh = parse_url($vw, PHP_URL_HOST) ?: '';
                                    $norm = function($h){ return strtolower(preg_replace('/^www\./i', '', (string)$h)); };
                                    return $norm($keyHost) !== '' && $norm($keyHost) === $norm($vh);
                                }));
                            }
                            foreach ($rows as $row) {
                                $upd = $pdo->prepare('UPDATE plans SET stock_status=:s, stock_checked_at=:t WHERE id=:id');
                                $upd->execute([':s'=>$stock, ':t'=>$now, ':id'=>(int)$row['id']]);
                                $aff += $upd->rowCount();
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
                // generic: try order_url first, then title
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
                    $stmt = $pdo->prepare('UPDATE plans SET stock_status=:s, stock_checked_at=:t WHERE order_url=:k');
                    $stmt->execute([':s'=>$stock, ':t'=>$now, ':k'=>$keyVal]);
                    if ($stmt->rowCount() === 0) {
                        $stmt2 = $pdo->prepare('UPDATE plans SET stock_status=:s, stock_checked_at=:t WHERE title=:k');
                        $stmt2->execute([':s'=>$stock, ':t'=>$now, ':k'=>$keyVal]);
                        $updated += $stmt2->rowCount();
                    } else {
                        $updated += $stmt->rowCount();
                    }
                }
            }
            $processed++;
        }
        // Persist last run snapshot (so UI's "最近一次执行" reflects manual sync too)
        try {
            db_set_setting('stock_last_run_at', $now);
            db_set_setting('stock_last_result', json_encode([
                'updated' => $updated,
                'unknown' => $unknown,
                'skipped' => $skipped,
                'dry_run' => $dryRun,
                'code' => 0,
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) { @error_log('[stock] save last_run (manual) failed: '.$e->getMessage()); }

        if ($isAjax) {
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['code'=>0,'message'=>'OK','data'=>['updated'=>$updated,'unknown'=>$unknown,'skipped'=>$skipped,'dry_run'=>$dryRun]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        } else {
            flash_set('success', "同步完成：更新 $updated 条，未知 $unknown 条，跳过 $skipped 条");
            redirect_same();
        }
    }

    // Execute configured cron command once (host/docker), for testing from UI
    if (isset($_POST['action']) && $_POST['action'] === 'stock_run_cron_once') {
        $isAjax = (isset($_POST['ajax']) && $_POST['ajax'] === '1');
        $jsonOut = function(int $code, string $msg, array $data=[]) use ($isAjax) {
            if ($isAjax && !headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['code'=>$code,'message'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        };
        // Build command based on saved settings
        $cronMode = db_get_setting('stock_cron_mode', 'host');
        $phpPath = db_get_setting('stock_php_path', '/usr/bin/php');
        $container = db_get_setting('stock_docker_container', 'PHP846');
        $dockerBin = db_get_setting('stock_docker_bin', '');
        if ($dockerBin === '') {
            foreach (['/usr/bin/docker','/usr/local/bin/docker'] as $cand) {
                if (@is_file($cand) && @is_executable($cand)) { $dockerBin = $cand; break; }
            }
            if ($dockerBin === '') { $dockerBin = 'docker'; }
        }
        $scriptPath = BASE_PATH . '/scripts/stock_cron.php';
        $cmdArgs = [];
        // Preflight checks
        $disabled = ini_get('disable_functions');
        $disabledList = is_string($disabled) ? array_map('trim', explode(',', $disabled)) : [];
        if (!function_exists('proc_open') || in_array('proc_open', $disabledList, true)) {
            $jsonOut(400, '服务器禁用了进程执行（proc_open），无法从后台调用系统命令。请使用 crontab/systemd 在服务器上执行。您也可以使用“同步库存”按钮手动执行一次。');
        }
        if ($cronMode === 'docker') {
            if (!@is_file($dockerBin) && !@is_executable($dockerBin) && basename($dockerBin) !== $dockerBin) {
                $jsonOut(400, 'docker 可执行文件不可用：' . $dockerBin);
            }
        } else {
            if (!@is_file($phpPath) || !@is_executable($phpPath)) {
                $jsonOut(400, '主机 PHP 路径不可执行：' . $phpPath);
            }
        }
        if ($cronMode === 'docker') {
            // Basic validation to avoid injection in container name
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', (string)$container)) {
                $jsonOut(400, '容器名非法');
            }
            $cmdArgs = [$dockerBin, 'exec', $container, 'php', $scriptPath];
        } else {
            $cmdArgs = [$phpPath, $scriptPath];
        }
        // Execute with timeout ~25s
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmdArgs, $descriptor, $pipes, null, null, ['bypass_shell'=>true]);
        if (!\is_resource($proc)) { $jsonOut(500, '无法启动进程'); }
        $start = microtime(true);
        $stdout = '';$stderr='';
        $timeoutSec = 25;
        $status = proc_get_status($proc);
        while ($status['running']) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if ((microtime(true) - $start) > $timeoutSec) { proc_terminate($proc); break; }
            usleep(100 * 1000);
            $status = proc_get_status($proc);
        }
        // Flush remaining
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        foreach ($pipes as $p) { if (\is_resource($p)) { fclose($p); } }
        $exitCode = proc_close($proc);
        $msg = trim($stdout !== '' ? $stdout : $stderr);
        if ($exitCode !== 0) { $jsonOut(500, '执行失败', ['exit'=>$exitCode,'output'=>$msg,'cmd'=>$cmdArgs]); }
        $jsonOut(0, '执行成功', ['exit'=>$exitCode,'output'=>$msg,'cmd'=>$cmdArgs]);
    }

    // Import: Vendors (JSON or CSV)
    if (isset($_POST['action']) && $_POST['action'] === 'import_vendors') {
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            flash_set('error', '未选择文件或上传失败');
            redirect_same();
        }
        $importMode = in_array(($_POST['import_mode'] ?? 'append'), ['append','overwrite'], true) ? $_POST['import_mode'] : 'append';
        $tmpFile = $_FILES['file']['tmp_name'];
        $raw = @file_get_contents($tmpFile);
        if ($raw === false) {
            flash_set('error', '读取上传文件失败');
            redirect_same();
        }
        $inserted = 0; $updated = 0; $skipped = 0; $handled = false; $rows = [];
        // Try JSON first -> collect rows
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $handled = true;
            $rows = isset($json['vendors']) && is_array($json['vendors']) ? $json['vendors'] : $json;
        }
        // Try CSV if not handled -> collect rows
        if (!$handled) {
            $fp = fopen($tmpFile, 'r');
            if ($fp !== false) {
                $header = fgetcsv($fp);
                $map = [];
                if (is_array($header)) { foreach ($header as $i => $h) { $map[strtolower(trim((string)$h))] = $i; } }
                while (($row = fgetcsv($fp)) !== false) {
                    $get = function(string $k) use ($map, $row) { $i = $map[$k] ?? null; return $i!==null && isset($row[$i]) ? trim((string)$row[$i]) : ''; };
                    $rows[] = [
                        'id' => $get('id'),
                        'name' => $get('name'),
                        'website' => $get('website'),
                        'logo_url' => $get('logo_url'),
                        'description' => $get('description'),
                    ];
                }
                fclose($fp);
                $handled = true;
            }
        }
        if (!$handled) { flash_set('error', '无法识别文件格式，请提供 JSON 或 CSV'); redirect_same(); }

        // Overwrite mode: clear vendors (cascades to plans)
        try {
            if ($importMode === 'overwrite') { $pdo->beginTransaction(); $pdo->exec('DELETE FROM vendors'); }
            foreach ($rows as $row) {
                if (!is_array($row)) { $skipped++; continue; }
                $id = isset($row['id']) && $row['id'] !== '' ? (int)$row['id'] : 0;
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') { $skipped++; continue; }
                $website = isset($row['website']) ? trim((string)$row['website']) : null;
                $logo = isset($row['logo_url']) ? trim((string)$row['logo_url']) : null;
                $desc = isset($row['description']) ? (string)$row['description'] : null;
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE vendors SET name=:name, website=:website, logo_url=:logo, description=:desc WHERE id=:id');
                    $ok = $stmt->execute([':name'=>$name, ':website'=>$website, ':logo'=>$logo, ':desc'=>$desc, ':id'=>$id]);
                    $updated += $ok ? 1 : 0;
                } else {
                    $stmt = $pdo->prepare('INSERT INTO vendors (name, website, logo_url, description) VALUES (:name,:website,:logo,:desc) ON DUPLICATE KEY UPDATE website=VALUES(website), logo_url=VALUES(logo_url), description=VALUES(description)');
                    $ok = $stmt->execute([':name'=>$name, ':website'=>$website, ':logo'=>$logo, ':desc'=>$desc]);
                    $inserted += $ok ? 1 : 0;
                }
            }
            if ($importMode === 'overwrite') { $pdo->commit(); }
            $modeLabel = $importMode === 'overwrite' ? '覆盖' : '追加/更新';
            flash_set('success', "$modeLabel 完成：新增 $inserted 条，更新 $updated 条，跳过 $skipped 条");
        } catch (Throwable $e) {
            if ($importMode === 'overwrite' && $pdo->inTransaction()) { $pdo->rollBack(); }
            flash_set('error', '导入失败：' . $e->getMessage());
        }
        redirect_same();
    }

    // Import: Plans (JSON or CSV)
    if (isset($_POST['action']) && $_POST['action'] === 'import_plans') {
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            flash_set('error', '未选择文件或上传失败');
            redirect_same();
        }
        $importMode = in_array(($_POST['import_mode'] ?? 'append'), ['append','overwrite'], true) ? $_POST['import_mode'] : 'append';
        $tmpFile = $_FILES['file']['tmp_name'];
        $raw = @file_get_contents($tmpFile);
        if ($raw === false) {
            flash_set('error', '读取上传文件失败');
            redirect_same();
        }
        $inserted = 0; $updated = 0; $skipped = 0; $handled = false; $rows = [];

        $normalizeFeatures = function($val) {
            $clean = function(array $arr) {
                // Flatten one-level nested JSON-string items
                $result = [];
                foreach ($arr as $item) {
                    if (is_string($item)) {
                        $item = trim($item);
                        if ($item === '') { continue; }
                        $decoded = json_decode($item, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            foreach ($decoded as $sub) {
                                if (is_string($sub)) { $sub = trim($sub); }
                                if ($sub !== '' && $sub !== null) { $result[] = (string)$sub; }
                            }
                            continue;
                        }
                        $result[] = $item;
                    } elseif ($item !== null && $item !== '') {
                        $result[] = (string)$item;
                    }
                }
                return array_values($result);
            };

            if (is_array($val)) {
                return json_encode($clean($val), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            }
            $s = trim((string)$val);
            if ($s === '') { return json_encode([], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
            // Try JSON array string first
            $decoded = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return json_encode($clean($decoded), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            }
            // Fallback split by newline / semicolon / comma
            $arr = array_values(array_filter(array_map('trim', preg_split('/\r?\n|;|,/', $s))));
            return json_encode($clean($arr), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        };

        $upsertPlan = function(array $row) use (&$inserted, &$updated, &$skipped, $pdo, $normalizeFeatures) {
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            $vendorId = isset($row['vendor_id']) ? (int)$row['vendor_id'] : 0;
            $vendorName = isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '';
            if ($vendorId <= 0 && $vendorName !== '') {
                $stmt = $pdo->prepare('SELECT id FROM vendors WHERE name=:name');
                $stmt->execute([':name'=>$vendorName]);
                $vid = (int)$stmt->fetchColumn();
                if ($vid <= 0) {
                    $ins = $pdo->prepare('INSERT INTO vendors (name) VALUES (:name)');
                    $ins->execute([':name'=>$vendorName]);
                    $vid = (int)$pdo->lastInsertId();
                }
                $vendorId = $vid;
            }
            $title = trim((string)($row['title'] ?? ''));
            $price = isset($row['price']) ? (float)$row['price'] : 0.0;
            if ($vendorId <= 0 || $title === '' || $price <= 0) { $skipped++; return; }
            $subtitle = isset($row['subtitle']) ? (string)$row['subtitle'] : null;
            $priceDuration = isset($row['price_duration']) ? (string)$row['price_duration'] : 'per year';
            if (!in_array($priceDuration, ['per month','per year','one-time'], true)) { $priceDuration = 'per year'; }
            $orderUrl = isset($row['order_url']) ? (string)$row['order_url'] : null;
            $location = isset($row['location']) ? (string)$row['location'] : null;
            $features = $normalizeFeatures($row['features'] ?? []);
            $cpu = isset($row['cpu']) ? (string)$row['cpu'] : null;
            $ram = isset($row['ram']) ? (string)$row['ram'] : null;
            $storage = isset($row['storage']) ? (string)$row['storage'] : null;
            $cpuCores = isset($row['cpu_cores']) && $row['cpu_cores'] !== '' ? (float)$row['cpu_cores'] : null;
            $ramMb = isset($row['ram_mb']) && $row['ram_mb'] !== '' ? (int)$row['ram_mb'] : null;
            $storageGb = isset($row['storage_gb']) && $row['storage_gb'] !== '' ? (int)$row['storage_gb'] : null;
            $highlights = isset($row['highlights']) ? (string)$row['highlights'] : null;
            $sortOrder = isset($row['sort_order']) && $row['sort_order'] !== '' ? (int)$row['sort_order'] : 0;

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE plans SET vendor_id=:vendor_id,title=:title,subtitle=:subtitle,price=:price,price_duration=:d,order_url=:url,location=:loc,features=:f,cpu=:cpu,ram=:ram,storage=:storage,cpu_cores=:cpu_cores,ram_mb=:ram_mb,storage_gb=:storage_gb,highlights=:h,sort_order=:s WHERE id=:id');
                $ok = $stmt->execute([':vendor_id'=>$vendorId, ':title'=>$title, ':subtitle'=>$subtitle, ':price'=>$price, ':d'=>$priceDuration, ':url'=>$orderUrl, ':loc'=>$location, ':f'=>$features, ':cpu'=>$cpu, ':ram'=>$ram, ':storage'=>$storage, ':cpu_cores'=>$cpuCores, ':ram_mb'=>$ramMb, ':storage_gb'=>$storageGb, ':h'=>$highlights, ':s'=>$sortOrder, ':id'=>$id]);
                $updated += $ok ? 1 : 0;
            } else {
                $stmt = $pdo->prepare('INSERT INTO plans (vendor_id,title,subtitle,price,price_duration,order_url,location,features,cpu,ram,storage,cpu_cores,ram_mb,storage_gb,highlights,sort_order) VALUES (:vendor_id,:title,:subtitle,:price,:d,:url,:loc,:f,:cpu,:ram,:storage,:cpu_cores,:ram_mb,:storage_gb,:h,:s)');
                $ok = $stmt->execute([':vendor_id'=>$vendorId, ':title'=>$title, ':subtitle'=>$subtitle, ':price'=>$price, ':d'=>$priceDuration, ':url'=>$orderUrl, ':loc'=>$location, ':f'=>$features, ':cpu'=>$cpu, ':ram'=>$ram, ':storage'=>$storage, ':cpu_cores'=>$cpuCores, ':ram_mb'=>$ramMb, ':storage_gb'=>$storageGb, ':h'=>$highlights, ':s'=>$sortOrder]);
                $inserted += $ok ? 1 : 0;
            }
        };

        // Try JSON first -> collect rows
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $handled = true;
            $rows = isset($json['plans']) && is_array($json['plans']) ? $json['plans'] : $json;
        }
        // Try CSV if not handled -> collect rows
        if (!$handled) {
            $fp = fopen($tmpFile, 'r');
            if ($fp !== false) {
                $header = fgetcsv($fp);
                $map = [];
                if (is_array($header)) { foreach ($header as $i => $h) { $map[strtolower(trim((string)$h))] = $i; } }
                while (($row = fgetcsv($fp)) !== false) {
                    $assoc = [];
                    foreach ($map as $k => $i) { $assoc[$k] = $row[$i] ?? null; }
                    $rows[] = $assoc;
                }
                fclose($fp);
                $handled = true;
            }
        }
        if (!$handled) { flash_set('error', '无法识别文件格式，请提供 JSON 或 CSV'); redirect_same(); }

        try {
            if ($importMode === 'overwrite') { $pdo->beginTransaction(); $pdo->exec('DELETE FROM plans'); }
            foreach ($rows as $row) { if (is_array($row)) { $upsertPlan($row); } else { $skipped++; } }
            if ($importMode === 'overwrite') { $pdo->commit(); }
            $modeLabel = $importMode === 'overwrite' ? '覆盖' : '追加/更新';
            flash_set('success', "$modeLabel 完成：新增 $inserted 条，更新 $updated 条，跳过 $skipped 条");
        } catch (Throwable $e) {
            if ($importMode === 'overwrite' && $pdo->inTransaction()) { $pdo->rollBack(); }
            flash_set('error', '导入失败：' . $e->getMessage());
        }
        redirect_same();
    }
}

$vendorsAll = $pdo->query('SELECT * FROM vendors ORDER BY id DESC')->fetchAll();
// Vendors pagination, search & sorting for listing table
$vendorsPage = max(1, (int)($_GET['vendors_page'] ?? 1));
$vendorsPageSize = min(100, max(5, (int)($_GET['vendors_page_size'] ?? 20)));
$vendorsSort = isset($_GET['vendors_sort']) ? (string)$_GET['vendors_sort'] : '';
$vendorQ = isset($_GET['vendor_q']) ? trim((string)$_GET['vendor_q']) : '';
$allowedVendorSort = [
  'id_desc' => 'id DESC',
  'id_asc' => 'id ASC',
  'name_asc' => 'name ASC, id DESC',
  'name_desc' => 'name DESC, id DESC',
  'updated_desc' => 'updated_at DESC, id DESC',
];
$orderVendors = $allowedVendorSort[$vendorsSort] ?? $allowedVendorSort['id_desc'];
$sqlVendors = 'SELECT * FROM vendors';
$whereV = [];$paramsV = [];
if ($vendorQ !== '') { $whereV[] = '(name LIKE :vq OR website LIKE :vq)'; $paramsV[':vq'] = "%$vendorQ%"; }
if ($whereV) { $sqlVendors .= ' WHERE ' . implode(' AND ', $whereV); }
$vendorsTotal = 0;
if ($whereV) {
  $stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM vendors WHERE ' . implode(' AND ', $whereV));
  $stmtCnt->execute($paramsV);
  $vendorsTotal = (int)$stmtCnt->fetchColumn();
} else {
  $vendorsTotal = (int)$pdo->query('SELECT COUNT(*) FROM vendors')->fetchColumn();
}
$vendorsOffset = ($vendorsPage - 1) * $vendorsPageSize;
$sqlVendors .= ' ORDER BY ' . $orderVendors . ' LIMIT ' . (int)$vendorsPageSize . ' OFFSET ' . (int)$vendorsOffset;
$stmtV = $pdo->prepare($sqlVendors);
$stmtV->execute($paramsV);
$vendors = $stmtV->fetchAll();

// Plans query with optional filters and pagination
$planVendorFilter = isset($_GET['plan_vendor']) ? (int)$_GET['plan_vendor'] : 0;
$planQ = isset($_GET['plan_q']) ? trim((string)$_GET['plan_q']) : '';
$sqlPlans = 'SELECT p.*, v.name AS vendor_name FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id';
$wherePlans = [];
$paramsPlans = [];
if ($planVendorFilter > 0) { $wherePlans[] = 'p.vendor_id = :pv'; $paramsPlans[':pv'] = $planVendorFilter; }
if ($planQ !== '') { $wherePlans[] = '(p.title LIKE :pq OR p.subtitle LIKE :pq)'; $paramsPlans[':pq'] = "%$planQ%"; }
// Stock filter
$planStock = isset($_GET['plan_stock']) ? trim((string)$_GET['plan_stock']) : '';
if (in_array($planStock, ['in','out','unknown'], true)) { $wherePlans[] = 'p.stock_status = :ps'; $paramsPlans[':ps'] = $planStock; }
// Price range filter
$planMinPrice = isset($_GET['plan_min_price']) ? (float)$_GET['plan_min_price'] : 0.0;
$planMaxPrice = isset($_GET['plan_max_price']) ? (float)$_GET['plan_max_price'] : 0.0;
if ($planMinPrice > 0) { $wherePlans[] = 'p.price >= :pmin'; $paramsPlans[':pmin'] = $planMinPrice; }
if ($planMaxPrice > 0 && ($planMinPrice==0 || $planMaxPrice >= $planMinPrice)) { $wherePlans[] = 'p.price <= :pmax'; $paramsPlans[':pmax'] = $planMaxPrice; }
if ($wherePlans) { $sqlPlans .= ' WHERE ' . implode(' AND ', $wherePlans); }
$plansPage = max(1, (int)($_GET['plans_page'] ?? 1));
$plansPageSize = min(100, max(10, (int)($_GET['plans_page_size'] ?? 20)));
// Sorting
$plansSort = isset($_GET['plans_sort']) ? (string)$_GET['plans_sort'] : '';
$allowedPlanSort = [
  'id_asc' => 'p.id ASC',
  'id_desc' => 'p.id DESC',
  'price_asc' => 'p.price ASC, p.id DESC',
  'price_desc' => 'p.price DESC, p.id DESC',
  'updated_desc' => 'p.updated_at DESC, p.id DESC',
  'sort_asc' => 'p.sort_order ASC, p.id DESC',
];
$orderPlans = $allowedPlanSort[$plansSort] ?? $allowedPlanSort['sort_asc'];
$plansOffset = ($plansPage - 1) * $plansPageSize;
$sqlPlansCount = 'SELECT COUNT(*) FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id' . ($wherePlans ? (' WHERE ' . implode(' AND ', $wherePlans)) : '');
$stmtPlansCount = $pdo->prepare($sqlPlansCount);
$stmtPlansCount->execute($paramsPlans);
$plansTotal = (int)$stmtPlansCount->fetchColumn();
$sqlPlans .= ' ORDER BY ' . $orderPlans . ' LIMIT ' . (int)$plansPageSize . ' OFFSET ' . (int)$plansOffset;
$stmtPlans = $pdo->prepare($sqlPlans);
$stmtPlans->execute($paramsPlans);
$plans = $stmtPlans->fetchAll();

// Prepare edit contexts
$editVendorId = isset($_GET['edit_vendor']) ? (int)$_GET['edit_vendor'] : 0;
$editingVendor = null;
if ($editVendorId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM vendors WHERE id=:id');
    $stmt->execute([':id' => $editVendorId]);
    $editingVendor = $stmt->fetch();
}

$editPlanId = isset($_GET['edit_plan']) ? (int)$_GET['edit_plan'] : 0;
$editingPlan = null;
if ($editPlanId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM plans WHERE id=:id');
    $stmt->execute([':id' => $editPlanId]);
    $editingPlan = $stmt->fetch();
}

$csrf = auth_get_csrf_token();

// Handle data export downloads (must run before HTML output)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $type = (string)$_GET['download'];
    if ($type === 'vendors_json') {
        $rows = $pdo->query('SELECT id,name,website,logo_url,description,created_at,updated_at FROM vendors ORDER BY id ASC')->fetchAll();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="vendors.json"');
        echo json_encode(['vendors' => $rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        exit;
    } elseif ($type === 'plans_json') {
        $rows = $pdo->query('SELECT id,vendor_id,title,subtitle,price,price_duration,order_url,location,features,cpu,ram,storage,cpu_cores,ram_mb,storage_gb,highlights,sort_order,created_at,updated_at FROM plans ORDER BY id ASC')->fetchAll();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="plans.json"');
        echo json_encode(['plans' => $rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        exit;
    } elseif ($type === 'vendors_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="vendors.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','name','website','logo_url','description','created_at','updated_at']);
        $stmt = $pdo->query('SELECT id,name,website,logo_url,description,created_at,updated_at FROM vendors ORDER BY id ASC');
        while ($row = $stmt->fetch()) { fputcsv($out, [$row['id'],$row['name'],$row['website'],$row['logo_url'],$row['description'],$row['created_at'],$row['updated_at']]); }
        fclose($out); exit;
    } elseif ($type === 'plans_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plans.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','vendor_id','title','subtitle','price','price_duration','order_url','location','features','cpu','ram','storage','cpu_cores','ram_mb','storage_gb','highlights','sort_order','created_at','updated_at']);
        $stmt = $pdo->query('SELECT id,vendor_id,title,subtitle,price,price_duration,order_url,location,features,cpu,ram,storage,cpu_cores,ram_mb,storage_gb,highlights,sort_order,created_at,updated_at FROM plans ORDER BY id ASC');
        while ($row = $stmt->fetch()) {
            $features = $row['features'];
            if (!is_string($features)) { $features = json_encode($features, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
            $featuresStr = '';
            $decoded = json_decode((string)$features, true);
            if (is_array($decoded)) { $featuresStr = implode('; ', array_map('strval', $decoded)); }
            fputcsv($out, [
                $row['id'],$row['vendor_id'],$row['title'],$row['subtitle'],$row['price'],$row['price_duration'],$row['order_url'],$row['location'],$featuresStr,$row['cpu'],$row['ram'],$row['storage'],$row['cpu_cores'],$row['ram_mb'],$row['storage_gb'],$row['highlights'],$row['sort_order'],$row['created_at'],$row['updated_at']
            ]);
        }
        fclose($out); exit;
    } elseif ($type === 'plans_csv_current') {
        // Export current filtered dataset from plans tab
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plans_filtered.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','vendor','title','subtitle','price','price_duration','order_url','location','stock_status','sort_order','updated_at']);
        // Build filter same as listing
        $planVendorFilter = isset($_GET['plan_vendor']) ? (int)$_GET['plan_vendor'] : 0;
        $planQ = isset($_GET['plan_q']) ? trim((string)$_GET['plan_q']) : '';
        $planStock = isset($_GET['plan_stock']) ? trim((string)$_GET['plan_stock']) : '';
        $plansSort = isset($_GET['plans_sort']) ? (string)$_GET['plans_sort'] : '';
        $allowedPlanSort = [
          'id_asc' => 'p.id ASC',
          'id_desc' => 'p.id DESC',
          'price_asc' => 'p.price ASC, p.id DESC',
          'price_desc' => 'p.price DESC, p.id DESC',
          'updated_desc' => 'p.updated_at DESC, p.id DESC',
          'sort_asc' => 'p.sort_order ASC, p.id DESC',
        ];
        $orderPlans = $allowedPlanSort[$plansSort] ?? $allowedPlanSort['sort_asc'];
        $sql = 'SELECT p.id, v.name AS vendor_name, p.title, p.subtitle, p.price, p.price_duration, p.order_url, p.location, p.stock_status, p.sort_order, p.updated_at FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id';
        $where = [];$params=[];
        if ($planVendorFilter > 0) { $where[]='p.vendor_id=:pv'; $params[':pv']=$planVendorFilter; }
        if ($planQ !== '') { $where[]='(p.title LIKE :pq OR p.subtitle LIKE :pq)'; $params[':pq']="%$planQ%"; }
        if (in_array($planStock, ['in','out','unknown'], true)) { $where[]='p.stock_status=:ps'; $params[':ps']=$planStock; }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY ' . $orderPlans;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                (int)$row['id'], (string)$row['vendor_name'], (string)$row['title'], (string)$row['subtitle'], (float)$row['price'], (string)$row['price_duration'], (string)$row['order_url'], (string)$row['location'], (string)($row['stock_status'] ?? ''), (int)$row['sort_order'], (string)$row['updated_at']
            ]);
        }
        fclose($out); exit;
    } elseif ($type === 'vendors_csv_current') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="vendors_filtered.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','name','website','logo_url','updated_at']);
        $vendorQ = isset($_GET['vendor_q']) ? trim((string)$_GET['vendor_q']) : '';
        $vendorsSort = isset($_GET['vendors_sort']) ? (string)$_GET['vendors_sort'] : '';
        $allowedVendorSort = [
          'id_desc' => 'id DESC', 'id_asc' => 'id ASC',
          'name_asc' => 'name ASC, id DESC', 'name_desc' => 'name DESC, id DESC',
          'updated_desc' => 'updated_at DESC, id DESC',
        ];
        $orderVendors = $allowedVendorSort[$vendorsSort] ?? $allowedVendorSort['id_desc'];
        $sql = 'SELECT id,name,website,logo_url,updated_at FROM vendors';
        $where = [];$params=[];
        if ($vendorQ !== '') { $where[]='(name LIKE :vq OR website LIKE :vq)'; $params[':vq']="%$vendorQ%"; }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY ' . $orderVendors;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            fputcsv($out, [(int)$row['id'], (string)$row['name'], (string)$row['website'], (string)$row['logo_url'], (string)$row['updated_at']]);
        }
        fclose($out); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <title>管理后台 - <?= htmlspecialchars(SITE_NAME) ?></title>
  <link rel="stylesheet" href="../assets/style.css">
  <script defer src="../assets/admin.js"></script>
</head>
<body>
  <div class="container">
    <?php if ($flash = flash_get()): ?>
      <div class="alert <?= $flash['type']==='error' ? 'alert-error' : 'alert-success' ?>">
        <?= htmlspecialchars($flash['message']) ?>
      </div>
    <?php endif; ?>
    <div class="header">
      <div class="brand">
        <img src="../assets/emoji/memo.svg" alt="admin" width="36" height="36">
        <h1>管理后台</h1>
      </div>
      <div class="row items-center gap8">
        <span class="muted small">当前账号：<?= htmlspecialchars($_SESSION['admin_username'] ?? ADMIN_USERNAME) ?></span>
        <a class="btn" href="../">返回前台</a>
        <a class="btn" href="./logout.php">退出登录</a>
      </div>
    </div>

    <nav class="row wrap gap8 mb16">
      <a class="btn" href="?tab=vendors">厂商管理</a>
      <a class="btn" href="?tab=plans">套餐管理</a>
      <a class="btn" href="?tab=account">账号设置</a>
      <a class="btn" href="../scripts/import_url.php" target="_blank">通用导入 URL</a>
      <a class="btn" href="?tab=data">数据导入导出</a>
      <a class="btn" href="?tab=stock">库存同步</a>
    </nav>

    <?php if ($tab === 'vendors'): ?>
      <section class="mb16">
        <h2 class="mt6 mb12">新增/编辑 厂商</h2>
        <form method="post">
          <input type="hidden" name="action" value="vendor_save">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <div class="form-row">
            <div>
              <label>厂商ID（编辑时填写）</label>
              <input class="input" type="number" name="id" placeholder="留空表示新增" value="<?= $editingVendor ? (int)$editingVendor['id'] : '' ?>">
            </div>
            <div>
              <label>名称</label>
              <input class="input" type="text" name="name" required value="<?= $editingVendor ? htmlspecialchars($editingVendor['name']) : '' ?>">
            </div>
            <div>
              <label>官网链接</label>
              <input class="input" type="url" name="website" value="<?= $editingVendor ? htmlspecialchars((string)$editingVendor['website']) : '' ?>">
            </div>
            <div>
              <label>Logo URL</label>
              <input class="input" type="url" name="logo_url" value="<?= $editingVendor ? htmlspecialchars((string)$editingVendor['logo_url']) : '' ?>">
            </div>
          </div>
          <div>
            <label>简介</label>
            <textarea name="description" rows="3"><?php if ($editingVendor) { echo htmlspecialchars((string)$editingVendor['description']); } ?></textarea>
          </div>
          <div class="mt10"><button class="btn" type="submit">保存</button></div>
        </form>
      </section>

      <section>
        <h2 class="mt6 mb12">厂商列表</h2>
        <?php $vendDl = array_intersect_key($_GET, array_flip(['tab','vendor_q','vendors_sort'])); $vendDl['download']='vendors_csv_current'; ?>
        <div class="row wrap items-center gap8 mb8">
          <form method="get" class="row wrap items-center gap8">
            <input type="hidden" name="tab" value="vendors">
            <input type="hidden" name="vendors_page" value="1">
            <input type="hidden" name="vendors_sort" value="<?= htmlspecialchars($vendorsSort) ?>">
            <input class="input w220" type="text" name="vendor_q" placeholder="搜索厂商/官网" value="<?= htmlspecialchars($vendorQ) ?>">
            <button class="btn" type="submit">搜索</button>
            <a class="btn btn-secondary" href="?tab=vendors">重置</a>
            <noscript><button class="btn" type="submit">应用</button></noscript>
          </form>
          <a class="btn" href="?<?= htmlspecialchars(http_build_query($vendDl)) ?>">导出当前筛选 CSV</a>
          <form method="post" onsubmit="return confirm('确认对所选厂商执行该操作？');" class="row items-center gap8">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="vendors_bulk_delete">
            <button class="btn btn-danger" type="submit">批量删除</button>
            <span class="small muted">勾选需要删除的厂商</span>
          </form>
        </div>
        <table class="table">
            <thead>
              <tr>
                <th><input type="checkbox" class="js-select-all" aria-label="选择全部"></th>
                <?php
                  $qsBaseV = $_GET; $qsBaseV['tab']='vendors'; unset($qsBaseV['vendors_page']);
                  $makeSortV = function(string $key) use ($qsBaseV, $vendorsSort) {
                    $next = $key;
                    if ($key==='id_desc' && $vendorsSort==='id_desc') { $next='id_asc'; }
                    if ($key==='name_desc' && $vendorsSort==='name_desc') { $next='name_asc'; }
                    $qs = $qsBaseV; $qs['vendors_sort']=$next; return '?' . http_build_query($qs);
                  };
                ?>
                <th><a href="<?= htmlspecialchars($makeSortV('id_desc')) ?>">ID</a></th>
                <th><a href="<?= htmlspecialchars($makeSortV('name_desc')) ?>">名称</a></th>
                <th>官网</th>
                <th>Logo</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($vendors as $v): ?>
                <tr>
                  <td><input type="checkbox" name="vendor_ids[]" value="<?= (int)$v['id'] ?>" class="js-row-check"></td>
                  <td><?= (int)$v['id'] ?></td>
                  <td><?= admin_highlight(htmlspecialchars($v['name']), $vendorQ) ?></td>
                  <td><?php $w=(string)$v['website']; if($w){ echo '<a href="'.htmlspecialchars($w).'" target="_blank">'.admin_highlight(htmlspecialchars($w), $vendorQ).'</a>'; } ?></td>
                  <td><?= $v['logo_url']?'<img src="'.htmlspecialchars($v['logo_url']).'" alt="logo" class="logo-small">':'' ?></td>
                  <td class="admin-actions">
                    <a class="btn" href="?tab=vendors&edit_vendor=<?= (int)$v['id'] ?>">编辑</a>
                    <form method="post" data-confirm="删除后不可恢复，确定？">
                      <input type="hidden" name="action" value="vendor_delete">
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                      <button class="btn" type="submit">删除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
        <?php
          $vendorsTotalPages = (int)ceil($vendorsTotal / $vendorsPageSize);
          $baseQuery = array_merge($_GET, ['tab' => 'vendors', 'vendors_page_size' => $vendorsPageSize, 'vendors_sort'=>$vendorsSort]);
          render_pagination([
            'page' => $vendorsPage,
            'total_pages' => $vendorsTotalPages,
            'total_items' => $vendorsTotal,
            'base_query' => $baseQuery,
            'page_param' => 'vendors_page',
            'window' => 2,
            'per_page_options' => [10,20,50,100],
            'per_page_param' => 'vendors_page_size',
            'per_page_value' => $vendorsPageSize,
            'align' => 'flex-end',
          ]);
        ?>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'plans'): ?>
      <section class="mb24">
        <h2 class="mt6 mb12">新增/编辑 套餐</h2>
        <form method="post">
          <input type="hidden" name="action" value="plan_save">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <div class="form-row">
            <div>
              <label>套餐ID（编辑时填写）</label>
              <input class="input" type="number" name="id" placeholder="留空表示新增" value="<?= $editingPlan ? (int)$editingPlan['id'] : '' ?>">
            </div>
            <div>
              <label>厂商</label>
              <select name="vendor_id" required>
                <option value="">请选择厂商</option>
                <?php foreach ($vendorsAll as $v): ?>
                  <option value="<?= (int)$v['id'] ?>" <?= $editingPlan && (int)$editingPlan['vendor_id']===(int)$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>标题（如 2GB）</label>
              <input class="input" type="text" name="title" required value="<?= $editingPlan ? htmlspecialchars($editingPlan['title']) : '' ?>">
            </div>
            <div>
              <label>副标题（如 KVM VPS）</label>
              <input class="input" type="text" name="subtitle" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['subtitle']) : '' ?>">
            </div>
            <div>
              <label>价格</label>
              <input class="input" type="number" step="0.01" name="price" required value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['price']) : '' ?>">
            </div>
            <div>
              <label>计费周期</label>
              <select name="price_duration">
                <?php $dur = $editingPlan ? (string)$editingPlan['price_duration'] : 'per year'; ?>
                <option value="per year" <?= $dur==='per year'?'selected':'' ?>>年付</option>
                <option value="per month" <?= $dur==='per month'?'selected':'' ?>>月付</option>
                <option value="one-time" <?= $dur==='one-time'?'selected':'' ?>>一次性</option>
              </select>
            </div>
            <div>
              <label>下单链接</label>
              <input class="input" type="url" name="order_url" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['order_url']) : '' ?>">
            </div>
            <div>
              <label>详情链接</label>
              <input class="input" type="url" name="details_url" placeholder="https://...（可选）" value="<?= $editingPlan ? htmlspecialchars((string)($editingPlan['details_url'] ?? '')) : '' ?>">
            </div>
            <div>
              <label>部署地区/机房</label>
              <input class="input" type="text" name="location" placeholder="Multiple Locations" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['location']) : '' ?>">
            </div>
            <div>
              <label>排序（越小越靠前）</label>
              <input class="input" type="number" name="sort_order" value="<?= $editingPlan ? (int)$editingPlan['sort_order'] : 0 ?>">
            </div>
          </div>
          <div>
            <label>功能/规格（每行一条）</label>
            <textarea name="features" rows="6" placeholder="1 vCPU Core\n20 GB SSD\n...\n"><?php
              if ($editingPlan) {
                $features = $editingPlan['features'];
                if (is_string($features)) { $features = json_decode($features, true); }
                if (is_array($features)) { echo htmlspecialchars(implode("\n", $features)); }
              }
            ?></textarea>
          </div>
          <div class="form-row">
            <div>
              <label>CPU（结构化字段）</label>
              <input class="input" type="text" name="cpu" placeholder="如：2 vCPU Cores" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['cpu']) : '' ?>">
            </div>
            <div>
              <label>内存（结构化字段）</label>
              <input class="input" type="text" name="ram" placeholder="如：3 GB RAM" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['ram']) : '' ?>">
            </div>
            <div>
              <label>存储（结构化字段）</label>
              <input class="input" type="text" name="storage" placeholder="如：60 GB NVMe SSD Storage" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['storage']) : '' ?>">
            </div>
          </div>
          <div>
            <label>角标文案（如 Black Friday，可留空）</label>
            <input class="input" type="text" name="highlights" placeholder="Black Friday" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['highlights']) : '' ?>">
          </div>
          <div class="row items-center gap12 mt10">
            <label class="row items-center gap6">
              <input type="checkbox" name="normalize_to_zh" value="1">
              保存前规范化为中文（基于术语表）
            </label>
            <button class="btn" type="submit">保存</button>
          </div>
        </form>
      </section>

      <section>
        <h2 class="mt6 mb12">套餐列表</h2>
        <form method="get" class="mb8 row wrap gap8 items-center">
          <input type="hidden" name="tab" value="plans">
          <input type="hidden" name="plans_page" value="1">
          <input type="hidden" name="plans_sort" value="<?= htmlspecialchars($plansSort) ?>">
          <select name="plan_vendor" class="js-auto-submit">
            <option value="0">全部厂商</option>
            <?php foreach ($vendorsAll as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= $planVendorFilter===(int)$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input class="input w220" type="text" name="plan_q" placeholder="搜索标题/副标题" value="<?= htmlspecialchars($planQ) ?>">
          <select name="plan_stock" class="js-auto-submit">
            <?php $ps = $planStock; ?>
            <option value="">库存（全部）</option>
            <option value="in" <?= $ps==='in'?'selected':'' ?>><?= htmlspecialchars(t('in_stock')) ?></option>
            <option value="out" <?= $ps==='out'?'selected':'' ?>><?= htmlspecialchars(t('out_of_stock')) ?></option>
            <option value="unknown" <?= $ps==='unknown'?'selected':'' ?>><?= htmlspecialchars(t('unknown')) ?></option>
          </select>
          <input class="input w120" type="number" step="0.01" name="plan_min_price" placeholder="最小价" value="<?= isset($_GET['plan_min_price'])?(float)$_GET['plan_min_price']:'' ?>">
          <input class="input w120" type="number" step="0.01" name="plan_max_price" placeholder="最大价" value="<?= isset($_GET['plan_max_price'])?(float)$_GET['plan_max_price']:'' ?>">
          <a class="btn btn-secondary" href="?tab=plans">重置</a>
          <?php $dlqs = array_intersect_key($_GET, array_flip(['tab','plan_vendor','plan_q','plan_stock','plans_sort','plan_min_price','plan_max_price'])); $dlqs['download']='plans_csv_current'; ?>
          <a class="btn" href="?<?= htmlspecialchars(http_build_query($dlqs)) ?>">导出当前筛选 CSV</a>
        </form>
        <form method="post" onsubmit="return confirm('确认对所选项执行该操作？');">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="plans_bulk">
          <div class="row wrap items-center gap12 mb8 mt6">
            <label class="small">批量操作：</label>
            <label class="row items-center gap6">
              <input type="radio" name="do" value="delete" checked>
              删除
            </label>
            <label class="row items-center gap6">
              <input type="radio" name="do" value="sort">
              修改排序
            </label>
            <label class="row items-center gap8" data-bulk-section="sort">
              <select name="sort_mode" class="input w140">
                <option value="set">设置为</option>
                <option value="inc">在原有基础上增加/减少</option>
              </select>
              <input class="input w120" type="number" name="sort_amount" value="0">
            </label>
            <label class="row items-center gap8" data-bulk-section="billing">
              <span class="small muted">计费周期</span>
              <select name="billing_value" class="input w160">
                <option value="per year">年付（per year）</option>
                <option value="per month">月付（per month）</option>
                <option value="one-time">一次性（one-time）</option>
              </select>
            </label>
            <label class="row items-center gap8" data-bulk-section="stock">
              <span class="small muted">库存状态</span>
              <select name="stock_value" class="input w160">
                <option value="in">有货（in）</option>
                <option value="out">无货（out）</option>
                <option value="unknown">未知（unknown）</option>
              </select>
            </label>
            <button class="btn" type="submit">执行</button>
            <span class="small muted">勾选记录后执行所选操作</span>
          </div>
          <table class="table">
            <thead>
              <tr>
                <th><input type="checkbox" class="js-select-all" aria-label="选择全部"></th>
                <?php
                  $qsBase = $_GET; $qsBase['tab']='plans'; unset($qsBase['plans_page']);
                  $makeSort = function(string $key) use ($qsBase, $plansSort) {
                    $next = $key;
                    // toggle asc/desc for id/price; others are fixed
                    if ($key==='id_desc' && $plansSort==='id_desc') { $next = 'id_asc'; }
                    if ($key==='price_desc' && $plansSort==='price_desc') { $next = 'price_asc'; }
                    $qs = $qsBase; $qs['plans_sort'] = $next; return '?' . http_build_query($qs);
                  };
                ?>
                <th><a href="<?= htmlspecialchars($makeSort('id_desc')) ?>">ID</a></th>
                <th>厂商</th>
                <th>标题</th>
                <th>地区</th>
                <th><a href="<?= htmlspecialchars($makeSort('price_desc')) ?>">价格</a></th>
                <th>周期</th>
                <th>库存</th>
                <th><a href="<?= htmlspecialchars($makeSort('sort_asc')) ?>">排序</a></th>
                <th>角标</th>
                <th>详情</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($plans as $p): ?>
              <tr>
                <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" class="js-row-check"></td>
                <td><?= (int)$p['id'] ?></td>
                <td><?= admin_highlight(htmlspecialchars($p['vendor_name']), $planQ) ?></td>
                <td><?= admin_highlight(htmlspecialchars($p['title']), $planQ) ?></td>
                <td><?= htmlspecialchars((string)$p['location']) ?></td>
                <td>$<?= number_format((float)$p['price'],2) ?></td>
                <td><?= htmlspecialchars($p['price_duration']) ?></td>
                <td>
                  <?php
                    $stock = $p['stock_status'] ?? null;
                    if ($stock === 'in') {
                        echo '<span class="chip chip-in">' . htmlspecialchars(t('in_stock')) . '</span>';
                    } elseif ($stock === 'out') {
                        echo '<span class="chip chip-out">' . htmlspecialchars(t('out_of_stock')) . '</span>';
                    } else {
                        echo '<span class="chip chip-unknown">' . htmlspecialchars(t('unknown')) . '</span>';
                    }
                  ?>
                </td>
                <td><?= (int)$p['sort_order'] ?></td>
                <td><?= htmlspecialchars((string)$p['highlights']) ?></td>
                <td><?php $du=(string)($p['details_url']??''); if($du){ echo '<a href="'.htmlspecialchars($du).'" target="_blank" rel="nofollow noopener">查看</a>'; } ?></td>
                <td class="admin-actions">
                  <a class="btn" href="?tab=plans&edit_plan=<?= (int)$p['id'] ?>">编辑</a>
                  <form method="post" data-confirm="复制该套餐为新记录？">
                    <input type="hidden" name="action" value="plan_duplicate">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn" type="submit">复制</button>
                  </form>
                  <form method="post" data-confirm="删除后不可恢复，确定？">
                    <input type="hidden" name="action" value="plan_delete">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-danger" type="submit">删除</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
        <?php
          $plansTotalPages = (int)ceil($plansTotal / $plansPageSize);
          $baseQuery = $_GET; $baseQuery['tab'] = 'plans';
          render_pagination([
            'page' => $plansPage,
            'total_pages' => $plansTotalPages,
            'total_items' => $plansTotal,
            'base_query' => [
              'tab' => 'plans',
              'plan_vendor' => $planVendorFilter,
              'plan_q' => $planQ,
              'plan_stock' => $planStock,
              'plans_page_size' => $plansPageSize,
              'plans_sort' => $plansSort,
            ],
            'page_param' => 'plans_page',
            'window' => 2,
            'per_page_options' => [10,20,50,100],
            'per_page_param' => 'plans_page_size',
            'per_page_value' => $plansPageSize,
            'align' => 'flex-end',
          ]);
        ?>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'account'): ?>
      <section class="mb24">
        <h2 class="mt6 mb12">管理员账号设置</h2>
        <form method="post" data-confirm="确认保存账号设置？" class="maxw520">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="account_save">
          <div>
            <label>用户名</label>
            <input class="input" type="text" name="admin_username" required value="<?= htmlspecialchars(db_get_setting('admin_username', ADMIN_USERNAME)) ?>">
          </div>
          <div class="mt10">
            <label>新密码（留空则不修改）</label>
            <input class="input" type="password" name="new_password" autocomplete="new-password" placeholder="不修改请留空">
          </div>
          <div class="mt10">
            <label>确认新密码</label>
            <input class="input" type="password" name="new_password_confirm" autocomplete="new-password" placeholder="再次输入新密码">
          </div>
          <div class="muted small mt6">
            密码要求：至少 12 位，且包含大写字母、小写字母、数字与特殊字符。
          </div>
          <div class="mt10">
            <label>当前密码（为安全起见，修改任一项都需验证）</label>
            <input class="input" type="password" name="current_password" required autocomplete="current-password">
          </div>
          <div class="mt12 row items-center gap8">
            <button class="btn" type="submit">保存</button>
            <span class="muted small">密码将以哈希保存到数据库的 settings 表中</span>
          </div>
        </form>
        <?php
          $uRow = db_get_setting_row('admin_username');
          $pRow = db_get_setting_row('admin_password_hash');
        ?>
        <div class="muted small mt8">
          用户名最后更新：<?= htmlspecialchars($uRow['updated_at'] ?? '尚未设置') ?>；
          密码最后更新：<?= htmlspecialchars($pRow['updated_at'] ?? '尚未设置') ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'stock'): ?>
      <?php
        // read settings
        $stockEndpoint = db_get_setting('stock_endpoint', '');
        $stockMethod = db_get_setting('stock_method', 'GET');
        $stockAuth = db_get_setting('stock_auth_header', '');
        $stockQuery = db_get_setting('stock_query', 'per_page=100');
        $stockMap = db_get_setting('stock_map', '{"match_on":"url","status_field":"status","in":"In Stock","out":"Out of Stock"}');
        $autoEnabled = (int)db_get_setting('stock_auto_enabled', '0');
        $autoIntervalMin = max(1, (int)db_get_setting('stock_auto_interval_min', '15'));
        $lastRunAt = db_get_setting('stock_last_run_at', '');
        $lastResultJson = db_get_setting('stock_last_result', '');
        $lastResult = $lastResultJson ? json_decode($lastResultJson, true) : null;
        // Cron environment settings
        $cronMode = db_get_setting('stock_cron_mode', 'host'); // host | docker
        $dockerContainer = db_get_setting('stock_docker_container', 'PHP846');
        $phpPath = db_get_setting('stock_php_path', '/usr/bin/php'); // host PHP path
        $containerBase = db_get_setting('stock_container_base', '/www/sites/vs.140581.xyz/index');

        $scriptPath = BASE_PATH . '/scripts/stock_cron.php';
        $logPath = BASE_PATH . '/log/stock_cron.log';
        // Resolve docker binary for examples
        $dockerBin = db_get_setting('stock_docker_bin', '');
        if ($dockerBin === '') {
            foreach (['/usr/bin/docker','/usr/local/bin/docker'] as $cand) {
                if (@is_file($cand) && @is_executable($cand)) { $dockerBin = $cand; break; }
            }
            if ($dockerBin === '') { $dockerBin = 'docker'; }
        }
        $scriptPathContainer = rtrim($containerBase, '/') . '/scripts/stock_cron.php';
        $execCmd = $cronMode === 'docker'
          ? ($dockerBin . ' exec ' . $dockerContainer . ' php ' . $scriptPathContainer)
          : ($phpPath . ' ' . $scriptPath);
        $cronLine = '*/' . $autoIntervalMin . ' * * * * ' . $execCmd . ' >> ' . $logPath . ' 2>&1';
      // export CSV for stock logs
      if (isset($_GET['export']) && $_GET['export'] === 'stock_logs') {
        $codeFilter = isset($_GET['logs_code']) ? (int)$_GET['logs_code'] : null;
        $from = isset($_GET['logs_from']) ? trim((string)$_GET['logs_from']) : '';
        $to = isset($_GET['logs_to']) ? trim((string)$_GET['logs_to']) : '';
        $conds=[];$params=[];
        if ($codeFilter !== null && ($codeFilter===0 || $codeFilter===400 || $codeFilter===500)) { $conds[]='code=:c'; $params[':c']=$codeFilter; }
        if ($from !== '') { $conds[]='run_at>=:f'; $params[':f']=$from; }
        if ($to !== '') { $conds[]='run_at<=:t'; $params[':t']=$to; }
        $where = $conds ? (' WHERE '.implode(' AND ',$conds)) : '';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stock_logs.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['run_at','code','updated','unknown','skipped','duration_ms','message']);
        $stmt = $pdo->prepare('SELECT * FROM stock_logs' . $where . ' ORDER BY run_at DESC');
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
          fputcsv($out, [$row['run_at'],$row['code'],$row['updated'],$row['unknown'],$row['skipped'],$row['duration_ms'],$row['message']]);
        }
        fclose($out);
        exit;
      }
      ?>
      <section class="mb16">
        <h2 class="mt6 mb12">库存接口配置</h2>
        <form method="post" id="form-stock-settings" class="maxw720">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="stock_save_settings">
          <div>
            <label>接口地址</label>
            <input class="input" type="url" name="endpoint" required placeholder="https://example.com/api/monitors" value="<?= htmlspecialchars($stockEndpoint) ?>">
          </div>
          <div class="form-row">
            <div>
              <label>请求方式</label>
              <select name="method">
                <?php foreach (["GET","POST"] as $m): ?>
                  <option value="<?= $m ?>" <?= strtoupper($stockMethod)===$m?'selected':'' ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Authorization 头（可选）</label>
              <input class="input" type="text" name="auth_header" placeholder="Bearer xxx" value="<?= htmlspecialchars($stockAuth) ?>">
            </div>
          </div>
          <div>
            <label>查询串/请求体（可选，形如 a=1&b=2）</label>
            <input class="input" type="text" name="query" placeholder="per_page=100" value="<?= htmlspecialchars($stockQuery) ?>">
          </div>
          <div>
            <label>字段映射（JSON）</label>
            <textarea name="map" rows="4" class="input" placeholder='{"match_on":"url","status_field":"status","in":"In Stock","out":"Out of Stock"}'><?= htmlspecialchars($stockMap) ?></textarea>
            <div class="muted small">match_on: 用于匹配套餐的字段（如 url 或 name）; status_field: 响应里的状态字段名；in/out: 表示有货/无货的取值。</div>
          </div>
          <div class="mt10"><button class="btn" type="submit">保存</button></div>
        </form>
      </section>

      <section class="mb16">
        <h2 class="mt6 mb12">执行同步</h2>
        <div class="maxw720">
          <button class="btn" id="btn-sync-stock" type="button">同步库存</button>
          <span id="sync-result" class="small muted ml8"></span>
          <pre id="sync-log" class="pre-log hidden"></pre>
          <div class="row wrap items-center gap12 mt8">
            <label class="row items-center gap6">
              <?php $dryDefault = (int)db_get_setting('stock_dry_run_default','0'); ?>
              <input type="checkbox" id="stock-dry-run" value="1" <?= $dryDefault ? 'checked' : '' ?> data-default="<?= $dryDefault ?>"> 演练模式（不落库）
            </label>
            <label class="row items-center gap6">
              <span class="small muted">限制条数</span>
              <input class="input w120" id="stock-limit" type="number" min="0" value="<?= (int)db_get_setting('stock_limit_default','0') ?>" placeholder="0 表示不限制">
            </label>
          </div>
        </div>
        <script src="../assets/admin-stock.js" defer></script>
      </section>

      <section class="mb16">
        <h2 class="mt6 mb12">自动同步设置</h2>
        <form method="post" id="form-stock-auto" class="maxw720">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="stock_save_settings">
          <div class="form-row">
            <label class="row items-center gap6">
              <input type="checkbox" name="auto_enabled" value="1" <?= $autoEnabled ? 'checked' : '' ?>> 启用自动同步（需配合服务器定时任务）
            </label>
          </div>
          <div class="form-row">
            <div>
              <label>执行频率（分钟）</label>
              <input class="input" type="number" min="1" name="auto_interval_min" value="<?= (int)$autoIntervalMin ?>">
            </div>
          </div>
          <div class="form-row row wrap items-center gap12 mt8">
            <div>
              <label>演练模式</label>
              <label class="row items-center gap6">
                <input type="checkbox" name="dry_run_default" value="1" <?= (int)db_get_setting('stock_dry_run_default','0') ? 'checked' : '' ?>> 仅统计，不落库
              </label>
            </div>
            <div>
              <label>每次最大处理条数（可选）</label>
              <input class="input" type="number" min="0" name="limit_default" value="<?= (int)db_get_setting('stock_limit_default','0') ?>" placeholder="0 表示不限制">
            </div>
          </div>
          <div class="muted small mt6">保存后，参考下方 crontab 或 systemd 配置在服务器上启用定时执行。</div>
          <div class="mt10"><button class="btn" type="submit">保存</button></div>
        </form>
      </section>

      <?php
        $whEnabled = (int)db_get_setting('stock_webhook_enabled','0');
        $whUrl = db_get_setting('stock_webhook_url','');
        $whAuth = db_get_setting('stock_webhook_auth_header','');
      ?>
      <section class="mb16">
        <h2 class="mt6 mb12">Webhook 通知</h2>
        <form method="post" id="form-stock-webhook" class="maxw720">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="stock_save_webhook">
          <div class="form-row">
            <label class="row items-center gap6">
              <input type="checkbox" name="webhook_enabled" value="1" <?= $whEnabled ? 'checked' : '' ?>> 启用库存变更 Webhook（仅在状态变更时推送）
            </label>
          </div>
          <div>
            <label>Webhook URL</label>
            <input class="input" type="url" name="webhook_url" placeholder="https://example.com/webhook" value="<?= htmlspecialchars($whUrl) ?>">
          </div>
          <div>
            <label>Authorization 头（可选，可多行）</label>
            <textarea class="input" name="webhook_auth" rows="2" placeholder="Authorization: Bearer xxx&#10;X-Custom-Token: abc123"><?= htmlspecialchars($whAuth) ?></textarea>
          </div>
          <div class="muted small mt6">
            负载格式：<code>{&quot;events&quot;:[{&quot;plan_id&quot;:1,&quot;title&quot;:&quot;...&quot;,&quot;order_url&quot;:&quot;...&quot;,&quot;prev&quot;:&quot;out&quot;,&quot;curr&quot;:&quot;in&quot;,&quot;checked_at&quot;:&quot;YYYY-mm-dd HH:MM:SS&quot;}]}</code>
          </div>
          <div class="mt10"><button class="btn" type="submit">保存</button></div>
        </form>
      </section>

      <section class="mb24">
        <h3 class="mt6 mb8">最近一次执行</h3>
        <div class="small text-light">
          时间：<?= htmlspecialchars($lastRunAt ?: 'N/A') ?>；
          结果：<?= $lastResult ? ('updated='.(int)($lastResult['updated']??0).', unknown='.(int)($lastResult['unknown']??0).', skipped='.(int)($lastResult['skipped']??0).', code='.(int)($lastResult['code']??0).', dry_run='.(int)(!empty($lastResult['dry_run'])?1:0)) : 'N/A' ?>
        </div>
        <div class="small muted mt6">
          当前默认设置：dry_run_default=
          <strong><?= (int)db_get_setting('stock_dry_run_default','0') ?></strong>，
          limit_default=
          <strong><?= (int)db_get_setting('stock_limit_default','0') ?></strong>
        </div>
      </section>

      <section class="mb24">
        <h3 class="mt6 mb8">服务器定时任务示例</h3>
        <form method="post" class="row gap12 items-end wrap mb8">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="stock_save_settings">
          <div>
            <label class="small">执行环境</label>
            <select name="stock_cron_mode" class="input">
              <option value="host" <?= $cronMode==='host'?'selected':'' ?>>主机 (直接调用 PHP)</option>
              <option value="docker" <?= $cronMode==='docker'?'selected':'' ?>>Docker 容器</option>
            </select>
          </div>
          <div>
            <label class="small">主机 PHP 路径（host 模式）</label>
            <input class="input" type="text" name="stock_php_path" value="<?= htmlspecialchars($phpPath) ?>" placeholder="/usr/bin/php">
          </div>
          <div>
            <label class="small">容器名（docker 模式）</label>
            <input class="input" type="text" name="stock_docker_container" value="<?= htmlspecialchars($dockerContainer) ?>" placeholder="PHP 容器名，如 PHP846">
          </div>
          <div>
            <label class="small">容器内站点路径</label>
            <input class="input" type="text" name="stock_container_base" value="<?= htmlspecialchars($containerBase) ?>" placeholder="/www/sites/vs.140581.xyz/index">
          </div>
          <div>
            <label class="small">docker 可执行路径（可选）</label>
            <input class="input" type="text" name="stock_docker_bin" value="<?= htmlspecialchars($dockerBin) ?>" placeholder="/usr/bin/docker 或 /usr/local/bin/docker">
          </div>
          <button class="btn" type="submit">保存执行环境</button>
        </form>
        <?php
          // Decide if backend can execute the command from this environment
          $disabledFns = ini_get('disable_functions');
          $disabledArr = is_string($disabledFns) ? array_map('trim', explode(',', $disabledFns)) : [];
          $procAllowed = function_exists('proc_open') && !in_array('proc_open', $disabledArr, true);
          $dockerUsableHere = @is_file($dockerBin) && @is_executable($dockerBin);
          $hostPhpUsable = @is_file($phpPath) && @is_executable($phpPath);
          $canRunHere = $procAllowed && (($cronMode==='docker' && $dockerUsableHere) || ($cronMode==='host' && $hostPhpUsable));
        ?>
        <?php if ($canRunHere): ?>
          <div class="row wrap items-center gap8 mt8 mb8">
            <button class="btn" id="btn-run-cron" type="button">立即执行一次（按当前执行环境）</button>
            <span class="small muted">用于验证 crontab/systemd 配置是否正确</span>
          </div>
        <?php else: ?>
          <div class="small muted mt8 mb8">
            当前运行环境不支持在后台直接执行系统命令（已禁用或缺少必要二进制）。请使用下方的 crontab/systemd 示例在服务器上配置自动执行；需要手动触发时，可使用上方的“同步库存”按钮。
          </div>
        <?php endif; ?>
        <div class="muted small mb6">crontab（每 <?= (int)$autoIntervalMin ?> 分钟）：</div>
        <pre class="pre-log"><?= htmlspecialchars($cronLine) ?></pre>
        <div class="muted small mt8 mb6">systemd 定时器：</div>
        <pre class="pre-log"><?= htmlspecialchars('[Unit]
Description=VPS Deals Stock Sync

[Service]
Type=oneshot
ExecStart='.$execCmd.'

[Install]
WantedBy=multi-user.target') ?></pre>
        <div class="muted small mt6">建议将日志输出到 <?= htmlspecialchars($logPath) ?>，确保目录可写。</div>
      </section>

      <?php
        // Stock logs pagination
        $logsPage = max(1, (int)($_GET['logs_page'] ?? 1));
        $logsPageSize = min(100, max(10, (int)($_GET['logs_page_size'] ?? 20)));
        $logsOffset = ($logsPage - 1) * $logsPageSize;
        $codeFilter = isset($_GET['logs_code']) ? (int)$_GET['logs_code'] : null;
        $from = isset($_GET['logs_from']) ? trim((string)$_GET['logs_from']) : '';
        $to = isset($_GET['logs_to']) ? trim((string)$_GET['logs_to']) : '';
        $conds = [];$params=[];
        if ($codeFilter !== null && ($codeFilter===0 || $codeFilter===400 || $codeFilter===500)) { $conds[]='code=:c'; $params[':c']=$codeFilter; }
        if ($from !== '') { $conds[]='run_at>=:f'; $params[':f']=$from; }
        if ($to !== '') { $conds[]='run_at<=:t'; $params[':t']=$to; }
        $where = $conds ? (' WHERE '.implode(' AND ',$conds)) : '';
        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM stock_logs' . $where);
        $stmtCount->execute($params);
        $totalLogs = (int)$stmtCount->fetchColumn();
        $stmtLogs = $pdo->prepare('SELECT * FROM stock_logs' . $where . ' ORDER BY run_at DESC LIMIT ' . (int)$logsPageSize . ' OFFSET ' . (int)$logsOffset);
        $stmtLogs->execute($params);
        $logs = $stmtLogs->fetchAll();
      ?>
      <section class="mb16">
        <h3 class="mt6 mb8">历史执行记录</h3>
        <form method="get" class="row gap8 items-start wrap mb8">
          <input type="hidden" name="tab" value="stock">
          <div>
            <label class="small">开始时间</label>
            <input class="input" type="datetime-local" name="logs_from" value="<?= htmlspecialchars($from) ?>">
          </div>
          <div>
            <label class="small">结束时间</label>
            <input class="input" type="datetime-local" name="logs_to" value="<?= htmlspecialchars($to) ?>">
          </div>
          <div>
            <label class="small">code</label>
            <select name="logs_code" class="input">
              <?php $codes=[''=>'全部','0'=>'0','400'=>'400','500'=>'500']; foreach($codes as $k=>$v): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= ((string)$codeFilter===(string)$k)?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="small">每页</label>
            <select name="logs_page_size" class="input">
              <?php foreach([20,50,100] as $opt): ?>
                <option value="<?= $opt ?>" <?= $logsPageSize===$opt?'selected':'' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row gap8 logs-actions">
            <button class="btn" type="submit">筛选</button>
            <button class="btn" type="submit" title="刷新列表">刷新</button>
            <a class="btn btn-secondary" href="?tab=stock">重置</a>
            <a class="btn" href="?tab=stock&export=stock_logs<?= $where?('&'.http_build_query(['logs_from'=>$from,'logs_to'=>$to,'logs_code'=>$codeFilter,'logs_page_size'=>$logsPageSize])):'' ?>">导出CSV</a>
          </div>
        </form>
        <table class="table">
          <thead>
            <tr><th>时间</th><th>code</th><th>updated</th><th>unknown</th><th>skipped</th><th>耗时(ms)</th><th>消息</th></tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $lg): ?>
              <tr>
                <td><?= htmlspecialchars($lg['run_at']) ?></td>
                <td><?= (int)$lg['code'] ?></td>
                <td><?= (int)$lg['updated'] ?></td>
                <td><?= (int)$lg['unknown'] ?></td>
                <td><?= (int)$lg['skipped'] ?></td>
                <td><?= (int)($lg['duration_ms'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string)($lg['message'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php
          $logsTotalPages = (int)ceil($totalLogs / $logsPageSize);
          render_pagination([
            'page' => $logsPage,
            'total_pages' => $logsTotalPages,
            'total_items' => $totalLogs,
            'base_query' => array_merge($_GET, ['tab'=>'stock']),
            'page_param' => 'logs_page',
            'window' => 2,
            'per_page_options' => [20,50,100],
            'per_page_param' => 'logs_page_size',
            'per_page_value' => $logsPageSize,
            'align' => 'flex-end',
          ]);
        ?>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'data'): ?>
      <section class="mb16">
        <h2 class="mt6 mb12">导出数据</h2>
        <div class="row wrap gap8">
          <a class="btn" href="?tab=data&download=vendors_json">导出厂商 JSON</a>
          <a class="btn" href="?tab=data&download=vendors_csv">导出厂商 CSV</a>
          <a class="btn" href="?tab=data&download=plans_json">导出套餐 JSON</a>
          <a class="btn" href="?tab=data&download=plans_csv">导出套餐 CSV</a>
        </div>
        <div class="muted small mt6">
          JSON 为结构化字段；CSV 中 `features` 将以分号分隔。
        </div>
      </section>

      <section class="mb16">
        <h2 class="mt6 mb12">导入厂商</h2>
        <form method="post" enctype="multipart/form-data" data-confirm="确认导入厂商数据？" class="maxw640">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="import_vendors">
          <div>
            <input class="input" type="file" name="file" accept=".json,.csv" required>
          </div>
          <div class="row wrap items-center gap12 mt8">
            <label class="row items-center gap6">
              <input type="radio" name="import_mode" value="append" checked>
              追加/更新（存在同名时更新，否则新增）
            </label>
            <label class="row items-center gap6">
              <input type="radio" name="import_mode" value="overwrite">
              覆盖（清空厂商与关联套餐后再导入）
            </label>
          </div>
          <div class="muted small mt6">
            支持 JSON（{vendors:[...]} 或数组）与 CSV（表头含 name, website, logo_url, description，可选 id）。
          </div>
          <div class="mt10"><button class="btn" type="submit">开始导入</button></div>
        </form>
      </section>

      <section>
        <h2 class="mt6 mb12">导入套餐</h2>
        <form method="post" enctype="multipart/form-data" data-confirm="确认导入套餐数据？" class="maxw640">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="import_plans">
          <div>
            <input class="input" type="file" name="file" accept=".json,.csv" required>
          </div>
          <div class="row wrap items-center gap12 mt8">
            <label class="row items-center gap6">
              <input type="radio" name="import_mode" value="append" checked>
              追加/更新（存在同 ID 更新；无 ID 则新增）
            </label>
            <label class="row items-center gap6">
              <input type="radio" name="import_mode" value="overwrite">
              覆盖（清空所有套餐后再导入）
            </label>
          </div>
          <div class="muted small mt6">
            支持 JSON（{plans:[...]} 或数组）与 CSV。字段：vendor_id 或 vendor_name，title，price，price_duration（per month/per year/one-time），order_url，location，features（分号或换行分隔），cpu，ram，storage，cpu_cores，ram_mb，storage_gb，highlights，sort_order（可选 id 表示更新）。
          </div>
          <div class="mt10"><button class="btn" type="submit">开始导入</button></div>
        </form>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>
