<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/headers.php';
require_once __DIR__ . '/../lib/pagination.php';

db_init_schema();
$pdo = get_pdo();
send_common_security_headers();

$forceLogin = isset($_GET['force_login']);
if ($forceLogin) {
    auth_logout();
}
auth_require_login();

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'vendors';

function redirect_same() { header('Location: ./?'.http_build_query($_GET)); exit; }

// Simple flash message helpers
function flash_set(string $type, string $message): void {
  auth_start_session();
  $_SESSION['flash'] = ['type'=>$type,'message'=>$message];
}
function flash_get(): ?array {
  auth_start_session();
  if (!empty($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
  return null;
}

// Handle POST actions: vendor/plan CRUD and account settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_validate_csrf_token($_POST['_csrf'] ?? null)) {
        http_response_code(400);
        echo '<meta charset="utf-8">CSRF 校验失败，请返回重试。';
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'vendor_save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $logo = trim($_POST['logo_url'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name !== '') {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE vendors SET name=:name, website=:website, logo_url=:logo, description=:desc WHERE id=:id');
                $stmt->execute([':name'=>$name, ':website'=>$website, ':logo'=>$logo, ':desc'=>$desc, ':id'=>$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO vendors (name, website, logo_url, description) VALUES (:name,:website,:logo,:desc)');
                $stmt->execute([':name'=>$name, ':website'=>$website, ':logo'=>$logo, ':desc'=>$desc]);
            }
        }
        flash_set('success', '厂商已保存');
        redirect_same();
    }
    if (isset($_POST['action']) && $_POST['action'] === 'vendor_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM vendors WHERE id=:id')->execute([':id'=>$id]);
        }
        flash_set('success', '厂商已删除');
        redirect_same();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'plan_save') {
        $id = (int)($_POST['id'] ?? 0);
        $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $price_duration = $_POST['price_duration'] ?? 'per year';
        $order_url = trim($_POST['order_url'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $features_raw = trim($_POST['features'] ?? '');
        $highlights = trim($_POST['highlights'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $features = [];
        $normalize_to_zh = isset($_POST['normalize_to_zh']) && $_POST['normalize_to_zh'] === '1';
        if ($features_raw !== '') {
            $features = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $features_raw))));
        }
        if ($normalize_to_zh) {
            require_once __DIR__ . '/../lib/i18n.php';
            $title = i18n_text_to_zh($title);
            if ($subtitle !== '') { $subtitle = i18n_text_to_zh($subtitle); }
            if ($highlights !== '') { $highlights = i18n_text_to_zh($highlights); }
            if ($location !== '') { $location = i18n_text_to_zh($location); }
            if (!empty($features)) {
                foreach ($features as $i => $feat) { $features[$i] = i18n_text_to_zh($feat); }
            }
        }
        // Normalize structured specs from features if not provided
        $cpu = trim($_POST['cpu'] ?? '');
        $ram = trim($_POST['ram'] ?? '');
        $storage = trim($_POST['storage'] ?? '');
        if ($cpu === '' || $ram === '' || $storage === '') {
            // Best-effort derive from features
            foreach ($features as $feat) {
                if ($cpu === '' && preg_match('/\b(?:[0-9]+(?:\.[0-9]+)?\s*)?v?CPU\b|核心/i', $feat)) { $cpu = $feat; }
                if ($ram === '' && preg_match('/\b[0-9]+(?:\.[0-9]+)?\s*(?:GB|MB)\b.*(?:RAM|内存)|\bRAM\b|内存/i', $feat)) { $ram = $feat; }
                if ($storage === '' && preg_match('/(?:NVMe|SSD|HDD|存储|Storage)/i', $feat)) { $storage = $feat; }
            }
        }

        // Normalize numeric specs for future排序/筛选（可留空）
        $cpu_cores = null; $ram_mb = null; $storage_gb = null;
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*v?CPU/i', $cpu, $m)) { $cpu_cores = (float)$m[1]; }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|MB)\b/i', $ram, $m)) {
            $ram_mb = (int)round((float)$m[1] * (strtoupper($m[2])==='GB' ? 1024 : 1));
        }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(TB|GB|MB)\b/i', $storage, $m)) {
            $val = (float)$m[1]; $unit = strtoupper($m[2]);
            $storage_gb = (int)round($val * ($unit==='TB'?1024:($unit==='GB'?1:1/1024)));
        }

        if ($vendor_id > 0 && $title !== '' && $price > 0) {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE plans SET vendor_id=:vendor_id,title=:title,subtitle=:subtitle,price=:price,price_duration=:d,order_url=:url,location=:loc,features=:f,cpu=:cpu,ram=:ram,storage=:storage,cpu_cores=:cpu_cores,ram_mb=:ram_mb,storage_gb=:storage_gb,highlights=:h,sort_order=:s WHERE id=:id');
                $stmt->execute([':vendor_id'=>$vendor_id, ':title'=>$title, ':subtitle'=>$subtitle, ':price'=>$price, ':d'=>$price_duration, ':url'=>$order_url, ':loc'=>$location, ':f'=>json_encode($features, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ':cpu'=>$cpu, ':ram'=>$ram, ':storage'=>$storage, ':cpu_cores'=>$cpu_cores, ':ram_mb'=>$ram_mb, ':storage_gb'=>$storage_gb, ':h'=>$highlights, ':s'=>$sort_order, ':id'=>$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO plans (vendor_id,title,subtitle,price,price_duration,order_url,location,features,cpu,ram,storage,cpu_cores,ram_mb,storage_gb,highlights,sort_order) VALUES (:vendor_id,:title,:subtitle,:price,:d,:url,:loc,:f,:cpu,:ram,:storage,:cpu_cores,:ram_mb,:storage_gb,:h,:s)');
                $stmt->execute([':vendor_id'=>$vendor_id, ':title'=>$title, ':subtitle'=>$subtitle, ':price'=>$price, ':d'=>$price_duration, ':url'=>$order_url, ':loc'=>$location, ':f'=>json_encode($features, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ':cpu'=>$cpu, ':ram'=>$ram, ':storage'=>$storage, ':cpu_cores'=>$cpu_cores, ':ram_mb'=>$ram_mb, ':storage_gb'=>$storage_gb, ':h'=>$highlights, ':s'=>$sort_order]);
            }
        }
        flash_set('success', '套餐已保存');
        redirect_same();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'plan_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM plans WHERE id=:id')->execute([':id'=>$id]);
        }
        flash_set('success', '套餐已删除');
        redirect_same();
    }
    if (isset($_POST['action']) && $_POST['action'] === 'plan_duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT * FROM plans WHERE id=:id');
            $stmt->execute([':id'=>$id]);
            if ($src = $stmt->fetch()) {
                $newTitle = (string)$src['title'] . ' (复制)';
                $ins = $pdo->prepare('INSERT INTO plans (vendor_id,title,subtitle,price,price_duration,order_url,location,features,highlights,sort_order) VALUES (:vendor_id,:title,:subtitle,:price,:d,:url,:loc,:f,:h,:s)');
                $ins->execute([
                    ':vendor_id' => (int)$src['vendor_id'],
                    ':title' => $newTitle,
                    ':subtitle' => (string)$src['subtitle'],
                    ':price' => (float)$src['price'],
                    ':d' => (string)$src['price_duration'],
                    ':url' => (string)$src['order_url'],
                    ':loc' => (string)$src['location'],
                    ':f' => is_string($src['features']) ? $src['features'] : json_encode($src['features'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    ':h' => (string)$src['highlights'],
                    ':s' => (int)$src['sort_order'],
                ]);
            }
        }
        flash_set('success', '套餐已复制');
        redirect_same();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'account_save') {
        // Validate current password with current effective credentials
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newUsername = trim((string)($_POST['admin_username'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');

        // Build the effective current username/hash (DB settings override env/constants)
        $effectiveUsername = db_get_setting('admin_username', ADMIN_USERNAME);
        $effectiveHash = db_get_setting('admin_password_hash', ADMIN_PASSWORD_HASH);

        $okCurrent = false;
        if ($effectiveHash !== '') {
            $okCurrent = password_verify($currentPassword, $effectiveHash);
        } elseif (ADMIN_PASSWORD_HASH !== '') {
            $okCurrent = password_verify($currentPassword, ADMIN_PASSWORD_HASH);
        } else {
            // Fallback to ADMIN_PASSWORD only if no hashes configured
            $okCurrent = (defined('ADMIN_PASSWORD') && ADMIN_PASSWORD !== '' && hash_equals(ADMIN_PASSWORD, $currentPassword));
        }

        if (!$okCurrent) {
            flash_set('error', '当前密码验证失败');
            redirect_same();
        }

        if ($newUsername === '') {
            $newUsername = $effectiveUsername;
        }
        if ($newPassword !== '' || $newPasswordConfirm !== '') {
            if ($newPassword !== $newPasswordConfirm) {
                flash_set('error', '两次输入的新密码不一致');
                redirect_same();
            }
            // Strong password policy: >= 12 chars, contains upper, lower, digit, special
            $len = strlen($newPassword);
            $hasUpper = (bool)preg_match('/[A-Z]/', $newPassword);
            $hasLower = (bool)preg_match('/[a-z]/', $newPassword);
            $hasDigit = (bool)preg_match('/\d/', $newPassword);
            $hasSpecial = (bool)preg_match('/[^a-zA-Z\d]/', $newPassword);
            if ($len < 12 || !$hasUpper || !$hasLower || !$hasDigit || !$hasSpecial) {
                flash_set('error', '新密码不符合强度要求：至少12位，且包含大写/小写/数字/特殊字符');
                redirect_same();
            }
            // Disallow same as current password
            $sameAsCurrent = false;
            if ($effectiveHash !== '') {
                $sameAsCurrent = password_verify($newPassword, $effectiveHash);
            } elseif (ADMIN_PASSWORD_HASH !== '') {
                $sameAsCurrent = password_verify($newPassword, ADMIN_PASSWORD_HASH);
            } elseif (defined('ADMIN_PASSWORD') && ADMIN_PASSWORD !== '') {
                $sameAsCurrent = hash_equals(ADMIN_PASSWORD, $newPassword);
            }
            if ($sameAsCurrent) {
                flash_set('error', '新密码不能与当前密码相同');
                redirect_same();
            }
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            db_set_setting('admin_password_hash', $newHash);
        }
        db_set_setting('admin_username', $newUsername);

        // Update session username for consistency
        auth_start_session();
        $_SESSION['admin_username'] = $newUsername;

        flash_set('success', '账号设置已更新');
        redirect_same();
    }
}

$vendorsAll = $pdo->query('SELECT * FROM vendors ORDER BY id DESC')->fetchAll();
// Vendors pagination for listing table
$vendorsPage = max(1, (int)($_GET['vendors_page'] ?? 1));
$vendorsPageSize = min(100, max(5, (int)($_GET['vendors_page_size'] ?? 20)));
$vendorsTotal = (int)$pdo->query('SELECT COUNT(*) FROM vendors')->fetchColumn();
$vendorsOffset = ($vendorsPage - 1) * $vendorsPageSize;
$vendors = $pdo->query('SELECT * FROM vendors ORDER BY id DESC LIMIT ' . (int)$vendorsPageSize . ' OFFSET ' . (int)$vendorsOffset)->fetchAll();

// Plans query with optional filters and pagination
$planVendorFilter = isset($_GET['plan_vendor']) ? (int)$_GET['plan_vendor'] : 0;
$planQ = isset($_GET['plan_q']) ? trim((string)$_GET['plan_q']) : '';
$sqlPlans = 'SELECT p.*, v.name AS vendor_name FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id';
$wherePlans = [];
$paramsPlans = [];
if ($planVendorFilter > 0) { $wherePlans[] = 'p.vendor_id = :pv'; $paramsPlans[':pv'] = $planVendorFilter; }
if ($planQ !== '') { $wherePlans[] = '(p.title LIKE :pq OR p.subtitle LIKE :pq)'; $paramsPlans[':pq'] = "%$planQ%"; }
if ($wherePlans) { $sqlPlans .= ' WHERE ' . implode(' AND ', $wherePlans); }
$plansPage = max(1, (int)($_GET['plans_page'] ?? 1));
$plansPageSize = min(100, max(10, (int)($_GET['plans_page_size'] ?? 20)));
$plansOffset = ($plansPage - 1) * $plansPageSize;
$sqlPlansCount = 'SELECT COUNT(*) FROM plans p INNER JOIN vendors v ON v.id=p.vendor_id' . ($wherePlans ? (' WHERE ' . implode(' AND ', $wherePlans)) : '');
$stmtPlansCount = $pdo->prepare($sqlPlansCount);
$stmtPlansCount->execute($paramsPlans);
$plansTotal = (int)$stmtPlansCount->fetchColumn();
$sqlPlans .= ' ORDER BY p.sort_order ASC, p.id DESC LIMIT ' . (int)$plansPageSize . ' OFFSET ' . (int)$plansOffset;
$stmtPlans = $pdo->prepare($sqlPlans);
$stmtPlans->execute($paramsPlans);
$plans = $stmtPlans->fetchAll();

// Prepare edit contexts
$editVendorId = isset($_GET['edit_vendor']) ? (int)$_GET['edit_vendor'] : 0;
$editingVendor = null;
if ($editVendorId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM vendors WHERE id=:id');
    $stmt->execute([':id' => $editVendorId]);
    $editingVendor = $stmt->fetch();
}

$editPlanId = isset($_GET['edit_plan']) ? (int)$_GET['edit_plan'] : 0;
$editingPlan = null;
if ($editPlanId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM plans WHERE id=:id');
    $stmt->execute([':id' => $editPlanId]);
    $editingPlan = $stmt->fetch();
}

$csrf = auth_get_csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>管理后台 - <?= htmlspecialchars(SITE_NAME) ?></title>
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
  <div class="container">
    <?php if ($flash = flash_get()): ?>
      <div style="margin-bottom:12px;padding:10px 12px;border-radius:8px;<?= $flash['type']==='error' ? 'background:#231b1b;border:1px solid #5b2727;color:#fca5a5;' : 'background:#152117;border:1px solid #2b614f;color:#a7f3d0;' ?>">
        <?= htmlspecialchars($flash['message']) ?>
      </div>
    <?php endif; ?>
    <div class="header">
      <div class="brand">
        <img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/1f4dd.png" alt="admin">
        <h1>管理后台</h1>
      </div>
      <div style="display:flex; gap:8px; align-items:center;">
        <span class="muted small">当前账号：<?= htmlspecialchars($_SESSION['admin_username'] ?? ADMIN_USERNAME) ?></span>
        <a class="btn" href="../">返回前台</a>
        <a class="btn" href="./logout.php">退出登录</a>
      </div>
    </div>

    <nav style="margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap;">
      <a class="btn" href="?tab=vendors">厂商管理</a>
      <a class="btn" href="?tab=plans">套餐管理</a>
      <a class="btn" href="?tab=account">账号设置</a>
      <a class="btn" href="../scripts/import_url.php" target="_blank">通用导入 URL</a>
    </nav>

    <?php if ($tab === 'vendors'): ?>
      <section style="margin-bottom:24px;">
        <h2 style="margin:6px 0 12px;">新增/编辑 厂商</h2>
        <form method="post">
          <input type="hidden" name="action" value="vendor_save">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <div class="form-row">
            <div>
              <label>厂商ID（编辑时填写）</label>
              <input class="input" type="number" name="id" placeholder="留空表示新增" value="<?= $editingVendor ? (int)$editingVendor['id'] : '' ?>">
            </div>
            <div>
              <label>名称</label>
              <input class="input" type="text" name="name" required value="<?= $editingVendor ? htmlspecialchars($editingVendor['name']) : '' ?>">
            </div>
            <div>
              <label>官网链接</label>
              <input class="input" type="url" name="website" value="<?= $editingVendor ? htmlspecialchars((string)$editingVendor['website']) : '' ?>">
            </div>
            <div>
              <label>Logo URL</label>
              <input class="input" type="url" name="logo_url" value="<?= $editingVendor ? htmlspecialchars((string)$editingVendor['logo_url']) : '' ?>">
            </div>
          </div>
          <div>
            <label>简介</label>
            <textarea name="description" rows="3"><?php if ($editingVendor) { echo htmlspecialchars((string)$editingVendor['description']); } ?></textarea>
          </div>
          <div style="margin-top:10px;"><button class="btn" type="submit">保存</button></div>
        </form>
      </section>

      <section>
        <h2 style="margin:6px 0 12px;">厂商列表</h2>
        <form method="get" style="margin-bottom:8px; display:flex; gap:8px; align-items:center;">
          <input type="hidden" name="tab" value="vendors">
          <input type="hidden" name="vendors_page" value="1">
          <label class="small" style="color:#9ca3af;">每页</label>
          <select name="vendors_page_size" onchange="this.form.submit()">
            <?php foreach ([10,20,50,100] as $opt): ?>
              <option value="<?= $opt ?>" <?= $vendorsPageSize===$opt?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn" type="submit">应用</button></noscript>
        </form>
        <table class="table">
          <thead>
            <tr><th>ID</th><th>名称</th><th>官网</th><th>Logo</th><th>操作</th></tr>
          </thead>
          <tbody>
            <?php foreach ($vendors as $v): ?>
              <tr>
                <td><?= (int)$v['id'] ?></td>
                <td><?= htmlspecialchars($v['name']) ?></td>
                <td><a href="<?= htmlspecialchars($v['website']) ?>" target="_blank">访问</a></td>
                <td><?= $v['logo_url']?'<img src="'.htmlspecialchars($v['logo_url']).'" alt="logo" style="width:32px;height:32px;object-fit:contain;">':'' ?></td>
                <td class="admin-actions">
                  <a class="btn" href="?tab=vendors&edit_vendor=<?= (int)$v['id'] ?>">编辑</a>
                  <form method="post" onsubmit="return confirm('删除后不可恢复，确定？');">
                    <input type="hidden" name="action" value="vendor_delete">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                    <button class="btn" type="submit">删除</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php
          $vendorsTotalPages = (int)ceil($vendorsTotal / $vendorsPageSize);
          if ($vendorsTotalPages > 1) {
            $baseQuery = $_GET; $baseQuery['tab'] = 'vendors';
            render_pagination_controls([
              'page' => $vendorsPage,
              'total_pages' => $vendorsTotalPages,
              'total_items' => $vendorsTotal,
              'base_query' => $baseQuery,
              'page_param' => 'vendors_page',
              'window' => 2,
            ]);
            render_pagination_jump_form([
              'page' => $vendorsPage,
              'total_pages' => $vendorsTotalPages,
              'base_query' => [
                'tab' => 'vendors',
                'vendors_page_size' => $vendorsPageSize,
              ],
              'page_param' => 'vendors_page',
            ]);
          }
        ?>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'plans'): ?>
      <section style="margin-bottom:24px;">
        <h2 style="margin:6px 0 12px;">新增/编辑 套餐</h2>
        <form method="post">
          <input type="hidden" name="action" value="plan_save">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <div class="form-row">
            <div>
              <label>套餐ID（编辑时填写）</label>
              <input class="input" type="number" name="id" placeholder="留空表示新增" value="<?= $editingPlan ? (int)$editingPlan['id'] : '' ?>">
            </div>
            <div>
              <label>厂商</label>
              <select name="vendor_id" required>
                <option value="">请选择厂商</option>
                <?php foreach ($vendorsAll as $v): ?>
                  <option value="<?= (int)$v['id'] ?>" <?= $editingPlan && (int)$editingPlan['vendor_id']===(int)$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>标题（如 2GB）</label>
              <input class="input" type="text" name="title" required value="<?= $editingPlan ? htmlspecialchars($editingPlan['title']) : '' ?>">
            </div>
            <div>
              <label>副标题（如 KVM VPS）</label>
              <input class="input" type="text" name="subtitle" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['subtitle']) : '' ?>">
            </div>
            <div>
              <label>价格</label>
              <input class="input" type="number" step="0.01" name="price" required value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['price']) : '' ?>">
            </div>
            <div>
              <label>计费周期</label>
              <select name="price_duration">
                <?php $dur = $editingPlan ? (string)$editingPlan['price_duration'] : 'per year'; ?>
                <option value="per year" <?= $dur==='per year'?'selected':'' ?>>年付</option>
                <option value="per month" <?= $dur==='per month'?'selected':'' ?>>月付</option>
                <option value="one-time" <?= $dur==='one-time'?'selected':'' ?>>一次性</option>
              </select>
            </div>
            <div>
              <label>下单链接</label>
              <input class="input" type="url" name="order_url" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['order_url']) : '' ?>">
            </div>
            <div>
              <label>部署地区/机房</label>
              <input class="input" type="text" name="location" placeholder="Multiple Locations" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['location']) : '' ?>">
            </div>
            <div>
              <label>排序（越小越靠前）</label>
              <input class="input" type="number" name="sort_order" value="<?= $editingPlan ? (int)$editingPlan['sort_order'] : 0 ?>">
            </div>
          </div>
          <div>
            <label>功能/规格（每行一条）</label>
            <textarea name="features" rows="6" placeholder="1 vCPU Core\n20 GB SSD\n...\n"><?php
              if ($editingPlan) {
                $features = $editingPlan['features'];
                if (is_string($features)) { $features = json_decode($features, true); }
                if (is_array($features)) { echo htmlspecialchars(implode("\n", $features)); }
              }
            ?></textarea>
          </div>
          <div class="form-row">
            <div>
              <label>CPU（结构化字段）</label>
              <input class="input" type="text" name="cpu" placeholder="如：2 vCPU Cores" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['cpu']) : '' ?>">
            </div>
            <div>
              <label>内存（结构化字段）</label>
              <input class="input" type="text" name="ram" placeholder="如：3 GB RAM" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['ram']) : '' ?>">
            </div>
            <div>
              <label>存储（结构化字段）</label>
              <input class="input" type="text" name="storage" placeholder="如：60 GB NVMe SSD Storage" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['storage']) : '' ?>">
            </div>
          </div>
          <div>
            <label>角标文案（如 Black Friday，可留空）</label>
            <input class="input" type="text" name="highlights" placeholder="Black Friday" value="<?= $editingPlan ? htmlspecialchars((string)$editingPlan['highlights']) : '' ?>">
          </div>
          <div style="display:flex; gap:12px; align-items:center; margin-top:10px;">
            <label style="display:flex; align-items:center; gap:6px;">
              <input type="checkbox" name="normalize_to_zh" value="1">
              保存前规范化为中文（基于术语表）
            </label>
            <button class="btn" type="submit">保存</button>
          </div>
        </form>
      </section>

      <section>
        <h2 style="margin:6px 0 12px;">套餐列表</h2>
        <form method="get" style="margin-bottom:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
          <input type="hidden" name="tab" value="plans">
          <input type="hidden" name="plans_page" value="1">
          <select name="plan_vendor" onchange="this.form.submit()">
            <option value="0">全部厂商</option>
            <?php foreach ($vendorsAll as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= $planVendorFilter===(int)$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input class="input" style="width:220px;" type="text" name="plan_q" placeholder="搜索标题/副标题" value="<?= htmlspecialchars($planQ) ?>">
          <label class="small" style="color:#9ca3af;">每页</label>
          <select name="plans_page_size" onchange="this.form.submit()">
            <?php foreach ([10,20,50,100] as $opt): ?>
              <option value="<?= $opt ?>" <?= $plansPageSize===$opt?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
          <a class="btn btn-secondary" href="?tab=plans">重置</a>
        </form>
        <table class="table">
          <thead>
              <tr><th>ID</th><th>厂商</th><th>标题</th><th>地区</th><th>价格</th><th>周期</th><th>排序</th><th>角标</th><th>操作</th></tr>
          </thead>
          <tbody>
            <?php foreach ($plans as $p): ?>
              <tr>
                <td><?= (int)$p['id'] ?></td>
                <td><?= htmlspecialchars($p['vendor_name']) ?></td>
                <td><?= htmlspecialchars($p['title']) ?></td>
                <td><?= htmlspecialchars((string)$p['location']) ?></td>
                <td>$<?= number_format((float)$p['price'],2) ?></td>
                <td><?= htmlspecialchars($p['price_duration']) ?></td>
                <td><?= (int)$p['sort_order'] ?></td>
                <td><?= htmlspecialchars((string)$p['highlights']) ?></td>
                <td class="admin-actions">
                  <a class="btn" href="?tab=plans&edit_plan=<?= (int)$p['id'] ?>">编辑</a>
                  <form method="post" onsubmit="return confirm('复制该套餐为新记录？');">
                    <input type="hidden" name="action" value="plan_duplicate">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn" type="submit">复制</button>
                  </form>
                  <form method="post" onsubmit="return confirm('删除后不可恢复，确定？');">
                    <input type="hidden" name="action" value="plan_delete">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-danger" type="submit">删除</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php
          $plansTotalPages = (int)ceil($plansTotal / $plansPageSize);
          if ($plansTotalPages > 1) {
            $baseQuery = $_GET; $baseQuery['tab'] = 'plans';
            render_pagination_controls([
              'page' => $plansPage,
              'total_pages' => $plansTotalPages,
              'total_items' => $plansTotal,
              'base_query' => $baseQuery,
              'page_param' => 'plans_page',
              'window' => 2,
            ]);
            render_pagination_jump_form([
              'page' => $plansPage,
              'total_pages' => $plansTotalPages,
              'base_query' => [
                'tab' => 'plans',
                'plan_vendor' => $planVendorFilter,
                'plan_q' => $planQ,
                'plans_page_size' => $plansPageSize,
              ],
              'page_param' => 'plans_page',
            ]);
          }
        ?>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'account'): ?>
      <section style="margin-bottom:24px;">
        <h2 style="margin:6px 0 12px;">管理员账号设置</h2>
        <form method="post" onsubmit="return confirm('确认保存账号设置？');" style="max-width:520px;">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="account_save">
          <div>
            <label>用户名</label>
            <input class="input" type="text" name="admin_username" required value="<?= htmlspecialchars(db_get_setting('admin_username', ADMIN_USERNAME)) ?>">
          </div>
          <div style="margin-top:10px;">
            <label>新密码（留空则不修改）</label>
            <input class="input" type="password" name="new_password" autocomplete="new-password" placeholder="不修改请留空">
          </div>
          <div style="margin-top:10px;">
            <label>确认新密码</label>
            <input class="input" type="password" name="new_password_confirm" autocomplete="new-password" placeholder="再次输入新密码">
          </div>
          <div class="muted small" style="margin-top:6px;">
            密码要求：至少 12 位，且包含大写字母、小写字母、数字与特殊字符。
          </div>
          <div style="margin-top:10px;">
            <label>当前密码（为安全起见，修改任一项都需验证）</label>
            <input class="input" type="password" name="current_password" required autocomplete="current-password">
          </div>
          <div style="margin-top:12px; display:flex; gap:8px; align-items:center;">
            <button class="btn" type="submit">保存</button>
            <span class="muted small">密码将以哈希保存到数据库的 settings 表中</span>
          </div>
        </form>
        <?php
          $uRow = db_get_setting_row('admin_username');
          $pRow = db_get_setting_row('admin_password_hash');
        ?>
        <div class="muted small" style="margin-top:8px;">
          用户名最后更新：<?= htmlspecialchars($uRow['updated_at'] ?? '尚未设置') ?>；
          密码最后更新：<?= htmlspecialchars($pRow['updated_at'] ?? '尚未设置') ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>
