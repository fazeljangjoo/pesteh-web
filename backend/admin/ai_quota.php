<?php
declare(strict_types=1);
/**
 * پسته‌کاو (A9) — سهمیهٔ روزانهٔ اختصاصی هر مشتری (فقط ادمین).
 * نبود ردیف = سقف پیش‌فرض کانفیگ (assistant_config.php → ai.daily_tokens).
 * شماره‌ها ماسک می‌شوند؛ لاگ فقط شناسه و عدد سقف را ثبت می‌کند.
 */
require __DIR__ . '/functions.php';
require_login();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');

const AI_QUOTA_MAX = 10000000;

/** شمارهٔ ماسک‌شده برای نمایش، مثل 0913***4567 */
function ai_quota_mask_phone(?string $normalized): string {
    $p = display_phone((string)$normalized);
    if (strlen($p) < 8) return '—';
    return substr($p, 0, 4) . '***' . substr($p, -4);
}

// سقف پیش‌فرض از کانفیگ (فقط خواندن آرایه؛ بدون اجرای منطق دستیار)
if (!defined('PB_ASSISTANT')) define('PB_ASSISTANT', 1);
$acfg = require __DIR__ . '/../api/assistant_config.php';
$defaultTokens = is_array($acfg) ? (int)($acfg['ai']['daily_tokens'] ?? 60000) : 60000;

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS assistant_ai_quota (
    customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
    daily_tokens INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$msg = '';
$msgTone = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $cid = (int)($_POST['customer_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $exists = false;
    if ($cid > 0) {
        $st = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $st->execute([$cid]);
        $exists = (bool)$st->fetchColumn();
    }
    if (!$exists) {
        $msg = 'مشتری با این شناسه پیدا نشد.'; $msgTone = 'err';
    } elseif ($action === 'clear') {
        $pdo->prepare('DELETE FROM assistant_ai_quota WHERE customer_id = ?')->execute([$cid]);
        error_log('[ai_quota] clear cid=' . $cid);
        $msg = 'سهمیهٔ اختصاصی حذف شد؛ این مشتری از سقف پیش‌فرض استفاده می‌کند.';
    } elseif ($action === 'set') {
        $raw = trim((string)($_POST['daily_tokens'] ?? ''));
        if (!preg_match('/^\d{1,8}$/', $raw) || (int)$raw > AI_QUOTA_MAX) {
            $msg = 'سقف باید عدد صحیح بین ۰ و ' . number_format(AI_QUOTA_MAX) . ' باشد.'; $msgTone = 'err';
        } else {
            $limit = (int)$raw;
            $pdo->prepare('INSERT INTO assistant_ai_quota (customer_id, daily_tokens, updated_at) VALUES (?,?,NOW())
                           ON DUPLICATE KEY UPDATE daily_tokens = VALUES(daily_tokens), updated_at = NOW()')
                ->execute([$cid, $limit]);
            error_log('[ai_quota] set cid=' . $cid . ' limit=' . $limit);
            $msg = 'سقف روزانهٔ این مشتری روی ' . number_format($limit) . ' توکن ثبت شد.';
        }
    } else {
        $msg = 'درخواست نامعتبر.'; $msgTone = 'err';
    }
}

// مشتری انتخاب‌شده برای نمایش وضعیت
$selId = (int)($_GET['id'] ?? ($_POST['customer_id'] ?? 0));
$sel = null;
if ($selId > 0) {
    $st = $pdo->prepare('SELECT c.id, c.phone, q.daily_tokens AS override, u.tokens_in + u.tokens_out AS used
                           FROM customers c
                           LEFT JOIN assistant_ai_quota q ON q.customer_id = c.id
                           LEFT JOIN assistant_ai_usage u ON u.customer_id = c.id AND u.day = ?
                          WHERE c.id = ? LIMIT 1');
    $st->execute([date('Y-m-d'), $selId]); // STAGE11.2: روز مصرف را دستیار با تاریخ تهران (PHP) می‌نویسد؛ CURDATE() ساعت MySQL است
    $sel = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// آخرین سهمیه‌های اختصاصی
$overrides = $pdo->query('SELECT q.customer_id, q.daily_tokens, q.updated_at, c.phone
                            FROM assistant_ai_quota q LEFT JOIN customers c ON c.id = q.customer_id
                           ORDER BY q.updated_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
$fmt = static function ($v): string { return number_format((int)$v); };
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>سهمیهٔ پسته‌کاو</title>
<style>
  body { font-family: Tahoma, sans-serif; margin: 16px; background: #fafafa; color: #222; }
  h1 { font-size: 18px; }
  .box { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
  label { display: block; margin: 6px 0 2px; font-size: 13px; }
  input[type=number], input[type=text] { padding: 6px; width: 220px; max-width: 100%; }
  button { padding: 6px 12px; margin-top: 8px; margin-left: 6px; cursor: pointer; }
  table { border-collapse: collapse; width: 100%; background: #fff; font-size: 13px; }
  th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: right; }
  th { background: #f0f0f0; }
  .note { font-size: 12px; color: #666; line-height: 1.8; }
  .ok { background: #e8f5e9; border: 1px solid #9c9; padding: 8px; border-radius: 6px; }
  .err { background: #fdecea; border: 1px solid #e99; padding: 8px; border-radius: 6px; }
</style>
</head>
<body>
<h1>سهمیهٔ روزانهٔ اختصاصی پسته‌کاو</h1>
<p class="note">اگر برای مشتری سهمیه‌ای ثبت نشده باشد، سقف پیش‌فرض (<b><?= h($fmt($defaultTokens)) ?></b> توکن در روز) اعمال می‌شود. شناسهٔ مشتری را از صفحهٔ گزارش مصرف بردارید.</p>

<?php if ($msg !== ''): ?>
  <p class="<?= $msgTone === 'err' ? 'err' : 'ok' ?>"><?= h($msg) ?></p>
<?php endif; ?>

<div class="box">
  <form method="get">
    <label for="id">شناسهٔ مشتری (برای نمایش وضعیت)</label>
    <input id="id" type="number" name="id" min="1" value="<?= $selId > 0 ? h((string)$selId) : '' ?>">
    <button type="submit">نمایش</button>
  </form>

  <?php if ($selId > 0 && $sel === null): ?>
    <p class="err">مشتری پیدا نشد.</p>
  <?php elseif ($sel !== null): ?>
    <table>
      <tr><th>شناسه</th><td><?= h((string)$sel['id']) ?></td></tr>
      <tr><th>شمارهٔ ماسک‌شده</th><td><?= h(ai_quota_mask_phone($sel['phone'] ?? null)) ?></td></tr>
      <tr><th>سهمیهٔ اختصاصی</th><td><?= $sel['override'] === null ? 'ندارد (پیش‌فرض)' : h($fmt($sel['override'])) ?></td></tr>
      <tr><th>سقف مؤثر امروز</th><td><?= h($fmt($sel['override'] ?? $defaultTokens)) ?> توکن</td></tr>
      <tr><th>مصرف امروز</th><td><?= h($fmt($sel['used'] ?? 0)) ?> توکن</td></tr>
    </table>

    <form method="post" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="customer_id" value="<?= h((string)$sel['id']) ?>">
      <label for="dt">سقف روزانه (توکن ورودی + خروجی؛ ۰ = این مشتری دستیار را استفاده نکند)</label>
      <input id="dt" type="number" name="daily_tokens" min="0" max="<?= AI_QUOTA_MAX ?>" step="1" required>
      <br>
      <button type="submit" name="action" value="set">ثبت سقف</button>
      <button type="submit" name="action" value="clear" onclick="return confirm('سهمیهٔ اختصاصی حذف شود؟')">حذف سهمیهٔ اختصاصی</button>
    </form>
  <?php endif; ?>
</div>

<div class="box">
  <h2 style="font-size:15px">سهمیه‌های اختصاصی ثبت‌شده (۲۰۰ مورد اخیر)</h2>
  <table>
    <thead><tr><th>شناسه</th><th>شمارهٔ ماسک‌شده</th><th>سقف روزانه</th><th>آخرین تغییر</th></tr></thead>
    <tbody>
    <?php if (!$overrides): ?>
      <tr><td colspan="4">هنوز سهمیهٔ اختصاصی‌ای ثبت نشده است.</td></tr>
    <?php endif; ?>
    <?php foreach ($overrides as $o): ?>
      <tr>
        <td><?= h((string)$o['customer_id']) ?></td>
        <td><?= h(ai_quota_mask_phone($o['phone'] ?? null)) ?></td>
        <td><?= h($fmt($o['daily_tokens'])) ?></td>
        <td><?= h((string)$o['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
