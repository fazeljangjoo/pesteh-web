<?php
declare(strict_types=1);
/**
 * S16.T16.5b2 — posting loaders "C4" (GOLD): payroll (payroll.php).
 *  - payroll      payroll_runs.id at approve: Dr salary_expense (earnings + employer insurance - non-statutory deductions/other deductions)
 *                 / Cr insurance_payable (employee + employer share) / Cr tax_payable / Cr emp_advance (advances deducted) / Cr salary_payable (net).
 *                 Insurance/tax come from payroll_line_stat (only exists with the statutory layer, else 0).
 *  - payroll_adv  employee_advances.id at create: Dr emp_advance / Cr cash (SIGNOFF: paid in cash).
 *  - payroll_pay  payroll_pay_lines.id when paid=1: Dr salary_payable / Cr cash (method cash) | bank (transfer) | cheque_payable (cheque), at net_part.
 * SIGNOFF: no per-employee tafsili on salary_payable (run-level voucher); fx-currency runs post nominal amounts; non-statutory deductions
 * and other deductions reduce salary expense; amounts are stored toman x unit multiplier like the other loaders.
 * Requires posting_loaders_a.php (pb_pl_rial) and posting_loaders_c1.php (pb_plc_post / pb_plc_unpost).
 */

function pb_plc4_load_payroll(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM payroll_runs WHERE id=? AND customer_id=? AND status='approved'");
    $st->execute([$id, $cid]);
    $run = $st->fetch();
    if (!$run) return null;
    $q = $pdo->prepare('SELECT l.earnings, l.deductions, l.other_deductions, l.advances_deducted, l.net,
        COALESCE(s.ins_employee,0) AS ins_emp, COALESCE(s.ins_employer,0) AS ins_er, COALESCE(s.tax,0) AS tax
        FROM payroll_lines l LEFT JOIN payroll_line_stat s ON s.line_id=l.id AND s.customer_id=l.customer_id
        WHERE l.run_id=? AND l.customer_id=?');
    $q->execute([$id, $cid]);
    $earn = 0; $insEmp = 0; $insEr = 0; $tax = 0; $adv = 0; $net = 0; $misc = 0;
    foreach ($q->fetchAll() as $r) {
        $e = pb_pl_rial($r['earnings']); $ie = pb_pl_rial($r['ins_emp']); $t = pb_pl_rial($r['tax']);
        $earn += $e; $insEmp += $ie; $insEr += pb_pl_rial($r['ins_er']); $tax += $t;
        $adv += pb_pl_rial($r['advances_deducted']); $net += pb_pl_rial($r['net']);
        $misc += pb_pl_rial($r['deductions']) - $ie - $t + pb_pl_rial($r['other_deductions']);
    }
    if ($earn <= 0) return null;
    $date = substr((string)$run['approved_at'], 0, 10);
    return ['date' => $date, 'description' => 'حقوق و دستمزد دورهٔ ' . (int)$run['year'] . '/' . (int)$run['month'],
        'fields' => ['earnings' => $earn, 'ins_emp' => $insEmp, 'ins_er' => $insEr, 'tax' => $tax, 'adv' => $adv, 'net' => $net, 'misc' => $misc], 'dims' => []];
}

function pb_plc4_load_adv(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM employee_advances WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $a = $st->fetch();
    if (!$a) return null;
    return ['date' => (string)$a['adv_date'], 'description' => 'مساعدهٔ کارمند #' . $id, 'fields' => ['amount' => pb_pl_rial($a['amount'])], 'dims' => []];
}

function pb_plc4_load_pay(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM payroll_pay_lines WHERE id=? AND customer_id=? AND paid=1');
    $st->execute([$id, $cid]);
    $p = $st->fetch();
    if (!$p) return null;
    $amt = pb_pl_rial($p['net_part']);
    $m = (string)$p['method'];
    return ['date' => (string)($p['paid_date'] ?: $p['due_date']), 'description' => 'پرداخت حقوق #' . $id,
        'fields' => ['amount' => $amt, 'cash' => $m === 'cash' ? $amt : 0, 'bank' => $m === 'transfer' ? $amt : 0, 'cheque' => $m === 'cheque' ? $amt : 0], 'dims' => []];
}

pb_posting_register_loader('payroll', 'pb_plc4_load_payroll');
pb_posting_register_loader('payroll_adv', 'pb_plc4_load_adv');
pb_posting_register_loader('payroll_pay', 'pb_plc4_load_pay');
