<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S31.T31.19 — two-step login (TOTP, RFC 6238, authenticator app) + session management. OWNER accounts only.
 * Gate: login.php (password) and otp_verify.php (SMS) call pb_2fa_gate() — when the owner enabled 2FA the request must carry
 *   `otp2` = 6-digit TOTP code or a one-time recovery code; otherwise HTTP 401 {error, need_2fa:true} and NO session is issued
 *   (an SMS code is not consumed in that case). Failures are throttled per phone. A TOTP step can be used only once (replay-safe).
 * Actions (any logged-in OWNER; staff -> 403; perm map ''):
 *   2fa.status | 2fa.setup | 2fa.enable {code} (returns 8 recovery codes ONCE) | 2fa.disable {code|recovery} | 2fa.recovery_regen {code}
 *   sessions.list | sessions.revoke {id} (not the current one) | sessions.revoke_others
 * The secret is stored in `customer_2fa` (operational, NEVER in backups). SIGNOFF: secret is not encrypted at rest; admin-side 2FA reset not built.
 */
const PB_2FA_ISSUER = 'PestehBazar';
const PB_2FA_RECOVERY_COUNT = 8;

function pb_2fa_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS customer_2fa (
        customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
        secret VARCHAR(64) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        last_step BIGINT NOT NULL DEFAULT 0,
        recovery TEXT NULL,
        created_at DATETIME NOT NULL,
        enabled_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ---------------------------------------------------------------- TOTP primitives (pure)
function pb_b32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function pb_b32_decode(string $s): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) $bits .= str_pad(decbin(strpos($alpha, $s[$i])), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

/** HOTP (RFC 4226) with HMAC-SHA1. */
function pb_hotp(string $secretBin, int $counter, int $digits = 6): string {
    $msg = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
    $h = hash_hmac('sha1', $msg, $secretBin, true);
    $o = ord($h[19]) & 0x0F;
    $bin = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** TOTP code for a unix time (30 s step). */
function pb_totp(string $secretB32, ?int $time = null, int $digits = 6): string {
    return pb_hotp(pb_b32_decode($secretB32), intdiv($time ?? time(), 30), $digits);
}

/** Matching step (±1 window) for a 6-digit code, or null. Constant-time compare. */
function pb_totp_match_step(string $secretB32, string $code, ?int $time = null, int $window = 1): ?int {
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $bin = pb_b32_decode($secretB32);
    $step = intdiv($time ?? time(), 30);
    $hit = null;
    for ($d = -$window; $d <= $window; $d++) {
        if (hash_equals(pb_hotp($bin, $step + $d), $code)) $hit = $step + $d;
    }
    return $hit;
}

function pb_2fa_norm_recovery(string $c): string {
    return strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $c));
}

function pb_2fa_new_recovery(): array {
    $plain = [];
    for ($i = 0; $i < PB_2FA_RECOVERY_COUNT; $i++) {
        $raw = strtolower(substr(pb_b32_encode(random_bytes(7)), 0, 10)); // 10 chars of base32 (digits 2-7 + letters)
        $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }
    return $plain;
}

// ---------------------------------------------------------------- state
function pb_2fa_row(PDO $pdo, int $cid): ?array {
    pb_2fa_ensure($pdo);
    $st = $pdo->prepare('SELECT * FROM customer_2fa WHERE customer_id=?');
    $st->execute([$cid]);
    $r = $st->fetch();
    return is_array($r) ? $r : null;
}

/** Verifies a TOTP code (marks its step used) — true/false. */
function pb_2fa_check_totp(PDO $pdo, array $row, string $code): bool {
    $step = pb_totp_match_step((string)$row['secret'], $code);
    if ($step === null) return false;
    $u = $pdo->prepare('UPDATE customer_2fa SET last_step=? WHERE customer_id=? AND last_step < ?'); // replay guard (atomic)
    $u->execute([$step, (int)$row['customer_id'], $step]);
    return $u->rowCount() === 1;
}

/** Verifies a recovery code and burns it (atomic compare-and-set on the stored list). */
function pb_2fa_check_recovery(PDO $pdo, array $row, string $code): bool {
    $n = pb_2fa_norm_recovery($code);
    if (strlen($n) !== 10) return false;
    $list = json_decode((string)($row['recovery'] ?? '[]'), true);
    if (!is_array($list)) return false;
    $h = hash('sha256', $n);
    $found = false;
    $rest = [];
    foreach ($list as $x) {
        if (!$found && is_string($x) && hash_equals($x, $h)) { $found = true; continue; }
        $rest[] = $x;
    }
    if (!$found) return false;
    $u = $pdo->prepare('UPDATE customer_2fa SET recovery=? WHERE customer_id=? AND recovery=?');
    $u->execute([json_encode(array_values($rest)), (int)$row['customer_id'], (string)$row['recovery']]);
    return $u->rowCount() === 1;
}

function pb_2fa_check_any(PDO $pdo, array $row, string $code): bool {
    $code = trim($code);
    return preg_match('/^\d{6}$/', $code) ? pb_2fa_check_totp($pdo, $row, $code) : pb_2fa_check_recovery($pdo, $row, $code);
}

/**
 * Login gate. Call AFTER the first factor is proven and BEFORE issuing a session. Exits with 401 when 2FA is enabled and
 * the code is missing/wrong. $given = request field `otp2`.
 */
function pb_2fa_gate(PDO $pdo, array $customer, string $given): void {
    $row = pb_2fa_row($pdo, (int)$customer['id']);
    if (!$row || (int)$row['enabled'] !== 1) return;
    $key = '2fa:' . (string)$customer['phone'];
    if (trim($given) === '') {
        http_response_code(401);
        echo json_encode(['error' => 'کد تأیید دو مرحله‌ای را وارد کنید', 'need_2fa' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    throttle_check($key, 8, 900);
    if (!pb_2fa_check_any($pdo, $row, $given)) {
        throttle_fail($key);
        http_response_code(401);
        echo json_encode(['error' => 'کد دو مرحله‌ای اشتباه است', 'need_2fa' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    throttle_reset($key);
}

// ---------------------------------------------------------------- actions
function pb_2fa_action(string $action, array $p, PDO $pdo, array $customer, string $curToken): void {
    if ((int)($customer['_staff_id'] ?? 0) > 0) bad('فقط مالک می‌تواند این کار را انجام دهد', 403);
    $cid = (int)$customer['id'];
    pb_2fa_ensure($pdo);
    $row = pb_2fa_row($pdo, $cid);
    $on = $row && (int)$row['enabled'] === 1;
    $code = trim((string)($p['code'] ?? ''));

    switch ($action) {
        case '2fa.status': {
            $left = $on ? count((array)json_decode((string)$row['recovery'], true)) : 0;
            out(['enabled' => $on, 'recovery_left' => $left]);
        }
        case '2fa.setup': {
            if ($on) bad('ورود دو مرحله‌ای از قبل فعال است', 409);
            $secret = pb_b32_encode(random_bytes(20));
            $pdo->prepare('REPLACE INTO customer_2fa (customer_id, secret, enabled, last_step, recovery, created_at) VALUES (?,?,0,0,NULL,NOW())')->execute([$cid, $secret]);
            $uri = 'otpauth://totp/' . rawurlencode(PB_2FA_ISSUER . ':' . (string)$customer['phone']) . '?secret=' . $secret . '&issuer=' . rawurlencode(PB_2FA_ISSUER) . '&digits=6&period=30';
            out(['secret' => $secret, 'uri' => $uri]);
        }
        case '2fa.enable': {
            if ($on) bad('ورود دو مرحله‌ای از قبل فعال است', 409);
            if (!$row) bad('ابتدا راه‌اندازی را شروع کنید', 400);
            throttle_check('2fa_en:' . $cid, 10, 900);
            if (!pb_2fa_check_totp($pdo, $row, $code)) { throttle_fail('2fa_en:' . $cid); bad('کد اشتباه است', 400); }
            $plain = pb_2fa_new_recovery();
            $hashes = array_map(static function (string $c): string { return hash('sha256', pb_2fa_norm_recovery($c)); }, $plain);
            $pdo->prepare('UPDATE customer_2fa SET enabled=1, enabled_at=NOW(), recovery=? WHERE customer_id=?')->execute([json_encode($hashes), $cid]);
            pb_audit($pdo, $customer, 'update', '2fa', (string)$cid, 'فعال‌سازی ورود دو مرحله‌ای');
            out(['ok' => true, 'recovery_codes' => $plain]); // shown once
        }
        case '2fa.disable': {
            if (!$on) bad('ورود دو مرحله‌ای فعال نیست', 400);
            throttle_check('2fa_dis:' . $cid, 10, 900);
            if (!pb_2fa_check_any($pdo, $row, $code)) { throttle_fail('2fa_dis:' . $cid); bad('کد اشتباه است', 400); }
            $pdo->prepare('DELETE FROM customer_2fa WHERE customer_id=?')->execute([$cid]);
            pb_audit($pdo, $customer, 'update', '2fa', (string)$cid, 'غیرفعال‌سازی ورود دو مرحله‌ای');
            out(['ok' => true]);
        }
        case '2fa.recovery_regen': {
            if (!$on) bad('ورود دو مرحله‌ای فعال نیست', 400);
            throttle_check('2fa_dis:' . $cid, 10, 900);
            if (!pb_2fa_check_totp($pdo, $row, $code)) { throttle_fail('2fa_dis:' . $cid); bad('کد اشتباه است', 400); }
            $plain = pb_2fa_new_recovery();
            $hashes = array_map(static function (string $c): string { return hash('sha256', pb_2fa_norm_recovery($c)); }, $plain);
            $pdo->prepare('UPDATE customer_2fa SET recovery=? WHERE customer_id=?')->execute([json_encode($hashes), $cid]);
            pb_audit($pdo, $customer, 'update', '2fa', (string)$cid, 'ساخت دوبارهٔ کدهای بازیابی');
            out(['ok' => true, 'recovery_codes' => $plain]);
        }
        case 'sessions.list': {
            $cur = tok_hash($curToken);
            $st = $pdo->prepare('SELECT id, token_hash, device, created_at, last_used_at FROM customer_tokens WHERE customer_id=? AND (staff_id IS NULL OR staff_id=0) ORDER BY last_used_at DESC, id DESC');
            $st->execute([$cid]);
            $rows = [];
            foreach ($st->fetchAll() as $r) {
                $rows[] = ['id' => (int)$r['id'], 'device' => $r['device'], 'created_at' => $r['created_at'], 'last_used_at' => $r['last_used_at'], 'current' => hash_equals((string)$r['token_hash'], $cur)];
            }
            out(['sessions' => $rows]);
        }
        case 'sessions.revoke': {
            $id = (int)($p['id'] ?? 0);
            if ($id <= 0) bad('شناسهٔ نشست نامعتبر است');
            $cur = tok_hash($curToken);
            $d = $pdo->prepare('DELETE FROM customer_tokens WHERE id=? AND customer_id=? AND (staff_id IS NULL OR staff_id=0) AND token_hash <> ?');
            $d->execute([$id, $cid, $cur]);
            out(['ok' => true, 'revoked' => $d->rowCount()]);
        }
        case 'sessions.revoke_others': {
            $cur = tok_hash($curToken);
            $d = $pdo->prepare('DELETE FROM customer_tokens WHERE customer_id=? AND (staff_id IS NULL OR staff_id=0) AND token_hash <> ?');
            $d->execute([$cid, $cur]);
            pb_audit($pdo, $customer, 'update', 'sessions', (string)$cid, 'خروج از سایر دستگاه‌ها');
            out(['ok' => true, 'revoked' => $d->rowCount()]);
        }
    }
    bad('اکشن نامعتبر است', 404);
}
