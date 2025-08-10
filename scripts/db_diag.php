<?php
// Quick DB diagnostics: try TCP hosts and optional socket, print results
require_once __DIR__ . '/../lib/config.php';

header('Content-Type: text/plain; charset=utf-8');

$hosts = DB_HOSTS;
$port = DB_PORT;
$db = DB_NAME;
$user = DB_USER;
$socket = defined('DB_SOCKET') ? DB_SOCKET : '';

printf("DB_NAME=%s\nDB_USER=%s\nDB_PORT=%d\nDB_HOSTS=%s\nDB_SOCKET=%s\n\n", $db, $user, $port, implode(',', $hosts), $socket);

$okAny = false;
foreach ($hosts as $h) {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $h, $port, $db, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, $user, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->query('SELECT 1');
        echo "TCP $h:$port -> OK\n";
        $okAny = true;
    } catch (Throwable $e) {
        echo "TCP $h:$port -> FAIL: " . $e->getMessage() . "\n";
    }
}

if ($socket !== '') {
    try {
        $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $db, DB_CHARSET);
        $pdo = new PDO($dsn, $user, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->query('SELECT 1');
        echo "SOCKET $socket -> OK\n";
        $okAny = true;
    } catch (Throwable $e) {
        echo "SOCKET $socket -> FAIL: " . $e->getMessage() . "\n";
    }
}

if (!$okAny) {
    echo "\nNo successful connections. Check service status, firewall, credentials, or set DB_SOCKET.\n";
}
