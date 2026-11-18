<?php
require_once __DIR__ . '/ddl_guard.php';
/**
 * S24.T24.2 — money unit gate (see docs/MONEY_CONTRACT.md).
 *
 * settings key `money_unit` (per customer, table app_settings):
 *   'toman' (default while the stored data is toman; until S29 cutover)
 *   'rial'  (after S29: all stored money is integer rial)
 *
 * Clients declare the unit they speak in the request field `unit`
 * ('toman' | 'rial'). When the tenant is already 'rial', a money WRITE from a
 * client that does not declare 'rial' is refused with HTTP 426
 * {error:'upgrade_required', need:'unit_rial'} so an old app can never write
 * toman into a rial database. Reads are never refused.
 *
 * Until S29 the tenant stays 'toman' and this gate is a no-op: additive, old clients keep working.
 */

const PB_MONEY_UNIT_KEY = 'money_unit';
const PB_MONEY_UNIT_DEFAULT = 'rial'; // after S29 cutover: new tenants without a row are rial

/**
 * Create the per-customer settings table (idempotent). Called from migration v45 and before every setting write.
 * IMPORTANT: "CREATE TABLE IF NOT EXISTS" is DDL and ends any open transaction (implicit commit) EVEN when the table exists,
 * which made the later commit() throw "There is no active transaction" (HTTP 500 on entity.save etc. via pb_audit -> audit.head).
 * So we check with SHOW TABLES (not DDL) and only create when it is really missing; the result is cached per request.
 */
function pb_app_settings_ensure(PDO $pdo): void {
    static $ok = false;
    if ($ok) return;
    $exists = false;
    try {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE 'app_settings'")->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }
    if (!$exists) {
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS app_settings (
            customer_id INT UNSIGNED NOT NULL,
            k VARCHAR(60) NOT NULL,
            v VARCHAR(255) NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (customer_id, k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $ok = true;
}

/** Read a per-customer setting; $default when missing or table absent (never throws). */
function pb_app_setting_get(PDO $pdo, int $cid, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare('SELECT v FROM app_settings WHERE customer_id=? AND k=?');
        $st->execute([$cid, $key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

/** Write a per-customer setting (upsert). */
function pb_app_setting_set(PDO $pdo, int $cid, string $key, string $value): void {
    pb_app_settings_ensure($pdo);
    $st = $pdo->prepare('INSERT INTO app_settings (customer_id, k, v, updated_at) VALUES (?,?,?,NOW())
        ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=NOW()');
    $st->execute([$cid, $key, $value]);
}

/** S29.T29.2: bind the voucher multiplier for this request: rial tenant stores rial already (x1), toman tenant x10. */
function pb_unit_mult_bind(PDO $pdo, int $cid): void {
    $GLOBALS['PB_UNIT_MULT'] = pb_money_unit($pdo, $cid) === 'rial' ? 1 : 10;
}

/** Current stored money unit of this tenant: 'toman' | 'rial'. */
function pb_money_unit(PDO $pdo, int $cid): string {
    $u = pb_app_setting_get($pdo, $cid, PB_MONEY_UNIT_KEY, PB_MONEY_UNIT_DEFAULT);
    return $u === 'rial' ? 'rial' : 'toman';
}

/**
 * Action names that change money. Verb-suffix rule so new actions are covered by default:
 * anything ending in a write verb under a module that stores amounts.
 */
function pb_money_write_action(string $action): bool {
    if ($action === '') return false;
    if (strpos($action, 'reports.') === 0 || strpos($action, 'accounts.list') === 0) return false; // reads
    if (strpos($action, 'fx.currencies.') === 0 || strpos($action, 'fx.rates.') === 0) return false; // ratios and master data, not amounts
    foreach (['staff.', 'roles.', 'form_settings.', 'audit.'] as $pfx) if (strpos($action, $pfx) === 0) return false; // S10: مدیریت کارکن/نقش/فرم/ممیزی مبلغی ذخیره نمی‌کنند (staff.save به‌خاطر پسوند .save اشتباه upgrade_required می‌داد)
    return (bool)preg_match('/\.(save|create|update|delete|transition|approve|apply|pay|receive|post|unpost|settle|rebuild|adjust|upgrade_apply|tier\.upgrade_apply)$/', $action)
        || in_array($action, ['payments.save', 'receipts.save', 'loans.create', 'sales.create', 'purchases.create', 'intake.preview_price', 'intake.confirm'], true); // S3: قیمت پذیرش
}

/**
 * Gate called from app.php right after pb_fy_guard_action().
 * $raw is the decoded request body; the client's declared unit is $raw['unit'].
 */
function pb_money_unit_guard(PDO $pdo, int $cid, string $action, array $raw): void {
    if (!pb_money_write_action($action)) return;
    $declared = (string)($raw['unit'] ?? '');
    if (pb_money_unit($pdo, $cid) !== 'rial') {
        // S29.T29.3: tenant still toman: only a client that explicitly declares 'rial' is refused (it would store rial as toman).
        if ($declared === 'rial') {
            out(['error' => 'unit_mismatch', 'need' => 'unit_toman',
                 'message' => 'واحد پول سرور هنوز تومان است؛ همگام‌سازی را دوباره انجام دهید'], 409);
        }
        return; // undeclared/toman: unchanged behaviour (old clients keep working)
    }
    if ($declared === 'rial') return;
    out(['error' => 'upgrade_required', 'need' => 'unit_rial',
         'message' => 'نسخه برنامه قدیمی است؛ برای ثبت مبلغ، برنامه را به‌روز کنید'], 426);
}
