<?php
declare(strict_types=1);
/**
 * برگشت از درگاه زرین‌پال (callback). آدرس این فایل باید در PAY_CALLBACK_URL (private/pay_config.php) باشد.
 * زرین‌پال با GET برمی‌گرداند: ?Authority=...&Status=OK|NOK
 * هرگز به Status اعتماد نمی‌شود: فقط verify سرور به سرور معتبر است. اجرای تکراری/رفرش بی‌خطر است (idempotent).
 */
require dirname(__DIR__, 3) . '/private/config.php';
require __DIR__ . '/pay_engine.php';
require __DIR__ . '/pay_zarinpal.php';
require __DIR__ . '/pay_core.php';
date_default_timezone_set('Asia/Tehran');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');

function cb_page(string $kind, string $title, string $body, ?string $orderId = null): void {
    $colors = ['ok' => '#1b7f4b', 'warn' => '#b7791f', 'bad' => '#c0392b'];
    $icons = ['ok' => '✅', 'warn' => '⏳', 'bad' => '❌'];
    $c = $colors[$kind] ?? '#555';
    $isAndroid = stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'Android') !== false;
    $ret = (zp_config_loaded() && defined('PAY_RETURN_URL')) ? (string)PAY_RETURN_URL : '';
    $support = (zp_config_loaded() && defined('PAY_SUPPORT_TEXT')) ? trim((string)PAY_SUPPORT_TEXT) : '';
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>نتیجهٔ پرداخت — پسته بازار</title><style>'
        . 'body{margin:0;background:#f4f1ea;font-family:Tahoma,Vazirmatn,sans-serif;color:#222;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}'
        . '.c{background:#fff;max-width:420px;width:100%;border-radius:16px;padding:28px 22px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center}'
        . '.i{font-size:48px}h1{font-size:19px;margin:10px 0;color:' . $c . '}p{line-height:1.9;font-size:14px;margin:8px 0}'
        . '.o{background:#f4f1ea;border-radius:8px;padding:6px 10px;font-size:13px;display:inline-block;direction:ltr;margin-top:6px}'
        . 'a.b{display:inline-block;margin-top:14px;background:' . $c . ';color:#fff;text-decoration:none;padding:10px 22px;border-radius:10px;font-size:14px}'
        . '</style></head><body><div class="c"><div class="i">' . ($icons[$kind] ?? '') . '</div><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>';
    if ($orderId) echo '<div>شمارهٔ سفارش:</div><div class="o">' . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') . '</div>';
    if ($support !== '') echo '<p>' . htmlspecialchars($support, ENT_QUOTES, 'UTF-8') . '</p>';
    if ($isAndroid) {
        echo '<p style="color:#777;font-size:13px">اگر برنامه خودکار باز نشد، روی دکمهٔ «بازگشت به برنامه» بزنید. وضعیت پرداخت در برنامه به‌روز می‌شود.</p>';
    } else {
        echo '<p style="color:#777;font-size:13px">اگر از برنامهٔ ویندوز آمده‌اید، این صفحه را ببندید و برنامه را دوباره باز کنید. اگر از وب‌اپ آمده‌اید، دکمهٔ زیر را بزنید.</p>';
    }
    // دکمهٔ برنامه و تلاش خودکار فقط برای اندروید؛ وب و ویندوز فقط دکمهٔ وب‌اپ می‌بینند
    $deep = 'pestehbazar://pay?result=' . $kind . ($orderId ? '&order=' . rawurlencode($orderId) : '');
    if ($isAndroid) {
        echo '<a class="b" href="' . htmlspecialchars($deep, ENT_QUOTES, 'UTF-8') . '">بازگشت به برنامه</a>';
        echo '<script>setTimeout(function(){location.href=' . json_encode($deep, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';},800);</script>';
    }
    if (!$isAndroid && $ret !== '') echo '<a class="b" href="' . htmlspecialchars($ret, ENT_QUOTES, 'UTF-8') . '">بازگشت به وب‌اپ</a>';
    echo '</div></body></html>';
    exit;
}

$authority = (string)($_GET['Authority'] ?? '');
$status = (string)($_GET['Status'] ?? '');
if (!preg_match('/^[A-Za-z0-9-]{10,64}$/', $authority)) cb_page('bad', 'درخواست نامعتبر', 'اطلاعات برگشت از درگاه معتبر نیست.');

try {
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM pay_transactions WHERE authority = ? AND gateway = 'zarinpal' LIMIT 1");
    $st->execute([$authority]);
    $tx = $st->fetch();
    if (!$tx) cb_page('bad', 'تراکنش پیدا نشد', 'این پرداخت در سیستم ثبت نشده است. اگر مبلغی از حساب شما کسر شده، با پشتیبانی تماس بگیرید.');

    $o = $pdo->prepare('SELECT public_id FROM pay_orders WHERE id = ?');
    $o->execute([$tx['order_id']]);
    $pid = (string)($o->fetchColumn() ?: '');

    $r = payc_gateway_finalize($pdo, $tx, $status === 'OK');
    switch ($r['result']) {
        case 'paid':
            cb_page('ok', 'پرداخت موفق بود', 'اشتراک شما فعال شد. ممنون از اعتماد شما.', $pid);
        case 'review':
            cb_page('warn', 'پرداخت ثبت شد', 'پرداخت شما دریافت شد، اما فعال‌سازی خودکار انجام نشد. سفارش شما در حال بررسی است و نتیجه با پیام داخل برنامه اعلام می‌شود.', $pid);
        case 'pending':
            cb_page('warn', 'در حال بررسی', 'تأیید پرداخت با درگاه هنوز انجام نشده است. چند دقیقه بعد در برنامه، گزینهٔ «بررسی پرداخت» را بزنید. اگر مبلغی کسر شده باشد، پلن شما فعال می‌شود.', $pid);
        default:
            cb_page('bad', 'پرداخت انجام نشد', 'پرداخت ناموفق بود یا لغو شد. اگر مبلغی از حساب شما کسر شده، معمولاً تا ۷۲ ساعت به حساب شما برمی‌گردد. می‌توانید دوباره تلاش کنید.', $pid);
    }
} catch (Throwable $e) {
    error_log('pay_callback: ' . $e->getMessage());
    cb_page('warn', 'خطای موقت', 'خطایی رخ داد. اگر مبلغی کسر شده، چند دقیقه بعد در برنامه «بررسی پرداخت» را بزنید یا با پشتیبانی تماس بگیرید.');
}
