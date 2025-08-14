<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/headers.php';

auth_start_session();
send_common_security_headers();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if (!auth_validate_csrf_token($_POST['_csrf'] ?? null)) {
        $error = '会话已过期，请刷新页面重试';
    } elseif (auth_login($username, $password)) {
        $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : (rtrim(BASE_URL, '/') . '/');
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = '用户名或密码错误';
    }
}
$csrf = auth_get_csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>登录 - <?= htmlspecialchars(SITE_NAME) ?></title>
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
  <div class="container maxw420">
    <div class="header">
      <div class="brand">
        <img src="../assets/emoji/key.svg" alt="login" width="36" height="36">
        <h1>管理员登录</h1>
      </div>
      <div><a class="btn" href="../">返回首页</a></div>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div>
        <label>用户名</label>
        <input class="input" type="text" name="username" autofocus required>
      </div>
      <div class="mt10">
        <label>密码</label>
        <input class="input" type="password" name="password" required>
      </div>
      <div class="mt12">
        <button class="btn" type="submit">登录</button>
      </div>
    </form>
  </div>
</body>
</html>
