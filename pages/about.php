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
<title>关于 - <?= htmlspecialchars(SITE_NAME) ?></title>
<link rel="stylesheet" href="../assets/style.css">
<script defer src="../assets/app.js"></script>
</head>
<body>
  <div class="container">
    <nav class="topnav">
      <div class="nav-left">
        <a class="brand-link" href="/">首页</a>
        <a href="https://www.itdianbao.com" target="_blank" rel="noopener">博客</a>
        <a href="/pages/disclaimer.php">免责声明</a>
        <a href="/pages/privacy.php">隐私保护</a>
        <a href="/pages/about.php" class="active">关于</a>
      </div>
      <div class="nav-right">
        <a class="btn" href="<?= i18n_build_lang_url('zh') ?>"><?= htmlspecialchars(t('lang_zh')) ?></a>
        <a class="btn" href="<?= i18n_build_lang_url('en') ?>">EN</a>
        <a class="btn btn-secondary" href="/admin/">&nbsp;<?= htmlspecialchars(t('admin_panel')) ?></a>
      </div>
    </nav>
  </div>
  <div class="container page">
    <section class="hero">
      <h1>关于本站 <span class="badge">About</span></h1>
      <div class="desc">聚合 VPS 套餐，帮你更快做出更优选择。</div>
    </section>

    <div class="prose">
      <div class="toc">
        <strong>目录</strong>
        <ul>
          <li><a href="#features">功能亮点</a></li>
          <li><a href="#roadmap">路线图</a></li>
          <li><a href="#contact">联系</a></li>
        </ul>
      </div>

      <p>本站旨在聚合与展示各 VPS 厂商的优惠套餐，帮助用户快速对比配置与价格，做出更高性价比的选择。后台支持对套餐的增删改查，以及从部分厂商页面导入信息（尽力解析）。</p>

      <h2 id="features">功能亮点</h2>
      <ul>
        <li><strong>开源与自建</strong>：轻量级 PHP 应用，可在低资源服务器上运行。</li>
        <li><strong>多语言</strong>：支持中英文界面，套餐文案提供术语表级别的中文规范化。</li>
        <li><strong>API</strong>：提供只读 API，用于聚合展示与应用集成。</li>
      </ul>

      <h2 id="roadmap">路线图</h2>
      <ul>
        <li>更多厂商的定制化导入解析与去重。</li>
        <li>更强的筛选维度与排序（如 CPU/内存/硬盘权重）。</li>
        <li>收藏/对比功能与订阅推送。</li>
      </ul>

      <h2 id="contact">联系</h2>
      <p>欢迎提出建议或反馈问题，我们会持续改进体验。</p>
    </div>
  </div>
  <?php render_site_footer(); ?>
</body>
</html>
