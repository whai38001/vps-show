<?php
require_once __DIR__ . '/config.php';

function send_common_security_headers(): void {
    if (headers_sent()) { return; }
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    // CSP: inline styles removed; tighten policy
    $csp = "default-src 'self'; img-src * data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'self'";
    header('Content-Security-Policy: ' . $csp);
    // HSTS for HTTPS sites
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains'); // 180 days
    }
}
