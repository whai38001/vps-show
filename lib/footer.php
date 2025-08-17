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
          <div class="muted small"><?= htmlspecialchars(t('footer_tagline')) ?></div>
        </div>
        <div class="footer-links">
          <a href="/pages/disclaimer.php"><?= htmlspecialchars(t('disclaimer')) ?></a>
          <span class="dot" aria-hidden="true"></span>
          <a href="/pages/privacy.php"><?= htmlspecialchars(t('privacy')) ?></a>
          <span class="dot" aria-hidden="true"></span>
          <a href="/pages/about.php"><?= htmlspecialchars(t('about')) ?></a>
        </div>
      </div>
    </footer>
    <div id="back-to-top"><button class="btn btn-secondary" type="button" aria-label="<?= htmlspecialchars(t('back_to_top')) ?>" title="<?= htmlspecialchars(t('back_to_top')) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M12 5l-7 7h4v7h6v-7h4l-7-7z"/>
      </svg>
    </button></div>
    <?php
}
