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
$res = stock_sync_run($pdo);
$now = date('Y-m-d H:i:s');
$data = $res['data'] ?? [];
// record last run
db_set_setting('stock_last_run_at', $now);
db_set_setting('stock_last_result', json_encode(($data + ['code'=>$res['code'] ?? 0]), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$line = sprintf('[%s] code=%d updated=%d unknown=%d skipped=%d msg=%s',
    $now,
    (int)($res['code'] ?? 0),
    (int)($data['updated'] ?? 0),
    (int)($data['unknown'] ?? 0),
    (int)($data['skipped'] ?? 0),
    (string)($res['message'] ?? '')
);
@error_log($line);
echo $line, "\n";
