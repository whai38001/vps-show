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
$stock = isset($_GET['stock']) ? trim($_GET['stock']) : '';
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
  // Default: by price ascending (non-null first)
  'default' => 'p.price IS NULL ASC, p.price ASC, p.id DESC',
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
 // Group by vendor only for default sorting
 if ($sort === 'default') {
   $orderBy = 'v.name ASC, ' . $orderBy;
 }

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
if ($stock !== '' && in_array($stock, ['in','out','unknown'], true)) {
    $where[] = 'p.stock_status = :stock';
    $params[':stock'] = $stock;
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
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <title><?= htmlspecialchars(t('site_title')) ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <script defer src="assets/app.js"></script>
</head>
<body>
  <div class="container">
    <nav class="topnav">
      <div class="nav-left">
        <a class="brand-link active" href="/"><?= htmlspecialchars(t('home')) ?></a>
        <a href="https://www.itdianbao.com" target="_blank" rel="noopener"><?= htmlspecialchars(t('blog')) ?></a>
        <a href="/pages/disclaimer.php"><?= htmlspecialchars(t('disclaimer')) ?></a>
        <a href="/pages/privacy.php"><?= htmlspecialchars(t('privacy')) ?></a>
        <a href="/pages/about.php"><?= htmlspecialchars(t('about')) ?></a>
      </div>
      <div class="nav-right">
        <a class="btn" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['lang'=>'zh']))) ?>"><?= htmlspecialchars(t('lang_zh')) ?></a>
        <a class="btn" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['lang'=>'en']))) ?>">EN</a>
        <button class="btn btn-secondary btn-small" id="theme-toggle" type="button" aria-label="Toggle theme"><?= htmlspecialchars(t('toggle_theme')) ?></button>
        <a class="btn btn-secondary" href="admin/"><?= htmlspecialchars(t('admin_panel')) ?></a>
      </div>
    </nav>
    <header class="header">
      <div class="brand">
        <img src="assets/emoji/rocket.svg" alt="logo" width="36" height="36">
        <h1><?= htmlspecialchars(t('site_title')) ?></h1>
      </div>
      <form class="search row items-center gap8" method="get">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars(t('search_placeholder')) ?>">
        <?php if ($q !== ''): ?>
          <button class="btn btn-secondary btn-small" type="button" data-clear-search>清空</button>
        <?php endif; ?>
      </form>
    </header>
    <section class="hero">
      <h1>
        <?= htmlspecialchars(t('site_title')) ?>
        <span class="badge">Modern UI</span>
      </h1>
      <p class="desc">
        <?= htmlspecialchars(t('search_placeholder')) ?> · <?= htmlspecialchars(t('filters_all_vendors')) ?> · <?= htmlspecialchars(t('sort_default')) ?>
      </p>
      <div class="row items-center gap8 mt10">
        <a class="btn" href="#plans"><?= htmlspecialchars(t('filter_button')) ?></a>
        <a class="btn btn-secondary" href="/pages/about.php"><?= htmlspecialchars(t('learn_more')) ?></a>
        <div class="row items-center gap8">
          <button class="btn btn-small btn-secondary" type="button" data-brand="theme-link" aria-label="配色：Link">Link</button>
          <button class="btn btn-small btn-secondary" type="button" data-brand="theme-ocean" aria-label="配色：Ocean">Ocean</button>
        </div>
      </div>
    </section>

    <div class="filters">
      <form method="get">
        <select name="vendor" class="js-auto-submit">
          <option value="0"><?= htmlspecialchars(t('filters_all_vendors')) ?></option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= $vendorId===(int)$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php
          // Quick toggle: Only in-stock
          $qsIn = $_GET; $qsIn['stock'] = 'in';
          $qsAll = $_GET; unset($qsAll['stock']);
          $hrefIn = '?' . http_build_query($qsIn);
          $hrefAll = '?' . http_build_query($qsAll);
          $isIn = ($stock === 'in');
        ?>
        <a class="btn <?= $isIn ? '' : 'btn-secondary' ?>" href="<?= htmlspecialchars($hrefIn) ?>"><?= htmlspecialchars(t('in_stock')) ?></a>
        <a class="btn <?= $stock === '' ? '' : 'btn-secondary' ?>" href="<?= htmlspecialchars($hrefAll) ?>"><?= htmlspecialchars(t('filters_all_stock')) ?></a>
        <select name="billing" class="js-auto-submit">
          <option value=""><?= htmlspecialchars(t('filters_all_billing')) ?></option>
          <?php foreach (['per month'=>t('billing_per_month'),'per year'=>t('billing_per_year'),'one-time'=>t('billing_one_time')] as $k=>$label): ?>
            <option value="<?= $k ?>" <?= $billing===$k?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="stock" class="js-auto-submit">
          <option value=""><?= htmlspecialchars(t('filters_all_stock')) ?></option>
          <option value="in" <?= $stock==='in'?'selected':'' ?>><?= htmlspecialchars(t('in_stock')) ?></option>
          <option value="out" <?= $stock==='out'?'selected':'' ?>><?= htmlspecialchars(t('out_of_stock')) ?></option>
          <option value="unknown" <?= $stock==='unknown'?'selected':'' ?>><?= htmlspecialchars(t('unknown')) ?></option>
        </select>
        <select name="sort" class="js-auto-submit">
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
        <select name="location" class="js-auto-submit">
          <option value=""><?= htmlspecialchars(t('label_location')) ?></option>
          <?php foreach ($locationsDistinct as $loc): ?>
            <option value="<?= htmlspecialchars($loc) ?>" <?= $location===$loc?'selected':'' ?>><?= htmlspecialchars(i18n_text($loc)) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= htmlspecialchars(t('filter_button')) ?></button>
        <?php $resetHref = '?' . http_build_query(['lang'=>i18n_current_lang()]); ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($resetHref) ?>"><?= htmlspecialchars(t('reset')) ?></a>
        <a class="btn btn-secondary" href="?<?= htmlspecialchars(http_build_query(['lang'=>i18n_current_lang()])) ?>">清空筛选</a>
        <?php if ($q !== ''): ?><input class="input" type="hidden" name="q" value="<?= htmlspecialchars($q) ?>"><?php endif; ?>
      </form>
    </div>

    <main class="grid" id="plans">
      <?php foreach ($plans as $plan): ?>
        <article class="card pricing" data-plan-id="<?= (int)$plan['id'] ?>">
          <?php if (!empty($plan['highlights'])): ?>
            <div class="ribbon"><?= htmlspecialchars(i18n_text((string)$plan['highlights'])) ?></div>
          <?php endif; ?>
          <div class="card-header">
            <?php $vsite = (string)($plan['website'] ?? ''); ?>
            <?php if ($vsite !== ''): ?>
              <a href="<?= htmlspecialchars($vsite) ?>" target="_blank" rel="noopener nofollow">
                <img src="<?= htmlspecialchars($plan['logo_url'] ?: 'https://via.placeholder.com/72x72?text=VPS') ?>" alt="<?= htmlspecialchars($plan['vendor_name']) ?>" loading="lazy" width="36" height="36">
              </a>
            <?php else: ?>
              <img src="<?= htmlspecialchars($plan['logo_url'] ?: 'https://via.placeholder.com/72x72?text=VPS') ?>" alt="<?= htmlspecialchars($plan['vendor_name']) ?>" loading="lazy" width="36" height="36">
            <?php endif; ?>
            <div class="title">
              <div class="size"><?= htmlspecialchars(i18n_text((string)$plan['title'])) ?></div>
              <div class="sub"><?= htmlspecialchars(i18n_text((string)($plan['subtitle'] ?: 'VPS'))) ?> · <?= htmlspecialchars($plan['vendor_name']) ?></div>
            </div>
          </div>
          <div class="card-body">
            <?php if (!empty($plan['location'])): ?>
              <div class="meta">📍 <?= htmlspecialchars(i18n_text((string)$plan['location'])) ?></div>
            <?php endif; ?>
            <?php $details = (string)($plan['details_url'] ?? ''); ?>
            <div class="price-row">
              <div class="row items-center gap8">
                <div class="price">
                  <?php $pc = isset($plan['price_currency']) ? (string)$plan['price_currency'] : 'USD'; $sym = ($pc==='GBP'?'£':($pc==='EUR'?'€':($pc==='CNY'?'¥':'$'))); ?>
                  <span class="amount"><?= $sym ?><?= number_format((float)$plan['price'], 2) ?></span>
                  <span class="duration"><?= htmlspecialchars(i18n_duration_label($plan['price_duration'])) ?></span>
                </div>
                <?php if (array_key_exists('stock_status', $plan)): ?>
                  <div class="meta" title="<?= htmlspecialchars(t('stock_status')) ?>">
                    <?php if ($plan['stock_status'] === 'in'): ?>
                      <span class="chip chip-in"><?= htmlspecialchars(t('in_stock')) ?></span>
                    <?php elseif ($plan['stock_status'] === 'out'): ?>
                      <span class="chip chip-out"><?= htmlspecialchars(t('out_of_stock')) ?></span>
                    <?php else: ?>
                      <span class="chip chip-unknown"><?= htmlspecialchars(t('unknown')) ?></span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <?php if ($details !== ''): ?>
                <a class="details-btn" href="<?= htmlspecialchars($details) ?>" target="_blank" rel="nofollow noopener"><?= htmlspecialchars(t('details')) ?></a>
              <?php endif; ?>
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
              <?php
                // Normalize features to array of strings even if stored as raw text
                $features = [];
                if (!empty($plan['features'])) {
                  if (is_array($plan['features'])) {
                    $features = $plan['features'];
                  } elseif (is_string($plan['features'])) {
                    $decoded = json_decode($plan['features'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                      $features = $decoded;
                    } else {
                      $features = array_values(array_filter(array_map('trim', preg_split('/\r?\n|;|,/', $plan['features']))));
                    }
                  }
                }
              ?>
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
        <div class="opacity-80">
          <?php
            $anyPlans = (int)$pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn();
            if ($anyPlans > 0) {
              $clearHref = '?' . http_build_query(['lang'=>i18n_current_lang()]);
              echo '<div>' . htmlspecialchars(t('no_data')) . '</div>';
              echo '<div class="mt8"><a class="btn" href="' . htmlspecialchars($clearHref) . '">' . htmlspecialchars(t('reset')) . '</a></div>';
            } else {
              $seed = '<a href="scripts/seed.php">' . htmlspecialchars(t('seed_script')) . '</a>';
              echo str_replace('{seed_link}', $seed, t('no_data'));
            }
          ?>
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
            <div class="row items-center gap8">
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
      $recent = $pdo->query('SELECT p.*, v.name AS vendor_name, v.logo_url, v.website FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id ORDER BY p.updated_at DESC LIMIT 6')->fetchAll();
      if ($recent): ?>
      <section class="mt24 home-recent">
        <h2 class="mb12 text-lg"><?= htmlspecialchars(t('recently_added')) ?></h2>
        <div class="grid grid-260">
          <?php foreach ($recent as $r): ?>
            <article class="card">
              <?php if (!empty($r['highlights'])): ?><div class="ribbon"><?= htmlspecialchars(i18n_text((string)$r['highlights'])) ?></div><?php endif; ?>
              <div class="card-header">
                <?php $rvsite = (string)($r['website'] ?? ''); ?>
                <?php if ($rvsite !== ''): ?>
                  <a href="<?= htmlspecialchars($rvsite) ?>" target="_blank" rel="noopener nofollow">
                    <img src="<?= htmlspecialchars($r['logo_url'] ?: 'https://via.placeholder.com/72x72?text=VPS') ?>" alt="<?= htmlspecialchars($r['vendor_name']) ?>" loading="lazy" width="36" height="36">
                  </a>
                <?php else: ?>
                  <img src="<?= htmlspecialchars($r['logo_url'] ?: 'https://via.placeholder.com/72x72?text=VPS') ?>" alt="<?= htmlspecialchars($r['vendor_name']) ?>" loading="lazy" width="36" height="36">
                <?php endif; ?>
                <div class="title">
                  <div class="size"><?= htmlspecialchars(i18n_text((string)$r['title'])) ?></div>
                  <div class="sub"><?= htmlspecialchars(i18n_text((string)($r['subtitle'] ?: 'VPS'))) ?> · <?= htmlspecialchars($r['vendor_name']) ?></div>
                </div>
              </div>
              <div class="card-footer">
                <div class="price">
                  <?php $rpc = isset($r['price_currency']) ? (string)$r['price_currency'] : 'USD'; $rsym = ($rpc==='GBP'?'£':($rpc==='EUR'?'€':($rpc==='CNY'?'¥':'$'))); ?>
                  <span class="amount"><?= $rsym ?><?= number_format((float)$r['price'], 2) ?></span>
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
      <?php $totalPages = (int)ceil($total / $pageSize); $baseQuery = $_GET; ?>
      <?php
        render_pagination([
          'page' => $page,
          'total_pages' => $totalPages,
          'total_items' => $total,
          'base_query' => $baseQuery,
          'page_param' => 'page',
          'window' => 2,
          'per_page_options' => [12,24,36,48],
          'per_page_param' => 'page_size',
          'per_page_value' => $pageSize,
          'align' => 'center',
        ]);
      ?>
    <?php endif; ?>
  </div>
  <?php render_site_footer(); ?>
</body>
</html>
