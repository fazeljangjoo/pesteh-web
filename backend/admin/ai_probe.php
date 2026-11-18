<?php
declare(strict_types=1);
/** صفحهٔ تست اتصال پسته‌کاو (فقط ادمین، فقط‌خواندنی؛ به دیتابیس دسترسی ندارد). بعد از تست می‌توانید حذفش کنید. */
define('PB_AI_ADMIN', 1);
require __DIR__ . '/functions.php';
require_login();
require_once __DIR__ . '/../api/assistant_ai_client.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');
@set_time_limit(240);

$cfg = ai_cfg();
$results = null;
$sum = ['in' => 0, 'out' => 0];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $results = [];
    $sys = ['role' => 'system', 'content' => 'تو «پسته‌کاو» هستی، دستیار فارسی‌زبان نرم‌افزار انبار پسته. کوتاه و مؤدبانه پاسخ بده. برای اطلاعات موجودی انبار حتماً از ابزار استفاده کن و عدد از خودت نساز.'];
    $tool = [[
        'type' => 'function',
        'function' => [
            'name' => 'get_stock_total',
            'description' => 'موجودی کل انبار به کیلوگرم را برمی‌گرداند.',
            'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
    ]];

    // تست ۱: فارسی ساده
    $r1 = ai_chat([$sys, ['role' => 'user', 'content' => 'سلام! خودت را در یک جمله معرفی کن.']]);
    $results[] = ['name' => 'تست ۱ — پاسخ فارسی', 'r' => $r1,
        'pass' => $r1['ok'] && trim((string)($r1['message']['content'] ?? '')) !== '',
        'show' => (string)($r1['message']['content'] ?? '')];

    // تست ۲: فراخوانی ابزار
    $r2 = ai_chat([$sys, ['role' => 'user', 'content' => 'موجودی کل انبارم چقدر است؟']], $tool);
    $tc = $r2['ok'] ? ($r2['message']['tool_calls'][0] ?? null) : null;
    $tcName = is_array($tc) ? (string)($tc['function']['name'] ?? '') : '';
    $results[] = ['name' => 'تست ۲ — فراخوانی ابزار (tool-calling)', 'r' => $r2,
        'pass' => $tcName === 'get_stock_total',
        'show' => $tcName !== '' ? ('ابزار: ' . $tcName . ' — آرگومان: ' . (string)($tc['function']['arguments'] ?? '')) : (string)($r2['message']['content'] ?? '(بدون فراخوانی ابزار)')];

    // تست ۳: برگرداندن نتیجهٔ ابزار و جملهٔ نهایی
    if ($tcName === 'get_stock_total') {
        $msgs = [$sys, ['role' => 'user', 'content' => 'موجودی کل انبارم چقدر است؟'], $r2['message'],
                 ['role' => 'tool', 'tool_call_id' => (string)($tc['id'] ?? 'call_1'), 'content' => '{"kg":1234.5}']];
        $r3 = ai_chat($msgs, $tool);
        $txt = (string)($r3['message']['content'] ?? '');
        $has = (bool)preg_match('/1234|۱۲۳۴|١٢٣٤/u', $txt);
        $results[] = ['name' => 'تست ۳ — جملهٔ نهایی از نتیجهٔ ابزار', 'r' => $r3, 'pass' => $r3['ok'] && $has,
            'show' => $txt . ($r3['ok'] && !$has ? '  ← عدد ۱۲۳۴٫۵ در پاسخ دیده نشد' : '')];
    } else {
        $results[] = ['name' => 'تست ۳ — جملهٔ نهایی از نتیجهٔ ابزار', 'r' => ['ok' => false, 'ms' => 0, 'usage' => ['in' => 0, 'out' => 0], 'err_code' => 'skipped', 'status' => 0, 'detail' => ''],
            'pass' => false, 'show' => 'انجام نشد چون تست ۲ ابزار را صدا نزد'];
    }
    foreach ($results as $x) { $sum['in'] += (int)$x['r']['usage']['in']; $sum['out'] += (int)$x['r']['usage']['out']; }
}
$cost = ai_estimate_cost_toman($sum['in'], $sum['out']);
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>تست پسته‌کاو</title>
<style>
body{font-family:Tahoma,sans-serif;background:#f5f6f8;margin:0;padding:16px;color:#1b1b1b}
.box{max-width:760px;margin:0 auto;background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 4px #0002}
h1{font-size:18px;margin:0 0 10px} .muted{color:#666;font-size:13px}
.row{border-top:1px solid #eee;padding:10px 0} .ok{color:#1b7f3b;font-weight:bold} .bad{color:#b3261e;font-weight:bold}
pre{white-space:pre-wrap;background:#f2f4f7;border-radius:8px;padding:8px;margin:6px 0;font-size:13px}
button{padding:10px 18px;border:0;border-radius:8px;background:#2e7d32;color:#fff;font-size:15px;cursor:pointer}
</style></head><body><div class="box">
<h1>تست اتصال پسته‌کاو</h1>
<p class="muted">وضعیت تنظیمات:
<?php if (!empty($cfg['enabled'])): ?>
  <span class="ok">آماده</span> — میزبان: <b dir="ltr"><?= h(ai_cfg_host()) ?></b> — مدل: <b dir="ltr"><?= h((string)$cfg['model']) ?></b>
<?php else: ?>
  <span class="bad">آماده نیست</span> (<?= h((string)($cfg['config_error'] ?? 'disabled')) ?>)
  <br>راهنما: <code>missing_file</code> = فایل <code>private/ai_config.php</code> ساخته نشده · <code>incomplete</code> = کلید/مدل/آدرس خالی است ·
  <code>host_not_allowed</code> = میزبان در <code>allowed_hosts</code> نیست · <code>not_https</code> = آدرس باید https باشد · <code>disabled</code> = <code>enabled</code> را true کنید.
<?php endif; ?></p>
<form method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <button type="submit"<?= empty($cfg['enabled']) ? ' disabled' : '' ?>>اجرای تست</button>
  <span class="muted">هر اجرا سه درخواست کوچک می‌فرستد و هزینهٔ ناچیزی دارد.</span>
</form>
<?php if ($results !== null): ?>
  <?php foreach ($results as $x): $r = $x['r']; ?>
  <div class="row">
    <div><b><?= h($x['name']) ?></b> — <?= $x['pass'] ? '<span class="ok">موفق</span>' : '<span class="bad">ناموفق</span>' ?></div>
    <div class="muted">تأخیر: <?= (int)$r['ms'] ?> ms · توکن ورودی/خروجی: <?= (int)$r['usage']['in'] ?>/<?= (int)$r['usage']['out'] ?>
      <?php if (empty($r['ok'])): ?> · کد خطا: <span dir="ltr"><?= h((string)$r['err_code']) ?></span> · وضعیت HTTP: <?= (int)$r['status'] ?><?php endif; ?></div>
    <?php if (!empty($r['detail'])): ?><div class="muted">پیام ارائه‌دهنده: <span dir="ltr"><?= h((string)$r['detail']) ?></span></div><?php endif; ?>
    <pre><?= h((string)$x['show']) ?></pre>
  </div>
  <?php endforeach; ?>
  <div class="row"><b>جمع:</b> توکن ورودی <?= (int)$sum['in'] ?> · خروجی <?= (int)$sum['out'] ?> · هزینهٔ تخمینی <?= h(number_format($cost, 0)) ?> تومان
    <?php if ((float)($cfg['price_in_usd_per_m'] ?? 0) == 0.0 && (float)($cfg['price_out_usd_per_m'] ?? 0) == 0.0): ?>
      <span class="muted">(قیمت‌ها در کانفیگ صفر است؛ از جدول قیمت کنسول پر کنید)</span><?php endif; ?></div>
  <p class="muted">این خروجی را کپی کنید و در HANDOFF (نتیجهٔ صفحهٔ تست) بگذارید. بعد از تست، فایل <code>ai_probe.php</code> را می‌توانید از هاست حذف کنید.</p>
<?php endif; ?>
</div></body></html>
