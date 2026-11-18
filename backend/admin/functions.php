<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/private/config.php';

$_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $_isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function is_logged_in(): bool {
    return !empty($_SESSION['admin']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void {
    $t = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        die('درخواست نامعتبر (لطفاً صفحه را رفرش کنید و دوباره امتحان کنید).');
    }
}

/** یک رمز عبور تصادفی و خواناتر برای مشتری جدید می‌سازد */
function generate_password(int $len = 8): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'; // بدون حروف/ارقام مشابه
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/** S00.T00.5: audit trail of admin edits to a customer (edition changes etc.). Never throws. */
function admin_audit(PDO $pdo, int $customerId, string $what, string $old, string $new): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            what VARCHAR(40) NOT NULL,
            old_value VARCHAR(120) NULL,
            new_value VARCHAR(120) NULL,
            admin_ip VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cust (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare('INSERT INTO admin_audit (customer_id, what, old_value, new_value, admin_ip) VALUES (?,?,?,?,?)')
            ->execute([$customerId, $what, $old, $new, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64)]);
    } catch (Throwable $e) {
        error_log('admin_audit: ' . $e->getMessage());
    }
}

/** برای نمایش شماره به شکل آشنا 0912xxxxxxx */
function display_phone(string $normalized): string {
    if (strlen($normalized) === 12 && substr($normalized, 0, 2) === '98') {
        return '0' . substr($normalized, 2);
    }
    return $normalized;
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/** جدول‌های پیام‌های مدیر (اگر هنوز ساخته نشده باشند) */
function ensure_message_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NULL,
        title VARCHAR(120) NOT NULL,
        body TEXT NOT NULL,
        link_url VARCHAR(500) NULL,
        link_label VARCHAR(60) NULL,
        kind VARCHAR(12) NOT NULL DEFAULT 'info',
        created_at DATETIME NOT NULL,
        expires_at DATETIME NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_ann_target (customer_id, active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        customer_id INT UNSIGNED NOT NULL,
        announcement_id INT UNSIGNED NOT NULL,
        read_at DATETIME NOT NULL,
        PRIMARY KEY (customer_id, announcement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
