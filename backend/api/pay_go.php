<?php
declare(strict_types=1);
/**
 * هدایت به درگاه زرین‌پال از دامنهٔ خود سایت (برای اینکه درخواست با Referer دامنهٔ مجاز برود).
 * اپ اندروید لینک را در مرورگر خارجی باز می‌کند؛ مستقیم به زرین‌پال که برود، ممکن است خطای «دامنه مجاز نیست» بگیرد.
 */
require_once __DIR__ . '/pay_zarinpal.php';

header('Referrer-Policy: origin');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Content-Type: text/html; charset=utf-8');

$a = (string)($_GET['a'] ?? '');
if (!preg_match('/^[A-Za-z0-9-]{10,64}$/', $a)) {
    http_response_code(400);
    echo 'درخواست نامعتبر';
    exit;
}
$target = zp_startpay_url($a);
$js = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
    . '<meta name="referrer" content="origin">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>انتقال به درگاه</title></head><body style="font-family:Tahoma,sans-serif;text-align:center;padding:40px">'
    . '<p>در حال انتقال به درگاه پرداخت…</p>'
    . '<p><a href="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">اگر منتقل نشدید، اینجا بزنید</a></p>'
    . '<script>location.replace(' . $js . ');</script></body></html>';
