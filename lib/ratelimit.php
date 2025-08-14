<?php
require_once __DIR__ . '/config.php';

/**
 * Very simple file-based rate limiter per key.
 * @param string $key Unique key (e.g., client IP)
 * @param int $maxRequests Max requests allowed in the window
 * @param int $windowSec Window size in seconds
 * @return bool true if allowed, false if limited
 */
function rate_limit_allow(string $key, int $maxRequests, int $windowSec): bool {
    $dir = dirname(__DIR__) . '/tmp/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $safeKey = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $key);
    $file = $dir . '/' . $safeKey . '.json';
    $now = time();

    $data = ['start' => $now, 'count' => 0];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $tmp = json_decode($raw, true);
            if (is_array($tmp) && isset($tmp['start'], $tmp['count'])) {
                $data = $tmp;
            }
        }
    }

    if (!is_int($data['start']) || !is_int($data['count'])) {
        $data = ['start' => $now, 'count' => 0];
    }

    if (($now - (int)$data['start']) >= $windowSec) {
        $data['start'] = $now;
        $data['count'] = 0;
    }

    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    return $data['count'] <= $maxRequests;
}
