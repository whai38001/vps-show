<?php
require_once __DIR__ . '/config.php';

function send_common_security_headers(): void {
    if (headers_sent()) { return; }
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    // A relaxed CSP to avoid breaking external images and inline styles used by the site
    // Adjust as needed in production
    $csp = "default-src 'self'; img-src * data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'";
    header('Content-Security-Policy: ' . $csp);
}
