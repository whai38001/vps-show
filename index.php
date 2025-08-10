<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/headers.php';
require_once __DIR__ . '/lib/pagination.php';
require_once __DIR__ . '/lib/footer.php';

db_init_schema();
$pdo = get_pdo();
$htmlLang = (i18n_current_lang() === 'en') ? 'en' : 'zh-CN';

// Security headers
send_common_security_headers();

// Search & filter
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$vendorId = isset($_GET['vendor']) ? (int)$_GET['vendor'] : 0;
$billing = isset($_GET['billing']) ? trim($_GET['billing']) : '';
$minPrice = isset($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
$maxPrice = isset($_GET['max_price']) ? (float)$_GET['max_price'] : 0;
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$minCpu = isset($_GET['min_cpu']) ? (float)$_GET['min_cpu'] : 0;
$minRamGb = isset($_GET['min_ram_gb']) ? (float)$_GET['min_ram_gb'] : 0;
$minStorageGb = isset($_GET['min_storage_gb']) ? (int)$_GET['min_storage_gb'] : 0;

$vendors = $pdo->query('SELECT id, name, logo_url, website FROM vendors ORDER BY name ASC')->fetchAll();
// Distinct non-empty locations for quick filter
$rawLocations = $pdo->query("SELECT DISTINCT location FROM plans WHERE location IS NOT NULL AND location <> '' ORDER BY location ASC")->fetchAll();
$locationsDistinct = [];
foreach ($rawLocations as $row) {
    $loc = trim((string)$row['location']);
    if ($loc !== '' && !in_array($loc, $locationsDistinct, true)) { $locationsDistinct[] = $loc; }
}

// Sorting & pagination
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'default';
$allowedSort = [
  'default' => 'p.sort_order ASC, p.id DESC',
  'price_asc' => 'p.price ASC',
  'price_desc' => 'p.price DESC',
  'newest' => 'p.id DESC',
  // Emulate NULLS LAST/FIRST for MySQL
  'cpu_desc' => 'p.cpu_cores IS NULL ASC, p.cpu_cores DESC, p.id DESC',
  'cpu_asc' => 'p.cpu_cores IS NULL ASC, p.cpu_cores ASC, p.id DESC',
  'ram_desc' => 'p.ram_mb IS NULL ASC, p.ram_mb DESC, p.id DESC',
  'ram_asc' => 'p.ram_mb IS NULL ASC, p.ram_mb ASC, p.id DESC',
  'storage_desc' => 'p.storage_gb IS NULL ASC, p.storage_gb DESC, p.id DESC',
  'storage_asc' => 'p.storage_gb IS NULL ASC, p.storage_gb ASC, p.id DESC',
];
$orderBy = $allowedSort[$sort] ?? $allowedSort['default'];

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(48, max(6, (int)($_GET['page_size'] ?? 12)));
$offset = ($page - 1) * $pageSize;

$sql = 'SELECT p.*, v.name AS vendor_name, v.logo_url, v.website FROM plans p INNER JOIN vendors v ON v.id = p.vendor_id';
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.title LIKE :kw OR p.subtitle LIKE :kw OR v.name LIKE :kw)';
    $params[':kw'] = "%$q%";
}
if ($vendorId > 0) {
    $where[] = 'p.vendor_id = :vendor_id';
    $params[':vendor_id'] = $vendorId;
}
if ($billing !== '' && in_array($billing, ['per month','per year','one-time'], true)) {
    $where[] = 'p.price_duration = :billing';
    $params[':billing'] = $billing;
}
if ($minPrice > 0) {
    $where[] = 'p.price >= :min_price';
    $params[':min_price'] = $minPrice;
}
if ($maxPrice > 0 && ($minPrice === 0 || $maxPrice >= $minPrice)) {
    $where[] = 'p.price <= :max_price';
    $params[':max_price'] = $maxPrice;
}
if ($location !== '') {
    $where[] = 'p.location LIKE :loc';
    $params[':loc'] = "%$location%";
}
if ($minCpu > 0) {
    $where[] = 'p.cpu_cores IS NOT NULL AND p.cpu_cores >= :min_cpu';
    $params[':min_cpu'] = $minCpu;
}
if ($minRamGb > 0) {
    $where[] = 'p.ram_mb IS NOT NULL AND p.ram_mb >= :min_ram_mb';
    $params[':min_ram_mb'] = (int)round($minRamGb * 1024);
}
if ($minStorageGb > 0) {
    $where[] = 'p.storage_gb IS NOT NULL AND p.storage_gb >= :min_storage_gb';
    $params[':min_storage_gb'] = $minStorageGb;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sqlCount = 'SELECT COUNT(*) FROM plans p INNER JOIN vendors v ON v.id = p.vendor_id' . ($where ? (' WHERE ' . implode(' AND ', $where)) : '');
$countStmt = $pdo->prepare($sqlCount);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql .= ' ORDER BY ' . $orderBy . ' LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$plans = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars(t('site_title')) ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <script defer src="assets/app.js"></script>
</head>
<body>
  <div class="container">
    <nav class="topnav">
      <div class="nav-left">
        <a class="brand-link active" href="/">首页</a>
        <a href="https://www.itdianbao.com" target="_blank" rel="noopener">博客</a>
        <a href="/pages/disclaimer.php">免责声明</a>
        <a href="/pages/privacy.php">隐私保护</a>
        <a href="/pages/about.php">关于</a>
      </div>
      <div class="nav-right">
        <a class="btn" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['lang'=>'zh']))) ?>"><?= htmlspecialchars(t('lang_zh')) ?></a>
        <a class="btn" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['lang'=>'en']))) ?>">EN</a>
        <a class="btn btn-secondary" href="admin/"><?= htmlspecialchars(t('admin_panel')) ?></a>
      </div>
    </nav>
    <header class="header">
      <div class="brand">
        <img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/1f680.png" alt="logo">
        <h1><?= htmlspecialchars(t('site_title')) ?></h1>
      </div>
      <form class="search" method="get">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars(t('search_placeholder')) ?>">
      </form>
    </header>

    <div class="filters">
      <form method="get">
        <select name="vendor" onchange="this.form.submit()">
          <option value="0"><?= htmlspecialchars(t('filters_all_vendors')) ?></option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= $vendorId===(int)$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="billing" onchange="this.form.submit()">
          <option value=""><?= htmlspecialchars(t('filters_all_billing')) ?></option>
          <?php foreach (['per month'=>t('billing_per_month'),'per year'=>t('billing_per_year'),'one-time'=>t('billing_one_time')] as $k=>$label): ?>
            <option value="<?= $k ?>" <?= $billing===$k?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="sort" onchange="this.form.submit()">
          <option value="default" <?= $sort==='default'?'selected':'' ?>><?= htmlspecialchars(t('sort_default')) ?></option>
          <option value="price_asc" <?= $sort==='price_asc'?'selected':'' ?>><?= htmlspecialchars(t('sort_price_asc')) ?></option>
          <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>><?= htmlspecialchars(t('sort_price_desc')) ?></option>
          <option value="newest" <?= $sort==='newest'?'selected':'' ?>><?= htmlspecialchars(t('sort_newest')) ?></option>
          <option value="cpu_desc" <?= $sort==='cpu_desc'?'selected':'' ?>><?= htmlspecialchars(t('sort_cpu_desc')) ?></option>
          <option value="cpu_asc" <?= $sort==='cpu_asc'?'selected':'' ?>><?= htmlspecialchars(t('sort_cpu_asc')) ?></option>
          <option value="ram_desc" <?= $sort==='ram_desc'?'selected':'' ?>><?= htmlspecialchars(t('sort_ram_desc')) ?></option>
          <option value="ram_asc" <?= $sort==='ram_asc'?'selected':'' ?>><?= htmlspecialchars(t('sort_ram_asc')) ?></option>
          <option value="storage_desc" <?= $sort==='storage_desc'?'selected':'' ?>><?= htmlspecialchars(t('sort_storage_desc')) ?></option>
          <option value="storage_asc" <?= $sort==='storage_asc'?'selected':'' ?>><?= htmlspecialchars(t('sort_storage_asc')) ?></option>
        </select>
        <input class="input" type="number" name="min_price" step="0.01" placeholder="<?= htmlspecialchars(t('input_min')) ?>" value="<?= $minPrice>0?htmlspecialchars($minPrice):'' ?>">
        <input class="input" type="number" name="max_price" step="0.01" placeholder="<?= htmlspecialchars(t('input_max')) ?>" value="<?= $maxPrice>0?htmlspecialchars($maxPrice):'' ?>">
        <input class="input" type="number" name="min_cpu" step="0.1" placeholder="<?= htmlspecialchars(t('input_min_cpu')) ?>" value="<?= isset($_GET['min_cpu']) && (float)$_GET['min_cpu']>0 ? htmlspecialchars((string)(float)$_GET['min_cpu']) : '' ?>">
        <input class="input" type="number" name="min_ram_gb" step="0.5" placeholder="<?= htmlspecialchars(t('input_min_ram_gb')) ?>" value="<?= isset($_GET['min_ram_gb']) && (float)$_GET['min_ram_gb']>0 ? htmlspecialchars((string)(float)$_GET['min_ram_gb']) : '' ?>">
        <input class="input" type="number" name="min_storage_gb" step="1" placeholder="<?= htmlspecialchars(t('input_min_storage_gb')) ?>" value="<?= isset($_GET['min_storage_gb']) && (int)$_GET['min_storage_gb']>0 ? htmlspecialchars((string)(int)$_GET['min_storage_gb']) : '' ?>">
        <select name="location" onchange="this.form.submit()">
          <option value=""><?= htmlspecialchars(t('label_location')) ?></option>
          <?php foreach ($locationsDistinct as $loc): ?>
            <option value="<?= htmlspecialchars($loc) ?>" <?= $location===$loc?'selected':'' ?>><?= htmlspecialchars(i18n_text($loc)) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="page_size" onchange="this.form.submit()">
          <?php foreach ([12,24,36,48] as $opt): ?>
            <option value="<?= $opt ?>" <?= $pageSize===$opt?'selected':'' ?>><?= htmlspecialchars(t('per_page')) ?> <?= $opt ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= htmlspecialchars(t('filter_button')) ?></button>
        <?php $resetHref = '?' . http_build_query(['lang'=>i18n_current_lang()]); ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($resetHref) ?>"><?= htmlspecialchars(t('reset')) ?></a>
        <?php if ($q !== ''): ?><input class="input" type="hidden" name="q" value="<?= htmlspecialchars($q) ?>"><?php endif; ?>
      </form>
    </div>

    <main class="grid">
      <?php foreach ($plans as $plan): ?>
        <article class="card" data-plan-id="<?= (int)$plan['id'] ?>">
          <?php if (!empty($plan['highlights'])): ?>
            <div class="ribbon"><?= htmlspecialchars(i18n_text((string)$plan['highlights'])) ?></div>
          <?php endif; ?>
          <div class="card-header">
            <img src="<?= htmlspecialchars($plan['logo_url'] ?: 'https://via.placeholder.com/72x72?text=VPS') ?>" alt="<?= htmlspecialchars($plan['vendor_name']) ?>" loading="lazy" width="36" height="36">
            <div class="title">
              <div class="size"><?= htmlspecialchars(i18n_text((string)$plan['title'])) ?></div>
              <div class="sub"><?= htmlspecialchars(i18n_text((string)($plan['subtitle'] ?: 'VPS'))) ?> · <?= htmlspecialchars($plan['vendor_name']) ?></div>
            </div>
          </div>
          <div class="card-body">
            <?php if (!empty($plan['location'])): ?>
              <div class="meta">📍 <?= htmlspecialchars(i18n_text((string)$plan['location'])) ?></div>
            <?php endif; ?>
            <div class="price">
              <span class="amount">$<?= number_format((float)$plan['price'], 2) ?></span>
              <span class="duration"><?= htmlspecialchars(i18n_duration_label($plan['price_duration'])) ?></span>
            </div>
            <?php
              $cpuCores = isset($plan['cpu_cores']) ? (float)$plan['cpu_cores'] : 0.0;
              if ($cpuCores <= 0 && !empty($plan['cpu']) && preg_match('/([0-9]+(?:\.[0-9]+)?)\s*v?CPU/i', (string)$plan['cpu'], $m)) { $cpuCores = (float)$m[1]; }
              $ramMB = isset($plan['ram_mb']) ? (int)$plan['ram_mb'] : 0;
              if ($ramMB <= 0 && !empty($plan['ram']) && preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\b/i', (string)$plan['ram'], $m)) { $ramMB = (int)round((float)$m[1] * (strtoupper($m[2])==='GB' ? 1024 : 1)); }
              $ramGBDisp = $ramMB > 0 ? (round($ramMB/1024, 1)) : 0;
              $storageGB = isset($plan['storage_gb']) ? (int)$plan['storage_gb'] : 0;
              if ($storageGB <= 0 && !empty($plan['storage']) && preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\b/i', (string)$plan['storage'], $m)) {
                $val = (float)$m[1]; $unit = strtoupper($m[2]);
                $storageGB = (int)round($val * ($unit==='TB'?1024:($unit==='GB'?1:1/1024)));
              }
            ?>
            <?php if ($cpuCores > 0 || $ramGBDisp > 0 || $storageGB > 0): ?>
              <div class="specs">
                <?php if ($cpuCores > 0): ?><span class="chip"><span class="emoji">🧠</span><?= htmlspecialchars(rtrim(rtrim(number_format($cpuCores, 2, '.', ''), '0'), '.')) ?> vCPU</span><?php endif; ?>
                <?php if ($ramGBDisp > 0): ?><span class="chip"><span class="emoji">💾</span><?= htmlspecialchars(rtrim(rtrim(number_format($ramGBDisp, 1, '.', ''), '0'), '.')) ?> GB RAM</span><?php endif; ?>
                <?php if ($storageGB > 0): ?><span class="chip"><span class="emoji">📦</span><?= (int)$storageGB ?> GB</span><?php endif; ?>
              </div>
            <?php endif; ?>
            <ul class="features">
              <?php $features = $plan['features'] ? (is_array($plan['features']) ? $plan['features'] : json_decode($plan['features'], true)) : []; ?>
              <?php foreach ($features as $f): ?>
                <li><?= htmlspecialchars(i18n_text((string)$f)) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="card-footer">
            <label class="compare-label"
                   data-cpu="<?= htmlspecialchars(i18n_text((string)($plan['cpu'] ?? ''))) ?>"
                   data-ram="<?= htmlspecialchars(i18n_text((string)($plan['ram'] ?? ''))) ?>"
                   data-storage="<?= htmlspecialchars(i18n_text((string)($plan['storage'] ?? ''))) ?>">
              <input type="checkbox" class="compare-toggle" aria-label="<?= htmlspecialchars(t('compare')) ?>">
              <span><?= htmlspecialchars(t('compare')) ?></span>
            </label>
            <a class="btn order-link" href="<?= htmlspecialchars($plan['order_url'] ?: ($plan['website'] ?? '#')) ?>" target="_blank" rel="nofollow noopener"><?= htmlspecialchars(t('order_now')) ?></a>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$plans): ?>
        <div style="opacity:.7;">
          <?php $seed = '<a href="scripts/seed.php">' . htmlspecialchars(t('seed_script')) . '</a>'; ?>
          <?= str_replace('{seed_link}', $seed, t('no_data')) ?>
        </div>
      <?php endif; ?>
    </main>
    <!-- Compare bar -->
    <div id="compare-bar" class="compare-bar hidden" role="region" aria-live="polite" aria-label="<?= htmlspecialchars(t('compare')) ?>">
      <div class="container compare-bar-inner">
        <div class="compare-summary">
          <strong class="count">0</strong>
          <span class="label" data-template="<?= htmlspecialchars(t('compare_selected')) ?>"><?= htmlspecialchars(t('compare_selected')) ?></span>
          <span class="hint small muted" data-max-hint><?= htmlspecialchars(str_replace('{n}', '4', t('max_compare_hint'))) ?></span>
        </div>
        <div class="compare-actions">
          <button class="btn btn-secondary" data-clear><?= htmlspecialchars(t('clear_all')) ?></button>
          <button class="btn" data-open disabled><?= htmlspecialchars(t('compare')) ?></button>
        </div>
      </div>
    </div>

    <!-- Compare modal -->
    <div id="compare-modal" class="compare-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" data-order-label="<?= htmlspecialchars(t('order_now')) ?>" data-unit-vcpu="<?= htmlspecialchars(t('unit_vcpu')) ?>" data-unit-gb="<?= htmlspecialchars(t('unit_gb')) ?>">
      <div class="modal-backdrop" data-close></div>
      <div class="modal-content" role="document">
        <div class="modal-header">
          <h3><?= htmlspecialchars(t('compare')) ?></h3>
          <div style="display:flex;gap:8px;align-items:center;">
            <button class="btn btn-secondary" data-copy data-copied-label="<?= htmlspecialchars(t('copied')) ?>"><?= htmlspecialchars(t('copy')) ?></button>
            <button class="btn btn-secondary" data-export><?= htmlspecialchars(t('export_csv')) ?></button>
            <button class="btn btn-secondary" data-close><?= htmlspecialchars(t('close')) ?></button>
          </div>
        </div>
        <div class="modal-body">
          <div class="table-wrapper">
            <table class="table compare-table">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(t('col_vendor_plan')) ?></th>
                  <th><?= htmlspecialchars(t('col_price_billing')) ?></th>
                  <th><?= htmlspecialchars(t('col_location')) ?></th>
                  <th><?= htmlspecialchars(t('col_cpu')) ?></th>
                  <th><?= htmlspecialchars(t('col_ram')) ?></th>
                  <th><?= htmlspecialchars(t('col_storage')) ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody data-rows>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php
      // Recently added: last 6 by updated_at
      $recent = $pdo->query('SELECT p.*, v.name AS vendor_name, v.logo_url FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id ORDER BY p.updated_at DESC LIMIT 6')->fetchAll();
      if ($recent): ?>
      <section style="margin-top:24px;">
        <h2 style="margin:0 0 12px;font-size:18px;"><?= htmlspecialchars(t('recently_added')) ?></h2>
        <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));">
          <?php foreach ($recent as $r): ?>
            <article class="card">
              <?php if (!empty($r['highlights'])): ?><div class="ribbon"><?= htmlspecialchars(i18n_text((string)$r['highlights'])) ?></div><?php endif; ?>
              <div class="card-header">
                <img src="<?= htmlspecialchars($r['logo_url'] ?: 'https://via.placeholder.com/72x72?text=VPS') ?>" alt="<?= htmlspecialchars($r['vendor_name']) ?>" loading="lazy" width="36" height="36">
                <div class="title">
                  <div class="size"><?= htmlspecialchars(i18n_text((string)$r['title'])) ?></div>
                  <div class="sub"><?= htmlspecialchars(i18n_text((string)($r['subtitle'] ?: 'VPS'))) ?> · <?= htmlspecialchars($r['vendor_name']) ?></div>
                </div>
              </div>
              <div class="card-footer">
                <div class="price">
                  <span class="amount">$<?= number_format((float)$r['price'], 2) ?></span>
                  <span class="duration"><?= htmlspecialchars(i18n_duration_label($r['price_duration'])) ?></span>
                </div>
                <a class="btn" href="<?= htmlspecialchars($r['order_url'] ?: '#') ?>" target="_blank" rel="nofollow noopener"><?= htmlspecialchars(t('order_now')) ?></a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
    <?php if ($total > 0): ?>
      <?php $totalPages = (int)ceil($total / $pageSize); ?>
      <?php if ($totalPages > 1): ?>
        <?php
          $baseQuery = $_GET;
          render_pagination_controls([
            'page' => $page,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'base_query' => $baseQuery,
            'page_param' => 'page',
            'window' => 2,
          ]);
          render_pagination_jump_form([
            'page' => $page,
            'total_pages' => $totalPages,
            'base_query' => $baseQuery,
            'page_param' => 'page',
          ]);
        ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php render_site_footer(); ?>
</body>
</html>
