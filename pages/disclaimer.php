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
<title>免责声明 - <?= htmlspecialchars(SITE_NAME) ?></title>
<link rel="stylesheet" href="../assets/style.css">
<script defer src="../assets/app.js"></script>
</head>
<body>
  <div class="container">
    <nav class="topnav">
      <div class="nav-left">
        <a class="brand-link" href="/"><?= htmlspecialchars(t('home')) ?></a>
        <a href="https://www.itdianbao.com" target="_blank" rel="noopener"><?= htmlspecialchars(t('blog')) ?></a>
        <a href="/pages/disclaimer.php" class="active"><?= htmlspecialchars(t('disclaimer')) ?></a>
        <a href="/pages/privacy.php"><?= htmlspecialchars(t('privacy')) ?></a>
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
      <h1>免责声明 <span class="badge">Notice</span></h1>
      <div class="desc">使用本站信息前，请您仔细阅读本声明。</div>
      <div class="row items-center gap8 mt10">
        <button class="btn btn-small btn-secondary" type="button" data-brand="theme-link" aria-label="配色：Link">Link</button>
        <button class="btn btn-small btn-secondary" type="button" data-brand="theme-ocean" aria-label="配色：Ocean">Ocean</button>
      </div>
    </section>

    <div class="prose">
      <div class="toc">
        <strong>目录</strong>
        <ul>
          <li><a href="#accuracy">信息准确性</a></li>
          <li><a href="#links">第三方链接</a></li>
          <li><a href="#liability">责任限制</a></li>
          <li><a href="#copyright">版权与侵权处理</a></li>
        </ul>
      </div>
      <p>本网站展示的 VPS 套餐信息来源于第三方厂商或用户投稿，仅用于信息分享与学习研究，不构成投资或购买建议。请您在下单前自行核实产品信息、价格、条款与服务质量，风险自负。</p>

      <h2 id="accuracy">信息准确性</h2>
      <p>我们会尽力保证信息的准确与及时，但不对信息的完整性、实时性与可用性做出任何承诺。价格、库存、配置等可能随厂商活动变动。</p>

      <h2 id="links">第三方链接</h2>
      <p>本站包含指向第三方网站的链接，该等网站的内容与服务由其各自运营方负责，与本站无关。访问第三方网站将适用其自身的条款与隐私政策。</p>

      <h2 id="liability">责任限制</h2>
      <p>因使用或依赖本站信息所导致的任何直接或间接损失，本站不承担任何责任。您应根据自身情况谨慎判断并承担相应风险。</p>

      <h2 id="copyright">版权与侵权处理</h2>
      <p>若您认为本站内容侵犯了您的合法权益，请通过联系方式与我们沟通。我们将在核实后采取删除、屏蔽或更正等措施。</p>

      <blockquote class="muted">提示：优惠活动具有时效性，建议以厂商页面为准。</blockquote>
    </div>
  </div>
  <?php render_site_footer(); ?>
</body>
</html>
