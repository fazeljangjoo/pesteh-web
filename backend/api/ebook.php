<?php
declare(strict_types=1);
/**
 * S18.T18.4 — electronic books (دفاتر الکترونیک) header + ledger-book data (GOLD, read-only). Action (perm accounting.view):
 *   reports.ebook {fiscal_year_id?, from?, to?, final_only?}
 * Returns company, fiscal year, period, status filter, journal totals (same vouchers as reports.journal), ledger book rows = trial balance level 2
 * (opening/period/closing per کل account) and check{} proving ledger period totals == journal totals == trial balance totals.
 * The journal rows themselves come from reports.journal (csv=1) so the book and the journal can never disagree.
 * Format note: official e-book layout expectation must be confirmed by the accountant (SIGNOFF_ITEMS); this is a printable PDF + Excel.
 */
require_once __DIR__ . '/trial_balance.php';

function pb_eb_report(PDO $pdo, int $cid, array $customer, array $p): array {
    $fy = pb_st_fy_basic($pdo, $cid, $customer, $p);
    $q = $p;
    if (empty($q['from'])) $q['from'] = $fy['start_date'];
    if (empty($q['to'])) $q['to'] = $fy['end_date'];
    $q['status'] = !empty($p['final_only']) ? 'final' : 'posted';
    $jr = pb_lr_journal($pdo, $cid, $customer, array_merge($q, ['limit' => 1, 'offset' => 0]));
    $tb = pb_tb_report($pdo, $cid, $customer, array_merge($q, ['level' => '2', 'columns' => 6, 'hide_zero' => 1]));
    $jt = $jr['totals'];
    $tt = $tb['totals'];
    $ok = $jt['debit'] === $tt['period_debit'] && $jt['credit'] === $tt['period_credit'] && $tb['balanced'] === true;
    return ['company' => (string)($customer['name'] ?? ''), 'fiscal_year' => $fy, 'from' => $q['from'], 'to' => $q['to'],
        'status_filter' => $q['status'], 'unit' => 'rial', 'voucher_count' => (int)$jr['count'],
        'journal_totals' => ['debit' => $jt['debit'], 'credit' => $jt['credit']], 'ledger_rows' => $tb['rows'], 'ledger_totals' => $tt,
        'check' => ['journal_equals_trial' => $ok, 'journal_balanced' => $jt['balanced'], 'trial_balanced' => $tb['balanced']]];
}

function pb_st_fy_basic(PDO $pdo, int $cid, array $customer, array $p): array {
    $fy = (int)($p['fiscal_year_id'] ?? 0);
    if ($fy > 0) { pb_fy_require($customer, $fy); $st = $pdo->prepare('SELECT id, name, start_date, end_date FROM fiscal_years WHERE id=? AND customer_id=?'); $st->execute([$fy, $cid]); }
    else { $st = $pdo->prepare('SELECT id, name, start_date, end_date FROM fiscal_years WHERE customer_id=? ORDER BY is_current DESC, start_date DESC LIMIT 1'); $st->execute([$cid]); }
    $r = $st->fetch();
    if (!$r) bad('سال مالی یافت نشد', 404);
    return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'start_date' => (string)$r['start_date'], 'end_date' => (string)$r['end_date']];
}

function pb_eb_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_acc_seed($pdo, $cid);
    pb_voucher_ensure($pdo);
    if ($action === 'reports.ebook') out(pb_eb_report($pdo, $cid, $customer, $p));
    bad('عملیات نامعتبر', 404);
}
