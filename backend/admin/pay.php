<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require __DIR__ . '/pay_lib.php';
require_login();

$pdo = db();
$tab = (string)($_GET['tab'] ?? 'orders');
if (!in_array($tab, ['orders', 'all', 'codes', 'settings', 'contract', 'upgrades'], true)) $tab = 'orders';
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$dbError = '';
$st = ['awaiting' => 0, 'paid30' => 0, 'sum30' => 0];
$ug = ['runs' => [], 'grants' => [], 'candidates' => []]; $orders = []; $codes = []; $banks = []; $contracts = []; $acc = []; $S = []; $page = 1; $hasNext = false;
$q = trim((string)($_GET['q'] ?? ''));
$fstatus = (string)($_GET['status'] ?? '');
try {
    $st['awaiting'] = (int)$pdo->query("SELECT COUNT(*) FROM pay_orders WHERE status = 'awaiting_review'")->fetchColumn();
    $r = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total),0) s FROM pay_orders WHERE status = 'paid' AND activated_at >= (NOW() - INTERVAL 30 DAY)")->fetch();
    $st['paid30'] = (int)$r['c']; $st['sum30'] = (int)$r['s'];
    $S = pay_settings_get($pdo);

    $baseSel = "SELECT o.*, c.name AS cname, c.phone AS cphone,
        m.payer_name, m.card_last4, m.paid_at AS m_paid_at, m.amount AS m_amount, m.tracking_no, m.submitted_at,
        (SELECT t.ref_id FROM pay_transactions t WHERE t.order_id = o.id AND t.status = 'paid' ORDER BY t.id DESC LIMIT 1) AS ref_id
        FROM pay_orders o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN pay_manual m ON m.order_id = o.id";
    if ($tab === 'orders') {
        $orders = $pdo->query($baseSel . " WHERE o.status = 'awaiting_review' ORDER BY o.id ASC LIMIT 200")->fetchAll();
    } elseif ($tab === 'all') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $where = []; $args = [];
        if (in_array($fstatus, ['pending', 'awaiting_review', 'paid', 'rejected', 'canceled'], true)) { $where[] = 'o.status = ?'; $args[] = $fstatus; }
        if ($q !== '') {
            $qq = pay_en($q);
            $where[] = '(c.phone LIKE ? OR c.name LIKE ? OR o.public_id LIKE ?)';
            $like = '%' . $qq . '%';
            array_push($args, $like, '%' . $q . '%', $like);
        }
        $sql = $baseSel . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.id DESC LIMIT 51 OFFSET ' . (($page - 1) * 50);
        $stm = $pdo->prepare($sql); $stm->execute($args);
        $orders = $stm->fetchAll();
        if (count($orders) > 50) { $hasNext = true; array_pop($orders); }
    } elseif ($tab === 'codes') {
        $codes = $pdo->query("SELECT d.*, (SELECT COUNT(*) FROM discount_redemptions r WHERE r.code_id = d.id) AS used FROM discount_codes d ORDER BY d.id DESC")->fetchAll();
    } elseif ($tab === 'upgrades') {
        require_once __DIR__ . '/ug_admin.php';
        $ug = ug_admin_data($pdo);
    } elseif ($tab === 'settings') {
        $banks = $pdo->query('SELECT * FROM bank_accounts ORDER BY sort_order, id')->fetchAll();
    } else {
        $contracts = $pdo->query('SELECT * FROM contract_versions ORDER BY version DESC')->fetchAll();
        foreach ($pdo->query('SELECT version, COUNT(*) n FROM contract_acceptances GROUP BY version')->fetchAll() as $a) $acc[(int)$a['version']] = (int)$a['n'];
    }
} catch (Throwable $e) {
    error_log('pay.php: ' . $e->getMessage());
    $dbError = 'خواندن جدول‌های پرداخت ممکن نشد. مطمئن شوید migration_v10_payments.sql را در phpMyAdmin اجرا کرده‌اید.';
}

function pay_breakdown(array $o): string {
    $rows = [];
    if ((int)$o['upgrade_amount'] > 0) $rows[] = ['ارتقا به ارزی', pay_money($o['upgrade_amount'])];
    if ((int)$o['renew_normal'] > 0) $rows[] = ['قیمت عادی تمدید', pay_money($o['renew_normal'])];
    if ((int)$o['site_discount'] > 0) $rows[] = ['تخفیف سایت', '−' . pay_money($o['site_discount'])];
    if ((int)$o['code_discount'] > 0) $rows[] = ['کد ' . ($o['code_text'] ?? ''), '−' . pay_money($o['code_discount'])];
    if ((float)$o['vat_percent'] > 0) $rows[] = ['مالیات ' . pay_fa((string)(float)$o['vat_percent']) . '٪', pay_money($o['vat_amount'])];
    $rows[] = ['جمع (' . pay_unit_label() . ')', pay_money($o['total'])];
    $snap = json_decode((string)($o['snapshot'] ?? ''), true);
    if (is_array($snap) && !empty($snap['new_expiry'])) $rows[] = ['پایان اشتراک پس از فعال‌سازی', pay_jdate((string)$snap['new_expiry'])];
    $h = '<div class="kv">';
    foreach ($rows as $r) $h .= '<span>' . h($r[0]) . '</span><span>' . h($r[1]) . '</span>';
    return $h . '</div>';
}
function pay_hidden(string $action, string $tab, array $extra = []): string {
    $s = '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="tab" value="' . h($tab) . '">';
    foreach ($extra as $k => $v) $s .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    return $s;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>پرداخت و تمدید — پسته بازار</title>
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

.stats a.stat{text-decoration:none;color:inherit}
.chips{display:flex;gap:10px;flex-wrap:wrap}
.chips label{font-weight:600;font-size:13px;background:#f3f6ef;padding:5px 10px;border-radius:20px;cursor:pointer}
.kv{display:grid;grid-template-columns:auto 1fr;gap:3px 14px;font-size:13.5px;margin:8px 0}
.kv span:nth-child(odd){color:var(--mut)}
.ltr{direction:ltr;unicode-bidi:plaintext;text-align:left}
.warn{background:#fff4d6;border:1px solid #f0d68a;color:#6b4a00;padding:8px 12px;border-radius:10px;font-size:13px;margin:8px 0}
.ordercard{border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:12px;background:#fcfdfb}
.ordercard h4{margin:0 0 4px;font-size:15px}
.pager{display:flex;gap:8px;justify-content:center;margin-top:12px}
pre.contract{white-space:pre-wrap;font:inherit;background:#fafcf7;border:1px solid var(--line);border-radius:12px;padding:12px;max-height:340px;overflow:auto;margin:8px 0}
textarea.big{min-height:320px}
.pop.wide{width:min(360px,86vw)}

</style>
</head>
<body>
<header>
  <div class="brand"><i>🌰</i> پنل مدیریت پسته بازار</div>
  <a href="logout.php">خروج</a>
</header>
<main>
  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($dbError): ?><div class="warn"><?= h($dbError) ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat a"><b><?= pay_fa((string)$st['awaiting']) ?></b><span>در انتظار بررسی</span></div>
    <div class="stat g"><b><?= pay_fa((string)$st['paid30']) ?></b><span>پرداخت موفق (۳۰ روز)</span></div>
    <div class="stat g"><b style="font-size:21px"><?= pay_money($st['sum30']) ?></b><span>جمع فروش ۳۰ روز (<?= h(pay_unit_label()) ?>)</span></div>
  </div>

  <nav class="tabs" style="flex-wrap:wrap">
    <a href="index.php">👥 مشتری‌ها</a>
    <a href="index.php?tab=messages">✉️ ارسال پیام</a>
    <a href="pay.php?tab=orders" class="<?= $tab === 'orders' ? 'on' : '' ?>">🧾 کارت‌به‌کارت<?= $st['awaiting'] ? ' (' . pay_fa((string)$st['awaiting']) . ')' : '' ?></a>
    <a href="pay.php?tab=all" class="<?= $tab === 'all' ? 'on' : '' ?>">📋 همهٔ سفارش‌ها</a>
    <a href="pay.php?tab=codes" class="<?= $tab === 'codes' ? 'on' : '' ?>">🏷️ کد تخفیف</a>
    <a href="pay.php?tab=settings" class="<?= $tab === 'settings' ? 'on' : '' ?>">⚙️ تنظیمات پرداخت</a>
    <a href="pay.php?tab=upgrades" class="<?= $tab === 'upgrades' ? 'on' : '' ?>">⬆️ ارتقا به طلایی</a>
    <a href="pay.php?tab=contract" class="<?= $tab === 'contract' ? 'on' : '' ?>">📜 قرارداد</a>
  </nav>

<?php if ($tab === 'orders'): ?>
  <div class="card">
    <h3>سفارش‌های کارت‌به‌کارت / شبا در انتظار بررسی (<?= pay_fa((string)count($orders)) ?>)</h3>
    <p class="muted" style="margin-top:-6px">مبلغ و ۴ رقم آخر کارت را با حساب بانکی خودتان مقایسه کنید. با «تأیید»، اشتراک بلافاصله فعال و پیام به مشتری ارسال می‌شود. قیمت‌ها از پنل سایت می‌آید و اینجا قابل ویرایش نیست.</p>
    <?php foreach ($orders as $o):
        [$sl, $sc] = pay_status_label((string)$o['status']);
        $mismatch = $o['m_amount'] !== null && (int)$o['m_amount'] !== (int)$o['total'];
    ?>
      <div class="ordercard">
        <h4><?= h(($o['cname'] ?: '—')) ?> <span class="muted ltr"><?= h(display_phone((string)$o['cphone'])) ?></span></h4>
        <div class="msgmeta">
          <span class="badge <?= $sc ?>"><?= h($sl) ?></span>
          <span class="badge info"><?= h(pay_type_label((string)$o['order_type'])) ?></span>
          <span class="badge <?= $o['plan'] === 'fx' ? 'fx' : 'ok' ?>"><?= h(pay_plan_label((string)$o['plan'])) ?><?= (int)$o['months'] > 0 ? ' — ' . pay_fa((string)$o['months']) . ' ماهه' : '' ?></span>
          <span class="badge info"><?= h(pay_method_label($o['method'])) ?></span>
          <span class="muted">سفارش <?= h(pay_fa((string)$o['public_id'])) ?> — <?= h(pay_jdate((string)$o['created_at'], true)) ?></span>
        </div>
        <?= pay_breakdown($o) ?>
        <?php if ($o['payer_name'] !== null): ?>
          <div class="kv">
            <span>نام پرداخت‌کننده</span><span><?= h($o['payer_name']) ?></span>
            <span>۴ رقم آخر کارت</span><span><?= h(pay_fa((string)$o['card_last4'])) ?></span>
            <span>تاریخ و ساعت پرداخت</span><span><?= h(pay_jdate((string)$o['m_paid_at'], true)) ?></span>
            <span>مبلغ اعلام‌شده (<?= h(pay_unit_label()) ?>)</span><span><?= h(pay_money($o['m_amount'])) ?></span>
            <span>شمارهٔ پیگیری</span><span><?= h($o['tracking_no'] !== null && $o['tracking_no'] !== '' ? pay_fa((string)$o['tracking_no']) : '—') ?></span>
          </div>
          <?php if ($mismatch): ?><div class="warn">⚠️ مبلغ اعلام‌شدهٔ کاربر با جمع سفارش یکی نیست.</div><?php endif; ?>
        <?php elseif ((string)$o['method'] === 'gateway'): ?>
          <div class="warn">💳 پرداخت از درگاه انجام شده است. <?= h((string)($o['admin_note'] ?? '')) ?></div>
        <?php else: ?>
          <div class="warn">اطلاعات پرداخت هنوز توسط کاربر ثبت نشده است.</div>
        <?php endif; ?>
        <div class="row" style="margin-top:8px">
          <form method="post" action="pay_actions.php" onsubmit="return confirm('پرداخت تأیید و اشتراک مشتری فعال شود؟')">
            <?= pay_hidden('approve_order', 'orders', ['id' => (int)$o['id']]) ?>
            <button type="submit" <?= ($o['payer_name'] === null && (string)$o['method'] !== 'gateway') ? 'disabled title="هنوز اطلاعات پرداخت ثبت نشده"' : '' ?>>✅ تأیید و فعال‌سازی</button>
          </form>
          <details class="menu">
            <summary>❌ رد سفارش</summary>
            <div class="pop wide">
              <form method="post" action="pay_actions.php">
                <?= pay_hidden('reject_order', 'orders', ['id' => (int)$o['id']]) ?>
                <input type="text" name="reason" maxlength="255" placeholder="دلیل رد (برای مشتری ارسال می‌شود)" required style="width:100%">
                <button type="submit" class="danger">رد و ارسال پیام</button>
              </form>
            </div>
          </details>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$orders): ?><p class="muted">سفارشی در انتظار بررسی نیست. 🎉</p><?php endif; ?>
  </div>

<?php elseif ($tab === 'all'): ?>
  <div class="card">
    <div class="row" style="justify-content:space-between;margin-bottom:12px">
      <h3 style="margin:0">همهٔ سفارش‌ها و پرداخت‌ها</h3>
      <form method="get" class="row">
        <input type="hidden" name="tab" value="all">
        <select name="status" onchange="this.form.submit()">
          <option value="">همهٔ وضعیت‌ها</option>
          <?php foreach (['pending','awaiting_review','paid','rejected','canceled'] as $s): ?>
            <option value="<?= $s ?>" <?= $fstatus === $s ? 'selected' : '' ?>><?= h(pay_status_label($s)[0]) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="🔍 شماره، نام یا شمارهٔ سفارش">
      </form>
    </div>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>سفارش</th><th>مشتری</th><th>نوع</th><th>پلن</th><th>مبلغ (<?= h(pay_unit_label()) ?>)</th><th>روش</th><th>وضعیت</th><th>تاریخ</th><th>جزئیات</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): [$sl, $sc] = pay_status_label((string)$o['status']); ?>
        <tr>
          <td data-label="سفارش"><?= h(pay_fa((string)$o['public_id'])) ?></td>
          <td data-label="مشتری" class="who"><b><?= h($o['cname'] ?: '—') ?></b><span><?= h(display_phone((string)$o['cphone'])) ?></span></td>
          <td data-label="نوع"><?= h(pay_type_label((string)$o['order_type'])) ?></td>
          <td data-label="پلن"><?= h(pay_plan_label((string)$o['plan'])) ?><?= (int)$o['months'] > 0 ? ' ' . pay_fa((string)$o['months']) . ' ماهه' : '' ?></td>
          <td data-label="مبلغ"><?= h(pay_money($o['total'])) ?></td>
          <td data-label="روش"><?= h(pay_method_label($o['method'])) ?></td>
          <td data-label="وضعیت"><span class="badge <?= $sc ?>"><?= h($sl) ?></span><?php if ($o['status'] === 'rejected' && $o['reject_reason']): ?><div class="muted"><?= h($o['reject_reason']) ?></div><?php endif; ?></td>
          <td data-label="تاریخ"><?= h(pay_jdate((string)$o['created_at'], true)) ?></td>
          <td data-label="جزئیات">
            <details class="menu"><summary>نمایش</summary>
              <div class="pop wide">
                <?= pay_breakdown($o) ?>
                <?php if ($o['ref_id']): ?><div class="kv"><span>کد پیگیری درگاه</span><span class="ltr"><?= h((string)$o['ref_id']) ?></span></div><?php endif; ?>
                <?php if ($o['payer_name'] !== null): ?><div class="kv"><span>پرداخت‌کننده</span><span><?= h($o['payer_name']) ?> — <?= h(pay_fa((string)$o['card_last4'])) ?></span></div><?php endif; ?>
                <?php if ($o['activated_at']): ?><div class="kv"><span>فعال‌سازی</span><span><?= h(pay_jdate((string)$o['activated_at'], true)) ?></span></div><?php endif; ?>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="9" style="text-align:center;color:#777">سفارشی پیدا نشد.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <div class="pager">
      <?php $qs = 'tab=all&status=' . urlencode($fstatus) . '&q=' . urlencode($q); ?>
      <?php if ($page > 1): ?><a class="btn ghost" href="pay.php?<?= $qs ?>&page=<?= $page - 1 ?>">قبلی</a><?php endif; ?>
      <?php if ($hasNext): ?><a class="btn ghost" href="pay.php?<?= $qs ?>&page=<?= $page + 1 ?>">بعدی</a><?php endif; ?>
    </div>
  </div>

<?php elseif ($tab === 'upgrades'): ?>
  <div class="card">
    <h3>➕ ثبت دستی «حق ارتقا به طلایی»</h3>
    <p class="muted" style="margin-top:-6px">برای توافق حضوری یا فیش خارج از درگاه. سطح حساب تغییر نمی‌کند؛ مالک حساب ارتقا را از داخل برنامه (ویزارد) اجرا می‌کند. فقط اشتراک فعال سبز.</p>
    <form method="post" action="pay_actions.php" class="row">
      <?= pay_hidden('manual_tier_upgrade', 'upgrades') ?>
      <select name="customer_id" required>
        <option value="">— انتخاب مشتری سبز با اشتراک فعال —</option>
        <?php foreach ($ug['candidates'] as $cd): ?>
          <option value="<?= (int)$cd['id'] ?>"><?= h(($cd['name'] ?: '—') . ' — ' . display_phone((string)$cd['phone'])) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="note" placeholder="شرح (شمارهٔ فیش / توافق) — الزامی" maxlength="200" required style="min-width:260px">
      <button type="submit" class="big" onclick="return confirm('حق ارتقا به طلایی برای این مشتری ثبت شود؟')">ثبت حق ارتقا</button>
    </form>
  </div>

  <div class="card">
    <h3>اجرای ارتقاها (<?= pay_fa((string)count($ug['runs'])) ?>)</h3>
    <p class="muted" style="margin-top:-6px">«ادامه»: اجرای ناموفق/نیمه‌کاره با همان تنظیمات ذخیره‌شده ادامه می‌یابد. «بررسی بازگشت»: فقط گزارش می‌دهد. «بازگشت»: لایهٔ حسابداری طلایی حذف و حساب سبز می‌شود (فقط تا ۷ روز و بدون دادهٔ اختصاصی طلایی؛ اسناد عملیاتی دست نمی‌خورند).</p>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>#</th><th>مشتری</th><th>وضعیت</th><th>مرحله</th><th>شروع</th><th>پایان</th><th>خطا</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php $firstPerCid = []; foreach ($ug['runs'] as $r): $rc = (int)$r['customer_id']; $isLatest = !isset($firstPerCid[$rc]); $firstPerCid[$rc] = true; ?>
        <tr>
          <td><?= pay_fa((string)$r['id']) ?></td>
          <td><?= h((string)($r['cname'] ?: '—')) ?> <span class="muted ltr"><?= h(display_phone((string)$r['cphone'])) ?></span></td>
          <td><?= h(ug_status_label((string)$r['status'])) ?></td>
          <td class="ltr"><?= h((string)$r['step']) ?></td>
          <td><?= h(pay_jdate((string)$r['started_at'], true)) ?></td>
          <td><?= $r['finished_at'] ? h(pay_jdate((string)$r['finished_at'], true)) : '—' ?></td>
          <td class="muted"><?= h((string)($r['error'] ?? '')) ?></td>
          <td>
            <?php if ($isLatest): ?>
              <?php if (in_array((string)$r['status'], ['running', 'failed'], true)): ?>
                <form method="post" action="pay_actions.php" style="display:inline"><?= pay_hidden('ug_retry', 'upgrades', ['run_id' => (int)$r['id']]) ?><button type="submit" class="secondary">▶️ ادامه</button></form>
              <?php endif; ?>
              <?php if ((string)$r['status'] !== 'rolled_back'): ?>
                <form method="post" action="pay_actions.php" style="display:inline"><?= pay_hidden('ug_rollback_check', 'upgrades', ['run_id' => (int)$r['id']]) ?><button type="submit" class="secondary">🔍 بررسی بازگشت</button></form>
                <form method="post" action="pay_actions.php" style="display:inline" onsubmit="return confirm('لایهٔ حسابداری طلایی این مشتری حذف و حساب سبز شود؟')"><?= pay_hidden('ug_rollback', 'upgrades', ['run_id' => (int)$r['id']]) ?><button type="submit" class="danger">↩️ بازگشت</button></form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (!$ug['runs']): ?><p class="muted">هنوز ارتقایی اجرا نشده است.</p><?php endif; ?>
  </div>

  <div class="card">
    <h3>حق‌های ارتقا (خرید یا دستی)</h3>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>سفارش</th><th>مشتری</th><th>روش</th><th>وضعیت</th><th>مبلغ (<?= h(pay_unit_label()) ?>)</th><th>فعال‌سازی</th></tr></thead>
      <tbody>
      <?php foreach ($ug['grants'] as $g): ?>
        <tr>
          <td class="ltr"><?= h((string)$g['public_id']) ?></td>
          <td><?= h((string)($g['cname'] ?: '—')) ?> <span class="muted ltr"><?= h(display_phone((string)$g['cphone'])) ?></span></td>
          <td><?= h(pay_method_label($g['method'] ?? null)) ?></td>
          <td><?= h((string)(pay_status_label((string)$g['status'])[0] ?? $g['status'])) ?></td>
          <td><?= pay_money($g['total']) ?></td>
          <td><?= $g['activated_at'] ? h(pay_jdate((string)$g['activated_at'], true)) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (!$ug['grants']): ?><p class="muted">سفارش ارتقایی ثبت نشده است.</p><?php endif; ?>
  </div>

<?php elseif ($tab === 'codes'): ?>
  <div class="card">
    <h3>🏷️ ساخت کد تخفیف</h3>
    <?php if (($S['discount_enabled'] ?? '1') !== '1'): ?><div class="warn">کلید کلی کد تخفیف الان <b>خاموش</b> است؛ در «تنظیمات پرداخت» روشنش کنید.</div><?php endif; ?>
    <form method="post" action="pay_actions.php">
      <?= pay_hidden('add_code', 'codes') ?>
      <div class="grid2">
        <div class="field"><label>کد (انگلیسی) *</label><input type="text" name="code" maxlength="40" required style="direction:ltr;text-transform:uppercase" placeholder="MEHR1405"></div>
        <div class="field"><label>نوع *</label>
          <select name="type" id="c-type" onchange="codeType()">
            <option value="percent">درصدی</option>
            <option value="fixed">مبلغ ثابت (<?= h(pay_unit_label()) ?>)</option>
            <option value="free_upgrade">ارتقا به ارزی رایگان</option>
          </select></div>
        <div class="field" id="c-val-box"><label id="c-val-lbl">درصد تخفیف (۱ تا ۱۰۰)</label><input type="text" inputmode="numeric" name="value" value="10" style="direction:ltr"></div>
        <div class="field"><label>اعتبار (روز؛ ۰ = بدون محدودیت)</label><input type="number" name="expires_days" min="0" max="3650" value="0"></div>
        <div class="field"><label>سقف کل استفاده (۰ = نامحدود)</label><input type="number" name="max_total" min="0" value="0"></div>
        <div class="field"><label>سقف برای هر کاربر (۰ = نامحدود)</label><input type="number" name="max_per_user" min="0" value="1"></div>
      </div>
      <p class="muted" style="margin:12px 0 4px">محدودیت‌ها (اگر هیچ‌کدام را نزنید = همه):</p>
      <div class="chips" style="margin-bottom:6px"><b class="muted">پلن:</b>
        <label><input type="checkbox" name="plans[]" value="rial"> ریالی</label><label><input type="checkbox" name="plans[]" value="fx"> ارزی</label></div>
      <div class="chips" style="margin-bottom:6px"><b class="muted">مدت:</b>
        <label><input type="checkbox" name="months[]" value="3"> ۳ ماه</label><label><input type="checkbox" name="months[]" value="6"> ۶ ماه</label><label><input type="checkbox" name="months[]" value="12"> ۱۲ ماه</label></div>
      <div class="chips" style="margin-bottom:10px"><b class="muted">نوع سفارش:</b>
        <label><input type="checkbox" name="order_types[]" value="renew"> تمدید</label><label><input type="checkbox" name="order_types[]" value="upgrade"> ارتقا</label><label><input type="checkbox" name="order_types[]" value="upgrade_renew"> ارتقا + تمدید</label></div>
      <div class="field"><label>یادداشت (فقط برای خودتان)</label><input type="text" name="note" maxlength="255" style="width:100%"></div>
      <div style="margin-top:14px"><button type="submit" class="big">ساخت کد</button></div>
    </form>
    <p class="muted">کد روی قیمت بعد از تخفیف‌های سایت اعمال می‌شود. در هر سفارش فقط یک کد و ترکیب کدها ممکن نیست.</p>
  </div>

  <div class="card">
    <h3>کدها (<?= pay_fa((string)count($codes)) ?>)</h3>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>کد</th><th>نوع</th><th>محدودیت</th><th>مصرف</th><th>انقضا</th><th>وضعیت</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php foreach ($codes as $d):
        $expd = $d['expires_at'] !== null && $d['expires_at'] < date('Y-m-d H:i:s');
        $tl = $d['type'] === 'percent' ? pay_fa((string)$d['value']) . '٪' : ($d['type'] === 'fixed' ? pay_money($d['value']) . ' ' . pay_unit_label() : 'ارتقا رایگان');
        $lim = [];
        if ($d['plans']) $lim[] = 'پلن: ' . implode('، ', array_map('pay_plan_label', explode(',', $d['plans'])));
        if ($d['months']) $lim[] = 'مدت: ' . pay_fa(str_replace(',', '، ', $d['months']));
        if ($d['order_types']) $lim[] = implode('، ', array_map('pay_type_label', explode(',', $d['order_types'])));
      ?>
        <tr>
          <td data-label="کد" class="ltr"><b><?= h($d['code']) ?></b><?php if ($d['note']): ?><div class="muted"><?= h($d['note']) ?></div><?php endif; ?></td>
          <td data-label="نوع"><?= h($tl) ?></td>
          <td data-label="محدودیت"><span class="muted"><?= h($lim ? implode(' | ', $lim) : 'بدون محدودیت') ?></span></td>
          <td data-label="مصرف"><?= pay_fa((string)(int)$d['used']) ?><?= $d['max_total'] !== null ? ' از ' . pay_fa((string)$d['max_total']) : '' ?><?= $d['max_per_user'] !== null ? '<div class="muted">هر کاربر ' . pay_fa((string)$d['max_per_user']) . '</div>' : '' ?></td>
          <td data-label="انقضا"><?= $d['expires_at'] ? h(pay_jdate((string)$d['expires_at'])) : '—' ?></td>
          <td data-label="وضعیت"><span class="badge <?= ((int)$d['active'] === 1 && !$expd) ? 'ok' : 'bad' ?>"><?= $expd ? 'منقضی' : ((int)$d['active'] === 1 ? 'فعال' : 'غیرفعال') ?></span></td>
          <td data-label="عملیات"><div class="row">
            <form method="post" action="pay_actions.php"><?= pay_hidden('toggle_code', 'codes', ['id' => (int)$d['id']]) ?><button type="submit" class="secondary"><?= (int)$d['active'] === 1 ? 'غیرفعال' : 'فعال' ?></button></form>
            <form method="post" action="pay_actions.php" onsubmit="return confirm('این کد حذف شود؟')"><?= pay_hidden('delete_code', 'codes', ['id' => (int)$d['id']]) ?><button type="submit" class="danger">حذف</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$codes): ?><tr><td colspan="7" style="text-align:center;color:#777">هنوز کدی ساخته نشده.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

<?php elseif ($tab === 'settings'): ?>
  <div class="card">
    <h3>⚙️ تنظیمات کلی</h3>
    <form method="post" action="pay_actions.php">
      <?= pay_hidden('save_settings', 'settings') ?>
      <div class="field" style="max-width:260px"><label>درصد مالیات بر ارزش افزوده (۰ = خاموش)</label>
        <input type="text" inputmode="decimal" name="vat_percent" value="<?= h((string)($S['vat_percent'] ?? '0')) ?>" style="direction:ltr"></div>
      <p class="muted">با روشن شدن، روی قیمت نهایی (بعد از همهٔ تخفیف‌ها) اضافه می‌شود و در فاکتور خط جدا می‌خورد. قیمت‌های خود سایت بدون مالیات است.</p>
      <div class="chips" style="margin:10px 0">
        <label><input type="checkbox" name="discount_enabled" value="1" <?= ($S['discount_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> کد تخفیف روشن</label>
        <label><input type="checkbox" name="gateway_enabled" value="1" <?= ($S['gateway_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> درگاه زرین‌پال</label>
        <label><input type="checkbox" name="card_enabled" value="1" <?= ($S['card_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> کارت‌به‌کارت</label>
        <label><input type="checkbox" name="sheba_enabled" value="1" <?= ($S['sheba_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> شبا</label>
      </div>
      <button type="submit" class="big">ذخیره</button>
    </form>
  </div>

  <div class="card">
    <h3>🏦 حساب‌های بانکی (نمایش در برنامه برای کارت‌به‌کارت و شبا)</h3>
    <?php foreach ($banks as $b): ?>
      <div class="msgcard <?= (int)$b['active'] === 1 ? '' : 'off' ?>">
        <h4><?= h($b['bank_name']) ?> — <?= h($b['owner_name']) ?> <?php if ((int)$b['active'] !== 1): ?><span class="badge bad">غیرفعال</span><?php endif; ?></h4>
        <div class="muted ltr"><?= $b['card_number'] ? h(implode('-', str_split((string)$b['card_number'], 4))) : '' ?><?= $b['card_number'] && $b['sheba'] ? ' | ' : '' ?><?= h((string)$b['sheba']) ?></div>
        <?php if ($b['note']): ?><div class="muted"><?= h($b['note']) ?></div><?php endif; ?>
        <div class="row" style="margin-top:8px">
          <form method="post" action="pay_actions.php"><?= pay_hidden('toggle_bank', 'settings', ['id' => (int)$b['id']]) ?><button type="submit" class="secondary"><?= (int)$b['active'] === 1 ? 'غیرفعال کردن' : 'فعال کردن' ?></button></form>
          <form method="post" action="pay_actions.php" onsubmit="return confirm('این حساب حذف شود؟')"><?= pay_hidden('delete_bank', 'settings', ['id' => (int)$b['id']]) ?><button type="submit" class="danger">حذف</button></form>
          <details class="menu"><summary>ویرایش ▾</summary>
            <div class="pop wide">
              <form method="post" action="pay_actions.php" style="flex-direction:column;align-items:stretch">
                <?= pay_hidden('update_bank', 'settings', ['id' => (int)$b['id']]) ?>
                <input type="text" name="bank_name" value="<?= h($b['bank_name']) ?>" placeholder="نام بانک" required>
                <input type="text" name="owner_name" value="<?= h($b['owner_name']) ?>" placeholder="نام صاحب حساب" required>
                <input type="text" name="card_number" value="<?= h((string)$b['card_number']) ?>" placeholder="شمارهٔ کارت (۱۶ رقم)" style="direction:ltr">
                <input type="text" name="sheba" value="<?= h((string)$b['sheba']) ?>" placeholder="شبا (IR...)" style="direction:ltr">
                <input type="text" name="note" value="<?= h((string)$b['note']) ?>" placeholder="توضیح (اختیاری)">
                <input type="number" name="sort_order" value="<?= (int)$b['sort_order'] ?>" placeholder="ترتیب نمایش">
                <button type="submit">ذخیره</button>
              </form>
            </div>
          </details>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$banks): ?><p class="muted">هنوز حسابی ثبت نشده؛ تا حساب فعال نباشد کارت‌به‌کارت و شبا در برنامه کار نمی‌کند.</p><?php endif; ?>
    <details class="add" style="margin-top:12px">
      <summary>➕ افزودن حساب</summary>
      <form method="post" action="pay_actions.php" class="grid2" style="margin-top:12px">
        <?= pay_hidden('add_bank', 'settings') ?>
        <div class="field"><label>نام بانک *</label><input type="text" name="bank_name" required></div>
        <div class="field"><label>نام صاحب حساب *</label><input type="text" name="owner_name" required></div>
        <div class="field"><label>شمارهٔ کارت (۱۶ رقم)</label><input type="text" name="card_number" style="direction:ltr"></div>
        <div class="field"><label>شبا (IR + ۲۴ رقم)</label><input type="text" name="sheba" style="direction:ltr"></div>
        <div class="field"><label>توضیح (اختیاری)</label><input type="text" name="note" maxlength="255"></div>
        <div class="field"><label>ترتیب نمایش</label><input type="number" name="sort_order" value="0"></div>
        <div><button type="submit" class="big">افزودن</button></div>
      </form>
    </details>
  </div>

<?php else: ?>
  <?php $latestPub = 0; foreach ($contracts as $c) { if ((int)$c['published'] === 1) { $latestPub = (int)$c['version']; break; } } ?>
  <div class="card">
    <h3>📜 نسخهٔ جدید قرارداد</h3>
    <div class="warn">این برنامه حقوقدان نیست. متن را حتماً یک وکیل مرور کند. نسخهٔ منتشرشده دیگر قابل ویرایش نیست؛ برای تغییر، نسخهٔ جدید بسازید. با انتشار، همهٔ کاربران باید دوباره تأیید کنند.</div>
    <form method="post" action="pay_actions.php">
      <?= pay_hidden('save_contract', 'contract') ?>
      <div class="field"><label>عنوان *</label><input type="text" name="title" id="k-title" maxlength="120" required style="width:100%" value="قرارداد و شرایط استفاده از پسته بازار"></div>
      <div class="field" style="margin-top:10px"><label>متن *</label><textarea name="body" id="k-body" class="big" required></textarea></div>
      <div class="row" style="margin-top:12px">
        <button type="button" class="ghost" onclick="fillTpl()">درج متن پیشنهادی</button>
        <button type="submit" name="mode" value="draft" class="secondary">ذخیره به‌عنوان پیش‌نویس</button>
        <button type="submit" name="mode" value="publish" class="big" onclick="return confirm('منتشر شود؟ همهٔ کاربران باید دوباره تأیید کنند.')">انتشار</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>نسخه‌ها</h3>
    <?php foreach ($contracts as $c): $pub = (int)$c['published'] === 1; ?>
      <div class="msgcard">
        <h4>نسخهٔ <?= pay_fa((string)$c['version']) ?> — <?= h($c['title']) ?>
          <?php if ($pub && (int)$c['version'] === $latestPub): ?><span class="badge ok">نسخهٔ فعلی</span><?php elseif ($pub): ?><span class="badge info">منتشرشده (قدیمی)</span><?php else: ?><span class="badge fx">پیش‌نویس</span><?php endif; ?></h4>
        <div class="muted"><?= h(pay_jdate((string)$c['created_at'], true)) ?> — تأییدشده توسط <?= pay_fa((string)($acc[(int)$c['version']] ?? 0)) ?> کاربر</div>
        <?php if ($pub): ?>
          <details style="margin-top:8px"><summary class="muted" style="cursor:pointer">نمایش متن</summary><pre class="contract"><?= h($c['body']) ?></pre></details>
        <?php else: ?>
          <details style="margin-top:8px"><summary class="muted" style="cursor:pointer">ویرایش پیش‌نویس</summary>
            <form method="post" action="pay_actions.php">
              <?= pay_hidden('update_contract', 'contract', ['id' => (int)$c['id']]) ?>
              <input type="text" name="title" value="<?= h($c['title']) ?>" maxlength="120" style="width:100%;margin:8px 0" required>
              <textarea name="body" class="big" required><?= h($c['body']) ?></textarea>
              <div class="row" style="margin-top:8px"><button type="submit" class="secondary">ذخیره</button></div>
            </form>
          </details>
          <div class="row" style="margin-top:8px">
            <form method="post" action="pay_actions.php" onsubmit="return confirm('منتشر شود؟ همهٔ کاربران باید دوباره تأیید کنند.')"><?= pay_hidden('publish_contract', 'contract', ['id' => (int)$c['id']]) ?><button type="submit">انتشار</button></form>
            <form method="post" action="pay_actions.php" onsubmit="return confirm('پیش‌نویس حذف شود؟')"><?= pay_hidden('delete_contract', 'contract', ['id' => (int)$c['id']]) ?><button type="submit" class="danger">حذف</button></form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$contracts): ?><p class="muted">هنوز نسخه‌ای ساخته نشده. تا یک نسخه منتشر نشود، کاربران قراردادی برای تأیید نمی‌بینند.</p><?php endif; ?>
  </div>
  <script>var TPL = <?= json_encode(pay_contract_template(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  function fillTpl(){ var b=document.getElementById('k-body'); if(b.value.trim()&&!confirm('متن فعلی جایگزین شود؟'))return; b.value=TPL; }</script>
<?php endif; ?>
</main>
<script>
function codeType(){
  var t=document.getElementById('c-type'); if(!t)return;
  var box=document.getElementById('c-val-box'), l=document.getElementById('c-val-lbl');
  box.style.display = t.value==='free_upgrade' ? 'none' : '';
  l.textContent = t.value==='percent' ? 'درصد تخفیف (۱ تا ۱۰۰)' : 'مبلغ تخفیف (<?= h(pay_unit_label()) ?>)';
}
</script>
</body>
</html>
