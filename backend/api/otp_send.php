<?php
declare(strict_types=1);
/**
 * ارسال کد ورود پیامکی (ملی‌پیامک - خط خدماتی اشتراکی، قالب ثابت تأییدشده)
 * کد را خودِ سرور می‌سازد و با متد BaseServiceNumber (REST) به‌عنوان تنها متغیر قالب {0} می‌فرستد.
 * ورودی JSON: { phone }      خروجی: { ok: true, resend_in: 60 }
 * کد هرگز به کلاینت برگردانده نمی‌شود؛ فقط هش آن در دیتابیس نگه داشته می‌شود.
 */
require dirname(__DIR__, 3) . '/private/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(string $msg, int $code = 400, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('درخواست نامعتبر است', 405);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$phoneRaw = (string)($input['phone'] ?? '');
if ($phoneRaw === '') fail('شماره موبایل را وارد کنید');
$phone = normalize_phone($phoneRaw);
if (!is_valid_mobile($phone)) fail('شماره موبایل را درست وارد کنید (مثال: 09123456789)');

$ip  = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
$now = time();
$nowStr = date('Y-m-d H:i:s', $now);

try {
    $pdo = db();
    ensure_otp_table($pdo);

    // پاک‌سازی گاه‌به‌گاه کدهای قدیمی
    if (mt_rand(1, 40) === 1) {
        $pdo->prepare('DELETE FROM otp_codes WHERE created_at < ?')->execute([date('Y-m-d H:i:s', $now - 86400)]);
    }

    // --- STAGE10: دروازهٔ سقف‌ها زیر قفل (شماره، سپس IP؛ ترتیب ثابت ⇒ بدون بن‌بست) ---
    // قبلاً: بررسی ← ارسال پیامک (تا ۱۵ ثانیه) ← درج. درخواست‌های موازی همه از بررسی رد می‌شدند و هر کدام پیامک می‌فرستاد.
    // حالا: زیر قفل، سقف‌ها بررسی و یک ردیفِ «رزروشده» با used=1 درج می‌شود (در فاصله/سقف‌ها شمرده می‌شود ولی otp_verify
    // که فقط used=0 می‌خواند آن را نمی‌بیند)؛ قفل‌ها قبل از ارسال پیامک آزاد می‌شوند و فقط چند میلی‌ثانیه نگه داشته می‌شوند.
    // فرض: MySQL ≥ 5.7 / MariaDB ≥ 10.0.2 (چند GET_LOCK هم‌زمان در یک نشست).
    $code = (string)random_int(10 ** (OTP_CODE_LEN - 1), 10 ** OTP_CODE_LEN - 1); // بدون صفر ابتدایی
    $locks = [];
    $acquire = function (string $name) use ($pdo, &$locks): bool {
        $q = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $q->execute([$name]);
        if ((int)$q->fetchColumn() !== 1) return false;
        $locks[] = $name;
        return true;
    };
    $gate = null;
    try {
        if (!$acquire('pb_otp_p_' . md5($phone)) || !$acquire('pb_otp_i_' . md5($ip))) {
            $gate = ['fail' => ['درخواست قبلی هنوز در حال پردازش است؛ چند لحظه بعد دوباره تلاش کنید.', 429, []]];
        } else {
            $gate = (function () use ($pdo, $phone, $ip, $now, $nowStr, $code) {
        // فاصلهٔ بین دو ارسال برای یک شماره
        $st = $pdo->prepare('SELECT created_at FROM otp_codes WHERE phone = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$phone]);
        $last = $st->fetchColumn();
        if ($last) {
            $wait = OTP_RESEND_COOLDOWN - ($now - strtotime((string)$last));
            if ($wait > 0) return ['fail' => ['کد قبلاً ارسال شده است؛ ' . $wait . ' ثانیهٔ دیگر دوباره تلاش کنید.', 429, ['resend_in' => $wait]]];
        }

        // سقف ساعتی برای هر شماره و هر IP
        $hourAgo = date('Y-m-d H:i:s', $now - 3600);
        $st = $pdo->prepare('SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at >= ?');
        $st->execute([$phone, $hourAgo]);
        if ((int)$st->fetchColumn() >= OTP_MAX_PER_PHONE_HOUR) return ['fail' => ['تعداد درخواست کد برای این شماره زیاد بود؛ حدود یک ساعت دیگر دوباره امتحان کنید.', 429, []]];

        $st = $pdo->prepare('SELECT COUNT(*) FROM otp_codes WHERE ip = ? AND created_at >= ?');
        $st->execute([$ip, $hourAgo]);
        if ((int)$st->fetchColumn() >= OTP_MAX_PER_IP_HOUR) return ['fail' => ['درخواست‌های زیادی از این شبکه ارسال شده؛ کمی بعد دوباره امتحان کنید.', 429, []]];

        // سقف روزانه (۲۴ ساعت گذشته) برای هر شماره و هر IP
        // (ردیف‌های قدیمی‌تر از ۲۴ ساعت در پاک‌سازی بالا حذف می‌شوند، پس این شمارش دقیق است)
        $dayAgo = date('Y-m-d H:i:s', $now - 86400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at >= ?');
        $st->execute([$phone, $dayAgo]);
        if ((int)$st->fetchColumn() >= OTP_MAX_PER_PHONE_DAY) return ['fail' => ['تعداد درخواست کد برای این شماره امروز به سقف رسیده؛ فردا دوباره امتحان کنید یا از «ورود با رمز عبور» استفاده کنید.', 429, []]];

        $st = $pdo->prepare('SELECT COUNT(*) FROM otp_codes WHERE ip = ? AND created_at >= ?');
        $st->execute([$ip, $dayAgo]);
        if ((int)$st->fetchColumn() >= OTP_MAX_PER_IP_DAY) return ['fail' => ['درخواست‌های زیادی از این شبکه ارسال شده؛ فردا دوباره امتحان کنید.', 429, []]];

                // ردیف رزروشده (used=1)؛ پس از موفقیت پیامک فعال می‌شود، در شکست حذف می‌شود
                $pdo->prepare('INSERT INTO otp_codes (phone, code_hash, code_hash2, ip, attempts, used, expires_at, created_at) VALUES (?,?,NULL,?,0,1,?,?)')
                    ->execute([$phone, otp_hash($phone, $code), $ip, date('Y-m-d H:i:s', $now + OTP_TTL), $nowStr]);
                return ['id' => (int)$pdo->lastInsertId()];
            })();
        }
    } finally {
        foreach (array_reverse($locks) as $ln) {
            try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$ln]); } catch (Throwable $e) { /* قفل با بسته‌شدن اتصال آزاد می‌شود */ }
        }
    }
    if (isset($gate['fail'])) fail($gate['fail'][0], $gate['fail'][1], $gate['fail'][2]);
    $reservedId = (int)$gate['id'];
    $dropReserved = function () use ($pdo, $reservedId): void {
        try { $pdo->prepare('DELETE FROM otp_codes WHERE id = ? AND used = 1')->execute([$reservedId]); } catch (Throwable $e) { error_log('otp_send: drop reserved: ' . $e->getMessage()); }
    };

    // --- ارسال از خط خدماتی ملی‌پیامک ---
    $to = '0' . substr($phone, 2); // 98912... -> 0912...

    $post = http_build_query([
        'username' => MELI_USERNAME,
        'password' => MELI_PASSWORD,   // اگر پنل ApiKey خواست (خطای -110)، ApiKey را اینجا بگذارید
        'text'     => $code,           // تنها متغیر قالب: {0}
        'to'       => $to,
        'bodyId'   => MELI_BODY_ID,
    ]);
    $ch = curl_init(MELI_SEND_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => MELI_SSL_VERIFY,
        CURLOPT_SSL_VERIFYHOST => MELI_SSL_VERIFY ? 2 : 0,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $result = curl_exec($ch);
    $curlErr = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);

    if ($result === false) {
        error_log('otp_send: curl error: ' . $curlErr);
        $dropReserved();
        fail('ارسال پیامک ناموفق بود؛ چند لحظه بعد دوباره تلاش کنید.', 502);
    }
    $resp = json_decode((string)$result, true);
    $retStatus = is_array($resp) ? (int)($resp['RetStatus'] ?? 0) : 0;
    $value = is_array($resp) ? trim((string)($resp['Value'] ?? '')) : '';
    // موفق: RetStatus = 1 و Value شناسهٔ ارسال (عدد بلند). در خطا Value یک کد خطای کوتاه است (مثل -4 یا 18).
    if ($retStatus !== 1 || $value === '' || !ctype_digit($value) || strlen($value) <= 4) {
        error_log('otp_send: gateway error: ' . substr((string)$result, 0, 300));
        $msg = 'ارسال پیامک ناموفق بود؛ چند لحظه بعد دوباره تلاش کنید.';
        if ($value === '18') $msg = 'شماره موبایل معتبر نیست.';
        $dropReserved();
        fail($msg, 502);
    }

    // موفق: کدهای قبلیِ استفاده‌نشدهٔ این شماره باطل و ردیف رزروشده فعال می‌شود (used=0 و انقضای تازه از لحظهٔ ارسال)
    $actNow = time();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE phone = ? AND used = 0')->execute([$phone]);
        $pdo->prepare('UPDATE otp_codes SET used = 0, expires_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', $actNow + OTP_TTL), $reservedId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode(['ok' => true, 'resend_in' => OTP_RESEND_COOLDOWN], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('otp_send: ' . $e->getMessage());
    fail('خطای سرور، دوباره تلاش کنید.', 500);
}
