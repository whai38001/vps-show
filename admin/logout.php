<?php
require_once __DIR__ . '/../lib/auth.php';

auth_logout();
// Always redirect to a fixed absolute admin login path (avoid admin/admin)
header('Location: /admin/login.php');
exit;
