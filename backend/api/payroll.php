<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S11.T11.1 — payroll (حقوق و دستمزد): employees, pay items, monthly runs, advances. Both tiers. Statutory parts: T11.3 (Gold).
 *
 * Tables: employees, pay_items (earning|deduction; fixed|percent of base), payroll_runs (year, month, ccy; draft|approved|paid),
 *         payroll_lines (per employee; net = earnings − deductions − advances, exact to 2 decimals),
 *         payroll_line_items (the pay items applied on the line), employee_advances (مساعده; balance = amount − deducted).
 * Rules:
 *  - A draft run recalculates its lines on every change. Advances are only PREVIEWED in a draft.
 *  - Approve (draft → approved): advances are consumed oldest-first up to each line's net earnings and recorded in
 *    employee_advances.deducted. An approved run is immutable (no line/run writes). Reversal is not supported yet.
 *  - Employee = its own table (party-type link is not added yet; a name is enough for now).
 */

const PB_PAY_KINDS = ['earning', 'deduction'];
const PB_PAY_FORMULAS = ['fixed', 'percent'];

function pb_pay_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS employees (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        name VARCHAR(120) NOT NULL,
        position VARCHAR(100) NULL,
        hire_date DATE NULL,
        base_salary DECIMAL(18,2) NOT NULL DEFAULT 0,
        ccy VARCHAR(8) NOT NULL DEFAULT 'IRR',
        pay_schedule VARCHAR(8) NOT NULL DEFAULT 'monthly',
        bank_account VARCHAR(40) NULL,
        insurance_no VARCHAR(30) NULL,
        active TINYINT NOT NULL DEFAULT 1,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_emp_uid (customer_id, uid),
        KEY idx_emp_cust (customer_id, active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS pay_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        name VARCHAR(100) NOT NULL,
        kind VARCHAR(10) NOT NULL,
        taxable TINYINT NOT NULL DEFAULT 0,
        insurable TINYINT NOT NULL DEFAULT 0,
        formula VARCHAR(8) NOT NULL DEFAULT 'fixed',
        default_value DECIMAL(18,4) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pitem_uid (customer_id, uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS payroll_runs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        year SMALLINT UNSIGNED NOT NULL,
        month TINYINT UNSIGNED NOT NULL,
        ccy VARCHAR(8) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'draft',
        approved_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_prun_period (customer_id, year, month, ccy)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS payroll_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        run_id INT UNSIGNED NOT NULL,
        employee_id INT UNSIGNED NOT NULL,
        base DECIMAL(18,2) NOT NULL DEFAULT 0,
        overtime DECIMAL(18,2) NOT NULL DEFAULT 0,
        allowances DECIMAL(18,2) NOT NULL DEFAULT 0,
        earnings DECIMAL(18,2) NOT NULL DEFAULT 0,
        deductions DECIMAL(18,2) NOT NULL DEFAULT 0,
        other_deductions DECIMAL(18,2) NOT NULL DEFAULT 0,
        advances_deducted DECIMAL(18,2) NOT NULL DEFAULT 0,
        net DECIMAL(18,2) NOT NULL DEFAULT 0,
        ccy VARCHAR(8) NOT NULL,
        UNIQUE KEY uq_pline (customer_id, run_id, employee_id),
        KEY idx_pline_run (customer_id, run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS payroll_line_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        line_id INT UNSIGNED NOT NULL,
        pay_item_id INT UNSIGNED NULL,
        name VARCHAR(100) NOT NULL,
        kind VARCHAR(10) NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        KEY idx_pli_line (customer_id, line_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS employee_advances (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        employee_id INT UNSIGNED NOT NULL,
        adv_date DATE NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        deducted DECIMAL(18,2) NOT NULL DEFAULT 0,
        ccy VARCHAR(8) NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_adv_uid (customer_id, uid),
        KEY idx_adv_emp (customer_id, employee_id, ccy)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_pay_employee(PDO $pdo, int $cid, int $id): array {
    $st = $pdo->prepare('SELECT * FROM employees WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $e = $st->fetch();
    if (!$e) bad('کارمند یافت نشد', 404);
    return $e;
}

function pb_pay_run(PDO $pdo, int $cid, int $id): array {
    $st = $pdo->prepare('SELECT * FROM payroll_runs WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) bad('دورهٔ حقوق یافت نشد', 404);
    return $r;
}

/** Open advance balance of an employee in a currency (amount − deducted, oldest first in $rows). */
function pb_pay_advance_rows(PDO $pdo, int $cid, int $employeeId, string $ccy): array {
    $st = $pdo->prepare('SELECT id, amount, deducted FROM employee_advances WHERE customer_id=? AND employee_id=? AND ccy=? AND amount > deducted ORDER BY adv_date, id');
    $st->execute([$cid, $employeeId, $ccy]);
    return $st->fetchAll();
}

/** Net of one payroll line. Pure given its inputs; $advances = amount to deduct from advances (already capped). */
function pb_pay_net(float $base, float $overtime, float $allowances, float $deductions, float $otherDed, float $advances): array {
    $earnings = round($base + $overtime + $allowances, 2);
    $ded = round($deductions + $otherDed, 2);
    $net = round($earnings - $ded - $advances, 2);
    return ['earnings' => $earnings, 'deductions' => $ded, 'net' => $net];
}

/** Recomputes a draft line from the employee, the pay items and the stored overtime / other deductions.
 *  $prm = year parameters (payroll_params row) when the statutory layer applies (gold edition), else null. */
function pb_pay_compute_line(PDO $pdo, int $cid, array $emp, float $overtime, float $otherDed, string $ccy, ?array $prm = null): array {
    $base = round((float)$emp['base_salary'], 2);
    $allow = 0.0; $ded = 0.0; $items = []; $insAllow = 0.0; $taxAllow = 0.0;
    $it = $pdo->query('SELECT * FROM pay_items WHERE customer_id=' . (int)$cid . ' ORDER BY id');
    foreach ($it->fetchAll() as $p) {
        $amt = $p['formula'] === 'percent' ? round($base * (float)$p['default_value'] / 100, 2) : round((float)$p['default_value'], 2);
        if ($amt == 0.0) continue;
        if ($p['kind'] === 'earning') {
            $allow += $amt;
            if ((int)$p['insurable'] === 1) $insAllow += $amt;
            if ((int)$p['taxable'] === 1) $taxAllow += $amt;
        } else $ded += $amt;
        $items[] = ['pay_item_id' => (int)$p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'amount' => $amt];
    }
    $stat = null;
    if ($prm !== null) {
        // overtime is treated as both insurable and taxable; base salary likewise
        $stat = pb_pay_statutory($prm, round($base + $overtime + $insAllow, 2), round($base + $overtime + $taxAllow, 2));
        $ded += $stat['ins_employee'] + $stat['tax'];
        $items[] = ['pay_item_id' => null, 'name' => 'بیمه سهم کارمند', 'kind' => 'deduction', 'amount' => $stat['ins_employee']];
        $items[] = ['pay_item_id' => null, 'name' => 'مالیات حقوق', 'kind' => 'deduction', 'amount' => $stat['tax']];
    }
    $calc = pb_pay_net($base, $overtime, $allow, $ded, $otherDed, 0.0);
    // draft preview: advances the employee owes in this currency, capped by what is left after deductions
    $bal = 0.0;
    foreach (pb_pay_advance_rows($pdo, $cid, (int)$emp['id'], $ccy) as $a) $bal += (float)$a['amount'] - (float)$a['deducted'];
    $adv = round(min($bal, max(0.0, $calc['earnings'] - $calc['deductions'])), 2);
    $calc = pb_pay_net($base, $overtime, $allow, $ded, $otherDed, $adv);
    return ['base' => $base, 'overtime' => round($overtime, 2), 'allowances' => round($allow, 2), 'earnings' => $calc['earnings'],
        'deductions' => $ded, 'other_deductions' => round($otherDed, 2), 'advances_deducted' => $adv, 'net' => $calc['net'], 'items' => $items, 'stat' => $stat, 'stat_fy' => $prm === null ? 0 : (int)$prm['fy']];
}

function pb_pay_store_line(PDO $pdo, int $cid, int $runId, int $empId, string $ccy, array $v): void {
    $pdo->prepare('INSERT INTO payroll_lines (customer_id, run_id, employee_id, base, overtime, allowances, earnings, deductions, other_deductions, advances_deducted, net, ccy)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE base=VALUES(base), overtime=VALUES(overtime), allowances=VALUES(allowances), earnings=VALUES(earnings),
        deductions=VALUES(deductions), other_deductions=VALUES(other_deductions), advances_deducted=VALUES(advances_deducted), net=VALUES(net)')
        ->execute([$cid, $runId, $empId, $v['base'], $v['overtime'], $v['allowances'], $v['earnings'], $v['deductions'], $v['other_deductions'], $v['advances_deducted'], $v['net'], $ccy]);
    $lid = (int)$pdo->query('SELECT id FROM payroll_lines WHERE customer_id=' . (int)$cid . ' AND run_id=' . (int)$runId . ' AND employee_id=' . (int)$empId)->fetchColumn();
    $pdo->prepare('DELETE FROM payroll_line_items WHERE customer_id=? AND line_id=?')->execute([$cid, $lid]);
    $ins = $pdo->prepare('INSERT INTO payroll_line_items (customer_id, line_id, pay_item_id, name, kind, amount) VALUES (?,?,?,?,?,?)');
    foreach ($v['items'] as $i) $ins->execute([$cid, $lid, $i['pay_item_id'], $i['name'], $i['kind'], $i['amount']]);
    $pdo->prepare('DELETE FROM payroll_line_stat WHERE customer_id=? AND line_id=?')->execute([$cid, $lid]);
    if (!empty($v['stat'])) {
        $st = $v['stat'];
        $pdo->prepare('INSERT INTO payroll_line_stat (customer_id, line_id, fy, insurable_base, taxable_base, ins_employee, ins_employer, exemption, taxable, tax) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $lid, (int)$v['stat_fy'], $st['insurable_base'], $st['taxable_base'], $st['ins_employee'], $st['ins_employer'], $st['exemption'], $st['taxable'], $st['tax']]);
    }
}

function pb_pay_assert_draft(array $run): void {
    if ($run['status'] !== 'draft') bad('این دوره تأیید شده و قابل تغییر نیست');
}

/** payroll.employee_save {id?, uid?, name, position?, hire_date?, base_salary, ccy?, pay_schedule?, bank_account?, insurance_no?, active?, note?} */
function pb_pay_employee_save(PDO $pdo, int $cid, array $p): array {
    $name = str_or_null($p['name'] ?? null, 120);
    if ($name === null) bad('نام کارمند را وارد کنید');
    $base = num($p['base_salary'] ?? 0);
    if ($base < 0 || $base > 1e13) bad('حقوق پایه نامعتبر است');
    $sched = (string)($p['pay_schedule'] ?? 'monthly');
    if (!in_array($sched, ['monthly', 'weekly', 'daily'], true)) bad('دورهٔ پرداخت نامعتبر است');
    $vals = [str_or_null($p['position'] ?? null, 100), ($p['hire_date'] ?? '') !== '' ? vdate($p['hire_date']) : null, round($base, 2),
        str_or_null($p['ccy'] ?? 'IRR', 8) ?? 'IRR', $sched, str_or_null($p['bank_account'] ?? null, 40), str_or_null($p['insurance_no'] ?? null, 30),
        isset($p['active']) ? (int)(bool)$p['active'] : 1, str_or_null($p['note'] ?? null, 500)];
    if (!empty($p['id'])) {
        $e = pb_pay_employee($pdo, $cid, (int)$p['id']);
        $pdo->prepare('UPDATE employees SET name=?, position=?, hire_date=?, base_salary=?, ccy=?, pay_schedule=?, bank_account=?, insurance_no=?, active=?, note=? WHERE id=? AND customer_id=?')
            ->execute(array_merge([$name], $vals, [(int)$e['id'], $cid]));
        pb_taf_hook($pdo, $cid, 'employee', ($e['uid'] ?? '') !== '' ? (string)$e['uid'] : 'id' . $e['id'], $name);
        return ['id' => (int)$e['id']];
    }
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM employees WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $eid = $ex->fetchColumn();
        if ($eid) return ['id' => (int)$eid];
    }
    $pdo->prepare('INSERT INTO employees (customer_id, uid, name, position, hire_date, base_salary, ccy, pay_schedule, bank_account, insurance_no, active, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(array_merge([$cid, $uid, $name], $vals));
    $newId = (int)$pdo->lastInsertId();
    pb_taf_hook($pdo, $cid, 'employee', $uid !== null && $uid !== '' ? (string)$uid : 'id' . $newId, $name);
    return ['id' => $newId];
}

/** payroll.employees {} */
function pb_pay_employees(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT * FROM employees WHERE customer_id=? ORDER BY active DESC, name, id');
    $st->execute([$cid]);
    $items = [];
    foreach ($st->fetchAll() as $e) $items[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'position' => $e['position'], 'base_salary' => (float)$e['base_salary'],
        'ccy' => $e['ccy'], 'pay_schedule' => $e['pay_schedule'], 'active' => (int)$e['active'], 'hire_date' => $e['hire_date'], 'bank_account' => $e['bank_account'],
        'insurance_no' => $e['insurance_no'], 'note' => $e['note']];
    return ['items' => $items];
}

/** payroll.item_save {id?, uid?, name, kind: earning|deduction, formula: fixed|percent, default_value, taxable?, insurable?} */
function pb_pay_item_save(PDO $pdo, int $cid, array $p): array {
    $name = str_or_null($p['name'] ?? null, 100);
    if ($name === null) bad('نام قلم حقوقی را وارد کنید');
    $kind = (string)($p['kind'] ?? '');
    if (!in_array($kind, PB_PAY_KINDS, true)) bad('نوع قلم نامعتبر است');
    $formula = (string)($p['formula'] ?? 'fixed');
    if (!in_array($formula, PB_PAY_FORMULAS, true)) bad('روش محاسبه نامعتبر است');
    $val = num($p['default_value'] ?? 0);
    if ($val < 0 || $val > 1e13 || ($formula === 'percent' && $val > 100)) bad('مقدار قلم نامعتبر است');
    $tax = !empty($p['taxable']) ? 1 : 0; $ins = !empty($p['insurable']) ? 1 : 0;
    if (!empty($p['id'])) {
        $pdo->prepare('UPDATE pay_items SET name=?, kind=?, taxable=?, insurable=?, formula=?, default_value=? WHERE id=? AND customer_id=?')
            ->execute([$name, $kind, $tax, $ins, $formula, $val, (int)$p['id'], $cid]);
        return ['id' => (int)$p['id']];
    }
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) { // ارسال دوباره با همان uid (uq_pitem_uid): خطای ۵۰۰ تکراری نیست؛ همان قلم برگردانده می‌شود
        $ex = $pdo->prepare('SELECT id FROM pay_items WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $exId = $ex->fetchColumn();
        if ($exId) return ['id' => (int)$exId];
    }
    $pdo->prepare('INSERT INTO pay_items (customer_id, uid, name, kind, taxable, insurable, formula, default_value) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$cid, $uid, $name, $kind, $tax, $ins, $formula, $val]);
    return ['id' => (int)$pdo->lastInsertId()];
}

function pb_pay_items(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT * FROM pay_items WHERE customer_id=? ORDER BY kind, id');
    $st->execute([$cid]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'formula' => $r['formula'],
        'default_value' => (float)$r['default_value'], 'taxable' => (int)$r['taxable'], 'insurable' => (int)$r['insurable']];
    return ['items' => $items];
}

/** payroll.advance_add {employee_id, amount, date?, note?, uid?} */
function pb_pay_advance_add(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM employee_advances WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        if ($ex->fetchColumn()) return ['ok' => true];
    }
    $e = pb_pay_employee($pdo, $cid, (int)($p['employee_id'] ?? 0));
    $amt = num($p['amount'] ?? 0);
    if ($amt <= 0 || $amt > 1e13) bad('مبلغ مساعده باید بیشتر از صفر باشد');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $cc = pb_cc_check($pdo, $cid, $p['cost_center_uid'] ?? null);
    // مساعده + سند حسابداری در یک تراکنش: خطای ثبت سند نباید ردیف مساعدهٔ بدون سند به‌جا بگذارد
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO employee_advances (customer_id, uid, employee_id, adv_date, amount, ccy, note, cost_center_uid) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, (int)$e['id'], $date, round($amt, 2), $e['ccy'], str_or_null($p['note'] ?? null, 500), $cc]);
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'payroll_adv', (int)$pdo->lastInsertId()); // S16.T16.5b2 (gold)
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    return ['ok' => true];
}

/** payroll.run_create {year, month, ccy?} — one draft per (year, month, ccy); lines for all active employees in that currency. */
function pb_pay_run_create(PDO $pdo, int $cid, array $p, bool $statOn = false): array {
    $year = (int)($p['year'] ?? 0); $month = (int)($p['month'] ?? 0);
    if ($year < 1300 || $year > 1600 || $month < 1 || $month > 12) bad('سال یا ماه نامعتبر است');
    $prm = $statOn ? pb_pay_params_required($pdo, $cid, $year) : null;
    $ccy = str_or_null($p['ccy'] ?? 'IRR', 8) ?? 'IRR';
    $ex = $pdo->prepare('SELECT id FROM payroll_runs WHERE customer_id=? AND year=? AND month=? AND ccy=?');
    $ex->execute([$cid, $year, $month, $ccy]);
    if ($ex->fetchColumn()) bad('دورهٔ حقوق این ماه قبلاً ایجاد شده است');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO payroll_runs (customer_id, uid, year, month, ccy, status) VALUES (?,?,?,?,?,?)')
            ->execute([$cid, str_or_null($p['uid'] ?? null, 36), $year, $month, $ccy, 'draft']);
        $runId = (int)$pdo->lastInsertId();
        $es = $pdo->prepare('SELECT * FROM employees WHERE customer_id=? AND active=1 AND ccy=? ORDER BY id');
        $es->execute([$cid, $ccy]);
        foreach ($es->fetchAll() as $e) {
            pb_pay_store_line($pdo, $cid, $runId, (int)$e['id'], $ccy, pb_pay_compute_line($pdo, $cid, $e, 0.0, 0.0, $ccy, $prm));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_pay_run_get($pdo, $cid, ['id' => $runId]);
}

/** payroll.line_update {line_id, overtime?, other_deductions?} — draft only. */
function pb_pay_line_update(PDO $pdo, int $cid, array $p, bool $statOn = false): array {
    $st = $pdo->prepare('SELECT l.*, r.status, r.year FROM payroll_lines l JOIN payroll_runs r ON r.id=l.run_id AND r.customer_id=l.customer_id WHERE l.id=? AND l.customer_id=?');
    $st->execute([(int)($p['line_id'] ?? 0), $cid]);
    $l = $st->fetch();
    if (!$l) bad('ردیف حقوق یافت نشد', 404);
    pb_pay_assert_draft(['status' => $l['status']]);
    $ot = num($p['overtime'] ?? $l['overtime']);
    $od = num($p['other_deductions'] ?? $l['other_deductions']);
    if ($ot < 0 || $od < 0 || $ot > 1e13 || $od > 1e13) bad('مبلغ نامعتبر است');
    $emp = pb_pay_employee($pdo, $cid, (int)$l['employee_id']);
    $prm = $statOn ? pb_pay_params_required($pdo, $cid, (int)$l['year']) : null;
    pb_pay_store_line($pdo, $cid, (int)$l['run_id'], (int)$l['employee_id'], $l['ccy'], pb_pay_compute_line($pdo, $cid, $emp, $ot, $od, $l['ccy'], $prm));
    return pb_pay_run_get($pdo, $cid, ['id' => (int)$l['run_id']]);
}

/** payroll.approve {run_id} — draft → approved; consumes advances (oldest first) up to each line's earnings − deductions. */
function pb_pay_approve(PDO $pdo, int $cid, array $p, bool $statOn = false): array {
    $runId = (int)($p['run_id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM payroll_runs WHERE id=? AND customer_id=? FOR UPDATE');
        $st->execute([$runId, $cid]);
        $run = $st->fetch();
        if (!$run) bad('دورهٔ حقوق یافت نشد', 404);
        pb_pay_assert_draft($run);
        $prm = $statOn ? pb_pay_params_required($pdo, $cid, (int)$run['year']) : null;
        $ls = $pdo->prepare('SELECT * FROM payroll_lines WHERE customer_id=? AND run_id=? ORDER BY id');
        $ls->execute([$cid, $runId]);
        $upAdv = $pdo->prepare('UPDATE employee_advances SET deducted = deducted + ? WHERE id=? AND customer_id=?');
        foreach ($ls->fetchAll() as $l) {
            $emp = pb_pay_employee($pdo, $cid, (int)$l['employee_id']);
            // recompute with the same inputs (balances may have changed since the draft), then consume
            $v = pb_pay_compute_line($pdo, $cid, $emp, (float)$l['overtime'], (float)$l['other_deductions'], $run['ccy'], $prm);
            $need = $v['advances_deducted'];
            foreach (pb_pay_advance_rows($pdo, $cid, (int)$emp['id'], $run['ccy']) as $a) {
                if ($need <= 0.0) break;
                $take = round(min($need, (float)$a['amount'] - (float)$a['deducted']), 2);
                if ($take <= 0) continue;
                $upAdv->execute([$take, (int)$a['id'], $cid]);
                $need = round($need - $take, 2);
            }
            pb_pay_store_line($pdo, $cid, $runId, (int)$emp['id'], $run['ccy'], $v);
        }
        $pdo->prepare("UPDATE payroll_runs SET status='approved', approved_at=? WHERE id=? AND customer_id=?")->execute([date('Y-m-d H:i:s'), $runId, $cid]); // تاریخ سند حقوق از approved_at ⇒ ساعت تهران
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'payroll', $runId); // S16.T16.5b2 (gold)
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_pay_run_get($pdo, $cid, ['id' => $runId]);
}

/** payroll.run_delete {run_id} — drafts only. */
function pb_pay_run_delete(PDO $pdo, int $cid, array $p): array {
    $run = pb_pay_run($pdo, $cid, (int)($p['run_id'] ?? 0));
    pb_pay_assert_draft($run);
    $pdo->prepare('DELETE FROM payroll_line_items WHERE customer_id=? AND line_id IN (SELECT id FROM payroll_lines WHERE customer_id=? AND run_id=?)')->execute([$cid, $cid, (int)$run['id']]);
    $pdo->prepare('DELETE FROM payroll_lines WHERE customer_id=? AND run_id=?')->execute([$cid, (int)$run['id']]);
    $pdo->prepare('DELETE FROM payroll_runs WHERE id=? AND customer_id=?')->execute([(int)$run['id'], $cid]);
    return ['ok' => true];
}

/** payroll.run_get {run_id} — run header, lines with items, totals (earnings, deductions, advances, net). */
function pb_pay_run_get(PDO $pdo, int $cid, array $p): array {
    $run = pb_pay_run($pdo, $cid, (int)($p['id'] ?? $p['run_id'] ?? 0));
    $ls = $pdo->prepare('SELECT l.*, e.name AS employee, s.fy AS stat_fy, s.insurable_base, s.taxable_base, s.ins_employee, s.ins_employer, s.exemption, s.taxable, s.tax FROM payroll_lines l JOIN employees e ON e.id=l.employee_id AND e.customer_id=l.customer_id LEFT JOIN payroll_line_stat s ON s.line_id=l.id AND s.customer_id=l.customer_id WHERE l.customer_id=? AND l.run_id=? ORDER BY e.name, l.id');
    $ls->execute([$cid, (int)$run['id']]);
    $it = $pdo->prepare('SELECT name, kind, amount FROM payroll_line_items WHERE customer_id=? AND line_id=? ORDER BY id');
    $lines = []; $tot = ['earnings' => 0.0, 'deductions' => 0.0, 'advances_deducted' => 0.0, 'net' => 0.0];
    foreach ($ls->fetchAll() as $l) {
        $it->execute([$cid, (int)$l['id']]);
        $items = [];
        foreach ($it->fetchAll() as $x) $items[] = ['name' => $x['name'], 'kind' => $x['kind'], 'amount' => (float)$x['amount']];
        $row = ['id' => (int)$l['id'], 'employee_id' => (int)$l['employee_id'], 'employee' => $l['employee'], 'base' => (float)$l['base'],
            'overtime' => (float)$l['overtime'], 'allowances' => (float)$l['allowances'], 'earnings' => (float)$l['earnings'],
            'deductions' => (float)$l['deductions'], 'other_deductions' => (float)$l['other_deductions'],
            'advances_deducted' => (float)$l['advances_deducted'], 'net' => (float)$l['net'], 'items' => $items,
            'statutory' => $l['stat_fy'] === null ? null : ['fy' => (int)$l['stat_fy'], 'insurable_base' => (float)$l['insurable_base'], 'taxable_base' => (float)$l['taxable_base'],
                'ins_employee' => (float)$l['ins_employee'], 'ins_employer' => (float)$l['ins_employer'], 'exemption' => (float)$l['exemption'], 'taxable' => (float)$l['taxable'], 'tax' => (float)$l['tax']]];
        $lines[] = $row;
        foreach (['earnings', 'deductions', 'advances_deducted', 'net'] as $k) $tot[$k] += $row[$k];
        // exact: net = earnings − deductions − advances
        if (round($row['earnings'] - $row['deductions'] - $row['advances_deducted'] - $row['net'], 2) != 0.0) bad('ناسازگاری در محاسبهٔ خالص ردیف ' . $l['employee']);
    }
    foreach ($tot as $k => $v) $tot[$k] = round($v, 2);
    return ['id' => (int)$run['id'], 'year' => (int)$run['year'], 'month' => (int)$run['month'], 'ccy' => $run['ccy'], 'status' => $run['status'],
        'approved_at' => $run['approved_at'], 'lines' => $lines, 'totals' => $tot, 'editable' => $run['status'] === 'draft'];
}

/** payroll.runs {} */
function pb_pay_runs(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT r.*, (SELECT COUNT(*) FROM payroll_lines l WHERE l.customer_id=r.customer_id AND l.run_id=r.id) AS employees FROM payroll_runs r WHERE r.customer_id=? ORDER BY r.year DESC, r.month DESC, r.id DESC LIMIT 200');
    $st->execute([$cid]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['id' => (int)$r['id'], 'year' => (int)$r['year'], 'month' => (int)$r['month'], 'ccy' => $r['ccy'],
        'status' => $r['status'], 'employees' => (int)$r['employees']];
    return ['items' => $items];
}

/* ===================== S11.T11.2 — payment lines (multi-currency, multi-due) ===================== */

/**
 * payroll_pay_lines: a payment schedule for one approved payroll line.
 *  - amount: paid in `ccy` (may differ from the run's currency).
 *  - net_part: the same payment expressed in the RUN currency (entered by the user; no FX rate source is assumed).
 *    Σ net_part over a line can never exceed the line's net, and it is exact to 2 decimals.
 *  - When ccy = run ccy, amount must equal net_part.
 *  Each row is a due (سررسید) with due_date, shown in dues.list (kind 'حقوق'), and paid via dues.mark_paid.
 */
function pb_pay_lines_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS payroll_pay_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        line_id INT UNSIGNED NOT NULL,
        ccy VARCHAR(8) NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        net_part DECIMAL(18,2) NOT NULL,
        due_date DATE NOT NULL,
        method VARCHAR(10) NOT NULL DEFAULT 'transfer',
        cheque_ref VARCHAR(60) NULL,
        paid TINYINT NOT NULL DEFAULT 0,
        paid_date DATE NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ppl_uid (customer_id, uid),
        KEY idx_ppl_line (customer_id, line_id),
        KEY idx_ppl_due (customer_id, paid, due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

const PB_PAY_METHODS = ['cash', 'transfer', 'cheque'];

/** payroll.pay_line_add {line_id, ccy, amount, net_part, due_date, method, cheque_ref?, uid?} */
function pb_pay_line_add(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM payroll_pay_lines WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        if ($ex->fetchColumn()) return ['ok' => true];
    }
    $lineId = (int)($p['line_id'] ?? 0);
    $amount = num($p['amount'] ?? 0);
    $netPart = num($p['net_part'] ?? 0);
    if ($amount <= 0 || $netPart <= 0 || $amount > 1e13 || $netPart > 1e13) bad('مبلغ پرداخت باید بیشتر از صفر باشد');
    $amount = round($amount, 2); $netPart = round($netPart, 2);
    $due = ($p['due_date'] ?? '') !== '' ? vdate($p['due_date']) : null;
    if ($due === null) bad('تاریخ سررسید را وارد کنید');
    $method = (string)($p['method'] ?? 'transfer');
    if (!in_array($method, PB_PAY_METHODS, true)) bad('روش پرداخت نامعتبر است');
    $ccy = str_or_null($p['ccy'] ?? null, 8);
    if ($ccy === null) bad('واحد پول پرداخت را وارد کنید');

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT l.id, l.net, l.ccy AS run_ccy, r.status FROM payroll_lines l JOIN payroll_runs r ON r.id=l.run_id AND r.customer_id=l.customer_id WHERE l.id=? AND l.customer_id=? FOR UPDATE');
        $st->execute([$lineId, $cid]);
        $l = $st->fetch();
        if (!$l) { $pdo->rollBack(); bad('ردیف حقوق یافت نشد', 404); }
        if ($l['status'] !== 'approved') { $pdo->rollBack(); bad('ابتدا دوره را تأیید کنید؛ زمان‌بندی پرداخت فقط برای دورهٔ تأییدشده ممکن است'); }
        if ($ccy === $l['run_ccy'] && round($amount - $netPart, 2) != 0.0) { $pdo->rollBack(); bad('برای واحد پول دوره، مبلغ پرداخت و معادل آن باید یکسان باشند'); }
        $sumSt = $pdo->prepare('SELECT COALESCE(SUM(net_part),0) FROM payroll_pay_lines WHERE customer_id=? AND line_id=?');
        $sumSt->execute([$cid, $lineId]);
        $scheduled = round((float)$sumSt->fetchColumn(), 2);
        if (round($scheduled + $netPart - (float)$l['net'], 2) > 0.0) { $pdo->rollBack(); bad('مجموع پرداخت‌ها از خالص حقوق بیشتر می‌شود (باقی‌مانده: ' . number_format(round((float)$l['net'] - $scheduled, 2), 2) . ')'); }
        $pdo->prepare('INSERT INTO payroll_pay_lines (customer_id, uid, line_id, ccy, amount, net_part, due_date, method, cheque_ref, paid) VALUES (?,?,?,?,?,?,?,?,?,0)')
            ->execute([$cid, $uid, $lineId, $ccy, $amount, $netPart, $due, $method, str_or_null($p['cheque_ref'] ?? null, 60)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}

/** payroll.pay_line_delete {id} — unpaid rows only. */
function pb_pay_line_delete(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT paid FROM payroll_pay_lines WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $row = $st->fetch();
    if (!$row) bad('پرداخت یافت نشد', 404);
    if ((int)$row['paid'] === 1) bad('پرداخت انجام‌شده قابل حذف نیست');
    $pdo->prepare('DELETE FROM payroll_pay_lines WHERE id=? AND customer_id=?')->execute([(int)($p['id'] ?? 0), $cid]);
    return ['ok' => true];
}

/** payroll.pay_lines {run_id} — for every line of the run: net, scheduled, paid, remaining, and its payment rows. */
function pb_pay_lines_for_run(PDO $pdo, int $cid, array $p): array {
    $run = pb_pay_run($pdo, $cid, (int)($p['run_id'] ?? 0));
    $ls = $pdo->prepare('SELECT l.id, l.net, e.name AS employee FROM payroll_lines l JOIN employees e ON e.id=l.employee_id AND e.customer_id=l.customer_id WHERE l.customer_id=? AND l.run_id=? ORDER BY e.name, l.id');
    $ls->execute([$cid, (int)$run['id']]);
    $ps = $pdo->prepare('SELECT id, ccy, amount, net_part, due_date, method, cheque_ref, paid, paid_date FROM payroll_pay_lines WHERE customer_id=? AND line_id=? ORDER BY due_date, id');
    $items = [];
    foreach ($ls->fetchAll() as $l) {
        $ps->execute([$cid, (int)$l['id']]);
        $rows = []; $sched = 0.0; $paid = 0.0;
        foreach ($ps->fetchAll() as $r) {
            $rows[] = ['id' => (int)$r['id'], 'ccy' => $r['ccy'], 'amount' => (float)$r['amount'], 'net_part' => (float)$r['net_part'],
                'due_date' => $r['due_date'], 'method' => $r['method'], 'cheque_ref' => $r['cheque_ref'], 'paid' => (int)$r['paid'], 'paid_date' => $r['paid_date']];
            $sched += (float)$r['net_part']; if ((int)$r['paid'] === 1) $paid += (float)$r['net_part'];
        }
        $net = (float)$l['net'];
        $items[] = ['line_id' => (int)$l['id'], 'employee' => $l['employee'], 'net' => $net, 'scheduled' => round($sched, 2),
            'paid' => round($paid, 2), 'remaining' => round($net - $sched, 2), 'rows' => $rows];
    }
    return ['run_id' => (int)$run['id'], 'ccy' => $run['ccy'], 'status' => $run['status'], 'items' => $items];
}

/** payroll.pay_unpaid {} — unpaid payment rows across all approved runs (the unpaid-dues report). */
function pb_pay_unpaid(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT x.id, x.ccy, x.amount, x.net_part, x.due_date, x.method, x.cheque_ref, e.name AS employee, r.year, r.month, r.ccy AS run_ccy
        FROM payroll_pay_lines x JOIN payroll_lines l ON l.id=x.line_id AND l.customer_id=x.customer_id JOIN payroll_runs r ON r.id=l.run_id AND r.customer_id=l.customer_id
        JOIN employees e ON e.id=l.employee_id AND e.customer_id=l.customer_id WHERE x.customer_id=? AND x.paid=0 ORDER BY x.due_date, x.id');
    $st->execute([$cid]);
    $items = []; $tot = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = ['id' => (int)$r['id'], 'employee' => $r['employee'], 'year' => (int)$r['year'], 'month' => (int)$r['month'], 'run_ccy' => $r['run_ccy'],
            'ccy' => $r['ccy'], 'amount' => (float)$r['amount'], 'net_part' => (float)$r['net_part'], 'due_date' => $r['due_date'], 'method' => $r['method'], 'cheque_ref' => $r['cheque_ref']];
        $tot[$r['run_ccy']] = round(($tot[$r['run_ccy']] ?? 0) + (float)$r['net_part'], 2);
    }
    return ['items' => $items, 'totals_by_run_ccy' => $tot];
}

/** payroll.statement {employee_id} — per approved run: net, scheduled, paid, outstanding; totals per currency. */
function pb_pay_statement(PDO $pdo, int $cid, array $p): array {
    $e = pb_pay_employee($pdo, $cid, (int)($p['employee_id'] ?? 0));
    $st = $pdo->prepare("SELECT l.id, l.net, r.year, r.month, r.ccy,
        COALESCE((SELECT SUM(x.net_part) FROM payroll_pay_lines x WHERE x.customer_id=l.customer_id AND x.line_id=l.id),0) AS scheduled,
        COALESCE((SELECT SUM(x.net_part) FROM payroll_pay_lines x WHERE x.customer_id=l.customer_id AND x.line_id=l.id AND x.paid=1),0) AS paid
        FROM payroll_lines l JOIN payroll_runs r ON r.id=l.run_id AND r.customer_id=l.customer_id
        WHERE l.customer_id=? AND l.employee_id=? AND r.status='approved' ORDER BY r.year, r.month, l.id");
    $st->execute([$cid, (int)$e['id']]);
    $rows = []; $tot = [];
    foreach ($st->fetchAll() as $r) {
        $net = (float)$r['net']; $paid = round((float)$r['paid'], 2);
        $rows[] = ['year' => (int)$r['year'], 'month' => (int)$r['month'], 'ccy' => $r['ccy'], 'net' => $net,
            'scheduled' => round((float)$r['scheduled'], 2), 'paid' => $paid, 'outstanding' => round($net - $paid, 2)];
        $t = &$tot[$r['ccy']];
        $t['net'] = round(($t['net'] ?? 0) + $net, 2);
        $t['paid'] = round(($t['paid'] ?? 0) + $paid, 2);
        $t['outstanding'] = round(($t['outstanding'] ?? 0) + $net - $paid, 2);
        unset($t);
    }
    return ['employee_id' => (int)$e['id'], 'employee' => $e['name'], 'rows' => $rows, 'totals_by_ccy' => $tot];
}


/* ===================== S11.T11.3 — statutory parameters (configurable, per FY) + payslip ===================== */

/**
 * payroll_params: per customer and fiscal year (the run's Persian year). NO defaults are seeded: every rate is entered by the user
 * from the current official rules, with its source and date. Statutory lines are produced only when the edition has
 * gold.payroll_law AND the year's row exists and is complete (else the run is refused with a clear message).
 *  - employee_ins_rate / employer_ins_rate: % of the insurable base (base + insurable allowances + overtime)
 *  - exemption: monthly amount deducted from the taxable base before the bracket table
 *  - tax_brackets: JSON [{"upto": number|null, "rate": %}] ascending; the last bracket has upto = null
 *  - overtime_multiplier, min_wage, unemployment_share_rate: stored for later use (overtime hours are not computed yet)
 */
function pb_pay_params_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS payroll_params (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        fy SMALLINT UNSIGNED NOT NULL,
        employee_ins_rate DECIMAL(9,4) NULL,
        employer_ins_rate DECIMAL(9,4) NULL,
        unemployment_share_rate DECIMAL(9,4) NULL,
        overtime_multiplier DECIMAL(9,4) NULL,
        min_wage DECIMAL(18,2) NULL,
        exemption DECIMAL(18,2) NOT NULL DEFAULT 0,
        tax_brackets TEXT NULL,
        source VARCHAR(255) NULL,
        source_date DATE NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pparams_fy (customer_id, fy)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS payroll_line_stat (
        customer_id INT UNSIGNED NOT NULL,
        line_id INT UNSIGNED NOT NULL,
        fy SMALLINT UNSIGNED NOT NULL,
        insurable_base DECIMAL(18,2) NOT NULL,
        taxable_base DECIMAL(18,2) NOT NULL,
        ins_employee DECIMAL(18,2) NOT NULL,
        ins_employer DECIMAL(18,2) NOT NULL,
        exemption DECIMAL(18,2) NOT NULL,
        taxable DECIMAL(18,2) NOT NULL,
        tax DECIMAL(18,2) NOT NULL,
        PRIMARY KEY (customer_id, line_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Pure statutory computation (no I/O). Insurance: employee share of the insurable base. Tax: progressive brackets
 * over (taxable base − employee insurance − exemption), monthly. Employer insurance is informational (employer cost).
 * Returns rounded amounts (2 dp). Mirrored in tests/payroll_oracle.py.
 */
function pb_pay_statutory(array $prm, float $insBase, float $taxBase): array {
    $insEmp = round($insBase * (float)$prm['employee_ins_rate'] / 100, 2);
    $insEr = round($insBase * (float)$prm['employer_ins_rate'] / 100, 2);
    $exemption = round((float)$prm['exemption'], 2);
    $taxable = round(max(0.0, $taxBase - $insEmp - $exemption), 2);
    $tax = 0.0; $lower = 0.0;
    foreach ($prm['tax_brackets'] as $b) {
        $upto = $b['upto'];
        $top = $upto === null ? $taxable : min($taxable, (float)$upto);
        if ($top > $lower) { $tax += ($top - $lower) * (float)$b['rate'] / 100; $lower = $top; }
        if ($upto === null || $taxable <= (float)$upto) break;
    }
    return ['insurable_base' => round($insBase, 2), 'taxable_base' => round($taxBase, 2), 'ins_employee' => $insEmp, 'ins_employer' => $insEr,
        'exemption' => $exemption, 'taxable' => $taxable, 'tax' => round($tax, 2)];
}

/** Returns the year's params as an array, or null when none is saved. */
function pb_pay_params_get(PDO $pdo, int $cid, int $fy): ?array {
    $st = $pdo->prepare('SELECT * FROM payroll_params WHERE customer_id=? AND fy=?');
    $st->execute([$cid, $fy]);
    $r = $st->fetch();
    if (!$r) return null;
    return ['fy' => (int)$r['fy'], 'employee_ins_rate' => $r['employee_ins_rate'] === null ? null : (float)$r['employee_ins_rate'],
        'employer_ins_rate' => $r['employer_ins_rate'] === null ? null : (float)$r['employer_ins_rate'],
        'unemployment_share_rate' => $r['unemployment_share_rate'] === null ? null : (float)$r['unemployment_share_rate'],
        'overtime_multiplier' => $r['overtime_multiplier'] === null ? null : (float)$r['overtime_multiplier'],
        'min_wage' => $r['min_wage'] === null ? null : (float)$r['min_wage'], 'exemption' => (float)$r['exemption'],
        'tax_brackets' => $r['tax_brackets'] === null ? null : json_decode($r['tax_brackets'], true),
        'source' => $r['source'], 'source_date' => $r['source_date']];
}

/** For a statutory run: the year's params, which must exist and be complete (refuses the run otherwise). */
function pb_pay_params_required(PDO $pdo, int $cid, int $fy): array {
    $prm = pb_pay_params_get($pdo, $cid, $fy);
    if ($prm === null) bad('پارامترهای قانونی سال ' . $fy . ' تعریف نشده است؛ ابتدا در تنظیمات حقوق ثبت کنید');
    if ($prm['employee_ins_rate'] === null || $prm['employer_ins_rate'] === null || !is_array($prm['tax_brackets']) || !$prm['tax_brackets'])
        bad('پارامترهای قانونی سال ' . $fy . ' ناقص است (نرخ بیمه یا جدول مالیات)');
    return $prm;
}

/** payroll.params_save {fy, employee_ins_rate, employer_ins_rate, unemployment_share_rate?, overtime_multiplier?, min_wage?, exemption?, tax_brackets [{upto,rate}], source, source_date} */
function pb_pay_params_save(PDO $pdo, int $cid, array $p): array {
    $fy = (int)($p['fy'] ?? 0);
    if ($fy < 1300 || $fy > 1600) bad('سال مالی نامعتبر است');
    $pct = function ($v, string $label, bool $req) {
        if ($v === null || $v === '') { if ($req) bad($label . ' را وارد کنید'); return null; }
        $n = num($v);
        if ($n < 0 || $n > 100) bad($label . ' باید بین ۰ و ۱۰۰ باشد');
        return round($n, 4);
    };
    $empR = $pct($p['employee_ins_rate'] ?? null, 'نرخ بیمهٔ کارمند', true);
    $erR = $pct($p['employer_ins_rate'] ?? null, 'نرخ بیمهٔ کارفرما', true);
    $unemp = $pct($p['unemployment_share_rate'] ?? null, 'سهم بیمه‌ی بیکاری', false);
    $otm = ($p['overtime_multiplier'] ?? '') === '' || !isset($p['overtime_multiplier']) ? null : num($p['overtime_multiplier']);
    if ($otm !== null && ($otm < 1 || $otm > 10)) bad('ضریب اضافه‌کاری نامعتبر است');
    $minW = ($p['min_wage'] ?? '') === '' || !isset($p['min_wage']) ? null : round(num($p['min_wage']), 2);
    $exempt = round(num($p['exemption'] ?? 0), 2);
    if ($exempt < 0 || $exempt > 1e13) bad('معافیت نامعتبر است');
    $br = $p['tax_brackets'] ?? null;
    if (is_string($br)) $br = json_decode($br, true);
    if (!is_array($br) || !$br) bad('جدول مالیات را وارد کنید');
    $prev = -1.0; $clean = [];
    foreach ($br as $i => $b) {
        $rate = $pct($b['rate'] ?? null, 'نرخ مالیات پلکان', true);
        $upto = $b['upto'] ?? null;
        $last = $i === count($br) - 1;
        if ($last) { if ($upto !== null && $upto !== '') bad('آخرین پلکان مالیات باید سقف نداشته باشد'); $upto = null; }
        else {
            if ($upto === null || $upto === '') bad('سقف هر پلکان (غیر از آخرین) را وارد کنید');
            $upto = round(num($upto), 2);
            if ($upto <= $prev) bad('سقف پلکان‌های مالیات باید صعودی باشد');
            $prev = $upto;
        }
        $clean[] = ['upto' => $upto, 'rate' => $rate];
    }
    $src = str_or_null($p['source'] ?? null, 255);
    if ($src === null) bad('منبع پارامترها (مثلاً نام قانون/بخشنامه) را وارد کنید');
    $sd = ($p['source_date'] ?? '') !== '' ? vdate($p['source_date']) : null;
    if ($sd === null) bad('تاریخ منبع را وارد کنید');
    $pdo->prepare('INSERT INTO payroll_params (customer_id, fy, employee_ins_rate, employer_ins_rate, unemployment_share_rate, overtime_multiplier, min_wage, exemption, tax_brackets, source, source_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE employee_ins_rate=VALUES(employee_ins_rate), employer_ins_rate=VALUES(employer_ins_rate),
        unemployment_share_rate=VALUES(unemployment_share_rate), overtime_multiplier=VALUES(overtime_multiplier), min_wage=VALUES(min_wage),
        exemption=VALUES(exemption), tax_brackets=VALUES(tax_brackets), source=VALUES(source), source_date=VALUES(source_date)')
        ->execute([$cid, $fy, $empR, $erR, $unemp, $otm, $minW, $exempt, json_encode($clean, JSON_UNESCAPED_UNICODE), $src, $sd]);
    return pb_pay_params_get($pdo, $cid, $fy);
}

/** payroll.params_get {fy} */
function pb_pay_params_view(PDO $pdo, int $cid, array $p): array {
    $fy = (int)($p['fy'] ?? 0);
    return ['fy' => $fy, 'params' => pb_pay_params_get($pdo, $cid, $fy)];
}

/** payroll.payslip {line_id} — فیش حقوقی: earnings, deductions, statutory detail, advances, net, employee and period. */
function pb_pay_payslip(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT l.*, r.year, r.month, r.ccy AS run_ccy, r.status, e.name AS employee, e.position, e.insurance_no, e.bank_account
        FROM payroll_lines l JOIN payroll_runs r ON r.id=l.run_id AND r.customer_id=l.customer_id JOIN employees e ON e.id=l.employee_id AND e.customer_id=l.customer_id
        WHERE l.id=? AND l.customer_id=?');
    $st->execute([(int)($p['line_id'] ?? 0), $cid]);
    $l = $st->fetch();
    if (!$l) bad('ردیف حقوق یافت نشد', 404);
    if ($l['status'] !== 'approved') bad('فیش فقط برای دورهٔ تأییدشده صادر می‌شود');
    $it = $pdo->prepare('SELECT name, kind, amount FROM payroll_line_items WHERE customer_id=? AND line_id=? ORDER BY id');
    $it->execute([$cid, (int)$l['id']]);
    $earn = []; $ded = [];
    foreach ($it->fetchAll() as $x) {
        $row = ['name' => $x['name'], 'amount' => (float)$x['amount']];
        if ($x['kind'] === 'earning') $earn[] = $row; else $ded[] = $row;
    }
    $earn = array_merge([['name' => 'حقوق پایه', 'amount' => (float)$l['base']]], $l['overtime'] > 0 ? [['name' => 'اضافه‌کاری', 'amount' => (float)$l['overtime']]] : [], $earn);
    if ((float)$l['advances_deducted'] > 0) $ded[] = ['name' => 'کسر مساعده', 'amount' => (float)$l['advances_deducted']];
    if ((float)$l['other_deductions'] > 0) $ded[] = ['name' => 'سایر کسورات', 'amount' => (float)$l['other_deductions']];
    $sf = $pdo->prepare('SELECT * FROM payroll_line_stat WHERE customer_id=? AND line_id=?');
    $sf->execute([$cid, (int)$l['id']]);
    $stat = $sf->fetch();
    return ['line_id' => (int)$l['id'], 'year' => (int)$l['year'], 'month' => (int)$l['month'], 'ccy' => $l['ccy'],
        'employee' => $l['employee'], 'position' => $l['position'], 'insurance_no' => $l['insurance_no'],
        'earnings' => $earn, 'deductions' => $ded, 'total_earnings' => (float)$l['earnings'], 'total_deductions' => (float)$l['deductions'] + (float)$l['advances_deducted'] + (float)$l['other_deductions'],
        'net' => (float)$l['net'],
        'statutory' => $stat ? ['fy' => (int)$stat['fy'], 'insurable_base' => (float)$stat['insurable_base'], 'taxable_base' => (float)$stat['taxable_base'],
            'ins_employee' => (float)$stat['ins_employee'], 'ins_employer' => (float)$stat['ins_employer'], 'exemption' => (float)$stat['exemption'],
            'taxable' => (float)$stat['taxable'], 'tax' => (float)$stat['tax']] : null];
}
