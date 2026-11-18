<?php
declare(strict_types=1);
/**
 * تأیید کد پیامکی و ورود. اگر شماره قبلاً ثبت نشده باشد، حساب جدید با TRIAL_DAYS روز اشتراک آزمایشی ساخته می‌شود.
 * ورودی JSON: { phone, code, name? }   (name فقط برای کاربر جدید لازم است)
 * خروجی:      { token, phone, name, active, expiry_date, days_left, is_new }
 *              یا برای کاربر جدید بدون نام: { need_name: true } (کد مصرف نمی‌شود)
 */
require dirname(__DIR__, 3) . '/private/config.php';
require __DIR__ . '/license_lib.php';
require __DIR__ . '/auth_token.php'; // STAGE14
require_once __DIR__ . '/migrations.php'; // S00
require_once __DIR__ . '/features.php'; // S00
require __DIR__ . '/auth_limits.php'; // LIMITS
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
$codeRaw  = (string)($input['code'] ?? '');
if ($phoneRaw === '' || $codeRaw === '') fail('شماره و کد پیامکی را وارد کنید');

$phone = normalize_phone($phoneRaw);
if (!is_valid_mobile($phone)) fail('شماره موبایل را درست وارد کنید');

// ارقام فارسی/عربی کد به انگلیسی
$fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹']; $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩']; $en = ['0','1','2','3','4','5','6','7','8','9'];
$code = preg_replace('/[^0-9]/', '', str_replace($ar, $en, str_replace($fa, $en, $codeRaw))) ?? '';
if ($code === '' || strlen($code) > 12) fail('کد پیامکی را درست وارد کنید');

// نام (فقط برای کاربر جدید): فاصله‌ها یکی، کاراکترهای کنترلی حذف، بین ۲ تا ۶۰ حرف
$nameRaw = trim((string)($input['name'] ?? ''));
$name = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $nameRaw)));
if ($name !== '' && (mb_strlen($name) < 2 || mb_strlen($name) > 60)) fail('نام باید بین ۲ تا ۶۰ حرف باشد');

// رمز عبور (اختیاری، فقط هنگام ثبت‌نام کاربر جدید؛ اپ اندروید آن را نمی‌فرستد و مشکلی ندارد)
$newPassword = (string)($input['password'] ?? '');
if ($newPassword !== '') {
    $pwErr = password_policy_error($newPassword);
    if ($pwErr !== null) fail($pwErr);
}

$throttleKey = 'otp_verify:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
throttle_check($throttleKey, 20, 900);

try {
    $pdo = db();
    ensure_plan_column($pdo);
    pb_migrate($pdo); // S00
    ensure_otp_table($pdo);
    $now = date('Y-m-d H:i:s');

    $st = $pdo->prepare('SELECT * FROM otp_codes WHERE phone = ? AND used = 0 AND expires_at >= ? ORDER BY id DESC LIMIT 1');
    $st->execute([$phone, $now]);
    $otp = $st->fetch();
    if (!$otp) {
        throttle_fail($throttleKey);
        fail('کد منقضی شده است. دوباره کد بگیرید.', 401);
    }
    if ((int)$otp['attempts'] >= OTP_MAX_ATTEMPTS) {
        $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?')->execute([$otp['id']]);
        fail('تعداد تلاش اشتباه زیاد بود. دوباره کد بگیرید.', 429);
    }

    $given = otp_hash($phone, $code);
    $ok = hash_equals((string)$otp['code_hash'], $given)
       || ($otp['code_hash2'] !== null && hash_equals((string)$otp['code_hash2'], $given));
    if (!$ok) {
        $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
        throttle_fail($throttleKey);
        fail('کد اشتباه است.', 401);
    }

    // کاربر جدید و بدون نام: کد درست است ولی هنوز مصرف نمی‌شود؛ کلاینت نام را می‌پرسد و دوباره می‌فرستد
    $chk = $pdo->prepare('SELECT 1 FROM customers WHERE phone = ? LIMIT 1');
    $chk->execute([$phone]);
    if (!$chk->fetchColumn() && $name === '') {
        echo json_encode(['need_name' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // S31.T31.19: owner with 2FA needs `otp2` too — checked BEFORE the SMS code is consumed (a missing code costs nothing)
    $chk2 = $pdo->prepare('SELECT * FROM customers WHERE phone = ? LIMIT 1');
    $chk2->execute([$phone]);
    $c2 = $chk2->fetch();
    if ($c2) pb_2fa_gate($pdo, $c2, (string)($input['otp2'] ?? ''));

    // مصرف کد: فقط یک درخواست می‌تواند موفق شود (جلوگیری از استفادهٔ دوباره/هم‌زمان)
    $use = $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = ? AND used = 0');
    $use->execute([$otp['id']]);
    if ($use->rowCount() !== 1) fail('این کد قبلاً استفاده شده است. دوباره کد بگیرید.', 401);
    throttle_reset($throttleKey);

    // --- پیدا کردن یا ساختن مشتری ---
    $sel = $pdo->prepare('SELECT * FROM customers WHERE phone = ? LIMIT 1');
    $sel->execute([$phone]);
    $customer = $sel->fetch();
    $isNew = false;

    if (!$customer) {
        $expiry = (new DateTime('today'))->modify('+' . TRIAL_DAYS . ' days')->format('Y-m-d');
        // اگر کاربر رمز تعیین کرده همان ذخیره می‌شود؛ وگرنه یک مقدار تصادفی غیرقابل‌حدس (ورود فقط با پیامک)
        ensure_password_column($pdo);
        $hasPw = $newPassword !== '' ? 1 : 0;
        $pwHash = password_hash($hasPw ? $newPassword : bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        try {
            $pdo->prepare('INSERT INTO customers (phone, name, password_hash, has_password, active, expiry_date, note) VALUES (?,?,?,?,1,?,?)')
                ->execute([$phone, $name, $pwHash, $hasPw, $expiry, 'ثبت‌نام خودکار (آزمایشی ' . TRIAL_DAYS . ' روزه)']);
            $isNew = true;
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') throw $e; // ثبت هم‌زمان همین شماره: همان ردیف را می‌خوانیم
        }
        $sel->execute([$phone]);
        $customer = $sel->fetch();
    }
    if (!$customer) fail('خطای سرور، دوباره تلاش کنید.', 500);

    // LIMITS: بیش از حد مجاز در یک روز ⇒ مسدودی ورود (رفع فقط از پنل ادمین)
    if (!pb_login_allowed($pdo, $customer)) fail(PB_MSG_LOGIN_BLOCKED, 403);

    // STAGE14: P1 = رفتار قبلی + ردیف هش؛ P2 = نشست جدید (TOKEN_NEW_SESSIONS)
    $token = tok_login_token($pdo, $customer, lic_clean_device($input['device'] ?? ''));

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
        'is_new'      => $isNew,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('otp_verify: ' . $e->getMessage());
    fail('خطای سرور، دوباره تلاش کنید.', 500);
}
