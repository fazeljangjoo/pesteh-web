<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * ثبت‌های دستیار (مرحله ۸): loan_add، loan_repay، settle_due.
 *
 * اصول (WRITE_DESIGN.md):
 *  - هیچ منطق موازی: نوشتن فقط با صدا زدن خودِ app.php (loans.create / dues.mark_paid) با توکن همان مشتری انجام می‌شود؛
 *    اعتبارسنجی، تراکنش و کلید ضدتکرار (idem) همان app.php است. app.php و جدول‌های کسب‌وکار دست نمی‌خورند.
 *  - دو مرحله‌ای: مرحلهٔ ۱ اعتبارسنجی + خلاصه + confirm_token یک‌بارمصرف (هش‌شده در DB، ۵ دقیقه، متعلق به مشتری)؛
 *    مرحلهٔ ۲ فقط با توکن؛ پارامترها از سرور خوانده می‌شود، نه از کلاینت.
 *  - پیش از اعلام موفقیت، رکورد از DB خوانده و تأیید می‌شود.
 *  - ایزوله‌سازی: هر شناسه/کلید با customer_id همان مشتری تطبیق داده می‌شود؛ ناهمخوان = ۴۰۴ یکسان.
 *  - تنها نوشتن مستقیم این فایل: assistant_write_tokens و assistant_write_audit (جدول‌های دستیار).
 */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }
require_once __DIR__ . '/assistant_h_common.php';

// ------------------------------------------------------------------ تنظیمات و سوئیچ‌ها

function as_w_cfg(): array { return (array)(as_config()['write'] ?? []); }

/** سوئیچ کل + سوئیچ همین اکشن؛ هر کدام خاموش باشد ۴۰۳ */
function as_w_require_enabled(string $id): void {
    $cfg = as_config();
    if (empty($cfg['writes_enabled']) || empty($cfg['writes'][$id])) as_bad('این عملیات در حال حاضر فعال نیست', 403);
    // STAGE11: قفل ایمنی — بدون سپر همگام‌سازی (سرور + config) هیچ ثبتی انجام نمی‌شود
    if (!defined('PB_SYNC_GUARD') || !PB_SYNC_GUARD || empty($cfg['sync_guard']['enabled'])) as_bad('این عملیات در حال حاضر فعال نیست', 403);
}

function as_w_is_sqlite(PDO $pdo): bool {
    return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

// ------------------------------------------------------------------ جدول‌های دستیار

function as_w_ensure_tables(PDO $pdo): void {
    static $done = [];
    $k = spl_object_id($pdo);
    if (isset($done[$k])) return;
    $sqlite = as_w_is_sqlite($pdo);
    $ai = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $tIdx = $sqlite ? '' : ', KEY idx_awt_cust (customer_id, created_ts)';
    $aIdx = $sqlite ? '' : ', KEY idx_awa_cust (customer_id, created_ts)';
    try {
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS assistant_write_tokens (
            id $ai,
            customer_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            action VARCHAR(30) NOT NULL,
            params_json TEXT NOT NULL,
            state_hash VARCHAR(64) NOT NULL,
            created_ts INT NOT NULL,
            expires_ts INT NOT NULL,
            used_ts INT NULL,
            result_json TEXT NULL,
            UNIQUE (token_hash)$tIdx
        )$eng");
        // STAGE11: لاگ تسویهٔ دستیار (هم‌ساختار با migration_v10_sync_guard.sql)
        $uns = $sqlite ? 'INT' : 'INT UNSIGNED';
        $lIdx = $sqlite ? '' : ', KEY idx_asl_owner (customer_id, owner_type, owner_id), KEY idx_asl_paid (paid_at)';
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS assistant_settle_log (
            id $ai,
            customer_id $uns NOT NULL,
            owner_type VARCHAR(10) NOT NULL,
            owner_id $uns NOT NULL,
            fp VARCHAR(255) NOT NULL,
            paid_at DATETIME NOT NULL,
            source VARCHAR(20) NULL DEFAULT NULL$lIdx
        )$eng");
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS assistant_write_audit (
            id $ai,
            customer_id INT NOT NULL,
            action VARCHAR(30) NOT NULL,
            outcome VARCHAR(20) NOT NULL,
            record_ref VARCHAR(80) NULL,
            amount DECIMAL(16,2) NULL,
            token_id BIGINT NULL,
            source VARCHAR(12) NOT NULL DEFAULT 'assistant',
            created_ts INT NOT NULL$aIdx
        )$eng");
    } catch (Throwable $e) {
        error_log('[assistant] write ensure_tables: ' . $e->getMessage());
        as_bad('ثبت در حال حاضر در دسترس نیست', 503); // بدون جدول توکن/حسابرسی هیچ ثبتی انجام نمی‌شود
    }
    $done[$k] = true;
    if (mt_rand(1, 100) === 1) { // پاکسازی تصادفی
        try {
            $pdo->prepare('DELETE FROM assistant_write_tokens WHERE expires_ts < ?')->execute([time() - 86400 * 2]);
            $pdo->prepare('DELETE FROM assistant_write_audit WHERE created_ts < ?')->execute([time() - 86400 * 180]);
        } catch (Throwable $e) { error_log('[assistant] write gc: ' . $e->getMessage()); }
    }
}

function as_w_audit_begin(PDO $pdo, int $cid, string $action, ?int $tokenId): int {
    $pdo->prepare("INSERT INTO assistant_write_audit (customer_id, action, outcome, token_id, source, created_ts) VALUES (?,?,'attempt',?,'assistant',?)")
        ->execute([$cid, $action, $tokenId, time()]);
    return (int)$pdo->lastInsertId();
}

function as_w_audit_end(PDO $pdo, int $auditId, string $outcome, ?string $ref = null, ?float $amount = null): void {
    try {
        $pdo->prepare('UPDATE assistant_write_audit SET outcome=?, record_ref=?, amount=? WHERE id=?')
            ->execute([mb_substr($outcome, 0, 20), $ref === null ? null : mb_substr($ref, 0, 80), $amount, $auditId]);
    } catch (Throwable $e) { error_log('[assistant] write audit_end: ' . $e->getMessage()); }
}

/** حد نرخ تلاش‌های ثبت (مرحلهٔ ۲) برای هر مشتری */
function as_w_rate_send(PDO $pdo, int $cid): void {
    $l = (array)(as_config()['write_limits'] ?? []);
    $perMin = max(1, (int)($l['per_minute'] ?? 10));
    $perDay = max(1, (int)($l['per_day'] ?? 100));
    $now = time();
    $st = $pdo->prepare('SELECT COUNT(*) FROM assistant_write_audit WHERE customer_id=? AND created_ts > ?');
    $st->execute([$cid, $now - 60]);
    if ((int)$st->fetchColumn() >= $perMin) as_bad('تعداد ثبت‌ها در این دقیقه از حد مجاز بیشتر است؛ چند لحظه بعد دوباره تلاش کنید', 429);
    $st->execute([$cid, $now - 86400]);
    if ((int)$st->fetchColumn() >= $perDay) as_bad('سقف ثبت‌های امروز پر شده است؛ بعداً دوباره تلاش کنید', 429);
}

/** حد صدور توکن تأیید (جلوگیری از پر شدن جدول) */
function as_w_rate_confirm(PDO $pdo, int $cid): void {
    $l = (array)(as_config()['write_limits'] ?? []);
    $lim = max(1, (int)($l['confirm_per_minute'] ?? 20));
    $st = $pdo->prepare('SELECT COUNT(*) FROM assistant_write_tokens WHERE customer_id=? AND created_ts > ?');
    $st->execute([$cid, time() - 60]);
    if ((int)$st->fetchColumn() >= $lim) as_bad('درخواست‌ها بیش از حد است؛ چند لحظه بعد دوباره تلاش کنید', 429);
}

// ------------------------------------------------------------------ ورودی‌ها

function as_w_digits(string $s): string {
    $s = strtr($s, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٫' => '.', '٬' => '', ',' => '', '،' => '',
    ]);
    return (string)preg_replace('/\s+/u', '', $s);
}

/** مبلغ مثبت با حداکثر ۲ رقم اعشار، یا null */
function as_w_amount($v): ?float {
    if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
    $s = as_w_digits((string)$v);
    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s)) return null;
    $a = round((float)$s, 2);
    // STAGE11.2: کاربر مبلغ را به «واحد نمایش» می‌نویسد؛ app.php مبلغ را به «واحد ذخیره» می‌خواهد (ضریب ۱۰ ریال/تومان)
    $m = as_ctx()['money'] ?? null;
    if (is_array($m) && isset($m['stored'], $m['display']) && $m['stored'] !== $m['display']) {
        $a = round($m['stored'] === 'rial' ? $a * 10 : $a / 10, 2);
    }
    $max = min((float)(as_w_cfg()['max_amount'] ?? 1.0E12), 1.0E13);
    return ($a > 0 && $a <= $max) ? $a : null;
}

function as_w_note($v): ?string {
    if (!is_string($v)) return null;
    $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{202A}|\x{202B}|\x{202C}|\x{202D}|\x{202E}|\x{2066}|\x{2067}|\x{2068}|\x{2069}/u', '', $v);
    $v = trim($v);
    if ($v === '') return null;
    return mb_strlen($v) > 500 ? mb_substr($v, 0, 500) : $v;
}

function as_w_values(array $params): ?array {
    return (isset($params['values']) && is_array($params['values'])) ? $params['values'] : null;
}

function as_w_kind_label(string $k): string {
    return ['given' => 'قرض اعطایی (به او قرض دادم)', 'taken' => 'قرض دریافتی (از او قرض گرفتم)',
            'repay_in' => 'بازپرداخت دریافتی (او پس داد)', 'repay_out' => 'بازپرداخت پرداختی (من پس دادم)'][$k] ?? $k;
}

function as_w_nav(string $again, string $label): array {
    return blk_buttons([
        ['id' => $again, 'label' => $label, 'icon' => '🔁', 'action' => as_action($again, 'start')],
        ['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')],
    ]);
}

// ------------------------------------------------------------------ دفتر قرض (فقط خواندن)

/** مانده‌ی هر طرف‌حساب: [party => [kind => sum]] */
function as_w_loan_sums(PDO $pdo, int $cid): array {
    if (!as_rd_table_exists($pdo, 'loan_entries')) return [];
    $st = $pdo->prepare('SELECT party, kind, SUM(amount) AS s FROM loan_entries WHERE customer_id=? GROUP BY party, kind');
    $st->execute([$cid]);
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(string)$r['party']][(string)$r['kind']] = (float)$r['s'];
    return $m;
}

/** مانده‌ی قرض: base=given ⇒ طلب ما (given − repay_in)؛ base=taken ⇒ بدهی ما (taken − repay_out) */
function as_w_owed(PDO $pdo, int $cid, string $party, string $base): float {
    if (!as_rd_table_exists($pdo, 'loan_entries')) return 0.0;
    $rep = $base === 'given' ? 'repay_in' : 'repay_out';
    $st = $pdo->prepare('SELECT COALESCE(SUM(CASE WHEN kind=? THEN amount WHEN kind=? THEN -amount ELSE 0 END),0) FROM loan_entries WHERE customer_id=? AND party=?');
    $st->execute([$base, $rep, $cid, $party]);
    return (float)$st->fetchColumn();
}

/** آیا این نام قبلاً در دفترهای همین مشتری دیده شده؟ (برای هشدار غلط‌املایی) */
function as_w_party_known(PDO $pdo, int $cid, string $party): bool {
    foreach ([['parties', 'name'], ['loan_entries', 'party'], ['purchases', 'seller'], ['sales', 'buyer']] as [$t, $c]) {
        if (!as_rd_table_exists($pdo, $t)) continue;
        $st = $pdo->prepare("SELECT 1 FROM $t WHERE customer_id=? AND $c=? LIMIT 1");
        $st->execute([$cid, $party]);
        if ($st->fetchColumn()) return true;
    }
    return false;
}

// ------------------------------------------------------------------ توکن تأیید

function as_w_state_hash(array $state): string {
    return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

/** توکن تصادفی می‌سازد؛ فقط هش آن ذخیره می‌شود */
function as_w_token_issue(PDO $pdo, int $cid, string $action, array $params, string $stateHash): string {
    $ttl = max(60, min(600, (int)(as_w_cfg()['confirm_ttl'] ?? 300)));
    $token = bin2hex(random_bytes(24));
    $now = time();
    $pdo->prepare('INSERT INTO assistant_write_tokens (customer_id, token_hash, action, params_json, state_hash, created_ts, expires_ts) VALUES (?,?,?,?,?,?,?)')
        ->execute([$cid, hash('sha256', $token), $action, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $stateHash, $now, $now + $ttl]);
    return $token;
}

// ------------------------------------------------------------------ تماس با app.php (تنها مسیر نوشتن)

/**
 * آدرس app.php از تنظیمات (نه از هدر کلاینت). فقط https (یا http برای localhost/127.0.0.1)؛ وگرنه null.
 */
function as_w_api_url(): ?string {
    $u = trim((string)(as_w_cfg()['api_url'] ?? ''));
    if ($u === '') return null;
    $p = parse_url($u);
    if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) return null;
    $scheme = strtolower((string)$p['scheme']);
    $host = strtolower((string)$p['host']);
    $local = in_array($host, ['localhost', '127.0.0.1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $local)) return null;
    if (isset($p['user']) || isset($p['pass'])) return null;
    return $u;
}

/** @return array{0:int,1:?array} [کد HTTP (۰ = خطای شبکه/زمان)، بدنهٔ JSON] */
function as_w_http(string $url, string $json): array {
    $body = false; $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $json, 'timeout' => 25, 'ignore_errors' => true, 'follow_location' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false && isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string)$http_response_header[0], $m)) $code = (int)$m[1];
    }
    if ($body === false || $code === 0 || strlen((string)$body) > 2097152) return [0, null];
    $j = json_decode((string)$body, true);
    return [$code, is_array($j) ? $j : null];
}

/**
 * اکشن app.php را با نشست همان مشتری اجرا می‌کند. توکن مشتری فقط به api_url تنظیم‌شده فرستاده می‌شود.
 * در تست‌ها می‌توان as_test_app_call را تعریف کرد (در محیط واقعی هیچ‌کس نمی‌تواند تابع تعریف کند).
 * @return array{0:int,1:?array}
 */
function as_w_app_call(array $customer, string $action, array $payload, ?string $idem): array {
    if (function_exists('as_test_app_call')) return as_test_app_call($customer, $action, $payload, $idem);
    $url = as_w_api_url();
    if ($url === null) { error_log('[assistant] write: write.api_url تنظیم نشده/نامعتبر است'); return [0, null]; }
    $body = ['phone' => (string)($customer['phone'] ?? ''), 'token' => (string)($customer['_presented_token'] ?? ''), 'action' => $action, 'payload' => $payload, 'unit' => (string)((as_ctx()['money']['stored'] ?? 'toman') === 'rial' ? 'rial' : 'toman')]; // STAGE11.2: بدون unit، app.php برای مشتری ریالی ۴۲۶ می‌دهد؛ STAGE14: توکنی که کلاینت ارائه کرده؛ نه ستون قدیمی
    if ($idem !== null) $body['idem'] = $idem;
    return as_w_http($url, json_encode($body, JSON_UNESCAPED_UNICODE));
}

/** پیش‌از صدور تأیید: اطمینان از اینکه مسیر app.php کار می‌کند (فقط‌خواندنی: account.status) */
function as_w_preflight(array $customer): void {
    if (!function_exists('as_test_app_call') && as_w_api_url() === null) {
        error_log('[assistant] write: write.api_url تنظیم نشده/نامعتبر است');
        as_bad('ثبت در حال حاضر در دسترس نیست', 503);
    }
    [$code, $data] = as_w_app_call($customer, 'account.status', [], null);
    if ($code !== 200 || !is_array($data) || !isset($data['item'])) {
        error_log('[assistant] write: preflight ناموفق (کد ' . $code . ')');
        as_bad('ثبت در حال حاضر در دسترس نیست؛ کمی بعد دوباره تلاش کنید', 503);
    }
}

// ------------------------------------------------------------------ اجرای مرحلهٔ ۲ (مشترک)

/** وضعیت فعلیِ هدف؛ null = هدف دیگر معتبر نیست */
function as_w_current_state(PDO $pdo, int $cid, string $action, array $p): ?string {
    if ($action === 'loan_add') {
        return as_w_state_hash([$p['party'], $p['kind'], (float)$p['amount'], $p['date']]);
    }
    if ($action === 'loan_repay') {
        $base = $p['kind'] === 'repay_in' ? 'given' : 'taken';
        $owed = as_w_owed($pdo, $cid, (string)$p['party'], $base);
        if ($owed <= 0.5 || (float)$p['amount'] > $owed + 0.5) return null;
        return as_w_state_hash([$p['party'], $p['kind'], round($owed, 2), (float)$p['amount']]);
    }
    if ($action === 'settle_due') {
        foreach (as_rd_dues_all($pdo, $cid) as $d) {
            if ($d['table'] === $p['table'] && $d['id'] === (int)$p['id']) {
                return as_w_state_hash([$d['table'], $d['id'], round($d['amount'], 2), $d['date'], $d['party'], $d['receivable']]);
            }
        }
        return null;
    }
    return null;
}

/** تأیید پس از ثبت: رکورد واقعاً در DB همان مشتری هست؟ */
function as_w_verify(PDO $pdo, int $cid, string $action, array $p, string $uid): bool {
    if ($action === 'loan_add' || $action === 'loan_repay') {
        $st = $pdo->prepare('SELECT amount, party, kind FROM loan_entries WHERE customer_id=? AND uid=?');
        $st->execute([$cid, $uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($r) && abs((float)$r['amount'] - (float)$p['amount']) < 0.01 && (string)$r['party'] === (string)$p['party'] && (string)$r['kind'] === (string)$p['kind'];
    }
    if ($action === 'settle_due') {
        $allowed = ['lump_tx', 'credit_inst', 'cheque_inst', 'receipt_lump_tx', 'receipt_credit_inst', 'receipt_cheque_inst', 'loan_dues'];
        if (!in_array($p['table'], $allowed, true)) return false;
        $st = $pdo->prepare("SELECT paid FROM {$p['table']} WHERE id=? AND customer_id=?");
        $st->execute([(int)$p['id'], $cid]);
        $v = $st->fetchColumn();
        return $v !== false && (int)$v === 1;
    }
    return false;
}

/**
 * STAGE11: ثبت لاگ تسویه «پیش از» dues.mark_paid (طراحی S2). fp = due_date|amount|cheque_no (یکجا: date|amount|)
 * با همان نرمال‌سازی sg_fp در sync_guard.php (مبلغ دو رقم اعشار، cheque_no تریم و حداکثر ۶۰). false ⇒ ثبت نکن.
 * ورودی لاگِ بدون تسویهٔ واقعی بی‌خطر است: سرور فقط ردیف‌های «هم‌اکنون paid=1 در DB» را حفظ می‌کند.
 */
function as_w_sync_log(PDO $pdo, int $cid, string $table, int $rowId, string $tokenHash): bool {
    $map = [
        'lump_tx' => ['purchase', 'payments', 'payment_id', 'purchase_id', 'lump', 'date'],
        'credit_inst' => ['purchase', 'payments', 'payment_id', 'purchase_id', 'credit', 'due_date'],
        'cheque_inst' => ['purchase', 'payments', 'payment_id', 'purchase_id', 'cheque', 'due_date'],
        'receipt_lump_tx' => ['sale', 'receipts', 'receipt_id', 'sale_id', 'lump', 'date'],
        'receipt_credit_inst' => ['sale', 'receipts', 'receipt_id', 'sale_id', 'credit', 'due_date'],
        'receipt_cheque_inst' => ['sale', 'receipts', 'receipt_id', 'sale_id', 'cheque', 'due_date'],
    ];
    if (!isset($map[$table])) return false;
    [$ownerType, $ptbl, $fk, $ofk, $kind, $dcol] = $map[$table];
    $source = 'as:' . substr($tokenHash, 0, 16);
    try {
        $ex = $pdo->prepare('SELECT 1 FROM assistant_settle_log WHERE customer_id=? AND source=? LIMIT 1');
        $ex->execute([$cid, $source]);
        if ($ex->fetchColumn()) return true; // تلاش مجدد با همان توکن: دوباره درج نمی‌شود
        $cheque = $kind === 'cheque' ? 'x.cheque_no' : 'NULL';
        $q = $pdo->prepare("SELECT x.$dcol AS d, x.amount AS amount, $cheque AS cheque_no, p.$ofk AS oid FROM $table x
            JOIN $ptbl p ON p.id = x.$fk AND p.customer_id = x.customer_id WHERE x.id=? AND x.customer_id=?");
        $q->execute([$rowId, $cid]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) return false;
        $amt = sprintf('%.2f', round((float)$r['amount'], 2));
        $c = $kind === 'cheque' ? trim((string)($r['cheque_no'] ?? '')) : '';
        if (mb_strlen($c) > 60) $c = mb_substr($c, 0, 60);
        $fp = trim((string)$r['d']) . '|' . $amt . '|' . $c;
        $pdo->prepare('INSERT INTO assistant_settle_log (customer_id, owner_type, owner_id, fp, paid_at, source) VALUES (?,?,?,?,?,?)')
            ->execute([$cid, $ownerType, (int)$r['oid'], $fp, date('Y-m-d H:i:s'), $source]);
        return true;
    } catch (Throwable $e) {
        error_log('[assistant] sync_log: ' . $e->getMessage());
        return false;
    }
}

function as_w_send(array $ctx, string $id): array {
    $pdo = $ctx['pdo']; $cid = (int)$ctx['cid']; $customer = (array)($ctx['customer'] ?? []);
    $tok = $ctx['params']['confirm_token'] ?? null;
    $deny = 'تأیید نامعتبر است؛ لطفاً از ابتدا شروع کنید';
    if (!is_string($tok) || !preg_match('/^[a-f0-9]{48}$/', $tok)) as_bad($deny, 400);
    as_w_ensure_tables($pdo);

    $sel = $pdo->prepare('SELECT * FROM assistant_write_tokens WHERE customer_id=? AND token_hash=? LIMIT 1');
    $sel->execute([$cid, hash('sha256', $tok)]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (string)$row['action'] !== $id) as_bad($deny, 404); // توکن مشتری دیگر / اکشن دیگر / ناموجود: پاسخ یکسان

    // ثبت قبلاً انجام شده: همان پاسخ قبلی (ضدتکرار)
    if ($row['used_ts'] !== null && $row['result_json'] !== null) {
        $stored = json_decode((string)$row['result_json'], true);
        if (is_array($stored)) {
            $a = as_w_audit_begin($pdo, $cid, $id, (int)$row['id']);
            as_w_audit_end($pdo, $a, 'replay');
            return $stored;
        }
    }
    if ($row['used_ts'] !== null) as_bad($deny, 409);
    if ((int)$row['expires_ts'] < time()) as_bad('مهلت تأیید تمام شد؛ لطفاً از ابتدا شروع کنید', 410);

    as_w_rate_send($pdo, $cid);
    $auditId = as_w_audit_begin($pdo, $cid, $id, (int)$row['id']);
    $p = json_decode((string)$row['params_json'], true);

    // قفل هم‌زمانیِ هر مشتری (MySQL): دو ثبت دستیار برای یک مشتری هم‌زمان اجرا نمی‌شود.
    // (exit داخل try بلوک finally را اجرا نمی‌کند؛ پس قبل از هر خروج، قفل صریحاً آزاد می‌شود.)
    $lockName = 'pb_as_w_' . $cid; $locked = false;
    $release = function () use ($pdo, $lockName, &$locked): void {
        if (!$locked) return;
        $locked = false;
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $e) { /* با بسته‌شدن اتصال آزاد می‌شود */ }
    };
    $bail = function (string $outcome, string $msg, int $code) use ($pdo, $auditId, $release): void {
        as_w_audit_end($pdo, $auditId, $outcome);
        $release();
        as_bad($msg, $code);
    };
    if (!is_array($p)) $bail('bad_token', $deny, 400);
    if (!as_w_is_sqlite($pdo)) {
        $ls = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $ls->execute([$lockName]);
        $locked = ((int)$ls->fetchColumn()) === 1;
        if (!$locked) $bail('busy', 'درخواست دیگری در حال انجام است؛ چند لحظه بعد دوباره تلاش کنید', 409);
    }
    try {
        // پس از گرفتن قفل دوباره بخوان (ممکن است درخواست هم‌زمان تمام کرده باشد)
        $sel->execute([$cid, hash('sha256', $tok)]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && $row['used_ts'] !== null && $row['result_json'] !== null) {
            $stored = json_decode((string)$row['result_json'], true);
            if (is_array($stored)) { as_w_audit_end($pdo, $auditId, 'replay'); return $stored; }
        }
        if (!is_array($row) || $row['used_ts'] !== null) $bail('used', $deny, 409);

        // وضعیتی که کاربر تأیید کرد هنوز همان است؟ (مثلاً از اپ تغییر نکرده)
        $cur = as_w_current_state($pdo, $cid, $id, $p);
        if ($cur === null || !hash_equals((string)$row['state_hash'], $cur)) {
            $bail('stale', 'وضعیت حساب بین دو مرحله تغییر کرده است؛ لطفاً دوباره از ابتدا شروع کنید', 409);
        }

        $idem = 'as' . substr(hash('sha256', 'w|' . $cid . '|' . $row['id'] . '|' . $row['token_hash']), 0, 40);
        $uid = 'A' . substr(hash('sha256', 'u|' . $row['token_hash']), 0, 30);
        if ($id === 'settle_due' && (string)$p['table'] !== 'loan_dues'
            && !as_w_sync_log($pdo, $cid, (string)$p['table'], (int)$p['id'], (string)$row['token_hash'])) {
            // STAGE11: بدون لاگ، اپِ کهنه می‌تواند تسویه را گم کند ⇒ ثبت نکن (هنوز هیچ تغییری در داده‌ها نیست)
            $bail('sync_log_failed', 'ثبت در حال حاضر در دسترس نیست؛ کمی بعد دوباره تلاش کنید', 503);
        }
        if ($id === 'settle_due') {
            [$code, $data] = as_w_app_call($customer, 'dues.mark_paid', ['table' => (string)$p['table'], 'id' => (int)$p['id'], 'paid' => 1], $idem);
        } else {
            [$code, $data] = as_w_app_call($customer, 'loans.create', [
                'uid' => $uid, 'party' => (string)$p['party'], 'kind' => (string)$p['kind'],
                'amount' => (float)$p['amount'], 'date' => (string)$p['date'], 'note' => $p['note'] ?? null,
            ], $idem);
        }

        if ($code === 409) { // app.php: درخواست قبلی هنوز در حال پردازش
            $bail('busy', 'درخواست قبلی هنوز در حال پردازش است؛ چند لحظه بعد همین دکمه را دوباره بزنید', 409);
        }
        if ($code >= 400 && $code < 500 && is_array($data) && isset($data['error'])) {
            $bail('rejected', mb_substr((string)$data['error'], 0, 200), 400); // پیام فارسی همان app.php؛ هیچ ثبتی انجام نشده
        }
        if ($code !== 200 || !is_array($data) || isset($data['error'])) {
            // نتیجه نامشخص (قطع شبکه/زمان/۵۰۰): ممکن است ثبت شده باشد. توکن معتبر می‌ماند؛ تلاش دوباره با همان idem ثبت تکراری نمی‌سازد
            $pdo->prepare('UPDATE assistant_write_tokens SET expires_ts=? WHERE id=? AND used_ts IS NULL')->execute([time() + 1800, (int)$row['id']]);
            $bail('unknown', 'نتیجهٔ ثبت مشخص نشد. همین دکمهٔ تأیید را دوباره بزنید (ثبت تکراری انجام نمی‌شود)؛ اگر باز هم خطا داد، فهرست را بررسی کنید.', 502);
        }

        // تأیید واقعی از DB پیش از اعلام موفقیت
        if (!as_w_verify($pdo, $cid, $id, $p, $uid)) {
            error_log('[assistant] write: ثبت در app.php موفق گزارش شد ولی در DB تأیید نشد؛ action=' . $id . ' cid=' . $cid);
            $bail('unverified', 'ثبت تأیید نشد؛ لطفاً فهرست را بررسی کنید و در صورت نیاز با پشتیبانی تماس بگیرید', 500);
        }

        $resp = as_w_success_response($pdo, $cid, $id, $p);
        $pdo->prepare('UPDATE assistant_write_tokens SET used_ts=?, result_json=? WHERE id=? AND used_ts IS NULL')
            ->execute([time(), json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), (int)$row['id']]);
        $ref = $id === 'settle_due' ? ($p['table'] . ':' . $p['id']) : $uid;
        as_w_audit_end($pdo, $auditId, 'ok', $ref, isset($p['amount']) ? (float)$p['amount'] : (isset($p['due_amount']) ? (float)$p['due_amount'] : null));
        return $resp;
    } finally {
        $release();
    }
}

function as_w_success_response(PDO $pdo, int $cid, string $id, array $p): array {
    $cur = as_money_unit();
    $lines = [];
    if ($id === 'settle_due') {
        $lines[] = ['label' => 'طرف‌حساب', 'value' => (string)$p['party']];
        $lines[] = ['label' => $p['receivable'] ? 'دریافت‌شد' : 'پرداخت‌شد', 'value' => as_money((float)$p['due_amount'])];
        $lines[] = ['label' => 'سررسید', 'value' => as_jdate((string)$p['date'])];
    } else {
        $base = in_array($p['kind'], ['given', 'repay_in'], true) ? 'given' : 'taken';
        $owed = as_w_owed($pdo, $cid, (string)$p['party'], $base);
        $lines[] = ['label' => 'طرف‌حساب', 'value' => (string)$p['party']];
        $lines[] = ['label' => 'نوع', 'value' => as_w_kind_label((string)$p['kind'])];
        $lines[] = ['label' => 'مبلغ', 'value' => as_money((float)$p['amount'])];
        $lines[] = ['label' => $base === 'given' ? 'مانده‌ی طلب از او' : 'مانده‌ی بدهی به او', 'value' => as_money($owed)];
    }
    $again = $id;
    return as_response([
        blk_notice('ثبت انجام شد ✅', 'success'),
        blk_card('خلاصهٔ ثبت', $lines),
        blk_notice('این ثبت روی سرور انجام شد. در اپ بعد از همگام‌سازی (حداکثر چند دقیقه) دیده می‌شود؛ برای دیدن فوری، در اپ از صفحهٔ همگام‌سازی «بازخوانی کامل از سرور» را بزنید. تا آن موقع همین مورد را در اپ دوباره ثبت نکنید.', 'info'),
        as_w_nav($again, 'ثبت دیگر'),
    ], ['data_note' => null]);
}

// ------------------------------------------------------------------ اکشن: loan_add

function as_h_loan_add(array $ctx): array {
    $id = 'loan_add'; as_w_require_enabled($id);
    $pdo = $ctx['pdo']; $cid = (int)$ctx['cid']; $step = (string)($ctx['step'] ?? 'start'); $params = (array)($ctx['params'] ?? []);
    if ($step === 'send') return as_w_send($ctx, $id);
    $cur = as_money_unit();

    if ($step === 'start') {
        return as_response([
            blk_text('ثبت قرض: کدام حالت است؟ (تاریخ ثبت امروز است؛ سررسید اقساطی را از داخل اپ ثبت کنید.)'),
            blk_buttons([
                ['id' => 'given', 'label' => 'به او قرض دادم', 'icon' => '📤', 'action' => as_action($id, 'answer', ['kind' => 'given'])],
                ['id' => 'taken', 'label' => 'از او قرض گرفتم', 'icon' => '📥', 'action' => as_action($id, 'answer', ['kind' => 'taken'])],
                ['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')],
            ]),
        ], ['data_note' => null]);
    }
    if ($step !== 'answer') as_bad('درخواست نامعتبر است', 400);

    $kind = $params['kind'] ?? null;
    if (!is_string($kind) || !in_array($kind, ['given', 'taken'], true)) as_bad('درخواست نامعتبر است', 400);
    $vals = as_w_values($params);
    if ($vals === null) {
        return as_response([
            blk_text(as_w_kind_label($kind) . ' — اطلاعات را وارد کنید.'),
            blk_form('ثبت قرض', [
                ['name' => 'party', 'label' => 'نام طرف‌حساب', 'kind' => 'text', 'hint' => 'دقیقاً مثل نامی که در اپ دارید (حروف مشابه یک نام یک حساب می‌شود)'],
                ['name' => 'amount', 'label' => 'مبلغ (' . $cur . ')', 'kind' => 'number'],
                ['name' => 'note', 'label' => 'توضیح (اختیاری)', 'kind' => 'text'],
            ], ['label' => 'ادامه', 'action' => as_action($id, 'answer', ['kind' => $kind])]),
        ], ['data_note' => null]);
    }

    $party = as_party_key($vals['party'] ?? '');
    if ($party === '' || mb_strlen($party) > 150) as_bad('نام طرف‌حساب را وارد کنید (حداکثر ۱۵۰ حرف)', 400);
    $amount = as_w_amount($vals['amount'] ?? null);
    if ($amount === null) as_bad('مبلغ را درست وارد کنید (عدد مثبت، حداکثر دو رقم اعشار)', 400);
    $note = as_w_note($vals['note'] ?? null);
    $date = as_rd_date_offset(0);

    as_w_ensure_tables($pdo);
    as_w_rate_confirm($pdo, $cid);
    as_w_preflight((array)($ctx['customer'] ?? []));
    $p = ['party' => $party, 'kind' => $kind, 'amount' => $amount, 'date' => $date, 'note' => $note];
    $token = as_w_token_issue($pdo, $cid, $id, $p, as_w_current_state($pdo, $cid, $id, $p) ?? '');

    $lines = [
        ['label' => 'طرف‌حساب', 'value' => $party],
        ['label' => 'نوع', 'value' => as_w_kind_label($kind)],
        ['label' => 'مبلغ', 'value' => as_money($amount)],
        ['label' => 'تاریخ ثبت', 'value' => as_jdate($date)],
        ['label' => 'اثر روی حساب', 'value' => $kind === 'given' ? 'طلب شما از او افزوده می‌شود' : 'بدهی شما به او افزوده می‌شود'],
    ];
    if ($note !== null) $lines[] = ['label' => 'توضیح', 'value' => $note];
    $blocks = [];
    if (!as_w_party_known($pdo, $cid, $party)) {
        $blocks[] = blk_notice('این نام قبلاً در دفترهای شما نبوده؛ با تأیید، یک طرف‌حساب تازه ساخته می‌شود. اگر غلط‌املایی دارد انصراف دهید.', 'warning');
    }
    $blocks[] = blk_confirm('تأیید ثبت قرض', $lines, as_action($id, 'send', ['confirm_token' => $token]), 'تأیید و ثبت');
    return as_response($blocks, ['data_note' => null]);
}

// ------------------------------------------------------------------ اکشن: loan_repay

/** فهرست مانده‌های بازپرداخت‌پذیر: [['key','party','base','kind','owed']] */
function as_w_repay_options(PDO $pdo, int $cid): array {
    $out = [];
    foreach (as_w_loan_sums($pdo, $cid) as $party => $k) {
        $party = (string)$party; // کلیدهای عددی‌نما در PHP int می‌شوند
        foreach ([['given', 'repay_in'], ['taken', 'repay_out']] as [$base, $rep]) {
            $owed = ($k[$base] ?? 0.0) - ($k[$rep] ?? 0.0);
            if ($owed > 0.5) $out[] = ['key' => as_rd_key($cid, 'lrep', $base . '|' . $party), 'party' => $party, 'base' => $base, 'kind' => $rep, 'owed' => $owed];
        }
    }
    usort($out, function (array $a, array $b): int { return $b['owed'] <=> $a['owed'] ?: strcmp($a['party'], $b['party']); });
    return $out;
}

function as_h_loan_repay(array $ctx): array {
    $id = 'loan_repay'; as_w_require_enabled($id);
    $pdo = $ctx['pdo']; $cid = (int)$ctx['cid']; $step = (string)($ctx['step'] ?? 'start'); $params = (array)($ctx['params'] ?? []);
    if ($step === 'send') return as_w_send($ctx, $id);
    $cur = as_money_unit();
    $opts = as_w_repay_options($pdo, $cid);

    if ($step === 'start') {
        if (!$opts) {
            return as_response([blk_notice('قرض بازی که نیاز به بازپرداخت داشته باشد پیدا نشد.', 'info'),
                blk_buttons([['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')]])], ['data_note' => null]);
        }
        $items = [];
        foreach (array_slice($opts, 0, 50) as $o) {
            $items[] = ['label' => $o['party'], 'sublabel' => ($o['base'] === 'given' ? 'طلب شما: ' : 'بدهی شما: ') . as_money($o['owed']),
                'action' => as_action($id, 'answer', ['k' => $o['key']])];
        }
        return as_response([blk_choice('بازپرداخت برای کدام طرف‌حساب؟', $items, true)], ['data_note' => null]);
    }
    if ($step !== 'answer') as_bad('درخواست نامعتبر است', 400);

    $k = $params['k'] ?? null;
    if (!is_string($k) || !preg_match('/^[a-f0-9]{16}$/', $k)) as_bad('مورد یافت نشد', 404);
    $sel = null;
    foreach ($opts as $o) { if (hash_equals($o['key'], $k)) { $sel = $o; break; } }
    if ($sel === null) as_bad('مورد یافت نشد', 404);

    $vals = as_w_values($params);
    if ($vals === null) {
        return as_response([
            blk_text(($sel['base'] === 'given' ? 'طلب شما از ' : 'بدهی شما به ') . $sel['party'] . ': ' . as_money($sel['owed']) . '. مبلغ بازپرداخت را وارد کنید (حداکثر تا همین مانده).'),
            blk_form('ثبت بازپرداخت', [
                ['name' => 'amount', 'label' => 'مبلغ (' . $cur . ')', 'kind' => 'number'],
                ['name' => 'note', 'label' => 'توضیح (اختیاری)', 'kind' => 'text'],
            ], ['label' => 'ادامه', 'action' => as_action($id, 'answer', ['k' => $k])]),
        ], ['data_note' => null]);
    }

    $amount = as_w_amount($vals['amount'] ?? null);
    if ($amount === null) as_bad('مبلغ را درست وارد کنید (عدد مثبت، حداکثر دو رقم اعشار)', 400);
    if ($amount > $sel['owed'] + 0.5) as_bad('مبلغ بازپرداخت (' . as_num($amount, 2) . ') از مانده (' . as_num($sel['owed'], 2) . ') بیشتر است', 400);
    $note = as_w_note($vals['note'] ?? null);
    $date = as_rd_date_offset(0);

    as_w_ensure_tables($pdo);
    as_w_rate_confirm($pdo, $cid);
    as_w_preflight((array)($ctx['customer'] ?? []));
    $p = ['party' => $sel['party'], 'kind' => $sel['kind'], 'amount' => $amount, 'date' => $date, 'note' => $note];
    $state = as_w_current_state($pdo, $cid, $id, $p);
    if ($state === null) as_bad('مانده‌ی قرض تغییر کرده است؛ دوباره شروع کنید', 409);
    $token = as_w_token_issue($pdo, $cid, $id, $p, $state);

    $after = $sel['owed'] - $amount;
    $lines = [
        ['label' => 'طرف‌حساب', 'value' => $sel['party']],
        ['label' => 'نوع', 'value' => as_w_kind_label($sel['kind'])],
        ['label' => 'مبلغ', 'value' => as_money($amount)],
        ['label' => 'تاریخ ثبت', 'value' => as_jdate($date)],
        ['label' => 'مانده قبل ← بعد', 'value' => as_num($sel['owed'], 2) . ' ← ' . as_money($after)],
    ];
    if ($note !== null) $lines[] = ['label' => 'توضیح', 'value' => $note];
    return as_response([blk_confirm('تأیید ثبت بازپرداخت', $lines, as_action($id, 'send', ['confirm_token' => $token]), 'تأیید و ثبت')], ['data_note' => null]);
}

// ------------------------------------------------------------------ اکشن: settle_due

function as_h_settle_due(array $ctx): array {
    $id = 'settle_due'; as_w_require_enabled($id);
    $pdo = $ctx['pdo']; $cid = (int)$ctx['cid']; $step = (string)($ctx['step'] ?? 'start'); $params = (array)($ctx['params'] ?? []);
    if ($step === 'send') return as_w_send($ctx, $id);
    $cur = as_money_unit();
    $cfg = as_w_cfg();
    $allowLoan = !empty($cfg['settle_loan_dues']);
    $rows = array_values(array_filter(as_rd_dues_all($pdo, $cid), function (array $d) use ($allowLoan): bool {
        return $allowLoan || $d['table'] !== 'loan_dues';
    }));

    if ($step === 'start') {
        if (!$rows) {
            return as_response([blk_notice('سررسید بازی برای تسویه پیدا نشد.', 'info'),
                blk_buttons([['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')]])], ['data_note' => null]);
        }
        $max = max(1, min(100, (int)($cfg['settle_max_rows'] ?? 30)));
        $items = [];
        foreach (array_slice($rows, 0, $max) as $d) {
            $items[] = ['label' => $d['party'] . ' — ' . as_money($d['amount']),
                'sublabel' => ($d['receivable'] ? 'دریافت' : 'پرداخت') . ' • ' . $d['kind'] . ' • سررسید ' . as_jdate($d['date']),
                'action' => as_action($id, 'answer', ['k' => as_rd_key($cid, 'due', $d['table'] . '|' . $d['id'])])];
        }
        $title = 'کدام مورد تسویه شد؟' . (count($rows) > $max ? ' (نزدیک‌ترین ' . as_fa_digits((string)$max) . ' مورد؛ بقیه را از اپ ثبت کنید)' : '');
        return as_response([blk_choice($title, $items, true)], ['data_note' => null]);
    }
    if ($step !== 'answer') as_bad('درخواست نامعتبر است', 400);

    $k = $params['k'] ?? null;
    if (!is_string($k) || !preg_match('/^[a-f0-9]{16}$/', $k)) as_bad('مورد یافت نشد', 404);
    $sel = null;
    foreach ($rows as $d) {
        if (hash_equals(as_rd_key($cid, 'due', $d['table'] . '|' . $d['id']), $k)) { $sel = $d; break; }
    }
    if ($sel === null) as_bad('مورد یافت نشد', 404); // کلید مشتری دیگر / تسویه‌شده / ناموجود: پاسخ یکسان

    as_w_ensure_tables($pdo);
    as_w_rate_confirm($pdo, $cid);
    as_w_preflight((array)($ctx['customer'] ?? []));
    $p = ['table' => $sel['table'], 'id' => $sel['id'], 'party' => $sel['party'], 'date' => $sel['date'],
          'receivable' => $sel['receivable'], 'due_amount' => round($sel['amount'], 2)];
    $state = as_w_current_state($pdo, $cid, $id, $p);
    if ($state === null) as_bad('این سررسید دیگر قابل تسویه نیست؛ دوباره شروع کنید', 409);
    $token = as_w_token_issue($pdo, $cid, $id, $p, $state);

    $lines = [
        ['label' => 'طرف‌حساب', 'value' => $sel['party']],
        ['label' => 'نوع', 'value' => ($sel['receivable'] ? 'دریافت' : 'پرداخت') . ' — ' . $sel['kind'] . ($sel['label'] !== '' ? ' (' . $sel['label'] . ')' : '')],
        ['label' => 'مبلغ', 'value' => as_money($sel['amount'])],
        ['label' => 'سررسید', 'value' => as_jdate($sel['date'])],
    ];
    if ($sel['ref'] !== '') $lines[] = ['label' => 'شمارهٔ چک', 'value' => (string)$sel['ref']];
    if ($sel['bank'] !== '') $lines[] = ['label' => 'بانک', 'value' => (string)$sel['bank']];
    if ($sel['table'] === 'loan_dues') {
        $base = $sel['receivable'] ? 'given' : 'taken';
        $owed = as_w_owed($pdo, $cid, $sel['party'], $base);
        $lines[] = ['label' => 'اثر روی حساب', 'value' => 'سررسید تسویه می‌شود و بازپرداخت ' . as_money(min($sel['amount'], $owed)) . ' (حداکثر تا مانده) در دفتر قرض ثبت می‌شود'];
    } else {
        $lines[] = ['label' => 'اثر روی حساب', 'value' => 'این ردیف «' . ($sel['receivable'] ? 'دریافت‌شده' : 'پرداخت‌شده') . '» علامت می‌خورد؛ مبلغ و تاریخ تغییر نمی‌کند'];
    }
    return as_response([
        blk_notice('اگر همین مورد را در اپِ بدون اینترنت هم تسویه کرده‌اید، ابتدا اپ را همگام کنید و بعد ادامه دهید.', 'warning'),
        blk_confirm('تأیید تسویه', $lines, as_action($id, 'send', ['confirm_token' => $token]), 'تأیید و ثبت'),
    ], ['data_note' => null]);
}

if (function_exists('as_register')) {
    as_register('loan_add', 'as_h_loan_add', true);
    as_register('loan_repay', 'as_h_loan_repay', true);
    as_register('settle_due', 'as_h_settle_due', true);
}
