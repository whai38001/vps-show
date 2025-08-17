<?php
require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/headers.php';
require_once __DIR__ . '/../lib/footer.php';
send_common_security_headers();
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>隐私保护 - <?= htmlspecialchars(SITE_NAME) ?></title>
<link rel="stylesheet" href="../assets/style.css">
<script defer src="../assets/app.js"></script>
</head>
<body>
  <div class="container">
    <nav class="topnav">
      <div class="nav-left">
        <a class="brand-link" href="/"><?= htmlspecialchars(t('home')) ?></a>
        <a href="https://www.itdianbao.com" target="_blank" rel="noopener"><?= htmlspecialchars(t('blog')) ?></a>
        <a href="/pages/disclaimer.php"><?= htmlspecialchars(t('disclaimer')) ?></a>
        <a href="/pages/privacy.php" class="active"><?= htmlspecialchars(t('privacy')) ?></a>
        <a href="/pages/about.php"><?= htmlspecialchars(t('about')) ?></a>
      </div>
      <div class="nav-right">
        <a class="btn" href="<?= i18n_build_lang_url('zh') ?>"><?= htmlspecialchars(t('lang_zh')) ?></a>
        <a class="btn" href="<?= i18n_build_lang_url('en') ?>">EN</a>
        <button class="btn btn-secondary btn-small" id="theme-toggle" type="button" aria-label="Toggle theme">切换主题</button>
        <a class="btn btn-secondary" href="/admin/">&nbsp;<?= htmlspecialchars(t('admin_panel')) ?></a>
      </div>
    </nav>
  </div>
  <div class="container page">
    <section class="hero">
      <h1>隐私保护 <span class="badge">Privacy</span></h1>
      <div class="desc">我们重视您的隐私与数据安全。</div>
      <div class="row items-center gap8 mt10">
        <button class="btn btn-small btn-secondary" type="button" data-brand="theme-link" aria-label="配色：Link">Link</button>
        <button class="btn btn-small btn-secondary" type="button" data-brand="theme-ocean" aria-label="配色：Ocean">Ocean</button>
      </div>
    </section>

    <div class="prose">
      <div class="toc">
        <strong>目录</strong>
        <ul>
          <li><a href="#collect">我们收集什么</a></li>
          <li><a href="#use">我们如何使用</a></li>
          <li><a href="#third">第三方与外部链接</a></li>
          <li><a href="#security">数据安全</a></li>
          <li><a href="#contact">联系我们</a></li>
        </ul>
      </div>
      <p>本站尊重并保护用户隐私。我们不主动收集可识别个人身份的信息。服务器可能记录基础访问日志（如 IP、User-Agent）用于安全审计与性能分析，日志仅用于站点运维，不用于商业目的。</p>

      <h2 id="collect">我们收集什么</h2>
      <ul>
        <li>访问日志：IP、User-Agent、访问时间与路径等，用于故障排查与安全审计。</li>
        <li>Cookies：用于保存语言偏好（<code>lang</code>）及后台登录会话。</li>
      </ul>

      <h2 id="use">我们如何使用</h2>
      <ul>
        <li>改进站点可用性与性能，定位问题。</li>
        <li>保障账户与数据安全，例如登录状态的维持与 CSRF 防护。</li>
      </ul>

      <h2 id="third">第三方与外部链接</h2>
      <p>访问第三方网站将适用其隐私政策，请自行查阅。我们不对第三方网站的隐私实践负责。</p>

      <h2 id="security">数据安全</h2>
      <p>我们采取合理的技术与管理措施保护站点安全，但互联网环境下无法保证绝对安全。</p>

      <h2 id="contact">联系我们</h2>
      <p>如需删除数据或提出隐私相关请求，请与站长联系。</p>
    </div>
  </div>
  <?php render_site_footer(); ?>
</body>
</html>
