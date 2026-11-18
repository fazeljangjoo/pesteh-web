<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * اعلان‌های پیامکی حساب (ورود با رمز / تغییر رمز) — از همان درگاه ملی‌پیامک کد ورود استفاده می‌کند.
 * هیچ‌کدام نباید ورود یا تغییر رمز را متوقف کند: همه خطاها در try/catch می‌مانند و فقط در لاگ ثبت می‌شوند.
 * متغیرها به ترتیب با «;» جدا و در text فرستاده می‌شوند (راهنمای SendByBaseNumber2).
 * هرگز توکن یا رمز در لاگ نمی‌آید.
 */

// شناسهٔ الگوهای تأییدشده در پنل درگاه (می‌توانید در private/config.php بازنویسی کنید)
if (!defined('PB_SMS_BODY_LOGIN'))    define('PB_SMS_BODY_LOGIN', 563963);   // {0} نام ، {1} تاریخ ، {2} ساعت
if (!defined('PB_SMS_BODY_PWCHANGE')) define('PB_SMS_BODY_PWCHANGE', 563973); // {0} نام ، {1} تاریخ ، {2} ساعت ، {3} شما|پشتیبانی
// حالت اعلان ورود با رمز: 'always' (هر ورود، با سقف زیر) | 'new_device' | 'off'
if (!defined('PB_LOGIN_SMS_MODE'))    define('PB_LOGIN_SMS_MODE', 'always');
// سقف پیامک ورود برای هر مشتری: در هر ساعت و در هر شبانه‌روز (ورود خود کاربر محدود نمی‌شود)
if (!defined('PB_LOGIN_SMS_PER_HOUR')) define('PB_LOGIN_SMS_PER_HOUR', 3);
if (!defined('PB_LOGIN_SMS_PER_DAY'))  define('PB_LOGIN_SMS_PER_DAY', 8);
// سقف پیامک تغییر رمز برای هر مشتری در شبانه‌روز (خود تغییر رمز محدود نمی‌شود)
if (!defined('PB_PWCHANGE_SMS_PER_DAY')) define('PB_PWCHANGE_SMS_PER_DAY', 5);

require_once __DIR__ . '/jdate.php'; // pb_g2j lives in jdate.php (was duplicated here)

/** ارقام فارسی */
function pb_fa_digits(string $s): string {
    return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

/** تاریخ و ساعت فعلی به وقت تهران: [تاریخ شمسی، ساعت] */
function pb_now_fa(): array {
    $d = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    [$jy, $jm, $jd] = pb_g2j((int)$d->format('Y'), (int)$d->format('n'), (int)$d->format('j'));
    $date = pb_fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    $time = pb_fa_digits($d->format('H:i'));
    return [$date, $time];
}

/** نام مشتری برای پیامک: بدون «;» و خط‌جدید، حداکثر ۴۰ حرف؛ اگر نبود «کاربر پسته بازار» */
function pb_sms_name($name): string {
    $n = is_string($name) ? trim(str_replace([';', "\r", "\n"], ' ', $name)) : '';
    if ($n === '') return 'کاربر پسته بازار';
    return mb_substr($n, 0, 40);
}

/** ارسال از درگاه. true = پذیرفته‌شده. هیچ‌وقت exception پرتاب نمی‌کند. */
function pb_sms_send(string $phone98, int $bodyId, string $text): bool {
    try {
        if (!defined('MELI_SEND_URL') || !defined('MELI_USERNAME') || !defined('MELI_PASSWORD')) {
            error_log('notify_sms: gateway constants missing');
            return false;
        }
        $to = '0' . substr($phone98, 2); // 98912... -> 0912...
        $post = http_build_query([
            'username' => MELI_USERNAME,
            'password' => MELI_PASSWORD,
            'text'     => $text,
            'to'       => $to,
            'bodyId'   => $bodyId,
        ]);
        $ch = curl_init(MELI_SEND_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => defined('MELI_SSL_VERIFY') ? (bool)MELI_SSL_VERIFY : true,
            CURLOPT_SSL_VERIFYHOST => (defined('MELI_SSL_VERIFY') && !MELI_SSL_VERIFY) ? 0 : 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $result = curl_exec($ch);
        $err = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);
        if ($result === false) { error_log('notify_sms: curl ' . $err); return false; }
        $resp = json_decode((string)$result, true);
        $ok = is_array($resp) && (int)($resp['RetStatus'] ?? 0) === 1;
        if (!$ok) error_log('notify_sms: gateway ' . substr((string)$result, 0, 200));
        return $ok;
    } catch (Throwable $e) {
        error_log('notify_sms: ' . $e->getMessage());
        return false;
    }
}

/** آیا برای این ورود با رمز پیامک بفرستیم؟ (باید قبل از صدور نشست فراخوانی شود) */
function pb_login_should_notify(PDO $pdo, array $customer, ?string $device): bool {
    try {
        if (PB_LOGIN_SMS_MODE === 'off') return false;
        if (PB_LOGIN_SMS_MODE === 'always') return true;
        $st = $pdo->prepare('SELECT COUNT(*) FROM customer_tokens WHERE customer_id = ? AND device <=> ?');
        $st->execute([(int)$customer['id'], $device]);
        return (int)$st->fetchColumn() === 0; // هیچ نشستی از این دستگاه نیست ⇒ دستگاه جدید
    } catch (Throwable $e) {
        error_log('notify_sms: device check ' . $e->getMessage());
        return false; // اگر بررسی نشد، پیامک نفرستیم (جلوی ارسال تکراری)
    }
}

/** جدول ثبت ارسال‌های پیامک (برای سقف‌ها). اگر نبود، خودکار ساخته می‌شود. */
function pb_sms_ensure_log(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS sms_send_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        kind VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_ssl_cust (customer_id, kind, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** آیا این مشتری در سقف ساعتی/روزانهٔ این نوع پیامک است؟ ($perHour=0 یعنی سقف ساعتی ندارد) */
function pb_sms_quota_ok(PDO $pdo, int $customerId, string $kind, int $perHour, int $perDay): bool {
    pb_sms_ensure_log($pdo);
    $st = $pdo->prepare("SELECT
        COALESCE(SUM(created_at >= NOW() - INTERVAL 1 HOUR), 0) AS h,
        COUNT(*) AS d
        FROM sms_send_log WHERE customer_id = ? AND kind = ? AND created_at >= NOW() - INTERVAL 1 DAY");
    $st->execute([$customerId, $kind]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['h' => 0, 'd' => 0];
    $okHour = $perHour <= 0 || (int)$r['h'] < $perHour;
    return $okHour && (int)$r['d'] < $perDay;
}

/** ثبت یک ارسال (قبل از ارسال، تا خطای درگاه باعث ارسال بی‌حساب نشود) */
function pb_sms_log(PDO $pdo, int $customerId, string $kind): void {
    pb_sms_ensure_log($pdo);
    $pdo->prepare('INSERT INTO sms_send_log (customer_id, kind, created_at) VALUES (?, ?, NOW())')
        ->execute([$customerId, $kind]);
}

/** پیامک ورود با رمز؛ اگر سقف پر باشد، ارسال نمی‌شود و ورود ادامه دارد */
function pb_notify_login(PDO $pdo, array $customer): void {
    try {
        $cid = (int)$customer['id'];
        if (!pb_sms_quota_ok($pdo, $cid, 'login', PB_LOGIN_SMS_PER_HOUR, PB_LOGIN_SMS_PER_DAY)) {
            error_log('notify_sms: login quota reached for customer ' . $cid);
            return;
        }
        pb_sms_log($pdo, $cid, 'login');
        [$date, $time] = pb_now_fa();
        $text = pb_sms_name($customer['name'] ?? '') . ';' . $date . ';' . $time;
        pb_sms_send((string)$customer['phone'], PB_SMS_BODY_LOGIN, $text);
    } catch (Throwable $e) {
        error_log('notify_sms: login ' . $e->getMessage());
    }
}

/** پیامک تغییر رمز؛ $by = 'شما' یا 'پشتیبانی' */
function pb_notify_password_changed_by_id(PDO $pdo, int $customerId, string $by): void {
    try {
        $st = $pdo->prepare('SELECT phone, name FROM customers WHERE id = ?');
        $st->execute([$customerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        if (!pb_sms_quota_ok($pdo, $customerId, 'pwchange', 0, PB_PWCHANGE_SMS_PER_DAY)) {
            error_log('notify_sms: pwchange quota reached for customer ' . $customerId);
            return;
        }
        pb_sms_log($pdo, $customerId, 'pwchange');
        [$date, $time] = pb_now_fa();
        $by = $by === 'پشتیبانی' ? 'پشتیبانی' : 'شما';
        $text = pb_sms_name($row['name']) . ';' . $date . ';' . $time . ';' . $by;
        pb_sms_send((string)$row['phone'], PB_SMS_BODY_PWCHANGE, $text);
    } catch (Throwable $e) {
        error_log('notify_sms: pwchange ' . $e->getMessage());
    }
}
