<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require_once __DIR__ . '/../api/auth_limits.php'; // LIMITS

// --- ورود ---
if (isset($_POST['login'])) {
    check_csrf();
    $throttleKey = 'admin_login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    throttle_check($throttleKey); // اگر تلاش ناموفق زیاد بوده، همینجا با 429 متوقف می‌شود
    $u = (string)($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if (hash_equals(ADMIN_USER, $u) && password_verify($p, ADMIN_PASS_HASH)) {
        throttle_reset($throttleKey);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    } else {
        throttle_fail($throttleKey);
        $loginError = 'نام کاربری یا رمز عبور اشتباه است.';
    }
}

if (!is_logged_in()) {
    ?>
    <!doctype html>
    <html lang="fa" dir="rtl">
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ورود به پنل مدیریت</title>
    <style>
    :root{--g1:#3f5f1c;--g2:#5B7F2A;--g3:#86a94a}
    *{box-sizing:border-box}
    body{font-family:Vazirmatn,Tahoma,"Segoe UI",Arial,sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
      background:linear-gradient(135deg,var(--g1),var(--g2) 55%,var(--g3));padding:16px}
    form{background:#fff;padding:32px 28px;border-radius:20px;box-shadow:0 20px 50px rgba(0,0,0,.25);width:100%;max-width:360px}
    .logo{width:64px;height:64px;border-radius:18px;background:linear-gradient(135deg,var(--g2),var(--g3));display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 12px}
    h2{margin:0 0 4px;text-align:center;font-size:20px}
    .sub{text-align:center;color:#777;font-size:13px;margin-bottom:18px}
    input{width:100%;padding:12px 14px;margin:6px 0;border:1.5px solid #e1e5dc;border-radius:12px;font:inherit;transition:.15s}
    input:focus{outline:0;border-color:var(--g2);box-shadow:0 0 0 3px rgba(91,127,42,.15)}
    button{width:100%;padding:12px;margin-top:10px;background:var(--g2);color:#fff;border:0;border-radius:12px;font:inherit;font-weight:700;font-size:15px;cursor:pointer}
    button:hover{background:var(--g1)}
    .err{color:#b00020;text-align:center;background:#fde8e8;border-radius:10px;padding:8px;font-size:13px}
    </style></head>
    <body>
    <form method="post">
      <div class="logo">🌰</div>
      <h2>پنل مدیریت پسته بازار</h2>
      <div class="sub">برای ادامه وارد شوید</div>
      <?php if (!empty($loginError)): ?><p class="err"><?= h($loginError) ?></p><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="text" name="username" placeholder="نام کاربری" required autofocus>
      <input type="password" name="password" placeholder="رمز عبور" required>
      <button type="submit" name="login" value="1">ورود</button>
    </form>
    </body></html>
    <?php
    exit;
}

// --- داشبورد ---
$pdo = db();
try { ensure_plan_column($pdo); } catch (Throwable $e) { error_log('admin ensure_plan_column: ' . $e->getMessage()); }
require_once __DIR__ . '/../api/features.php'; // S00: edition names
require_once __DIR__ . '/../api/entities.php';
require_once __DIR__ . '/../api/migrations.php';
try { pb_migrate($pdo); } catch (Throwable $e) { error_log('admin pb_migrate: ' . $e->getMessage()); }
try { ensure_message_tables($pdo); } catch (Throwable $e) { error_log('admin ensure_message_tables: ' . $e->getMessage()); }
$tab = (($_GET['tab'] ?? '') === 'messages') ? 'messages' : 'customers';
$toPhone = preg_replace('/[^0-9]/', '', (string)($_GET['to'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? '');
$todayDate = (new DateTime('today'))->format('Y-m-d');
if ($filter === 'expiring') {
    // اشتراک‌های رو به اتمام (امروز تا ۳ روز دیگر) برای تماس تمدید
    $until = (new DateTime('today'))->modify('+3 days')->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE expiry_date >= ? AND expiry_date <= ? ORDER BY expiry_date ASC');
    $stmt->execute([$todayDate, $until]);
} elseif ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE phone LIKE ? OR name LIKE ? ORDER BY id DESC');
    $stmt->execute([$like, $like]);
} else {
    $stmt = $pdo->query('SELECT * FROM customers ORDER BY id DESC');
}
$customers = $stmt->fetchAll();
// S22.T22.3c: edition filter (tier x plan) applied on top of search / expiring filters
$fTier = in_array((string)($_GET['tier'] ?? ''), ['green', 'gold'], true) ? (string)$_GET['tier'] : '';
$fPlan = in_array((string)($_GET['plan'] ?? ''), ['rial', 'fx'], true) ? (string)$_GET['plan'] : '';
if ($fTier !== '' || $fPlan !== '') {
    $customers = array_values(array_filter($customers, function (array $cu) use ($fTier, $fPlan): bool {
        if ($fTier !== '' && pb_tier_of($cu) !== $fTier) return false;
        if ($fPlan !== '' && (customer_plan($cu) === PLAN_FX ? 'fx' : 'rial') !== $fPlan) return false;
        return true;
    }));
}
$blocksMap = pb_blocks_map($pdo); // LIMITS
$today = (new DateTime())->format('Y-m-d');
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// آمار بالای صفحه
$allC = $pdo->query('SELECT * FROM customers')->fetchAll();
$stTotal = count($allC); $stActive = 0; $stSoon = 0; $stFx = 0; $stExpired = 0;
$soonDate = (new DateTime('today'))->modify('+3 days')->format('Y-m-d');
foreach ($allC as $cc) {
    $exp = $cc['expiry_date'] < $todayDate;
    if ($exp) $stExpired++;
    elseif ((bool)$cc['active']) $stActive++;
    if (!$exp && $cc['expiry_date'] <= $soonDate) $stSoon++;
    if (customer_plan($cc) === PLAN_FX) $stFx++;
}
$payPending = 0;
try { $payPending = (int)$pdo->query("SELECT COUNT(*) FROM pay_orders WHERE status = 'awaiting_review'")->fetchColumn(); } catch (Throwable $e) { /* جدول‌های پرداخت هنوز ساخته نشده */ }
$sent = $pdo->query('SELECT a.*, c.name AS cname, c.phone AS cphone, (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.id) AS reads_count FROM announcements a LEFT JOIN customers c ON c.id = a.customer_id ORDER BY a.id DESC LIMIT 50')->fetchAll();
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>پنل مدیریت — پسته بازار</title>
<style>
:root{--g1:#3f5f1c;--g2:#5B7F2A;--g3:#86a94a;--bg:#f3f6ef;--card:#fff;--line:#e8ece2;--txt:#1f2a14;--mut:#7a8470;--red:#c0392b;--amber:#b7791f}
*{box-sizing:border-box}
body{font-family:Vazirmatn,Tahoma,"Segoe UI",Arial,sans-serif;background:var(--bg);margin:0;color:var(--txt);line-height:1.6}
header{background:linear-gradient(135deg,var(--g1),var(--g2) 60%,var(--g3));color:#fff;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:20;box-shadow:0 2px 12px rgba(0,0,0,.18)}
header .brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
header .brand i{font-style:normal;background:rgba(255,255,255,.2);border-radius:12px;width:38px;height:38px;display:flex;align-items:center;justify-content:center;font-size:20px}
header a{color:#fff;text-decoration:none;font-size:14px;background:rgba(255,255,255,.18);padding:7px 14px;border-radius:10px}
header a:hover{background:rgba(255,255,255,.3)}
main{max-width:1180px;margin:22px auto;padding:0 16px}
.flash{background:#e7f6e1;border:1px solid #b8e0a6;color:#2b5a17;padding:12px 16px;border-radius:12px;margin-bottom:16px;font-weight:600}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:18px}
.stat{background:var(--card);border-radius:16px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);border:1px solid var(--line)}
.stat b{display:block;font-size:28px;line-height:1.2}
.stat span{color:var(--mut);font-size:13px}
.stat.g b{color:var(--g2)} .stat.a b{color:var(--amber)} .stat.r b{color:var(--red)} .stat.f b{color:#8a5a00}
.tabs{display:flex;gap:8px;margin-bottom:16px;background:#e6ecdd;padding:5px;border-radius:14px;width:fit-content}
.tabs a{padding:9px 20px;border-radius:10px;text-decoration:none;color:#4a5a38;font-weight:700;font-size:14px}
.tabs a.on{background:#fff;color:var(--g2);box-shadow:0 1px 4px rgba(0,0,0,.1)}
.card{background:var(--card);border-radius:18px;padding:20px 22px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.05);border:1px solid var(--line)}
.card h3{margin:0 0 12px;font-size:17px}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
input,select,textarea{padding:10px 12px;border:1.5px solid #dde3d4;border-radius:11px;font:inherit;background:#fff;transition:.15s;max-width:100%}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--g2);box-shadow:0 0 0 3px rgba(91,127,42,.15)}
textarea{width:100%;min-height:110px;resize:vertical}
button,.btn{padding:8px 14px;border:0;border-radius:10px;background:var(--g2);color:#fff;cursor:pointer;font:inherit;font-size:13px;font-weight:700;text-decoration:none;display:inline-block}
button:hover,.btn:hover{background:var(--g1)}
button.danger{background:var(--red)} button.secondary{background:#8c9680} button.ghost{background:#eef2e8;color:#3f5f1c}
button.big{padding:11px 22px;font-size:14px}
.muted{color:var(--mut);font-size:12.5px}
table{width:100%;border-collapse:collapse;font-size:14px}
th{background:#f3f6ef;color:#5a664b;font-size:12.5px;padding:10px 8px;text-align:right;white-space:nowrap}
td{padding:11px 8px;border-bottom:1px solid var(--line);text-align:right;vertical-align:middle}
tr:hover td{background:#fafcf7}
.badge{display:inline-block;padding:3px 11px;border-radius:20px;font-size:12px;font-weight:700}
.ok{background:#e1f4da;color:#256b1b} .fx{background:#fff0cf;color:#85560a} .bad{background:#fde3e1;color:#a4261a} .info{background:#e3eefc;color:#1d4f91}
.who b{display:block} .who span{color:var(--mut);font-size:12.5px;direction:ltr;display:inline-block}
details.menu{position:relative;display:inline-block}
details.menu>summary{list-style:none;cursor:pointer;background:#eef2e8;color:#3f5f1c;padding:7px 14px;border-radius:10px;font-weight:700;font-size:13px}
details.menu>summary::-webkit-details-marker{display:none}
details.menu[open]>summary{background:var(--g2);color:#fff}
.pop{position:absolute;left:0;top:calc(100% + 6px);z-index:30;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.16);padding:12px;width:330px;display:flex;flex-direction:column;gap:9px}
.pop form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.pop .lbl{font-size:11.5px;color:var(--mut);font-weight:700;margin-top:2px}
.pop input{padding:7px 9px;font-size:13px}
details.add>summary{list-style:none;cursor:pointer;font-weight:800;font-size:16px}
details.add>summary::-webkit-details-marker{display:none}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field label{display:block;font-size:12.5px;font-weight:700;margin-bottom:5px;color:#4a5a38}
.field input,.field select{width:100%}
.tpl{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.msgcard{border:1px solid var(--line);border-radius:14px;padding:13px 15px;margin-bottom:10px;background:#fcfdfb}
.msgcard.off{opacity:.55}
.msgcard h4{margin:0 0 4px;font-size:15px}
.msgmeta{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0;align-items:center}
@media(max-width:760px){
  .grid2{grid-template-columns:1fr}
  table,thead,tbody,th,td,tr{display:block} thead{display:none}
  tr{border:1px solid var(--line);border-radius:14px;margin-bottom:10px;padding:8px 12px;background:#fff}
  td{border:0;padding:5px 0} td:before{content:attr(data-label) "：";font-weight:700;color:#667357}
  .pop{width:min(330px,86vw)}
}
</style>
</head>
<body>
<header>
  <div class="brand"><i>🌰</i> پنل مدیریت پسته بازار</div>
  <a href="logout.php">خروج</a>
</header>
<main>
  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat g"><b><?= $stTotal ?></b><span>کل مشتری‌ها</span></div>
    <div class="stat g"><b><?= $stActive ?></b><span>اشتراک فعال</span></div>
    <div class="stat a"><b><?= $stSoon ?></b><span>رو به اتمام (۳ روز)</span></div>
    <div class="stat r"><b><?= $stExpired ?></b><span>منقضی‌شده</span></div>
    <div class="stat f"><b><?= $stFx ?></b><span>پلن ارزی</span></div>
  </div>

  <nav class="tabs">
    <a href="index.php" class="<?= $tab === 'customers' ? 'on' : '' ?>">👥 مشتری‌ها</a>
    <a href="index.php?tab=messages" class="<?= $tab === 'messages' ? 'on' : '' ?>">✉️ ارسال پیام</a>
    <a href="pay.php">💳 پرداخت و تمدید<?= $payPending ? ' (' . $payPending . ')' : '' ?></a>
  </nav>

<?php if ($tab === 'customers'): ?>

  <div class="card">
    <details class="add">
      <summary>➕ افزودن مشتری جدید</summary>
      <form method="post" action="actions.php" class="row" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_customer">
        <input type="text" name="phone" placeholder="شماره موبایل (مثال 0912...)" required>
        <input type="text" name="name" placeholder="نام (اختیاری)" maxlength="60">
        <span class="row" style="gap:4px">
          <input type="text" name="password" id="add-pw" placeholder="رمز عبور (اختیاری)" autocomplete="off" style="direction:ltr">
          <button type="button" class="secondary" onclick="genPw('add-pw')" title="ساخت رمز تصادفی">🎲</button>
        </span>
        <select name="plan" title="نوع اشتراک">
          <option value="rial" selected>پلن ریالی</option>
          <option value="fx">پلن ارزی (ریالی + دلاری)</option>
        </select>
        <select name="days">
          <option value="10" selected>۱۰ روزه (آزمایشی)</option>
          <option value="90">۳ ماهه</option>
          <option value="180">۶ ماهه</option>
          <option value="365">۱۲ ماهه</option>
        </select>
        <button type="submit" class="big">افزودن + فعال‌سازی</button>
      </form>
      <p class="muted" style="margin:10px 0 0">مشتری‌ها خودشان با شماره موبایل و کد پیامکی وارد می‌شوند و ۱۰ روز اشتراک آزمایشی خودکار می‌گیرند؛ این فرم برای موارد خاص است.</p>
    </details>
  </div>

  <div class="card">
    <div class="row" style="justify-content:space-between;margin-bottom:12px">
      <h3 style="margin:0">لیست مشتری‌ها (<?= count($customers) ?>)<?= $filter === 'expiring' ? ' — رو به اتمام' : '' ?></h3>
      <div class="row">
        <?php if ($filter === 'expiring'): ?>
          <a class="btn ghost" href="index.php">نمایش همه</a>
        <?php else: ?>
          <a class="btn ghost" href="index.php?filter=expiring">⏳ رو به اتمام (۳ روز)</a>
        <?php endif; ?>
        <form method="get" class="row" style="gap:6px">
          <select name="tier" title="سطح نسخه" onchange="this.form.submit()"><option value="">همهٔ سطح‌ها</option><option value="green"<?= $fTier === 'green' ? ' selected' : '' ?>>سبز</option><option value="gold"<?= $fTier === 'gold' ? ' selected' : '' ?>>طلایی</option></select>
          <select name="plan" title="پلن" onchange="this.form.submit()"><option value="">همهٔ پلن‌ها</option><option value="rial"<?= $fPlan === 'rial' ? ' selected' : '' ?>>ریالی</option><option value="fx"<?= $fPlan === 'fx' ? ' selected' : '' ?>>ارزی</option></select>
          <input type="text" name="q" placeholder="🔍 جستجو با شماره یا نام" value="<?= h($q) ?>">
        </form>
      </div>
    </div>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>مشتری</th><th>پلن</th><th>رمز</th><th>وضعیت</th><th>انقضا</th><th>باقی‌مانده</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php foreach ($customers as $c):
          $expired = $c['expiry_date'] < $today;
          $isActive = ((bool)$c['active']) && !$expired;
          $daysLeft = (int)floor((strtotime($c['expiry_date']) - strtotime($today)) / 86400);
          $isFxPlan = customer_plan($c) === PLAN_FX;
          $cTier = pb_tier_of($c);
          $cid = (int)$c['id'];
          $cBlocks = $blocksMap[$cid] ?? []; // LIMITS
      ?>
        <tr>
          <td data-label="مشتری" class="who"><b><?= h($c['name'] ?: '—') ?></b><span><?= h(display_phone($c['phone'])) ?></span></td>
          <td data-label="پلن"><span class="badge <?= $isFxPlan ? 'fx' : 'ok' ?>"><?= $isFxPlan ? 'ارزی' : 'ریالی' ?></span> <span class="badge <?= $cTier === 'gold' ? 'fx' : 'ok' ?>"><?= $cTier === 'gold' ? 'طلایی' : 'سبز' ?></span></td>
          <td data-label="رمز"><?= !empty($c['has_password']) ? '<span class="badge ok">دارد</span>' : '<span class="muted">—</span>' ?></td>
          <td data-label="وضعیت"><span class="badge <?= $isActive ? 'ok' : 'bad' ?>"><?= $isActive ? 'فعال' : ($expired ? 'منقضی' : 'غیرفعال') ?></span><?php if (in_array('login', $cBlocks, true)): ?> <span class="badge bad">ورود مسدود</span><?php endif; ?><?php if (in_array('pwchange', $cBlocks, true)): ?> <span class="badge bad">تغییر رمز مسدود</span><?php endif; ?></td>
          <td data-label="انقضا"><?= h($c['expiry_date']) ?></td>
          <td data-label="باقی‌مانده"><?= $expired ? '—' : ('<span' . ($daysLeft <= 3 ? ' style="color:#c0392b;font-weight:800"' : '') . '>' . $daysLeft . ' روز</span>') ?></td>
          <td data-label="عملیات">
            <div class="row">
              <a class="btn ghost" href="index.php?tab=messages&to=<?= h(display_phone($c['phone'])) ?>" title="ارسال پیام به این مشتری">✉️ پیام</a>
              <details class="menu">
                <summary>مدیریت ▾</summary>
                <div class="pop">
                  <div class="lbl">تمدید اشتراک</div>
                  <div class="row">
                  <?php foreach ([90 => '۳ ماه', 180 => '۶ ماه', 365 => '۱۲ ماه'] as $d => $lbl): ?>
                    <form method="post" action="actions.php">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="renew">
                      <input type="hidden" name="id" value="<?= $cid ?>"><input type="hidden" name="days" value="<?= $d ?>">
                      <button type="submit">+<?= $lbl ?></button>
                    </form>
                  <?php endforeach; ?>
                  </div>
                  <form method="post" action="actions.php">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="renew"><input type="hidden" name="id" value="<?= $cid ?>">
                    <input type="number" name="days" min="1" max="3650" placeholder="روز دلخواه" style="width:110px" required>
                    <button type="submit" class="secondary">تمدید</button>
                  </form>
                  <div class="lbl">نام</div>
                  <form method="post" action="actions.php">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="set_name"><input type="hidden" name="id" value="<?= $cid ?>">
                    <input type="text" name="name" value="<?= h($c['name'] ?? '') ?>" placeholder="نام" maxlength="60" style="width:150px" required>
                    <button type="submit" class="secondary">ذخیره</button>
                  </form>
                  <div class="lbl">رمز عبور</div>
                  <form method="post" action="actions.php" onsubmit="return confirm('با تعیین رمز جدید، همهٔ دستگاه‌های این مشتری خارج می‌شوند. ادامه؟')">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="set_password"><input type="hidden" name="id" value="<?= $cid ?>">
                    <input type="text" name="password" id="pw-<?= $cid ?>" placeholder="رمز جدید (حداقل ۶)" autocomplete="off" style="width:150px;direction:ltr" required>
                    <button type="button" class="secondary" onclick="genPw('pw-<?= $cid ?>')" title="ساخت رمز تصادفی">🎲</button>
                    <button type="submit" class="secondary"><?= !empty($c['has_password']) ? 'تغییر' : 'تعیین' ?></button>
                  </form>
                  <div class="lbl">نسخه (سطح × پلن)</div>
                  <form method="post" action="actions.php" onsubmit="return confirm('نسخهٔ این مشتری تغییر کند؟ (داده‌های او حذف نمی‌شود؛ مهاجرت دادهٔ ارتقا به طلایی هنوز خودکار نیست)')">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="set_edition"><input type="hidden" name="id" value="<?= $cid ?>">
                    <select name="tier" style="width:90px"><option value="green"<?= $cTier === 'green' ? ' selected' : '' ?>>سبز</option><option value="gold"<?= $cTier === 'gold' ? ' selected' : '' ?>>طلایی</option></select>
                    <select name="plan" style="width:90px"><option value="rial"<?= !$isFxPlan ? ' selected' : '' ?>>ریالی</option><option value="fx"<?= $isFxPlan ? ' selected' : '' ?>>ارزی</option></select>
                    <button type="submit" class="secondary">ذخیره</button>
                  </form>
                  <div class="lbl">پلن و وضعیت</div>
                  <div class="row">
                    <form method="post" action="actions.php" onsubmit="return confirm('نوع پلن این مشتری تغییر کند؟ (داده‌های او حذف نمی‌شود)')">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="set_plan"><input type="hidden" name="id" value="<?= $cid ?>">
                      <input type="hidden" name="plan" value="<?= $isFxPlan ? 'rial' : 'fx' ?>">
                      <button type="submit" class="secondary"><?= $isFxPlan ? 'برگشت به ریالی' : 'ارتقا به ارزی' ?></button>
                    </form>
                    <?php if ($cBlocks): ?>
                    <form method="post" action="actions.php">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="unblock"><input type="hidden" name="id" value="<?= $cid ?>">
                      <button type="submit" onclick="return confirm('مسدودی ورود و تغییر رمز این مشتری برداشته شود؟')">رفع مسدودی</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="actions.php">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= $cid ?>">
                      <button type="submit" class="secondary"><?= $c['active'] ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
                    </form>
                    <form method="post" action="actions.php">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $cid ?>">
                      <button type="submit" class="danger" onclick="return confirm('این مشتری و همهٔ داده‌هایش (خرید، فروش، چک‌ها، عکس‌ها و پشتیبان‌ها) برای همیشه حذف شود؟ این کار برگشت‌پذیر نیست.')">حذف</button>
                    </form>
                  </div>
                </div>
              </details>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$customers): ?>
        <tr><td colspan="7" style="text-align:center;color:#777">هنوز مشتری‌ای ثبت نشده.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php else: ?>

  <div class="card">
    <h3>✉️ پیام جدید</h3>
    <p class="muted" style="margin-top:-6px">پیام در «تب خانه»ی همهٔ نسخه‌ها (اندروید، ویندوز، وب) به‌صورت کارت بالای صفحه نشان داده می‌شود تا کاربر آن را ببندد.</p>
    <div class="tpl">
      <button type="button" class="ghost" onclick="tpl('update')">🔄 اطلاع‌رسانی آپدیت</button>
      <button type="button" class="ghost" onclick="tpl('greeting')">🎉 تبریک مناسبت</button>
      <button type="button" class="ghost" onclick="tpl('download')">⬇️ لینک دانلود نسخهٔ جدید</button>
    </div>
    <form method="post" action="actions.php" id="msgform">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="send_message">
      <div class="grid2">
        <div class="field">
          <label>گیرنده</label>
          <select name="target" id="m-target" onchange="toggleTarget()">
            <option value="all" <?= $toPhone === '' ? 'selected' : '' ?>>همهٔ مشتری‌ها</option>
            <option value="customer" <?= $toPhone !== '' ? 'selected' : '' ?>>یک مشتری مشخص</option>
          </select>
        </div>
        <div class="field" id="m-phone-box" style="<?= $toPhone === '' ? 'display:none' : '' ?>">
          <label>شماره موبایل مشتری</label>
          <input type="text" name="phone" id="m-phone" value="<?= h($toPhone) ?>" placeholder="0912..." style="direction:ltr">
        </div>
        <div class="field">
          <label>نوع پیام</label>
          <select name="kind" id="m-kind">
            <option value="info">اطلاعیه</option>
            <option value="update">آپدیت برنامه</option>
            <option value="greeting">تبریک / مناسبت</option>
          </select>
        </div>
        <div class="field">
          <label>نمایش تا (اختیاری)</label>
          <select name="expires_days">
            <option value="0">بدون محدودیت</option>
            <option value="3">۳ روز</option>
            <option value="7">۷ روز</option>
            <option value="30">۳۰ روز</option>
          </select>
        </div>
      </div>
      <div class="field" style="margin-top:12px"><label>عنوان *</label><input type="text" name="title" id="m-title" maxlength="120" required style="width:100%"></div>
      <div class="field" style="margin-top:12px"><label>متن پیام</label><textarea name="body" id="m-body" maxlength="2000"></textarea></div>
      <div class="grid2" style="margin-top:12px">
        <div class="field"><label>لینک (اختیاری؛ مثلاً لینک دانلود)</label><input type="url" name="link_url" id="m-link" placeholder="https://..." style="direction:ltr"></div>
        <div class="field"><label>متن دکمهٔ لینک</label><input type="text" name="link_label" id="m-label" maxlength="60" placeholder="دانلود نسخهٔ جدید"></div>
      </div>
      <div style="margin-top:16px"><button type="submit" class="big">📤 ارسال پیام</button></div>
    </form>
  </div>

  <div class="card">
    <h3>پیام‌های ارسال‌شده (<?= count($sent) ?>)</h3>
    <?php foreach ($sent as $m):
        $kinds = ['info' => ['اطلاعیه', 'info'], 'update' => ['آپدیت', 'fx'], 'greeting' => ['تبریک', 'ok']];
        [$kl, $kc] = $kinds[$m['kind']] ?? $kinds['info'];
        $expiredMsg = $m['expires_at'] !== null && $m['expires_at'] < date('Y-m-d H:i:s');
    ?>
      <div class="msgcard <?= ((int)$m['active'] === 1 && !$expiredMsg) ? '' : 'off' ?>">
        <h4><?= h($m['title']) ?></h4>
        <?php if ($m['body'] !== ''): ?><div style="white-space:pre-wrap"><?= h($m['body']) ?></div><?php endif; ?>
        <div class="msgmeta">
          <span class="badge <?= $kc ?>"><?= $kl ?></span>
          <span class="badge <?= $m['customer_id'] === null ? 'ok' : 'info' ?>"><?= $m['customer_id'] === null ? 'همه' : h(($m['cname'] ?: '') . ' ' . display_phone((string)$m['cphone'])) ?></span>
          <span class="muted">خوانده‌شده: <?= (int)$m['reads_count'] ?></span>
          <span class="muted"><?= h($m['created_at']) ?></span>
          <?php if ($expiredMsg): ?><span class="badge bad">منقضی</span><?php elseif ((int)$m['active'] !== 1): ?><span class="badge bad">غیرفعال</span><?php endif; ?>
          <?php if ($m['link_url']): ?><a class="muted" href="<?= h($m['link_url']) ?>" target="_blank" rel="noopener noreferrer" style="direction:ltr">🔗 <?= h($m['link_label'] ?: $m['link_url']) ?></a><?php endif; ?>
        </div>
        <div class="row">
          <form method="post" action="actions.php"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_message"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button type="submit" class="secondary"><?= (int)$m['active'] === 1 ? 'غیرفعال کردن' : 'فعال کردن' ?></button></form>
          <form method="post" action="actions.php" onsubmit="return confirm('این پیام حذف شود؟')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_message"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button type="submit" class="danger">حذف</button></form>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$sent): ?><p class="muted">هنوز پیامی ارسال نشده است.</p><?php endif; ?>
  </div>

<?php endif; ?>
</main>
<script>
// ساخت رمز تصادفی خوانا (بدون حروف/ارقام مشابه)
function genPw(id) {
  var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
  var a = new Uint32Array(8);
  crypto.getRandomValues(a);
  var out = '';
  for (var i = 0; i < a.length; i++) out += chars[a[i] % chars.length];
  document.getElementById(id).value = out;
}
function toggleTarget() {
  var t = document.getElementById('m-target');
  var box = document.getElementById('m-phone-box');
  if (box) box.style.display = (t.value === 'customer') ? '' : 'none';
}
// قالب‌های آماده
function tpl(k) {
  var T = {
    update:   {kind: 'update',   title: 'نسخهٔ جدید پسته بازار آمد 🔄', body: 'نسخهٔ جدید برنامه با امکانات و بهبودهای تازه منتشر شد. برای دریافت، روی دکمهٔ زیر بزنید.', label: 'دریافت آپدیت'},
    greeting: {kind: 'greeting', title: 'تبریک 🎉', body: 'مناسبت را به شما تبریک می‌گوییم؛ آرزوی کسب‌وکاری پربرکت برای شما داریم.', label: ''},
    download: {kind: 'update',   title: 'لینک دانلود نسخهٔ جدید', body: 'نسخهٔ جدید را از لینک زیر دانلود و نصب کنید. اطلاعات شما حفظ می‌شود.', label: 'دانلود نسخهٔ جدید'}
  }[k];
  document.getElementById('m-kind').value = T.kind;
  document.getElementById('m-title').value = T.title;
  document.getElementById('m-body').value = T.body;
  document.getElementById('m-label').value = T.label;
}
</script>
</body>
</html>
