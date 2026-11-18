<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S15.T15.1 — fiscal years (GOLD). Green customers: nothing changes (guard is a no-op, actions answer edition_required).
 * - fiscal_years: Jalali-year boundaries via jdate.php; status open|closed|locked; one is_current.
 * - VAT settings per year are DATA entered by the user/accountant (vat_enabled, vat_rate, vat_levy_rate, vat_note, vat_source).
 *   Nothing statutory is hard-coded; a seeded year has vat_enabled=0, vat_rate=NULL.
 * - pb_fy_guard(): gold documents must fall in an OPEN fiscal year (purchases, sales, ... hooks in app.php/sale_core.php).
 * Actions: fiscal_years.list | fiscal_years.save | fiscal_years.create | fiscal_years.set_current
 */
require_once __DIR__ . '/jdate.php';
require_once __DIR__ . '/period_lock.php'; // S31.T31.2

const PB_FY_STATUSES = ['open', 'closed', 'locked'];

function pb_fy_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS fiscal_years (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        vat_enabled TINYINT(1) NOT NULL DEFAULT 0,
        vat_rate DECIMAL(6,3) NULL,
        vat_levy_rate DECIMAL(6,3) NULL,
        vat_note VARCHAR(500) NULL,
        vat_source VARCHAR(300) NULL,
        is_current TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        UNIQUE KEY uq_fy_start (customer_id, start_date),
        KEY idx_fy_c (customer_id, start_date, end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_fy_is_gold(array $customer): bool {
    return function_exists('pb_tier_of') && pb_tier_of($customer) === PB_TIER_GOLD;
}

/** Inserts the Jalali year $jy for the customer when missing; returns its id. */
function pb_fy_insert(PDO $pdo, int $cid, int $jy, bool $current): int {
    [$s, $e] = pb_jyear_bounds($jy);
    $st = $pdo->prepare('SELECT id FROM fiscal_years WHERE customer_id=? AND start_date=?');
    $st->execute([$cid, $s]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $ov = $pdo->prepare('SELECT 1 FROM fiscal_years WHERE customer_id=? AND start_date<=? AND end_date>=? LIMIT 1');
    $ov->execute([$cid, $e, $s]);
    if ($ov->fetchColumn()) bad('این سال مالی با سال مالی دیگری همپوشانی دارد');
    $pdo->prepare('INSERT INTO fiscal_years (customer_id, name, start_date, end_date, status, is_current, created_at) VALUES (?,?,?,?,?,?,NOW())')
        ->execute([$cid, 'سال مالی ' . $jy, $s, $e, 'open', $current ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

/** Gold customers always have at least the current Jalali year. */
function pb_fy_seed(PDO $pdo, int $cid): void {
    static $done = [];
    if (isset($done[$cid])) return;
    pb_fy_ensure($pdo);
    $st = $pdo->prepare('SELECT COUNT(*) FROM fiscal_years WHERE customer_id=?');
    $st->execute([$cid]);
    if ((int)$st->fetchColumn() === 0) pb_fy_insert($pdo, $cid, pb_jyear_of(date('Y-m-d')), true);
    $done[$cid] = true;
}

/** S15.T15.4: owner = all years; staff = allowed_fy_ids (null = all). */
function pb_fy_access(array $customer, int $fyId): bool {
    if ((int)($customer['_staff_id'] ?? 0) <= 0) return true;
    $a = $customer['_allowed_fy'] ?? null;
    return $a === null || in_array($fyId, (array)$a, true);
}

function pb_fy_require(array $customer, int $fyId): void {
    if (!pb_fy_access($customer, $fyId)) out(['error' => 'fy_forbidden', 'message' => 'به این سال مالی دسترسی ندارید'], 403);
}

/** Gold: the date must fall inside an OPEN fiscal year. Green: no-op. */
function pb_fy_guard(PDO $pdo, int $cid, array $customer, string $date): void {
    if (!pb_fy_is_gold($customer)) return;
    pb_fy_seed($pdo, $cid);
    $st = $pdo->prepare('SELECT status, id FROM fiscal_years WHERE customer_id=? AND start_date<=? AND end_date>=? LIMIT 1');
    $st->execute([$cid, $date, $date]);
    $fr = $st->fetch();
    $s = $fr ? $fr['status'] : false;
    if ($fr) pb_fy_require($customer, (int)$fr['id']);
    if ($s === false) bad('تاریخ سند در هیچ سال مالی تعریف‌شده قرار ندارد؛ ابتدا سال مالی مربوطه را بسازید');
    if ($s !== 'open') bad('سال مالی این تاریخ بسته یا قفل است؛ ثبت یا ویرایش سند در آن ممکن نیست');
    pb_pl_guard($pdo, $cid, $date); // S31.T31.2 monthly lock
}

function pb_fy_row_out(array $r): array {
    return [
        'id' => (int)$r['id'], 'name' => (string)$r['name'], 'start_date' => (string)$r['start_date'], 'end_date' => (string)$r['end_date'],
        'status' => (string)$r['status'], 'vat_enabled' => (int)$r['vat_enabled'] === 1,
        'vat_rate' => $r['vat_rate'] === null ? null : (float)$r['vat_rate'],
        'vat_levy_rate' => $r['vat_levy_rate'] === null ? null : (float)$r['vat_levy_rate'],
        'vat_note' => $r['vat_note'], 'vat_source' => $r['vat_source'], 'is_current' => (int)$r['is_current'] === 1,
        'tax_rounding' => (string)($r['tax_rounding'] ?? 'line'), 'tax_round_to' => (int)($r['tax_round_to'] ?? 1), // S17.T17.1c
    ];
}

function pb_fy_rate($v, string $label): ?string {
    if ($v === null || $v === '') return null;
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100) bad("«$label» باید بین ۰ تا ۱۰۰ باشد");
    return (string)round((float)$v, 3);
}

function pb_fy_list(PDO $pdo, int $cid, ?array $customer = null): array {
    pb_fy_seed($pdo, $cid);
    $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE customer_id=? ORDER BY start_date');
    $st->execute([$cid]);
    $rows = $st->fetchAll();
    if ($customer !== null) $rows = array_values(array_filter($rows, function ($r) use ($customer) { return pb_fy_access($customer, (int)$r['id']); }));
    return ['items' => array_map('pb_fy_row_out', $rows)];
}

function pb_fy_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    pb_fy_ensure($pdo);
    if ($action === 'fiscal_years.list') out(pb_fy_list($pdo, $cid, $customer));

    if ($action === 'fiscal_years.create') { // {jy?} default = year after the latest one
        pb_fy_seed($pdo, $cid);
        $jy = (int)($p['jy'] ?? 0);
        if ($jy === 0) {
            $st = $pdo->prepare('SELECT MAX(end_date) FROM fiscal_years WHERE customer_id=?');
            $st->execute([$cid]);
            $jy = pb_jyear_of((string)$st->fetchColumn()) + 1;
        }
        if ($jy < 1300 || $jy > 1600) bad('سال نامعتبر است');
        $id = pb_fy_insert($pdo, $cid, $jy, false);
        $prev = $pdo->prepare('SELECT * FROM fiscal_years WHERE customer_id=? AND end_date<(SELECT start_date FROM fiscal_years WHERE id=?) ORDER BY end_date DESC LIMIT 1');
        $prev->execute([$cid, $id]);
        if (($pr = $prev->fetch()) && !empty($p['copy_vat'])) { // user-entered settings of the previous year, copied as data
            $pdo->prepare('UPDATE fiscal_years SET vat_enabled=?, vat_rate=?, vat_levy_rate=?, vat_note=?, vat_source=? WHERE id=? AND customer_id=?')
                ->execute([$pr['vat_enabled'], $pr['vat_rate'], $pr['vat_levy_rate'], $pr['vat_note'], $pr['vat_source'], $id, $cid]);
        }
        pb_audit($pdo, $customer, 'fy.create', 'fiscal_year', (string)$id, 'ایجاد سال مالی ' . $jy);
        out(['id' => $id] + pb_fy_list($pdo, $cid, $customer));
    }

    $id = (int)($p['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $row = $st->fetch();
    if (!$row) bad('سال مالی یافت نشد', 404);
    pb_fy_require($customer, (int)$row['id']);

    if ($action === 'fiscal_years.set_current') {
        $pdo->prepare('UPDATE fiscal_years SET is_current=0 WHERE customer_id=?')->execute([$cid]);
        $pdo->prepare('UPDATE fiscal_years SET is_current=1 WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        out(pb_fy_list($pdo, $cid, $customer));
    }

    // fiscal_years.save: {id, name?, status?, vat_enabled?, vat_rate?, vat_levy_rate?, vat_note?, vat_source?}
    $set = []; $a = [];
    if (isset($p['name'])) {
        $n = trim((string)$p['name']);
        if ($n === '' || mb_strlen($n) > 100) bad('نام سال مالی نامعتبر است');
        $set[] = 'name=?'; $a[] = $n;
    }
    if (isset($p['status'])) {
        if (!in_array($p['status'], PB_FY_STATUSES, true)) bad('وضعیت سال مالی نامعتبر است');
        if ($row['status'] === 'locked' && $p['status'] !== 'locked') bad('سال مالی قفل‌شده فقط با فرایند بازگشایی ویژه (مرحلهٔ پایان سال) باز می‌شود');
        $set[] = 'status=?'; $a[] = $p['status'];
    }
    if (array_key_exists('vat_enabled', $p)) { $set[] = 'vat_enabled=?'; $a[] = ($p['vat_enabled'] === true || $p['vat_enabled'] === 1 || $p['vat_enabled'] === '1') ? 1 : 0; }
    if (array_key_exists('vat_rate', $p)) { $set[] = 'vat_rate=?'; $a[] = pb_fy_rate($p['vat_rate'], 'نرخ مالیات'); }
    if (array_key_exists('vat_levy_rate', $p)) { $set[] = 'vat_levy_rate=?'; $a[] = pb_fy_rate($p['vat_levy_rate'], 'نرخ عوارض'); }
    if (array_key_exists('tax_rounding', $p)) { // S17.T17.1c: line = round each line, doc = round once per rate group
        if (!in_array($p['tax_rounding'], ['line', 'doc'], true)) bad('قاعدهٔ گرد کردن مالیات نامعتبر است');
        $set[] = 'tax_rounding=?'; $a[] = $p['tax_rounding'];
    }
    if (array_key_exists('tax_round_to', $p)) {
        if (!in_array((int)$p['tax_round_to'], [1, 10, 100, 1000], true)) bad('مضرب گرد کردن مالیات نامعتبر است');
        $set[] = 'tax_round_to=?'; $a[] = (int)$p['tax_round_to'];
    }
    foreach (['vat_note' => 500, 'vat_source' => 300] as $f => $max) {
        if (array_key_exists($f, $p)) {
            $v = $p[$f] === null ? null : trim((string)$p[$f]);
            if ($v !== null && mb_strlen($v) > $max) bad('متن بیش از حد طولانی است');
            $set[] = "$f=?"; $a[] = ($v === '' ? null : $v);
        }
    }
    if ($set) {
        $a[] = $id; $a[] = $cid;
        $pdo->prepare('UPDATE fiscal_years SET ' . implode(', ', $set) . ' WHERE id=? AND customer_id=?')->execute($a);
        pb_audit($pdo, $customer, 'fy.update', 'fiscal_year', (string)$id, 'ویرایش سال مالی ' . $row['name']);
    }
    out(pb_fy_list($pdo, $cid, $customer));
}

/**
 * S15.T15.1b — central guard for the remaining document actions (gold only; green = no-op).
 * Called once from app.php before the action switch. Date comes from the payload, or from the parent document.
 */
function pb_fy_guard_action(PDO $pdo, int $cid, array $customer, string $action, array $p): void {
    if (!pb_fy_is_gold($customer)) return;
    static $own = [
        'purchase_returns.create' => 1, 'sale_returns.create' => 1, 'mixes.create' => 1, 'mixes.update' => 1,
        'consignments.create' => 1, 'consignments.return' => 1, 'consignments.pay' => 1, 'consignments.sold' => 1,
        'processing.create' => 1, 'processing.receive' => 1, 'processing.deliver' => 1,
        'separations.create' => 1, 'separations.update' => 1, 'loans.create' => 1,
    ];
    $date = null;
    if (isset($own[$action])) {
        $d = (string)($p['date'] ?? '');
        if ($d !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return; // the action's own validation reports it
            $date = $d;
        } elseif ($action === 'loans.create' || $action === 'purchase_returns.create' || $action === 'sale_returns.create' || $action === 'mixes.create') {
            $date = date('Y-m-d'); // vdate() defaults to today
        } else {
            $date = date('Y-m-d');
        }
    } elseif ($action === 'payments.save' || $action === 'receipts.save') {
        $isP = $action === 'payments.save';
        $st = $pdo->prepare($isP ? 'SELECT date FROM purchases WHERE id=? AND customer_id=?' : 'SELECT date FROM sales WHERE id=? AND customer_id=?');
        $st->execute([(int)($p[$isP ? 'purchase_id' : 'sale_id'] ?? 0), $cid]);
        $d = $st->fetchColumn();
        if ($d === false) return; // handler answers 404
        $date = (string)$d;
    } elseif ($action === 'payroll.run_create') {
        $y = (int)($p['year'] ?? 0); $m = (int)($p['month'] ?? 0);
        if ($y < 1300 || $y > 1600 || $m < 1 || $m > 12) return;
        $date = pb_j2g_str($y, $m, 1);
    }
    if ($date !== null) pb_fy_guard($pdo, $cid, $customer, $date);
}
