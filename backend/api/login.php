<?php
declare(strict_types=1);
/**
 * ورود با شماره موبایل + رمز عبور (راه جایگزین وقتی پیامک ارسال نمی‌شود).
 * فقط برای حساب‌هایی که رمز دارند (ادمین یا خود کاربر رمز گذاشته). ثبت‌نام کاربر جدید فقط با پیامک است.
 * ورودی JSON: { phone, password }
 * خروجی:      { token, phone, name, active, expiry_date, days_left, is_new: false }
 */
require dirname(__DIR__, 3) . '/private/config.php';
require __DIR__ . '/license_lib.php';
require __DIR__ . '/auth_token.php'; // STAGE14
require_once __DIR__ . '/migrations.php'; // S00
require_once __DIR__ . '/features.php'; // S00
require_once __DIR__ . '/notify_sms.php'; // SMS
require_once __DIR__ . '/auth_limits.php'; // LIMITS
require_once __DIR__ . '/two_factor.php'; // S31.T31.19
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('درخواست نامعتبر است', 405);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$phoneRaw = (string)($input['phone'] ?? '');
$password = (string)($input['password'] ?? '');
if ($phoneRaw === '' || $password === '') fail('شماره موبایل و رمز عبور را وارد کنید');
$phone = normalize_phone($phoneRaw);
if (!is_valid_mobile($phone)) fail('شماره موبایل را درست وارد کنید (مثال: 09123456789)');
if (strlen($password) > 200) fail('شماره یا رمز عبور اشتباه است.', 401);

// ضد حدس‌زدن رمز: هر IP، هر IP+شماره، و (سقف بالاتر) هر شماره از همهٔ IPها
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$keyIp      = 'pw_ip:' . $ip;
$keyIpPhone = 'pw_ipphone:' . $ip . '|' . $phone;
$keyPhone   = 'pw_phone:' . $phone;
throttle_check($keyIp, 20, 900);
throttle_check($keyIpPhone, 8, 900);
throttle_check($keyPhone, 30, 900);

try {
    $pdo = db();
    ensure_password_column($pdo);
    ensure_plan_column($pdo);
    pb_migrate($pdo); // S00

    $sel = $pdo->prepare('SELECT * FROM customers WHERE phone = ? LIMIT 1');
    $sel->execute([$phone]);
    $customer = $sel->fetch();

    // همیشه یک password_verify اجرا می‌شود (حتی اگر شماره ثبت نباشد) تا زمان پاسخ چیزی لو ندهد
    $canUse = $customer && (int)($customer['has_password'] ?? 0) === 1;
    $hash = $canUse ? (string)$customer['password_hash'] : ADMIN_PASS_HASH;
    $match = password_verify($password, $hash);
    if (!($canUse && $match)) {
        throttle_fail($keyIp);
        throttle_fail($keyIpPhone);
        throttle_fail($keyPhone);
        fail('شماره یا رمز عبور اشتباه است.', 401);
    }
    throttle_reset($keyIpPhone);

    // LIMITS: بیش از حد مجاز در یک روز ⇒ مسدودی ورود (رفع فقط از پنل ادمین)
    if (!pb_login_allowed($pdo, $customer)) fail(PB_MSG_LOGIN_BLOCKED, 403);

    pb_2fa_gate($pdo, $customer, (string)($input['otp2'] ?? '')); // S31.T31.19: second factor (only when the owner enabled it)

    // STAGE14: P1 = رفتار قبلی + ردیف هش؛ P2 = نشست جدید (TOKEN_NEW_SESSIONS)
    $pbNotifyLogin = pb_login_should_notify($pdo, $customer, lic_clean_device($input['device'] ?? '')); // SMS
    $token = tok_login_token($pdo, $customer, lic_clean_device($input['device'] ?? ''));
    if ($pbNotifyLogin) pb_notify_login($pdo, $customer); // SMS: بعد از صدور نشست؛ خطا ورود را متوقف نمی‌کند

    $daysLeft = (int)floor((strtotime((string)$customer['expiry_date']) - strtotime('today')) / 86400);
    echo json_encode([
        'lic'         => license_issue($phone, $customer, lic_clean_device($input['device'] ?? '')),
        'server_time' => time(),
        'token'       => $token,
        'phone'       => $customer['phone'],
        'name'        => $customer['name'],
        'active'      => (bool)$customer['active'],
        'expiry_date' => $customer['expiry_date'],
        'days_left'   => $daysLeft,
        'plan'        => customer_plan($customer),
        'edition'     => edition_of($customer),
        'is_new'      => false,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('login: ' . $e->getMessage());
    fail('خطای سرور، دوباره تلاش کنید.', 500);
}
