<?php
require_once __DIR__ . '/config.php';

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $lastException = null;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET,
    ];

    // Try TCP hosts first
    foreach (DB_HOSTS as $host) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, DB_PORT, DB_NAME, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            return $pdo;
        } catch (Throwable $e) {
            $lastException = $e;
            // Log minimal details for diagnosis (no credentials)
            @error_log('[DB] TCP connect failed host=' . $host . ' port=' . DB_PORT . ' err=' . $e->getMessage());
        }
    }

    // Fallback to UNIX socket if provided
    if (defined('DB_SOCKET') && DB_SOCKET !== '') {
        try {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', DB_SOCKET, DB_NAME, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            return $pdo;
        } catch (Throwable $e) {
            $lastException = $e;
            @error_log('[DB] SOCKET connect failed socket=' . DB_SOCKET . ' err=' . $e->getMessage());
        }
    }

    // If API expects JSON, return a structured error
    if (defined('EXPECT_JSON') && EXPECT_JSON === true) {
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            if (defined('CORS_ALLOW_ORIGIN')) {
                header('Access-Control-Allow-Origin: ' . CORS_ALLOW_ORIGIN);
            }
        }
        echo json_encode(['code'=>500,'message'=>'Database connection failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Database connection failed.\n";
        if ($lastException) {
            echo $lastException->getMessage();
        }
        exit;
    }
}

function db_init_schema(): void {
    $pdo = get_pdo();

    $pdo->exec("CREATE TABLE IF NOT EXISTS vendors (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(191) NOT NULL,
        website VARCHAR(255) NULL,
        logo_url VARCHAR(255) NULL,
        description TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS plans (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT UNSIGNED NOT NULL,
        title VARCHAR(191) NOT NULL,
        subtitle VARCHAR(191) NULL,
        price DECIMAL(10,2) NOT NULL,
        price_duration ENUM('per month', 'per year', 'one-time') DEFAULT 'per year',
        order_url VARCHAR(255) NULL,
        location VARCHAR(255) NULL,
        features JSON NULL,
        cpu VARCHAR(191) NULL,
        ram VARCHAR(191) NULL,
        storage VARCHAR(191) NULL,
        cpu_cores DECIMAL(5,2) NULL,
        ram_mb INT NULL,
        storage_gb INT NULL,
        highlights VARCHAR(191) NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
        INDEX idx_vendor (vendor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Migrations: add columns if missing (for existing installations)
    try {
        $dbName = DB_NAME;
        $cols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'plans'");
        $cols->execute([':db' => $dbName]);
        $existing = array_fill_keys(array_map(function($r){ return (string)$r['COLUMN_NAME']; }, $cols->fetchAll()), true);
        $alters = [];
        if (!isset($existing['cpu'])) { $alters[] = 'ADD COLUMN cpu VARCHAR(191) NULL'; }
        if (!isset($existing['ram'])) { $alters[] = 'ADD COLUMN ram VARCHAR(191) NULL'; }
        if (!isset($existing['storage'])) { $alters[] = 'ADD COLUMN storage VARCHAR(191) NULL'; }
        if (!isset($existing['cpu_cores'])) { $alters[] = 'ADD COLUMN cpu_cores DECIMAL(5,2) NULL'; }
        if (!isset($existing['ram_mb'])) { $alters[] = 'ADD COLUMN ram_mb INT NULL'; }
        if (!isset($existing['storage_gb'])) { $alters[] = 'ADD COLUMN storage_gb INT NULL'; }
        if ($alters) {
            $pdo->exec('ALTER TABLE plans ' . implode(', ', $alters));
        }
    } catch (Throwable $e) {
        @error_log('[DB] schema migrate check failed: ' . $e->getMessage());
    }

    // Key-Value settings table for site/admin configuration
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(191) NOT NULL PRIMARY KEY,
        v TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

/**
 * Read a string setting value. Returns $default if not found.
 */
function db_get_setting(string $key, ?string $default = null): ?string {
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT v FROM settings WHERE k = :k');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    if ($row && array_key_exists('v', $row)) {
        return is_string($row['v']) ? $row['v'] : (string)$row['v'];
    }
    return $default;
}

/**
 * Upsert a string setting value.
 */
function db_set_setting(string $key, string $value): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare('INSERT INTO settings (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $stmt->execute([':k' => $key, ':v' => $value]);
}

/**
 * Get full setting row including updated_at
 * Returns ['v' => string|null, 'updated_at' => string] or null when absent
 */
function db_get_setting_row(string $key): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT v, updated_at FROM settings WHERE k = :k');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    return $row ?: null;
}
