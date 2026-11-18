<?php
declare(strict_types=1);
/**
 * پسته‌کاو (A8) — گزارش مصرف و هزینهٔ تخمینی (فقط ادمین، فقط‌خواندنی).
 * هیچ نوشتنی در دیتابیس نمی‌کند. شماره‌ها ماسک می‌شوند و محتوای پرسش/پاسخ اصلاً خوانده نمی‌شود.
 */
define('PB_AI_ADMIN', 1);
require __DIR__ . '/functions.php';
require_login();
require_once __DIR__ . '/../api/assistant_ai_client.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');

/** شمارهٔ ماسک‌شده برای نمایش، مثل 0913***4567 */
function ai_usage_mask_phone(?string $normalized): string {
    $p = display_phone((string)$normalized);
    if (strlen($p) < 8) return '—';
    return substr($p, 0, 4) . '***' . substr($p, -4);
}

$cfg = ai_cfg();
$error = '';
$rows = [];
$today = ['calls' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0];
$month = ['calls' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0];

try {
    $pdo = db();
    $todayStr = date('Y-m-d'); // STAGE11.2: همان تاریخ تهرانی که assistant_h_ai.php در assistant_ai_usage.day می‌نویسد
    // ۳۰ روز اخیر، جدیدترین اول؛ فقط ستون‌های لازم
    $st = $pdo->prepare(
        'SELECT u.day, u.customer_id, u.calls, u.tokens_in, u.tokens_out, c.phone
           FROM assistant_ai_usage u
           LEFT JOIN customers c ON c.id = u.customer_id
          WHERE u.day >= ?
          ORDER BY u.day DESC, u.calls DESC
          LIMIT 500'
    );
    $st->execute([date('Y-m-d', strtotime($todayStr . ' -29 days'))]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $in = (int)$r['tokens_in'];
        $out = (int)$r['tokens_out'];
        $calls = (int)$r['calls'];
        $cost = ai_estimate_cost_toman($in, $out);
        $rows[] = ['day' => (string)$r['day'], 'cid' => (int)$r['customer_id'],
                   'phone' => ai_usage_mask_phone($r['phone'] ?? null),
                   'calls' => $calls, 'in' => $in, 'out' => $out, 'cost' => $cost];
        $month['calls'] += $calls; $month['in'] += $in; $month['out'] += $out; $month['cost'] += $cost;
        if ((string)$r['day'] === $todayStr) {
            $today['calls'] += $calls; $today['in'] += $in; $today['out'] += $out; $today['cost'] += $cost;
        }
    }
} catch (Throwable $e) {
    error_log('[ai_usage] read failed: ' . $e->getMessage()); // بدون محتوای کاربر
    $error = 'جدول مصرف هنوز ساخته نشده یا خواندن آن ممکن نبود. (جدول با اولین استفاده از پسته‌کاو ساخته می‌شود.)';
}

$avg = $month['calls'] > 0 ? $month['cost'] / $month['calls'] : 0.0;
$priceSet = ((float)($cfg['price_in_usd_per_m'] ?? 0) > 0) || ((float)($cfg['price_out_usd_per_m'] ?? 0) > 0);
$fmt = static function (float $v): string { return number_format((int)round($v)); };
$status = !empty($cfg['enabled']) ? 'روشن' : 'خاموش';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>گزارش مصرف پسته‌کاو</title>
<style>
  body { font-family: Tahoma, sans-serif; margin: 16px; background: #fafafa; color: #222; }
  h1 { font-size: 18px; }
  .cards { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
  .card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 10px 12px; min-width: 150px; }
  .card b { display: block; font-size: 16px; margin-top: 4px; }
  table { border-collapse: collapse; width: 100%; background: #fff; font-size: 13px; }
  th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: right; }
  th { background: #f0f0f0; }
  .note { font-size: 12px; color: #666; margin: 10px 0; line-height: 1.8; }
  .warn { background: #fff4e0; border: 1px solid #f0c060; padding: 8px; border-radius: 6px; }
  .err { background: #fdecea; border: 1px solid #e99; padding: 8px; border-radius: 6px; }
</style>
</head>
<body>
<h1>گزارش مصرف پسته‌کاو (۳۰ روز اخیر)</h1>
<p class="note">وضعیت کل: <b><?= h($status) ?></b> · این صفحه فقط‌خوانده است و هیچ تغییری در داده‌ها نمی‌دهد. شماره‌ها ماسک شده‌اند و متن پرسش/پاسخ نمایش داده نمی‌شود.</p>

<?php if ($error !== ''): ?>
  <p class="err"><?= h($error) ?></p>
<?php endif; ?>
<?php if (!$priceSet): ?>
  <p class="warn">قیمت‌های کانفیگ (price_in_usd_per_m / price_out_usd_per_m) صفر است؛ هزینهٔ تخمینی صفر نشان داده می‌شود.</p>
<?php endif; ?>

<div class="cards">
  <div class="card">تماس امروز<b><?= $fmt((float)$today['calls']) ?></b></div>
  <div class="card">هزینهٔ امروز (تخمینی)<b><?= $fmt($today['cost']) ?> تومان</b></div>
  <div class="card">تماس ۳۰ روز<b><?= $fmt((float)$month['calls']) ?></b></div>
  <div class="card">توکن ورودی ۳۰ روز<b><?= $fmt((float)$month['in']) ?></b></div>
  <div class="card">توکن خروجی ۳۰ روز<b><?= $fmt((float)$month['out']) ?></b></div>
  <div class="card">هزینهٔ ۳۰ روز (تخمینی)<b><?= $fmt($month['cost']) ?> تومان</b></div>
  <div class="card">میانگین هزینه هر پرسش<b><?= $fmt($avg) ?> تومان</b></div>
</div>

<table>
  <thead>
    <tr><th>روز</th><th>مشتری (id)</th><th>شمارهٔ ماسک‌شده</th><th>تعداد تماس</th><th>توکن ورودی</th><th>توکن خروجی</th><th>هزینهٔ تخمینی (تومان)</th></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7">داده‌ای برای نمایش نیست.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['day']) ?></td>
      <td><?= h((string)$r['cid']) ?></td>
      <td><?= h($r['phone']) ?></td>
      <td><?= $fmt((float)$r['calls']) ?></td>
      <td><?= $fmt((float)$r['in']) ?></td>
      <td><?= $fmt((float)$r['out']) ?></td>
      <td><?= $fmt($r['cost']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="note">هزینه با نرخ دلار کانفیگ (usd_toman) و قیمت هر یک میلیون توکن محاسبه شده و فقط برآورد است. «میانگین هزینه هر پرسش» = هزینهٔ ۳۰ روز ÷ تعداد تماس‌ها.</p>
</body>
</html>
