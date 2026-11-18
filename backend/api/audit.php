<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S02.T02.3 — audit log (گزارش ممیزی).
 * - pb_audit(): explicit helper (used by entities.php and any dedicated module).
 * - pb_audit_auto(): called from out() on every successful mutating action of app.php (central hook, one row per action).
 * - audit.list: filters (staff, action, entity, date range, text). Permission `audit.view` (owner always).
 * - S31.T31.5: IMMUTABLE. Rows are never updated/deleted (retention purge removed; best-effort DB triggers refuse UPDATE/DELETE) and form a per-tenant
 *   SHA-1 hash chain (prev_hash/row_hash); the chain head is mirrored in app_settings `audit.head`. audit.verify re-walks the chain and reports tampering.
 *   Update/delete actions in pb_audit_before_map() store the previous DB row as before_json.
 * - PB_AUDIT_RETENTION_DAYS is kept only for old clients (reported as 0 = keep forever).
 */
if (!defined('PB_AUDIT_RETENTION_DAYS')) define('PB_AUDIT_RETENTION_DAYS', 730);

/** action => [verb, entity, Persian summary]. entity.* is audited by entities.php itself (explicit pb_audit calls). */
function pb_audit_map(): array {
    return [
        'categories.create' => ['create', 'category', 'ثبت دسته‌بندی'],
        'types.create' => ['create', 'type', 'ثبت نوع'],
        'stock_count.approve' => ['update', 'stock_count', 'تأیید انبارگردانی'],
        'stock_count.submit' => ['update', 'stock_count', 'ارسال انبارگردانی برای تأیید'],
        'stock_count.reopen' => ['update', 'stock_count', 'بازگشایی انبارگردانی'],
        'fy.close' => ['update', 'fiscal_year', 'بستن سال مالی'],
        'fy.open_next' => ['update', 'fiscal_year', 'سند افتتاحیه سال بعد'], 'fy.open_undo' => ['update', 'fiscal_year', 'حذف سند افتتاحیه'],
        'purchases.create' => ['create', 'purchase', 'ثبت خرید'],
        'docs.correct' => ['update', 'doc_correction', 'سند اصلاحی (فی/وزن)'],
        'bank_recon.import' => ['create', 'bank_statement', 'ورود صورت‌حساب بانک'],
        'bank_recon.auto' => ['update', 'bank_recon', 'تطبیق خودکار بانک'],
        'bank_recon.match' => ['update', 'bank_recon', 'تطبیق دستی ردیف بانک'],
        'bank_recon.unmatch' => ['update', 'bank_recon', 'لغو تطبیق ردیف بانک'],
        'bank_recon.ignore' => ['update', 'bank_recon', 'نادیده‌گرفتن ردیف بانک'],
        'backup_drill.run' => ['update', 'backup_drill', 'تمرین بازیابی بک‌آپ'],
        'lc.save' => ['update', 'letters_of_credit', 'ثبت/ویرایش اعتبار اسنادی'],
        'lc.set_status' => ['update', 'letters_of_credit', 'تغییر وضعیت اعتبار اسنادی'],
        'lc.delete' => ['delete', 'letters_of_credit', 'حذف اعتبار اسنادی'],
        'sale_export.save' => ['update', 'sale_export', 'ویرایش فیلدهای صادراتی فاکتور'],
        'pay_approval.approve' => ['update', 'pay_approval', 'تأیید پرداخت بالای سقف'],
        'pay_approval.reject' => ['update', 'pay_approval', 'رد پرداخت بالای سقف'],
        'pay_approval.set_limit' => ['update', 'pay_approval_limit', 'تغییر سقف تأیید پرداخت'],
        'period_lock.close' => ['update', 'period_lock', 'بستن دورهٔ ماهانه'],
        'period_lock.reopen' => ['update', 'period_lock', 'بازگشایی دورهٔ ماهانه'],
        'approval.approve' => ['update', 'doc_approval', 'تأیید سند بالای سقف'],
        'approval.set_limit' => ['update', 'approval_limit', 'تغییر سقف تأیید'],
        'purchases.delete' => ['delete', 'purchase', 'حذف خرید'],
        'purchase_returns.create' => ['create', 'purchase_return', 'ثبت برگشت از خرید'],
        'purchase_returns.delete' => ['delete', 'purchase_return', 'حذف برگشت از خرید'],
        'sale_returns.create' => ['create', 'sale_return', 'ثبت برگشت از فروش'],
        'sale_returns.delete' => ['delete', 'sale_return', 'حذف برگشت از فروش'],
        'proformas.save' => ['update', 'proforma', 'ثبت/ویرایش پیش‌فاکتور'],
        'proformas.set_status' => ['update', 'proforma', 'تغییر وضعیت پیش‌فاکتور'],
        'proformas.delete' => ['delete', 'proforma', 'حذف پیش‌فاکتور'],
        'proformas.convert' => ['create', 'proforma', 'تبدیل پیش‌فاکتور به فروش'],
        'proformas.link_sales' => ['create', 'proforma', 'اتصال فروش‌های آفلاین به پیش‌فاکتور'],
        'broker.attach' => ['update', 'broker', 'ثبت/ویرایش دلال سند'],
        'broker_rules.save' => ['update', 'broker_rule', 'ثبت قانون کمیسیون'],
        'broker_rules.delete' => ['delete', 'broker_rule', 'حذف قانون کمیسیون'],
        'broker_payments.save' => ['create', 'broker_payment', 'ثبت پرداخت کمیسیون'],
        'broker_payments.delete' => ['delete', 'broker_payment', 'حذف پرداخت کمیسیون'],
        'cheques.transition' => ['update', 'cheque', 'تغییر وضعیت چک'],
        'mixes.create' => ['create', 'mix', 'ثبت مخلوط'],
        'separations.create' => ['create', 'separation', 'ثبت جداسازی'],
        'separations.update' => ['update', 'separation', 'ویرایش جداسازی'],
        'separations.delete' => ['delete', 'separation', 'حذف جداسازی'],
        'separations.settings_save' => ['update', 'separation_settings', 'تنظیمات جداسازی'],
        'mixes.update' => ['update', 'mix', 'ویرایش مخلوط'],
        'mixes.delete' => ['delete', 'mix', 'حذف مخلوط'],
        'payments.save' => ['update', 'payment', 'ثبت/ویرایش پرداخت'],
        'receipts.save' => ['update', 'receipt', 'ثبت/ویرایش دریافت'],
        'sales.create' => ['create', 'sale', 'ثبت فروش'],
        'sales.update' => ['update', 'sale', 'ویرایش فروش'],
        'sales.delete' => ['delete', 'sale', 'حذف فروش'],
        'dues.mark_paid' => ['update', 'due', 'تسویهٔ سررسید'],
        'parties.create' => ['create', 'party', 'ثبت طرف حساب'],
        'parties.save' => ['update', 'party', 'ذخیرهٔ مشخصات طرف حساب'],
        'loans.create' => ['create', 'loan', 'ثبت قرض/وام'],
        'loans.delete' => ['delete', 'loan', 'حذف قرض/وام'],
        'loans.due_paid' => ['update', 'loan', 'تسویهٔ قسط قرض'],
        'stock.transfer' => ['create', 'stock_transfer', 'انتقال بین انبارها'],
        'stock.transfer_cancel' => ['update', 'stock_transfer', 'لغو انتقال انبار'],
        'staff.save' => ['update', 'staff', 'ذخیرهٔ کاربر'],
        'staff.delete' => ['delete', 'staff', 'حذف کاربر'],
        'roles.save' => ['update', 'role', 'ذخیرهٔ نقش'],
        'account.set_name' => ['update', 'account', 'تغییر نام حساب'],
        'account.set_password' => ['update', 'account', 'تغییر رمز'],
        'account.reset_password' => ['update', 'account', 'بازنشانی رمز'],
        'backup.import' => ['update', 'backup', 'بازیابی پشتیبان'],
    ];
}

function pb_audit_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    // "CREATE TABLE IF NOT EXISTS" is DDL = implicit commit even when the table exists; pb_audit() runs INSIDE open transactions
    // (entity.save ...), so the later commit() threw "There is no active transaction" (HTTP 500). SHOW TABLES is not DDL.
    $exists = false;
    try { $exists = (bool)$pdo->query("SHOW TABLES LIKE 'audit_log'")->fetchColumn(); } catch (Throwable $e) { $exists = false; }
    if (!$exists) {
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            staff_id INT UNSIGNED NOT NULL DEFAULT 0,
            staff_name VARCHAR(120) NOT NULL DEFAULT '',
            action VARCHAR(20) NOT NULL,
            entity VARCHAR(60) NOT NULL,
            entity_id VARCHAR(64) NOT NULL DEFAULT '',
            summary VARCHAR(255) NOT NULL DEFAULT '',
            before_json TEXT NULL,
            after_json TEXT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_cid (customer_id, id),
            KEY idx_audit_ent (customer_id, entity, entity_id),
            KEY idx_audit_staff (customer_id, staff_id),
            KEY idx_audit_ts (customer_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $done = true;
    try { // S31.T31.5: hash-chain columns (idempotent; also run by migration v55)
        $have = [];
        foreach ($pdo->query('SHOW COLUMNS FROM audit_log')->fetchAll() as $c) $have[$c['Field']] = 1;
        if (!isset($have['prev_hash'])) $pdo->exec('ALTER TABLE audit_log ADD COLUMN prev_hash CHAR(40) NULL');
        if (!isset($have['row_hash'])) $pdo->exec('ALTER TABLE audit_log ADD COLUMN row_hash CHAR(40) NULL');
    } catch (Throwable $e) { error_log('[audit] columns: ' . $e->getMessage()); }
}

/** Best-effort append-only triggers (needs the TRIGGER privilege; silently skipped when the host refuses). Returns true when both exist. */
function pb_audit_install_triggers(PDO $pdo): bool {
    try {
        pb_audit_ensure($pdo);
        $have = [];
        foreach ($pdo->query("SHOW TRIGGERS WHERE `Table`='audit_log'")->fetchAll() as $t) $have[$t['Trigger']] = 1;
        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $k => $ev) {
            if (isset($have["audit_log_no_$k"])) continue;
            $pdo->exec("CREATE TRIGGER audit_log_no_$k BEFORE $ev ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit_log is append-only'");
        }
        return true;
    } catch (Throwable $e) {
        error_log('[audit] triggers: ' . $e->getMessage());
        return false;
    }
}

function pb_audit_hash(string $prev, int $cid, int $staffId, string $action, string $entity, string $entityId, string $summary, ?string $before, ?string $after, string $at): string {
    return sha1(implode("\x1f", [$prev, $cid, $staffId, $action, $entity, $entityId, $summary, (string)$before, (string)$after, $at]));
}

/** action => [table, payload id key] : the row as it is BEFORE the action runs becomes before_json. */
function pb_audit_before_map(): array {
    return ['sales.update' => ['sales', 'id'], 'sales.delete' => ['sales', 'id'], 'purchases.delete' => ['purchases', 'id'],
        'mixes.update' => ['purchases', 'id'], 'mixes.delete' => ['purchases', 'id'],
        'separations.update' => ['separations', 'id'], 'separations.delete' => ['separations', 'id']];
}

/** Called by app.php right before the action switch. Never throws. */
function pb_audit_before_capture(PDO $pdo, int $cid, string $action, array $payload): void {
    $m = pb_audit_before_map();
    if (!isset($m[$action])) return;
    try {
        [$tbl, $key] = $m[$action];
        $id = (int)($payload[$key] ?? 0);
        if ($id <= 0) return;
        $st = $pdo->prepare("SELECT * FROM `$tbl` WHERE id=? AND customer_id=?");
        $st->execute([$id, $cid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $GLOBALS['pb_audit_before_row'] = $r;
    } catch (Throwable $e) { /* optional */ }
}

/** Removes secrets and big blobs, then JSON-encodes to ≤ $max chars (NULL when empty). */
function pb_audit_short($v, int $max = 1500): ?string {
    if ($v === null || $v === [] || $v === '') return null;
    if (is_array($v)) {
        $clean = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && preg_match('/pass|otp|token|secret|pin|image|photo|base64|file|backup/i', $k)) continue;
            if (is_array($x) && count($x) > 20) $x = array_slice($x, 0, 20);
            if (is_string($x) && mb_strlen($x) > 200) $x = mb_substr($x, 0, 200) . '…';
            $clean[$k] = $x;
        }
        $v = $clean;
    }
    $j = json_encode($v, JSON_UNESCAPED_UNICODE);
    if ($j === false) return null;
    return mb_strlen($j) > $max ? mb_substr($j, 0, $max - 1) . '…' : $j;
}

/** Writes one audit row. Never throws and never aborts the business action. */
function pb_audit(PDO $pdo, array $customer, string $action, string $entity, string $entityId = '', string $summary = '', $before = null, $after = null): void {
    try {
        pb_audit_ensure($pdo);
        $staffId = (int)($customer['_staff_id'] ?? 0);
        $cid = (int)$customer['id'];
        $staffName = $staffId > 0 ? mb_substr((string)($customer['_staff_name'] ?? ''), 0, 120) : '';
        $action = mb_substr($action, 0, 20); $entity = mb_substr($entity, 0, 60); $entityId = mb_substr($entityId, 0, 64); $summary = mb_substr($summary, 0, 255);
        $b = pb_audit_short($before); $a = pb_audit_short($after);
        $at = date('Y-m-d H:i:s');
        $lock = 'pb_audit_' . $cid;
        $got = (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 3)')->fetchColumn() === 1; // serialize the chain per tenant
        try {
            $ph = $pdo->prepare('SELECT row_hash FROM audit_log WHERE customer_id=? AND row_hash IS NOT NULL ORDER BY id DESC LIMIT 1');
            $ph->execute([$cid]);
            $prev = (string)($ph->fetchColumn() ?: str_repeat('0', 40));
            $hash = pb_audit_hash($prev, $cid, $staffId, $action, $entity, $entityId, $summary, $b, $a, $at);
            $pdo->prepare('INSERT INTO audit_log (customer_id, staff_id, staff_name, action, entity, entity_id, summary, before_json, after_json, ip, created_at, prev_hash, row_hash)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid, $staffId, $staffName, $action, $entity, $entityId, $summary, $b, $a,
                mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $at, $prev, $hash]);
            if (function_exists('pb_app_setting_set')) pb_app_setting_set($pdo, $cid, 'audit.head', $hash);
        } finally {
            if ($got) $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
        }
    } catch (Throwable $e) {
        error_log('[audit] ' . $e->getMessage());
    }
}

/** Central hook: called by out() for every successful response. Only mapped mutating actions produce a row. */
function pb_audit_auto(PDO $pdo, array $customer, string $action, array $payload, $data): void {
    static $logged = false;
    if ($logged) return;
    $map = pb_audit_map();
    if (!isset($map[$action])) return;
    $logged = true;
    [$verb, $entity, $summary] = $map[$action];
    $id = '';
    foreach (['uid', 'id', 'purchase_id', 'sale_id', 'loan_id', 'mix_id', 'staff_id', 'role_id'] as $k) {
        if (isset($payload[$k]) && is_scalar($payload[$k]) && (string)$payload[$k] !== '') { $id = (string)$payload[$k]; break; }
    }
    if ($id === '' && is_array($data)) {
        foreach (['uid', 'id', 'purchase_id', 'sale_id'] as $k) {
            if (isset($data[$k]) && is_scalar($data[$k]) && (string)$data[$k] !== '') { $id = (string)$data[$k]; break; }
        }
    }
    $after = $verb === 'delete' ? null : $payload;
    $before = $verb === 'delete' ? $payload : null;
    if (!empty($GLOBALS['pb_audit_before_row']) && is_array($GLOBALS['pb_audit_before_row'])) { // S31.T31.5: real previous row (payload kept as the new value / delete reason)
        $before = $GLOBALS['pb_audit_before_row'];
        if ($verb === 'delete' && isset($payload['reason'])) $before['_delete_reason'] = $payload['reason'];
        $GLOBALS['pb_audit_before_row'] = null;
    }
    if (strpos($action, 'account.') === 0 || $action === 'backup.import') { $after = null; $before = null; }
    pb_audit($pdo, $customer, $verb, $entity, $id, $summary, $before, $after);
}

function pb_audit_action(string $action, array $payload, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    pb_audit_ensure($pdo);
    $where = ['a.customer_id = ?']; $args = [$cid];
    if (isset($payload['staff_id']) && $payload['staff_id'] !== '' && $payload['staff_id'] !== null) { $where[] = 'a.staff_id = ?'; $args[] = (int)$payload['staff_id']; }
    foreach (['entity', 'action'] as $f) {
        $v = trim((string)($payload[$f] ?? ''));
        if ($v !== '') { $where[] = "a.$f = ?"; $args[] = $v; }
    }
    $from = (string)($payload['from'] ?? ''); $to = (string)($payload['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'a.created_at >= ?'; $args[] = $from . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'a.created_at <= ?'; $args[] = $to . ' 23:59:59'; }
    $q = trim((string)($payload['q'] ?? ''));
    if ($q !== '') { $where[] = '(a.summary LIKE ? OR a.entity_id LIKE ? OR a.staff_name LIKE ?)'; $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; array_push($args, $like, $like, $like); }
    $before = (int)($payload['before_id'] ?? 0);
    if ($before > 0) { $where[] = 'a.id < ?'; $args[] = $before; }
    $limit = max(1, min(200, (int)($payload['limit'] ?? 50)));
    $st = $pdo->prepare('SELECT a.id, a.staff_id, a.staff_name, a.action, a.entity, a.entity_id, a.summary, a.before_json, a.after_json, a.ip, a.created_at
        FROM audit_log a WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT ' . ($limit + 1));
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    if ($more) array_pop($rows);
    $items = [];
    foreach ($rows as $r) {
        $items[] = ['id' => (int)$r['id'], 'staff_id' => (int)$r['staff_id'], 'staff_name' => (string)$r['staff_name'],
            'action' => (string)$r['action'], 'entity' => (string)$r['entity'], 'entity_id' => (string)$r['entity_id'],
            'summary' => (string)$r['summary'], 'before' => $r['before_json'], 'after' => $r['after_json'],
            'ip' => (string)$r['ip'], 'created_at' => (string)$r['created_at']];
    }
    out(['items' => $items, 'has_more' => $more, 'retention_days' => 0]);
}

/** audit.verify: re-walk the hash chain. Legacy rows (no row_hash) are counted, not verified. */
function pb_audit_verify(PDO $pdo, array $customer): array {
    $cid = (int)$customer['id'];
    pb_audit_ensure($pdo);
    $st = $pdo->prepare('SELECT id, staff_id, action, entity, entity_id, summary, before_json, after_json, created_at, prev_hash, row_hash FROM audit_log WHERE customer_id=? ORDER BY id');
    $st->execute([$cid]);
    $prev = str_repeat('0', 40); $n = 0; $legacy = 0; $broken = null; $why = ''; $last = null;
    while ($r = $st->fetch()) {
        if ($r['row_hash'] === null) { $legacy++; continue; }
        $n++;
        if ((string)$r['prev_hash'] !== $prev) { $broken = (int)$r['id']; $why = 'زنجیره قطع شده (ردیفی حذف یا جابه‌جا شده)'; break; }
        $h = pb_audit_hash($prev, $cid, (int)$r['staff_id'], (string)$r['action'], (string)$r['entity'], (string)$r['entity_id'], (string)$r['summary'], $r['before_json'], $r['after_json'], (string)$r['created_at']);
        if ($h !== (string)$r['row_hash']) { $broken = (int)$r['id']; $why = 'محتوای ردیف دستکاری شده'; break; }
        $prev = $h; $last = (int)$r['id'];
    }
    $head = pb_app_setting_get($pdo, $cid, 'audit.head', '');
    if ($broken === null && $n > 0 && $head !== '' && $head !== $prev) { $broken = $last; $why = 'ردیف‌های انتهای زنجیره حذف شده‌اند'; }
    $trig = 0;
    try { $trig = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='audit_log'")->fetchColumn(); } catch (Throwable $e) { /* optional */ }
    return ['ok' => $broken === null, 'sealed_rows' => $n, 'legacy_rows' => $legacy, 'broken_at_id' => $broken, 'reason' => $why, 'db_triggers' => $trig >= 2];
}

/** S35.T35.9a: optional-but-validated delete reason. Absent = legacy client / queued old op (accepted); present must be 5-300 chars. */
function pb_delete_reason_check(array $payload): void {
    if (!isset($payload['reason'])) return;
    $r = trim((string)$payload['reason']);
    $n = function_exists('mb_strlen') ? mb_strlen($r, 'UTF-8') : strlen($r);
    if ($n < 5 || $n > 300) bad('دلیل حذف باید ۵ تا ۳۰۰ نویسه باشد');
}
