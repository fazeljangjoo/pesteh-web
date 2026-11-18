<?php
declare(strict_types=1);
/**
 * ارتباط با درگاه زرین‌پال (REST نسخهٔ ۴) — مرحلهٔ ۴.
 * فقط از سمت سرور صدا زده می‌شود. مرچنت‌کد فقط از private/pay_config.php خوانده می‌شود و هیچ‌جا لاگ نمی‌شود.
 * مبلغ سفارش‌ها «تومان» است؛ تبدیل به واحد حساب زرین‌پال فقط در zp_amount (هنگام درخواست) انجام می‌شود و verify همان مقدار ذخیره‌شده را می‌فرستد.
 */

function zp_config_loaded(): bool {
    static $loaded = null;
    if ($loaded === null) {
        $f = dirname(__DIR__, 3) . '/private/pay_config.php';
        $loaded = is_file($f);
        if ($loaded) require_once $f;
    }
    return $loaded;
}

function zp_configured(): bool {
    return zp_config_loaded() && defined('ZP_MERCHANT_ID') && preg_match('/^[A-Za-z0-9-]{36}$/', (string)ZP_MERCHANT_ID) === 1;
}

function zp_unit(): string {
    return (zp_config_loaded() && defined('ZP_UNIT') && strtoupper((string)ZP_UNIT) === 'IRT') ? 'IRT' : 'IRR';
}

/** تومان ← واحد حساب زرین‌پال */
function zp_amount(int $toman): int { // param = order amount in the price unit (PB_PRICE_UNIT: toman default, rial after S29)
    $rialOrders = function_exists('pay_price_unit') && pay_price_unit() === 'rial';
    if ($rialOrders) return zp_unit() === 'IRT' ? intdiv($toman, 10) : $toman;
    return zp_unit() === 'IRT' ? $toman : $toman * 10;
}

function zp_host(): string {
    // ZP_BASE_URL فقط برای آزمایش خودکار روی سرور ساختگی است؛ در حالت عادی تعریف نمی‌شود.
    if (zp_config_loaded() && defined('ZP_BASE_URL') && ZP_BASE_URL) return rtrim((string)ZP_BASE_URL, '/');
    return (zp_config_loaded() && defined('ZP_SANDBOX') && ZP_SANDBOX) ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
}

function zp_startpay_url(string $authority): string {
    return zp_host() . '/pg/StartPay/' . rawurlencode($authority);
}

/** آدرس واسط روی دامنهٔ خودمان (pay_go.php) که کاربر را از همین دامنه به درگاه می‌برد */
function zp_go_url(string $authority): string {
    $base = (zp_config_loaded() && defined('PAY_CALLBACK_URL')) ? dirname((string)PAY_CALLBACK_URL) : '';
    return $base . '/pay_go.php?a=' . rawurlencode($authority);
}

/**
 * POST به یک مسیر زرین‌پال.
 * خروجی: ['net'=>bool, 'http'=>int, 'code'=>int|null, 'data'=>array, 'errors'=>array]
 *  net=false یعنی ارتباط برقرار نشد یا پاسخ قابل‌فهم نبود (نتیجه نامعلوم؛ نباید سفارش را «ناموفق» علامت زد).
 */
function zp_post(string $path, array $body): array {
    $fail = ['net' => false, 'http' => 0, 'code' => null, 'data' => [], 'errors' => []];
    if (!function_exists('curl_init')) { error_log('zp: curl extension missing'); return $fail; }
    $ch = curl_init(zp_host() . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($res === false) { error_log('zp: curl error ' . curl_errno($ch) . ' on ' . $path); curl_close($ch); return $fail; }
    curl_close($ch);
    $j = json_decode((string)$res, true);
    if (!is_array($j)) { error_log('zp: bad json http=' . $http . ' on ' . $path); $fail['http'] = $http; return $fail; }
    $data = (isset($j['data']) && is_array($j['data'])) ? $j['data'] : [];
    $errors = (isset($j['errors']) && is_array($j['errors'])) ? $j['errors'] : [];
    $code = null;
    if (isset($data['code'])) $code = (int)$data['code'];
    elseif (isset($errors['code'])) $code = (int)$errors['code'];
    if ($code === null && $http >= 500) { $fail['http'] = $http; return $fail; }
    return ['net' => true, 'http' => $http, 'code' => $code, 'data' => $data, 'errors' => $errors];
}

/**
 * درخواست پرداخت. خروجی: ['ok'=>true,'authority'=>..,'url'=>..] یا ['ok'=>false,'net'=>bool,'code'=>int|null]
 */
function zp_request(int $amountToman, string $description, string $mobile, string $orderPublicId): array {
    if (!zp_configured()) return ['ok' => false, 'net' => true, 'code' => null, 'not_configured' => true];
    $body = [
        'merchant_id' => (string)ZP_MERCHANT_ID,
        'amount' => zp_amount($amountToman),
        'callback_url' => (string)PAY_CALLBACK_URL,
        'description' => mb_substr($description, 0, 200),
        'metadata' => ['order_id' => $orderPublicId],
    ];
    if (preg_match('/^09\d{9}$/', $mobile)) $body['metadata']['mobile'] = $mobile;
    $r = zp_post('/pg/v4/payment/request.json', $body);
    $auth = (string)($r['data']['authority'] ?? '');
    if ($r['net'] && $r['code'] === 100 && $auth !== '') return ['ok' => true, 'authority' => $auth, 'url' => zp_go_url($auth), 'amount_gateway' => $body['amount']];
    error_log('zp_request failed: net=' . (int)$r['net'] . ' code=' . json_encode($r['code']));
    return ['ok' => false, 'net' => $r['net'], 'code' => $r['code']];
}

/**
 * تأیید پرداخت (verify) — تنها مرجع معتبر موفق‌بودن پرداخت.
 * کد ۱۰۰ = تازه تأیید شد، ۱۰۱ = قبلاً تأیید شده بود. هر دو «پرداخت موفق» حساب می‌شوند.
 * خروجی: ['state'=>'paid','ref_id'=>..,'card_pan'=>..] | ['state'=>'failed','code'=>..] | ['state'=>'unknown'] (خطای شبکه/نامعلوم)
 */
function zp_verify(string $authority, int $amountGateway): array {
    // $amountGateway همان مقداری است که هنگام درخواست در pay_transactions.amount_gateway ذخیره شده (نه محاسبهٔ دوباره)
    if (!zp_configured()) return ['state' => 'unknown'];
    $r = zp_post('/pg/v4/payment/verify.json', [
        'merchant_id' => (string)ZP_MERCHANT_ID,
        'amount' => $amountGateway,
        'authority' => $authority,
    ]);
    if (!$r['net']) return ['state' => 'unknown'];
    if (in_array($r['code'], [100, 101], true)) {
        $ref = (string)($r['data']['ref_id'] ?? '');
        if ($ref === '') { error_log('zp_verify: success without ref_id'); return ['state' => 'unknown']; }
        return ['state' => 'paid', 'ref_id' => mb_substr($ref, 0, 40), 'card_pan' => mb_substr((string)($r['data']['card_pan'] ?? ''), 0, 24), 'code' => $r['code']];
    }
    error_log('zp_verify failed: code=' . json_encode($r['code']) . ' http=' . $r['http']);
    return ['state' => 'failed', 'code' => $r['code']];
}
