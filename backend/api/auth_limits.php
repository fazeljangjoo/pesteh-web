<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * محدودیت ورود و تغییر رمز (مسدودسازی خودکار؛ رفع مسدودی فقط از پنل ادمین).
 * مسدودی هر نوع بعد از PB_BLOCK_HOURS ساعت خودبه‌خود برطرف می‌شود (یا زودتر با رفع مسدودی ادمین).
 * - ورود: حداکثر PB_LOGIN_MAX_PER_DAY ورود موفق در ۲۴ ساعت. ورود بعدی = مسدودی ورود + باطل‌شدن همهٔ نشست‌ها.
 * - تغییر رمز: حداکثر PB_PWCHANGE_MAX_PER_DAY تغییر موفق در ۲۴ ساعت. تغییر بعدی = مسدودی تغییر رمز.
 * تغییر رمز توسط پشتیبانی (ادمین) شمارش و محدود نمی‌شود.
 */
require_once __DIR__ . '/auth_token.php';

if (!defined('PB_LOGIN_MAX_PER_DAY'))    define('PB_LOGIN_MAX_PER_DAY', 8);
if (!defined('PB_PWCHANGE_MAX_PER_DAY')) define('PB_PWCHANGE_MAX_PER_DAY', 5);
const PB_BLOCK_HOURS = 24; // مسدودی بعد از این مدت خودبه‌خود برطرف می‌شود
const PB_MSG_LOGIN_BLOCKED = 'ورود حساب شما موقتاً مسدود شده است. دلیل: بیش از ۸ بار ورود موفق در مدت ۲۴ ساعت. این مسدودی بعد از ۲۴ ساعت خودبه‌خود برطرف می‌شود. برای رفع زودتر مسدودی با پشتیبانی تماس بگیرید.';
const PB_MSG_PWCHANGE_BLOCKED = 'تغییر رمز حساب شما موقتاً مسدود شده است. دلیل: بیش از ۵ بار تغییر رمز در مدت ۲۴ ساعت. این مسدودی بعد از ۲۴ ساعت خودبه‌خود برطرف می‌شود. برای رفع زودتر مسدودی با پشتیبانی تماس بگیرید.';

/** ساخت جدول‌ها (idempotent). */
function pb_auth_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS customer_auth_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        kind VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_cae (customer_id, kind, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS customer_blocks (
        customer_id INT UNSIGNED NOT NULL,
        kind VARCHAR(16) NOT NULL,
        blocked_at DATETIME NOT NULL,
        PRIMARY KEY (customer_id, kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** مسدودی فعال است؟ اگر ۲۴ ساعت گذشته باشد، مسدودی و شمارندهٔ همان نوع پاک می‌شود و false برمی‌گردد. */
function pb_is_blocked(PDO $pdo, int $cid, string $kind): bool {
    $st = $pdo->prepare('SELECT (blocked_at >= NOW() - INTERVAL ' . PB_BLOCK_HOURS . ' HOUR) AS active FROM customer_blocks WHERE customer_id = ? AND kind = ? LIMIT 1');
    $st->execute([$cid, $kind]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ((int)$row['active'] === 1) return true;
    $pdo->prepare('DELETE FROM customer_blocks WHERE customer_id = ? AND kind = ?')->execute([$cid, $kind]);
    $pdo->prepare('DELETE FROM customer_auth_events WHERE customer_id = ? AND kind = ?')->execute([$cid, $kind]);
    return false;
}

function pb_count_24h(PDO $pdo, int $cid, string $kind): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM customer_auth_events WHERE customer_id = ? AND kind = ? AND created_at >= NOW() - INTERVAL 1 DAY');
    $st->execute([$cid, $kind]);
    return (int)$st->fetchColumn();
}

function pb_record(PDO $pdo, int $cid, string $kind): void {
    $pdo->prepare('INSERT INTO customer_auth_events (customer_id, kind, created_at) VALUES (?, ?, NOW())')->execute([$cid, $kind]);
}

function pb_block(PDO $pdo, int $cid, string $kind): void {
    $pdo->prepare('INSERT IGNORE INTO customer_blocks (customer_id, kind, blocked_at) VALUES (?, ?, NOW())')->execute([$cid, $kind]);
}

/**
 * بررسی ورود (بعد از تأیید رمز یا کد). true = ورود مجاز است و ثبت می‌شود.
 * false = مسدود؛ نشست‌های این مشتری باطل شده‌اند.
 */
function pb_login_allowed(PDO $pdo, array $customer): bool {
    pb_auth_ensure($pdo);
    $cid = (int)$customer['id'];
    if (pb_is_blocked($pdo, $cid, 'login')) return false;
    if (pb_count_24h($pdo, $cid, 'login') >= PB_LOGIN_MAX_PER_DAY) {
        pb_block($pdo, $cid, 'login');
        tok_revoke_all($pdo, $cid);
        error_log('auth_limits: login blocked for customer ' . $cid);
        return false;
    }
    pb_record($pdo, $cid, 'login');
    return true;
}

/** بررسی تغییر رمز قبل از انجام آن. true = مجاز. */
function pb_pwchange_allowed(PDO $pdo, int $cid): bool {
    pb_auth_ensure($pdo);
    if (pb_is_blocked($pdo, $cid, 'pwchange')) return false;
    if (pb_count_24h($pdo, $cid, 'pwchange') >= PB_PWCHANGE_MAX_PER_DAY) {
        pb_block($pdo, $cid, 'pwchange');
        error_log('auth_limits: pwchange blocked for customer ' . $cid);
        return false;
    }
    return true;
}

/** بعد از تغییر موفق رمز توسط خود کاربر صدا زده شود. */
function pb_pwchange_done(PDO $pdo, int $cid): void {
    pb_auth_ensure($pdo);
    pb_record($pdo, $cid, 'pwchange');
}

/** رفع مسدودی (فقط ادمین): همهٔ مسدودی‌ها و شمارنده‌ها پاک می‌شوند. */
function pb_unblock_all(PDO $pdo, int $cid): void {
    pb_auth_ensure($pdo);
    $pdo->prepare('DELETE FROM customer_blocks WHERE customer_id = ?')->execute([$cid]);
    $pdo->prepare('DELETE FROM customer_auth_events WHERE customer_id = ?')->execute([$cid]);
}

/** نقشهٔ مسدودی‌ها برای پنل: [customer_id => ['login','pwchange']] */
function pb_blocks_map(PDO $pdo): array {
    pb_auth_ensure($pdo);
    $map = [];
    $q = 'SELECT customer_id, kind FROM customer_blocks WHERE blocked_at >= NOW() - INTERVAL ' . PB_BLOCK_HOURS . ' HOUR';
    foreach ($pdo->query($q)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int)$r['customer_id']][] = (string)$r['kind'];
    }
    return $map;
}
