<?php
declare(strict_types=1);
/**
 * S4 — endpoint سبک poll (بدون بوت app.php: بدون migration/edition/نقش‌ها).
 * ورودی JSON: { phone, token, since_ver? }  → خروجی: { ver, poll_interval, [changes, reset] }
 * هزینه: یک احراز توکن (JOIN) + یک SELECT ایندکس‌دار (+ یک SELECT تنظیم). توکن کارکن هم پذیرفته می‌شود؛
 * فقط اعداد و نام اکشن برمی‌گردد (هیچ دادهٔ کسب‌وکاری). اگر جدول‌ها هنوز ساخته نشده‌اند ver=0 (تا اولین درخواست app.php و مهاجرت v63).
 */
require dirname(__DIR__, 3) . '/private/config.php';
require __DIR__ . '/auth_token.php';
require_once __DIR__ . '/sync_version.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function sp_fail(string $m, int $c): void { http_response_code($c); echo json_encode(['error' => $m], JSON_UNESCAPED_UNICODE); exit; }
function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; } // برای pb_sync_action

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sp_fail('درخواست نامعتبر است', 405);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true);
if (!is_array($in)) sp_fail('درخواست نامعتبر است', 400);
$phoneRaw = (string)($in['phone'] ?? ''); $token = (string)($in['token'] ?? '');
if ($phoneRaw === '' || $token === '') sp_fail('نیاز به ورود مجدد است', 401);
$pdo = db();
$customer = tok_auth($pdo, normalize_phone($phoneRaw), $token, true);
if (!$customer) sp_fail('نشست شما نامعتبر است، دوباره وارد شوید', 401);
if (!$customer['active'] || strtotime((string)$customer['expiry_date']) < strtotime('today')) sp_fail('اشتراک شما فعال نیست', 403);
pb_sync_action('sync.version', $in, $pdo, $customer);
