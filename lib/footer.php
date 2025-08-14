<?php
require_once __DIR__ . '/i18n.php';

function render_site_footer(): void {
    $year = (int)date('Y');
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'VPS Deals';
    ?>
    <footer class="site-footer">
      <div class="container footer-inner">
        <div class="footer-left">
          <div class="brand">© <?= $year ?> <?= htmlspecialchars($siteName) ?></div>
          <div class="muted small">聚合 VPS 套餐信息，帮助快速对比选择。</div>
        </div>
        <div class="footer-links">
          <a href="/pages/disclaimer.php">免责声明</a>
          <span class="dot" aria-hidden="true"></span>
          <a href="/pages/privacy.php">隐私保护</a>
          <span class="dot" aria-hidden="true"></span>
          <a href="/pages/about.php">关于</a>
        </div>
      </div>
    </footer>
    <?php
}
