<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S20.T20.2 — plan upgrade rial -> fx (ADDITIVE; no existing row is rewritten).
 * pb_plan_upgrade_fx($pdo, $cid) is idempotent and is called AFTER customers.plan was set to 'fx' by:
 *   - admin set_plan / set_edition (backend/admin/actions.php)
 *   - the payment engine after a paid fx order (pay_engine.php, S22)
 * It: (1) adds nullable usd columns on purchases/sales, (2) creates currencies + fx_rates and seeds defaults for the tenant,
 * (3) for gold tenants makes sure the fx_diff account/role exists so FX settlement posting works.
 * There is deliberately NO client action that flips the plan: the plan is a paid entitlement, only admin/payment may change it.
 * Edge cases (documented, handled):
 *   - rial documents keep usd_rate/price_usd = NULL: UI shows empty optional fields; reports treat NULL as "no fx value".
 *   - fx -> rial (admin) hides fx fields (stripFx) but keeps data; currencies/fx_rates tables stay; re-upgrade just re-runs this (no duplicates).
 *   - base currency is the stored unit (IRT/toman, DECISIONS Q1); gold official outputs convert x10 via display_unit, not here.
 *   - DDL is auto-committed by MySQL: call outside an open transaction (the payment engine calls it after commit).
 */
function pb_plan_fx_ensure_tables(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS currencies (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        code VARCHAR(8) NOT NULL,
        name VARCHAR(60) NOT NULL,
        symbol VARCHAR(8) NULL,
        decimals TINYINT NOT NULL DEFAULT 2,
        is_base TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        UNIQUE KEY uq_ccy (customer_id, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS fx_rates (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        ccy VARCHAR(8) NOT NULL,
        rate_date DATE NOT NULL,
        rate DECIMAL(18,6) NOT NULL,
        source VARCHAR(60) NULL,
        created_at DATETIME NULL,
        UNIQUE KEY uq_fxr (customer_id, ccy, rate_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_plan_fx_columns(PDO $pdo): array {
    $added = [];
    foreach ([['purchases', 'usd_rate', 'DECIMAL(14,2) NULL'], ['purchases', 'price_usd', 'DECIMAL(14,4) NULL'], ['sales', 'usd_rate', 'DECIMAL(14,2) NULL']] as [$t, $c, $def]) {
        $q = $pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        if (!$q || !$q->fetch()) { $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); $added[] = "$t.$c"; }
    }
    return $added;
}

/** @return array{ok:bool, columns_added:string[], currencies_added:int, fx_diff_role:bool, error?:string} */
function pb_plan_upgrade_fx(PDO $pdo, int $cid): array {
    $res = ['ok' => false, 'columns_added' => [], 'currencies_added' => 0, 'fx_diff_role' => false];
    try {
        $st = $pdo->prepare('SELECT plan, tier FROM customers WHERE id=?');
        $st->execute([$cid]);
        $c = $st->fetch();
        if (!$c) { $res['error'] = 'customer_not_found'; return $res; }
        if ((string)$c['plan'] !== 'fx') { $res['error'] = 'plan_not_fx'; return $res; } // caller must set the plan first
        $res['columns_added'] = pb_plan_fx_columns($pdo);
        pb_plan_fx_ensure_tables($pdo);
        $ins = $pdo->prepare('INSERT IGNORE INTO currencies (customer_id, code, name, symbol, decimals, is_base, active, created_at) VALUES (?,?,?,?,?,?,1,NOW())');
        // STAGE10: باید دقیقاً با PB_FX_DEFAULTS در fx_currencies.php یکی باشد (INSERT IGNORE: اولین نویسنده می‌ماند؛
        // نسخهٔ قبلی «تومان/ت» و ۴ ارز می‌ساخت و پایهٔ «ریال» fx_currencies را برای همیشه مسدود می‌کرد).
        // fx_currencies.php این فایل را require می‌کند، پس برای پرهیز از وابستگی حلقوی فهرست اینجا تکرار شده است.
        foreach ([['IRT', 'ریال (پایه)', 'ریال', 0, 1], ['USD', 'دلار آمریکا', '$', 2, 0], ['EUR', 'یورو', '€', 2, 0], ['AED', 'درهم امارات', 'د.إ', 2, 0],
                  ['GBP', 'پوند انگلیس', '£', 2, 0], ['IQD', 'دینار عراق', 'ع.د', 0, 0], ['TRY', 'لیر ترکیه', '₺', 2, 0], ['CNY', 'یوان چین', '¥', 2, 0]] as $r) {
            $ins->execute([$cid, $r[0], $r[1], $r[2], $r[3], $r[4]]);
            $res['currencies_added'] += $ins->rowCount();
        }
        if ((string)$c['tier'] === 'gold' && function_exists('pb_acc_backfill_roles')) { // gold+fx: fx_diff (5207) must exist for settlement differences
            pb_acc_ensure($pdo);
            pb_acc_backfill_roles($pdo, $cid);
            $res['fx_diff_role'] = true;
        }
        $res['ok'] = true;
    } catch (Throwable $e) {
        error_log('[plan_upgrade_fx] ' . $e->getMessage());
        $res['error'] = 'failed';
    }
    return $res;
}
