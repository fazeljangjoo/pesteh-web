<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * Ordered, idempotent schema migrations (Z-Limit S00.T00.1).
 *
 * - Version lives in table `schema_info` (k='version'). Missing table/row = baseline 11
 *   (everything up to v11 is handled by the legacy ensure*() functions and the old SQL files).
 * - pb_migrate() is cheap on the hot path: one SELECT, then returns.
 * - Every step MUST be safe to run twice (check SHOW COLUMNS / IF NOT EXISTS first).
 * - A failing step is logged and the request continues (old behavior keeps working);
 *   the version is NOT advanced, so the step is retried on the next request.
 * - Mirror every step in backend/sql/migration_vNN_<name>.sql and in schema.sql.
 * To add a migration: add a function pb_mig_NN_<name>(PDO $pdo): void and register it in pb_migrations().
 */

require_once __DIR__ . '/entities.php'; // registry -> tables are synced here (hash-based)

const PB_SCHEMA_BASELINE = 11;

/** version => function name. Keep ascending; NEVER renumber a released step. */
function pb_migrations(): array {
    return [
        12 => 'pb_mig_12_customers_tier',
        13 => 'pb_mig_13_lot_warehouse_ounce',
        14 => 'pb_mig_14_stock_moves',
        15 => 'pb_mig_15_stock_transfers',
        16 => 'pb_mig_16_staff',
        17 => 'pb_mig_17_audit_log',
        18 => 'pb_mig_18_parties',
        19 => 'pb_mig_19_party_crm',
        20 => 'pb_mig_20_purchase_returns',
        21 => 'pb_mig_21_sale_returns',
        22 => 'pb_mig_22_proformas',
        23 => 'pb_mig_23_brokers',
        24 => 'pb_mig_24_cheques',
        25 => 'pb_mig_25_separations',
        26 => 'pb_mig_26_consignments',
        27 => 'pb_mig_27_consignment_out',
        28 => 'pb_mig_28_processing',
        29 => 'pb_mig_29_orchards',
        30 => 'pb_mig_30_payroll',
        31 => 'pb_mig_31_payroll_pay',
        32 => 'pb_mig_32_payroll_stat',
        33 => 'pb_mig_33_import_batches',
        34 => 'pb_mig_34_fiscal_years',
        35 => 'pb_mig_35_accounts',
        36 => 'pb_mig_36_treasury',
        37 => 'pb_mig_37_vouchers',
        38 => 'pb_mig_38_cheque_event_bank',
        39 => 'pb_mig_39_doc_tax',
        40 => 'pb_mig_40_sale_tax_choice',
        41 => 'pb_mig_41_upgrade_runs',
        42 => 'pb_mig_42_fx_tables',
        43 => 'pb_mig_43_pay_tiers',
        44 => 'pb_mig_44_pay_order_type',
        45 => 'pb_mig_45_app_settings',
        46 => 'pb_mig_46_fx_defaults',
        47 => 'pb_mig_47_fx_documents',
        48 => 'pb_mig_48_fx_rate_source',
        49 => 'pb_mig_49_fx_tx',
        50 => 'pb_mig_50_form_columns',
        51 => 'pb_mig_51_fin_block',
        52 => 'pb_mig_52_sayad_id',
        53 => 'pb_mig_53_bank_recon',
        54 => 'pb_mig_54_pay_approvals',
        55 => 'pb_mig_55_audit_seal',
        56 => 'pb_mig_56_sale_export',
        57 => 'pb_mig_57_sale_export_docs',
        58 => 'pb_mig_58_letters_of_credit',
        59 => 'pb_mig_59_backup_drills',
        60 => 'pb_mig_60_error_events',
        61 => 'pb_mig_61_customer_2fa',
        62 => 'pb_mig_62_ounce_room',
        63 => 'pb_mig_63_sync_version',
        64 => 'pb_mig_64_workshop_internal',
        65 => 'pb_mig_65_workshop_cost',
        66 => 'pb_mig_66_workshop_crack',
        67 => 'pb_mig_67_workshop_outside',
        68 => 'pb_mig_68_feed_stock',
        69 => 'pb_mig_69_crack_lots',
        70 => 'pb_mig_70_crack_pool',
        71 => 'pb_mig_71_credit_used',
        72 => 'pb_mig_72_workshop_outputs_price',
        73 => 'pb_mig_73_fx_reval_pro',
        74 => 'pb_mig_74_fx_reval_perms',
        75 => 'pb_mig_75_cartex_role',
    ];
}

function pb_schema_target(): int {
    $k = array_keys(pb_migrations());
    return $k ? max($k) : PB_SCHEMA_BASELINE;
}

/** All schema_info rows as k=>v, or null when the table does not exist yet. */
function pb_schema_state(PDO $pdo): ?array {
    try {
        $st = $pdo->query("SELECT k, v FROM schema_info WHERE k IN ('version','entities_hash')");
        $o = [];
        if ($st) foreach ($st->fetchAll() as $r) $o[(string)$r['k']] = (string)$r['v'];
        return $o;
    } catch (Throwable $e) {
        return null; // table missing
    }
}

function pb_schema_set(PDO $pdo, string $k, string $v): void {
    $pdo->prepare("INSERT INTO schema_info (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, $v]);
}

/** Run pending migrations + entity-table sync once per request. Never throws. */
function pb_migrate(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $locked = false;
    try {
        $target = pb_schema_target();
        $wantHash = function_exists('pb_entities_hash') ? pb_entities_hash() : '';
        $state = pb_schema_state($pdo);
        $cur = ($state !== null && isset($state['version'])) ? (int)$state['version'] : PB_SCHEMA_BASELINE;
        $hash = ($state !== null && isset($state['entities_hash'])) ? $state['entities_hash'] : '';
        if ($state !== null && $cur >= $target && $hash === $wantHash) return; // hot path: nothing to do
        if ($state === null) {
            pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS schema_info (
                k VARCHAR(40) NOT NULL PRIMARY KEY,
                v VARCHAR(100) NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        // one runner at a time (concurrent first requests after a deploy)
        $lk = $pdo->query("SELECT GET_LOCK('pb_migrate', 10)");
        $locked = $lk && (int)$lk->fetchColumn() === 1;
        if (!$locked) return; // another request is migrating; this one proceeds with old behavior
        $state = pb_schema_state($pdo) ?? [];
        $cur = isset($state['version']) ? (int)$state['version'] : PB_SCHEMA_BASELINE;
        $hash = $state['entities_hash'] ?? '';
        $steps = pb_migrations();
        ksort($steps);
        foreach ($steps as $ver => $fn) {
            if ($ver <= $cur) continue;
            $fn($pdo); // may throw -> caught below, version not advanced
            pb_schema_set($pdo, 'version', (string)$ver);
            $cur = (int)$ver;
        }
        if ($wantHash !== '' && $hash !== $wantHash && function_exists('pb_ensure_entity_tables')) {
            pb_ensure_entity_tables($pdo);
            pb_schema_set($pdo, 'entities_hash', $wantHash);
        }
    } catch (Throwable $e) {
        error_log('[migrations.php] ' . $e->getMessage());
    } finally {
        if ($locked) {
            try { $pdo->query("SELECT RELEASE_LOCK('pb_migrate')"); } catch (Throwable $e) {}
        }
    }
}

function pb_col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
}

/* ---------------- steps ---------------- */

/** v12: customers.tier ('green' default for every existing customer). */
function pb_mig_12_customers_tier(PDO $pdo): void {
    if (!pb_col_exists($pdo, 'customers', 'tier')) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN tier ENUM('green','gold') NOT NULL DEFAULT 'green'");
    }
}

/** v13: lots point to warehouses/ounce grades by uid (NULL warehouse_uid == default warehouse). */
function pb_mig_13_lot_warehouse_ounce(PDO $pdo): void {
    if (!pb_col_exists($pdo, 'purchases', 'warehouse_uid')) {
        $pdo->exec('ALTER TABLE purchases ADD COLUMN warehouse_uid CHAR(36) NULL, ADD COLUMN ounce_uid CHAR(36) NULL, ADD KEY idx_pur_wh (customer_id, warehouse_uid)');
    }
}

/** v14: stock moves ledger (S01.T01.2). Backfill is lazy per customer (pb_stock_ensure) to keep the migration fast. */
function pb_mig_14_stock_moves(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS stock_moves (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        warehouse_uid CHAR(36) NULL,
        ounce_uid CHAR(36) NULL,
        kind VARCHAR(20) NOT NULL,
        doc_type VARCHAR(20) NOT NULL,
        doc_id BIGINT UNSIGNED NOT NULL,
        qty_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(18,4) NULL,
        move_date DATE NOT NULL,
        ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reversal_of BIGINT UNSIGNED NULL,
        reversed TINYINT(1) NOT NULL DEFAULT 0,
        KEY idx_sm_lot (customer_id, lot_id),
        KEY idx_sm_doc (customer_id, doc_type, doc_id),
        KEY idx_sm_wh (customer_id, warehouse_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS stock_state (
        customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
        built_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** v15 (S01.T01.3): warehouse transfers + the warehouse a sale item / mix source was taken from (NULL = lot's home warehouse). */
function pb_mig_15_stock_transfers(PDO $pdo): void {
    if (!pb_col_exists($pdo, 'sale_items', 'warehouse_uid')) {
        $pdo->exec('ALTER TABLE sale_items ADD COLUMN warehouse_uid CHAR(36) NULL');
    }
    if (!pb_col_exists($pdo, 'mix_sources', 'warehouse_uid')) {
        $pdo->exec('ALTER TABLE mix_sources ADD COLUMN warehouse_uid CHAR(36) NULL');
    }
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS stock_transfers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        from_warehouse_uid CHAR(36) NOT NULL,
        to_warehouse_uid CHAR(36) NOT NULL,
        qty_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        move_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        cancelled TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_st_uid (customer_id, uid),
        KEY idx_st_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** v16 (S02.T02.1): کارکنان، نقش‌ها و staff_id روی توکن‌ها (کارکن با توکن خودش وارد می‌شود). */
function pb_mig_16_staff(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS roles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        perms TEXT NOT NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_roles_cid (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS staff_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        username VARCHAR(60) NOT NULL,
        name VARCHAR(120) NOT NULL,
        pass_hash VARCHAR(255) NOT NULL,
        role_id INT UNSIGNED NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        allowed_fy_ids TEXT NULL,
        last_login DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_staff_user (customer_id, username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!pb_col_exists($pdo, 'customer_tokens', 'staff_id')) {
        $pdo->exec('ALTER TABLE customer_tokens ADD COLUMN staff_id INT UNSIGNED NULL, ADD KEY idx_tok_staff (staff_id)');
    }
}

/** v17 (S02.T02.3): audit log table (also created lazily by pb_audit_ensure()). */
function pb_mig_17_audit_log(PDO $pdo): void {
    require_once __DIR__ . '/audit.php';
    pb_audit_ensure($pdo);
}

/** v18 (S03.T03.1): party master columns + backfill of master rows from purchases/sales/loans names. */
function pb_mig_18_parties(PDO $pdo): void {
    require_once __DIR__ . '/parties.php';
    pb_party_ensure_cols($pdo);
    $cids = $pdo->query('SELECT id FROM customers')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cids as $c) pb_party_sync($pdo, (int)$c);
}

/** v19 (S03.T03.3): parties.tags / parties.stage (CRM). crm_notes/crm_tasks are registry entities (created by hash sync). */
/** v20 (S04.T04.1): purchase_returns + purchase_return_items. */
function pb_mig_20_purchase_returns(PDO $pdo): void {
    require_once __DIR__ . '/returns.php';
    pb_preturn_ensure($pdo);
}

/** v21 (S04.T04.2): sale_returns + sale_return_items. */
function pb_mig_21_sale_returns(PDO $pdo): void {
    require_once __DIR__ . '/sale_returns.php';
    pb_sreturn_ensure($pdo);
}

/** v22 (S04.T04.3): proformas + proforma_items + sales.proforma_id. */
function pb_mig_22_proformas(PDO $pdo): void {
    require_once __DIR__ . '/proformas.php';
    pb_proforma_ensure($pdo);
}

/** v23 (S05.T05.1): broker_rules + broker_payments + broker_* columns on purchases/sales. */
function pb_mig_23_brokers(PDO $pdo): void {
    require_once __DIR__ . '/brokers.php';
    pb_broker_ensure($pdo);
}

/** v24 (S06.T06.1): cheques + cheque_events (register is backfilled lazily by pb_cheque_sync). */
function pb_mig_24_cheques(PDO $pdo): void {
    require_once __DIR__ . '/cheques.php';
    pb_cheque_ensure($pdo);
}

/** v25 (S07.T07.1): multi-output separations + lot lineage. */
function pb_mig_25_separations(PDO $pdo): void {
    require_once __DIR__ . '/separation.php';
    pb_sep_ensure($pdo);
}

/** v26 (S08.T08.1): consignments (+items, owner returns, payments). Consigned-in lots are purchases rows with is_mix=3. */
function pb_mig_26_consignments(PDO $pdo): void {
    require_once __DIR__ . '/consignment.php';
    pb_cons_ensure($pdo);
}

/** v27 (S08.T08.2): consignment OUT — consignment_sales (sold reports) + consignment_items.warehouse_uid (source warehouse). */
function pb_mig_27_consignment_out(PDO $pdo): void {
    require_once __DIR__ . '/consignment.php';
    pb_cons_ensure($pdo);
    pb_consout_ensure($pdo);
}

/** v28 (S09.T09.1): processing orders (فراوری paid / received) — proc_orders, proc_inputs, proc_outputs. */
function pb_mig_28_processing(PDO $pdo): void {
    require_once __DIR__ . '/processing.php';
    pb_proc_ensure($pdo);
}

/** v32 (S11.T11.3): statutory parameters per FY + per-line statutory detail. */
function pb_mig_32_payroll_stat(PDO $pdo): void {
    require_once __DIR__ . '/payroll.php';
    pb_pay_params_ensure($pdo);
}

/** v33 (S13.T13.2b): import batches (idempotency + result store for bulk import). */
function pb_mig_33_import_batches(PDO $pdo): void {
    require_once __DIR__ . '/import.php';
    pb_import_ensure($pdo);
}

/** v34 (S15.T15.1): fiscal years + per-year VAT settings (data only). */
function pb_mig_34_fiscal_years(PDO $pdo): void {
    require_once __DIR__ . '/fiscal.php';
    pb_fy_ensure($pdo);
}

/** v35 (S15.T15.2): chart of accounts, role map, tafsili. */
function pb_mig_35_accounts(PDO $pdo): void {
    require_once __DIR__ . '/fiscal.php';
    require_once __DIR__ . '/accounts.php';
    pb_acc_ensure($pdo);
}

/** v36 (S15.T15.3b): treasury_tafsili_id on legacy payment/receipt rows, cost_center_uid on expense docs; maps legacy text for gold customers. */
function pb_mig_36_treasury(PDO $pdo): void {
    require_once __DIR__ . '/fiscal.php';
    require_once __DIR__ . '/accounts.php';
    require_once __DIR__ . '/treasury.php';
    pb_ensure_entity_tables($pdo);
    pb_treasury_ensure_cols($pdo);
    foreach ($pdo->query("SELECT id FROM customers WHERE tier='gold'")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        pb_treasury_map_legacy($pdo, (int)$cid);
    }
}

/** v37 (S16.T16.1): vouchers + voucher_lines (gold; tables are created for everyone, only gold writes). */
function pb_mig_37_vouchers(PDO $pdo): void {
    require_once __DIR__ . '/vouchers.php';
    pb_voucher_ensure($pdo);
}

/** v31 (S11.T11.2): payroll payment schedule rows (multi-currency, multi-due). */
function pb_mig_31_payroll_pay(PDO $pdo): void {
    require_once __DIR__ . '/payroll.php';
    pb_pay_lines_ensure($pdo);
}

/** v30 (S11.T11.1): employees, pay items, payroll runs/lines, advances. */
function pb_mig_30_payroll(PDO $pdo): void {
    require_once __DIR__ . '/payroll.php';
    pb_pay_ensure($pdo);
}

/** v29 (S10.T10.1): orchards, season costs, harvests (→ stock lots), per-season allocations. */
function pb_mig_29_orchards(PDO $pdo): void {
    require_once __DIR__ . '/orchards.php';
    pb_orch_ensure($pdo);
}

function pb_mig_19_party_crm(PDO $pdo): void {
    require_once __DIR__ . '/parties.php';
    pb_party_ensure_cols($pdo);
}

/** v38 (S16.T16.4): bank account + bank charge captured on cheque events (feeds the cheque_ev posting rule). */
function pb_mig_38_cheque_event_bank(PDO $pdo): void {
    require_once __DIR__ . '/cheques.php';
    pb_cheque_ensure($pdo);
    foreach (['bank_ref' => 'VARCHAR(64) NULL', 'bank_charge' => 'BIGINT NULL'] as $c => $def) {
        $q = $pdo->query("SHOW COLUMNS FROM cheque_events LIKE '$c'");
        if (!$q || !$q->fetch()) $pdo->exec("ALTER TABLE cheque_events ADD COLUMN $c $def");
    }
}

/**
 * v39 (S17.T17.1): GOLD document tax columns, all nullable (green never writes them; old clients ignore them).
 * sale_items / purchases: tax_status taxable|exempt|zero, tax_rate_milli (9% = 9000), tax_amount, discount, surcharge (integer, same unit as other stored amounts; posting converts with pb_pl_rial).
 * sales / purchases: total_before_tax, total_tax, total_after_tax (same unit). fiscal_years: tax_rounding line|doc, tax_round_to (1|10|100|1000).
 */
function pb_mig_39_doc_tax(PDO $pdo): void {
    $add = function (string $table, array $cols) use ($pdo): void {
        $t = $pdo->query("SHOW TABLES LIKE '$table'");
        if (!$t || !$t->fetch()) return; // table created later by its own ensure*: columns come from schema_app / next ensure
        foreach ($cols as $c => $def) {
            $q = $pdo->query("SHOW COLUMNS FROM $table LIKE '$c'");
            if (!$q || !$q->fetch()) $pdo->exec("ALTER TABLE $table ADD COLUMN $c $def");
        }
    };
    $line = ['tax_status' => 'VARCHAR(10) NULL', 'tax_rate_milli' => 'INT NULL', 'tax_amount' => 'BIGINT NULL', 'discount' => 'BIGINT NULL', 'surcharge' => 'BIGINT NULL'];
    $tot = ['total_before_tax' => 'BIGINT NULL', 'total_tax' => 'BIGINT NULL', 'total_after_tax' => 'BIGINT NULL'];
    $add('sale_items', $line);
    $add('sales', array_merge(['discount' => 'BIGINT NULL'], $tot)); // each column is added only when missing (discount may already exist)
    $add('purchases', array_merge($line, $tot));
    $add('fiscal_years', ['tax_rounding' => "VARCHAR(8) NOT NULL DEFAULT 'line'", 'tax_round_to' => 'INT NOT NULL DEFAULT 1']);
}

/** v40 (S17.T17.1b): the document-level tax CHOICE lives on the sale header too (sales.update re-inserts items, so a choice kept only on items would be lost). */
function pb_mig_40_sale_tax_choice(PDO $pdo): void {
    $t = $pdo->query("SHOW TABLES LIKE 'sales'");
    if (!$t || !$t->fetch()) return;
    foreach (['tax_status' => 'VARCHAR(10) NULL', 'tax_rate_milli' => 'INT NULL'] as $c => $def) {
        $q = $pdo->query("SHOW COLUMNS FROM sales LIKE '$c'");
        if (!$q || !$q->fetch()) $pdo->exec("ALTER TABLE sales ADD COLUMN $c $def");
    }
}

/** v41 (S20.T20.1): resumable green->gold upgrade runs (steps, cursor, snapshot file, reconciliation report). */
function pb_mig_41_upgrade_runs(PDO $pdo): void {
    require_once __DIR__ . '/tier_upgrade.php';
    pb_ug_ensure($pdo);
}

/** v42 (S20.T20.2): currencies + fx_rates tables (empty until a tenant is on the fx plan; see plan_upgrade.php). */
function pb_mig_42_fx_tables(PDO $pdo): void {
    require_once __DIR__ . '/plan_upgrade.php';
    pb_plan_fx_ensure_tables($pdo);
}

/**
 * v43 (S22.T22.1): سطح سفارش (pay_orders.tier) و محدودیت سطح کد تخفیف (discount_codes.tiers; NULL = همه).
 * جداول پرداخت DDL خودشان را در private/config.php دارند (خارج از بسته)؛ اگر جدولی وجود نداشت، همان را رد می‌کنیم.
 */
function pb_mig_43_pay_tiers(PDO $pdo): void {
    $hasTable = function (string $t) use ($pdo): bool {
        return (bool)$pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t))->fetch();
    };
    if ($hasTable('pay_orders') && !pb_col_exists($pdo, 'pay_orders', 'tier')) {
        $pdo->exec("ALTER TABLE pay_orders ADD COLUMN tier VARCHAR(8) NOT NULL DEFAULT 'green'");
    }
    if ($hasTable('discount_codes') && !pb_col_exists($pdo, 'discount_codes', 'tiers')) {
        $pdo->exec("ALTER TABLE discount_codes ADD COLUMN tiers VARCHAR(40) NULL DEFAULT NULL");
    }
}

/** S22.T22.3c: pay_orders.order_type must accept 'tier_upgrade'. The table DDL lives outside the repo; if the column is an ENUM, widen it to VARCHAR. */
function pb_mig_44_pay_order_type(PDO $pdo): void {
    if (!$pdo->query('SHOW TABLES LIKE ' . $pdo->quote('pay_orders'))->fetch()) return;
    $col = $pdo->query("SHOW COLUMNS FROM pay_orders LIKE 'order_type'")->fetch();
    if (!$col) return;
    if (stripos((string)($col['Type'] ?? ''), 'enum') !== 0 && (int)preg_replace('/\D+/', '', (string)($col['Type'] ?? '')) >= 12) return; // already VARCHAR(>=12)
    $def = ($col['Default'] ?? null) !== null && (string)$col['Default'] !== '' ? (string)$col['Default'] : 'renew';
    $pdo->exec('ALTER TABLE pay_orders MODIFY COLUMN order_type VARCHAR(20) NOT NULL DEFAULT ' . $pdo->quote($def));
}

/** v45 (S24.T24.2): per-customer app_settings (money_unit gate, see money_unit.php). Additive; default toman. */
function pb_mig_45_app_settings(PDO $pdo): void {
    require_once __DIR__ . '/money_unit.php';
    pb_app_settings_ensure($pdo);
}

/** v46 (S25.T25.1): seed default currencies for every customer already on the fx plan (idempotent). */
function pb_mig_46_fx_defaults(PDO $pdo): void {
    require_once __DIR__ . '/fx_currencies.php';
    pb_plan_fx_ensure_tables($pdo);
    $ids = $pdo->query("SELECT id FROM customers WHERE plan='fx'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $cid) pb_fx_seed_defaults($pdo, (int)$cid);
}

/** v47 (S27.T27.1/2): currency columns on sales and purchases (NULL = rial document). */
function pb_mig_47_fx_documents(PDO $pdo): void {
    $cols = [
        ['sales', 'ccy', 'VARCHAR(8) NULL'], ['sales', 'fx_rate', 'DECIMAL(18,6) NULL'], ['sales', 'total_ccy', 'DECIMAL(20,4) NULL'],
        ['purchases', 'ccy', 'VARCHAR(8) NULL'], ['purchases', 'fx_rate', 'DECIMAL(18,6) NULL'], ['purchases', 'price_ccy', 'DECIMAL(20,4) NULL'],
    ];
    foreach ($cols as [$t, $c, $def]) {
        if (!pb_col_exists($pdo, $t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    }
}

/** v48 (S27b): rate source/time on foreign documents + fx_adjustments table (see api/fx_adjust.php). */
/** v50 (S35.T35.1): additive nullable columns for the professional forms + grade/price tables (unused until T35.3/T35.4). */
function pb_mig_50_form_columns(PDO $pdo): void {
    $docCols = [
        ['gross_kg', 'DECIMAL(12,3) NULL'], ['tare_kg', 'DECIMAL(12,3) NULL'], ['quality_deduct_kg', 'DECIMAL(12,3) NULL'],
        ['weigh_ticket_no', 'VARCHAR(40) NULL'], ['vehicle_plate', 'VARCHAR(20) NULL'], ['driver_name', 'VARCHAR(80) NULL'],
        ['discount_amount', 'BIGINT NULL'], ['tax_amount', 'BIGINT NULL'], ['doc_status', 'VARCHAR(10) NULL'], ['side_cost_payer', 'VARCHAR(10) NULL'],
    ];
    foreach (['purchases', 'sales'] as $t) {
        foreach ($docCols as [$c, $def]) {
            if (!pb_col_exists($pdo, $t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        }
    }
    foreach ([['sale_items', 'taxable'], ['purchases', 'taxable']] as [$t, $c]) { // NULL = follow the document default
        if (!pb_col_exists($pdo, $t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` TINYINT NULL");
    }
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS doc_grade_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, doc_type VARCHAR(10) NOT NULL, doc_id INT UNSIGNED NOT NULL,
        grade_key VARCHAR(40) NOT NULL, kg DECIMAL(12,3) NOT NULL, unit_price BIGINT NOT NULL,
        KEY idx_dgl_doc (customer_id, doc_type, doc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS grade_prices (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, type_name VARCHAR(100) NOT NULL, ounce_uid CHAR(36) NULL,
        grade_key VARCHAR(40) NULL, price_per_kg BIGINT NOT NULL, price_date DATE NOT NULL,
        KEY idx_gp_lookup (customer_id, type_name, price_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** v52 (S35.T35.7c): sayad id (شناسهٔ صیاد، ۱۶ رقم) on cheque lines (NULL = not given). */
/**
 * v62 (S1 اتاق انس): کد ۲ رقمی پرسنل (staff_users.staff_code) + شمارندهٔ کد پسته (lot_code_seq) + مجوزها/نقش‌های جدید.
 * پس‌رو-سازگار: به همهٔ نقش‌های موجود «price.view» داده می‌شود تا رفتار فعلی هیچ کاربری عوض نشود؛
 * فقط نقش جدید «اتاق انس» آن را ندارد. idempotent.
 */
function pb_mig_62_ounce_room(PDO $pdo): void {
    require_once __DIR__ . '/lot_code.php';
    require_once __DIR__ . '/permissions.php';
    pb_lotcode_ensure($pdo);
    if (!pb_col_exists($pdo, 'staff_users', 'staff_code')) {
        $pdo->exec('ALTER TABLE staff_users ADD COLUMN staff_code CHAR(2) NULL, ADD UNIQUE KEY uq_staff_code (customer_id, staff_code)');
    }
    // کد پرسنل برای کارکنان موجود (به ترتیب id)
    $rows = $pdo->query('SELECT id, customer_id FROM staff_users WHERE staff_code IS NULL ORDER BY customer_id, id')->fetchAll();
    foreach ($rows as $r) {
        try { pb_staff_code_assign($pdo, (int)$r['customer_id'], (int)$r['id']); } catch (Throwable $e) { error_log('[mig62] staff_code: ' . $e->getMessage()); }
    }
    // نقش‌ها
    $upd = $pdo->prepare('UPDATE roles SET perms = ? WHERE id = ?');
    $all = $pdo->query('SELECT id, customer_id, name, perms FROM roles')->fetchAll();
    $custWithRoles = [];
    foreach ($all as $r) {
        $custWithRoles[(int)$r['customer_id']] = true;
        $name = (string)$r['name'];
        if ($name === PB_ROLE_OUNCE_ROOM) continue;
        $perms = json_decode((string)$r['perms'], true);
        if (!is_array($perms)) $perms = [];
        $add = ['price.view'];
        if ($name === 'مالک') $add = array_merge($add, ['ounce.intake', 'ounce.view_own', 'ounce.view_all', 'ounce.edit_own', 'intake.confirm', 'intake.price']);
        if ($name === 'حسابدار') $add = array_merge($add, ['intake.confirm', 'intake.price', 'ounce.view_all']);
        $new = array_values(array_unique(array_merge($perms, $add)));
        if ($new !== array_values($perms)) $upd->execute([json_encode($new, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
    }
    foreach (array_keys($custWithRoles) as $cid) pb_roles_ensure_system($pdo, (int)$cid);
}

/** v61 (S31.T31.19): TOTP two-step login. */
function pb_mig_61_customer_2fa(PDO $pdo): void {
    require_once __DIR__ . '/two_factor.php';
    pb_2fa_ensure($pdo);
}

/** v60 (S31.T31.18): error monitoring events. */
function pb_mig_60_error_events(PDO $pdo): void {
    require_once __DIR__ . '/error_monitor.php';
    pb_err_ensure($pdo);
}

/** v59 (S31.T31.15): backup drill history. */
function pb_mig_59_backup_drills(PDO $pdo): void {
    require_once __DIR__ . '/backup_drill.php';
    pb_drill_ensure($pdo);
}

/** v58 (S31.T31.12): letters of credit. */
function pb_mig_58_letters_of_credit(PDO $pdo): void {
    require_once __DIR__ . '/lc.php';
    pb_lc_ensure($pdo);
}

/** v57 (S31.T31.11): packing/origin columns on sale_export (added by pb_sx_ensure). */
function pb_mig_57_sale_export_docs(PDO $pdo): void {
    require_once __DIR__ . '/sale_export.php';
    pb_sx_ensure($pdo);
}

/** v56 (S31.T31.10): export fields side table. */
function pb_mig_56_sale_export(PDO $pdo): void {
    require_once __DIR__ . '/sale_export.php';
    pb_sx_ensure($pdo);
}

/** v55 (S31.T31.5): audit_log hash chain columns + append-only triggers (best effort). */
function pb_mig_55_audit_seal(PDO $pdo): void {
    pb_audit_install_triggers($pdo);
}

/** v54 (S31.T31.3): pending payment approvals. */
function pb_mig_54_pay_approvals(PDO $pdo): void {
    require_once __DIR__ . '/pay_approval.php';
    pb_payappr_ensure($pdo);
}

/** v53 (S31.T31.1): bank statement lines for reconciliation. */
function pb_mig_53_bank_recon(PDO $pdo): void {
    require_once __DIR__ . '/bank_recon.php';
    pb_bank_recon_ensure($pdo);
}

function pb_mig_52_sayad_id(PDO $pdo): void {
    foreach (['cheque_inst', 'receipt_cheque_inst'] as $t) {
        if (!pb_col_exists($pdo, $t, 'sayad_id')) $pdo->exec("ALTER TABLE `$t` ADD COLUMN sayad_id VARCHAR(16) NULL");
    }
}

/** v51 (S35.T35.5): weighbridge cost on documents (BIGINT stored unit, NULL = none). */
function pb_mig_51_fin_block(PDO $pdo): void {
    foreach (['purchases', 'sales'] as $t) {
        if (!pb_col_exists($pdo, $t, 'weighbridge_cost')) $pdo->exec("ALTER TABLE `$t` ADD COLUMN weighbridge_cost BIGINT NULL");
    }
}

function pb_mig_48_fx_rate_source(PDO $pdo): void {
    foreach (['sales', 'purchases'] as $t) {
        if (!pb_col_exists($pdo, $t, 'fx_rate_source')) $pdo->exec("ALTER TABLE `$t` ADD COLUMN fx_rate_source VARCHAR(10) NULL");
        if (!pb_col_exists($pdo, $t, 'fx_rate_entered_at')) $pdo->exec("ALTER TABLE `$t` ADD COLUMN fx_rate_entered_at VARCHAR(30) NULL");
    }
    require_once __DIR__ . '/fx_adjust.php';
    pb_fx_ensure_adjust($pdo);
}


/** v49 (S28.T28.2): currency columns on receipt/payment lines and the cheque register (NULL = rial line). */
function pb_mig_49_fx_tx(PDO $pdo): void {
    $tables = ['lump_tx', 'credit_inst', 'cheque_inst', 'receipt_lump_tx', 'receipt_credit_inst', 'receipt_cheque_inst', 'cheques'];
    foreach ($tables as $t) {
        $ex = $pdo->query("SHOW TABLES LIKE '$t'");
        if (!($ex && $ex->fetch())) continue; // cheques is created lazily (pb_cheque_ensure adds the columns itself)
        foreach ([['ccy', 'VARCHAR(8) NULL'], ['ccy_amount', 'DECIMAL(20,4) NULL'], ['fx_rate', 'DECIMAL(18,6) NULL']] as [$c, $def]) {
            if (!pb_col_exists($pdo, $t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        }
    }
}

/** v63 (S4): شمارندهٔ تغییر و لاگ کوتاه برای poll لحظه‌ای. Additive. */
function pb_mig_63_sync_version(PDO $pdo): void {
    require_once __DIR__ . '/sync_version.php';
    pb_sync_ensure($pdo);
}

/**
 * v64 (کارگاه مرحلهٔ ۱): جهت `internal` روی proc_orders/proc_inputs/proc_outputs (ستون‌های جدید) + مجوزهای workshop.* + نقش سیستمی «اپراتور کارگاه».
 * پس‌رو-سازگار: فقط مجوز افزوده می‌شود (مالک: view/operate/manage، حسابدار: view)؛ رفتار هیچ کاربر فعلی کم نمی‌شود. idempotent.
 */
function pb_mig_64_workshop_internal(PDO $pdo): void {
    require_once __DIR__ . '/processing.php';   // + workshop.php
    require_once __DIR__ . '/permissions.php';
    pb_proc_ensure($pdo);                        // جداول (اگر هنوز نیستند)
    pb_ws_ensure_columns($pdo);                  // ستون‌های جدید با SHOW COLUMNS (idempotent)
    $upd = $pdo->prepare('UPDATE roles SET perms = ? WHERE id = ?');
    $custWithRoles = [];
    foreach ($pdo->query('SELECT id, customer_id, name, perms FROM roles')->fetchAll() as $r) {
        $custWithRoles[(int)$r['customer_id']] = true;
        $name = (string)$r['name'];
        $add = [];
        if ($name === 'مالک') $add = ['workshop.view', 'workshop.operate', 'workshop.manage'];
        elseif ($name === 'حسابدار') $add = ['workshop.view'];
        if (!$add) continue;
        $perms = json_decode((string)$r['perms'], true);
        if (!is_array($perms)) $perms = [];
        $new = array_values(array_unique(array_merge($perms, $add)));
        if ($new !== array_values($perms)) $upd->execute([json_encode($new, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
    }
    foreach (array_keys($custWithRoles) as $cid) pb_roles_ensure_system($pdo, (int)$cid); // نقش «اپراتور کارگاه»
}

/**
 * v65 (کارگاه مرحلهٔ ۲): ستون‌های بهای تمام‌شده روی proc_orders (قیمت‌های ورودی + نتایج W/Z/AF/AG/AH) + مجوز workshop.cost (مالک/حسابدار).
 * پس‌رو-سازگار: فقط ستون‌های NULL‌پذیر و مجوز افزوده می‌شود؛ idempotent (pb_col_exists / array_unique).
 */
function pb_mig_65_workshop_cost(PDO $pdo): void {
    require_once __DIR__ . '/processing.php';   // + workshop.php
    require_once __DIR__ . '/permissions.php';
    pb_proc_ensure($pdo);
    foreach ([
        ['price_per_kg', 'DECIMAL(18,4) NULL'], ['wage_per_kg', 'DECIMAL(18,4) NULL'], ['other_cost', 'DECIMAL(18,2) NULL'],
        ['price_deformed', 'DECIMAL(18,4) NULL'], ['price_discolored', 'DECIMAL(18,4) NULL'], ['price_hull', 'DECIMAL(18,4) NULL'],
        ['price_soil', 'DECIMAL(18,4) NULL'], ['price_kernel', 'DECIMAL(18,4) NULL'],
        ['value_in', 'DECIMAL(18,2) NULL'], ['cost_total', 'DECIMAL(18,2) NULL'], ['recovery_value', 'DECIMAL(18,2) NULL'],
        ['cost_sound', 'DECIMAL(18,2) NULL'], ['cost_per_kg_sound', 'DECIMAL(18,4) NULL'],
    ] as [$c, $def]) {
        if (!pb_col_exists($pdo, 'proc_orders', $c)) $pdo->exec("ALTER TABLE `proc_orders` ADD COLUMN `$c` $def");
    }
    $upd = $pdo->prepare('UPDATE roles SET perms = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id, name, perms FROM roles')->fetchAll() as $r) {
        if (!in_array((string)$r['name'], ['مالک', 'حسابدار'], true)) continue;
        $perms = json_decode((string)$r['perms'], true);
        if (!is_array($perms)) $perms = [];
        $new = array_values(array_unique(array_merge($perms, ['workshop.cost'])));
        if ($new !== array_values($perms)) $upd->execute([json_encode($new, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
    }
}

/**
 * v66 (کارگاه مرحلهٔ ۳-الف): جداول گزارش خط شکست crack_runs / crack_stops (مستقل از موجودی انبار) + مجوز workshop.crack
 * (مالک و «اپراتور کارگاه»؛ هیچ مجوز مالی). idempotent: CREATE TABLE IF NOT EXISTS + array_unique.
 */
function pb_mig_66_workshop_crack(PDO $pdo): void {
    require_once __DIR__ . '/workshop_crack.php';
    require_once __DIR__ . '/permissions.php';
    pb_cr_ensure($pdo);
    $upd = $pdo->prepare('UPDATE roles SET perms = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id, name, perms FROM roles')->fetchAll() as $r) {
        if (!in_array((string)$r['name'], ['مالک', PB_ROLE_WORKSHOP_OPERATOR], true)) continue;
        $perms = json_decode((string)$r['perms'], true);
        if (!is_array($perms)) $perms = [];
        $new = array_values(array_unique(array_merge($perms, ['workshop.crack'])));
        if ($new !== array_values($perms)) $upd->execute([json_encode($new, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
    }
}

/**
 * v67 (کارگاه مرحلهٔ ۴): کارگاه بیرونی با قبض روی جهت موجود `paid` — ستون‌های قبض روی proc_orders
 * (receipt_no، cash_note «کش»، non_company_note «غیر شرکتی»، sent_gram_pct F، recv_kernel_kg H، recv_gram_pct I).
 * owner/bale_count/input_kg از قبل هستند. فقط ستون NULL‌پذیر؛ بدون مجوز جدید (processing.manage/create و stock.view موجودند). idempotent (pb_col_exists).
 */
function pb_mig_67_workshop_outside(PDO $pdo): void {
    require_once __DIR__ . '/processing.php';   // + workshop.php + workshop_outside.php
    pb_proc_ensure($pdo);
    foreach (PB_OW_COLS as [$c, $def]) {
        if (!pb_col_exists($pdo, 'proc_orders', $c)) $pdo->exec("ALTER TABLE `proc_orders` ADD COLUMN `$c` $def");
    }
}

/**
 * v68 (کارگاه مرحلهٔ ۵-الف): «موجودی پستهٔ خرجی» — جدول تعدیل دستی feed_adjust + مجوز مشاهدهٔ workshop.feed.
 * workshop.feed به مالک، «اپراتور کارگاه» (فقط‌مشاهده) و هر نقشی که stock.view دارد اضافه می‌شود (تعدیل دستی/حذف = workshop.manage).
 * idempotent (CREATE IF NOT EXISTS + افزودن مجوز فقط اگر نبود). منبع خودکار (proc_orders paid / crack_runs) جدول جدید نمی‌خواهد.
 */
function pb_mig_68_feed_stock(PDO $pdo): void {
    require_once __DIR__ . '/workshop_feed.php';
    require_once __DIR__ . '/permissions.php';
    pb_feed_ensure($pdo);
    $upd = $pdo->prepare('UPDATE roles SET perms = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id, name, perms FROM roles')->fetchAll() as $r) {
        $perms = json_decode((string)$r['perms'], true);
        if (!is_array($perms)) $perms = [];
        $want = in_array((string)$r['name'], ['مالک', PB_ROLE_WORKSHOP_OPERATOR], true) || in_array('stock.view', $perms, true);
        if (!$want) continue;
        $new = array_values(array_unique(array_merge($perms, ['workshop.feed'])));
        if ($new !== array_values($perms)) $upd->execute([json_encode($new, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
    }
}

/**
 * v69 (S5): جدول پیوند crack_run_lots (لات‌های ساخته‌شده از خروجی‌های خط شکست). فقط CREATE TABLE IF NOT EXISTS (در pb_cr_ensure)؛
 * بدون مجوز جدید (ساخت لات با workshop.manage). idempotent.
 */
function pb_mig_69_crack_lots(PDO $pdo): void {
    require_once __DIR__ . '/workshop_crack.php';
    pb_cr_ensure($pdo);
}

/**
 * v70 (CP1): جدول crack_pools (استخر هزینهٔ خط شکست) + بک‌فیل استخر برای ردیف‌های قبلیِ دارای crack_run_lots (lots_only، بهای فعلی لات مبدأ، backfilled=1).
 * بدون مجوز جدید. idempotent (CREATE IF NOT EXISTS + فقط ردیف‌های بدون استخر).
 */
function pb_mig_70_crack_pool(PDO $pdo): void {
    require_once __DIR__ . '/workshop_crack.php'; // + crack_pool.php
    pb_cr_ensure($pdo);
    pb_cp_backfill($pdo);
}

/**
 * v71: credit_used on the six receipt/payment line tables. When a due reduced by return credit is marked paid, amount is lowered by
 * credit_used (the part settled by the return) and the pool of that party is reduced by Σ credit_used (no double application). NULL/0 = none.
 * Additive + idempotent (see backend/sql/migration_v71_credit_used.sql).
 */
function pb_mig_71_credit_used(PDO $pdo): void {
    foreach (['lump_tx', 'credit_inst', 'cheque_inst', 'receipt_lump_tx', 'receipt_credit_inst', 'receipt_cheque_inst'] as $t) {
        $ex = $pdo->query("SHOW TABLES LIKE '$t'");
        if (!($ex && $ex->fetch())) continue;
        if (!pb_col_exists($pdo, $t, 'credit_used')) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `credit_used` DECIMAL(14,2) NULL DEFAULT NULL");
    }
}

/**
 * v72 (کارگاه مرحلهٔ ۴): proc_outputs.out_price = قیمت هر کیلوی ردیف خروجی (جلوی هر خروجی + «خروجی جدید» با grade=custom).
 * Additive + idempotent (SHOW COLUMNS)؛ مستند: backend/sql/migration_v72_workshop_outputs_price.sql. grade=custom نیاز به تغییر ساختار ندارد (VARCHAR(20)).
 */
function pb_mig_72_workshop_outputs_price(PDO $pdo): void {
    require_once __DIR__ . '/processing.php';   // + workshop.php
    $ex = $pdo->query("SHOW TABLES LIKE 'proc_outputs'");
    if (!($ex && $ex->fetch())) pb_proc_ensure($pdo);
    pb_ws_ensure_out_price($pdo);
}

/**
 * v73 (تسعیر ارز حرفه‌ای، مرحلهٔ ۱): ستون‌های جدید fx_revals (rev_date, rev_status, doc_count, net_delta, note, reversed_by)
 * + نقش حساب fx_unrealized (۵۲۰۹) برای مشتریان طلایی (backfill). Additive + idempotent؛ مستند: backend/sql/migration_v73_fx_reval_pro.sql.
 */
function pb_mig_73_fx_reval_pro(PDO $pdo): void {
    require_once __DIR__ . '/fx_reval.php';
    pb_fxr_ensure($pdo);
    require_once __DIR__ . '/fiscal.php';
    require_once __DIR__ . '/accounts.php';
    foreach ($pdo->query("SELECT id FROM customers WHERE tier='gold' AND plan='fx'")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        pb_acc_backfill_roles($pdo, (int)$cid);
    }
}

/**
 * v74 (تسعیر ارز حرفه‌ای، مرحلهٔ ۶): مجوزهای جدا accounting.fx_reval.apply / accounting.fx_reval.reverse.
 * سازگاری با قبل: هر نقشی که accounting.post دارد این دو کلید را هم می‌گیرد (قبلاً apply/reverse با accounting.post مجاز بود).
 * یک‌بار اجرا می‌شود؛ بعد از آن مالک می‌تواند هر کدام را در نقش خاموش کند و دوباره اضافه نمی‌شود. Idempotent. مستند: backend/sql/migration_v74_fx_reval_perms.sql.
 */
function pb_mig_74_fx_reval_perms(PDO $pdo): void {
    $upd = $pdo->prepare('UPDATE roles SET perms = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id, perms FROM roles')->fetchAll() as $r) {
        $perms = json_decode((string)$r['perms'], true);
        if (!is_array($perms) || !in_array('accounting.post', $perms, true)) continue;
        $new = array_values(array_unique(array_merge($perms, ['accounting.fx_reval.apply', 'accounting.fx_reval.reverse'])));
        if ($new !== $perms) $upd->execute([json_encode($new, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
    }
}

/**
 * v75 (کارتکس لات): مجوز جدید cartex.view (فقط مشاهدهٔ «کارتکس لات») + نقش سیستمی «کارتکس».
 * سازگاری با قبل: «مالک» و هر نقشی که stock.view دارد (قبلاً کارتکس با stock.view دیده می‌شد) cartex.view را هم می‌گیرد.
 * نقش «کارتکس» برای مشتری‌هایی که قبلاً نقش دارند ساخته می‌شود. Idempotent.
 */
function pb_mig_75_cartex_role(PDO $pdo): void {
    require_once __DIR__ . '/permissions.php';
    $upd = $pdo->prepare('UPDATE roles SET perms = ? WHERE id = ?');
    $custWithRoles = [];
    foreach ($pdo->query('SELECT id, customer_id, name, perms FROM roles')->fetchAll() as $r) {
        $custWithRoles[(int)$r['customer_id']] = true;
        $perms = json_decode((string)$r['perms'], true);
        if (!is_array($perms)) $perms = [];
        $want = (string)$r['name'] === 'مالک' || in_array('stock.view', $perms, true);
        if (!$want) continue;
        $new = array_values(array_unique(array_merge($perms, ['cartex.view'])));
        if ($new !== array_values($perms)) $upd->execute([json_encode($new, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
    }
    foreach (array_keys($custWithRoles) as $cid) pb_roles_ensure_system($pdo, (int)$cid);
}
