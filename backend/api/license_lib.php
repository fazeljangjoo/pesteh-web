<?php
declare(strict_types=1);
/**
 * مجوز امضادار اشتراک (Ed25519) برای نسخهٔ ویندوز.
 * سرور وضعیت اشتراک را امضا می‌کند؛ برنامه فقط با کلید عمومی آن را تأیید می‌کند.
 * بنابراین کاربر نمی‌تواند تاریخ انقضا/وضعیت را در فایل‌های محلی دستکاری کند.
 * اگر کلید یا افزونهٔ sodium نباشد، null برمی‌گردد و بقیهٔ API مثل قبل کار می‌کند.
 */

require_once __DIR__ . '/features.php'; // S22.T22.2: سطح و فهرست قابلیت‌ها در خود مجوز امضا می‌شوند

/** حالت «فقط خروجی» برای طلایی منقضی (Q3): تنها این قابلیت‌ها باقی می‌مانند */
const LIC_EXPORT_ONLY_FEATURES = ['ops.excel', 'core.backup'];

function lic_b64u(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function lic_seed(): ?string {
    static $seed = false;
    if ($seed !== false) return $seed;
    $seed = null;
    $b64 = (string)(getenv('PESTEH_LIC_SEED') ?: '');
    if ($b64 === '') {
        $f = dirname(__DIR__, 3) . '/private/license_key.php';
        if (is_file($f)) {
            $v = include $f;
            if (is_string($v)) $b64 = $v;
        }
    }
    $raw = base64_decode(trim($b64), true);
    if ($raw !== false && strlen($raw) === 32) $seed = $raw;
    return $seed;
}

/** شناسهٔ دستگاه (SHA-256 هگز، ۶۴ کاراکتر) یا رشتهٔ خالی */
function lic_clean_device($raw): string {
    $d = strtolower(trim((string)$raw));
    return preg_match('/^[a-f0-9]{64}$/', $d) ? $d : '';
}

/** مجوز امضاشده برای این مشتری؛ در صورت نبودِ کلید/sodium مقدار null */
function license_issue(string $phone, array $customer, string $deviceId = ''): ?string {
    if (!function_exists('sodium_crypto_sign_detached')) {
        error_log('license_issue: ext-sodium is not available');
        return null;
    }
    $seed = lic_seed();
    if ($seed === null) {
        error_log('license_issue: signing key is not configured');
        return null;
    }
    try {
        $sk = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
        // سطح و قابلیت‌ها داخل امضا هستند: کلاینت نمی‌تواند با تغییر JSON سطح طلایی بسازد.
        // نکتهٔ سازگاری: v=1 ثابت است؛ کلیدهای تازه (tier, feat, mode) برای کلاینت قدیمی نادیده گرفته می‌شوند.
        $tier = pb_tier_of($customer);
        $exp = (string)($customer['expiry_date'] ?? '');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $expired = $exp === '' || $exp < $today;
        $exportOnly = $tier === PB_TIER_GOLD && $expired; // Q3: طلایی منقضی = فقط خروجی
        $feat = $exportOnly
            ? LIC_EXPORT_ONLY_FEATURES
            : edition_of($customer)['features'];
        $payload = [
            'v'      => 1,
            'phone'  => $phone,
            'active' => (bool)($customer['active'] ?? false),
            'exp'    => $exp,
            'plan'   => customer_plan($customer),
            'tier'   => $tier,
            'feat'   => array_values($feat),
            'mode'   => $exportOnly ? 'export_only' : 'full',
            'iat'    => time(),
            'dev'    => $deviceId,
        ];
        $body = lic_b64u((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $body . '.' . lic_b64u(sodium_crypto_sign_detached($body, $sk));
    } catch (Throwable $e) {
        error_log('license_issue: ' . $e->getMessage());
        return null;
    }
}
