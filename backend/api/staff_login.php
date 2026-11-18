<?php
declare(strict_types=1);
/**
 * S02.T02.1: ورود کارکن. ورودی JSON: { phone (شمارهٔ مالک), username, password, device? }
 * خروجی: { token, phone, name, active, expiry_date, days_left, plan, edition, staff:{id,name,username,role,perms[]}, lic }
 * توکن کارکن با phone مالک و همان فیلد token در app.php کار می‌کند (customer_tokens.staff_id).
 */
require dirname(__DIR__, 3) . '/private/config.php';
require __DIR__ . '/license_lib.php';
require __DIR__ . '/auth_token.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/features.php';
require_once __DIR__ . '/auth_limits.php';
require_once __DIR__ . '/permissions.php';
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
$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');
if ($phoneRaw === '' || $username === '' || $password === '') fail('شماره موبایل مالک، نام کاربری و رمز را وارد کنید');
$phone = normalize_phone($phoneRaw);
if (!is_valid_mobile($phone)) fail('شماره موبایل را درست وارد کنید (مثال: 09123456789)');
if (strlen($password) > 200) fail('نام کاربری یا رمز اشتباه است.', 401);

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$keyIp = 'st_ip:' . $ip;
$keyIpUser = 'st_ipuser:' . $ip . '|' . $phone . '|' . $username;
$keyUser = 'st_user:' . $phone . '|' . $username;
throttle_check($keyIp, 20, 900);
throttle_check($keyIpUser, 8, 900);
throttle_check($keyUser, 30, 900);

try {
    $pdo = db();
    ensure_plan_column($pdo);
    pb_migrate($pdo);

    $sel = $pdo->prepare('SELECT * FROM customers WHERE phone = ? LIMIT 1');
    $sel->execute([$phone]);
    $customer = $sel->fetch();

    $st = $customer ? $pdo->prepare('SELECT * FROM staff_users WHERE customer_id = ? AND username = ? LIMIT 1') : null;
    if ($st) $st->execute([(int)$customer['id'], $username]);
    $staff = $st ? $st->fetch() : false;

    // همیشه یک password_verify اجرا می‌شود تا زمان پاسخ چیزی لو ندهد
    $canUse = is_array($customer) && is_array($staff) && (int)$staff['active'] === 1;
    $hash = $canUse ? (string)$staff['pass_hash'] : ADMIN_PASS_HASH;
    $match = password_verify($password, $hash);
    if (!($canUse && $match)) {
        throttle_fail($keyIp);
        throttle_fail($keyIpUser);
        throttle_fail($keyUser);
        fail('نام کاربری یا رمز اشتباه است.', 401);
    }
    throttle_reset($keyIpUser);

    if (!pb_login_allowed($pdo, $customer)) fail(PB_MSG_LOGIN_BLOCKED, 403);

    $cid = (int)$customer['id'];
    $device = lic_clean_device($input['device'] ?? '');
    $token = pb_staff_issue($pdo, $cid, (int)$staff['id'], $device);
    $pdo->prepare('UPDATE staff_users SET last_login = NOW() WHERE id = ? AND customer_id = ?')->execute([(int)$staff['id'], $cid]);

    // پروفایل کامل کارکن (نقش و مجوزها) برای کلاینت
    $ctx = $customer;
    $ctx['_staff_id'] = (int)$staff['id'];
    pb_staff_attach($pdo, $ctx);

    $daysLeft = (int)floor((strtotime((string)$customer['expiry_date']) - strtotime('today')) / 86400);
    echo json_encode([
        'lic'         => license_issue($phone, $customer, $device),
        'server_time' => time(),
        'token'       => $token,
        'phone'       => $customer['phone'],
        'name'        => $customer['name'],
        'active'      => (bool)$customer['active'],
        'expiry_date' => $customer['expiry_date'],
        'days_left'   => $daysLeft,
        'plan'        => customer_plan($customer),
        'edition'     => edition_of($customer),
        'staff'       => pb_staff_public($ctx),
        'is_new'      => false,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('staff_login: ' . $e->getMessage());
    fail('خطای سرور، دوباره تلاش کنید.', 500);
}
