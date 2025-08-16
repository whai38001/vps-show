<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/stock.php';
require_once __DIR__ . '/../lib/config.php';

// CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

db_init_schema();
$pdo = get_pdo();
// Read default flags for cron runs
$dryDefault = (int)db_get_setting('stock_dry_run_default', '0') ? true : false;
$limitDefault = max(0, (int)db_get_setting('stock_limit_default', '0'));
$opts = [];
if ($dryDefault) { $opts['dry_run'] = true; }
if ($limitDefault > 0) { $opts['limit'] = $limitDefault; }
$res = stock_sync_run($pdo, $opts);
$now = date('Y-m-d H:i:s');
$data = $res['data'] ?? [];
// record last run
db_set_setting('stock_last_run_at', $now);
db_set_setting('stock_last_result', json_encode(($data + ['code'=>$res['code'] ?? 0]), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$line = sprintf('[%s] code=%d updated=%d unknown=%d skipped=%d dry_run=%s msg=%s',
    $now,
    (int)($res['code'] ?? 0),
    (int)($data['updated'] ?? 0),
    (int)($data['unknown'] ?? 0),
    (int)($data['skipped'] ?? 0),
    !empty($data['dry_run']) ? '1' : '0',
    (string)($res['message'] ?? '')
);
@error_log($line);
echo $line, "\n";
