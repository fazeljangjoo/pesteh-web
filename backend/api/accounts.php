<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S15.T15.2 — chart of accounts (GOLD): accounts (گروه/کل/معین), tafsili (detail dimension), account roles.
 * - accounts: level 1 group, 2 general (کل), 3 sub (معین). System accounts cannot be deleted / re-typed.
 * - account_roles: role => account (editable); posting rules (S16) reference ROLES only.
 * - tafsili: kind party|bank|cash|employee|cost_center|other; ref_key = stable key of the source row
 *   (party name / employee uid / later bank & cash & cost-center uid). Synced on demand + lazily.
 * - Default CoA is a SUGGESTION for a pistachio trader; the accountant reviews it (SIGNOFF_ITEMS).
 * Actions: accounts.list|save|delete|roles|roles_save|reset_check, tafsili.list|save|delete|sync
 */
if (!function_exists('pb_ddl_tables_exist')) {
    /** True when every table exists. SHOW TABLES is not DDL: unlike CREATE TABLE IF NOT EXISTS it never ends an open transaction (implicit commit). */
    function pb_ddl_tables_exist(PDO $pdo, array $tables): bool {
        foreach ($tables as $t) {
            $st = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t));
            if (!$st || $st->fetchColumn() === false) return false;
        }
        return true;
    }
}

const PB_ACC_TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense', 'memo'];
const PB_ACC_NATURES = ['debit', 'credit'];
const PB_TAF_KINDS = ['party', 'bank', 'cash', 'employee', 'cost_center', 'other'];

function pb_acc_ensure(PDO $pdo): void {
    static $ok = false; // hotfix: DDL inside a posting transaction would implicitly commit it ("There is no active transaction")
    if ($ok) return;
    if (pb_ddl_tables_exist($pdo, ['accounts', 'account_roles', 'tafsili'])) { $ok = true; return; }
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS accounts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        code VARCHAR(20) NOT NULL,
        name VARCHAR(150) NOT NULL,
        level TINYINT UNSIGNED NOT NULL,
        parent_id INT UNSIGNED NULL,
        nature VARCHAR(8) NOT NULL,
        type VARCHAR(10) NOT NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        system_role VARCHAR(40) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        UNIQUE KEY uq_acc_code (customer_id, code),
        KEY idx_acc_parent (customer_id, parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS account_roles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        role VARCHAR(40) NOT NULL,
        account_id INT UNSIGNED NOT NULL,
        UNIQUE KEY uq_role (customer_id, role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS tafsili (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        kind VARCHAR(12) NOT NULL,
        ref_key VARCHAR(150) NULL,
        code VARCHAR(20) NOT NULL,
        name VARCHAR(150) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        UNIQUE KEY uq_taf_code (customer_id, code),
        UNIQUE KEY uq_taf_ref (customer_id, kind, ref_key),
        KEY idx_taf_kind (customer_id, kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Roles the posting engine may reference: role => [label, default account code]. Single source for S16 + oracle. */
function pb_acc_roles(): array {
    return [
        'cash' => ['صندوق', '1101'], 'bank' => ['بانک‌ها', '1102'],
        'cheque_in_hand' => ['اسناد دریافتنی (چک در جریان وصول: در دست)', '1103'], 'cheque_collect' => ['چک‌های در جریان وصول', '1104'],
        'ar' => ['حساب‌های دریافتنی (بدهکاران)', '1105'], 'advance_paid' => ['پیش‌پرداخت‌ها', '1106'],
        'emp_advance' => ['مساعده و وام کارکنان', '1107'], 'vat_in' => ['مالیات و عوارض ارزش افزوده خرید', '1108'],
        'inventory' => ['موجودی پسته (خام)', '1201'], 'inventory_processed' => ['موجودی پسته (فرآوری‌شده)', '1202'],
        'inventory_at_processor' => ['موجودی نزد فرآورنده', '1203'],
        'ap' => ['حساب‌های پرداختنی (بستانکاران)', '2101'], 'cheque_payable' => ['اسناد پرداختنی (چک‌های پرداختنی)', '2102'],
        'vat_out' => ['مالیات و عوارض ارزش افزوده فروش', '2103'], 'salary_payable' => ['حقوق پرداختنی', '2104'],
        'insurance_payable' => ['بیمه پرداختنی', '2105'], 'tax_payable' => ['مالیات حقوق پرداختنی', '2106'],
        'customer_advance' => ['پیش‌دریافت از مشتریان', '2107'], 'loans' => ['وام‌ها و قرض‌ها', '2201'],
        'capital' => ['سرمایه', '3101'], 'retained' => ['سود (زیان) انباشته', '3102'], 'draws' => ['برداشت صاحب سرمایه', '3103'],
        'sales' => ['فروش پسته', '4101'], 'sales_returns' => ['برگشت از فروش و تخفیفات', '4102'],
        'processing_income' => ['درآمد خدمات فرآوری', '4201'], 'commission_income' => ['درآمد کمیسیون (امانی)', '4202'],
        'other_income' => ['سایر درآمدها', '4203'],
        'cogs' => ['بهای تمام‌شده کالای فروش‌رفته', '5101'], 'waste_loss' => ['ضایعات غیرعادی', '5102'],
        'count_diff' => ['اختلاف انبارگردانی', '5103'], 'salary_expense' => ['هزینه حقوق و دستمزد', '5201'],
        'broker_expense' => ['هزینه کارمزد دلال', '5202'], 'transport' => ['هزینه حمل', '5203'], 'labor' => ['هزینه کارگری', '5204'],
        'orchard_cost' => ['هزینه‌های باغ', '5205'], 'bank_charges' => ['کارمزد بانکی', '5206'], 'fx_diff' => ['تفاوت نرخ ارز', '5207'], 'general_expense' => ['هزینه‌های عمومی', '5208'],
        'fx_unrealized' => ['سود و زیان تسعیر ارز (تحقق‌نیافته)', '5209'], // fx-reval pro S1: unrealized period-end revaluation; 5207 stays realized (settlement/adjust)
        'memo_consigned_in' => ['کالای امانی نزد ما', '6101'], 'memo_consigned_in_contra' => ['طرف حساب کالای امانی نزد ما', '6102'],
        'memo_consigned_out' => ['کالای امانی نزد دیگران', '6103'], 'memo_consigned_out_contra' => ['طرف حساب کالای امانی نزد دیگران', '6104'],
    ];
}

/** Default CoA: [code, name, level, parent_code, type, nature|null]. Nature null = from type. */
function pb_acc_default_tree(): array {
    $t = [
        ['1', 'دارایی‌ها', 1, null, 'asset'], ['11', 'دارایی‌های جاری', 2, '1', 'asset'], ['12', 'موجودی کالا', 2, '1', 'asset'],
        ['2', 'بدهی‌ها', 1, null, 'liability'], ['21', 'بدهی‌های جاری', 2, '2', 'liability'], ['22', 'بدهی‌های بلندمدت', 2, '2', 'liability'],
        ['3', 'حقوق صاحبان سرمایه', 1, null, 'equity'], ['31', 'سرمایه و سود انباشته', 2, '3', 'equity'],
        ['4', 'درآمدها', 1, null, 'revenue'], ['41', 'درآمد فروش', 2, '4', 'revenue'], ['42', 'سایر درآمدها', 2, '4', 'revenue'],
        ['5', 'هزینه‌ها و بهای تمام‌شده', 1, null, 'expense'], ['51', 'بهای تمام‌شده', 2, '5', 'expense'], ['52', 'هزینه‌های عملیاتی', 2, '5', 'expense'],
        ['6', 'حساب‌های انتظامی', 1, null, 'memo'], ['61', 'کالای امانی', 2, '6', 'memo'],
    ];
    $debitRoles = ['sales_returns' => 1, 'draws' => 1];
    foreach (pb_acc_roles() as $role => $v) {
        $code = $v[1];
        $parent = substr($code, 0, 2);
        $type = ['1' => 'asset', '2' => 'liability', '3' => 'equity', '4' => 'revenue', '5' => 'expense', '6' => 'memo'][$code[0]];
        $nature = null;
        if (isset($debitRoles[$role])) $nature = 'debit';
        if ($type === 'memo') $nature = (substr($role, -6) === 'contra') ? 'credit' : 'debit';
        $t[] = [$code, $v[0], 3, $parent, $type, $nature, $role];
    }
    return $t;
}

function pb_acc_nature_of(string $type): string {
    return in_array($type, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit';
}

/** Seeds the default chart once per gold customer (idempotent: only when the customer has no accounts). */
function pb_acc_seed(PDO $pdo, int $cid): void {
    static $done = [];
    if (isset($done[$cid])) return;
    pb_acc_ensure($pdo);
    $st = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE customer_id=?');
    $st->execute([$cid]);
    if ((int)$st->fetchColumn() > 0) { pb_acc_backfill_roles($pdo, $cid); $done[$cid] = true; return; }
    $ids = [];
    $ins = $pdo->prepare('INSERT INTO accounts (customer_id, code, name, level, parent_id, nature, type, is_system, system_role, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,1,NOW())');
    $rl = $pdo->prepare('INSERT INTO account_roles (customer_id, role, account_id) VALUES (?,?,?)');
    foreach (pb_acc_default_tree() as $r) {
        $role = $r[6] ?? null;
        $nature = $r[5] ?? pb_acc_nature_of($r[4]);
        $ins->execute([$cid, $r[0], $r[1], $r[2], $r[3] === null ? null : ($ids[$r[3]] ?? null), $nature, $r[4], 1, $role]);
        $ids[$r[0]] = (int)$pdo->lastInsertId();
        if ($role !== null) $rl->execute([$cid, $role, $ids[$r[0]]]);
    }
    $done[$cid] = true;
}

/** T16.4: roles added after a customer's chart was seeded (e.g. general_expense) get their default account created + mapped. Idempotent. */
function pb_acc_backfill_roles(PDO $pdo, int $cid): void {
    try {
        $st = $pdo->prepare('SELECT role FROM account_roles WHERE customer_id=?');
        $st->execute([$cid]);
        $have = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        foreach (pb_acc_default_tree() as $r) {
            $role = $r[6] ?? null;
            if ($role === null || isset($have[$role])) continue;
            $q = $pdo->prepare('SELECT id FROM accounts WHERE customer_id=? AND code=?');
            $q->execute([$cid, $r[0]]);
            $aid = (int)$q->fetchColumn();
            if ($aid === 0) {
                $pid = 0;
                if ($r[3] !== null) { $q->execute([$cid, $r[3]]); $pid = (int)$q->fetchColumn(); }
                $pdo->prepare('INSERT INTO accounts (customer_id, code, name, level, parent_id, nature, type, is_system, system_role, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,1,NOW())')
                    ->execute([$cid, $r[0], $r[1], $r[2], $pid > 0 ? $pid : null, $r[5] ?? pb_acc_nature_of($r[4]), $r[4], 1, $role]);
                $aid = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('INSERT IGNORE INTO account_roles (customer_id, role, account_id) VALUES (?,?,?)')->execute([$cid, $role, $aid]);
        }
    } catch (Throwable $e) {
        error_log('[accounts] backfill roles: ' . $e->getMessage());
    }
}

/** Roles that have no (active) account mapped. S16 posting rules must find this empty. */
function pb_acc_roles_missing(PDO $pdo, int $cid, ?array $needed = null): array {
    $st = $pdo->prepare('SELECT r.role FROM account_roles r JOIN accounts a ON a.id=r.account_id AND a.customer_id=r.customer_id WHERE r.customer_id=? AND a.active=1');
    $st->execute([$cid]);
    $have = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    $miss = [];
    foreach ($needed ?? array_keys(pb_acc_roles()) as $r) if (!isset($have[$r])) $miss[] = $r;
    return $miss;
}

function pb_acc_out(array $r): array {
    return ['id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'level' => (int)$r['level'],
        'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id'], 'nature' => (string)$r['nature'], 'type' => (string)$r['type'],
        'is_system' => (int)$r['is_system'] === 1, 'system_role' => $r['system_role'], 'active' => (int)$r['active'] === 1];
}

function pb_acc_list(PDO $pdo, int $cid): array {
    pb_acc_seed($pdo, $cid);
    $st = $pdo->prepare('SELECT * FROM accounts WHERE customer_id=? ORDER BY code');
    $st->execute([$cid]);
    return ['items' => array_map('pb_acc_out', $st->fetchAll())];
}

/** True when any voucher line references the account (voucher tables arrive in S16; safe before that). */
function pb_acc_is_used(PDO $pdo, int $cid, int $accId): bool {
    try {
        $st = $pdo->prepare('SELECT 1 FROM voucher_lines WHERE customer_id=? AND account_id=? LIMIT 1');
        $st->execute([$cid, $accId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false; // table not created yet
    }
}

function pb_acc_save(PDO $pdo, int $cid, array $customer, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $row = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM accounts WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $row = $st->fetch();
        if (!$row) bad('حساب یافت نشد', 404);
    }
    $name = trim((string)($p['name'] ?? ($row['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 150) bad('نام حساب نامعتبر است');
    $active = array_key_exists('active', $p) ? (($p['active'] === true || $p['active'] === 1 || $p['active'] === '1') ? 1 : 0) : (int)($row['active'] ?? 1);
    if ($row && (int)$row['is_system'] === 1) { // system: only name / active (a role-mapped account cannot be deactivated)
        if ($active === 0 && $row['system_role'] !== null) bad('حساب سیستمی دارای نقش را نمی‌توان غیرفعال کرد');
        $pdo->prepare('UPDATE accounts SET name=?, active=? WHERE id=? AND customer_id=?')->execute([$name, $active, $id, $cid]);
        pb_audit($pdo, $customer, 'account.update', 'account', (string)$id, 'ویرایش حساب ' . $row['code']);
        return pb_acc_list($pdo, $cid);
    }
    $code = trim((string)($p['code'] ?? ($row['code'] ?? '')));
    if (!preg_match('/^[0-9]{1,20}$/', $code)) bad('کد حساب باید فقط عدد باشد (حداکثر ۲۰ رقم)');
    $level = (int)($p['level'] ?? ($row['level'] ?? 0));
    if ($level < 1 || $level > 3) bad('سطح حساب باید ۱ تا ۳ باشد');
    $type = (string)($p['type'] ?? ($row['type'] ?? ''));
    if (!in_array($type, PB_ACC_TYPES, true)) bad('نوع حساب نامعتبر است');
    $nature = (string)($p['nature'] ?? ($row['nature'] ?? pb_acc_nature_of($type)));
    if (!in_array($nature, PB_ACC_NATURES, true)) bad('ماهیت حساب نامعتبر است');
    $parent = null;
    if ($level > 1) {
        $pid = (int)($p['parent_id'] ?? ($row['parent_id'] ?? 0));
        $ps = $pdo->prepare('SELECT level, type FROM accounts WHERE id=? AND customer_id=?');
        $ps->execute([$pid, $cid]);
        $pr = $ps->fetch();
        if (!$pr || (int)$pr['level'] !== $level - 1) bad('حساب والد باید یک سطح بالاتر باشد');
        if ($pr['type'] !== $type) bad('نوع حساب باید با نوع حساب والد یکی باشد');
        $parent = $pid;
    }
    $dup = $pdo->prepare('SELECT id FROM accounts WHERE customer_id=? AND code=? AND id<>?');
    $dup->execute([$cid, $code, $id]);
    if ($dup->fetchColumn()) bad('این کد حساب قبلاً استفاده شده است');
    if ($row) {
        if (($row['level'] != $level || $row['type'] !== $type) && pb_acc_is_used($pdo, $cid, $id)) bad('حساب دارای سند است؛ سطح و نوع آن قابل تغییر نیست');
        $pdo->prepare('UPDATE accounts SET code=?, name=?, level=?, parent_id=?, nature=?, type=?, active=? WHERE id=? AND customer_id=?')
            ->execute([$code, $name, $level, $parent, $nature, $type, $active, $id, $cid]);
        pb_audit($pdo, $customer, 'account.update', 'account', (string)$id, 'ویرایش حساب ' . $code);
    } else {
        $pdo->prepare('INSERT INTO accounts (customer_id, code, name, level, parent_id, nature, type, is_system, system_role, active, created_at) VALUES (?,?,?,?,?,?,?,0,NULL,?,NOW())')
            ->execute([$cid, $code, $name, $level, $parent, $nature, $type, $active]);
        pb_audit($pdo, $customer, 'account.create', 'account', (string)$pdo->lastInsertId(), 'ایجاد حساب ' . $code);
    }
    return pb_acc_list($pdo, $cid);
}

function pb_acc_delete(PDO $pdo, int $cid, array $customer, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM accounts WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) bad('حساب یافت نشد', 404);
    if ((int)$r['is_system'] === 1) bad('حساب سیستمی قابل حذف نیست');
    $ch = $pdo->prepare('SELECT 1 FROM accounts WHERE customer_id=? AND parent_id=? LIMIT 1');
    $ch->execute([$cid, $id]);
    if ($ch->fetchColumn()) bad('این حساب زیرمجموعه دارد و قابل حذف نیست');
    $ro = $pdo->prepare('SELECT 1 FROM account_roles WHERE customer_id=? AND account_id=? LIMIT 1');
    $ro->execute([$cid, $id]);
    if ($ro->fetchColumn()) bad('این حساب به یک نقش سیستمی نگاشت شده است؛ ابتدا نقش را به حساب دیگری ببرید');
    if (pb_acc_is_used($pdo, $cid, $id)) bad('حساب دارای سند است و قابل حذف نیست؛ آن را غیرفعال کنید');
    $pdo->prepare('DELETE FROM accounts WHERE id=? AND customer_id=?')->execute([$id, $cid]);
    pb_audit($pdo, $customer, 'account.delete', 'account', (string)$id, 'حذف حساب ' . $r['code']);
    return pb_acc_list($pdo, $cid);
}

function pb_acc_roles_view(PDO $pdo, int $cid): array {
    pb_acc_seed($pdo, $cid);
    $st = $pdo->prepare('SELECT role, account_id FROM account_roles WHERE customer_id=?');
    $st->execute([$cid]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[$r['role']] = (int)$r['account_id'];
    $items = [];
    foreach (pb_acc_roles() as $role => $v) $items[] = ['role' => $role, 'label' => $v[0], 'account_id' => $map[$role] ?? null];
    return ['items' => $items, 'missing' => pb_acc_roles_missing($pdo, $cid)];
}

/** roles_save {map: {role: account_id}} — only level-3 active accounts, only known roles. */
function pb_acc_roles_save(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_acc_seed($pdo, $cid);
    $map = $p['map'] ?? null;
    if (!is_array($map) || !$map) bad('نگاشت نقش‌ها خالی است');
    $known = pb_acc_roles();
    $chk = $pdo->prepare('SELECT level, active FROM accounts WHERE id=? AND customer_id=?');
    $up = $pdo->prepare('INSERT INTO account_roles (customer_id, role, account_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id)');
    foreach ($map as $role => $aid) {
        if (!isset($known[$role])) bad('نقش نامعتبر: ' . (string)$role);
        $chk->execute([(int)$aid, $cid]);
        $a = $chk->fetch();
        if (!$a || (int)$a['level'] !== 3 || (int)$a['active'] !== 1) bad('برای هر نقش باید یک حساب معین فعال انتخاب شود');
        $up->execute([$cid, (string)$role, (int)$aid]);
    }
    pb_audit($pdo, $customer, 'account.roles', 'account_roles', '', 'ویرایش نگاشت نقش حساب‌ها');
    return pb_acc_roles_view($pdo, $cid);
}

// ---------------- tafsili ----------------

function pb_taf_out(array $r): array {
    return ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'ref_key' => $r['ref_key'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'active' => (int)$r['active'] === 1];
}

function pb_taf_next_code(PDO $pdo, int $cid): string {
    $st = $pdo->prepare("SELECT MAX(CAST(code AS UNSIGNED)) FROM tafsili WHERE customer_id=? AND code REGEXP '^[0-9]+$'");
    $st->execute([$cid]);
    return (string)(max(100000, (int)$st->fetchColumn()) + 1);
}

/** Creates/updates one tafsili row for a source entity (used by sync and by later modules: banks, cash, cost centers). */
function pb_taf_upsert(PDO $pdo, int $cid, string $kind, string $refKey, string $name): int {
    $st = $pdo->prepare('SELECT id, name FROM tafsili WHERE customer_id=? AND kind=? AND ref_key=?');
    $st->execute([$cid, $kind, $refKey]);
    $r = $st->fetch();
    if ($r) {
        if ($r['name'] !== $name) $pdo->prepare('UPDATE tafsili SET name=? WHERE id=? AND customer_id=?')->execute([$name, $r['id'], $cid]);
        return (int)$r['id'];
    }
    $pdo->prepare('INSERT INTO tafsili (customer_id, kind, ref_key, code, name, active, created_at) VALUES (?,?,?,?,?,1,NOW())')
        ->execute([$cid, $kind, $refKey, pb_taf_next_code($pdo, $cid), mb_substr($name, 0, 150)]);
    return (int)$pdo->lastInsertId();
}

/** Best-effort auto-tafsili on create/rename of a party/employee/bank/cash/cost center (GOLD customers only; never throws). */
function pb_taf_hook(PDO $pdo, int $cid, string $kind, string $refKey, string $name): void {
    static $gold = [];
    try {
        if (!isset($gold[$cid])) {
            $st = $pdo->prepare('SELECT tier FROM customers WHERE id=?');
            $st->execute([$cid]);
            $gold[$cid] = ($st->fetchColumn() === 'gold');
        }
        $name = trim($name);
        if (!$gold[$cid] || $name === '' || !in_array($kind, PB_TAF_KINDS, true)) return;
        pb_acc_ensure($pdo);
        pb_taf_upsert($pdo, $cid, $kind, mb_substr($refKey, 0, 150), $name);
    } catch (Throwable $e) {
        error_log('[taf_hook] ' . $e->getMessage());
    }
}

function pb_taf_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

/** Party names (purchases/sales/loans/parties) and employees → tafsili rows. Idempotent. */
function pb_taf_sync(PDO $pdo, int $cid): array {
    $added = 0;
    $before = $pdo->prepare('SELECT COUNT(*) FROM tafsili WHERE customer_id=?');
    $before->execute([$cid]);
    $n0 = (int)$before->fetchColumn();
    $names = [];
    $srcs = [['parties', 'name'], ['loan_entries', 'party'], ['sales', 'buyer']];
    foreach ($srcs as $s) {
        if (!pb_taf_exists($pdo, $s[0])) continue;
        $q = $pdo->prepare("SELECT DISTINCT {$s[1]} FROM {$s[0]} WHERE customer_id=? AND {$s[1]} IS NOT NULL AND {$s[1]}<>''");
        $q->execute([$cid]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $n) $names[trim((string)$n)] = true;
    }
    foreach (array_keys($names) as $n) if ($n !== '') pb_taf_upsert($pdo, $cid, 'party', mb_substr($n, 0, 150), $n);
    if (pb_taf_exists($pdo, 'employees')) {
        $q = $pdo->prepare('SELECT id, uid, name FROM employees WHERE customer_id=?');
        $q->execute([$cid]);
        foreach ($q->fetchAll() as $e) pb_taf_upsert($pdo, $cid, 'employee', ($e['uid'] !== null && $e['uid'] !== '') ? (string)$e['uid'] : 'id' . $e['id'], (string)$e['name']);
    }
    $before->execute([$cid]);
    $added = (int)$before->fetchColumn() - $n0;
    return ['added' => $added];
}

function pb_taf_list(PDO $pdo, int $cid, array $p): array {
    pb_acc_ensure($pdo);
    $kind = (string)($p['kind'] ?? '');
    $sql = 'SELECT * FROM tafsili WHERE customer_id=?';
    $a = [$cid];
    if ($kind !== '') {
        if (!in_array($kind, PB_TAF_KINDS, true)) bad('نوع تفصیلی نامعتبر است');
        $sql .= ' AND kind=?'; $a[] = $kind;
    }
    $q = trim((string)($p['q'] ?? ''));
    if ($q !== '') { $sql .= ' AND (name LIKE ? OR code LIKE ?)'; $a[] = '%' . $q . '%'; $a[] = $q . '%'; }
    $st = $pdo->prepare($sql . ' ORDER BY kind, name LIMIT 1000');
    $st->execute($a);
    return ['items' => array_map('pb_taf_out', $st->fetchAll())];
}

/** tafsili.save {id?, kind, name, code?, active?} — manual rows (kind other) or rename/deactivate synced rows. */
function pb_taf_save(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_acc_ensure($pdo);
    $id = (int)($p['id'] ?? 0);
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) bad('نام تفصیلی نامعتبر است');
    $active = (!array_key_exists('active', $p) || $p['active'] === true || $p['active'] === 1 || $p['active'] === '1') ? 1 : 0;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM tafsili WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        if (!$st->fetch()) bad('تفصیلی یافت نشد', 404);
        $pdo->prepare('UPDATE tafsili SET name=?, active=? WHERE id=? AND customer_id=?')->execute([$name, $active, $id, $cid]);
    } else {
        $kind = (string)($p['kind'] ?? 'other');
        if (!in_array($kind, PB_TAF_KINDS, true)) bad('نوع تفصیلی نامعتبر است');
        $code = trim((string)($p['code'] ?? ''));
        if ($code === '') $code = pb_taf_next_code($pdo, $cid);
        if (!preg_match('/^[0-9]{1,20}$/', $code)) bad('کد تفصیلی باید فقط عدد باشد');
        $d = $pdo->prepare('SELECT id FROM tafsili WHERE customer_id=? AND code=?');
        $d->execute([$cid, $code]);
        if ($d->fetchColumn()) bad('این کد تفصیلی قبلاً استفاده شده است');
        $pdo->prepare('INSERT INTO tafsili (customer_id, kind, ref_key, code, name, active, created_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$cid, $kind, $kind === 'other' ? 'm' . bin2hex(random_bytes(6)) : null, $code, $name, $active]);
        $id = (int)$pdo->lastInsertId();
    }
    pb_audit($pdo, $customer, 'tafsili.save', 'tafsili', (string)$id, 'ثبت/ویرایش تفصیلی ' . $name);
    return pb_taf_list($pdo, $cid, []);
}

function pb_taf_delete(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_acc_ensure($pdo);
    $id = (int)($p['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM tafsili WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) bad('تفصیلی یافت نشد', 404);
    if ($r['kind'] !== 'other') bad('تفصیلی خودکار قابل حذف نیست؛ آن را غیرفعال کنید');
    try {
        $u = $pdo->prepare('SELECT 1 FROM voucher_lines WHERE customer_id=? AND tafsili_id=? LIMIT 1');
        $u->execute([$cid, $id]);
        if ($u->fetchColumn()) bad('این تفصیلی در اسناد استفاده شده است؛ غیرفعال کنید');
    } catch (PDOException $e) { /* voucher tables not created yet */ }
    $pdo->prepare('DELETE FROM tafsili WHERE id=? AND customer_id=?')->execute([$id, $cid]);
    pb_audit($pdo, $customer, 'tafsili.delete', 'tafsili', (string)$id, 'حذف تفصیلی ' . $r['name']);
    return pb_taf_list($pdo, $cid, []);
}

function pb_acc_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    pb_acc_ensure($pdo);
    switch ($action) {
        case 'accounts.list': out(pb_acc_list($pdo, $cid));
        case 'accounts.save': pb_acc_seed($pdo, $cid); out(pb_acc_save($pdo, $cid, $customer, $p));
        case 'accounts.delete': out(pb_acc_delete($pdo, $cid, $customer, $p));
        case 'accounts.roles': out(pb_acc_roles_view($pdo, $cid));
        case 'accounts.roles_save': out(pb_acc_roles_save($pdo, $cid, $customer, $p));
        case 'tafsili.list': out(pb_taf_list($pdo, $cid, $p));
        case 'tafsili.save': out(pb_taf_save($pdo, $cid, $customer, $p));
        case 'tafsili.delete': out(pb_taf_delete($pdo, $cid, $customer, $p));
        case 'tafsili.sync': out(pb_taf_sync($pdo, $cid) + pb_taf_list($pdo, $cid, []));
    }
    bad('عملیات نامعتبر', 404);
}
