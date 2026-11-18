<?php
declare(strict_types=1);
/**
 * S16.T16.6 — accounting.rebuild + accounting.integrity (GOLD).
 *  accounting.rebuild   {dry?:1}  re-posts every source document of every registered posting type (same voucher ids/numbers: pb_post_document
 *                       replaces the lines of the existing voucher) and deletes auto vouchers whose document no longer produces one (orphans).
 *                       Documents in a locked fiscal year are skipped and reported. dry=1 only reports what would change.
 *  accounting.integrity read-only report: missing / mismatching / orphan auto vouchers, unbalanced vouchers, global debit=credit,
 *                       inventory valuation (warning only). cheque accounts vs register and AR/AP lines without tafsili (warnings).
 *                       NOT yet checked: AR/AP per-party balances (needs opening entry S19), VAT accounts vs VAT report (S17).
 * Source map: doc type -> [table, extra WHERE]. A type without a registered loader (fx_*, count_adj until their modules exist) is skipped.
 */

function pb_acct_sources(): array {
    return [
        'purchase' => ['purchases', 'is_mix=0'], 'sale' => ['sales', '1=1'], 'preturn' => ['purchase_returns', '1=1'], 'sreturn' => ['sale_returns', '1=1'],
        'cheque_ev' => ['cheque_events', '1=1'], 'inst_pay' => ['credit_inst', '1=1'], 'inst_recv' => ['receipt_credit_inst', '1=1'],
        'loan' => ['loan_entries', '1=1'], 'bpay' => ['broker_payments', '1=1'], 'expense' => ['ent_expenses', 'deleted_at IS NULL'],
        'owner_draw' => ['ent_owner_draws', 'deleted_at IS NULL'], 'sep' => ['separations', '1=1'],
        'proc_send' => ['proc_orders', '1=1'], 'proc_recv' => ['proc_orders', '1=1'], 'proc_toll' => ['proc_orders', '1=1'],
        'cons_in' => ['consignments', "direction='in'"], 'cons_out' => ['consignments', "direction='out'"], 'cons_sale' => ['sales', '1=1'],
        'cons_pay' => ['consignment_payments', '1=1'], 'cons_out_sold' => ['consignment_sales', '1=1'], 'cons_out_ret' => ['consignment_returns', '1=1'],
        'orch_cost' => ['orchard_costs', '1=1'], 'orch_alloc' => ['orchard_allocations', '1=1'],
        'payroll' => ['payroll_runs', "status='approved'"], 'payroll_adv' => ['employee_advances', '1=1'], 'payroll_pay' => ['payroll_pay_lines', 'paid=1'],
        'fx_settle_recv' => ['sales', 'ccy IS NOT NULL'], 'fx_settle_pay' => ['purchases', 'ccy IS NOT NULL'], 'fx_reval' => ['fx_revals', '1=1'], 'fx_reval_rev' => ['fx_revals', "(status='reversed' OR rev_status='posted')"], // S30.T30.2 (before T30.1 marker)
        'fx_adjust' => ['fx_adjustments', '1=1'], // T30.3c
        // S30.T30.1
    ];
}

/** [type => [doc ids]] for every registered type whose source table exists. */
function pb_acct_documents(PDO $pdo, int $cid, bool $all = false): array {
    $reg = pb_posting_loaders();
    $out = [];
    $boundary = ($all || !function_exists('pb_ug_boundary')) ? null : pb_ug_boundary($pdo, $cid); // S20.T20.1: history before the first gold FY has no vouchers of its own
    foreach (pb_acct_sources() as $type => [$table, $where]) {
        if (!isset($reg[$type]) || !pb_posting_has_type($type)) continue;
        try {
            $q = $pdo->prepare("SELECT id FROM `$table` WHERE customer_id=? AND ($where) ORDER BY id");
            $q->execute([$cid]);
            $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            if ($boundary !== null) $ids = array_values(array_filter($ids, static function (int $id) use ($reg, $type, $pdo, $cid, $boundary): bool {
                try { $c = $reg[$type]($pdo, $cid, $id); } catch (Throwable $e) { return true; }
                return $c === null || (string)($c['date'] ?? '') >= $boundary;
            }));
            $out[$type] = $ids;
        } catch (Throwable $e) { /* table not migrated for this customer */ }
    }
    return $out;
}

/** Expected total debit (rial) of a document, or null when it must have no voucher. */
function pb_acct_expected(PDO $pdo, int $cid, string $type, int $id): ?int {
    $ctx = pb_posting_loaders()[$type]($pdo, $cid, $id);
    if ($ctx === null) return null;
    $built = pb_posting_build(pb_posting_rules(), $type, $ctx);
    if (!$built) return null;
    $sum = 0;
    foreach ($built as $l) $sum += (int)$l['debit'];
    return $sum;
}

function pb_acct_rebuild(PDO $pdo, array $customer, array $p): array {
    $cid = (int)$customer['id'];
    $dry = !empty($p['dry']);
    pb_acc_seed($pdo, $cid);
    $posted = 0; $removed = 0; $skipped = []; $keep = [];
    foreach (pb_acct_documents($pdo, $cid) as $type => $ids) {
        foreach ($ids as $id) {
            try {
                if ($dry) { $exp = pb_acct_expected($pdo, $cid, $type, $id); if ($exp !== null) { $keep[$type . ':' . $id] = 1; $posted++; } continue; }
                $vid = pb_post_document($pdo, $customer, $type, $id);
                if ($vid > 0) { $keep[$type . ':' . $id] = 1; $posted++; }
            } catch (Throwable $e) {
                $skipped[] = ['type' => $type, 'id' => $id, 'error' => $e->getMessage()];
                $keep[$type . ':' . $id] = 1; // do not treat a failing document as orphan
            }
        }
    }
    $reg = pb_posting_loaders();
    $q = $pdo->prepare("SELECT id, source_type, source_id FROM vouchers WHERE customer_id=? AND source_type<>'manual'");
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $v) {
        if (!isset($reg[(string)$v['source_type']])) continue; // types of modules not built yet: never touch
        if (isset($keep[$v['source_type'] . ':' . $v['source_id']])) continue;
        try {
            if (!$dry) pb_unpost_document($pdo, $customer, (string)$v['source_type'], (string)$v['source_id']);
            $removed++;
        } catch (Throwable $e) { $skipped[] = ['type' => $v['source_type'], 'id' => $v['source_id'], 'error' => $e->getMessage()]; }
    }
    return ['dry' => $dry, 'posted' => $posted, 'orphans_removed' => $removed, 'skipped' => $skipped];
}

function pb_acct_integrity(PDO $pdo, array $customer, array $p): array {
    $cid = (int)$customer['id'];
    $issues = [];
    $reg = pb_posting_loaders();
    $docs = 0;
    $have = [];
    $q = $pdo->prepare("SELECT v.id, v.source_type, v.source_id, COALESCE(SUM(l.debit),0) AS d, COALESCE(SUM(l.credit),0) AS c
        FROM vouchers v LEFT JOIN voucher_lines l ON l.voucher_id=v.id AND l.customer_id=v.customer_id WHERE v.customer_id=? GROUP BY v.id, v.source_type, v.source_id");
    $q->execute([$cid]);
    $totD = 0; $totC = 0; $vouchers = 0;
    foreach ($q->fetchAll() as $v) {
        $vouchers++;
        $totD += (int)$v['d']; $totC += (int)$v['c'];
        if ((int)$v['d'] !== (int)$v['c']) $issues[] = ['code' => 'voucher_unbalanced', 'level' => 'error', 'voucher_id' => (int)$v['id'], 'detail' => 'بدهکار ' . $v['d'] . ' ≠ بستانکار ' . $v['c']];
        if ($v['source_type'] !== 'manual') $have[$v['source_type'] . ':' . $v['source_id']] = ['id' => (int)$v['id'], 'd' => (int)$v['d']];
    }
    if ($totD !== $totC) $issues[] = ['code' => 'global_unbalanced', 'level' => 'error', 'detail' => 'جمع کل بدهکار ' . $totD . ' ≠ بستانکار ' . $totC];
    $expectedKeys = [];
    foreach (pb_acct_documents($pdo, $cid) as $type => $ids) {
        foreach ($ids as $id) {
            $docs++;
            $key = $type . ':' . $id;
            try { $exp = pb_acct_expected($pdo, $cid, $type, $id); }
            catch (Throwable $e) { $issues[] = ['code' => 'loader_error', 'level' => 'error', 'source' => $key, 'detail' => $e->getMessage()]; continue; }
            if ($exp === null) continue; // must have no voucher: caught below if one exists
            $expectedKeys[$key] = 1;
            if (!isset($have[$key])) $issues[] = ['code' => 'voucher_missing', 'level' => 'error', 'source' => $key, 'detail' => 'سند خودکار این سند ثبت نشده است'];
            elseif ($have[$key]['d'] !== $exp) $issues[] = ['code' => 'voucher_mismatch', 'level' => 'error', 'source' => $key, 'voucher_id' => $have[$key]['id'], 'detail' => 'مبلغ سند ' . $have[$key]['d'] . ' ≠ مبلغ مورد انتظار ' . $exp];
        }
    }
    foreach ($have as $key => $v) {
        [$t] = explode(':', $key, 2);
        if (!isset($reg[$t])) continue; // module not built yet
        if (!isset($expectedKeys[$key])) $issues[] = ['code' => 'voucher_orphan', 'level' => 'error', 'source' => $key, 'voucher_id' => $v['id'], 'detail' => 'سند خودکار بدون سند مبدأ معتبر'];
    }
    // inventory valuation (warning only: processor/consignee stock and costing details are not all modelled here)
    $warn = null;
    try {
        $rq = $pdo->prepare("SELECT COALESCE(SUM(l.debit-l.credit),0) FROM voucher_lines l JOIN account_roles r ON r.account_id=l.account_id AND r.customer_id=l.customer_id
            JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id
            WHERE l.customer_id=? AND r.role IN ('inventory','inventory_processed') AND v.source_type NOT IN ('fy_open','fy_close')");
        $rq->execute([$cid]);
        $book = (int)$rq->fetchColumn();
        $lq = $pdo->prepare('SELECT id, remaining FROM purchases WHERE customer_id=? AND remaining>0 AND is_mix<>3');
        $lq->execute([$cid]);
        $val = 0;
        foreach ($lq->fetchAll() as $lot) {
            $l = pb_pl_lot($pdo, $cid, (int)$lot['id']);
            if ($l && $l[1] > 0) $val += pb_posting_divround(pb_posting_mul($l[0], (int)round((float)$lot['remaining'] * 1000)), $l[1]);
        }
        if ($book !== $val) { $warn = ['code' => 'inventory_valuation', 'level' => 'warning', 'detail' => 'موجودی دفتری ' . $book . ' ≠ ارزش انبار ' . $val]; $issues[] = $warn; }
    } catch (Throwable $e) { /* optional check */ }
    // cheque accounts vs cheque register (warning: cheques registered before the gold upgrade have no voucher until the opening entry, S19)
    try {
        $mult = pb_posting_unit_mult();
        $bal = static function (string $role) use ($pdo, $cid): int {
            $b = $pdo->prepare('SELECT COALESCE(SUM(l.debit-l.credit),0) FROM voucher_lines l JOIN account_roles r ON r.account_id=l.account_id AND r.customer_id=l.customer_id JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id WHERE l.customer_id=? AND r.role=? AND v.source_type NOT IN (\'fy_open\',\'fy_close\')');
            $b->execute([$cid, $role]);
            return (int)$b->fetchColumn();
        };
        foreach ([['cheque_in_hand', 'received', 'in_hand', 1], ['cheque_collect', 'received', 'deposited', 1], ['cheque_payable', 'issued', 'pending', -1]] as [$role, $dir, $st, $sign]) {
            $r = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM cheques WHERE customer_id=? AND direction=? AND status=?');
            $r->execute([$cid, $dir, $st]);
            $reg = (int)round((float)$r->fetchColumn() * $mult);
            $book = $sign * $bal($role);
            if ($book !== $reg) $issues[] = ['code' => 'cheque_register', 'level' => 'warning', 'detail' => $role . ': دفتر ' . $book . ' ≠ دفتر چک ' . $reg];
        }
        // every AR/AP line should carry a party tafsili, otherwise party balances cannot be reconciled
        $nt = $pdo->prepare("SELECT COUNT(*) FROM voucher_lines l JOIN account_roles r ON r.account_id=l.account_id AND r.customer_id=l.customer_id
            JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id WHERE l.customer_id=? AND r.role IN ('ar','ap') AND v.source_type<>'manual' AND (l.tafsili_id IS NULL OR l.tafsili_id=0)");
        $nt->execute([$cid]);
        $n = (int)$nt->fetchColumn();
        if ($n > 0) $issues[] = ['code' => 'ar_ap_no_tafsili', 'level' => 'warning', 'detail' => $n . ' ردیف حساب دریافتنی/پرداختنی بدون تفصیلی طرف‌حساب'];
    } catch (Throwable $e) { /* optional checks */ }
    // S30.T30.7: foreign-currency reconciliation. (1) every currency line is complete and its rate agrees with the rial amount
    // (rate is rial/ccy_amount, both stored rounded => tolerance of one rounding step); (2) per account x currency the currency balance
    // (sum of signed ccy_amount) must not contradict the rial balance of the same lines.
    try {
        $fq = $pdo->prepare("SELECT l.id, l.voucher_id, l.debit, l.credit, l.ccy, l.ccy_amount, l.rate FROM voucher_lines l
            WHERE l.customer_id=? AND ((l.ccy IS NOT NULL AND l.ccy<>'') OR l.ccy_amount IS NOT NULL OR l.rate IS NOT NULL)");
        $fq->execute([$cid]);
        $fxBad = 0;
        foreach ($fq->fetchAll() as $l) {
            $rial = max((int)$l['debit'], (int)$l['credit']); $ca = (float)($l['ccy_amount'] ?? 0); $rt = (float)($l['rate'] ?? 0);
            $why = null;
            if (trim((string)($l['ccy'] ?? '')) === '' || $ca <= 0 || $rt <= 0) $why = 'ردیف ارزی ناقص (ارز/مبلغ ارزی/نرخ)';
            elseif ((int)$l['debit'] > 0 && (int)$l['credit'] > 0) $why = 'ردیف ارزی هم بدهکار هم بستانکار';
            elseif (abs($ca * $rt - $rial) > $rt * 0.00005 + $ca * 0.0000005 + 1) $why = 'نرخ با مبلغ ریالی و ارزی سازگار نیست (' . $ca . ' × ' . $rt . ' ≠ ' . $rial . ')';
            if ($why !== null) {
                $fxBad++;
                if ($fxBad <= 20) $issues[] = ['code' => 'fx_line_inconsistent', 'level' => 'error', 'voucher_id' => (int)$l['voucher_id'], 'line_id' => (int)$l['id'], 'detail' => $why];
            }
        }
        if ($fxBad > 20) $issues[] = ['code' => 'fx_line_inconsistent', 'level' => 'error', 'detail' => ($fxBad - 20) . ' ردیف ارزی ناسازگار دیگر'];
        $bq = $pdo->prepare("SELECT a.code, l.ccy, SUM(CASE WHEN l.debit>0 THEN l.ccy_amount ELSE -l.ccy_amount END) AS cb, SUM(l.debit-l.credit) AS rb
            FROM voucher_lines l JOIN accounts a ON a.id=l.account_id AND a.customer_id=l.customer_id
            WHERE l.customer_id=? AND l.ccy IS NOT NULL AND l.ccy<>'' AND l.ccy_amount IS NOT NULL GROUP BY a.code, l.ccy");
        $bq->execute([$cid]);
        foreach ($bq->fetchAll() as $b) {
            $cb = round((float)$b['cb'], 4); $rb = (int)$b['rb'];
            if ($cb != 0.0 && $rb != 0 && (($cb > 0) !== ($rb > 0))) {
                $issues[] = ['code' => 'fx_balance_sign', 'level' => 'warning', 'detail' => 'حساب ' . $b['code'] . ' (' . $b['ccy'] . '): مانده ارزی ' . $cb . ' با مانده ریالی ' . $rb . ' هم‌علامت نیست'];
            }
        }
    } catch (Throwable $e) { /* optional check (S30.T30.7) */ }
    try { foreach (pb_vat_integrity($pdo, $customer) as $vi) $issues[] = $vi; } catch (Throwable $e) { /* optional check (S17.T17.2) */ }
    $errors = count(array_filter($issues, static fn($i) => $i['level'] === 'error'));
    return ['clean' => $errors === 0, 'errors' => $errors, 'warnings' => count($issues) - $errors, 'documents' => $docs, 'vouchers' => $vouchers, 'issues' => $issues,
        'not_checked' => ['ar_ap_party_balances (needs opening entry, S19)', 'vat_report_by_period (see reports.vat)']];
}

function pb_acct_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_voucher_ensure($pdo);
    if ($action === 'accounting.rebuild') out(pb_acct_rebuild($pdo, $customer, $p));
    if ($action === 'accounting.integrity') out(pb_acct_integrity($pdo, $customer, $p));
    bad('عملیات نامعتبر', 404);
}
