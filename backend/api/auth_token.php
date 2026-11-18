<?php
// auth_token.php — مرحلهٔ ۱۳ (v11): هش‌کردن توکن‌های ورود (OPEN-2، بخش آماده‌سازی)
// این فایل بدون exit است و فقط تابع تعریف می‌کند. هنوز توسط هیچ مسیر زنده‌ای صدا زده نمی‌شود
// (سوئیچ‌زدن در مرحلهٔ ۱۴ است). بدون include، رفتار سرور دقیقاً قبلی است.
// مدل: توکن ۲۵۶ بیتی تصادفی؛ در DB فقط sha256 (هگز کوچک، ۶۴ کاراکتر) ذخیره می‌شود.

if (!defined('TOKEN_MAX_SESSIONS')) define('TOKEN_MAX_SESSIONS', 10);
if (!defined('TOKEN_TOUCH_SECONDS')) define('TOKEN_TOUCH_SECONDS', 600);
if (!defined('TOKEN_LEGACY_FALLBACK')) define('TOKEN_LEGACY_FALLBACK', true);

/** توکن تازه (۶۴ کاراکتر هگز، ۲۵۶ بیت). */
function tok_new(): string {
    return bin2hex(random_bytes(32));
}

/** هش ذخیره‌شونده. با SHA2(x,256) در MySQL یکی است (هر دو هگز کوچک روی بایت‌های یکسان ASCII). */
function tok_hash(string $token): string {
    return hash('sha256', $token);
}

/**
 * جستجوی مشتری با شماره+توکن. برای «شماره نیست» و «توکن غلط» همیشه null برمی‌گرداند
 * (فراخواننده باید پیام یکسان بدهد). برمی‌گرداند: ['customer'=>row, 'token_id'=>int|null, 'legacy'=>bool] | null
 * $legacy=true: اگر هش پیدا نشد، ستون قدیمی customers.api_token با مقایسهٔ ثابت‌زمان امتحان می‌شود.
 */
function tok_find(PDO $pdo, string $phone, string $token, bool $legacy = TOKEN_LEGACY_FALLBACK): ?array {
    if ($phone === '' || $token === '' || strlen($token) > 200) return null;
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT c.*, t.id AS tok_id, t.staff_id AS tok_staff, su.active AS su_active FROM customer_tokens t
        JOIN customers c ON c.id = t.customer_id
        LEFT JOIN staff_users su ON su.id = t.staff_id
        WHERE c.phone = ? AND t.token_hash = ? AND (t.expires_at IS NULL OR t.expires_at > ?)
        LIMIT 1');
    $st->execute([$phone, tok_hash($token), $now]);
    $row = $st->fetch();
    if (is_array($row)) {
        $tokId = (int)$row['tok_id'];
        $staffId = $row['tok_staff'] === null ? 0 : (int)$row['tok_staff']; // S02: 0 = مالک
        $suActive = $row['su_active'];
        unset($row['tok_id'], $row['tok_staff'], $row['su_active']);
        // کارکنِ غیرفعال یا حذف‌شده: نشستش همان لحظه بی‌اعتبار است
        if ($staffId > 0 && ($suActive === null || (int)$suActive !== 1)) return null;
        $row['_staff_id'] = $staffId;
        return ['customer' => $row, 'token_id' => $tokId, 'legacy' => false, 'staff_id' => $staffId];
    }
    if ($legacy) {
        $st = $pdo->prepare('SELECT * FROM customers WHERE phone = ? AND api_token IS NOT NULL LIMIT 1');
        $st->execute([$phone]);
        $c = $st->fetch();
        if (is_array($c) && hash_equals((string)$c['api_token'], $token)) {
            return ['customer' => $c, 'token_id' => null, 'legacy' => true];
        }
    }
    return null;
}

/** به‌روزرسانی last_used_at حداکثر یک‌بار در TOKEN_TOUCH_SECONDS. خطا هرگز درخواست را نمی‌شکند. */
function tok_touch(PDO $pdo, int $tokId): void {
    try {
        $now = date('Y-m-d H:i:s');
        $cut = date('Y-m-d H:i:s', time() - TOKEN_TOUCH_SECONDS);
        $pdo->prepare('UPDATE customer_tokens SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)')
            ->execute([$now, $tokId, $cut]);
    } catch (Throwable $e) {
        error_log('[auth_token] touch: ' . $e->getMessage());
    }
}

/** صدور یک نشست جدید برای مشتری (فقط هش ذخیره می‌شود). برمی‌گرداند توکن متن‌ساده را — فقط یک بار. */
function tok_issue(PDO $pdo, int $cid, ?string $device = null): string {
    $t = tok_new();
    $now = date('Y-m-d H:i:s');
    $dev = ($device !== null && $device !== '') ? substr($device, 0, 64) : null;
    $pdo->prepare('INSERT INTO customer_tokens (customer_id, token_hash, device, created_at, last_used_at) VALUES (?,?,?,?,?)')
        ->execute([$cid, tok_hash($t), $dev, $now, $now]);
    tok_cap($pdo, $cid);
    return $t;
}

/**
 * بیش از TOKEN_MAX_SESSIONS نشست؟ قدیمی‌ترین (کوچک‌ترین id) حذف می‌شود.
 * سقف برای هر «صاحب نشست» جداست: مالک (staff_id NULL) یا هر کارکن؛
 * وگرنه ورود کارکنان، نشست مالک یا کارکن دیگر را بی‌دلیل باطل می‌کرد (همه یک customer_id دارند).
 */
function tok_cap(PDO $pdo, int $cid, int $staffId = 0): void {
    if ($staffId > 0) {
        $st = $pdo->prepare('SELECT id FROM customer_tokens WHERE customer_id = ? AND staff_id = ? ORDER BY id DESC');
        $st->execute([$cid, $staffId]);
    } else {
        $st = $pdo->prepare('SELECT id FROM customer_tokens WHERE customer_id = ? AND staff_id IS NULL ORDER BY id DESC');
        $st->execute([$cid]);
    }
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $extra = array_slice($ids, TOKEN_MAX_SESSIONS);
    if (!$extra) return;
    $ph = implode(',', array_fill(0, count($extra), '?'));
    $pdo->prepare("DELETE FROM customer_tokens WHERE customer_id = ? AND id IN ($ph)")
        ->execute(array_merge([$cid], $extra));
}

/**
 * باطل‌سازی همهٔ نشست‌ها: ردیف‌های هش + ستون قدیمی (تا توکن متن‌ساده قدیمی هم بمیرد).
 * برای set_password / reset_password / ادمین (غیرفعال‌سازی) — همیشه قبل از tok_issue صدا زده شود.
 */
function tok_revoke_all(PDO $pdo, int $cid): void {
    $pdo->prepare('DELETE FROM customer_tokens WHERE customer_id = ?')->execute([$cid]);
    $pdo->prepare('UPDATE customers SET api_token = NULL WHERE id = ?')->execute([$cid]);
}

// ---------------------------------------------------------------- مرحلهٔ ۱۴: توابع استفاده‌شده در مسیرهای احراز
// P1 (TOKEN_NEW_SESSIONS=false): رفتار قبلی؛ توکن موجود برمی‌گردد، ردیف هش هم ثبت می‌شود، ستون قدیمی نوشته می‌شود.
// P2 (TOKEN_NEW_SESSIONS=true): هر ورود/تغییر رمز فقط نشست هش‌شده می‌سازد؛ ستون قدیمی نوشته نمی‌شود.
if (!defined('TOKEN_NEW_SESSIONS')) define('TOKEN_NEW_SESSIONS', false);

/** احراز یک درخواست: tok_find + لمس. null = نامعتبر (فراخواننده پیام یکسان می‌دهد). */
function tok_auth(PDO $pdo, string $phone, string $token, bool $allowStaff = false): ?array {
    $f = tok_find($pdo, $phone, $token);
    if ($f === null) return null;
    // S02: توکن کارکن فقط وقتی پذیرفته می‌شود که فراخواننده (app.php) مجوز اکشن‌ها را اعمال کند
    if (!$allowStaff && (int)($f['staff_id'] ?? 0) > 0) return null;
    if ($f['token_id'] !== null) tok_touch($pdo, (int)$f['token_id']);
    return $f['customer'];
}

/** ثبت ردیف هش برای توکنی که از ستون قدیمی برگشته (P1). تکراری = بی‌صدا. */
function tok_ensure_row(PDO $pdo, int $cid, string $token, ?string $device = null): void {
    $h = tok_hash($token);
    $ex = $pdo->prepare('SELECT 1 FROM customer_tokens WHERE token_hash = ?');
    $ex->execute([$h]);
    if ($ex->fetchColumn()) return;
    $now = date('Y-m-d H:i:s');
    try {
        $pdo->prepare('INSERT INTO customer_tokens (customer_id, token_hash, device, created_at, last_used_at) VALUES (?,?,?,?,?)')
            ->execute([$cid, $h, $device !== null && $device !== '' ? substr($device, 0, 64) : null, $now, $now]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') throw $e; // درج هم‌زمان همان توکن
    }
}

/** توکن برگشتی ورود (login.php و otp_verify.php). */
function tok_login_token(PDO $pdo, array $customer, ?string $device = null, bool $newSessions = TOKEN_NEW_SESSIONS): string {
    $cid = (int)$customer['id'];
    if ($newSessions) return tok_issue($pdo, $cid, $device);
    $token = (string)($customer['api_token'] ?? '');
    if ($token === '') {
        $token = tok_new();
        $pdo->prepare('UPDATE customers SET api_token = ? WHERE id = ?')->execute([$token, $cid]);
    }
    tok_ensure_row($pdo, $cid, $token, $device);
    return $token;
}

/** توکن بعد از تغییر/بازنشانی رمز: همهٔ نشست‌ها باطل، نشست جدید برای همین دستگاه. باید داخل تراکنش فراخوانی شود. */
function tok_password_token(PDO $pdo, int $cid, ?string $device = null, bool $newSessions = TOKEN_NEW_SESSIONS): string {
    tok_revoke_all($pdo, $cid);
    $t = tok_issue($pdo, $cid, $device);
    if (!$newSessions) {
        // P1: ستون قدیمی هم نوشته شود تا برگشت به کد قبل از این مرحله امن باشد
        $pdo->prepare('UPDATE customers SET api_token = ? WHERE id = ?')->execute([$t, $cid]);
    }
    return $t;
}
