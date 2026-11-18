<?php
declare(strict_types=1);
/**
 * Endpoint دستیار پسته بازار (فقط پلن ارزی). POST/JSON:
 *   { phone, token, q: "menu|stock_total|...", step: "start|answer|send|poll", params: {} }
 * پاسخ: { v, title, theme, blocks[], data_note } یا { v, error }
 * فقط‌خواندنی روی داده‌های کسب‌وکار. app.php را require نمی‌کند.
 */
define('PB_ASSISTANT', 1);
ini_set('display_errors', '0'); // خطا/هشدار PHP هرگز داخل پاسخ نیاید (جزئیات فقط در لاگ سرور)
require dirname(__DIR__, 3) . '/private/config.php';
date_default_timezone_set('Asia/Tehran');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

// CORS: همان منطق app.php (پیش‌فرض فقط هم‌مبدأ؛ یا ALLOWED_ORIGINS در config.php)
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $corsOk = false;
    if (defined('ALLOWED_ORIGINS')) {
        $list = is_array(ALLOWED_ORIGINS) ? ALLOWED_ORIGINS : array_map('trim', explode(',', (string)ALLOWED_ORIGINS));
        $corsOk = in_array($origin, $list, true);
    } else {
        $oh = (string)parse_url($origin, PHP_URL_HOST);
        $op = parse_url($origin, PHP_URL_PORT);
        $corsOk = $oh !== '' && strcasecmp($oh . ($op ? ':' . $op : ''), (string)($_SERVER['HTTP_HOST'] ?? '')) === 0;
    }
    if ($corsOk) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

require __DIR__ . '/assistant_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') as_bad('درخواست نامعتبر است', 405);

try {
    $req = as_read_request();
    $pdo = db();
    $customer = as_authenticate($pdo, $req);   // ۴۰۱/۴۰۳ و خروج در صورت هر ایراد (از جمله پلن غیرارزی)
    $cid = (int)$customer['id'];               // تنها منبع customer_id
    as_ensure_tables($pdo);
    as_ctx(['pdo' => $pdo, 'cid' => $cid]);
    require_once __DIR__ . '/money_unit.php'; // S35.T35.12: واحد ذخیره/نمایش برای همهٔ پاسخ‌های دستیار
    require_once __DIR__ . '/pb_fmt.php';
    as_ctx(['money' => ['stored' => pb_money_unit($pdo, $cid), 'display' => pb_fmt_display_unit($pdo, $cid)]]);
    as_rate_limit($pdo, $cid);

    // اکشن‌های هر مرحله در فایل‌های assistant_h_*.php ثبت می‌شوند (as_register)
    foreach (glob(__DIR__ . '/assistant_h_*.php') ?: [] as $f) { require_once $f; }

    $q = $req['q'] ?? 'menu';
    if (!is_string($q) || !preg_match('/^[a-z_]{1,30}$/', $q)) as_bad('درخواست نامعتبر است', 400);
    $step = $req['step'] ?? 'start';
    if (!is_string($step) || !in_array($step, ['start', 'answer', 'send', 'poll'], true)) as_bad('درخواست نامعتبر است', 400);
    $params = (isset($req['params']) && is_array($req['params'])) ? $req['params'] : [];
    as_ctx(['q' => $q]);

    as_dispatch(['pdo' => $pdo, 'cid' => $cid, 'customer' => $customer, 'q' => $q, 'step' => $step, 'params' => $params], $q);
} catch (Throwable $e) {
    error_log('[assistant] ' . get_class($e) . ': ' . $e->getMessage()); // جزئیات فقط در لاگ سرور
    as_bad('خطای داخلی؛ لطفاً دوباره تلاش کنید', 500);
}
