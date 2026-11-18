<?php
declare(strict_types=1);
/**
 * S16.T16.4 — posting loaders "B" (GOLD): cheque lifecycle events, credit installments, loans, general expenses, broker payments, owner draws.
 * DOUBLE-POSTING GUARD: payments/receipts saved WITH a purchase/sale (lump paid=1, cheque_inst/receipt_cheque_inst totals) belong to the
 * document voucher (loaders A, pb_pl_settle). Nothing here re-posts them:
 *  - cheque_ev: only STATUS CHANGES of a cheque after the document (one voucher per cheque_events row; source_id = event id).
 *  - inst_pay / inst_recv: credit installments (credit_inst / receipt_credit_inst) when marked paid (never part of the doc voucher).
 *  - A payment/receipt edited later (payments.save, receipts.save, lump paid toggle) re-posts the PARENT doc voucher (replace, not add).
 * Cash vs bank: tafsili kind of treasury_tafsili_id (default bank, as in loaders A). Units: stored toman x pb_posting_unit_mult().
 */

function pb_plb_customer(PDO $pdo, int $cid): ?array {
    static $c = [];
    if (!array_key_exists($cid, $c)) {
        $st = $pdo->prepare('SELECT * FROM customers WHERE id=?');
        $st->execute([$cid]);
        $r = $st->fetch();
        $c[$cid] = $r ?: null;
    }
    return $c[$cid];
}

function pb_plb_tid(PDO $pdo, int $cid, string $kind, ?string $ref): int {
    $ref = trim((string)$ref);
    if ($ref === '') return 0;
    $st = $pdo->prepare('SELECT id FROM tafsili WHERE customer_id=? AND kind=? AND ref_key=? LIMIT 1');
    $st->execute([$cid, $kind, mb_substr($ref, 0, 150)]);
    return (int)$st->fetchColumn();
}

/** Date of the voucher already posted for (type,id) - keeps re-posts stable when the source row has no date of its own. */
function pb_plb_voucher_date(PDO $pdo, int $cid, string $type, int $id): string {
    $st = $pdo->prepare('SELECT voucher_date FROM vouchers WHERE customer_id=? AND source_type=? AND source_id=? LIMIT 1');
    $st->execute([$cid, $type, (string)$id]);
    $d = $st->fetchColumn();
    return $d ? (string)$d : date('Y-m-d');
}

/* ---------------- cheque lifecycle ---------------- */

/** T30.3d: foreign-currency stamp for engine lines (see pb_posting_resolve). null when the row is a rial document. Carried at the document rate: no rate difference without a settlement rate. */
function pb_plb_ccy_ctx($code, $ccyAmount, int $rial): ?array {
    $c = strtoupper(trim((string)$code));
    if ($c === '' || $c === 'IRR' || $c === 'IRT' || (float)$ccyAmount <= 0 || $rial <= 0) return null;
    return ['code' => $c, 'amount' => (float)$ccyAmount, 'rial' => $rial, 'roles' => ['ar', 'ap', 'cheque_in_hand', 'cheque_collect', 'cheque_payable', 'bank', 'cash']];
}
/** T30.3e: settled rial of a foreign amount at rial-per-unit rate; null when no rate (=> carried at document rate). */
function pb_plb_settle_rial($ccyAmount, $rate): ?int {
    return ((float)$ccyAmount > 0 && (float)$rate > 0) ? (int)round((float)$ccyAmount * (float)$rate) : null;
}
/** T30.3e: foreign-currency ctx with a settlement rial on the treasury roles (bank/cash) and the signed rate difference fields. */
function pb_plb_ccy_ctx_settle($code, $ccyAmount, int $carry, ?int $settled): ?array {
    $c = pb_plb_ccy_ctx($code, $ccyAmount, $carry);
    if ($c && $settled !== null && $settled > 0) $c['rial_by_role'] = ['bank' => $settled, 'cash' => $settled];
    return $c;
}
function pb_plb_load_cheque_ev(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT e.*, c.direction, c.number, c.party, c.endorsed_to, c.source_table, c.source_id, c.amount AS c_amount, c.ccy AS c_ccy, c.ccy_amount AS c_ccy_amount
        FROM cheque_events e JOIN cheques c ON c.id=e.cheque_id AND c.customer_id=e.customer_id WHERE e.id=? AND e.customer_id=?');
    $st->execute([$id, $cid]);
    $e = $st->fetch();
    if (!$e) return null;
    $recv = (string)$e['direction'] === 'received';
    $norm = function (?string $s) { return $s === 'returned_to_payer' ? 'returned' : (string)$s; };
    $from = $norm($e['from_status'] !== null ? (string)$e['from_status'] : ($recv ? 'in_hand' : 'pending'));
    $to = $norm((string)$e['to_status']);
    if ($from === $to) return null; // initial registration: the document voucher already carries the cheque
    $f = ['amt' => pb_pl_rial($e['c_amount']), 'bank_charge' => isset($e['bank_charge']) ? pb_pl_rial($e['bank_charge']) : 0,
        'dir_recv' => $recv ? 1 : 0, 'dir_iss' => $recv ? 0 : 1];
    foreach (['in_hand', 'deposited', 'pending', 'cleared'] as $s) if ($from === $s) $f['f_' . $s] = 1;
    $f['t_' . $to] = 1;
    $treasury = pb_plb_tid($pdo, $cid, 'bank', $e['bank_ref'] ?? null);
    if ($treasury === 0 && $e['source_table'] !== null && $e['source_id'] !== null && in_array((string)$e['source_table'], ['cheque_inst', 'receipt_cheque_inst'], true)) {
        try {
            $q = $pdo->prepare('SELECT treasury_tafsili_id FROM `' . $e['source_table'] . '` WHERE id=? AND customer_id=?');
            $q->execute([(int)$e['source_id'], $cid]);
            $treasury = (int)$q->fetchColumn();
        } catch (Throwable $ex) { /* column not present yet */ }
    }
    // S30.T30.3e: clearing (or its cancellation) of a foreign cheque at the settlement rate; diff = settled - booked rial => fx_diff
    $f['bank_amt'] = $f['amt']; $f['fxd_pos'] = 0; $f['fxd_neg'] = 0; $settled = null;
    if (($to === 'cleared' || $from === 'cleared') && (float)($e['c_ccy_amount'] ?? 0) > 0) {
        $sr = $to === 'cleared' ? ($e['settle_rate'] ?? null) : null;
        if ($from === 'cleared') { // cancellation reverses at the rate of the clearing it undoes
            try {
                $q = $pdo->prepare("SELECT settle_rate FROM cheque_events WHERE customer_id=? AND cheque_id=? AND to_status='cleared' AND id<? ORDER BY id DESC LIMIT 1");
                $q->execute([$cid, (int)$e['cheque_id'], $id]);
                $v = $q->fetchColumn();
                $sr = $v === false ? null : $v;
            } catch (Throwable $ex) { $sr = null; }
        }
        $settled = pb_plb_settle_rial($e['c_ccy_amount'], $sr);
        if ($settled !== null) { $f['bank_amt'] = $settled; $d = $settled - $f['amt']; $f['fxd_pos'] = max(0, $d); $f['fxd_neg'] = max(0, -$d); }
    }
    return ['date' => (string)$e['event_date'], 'description' => 'چک ' . (string)($e['number'] ?? '') . ' ' . ($recv ? 'دریافتی' : 'پرداختی') . ': ' . (PB_CHEQUE_STATUS_LABELS[(string)$e['to_status']] ?? $to),
        'ccy' => pb_plb_ccy_ctx_settle($e['c_ccy'] ?? null, $e['c_ccy_amount'] ?? null, $f['amt'], $settled),
        'fields' => $f, 'dims' => ['party' => pb_pl_party_tid($pdo, $cid, $e['party'] ?? null), 'endorsee' => pb_pl_party_tid($pdo, $cid, $e['endorsed_to'] ?? null), 'treasury' => $treasury]];
}

/** Called by pb_cheque_event() for every event. $strict (user transition) = errors surface; otherwise (lazy sync) posting is skipped quietly when accounts are not mapped. */
function pb_plb_cheque_event_posted(PDO $pdo, int $cid, int $evId, bool $strict): void {
    $cu = pb_plb_customer($pdo, $cid);
    if (!$cu || !pb_fy_is_gold($cu)) return;
    pb_acc_seed($pdo, $cid);
    if (!$strict) {
        $miss = pb_acc_roles_missing($pdo, $cid, ['bank', 'cheque_in_hand', 'cheque_collect', 'cheque_payable', 'ar', 'ap', 'bank_charges']);
        if ($miss) { error_log('[posting] cheque event ' . $evId . ' not posted, roles missing: ' . implode(',', $miss)); return; }
    }
    pb_post_document($pdo, $cu, 'cheque_ev', $evId);
}

/* ---------------- credit installments ---------------- */

function pb_plb_load_inst(PDO $pdo, int $cid, int $id, bool $sale): ?array {
    [$tbl, $hdr, $key, $doc, $pc] = $sale ? ['receipt_credit_inst', 'receipts', 'sale_id', 'sales', 'buyer'] : ['credit_inst', 'payments', 'purchase_id', 'purchases', 'seller'];
    $idc = $sale ? 'receipt_id' : 'payment_id';
    $st = $pdo->prepare("SELECT x.*, d.$pc AS party_name FROM $tbl x JOIN $hdr h ON h.id=x.$idc AND h.customer_id=x.customer_id
        JOIN $doc d ON d.id=h.$key AND d.customer_id=x.customer_id WHERE x.id=? AND x.customer_id=?");
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r || (int)$r['paid'] !== 1) return null;
    $amt = pb_pl_rial($r['amount']);
    $tid = (int)($r['treasury_tafsili_id'] ?? 0);
    $isCash = pb_pl_taf_kind($pdo, $cid, $tid) === 'cash';
    $type = $sale ? 'inst_recv' : 'inst_pay';
    $vdate = pb_plb_voucher_date($pdo, $cid, $type, $id);
    // S30.T30.3e: foreign installment settled at the registered rate on the payment date (none registered => document rate, no diff)
    $settled = null; $ic = strtoupper(trim((string)($r['ccy'] ?? '')));
    if ($ic !== '' && $ic !== 'IRR' && $ic !== 'IRT' && (float)($r['ccy_amount'] ?? 0) > 0 && function_exists('pb_fx_rate_at')) {
        try { $settled = pb_plb_settle_rial($r['ccy_amount'], pb_fx_rate_at($pdo, $cid, $ic, $vdate)); } catch (Throwable $ex) { $settled = null; }
    }
    $cashv = $settled ?? $amt; $dd = $cashv - $amt;
    return ['date' => $vdate, 'description' => ($sale ? 'دریافت قسط ' : 'پرداخت قسط ') . (string)($r['party_name'] ?? ''),
        'fields' => ['amt' => $amt, 'cash_amt' => $isCash ? $cashv : 0, 'bank_amt' => $isCash ? 0 : $cashv, 'fxd_pos' => max(0, $dd), 'fxd_neg' => max(0, -$dd)],
        'ccy' => pb_plb_ccy_ctx_settle($r['ccy'] ?? null, $r['ccy_amount'] ?? null, $amt, $settled),
        'dims' => ['party' => pb_pl_party_tid($pdo, $cid, $r['party_name'] ?? null), 'treasury' => $tid]];
}

/** dues.mark_paid hook: installments post/unpost; lump rows re-post the parent doc; cheque rows are reconciled by the lazy register sync (events post). */
function pb_plb_mark_paid_hook(PDO $pdo, array $customer, string $table, int $id): void {
    if (!pb_fy_is_gold($customer)) return;
    $cid = (int)$customer['id'];
    if ($table === 'credit_inst' || $table === 'receipt_credit_inst') {
        $sale = $table === 'receipt_credit_inst';
        $type = $sale ? 'inst_recv' : 'inst_pay';
        $st = $pdo->prepare("SELECT paid FROM $table WHERE id=? AND customer_id=?");
        $st->execute([$id, $cid]);
        $paid = $st->fetchColumn();
        if ($paid === false || (int)$paid !== 1) pb_unpost_document($pdo, $customer, $type, $id);
        else pb_post_document($pdo, $customer, $type, $id);
    } elseif ($table === 'lump_tx' || $table === 'receipt_lump_tx') {
        $sale = $table === 'receipt_lump_tx';
        $sql = $sale ? 'SELECT h.sale_id FROM receipt_lump_tx t JOIN receipts h ON h.id=t.receipt_id AND h.customer_id=t.customer_id WHERE t.id=? AND t.customer_id=?'
                     : 'SELECT h.purchase_id FROM lump_tx t JOIN payments h ON h.id=t.payment_id AND h.customer_id=t.customer_id WHERE t.id=? AND t.customer_id=?';
        $st = $pdo->prepare($sql);
        $st->execute([$id, $cid]);
        $doc = (int)$st->fetchColumn();
        if ($doc > 0) pb_post_document($pdo, $customer, $sale ? 'sale' : 'purchase', $doc); // replaces the doc voucher, never adds a second one
        if ($doc > 0) pb_post_document($pdo, $customer, $sale ? 'fx_settle_recv' : 'fx_settle_pay', $doc); // S30.T30.1
    } elseif ($table === 'cheque_inst' || $table === 'receipt_cheque_inst') {
        require_once __DIR__ . '/cheques.php';
        pb_cheque_sync($pdo, $cid); // legacy paid flag -> lifecycle event -> voucher
    }
}

/* ---------------- loans ---------------- */

function pb_plb_load_loan(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM loan_entries WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r || !in_array((string)$r['kind'], ['given', 'taken', 'repay_in', 'repay_out'], true)) return null;
    $labels = ['given' => 'قرض اعطایی به ', 'taken' => 'قرض دریافتی از ', 'repay_in' => 'بازپرداخت قرض از ', 'repay_out' => 'بازپرداخت قرض به '];
    return ['date' => (string)$r['date'], 'description' => $labels[(string)$r['kind']] . (string)$r['party'],
        'fields' => ['amt' => pb_pl_rial($r['amount']), 'k_' . (string)$r['kind'] => 1],
        'dims' => ['party' => pb_pl_party_tid($pdo, $cid, $r['party'])]];
}

/* ---------------- broker payments ---------------- */

function pb_plb_load_bpay(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM broker_payments WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) return null;
    $b = pb_broker_party($pdo, $cid, (int)$r['broker_id']);
    return ['date' => (string)$r['date'], 'description' => 'پرداخت کارمزد به دلال ' . ($b ? (string)$b['name'] : ''),
        'fields' => ['amt' => pb_pl_rial($r['amount'])], 'dims' => ['broker' => pb_pl_party_tid($pdo, $cid, $b ? (string)$b['name'] : null)]];
}

/* ---------------- general expenses + owner draws (entities) ---------------- */

function pb_plb_treasury_dim(PDO $pdo, int $cid, string $payer, $cashUid, $bankUid): int {
    if ($payer === 'cash') return pb_plb_tid($pdo, $cid, 'cash', $cashUid === null ? null : (string)$cashUid);
    if ($payer === 'bank') return pb_plb_tid($pdo, $cid, 'bank', $bankUid === null ? null : (string)$bankUid);
    return 0;
}

function pb_plb_load_expense(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM ent_expenses WHERE id=? AND customer_id=? AND deleted_at IS NULL');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) return null;
    $amt = pb_pl_rial($r['amount']);
    $payer = (string)($r['payer'] ?? 'cash');
    $acc = 0;
    if (trim((string)($r['account_code'] ?? '')) !== '') {
        $q = $pdo->prepare('SELECT id FROM accounts WHERE customer_id=? AND code=? AND active=1');
        $q->execute([$cid, trim((string)$r['account_code'])]);
        $acc = (int)$q->fetchColumn();
    }
    $cc = 0;
    if (!empty($r['cost_center'])) {
        $q = $pdo->prepare('SELECT id FROM ent_cost_centers WHERE customer_id=? AND uid=?');
        $q->execute([$cid, (string)$r['cost_center']]);
        $cc = (int)$q->fetchColumn();
    }
    return ['date' => (string)$r['date'], 'description' => 'هزینه ' . (string)($r['category'] ?? '') . ' ' . (string)($r['note'] ?? ''),
        'fields' => ['amt' => $amt, 'pay_cash' => $payer === 'cash' ? $amt : 0, 'pay_bank' => $payer === 'bank' ? $amt : 0, 'pay_credit' => $payer === 'credit' ? $amt : 0],
        'dims' => ['exp_account' => $acc, 'cc' => $cc, 'party' => pb_pl_party_tid($pdo, $cid, $r['party'] ?? null),
            'treasury' => pb_plb_treasury_dim($pdo, $cid, $payer, $r['cash_box'] ?? null, $r['bank_account'] ?? null)]];
}

function pb_plb_load_owner_draw(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM ent_owner_draws WHERE id=? AND customer_id=? AND deleted_at IS NULL');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) return null;
    $amt = pb_pl_rial($r['amount']);
    $payer = (string)($r['payer'] ?? 'cash') === 'bank' ? 'bank' : 'cash';
    $draw = (string)($r['kind'] ?? 'draw') !== 'contribution';
    $f = ['draw_cash' => 0, 'draw_bank' => 0, 'contrib_cash' => 0, 'contrib_bank' => 0];
    $f[($draw ? 'draw_' : 'contrib_') . $payer] = $amt;
    return ['date' => (string)$r['date'], 'description' => ($draw ? 'برداشت صاحب سرمایه ' : 'آورده صاحب سرمایه ') . (string)($r['note'] ?? ''),
        'fields' => $f, 'dims' => ['treasury' => pb_plb_treasury_dim($pdo, $cid, $payer, $r['cash_box'] ?? null, $r['bank_account'] ?? null)]];
}

/** Entity hooks (registered in treasury.php): post after save, unpost before soft delete. */
function pb_plb_entity_after_save(PDO $pdo, int $cid, string $type, string $table, string $uid): void {
    $cu = pb_plb_customer($pdo, $cid);
    if (!$cu || !pb_fy_is_gold($cu)) return;
    $st = $pdo->prepare("SELECT id FROM `$table` WHERE customer_id=? AND uid=?");
    $st->execute([$cid, $uid]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) pb_post_document($pdo, $cu, $type, $id);
}
function pb_plb_entity_before_delete(PDO $pdo, int $cid, string $type, array $row): void {
    $cu = pb_plb_customer($pdo, $cid);
    if ($cu && pb_fy_is_gold($cu) && !empty($row['id'])) pb_unpost_document($pdo, $cu, $type, (int)$row['id']);
}
function pb_expense_after_save(PDO $pdo, int $cid, string $uid, bool $isNew, array $vals): void { pb_plb_entity_after_save($pdo, $cid, 'expense', 'ent_expenses', $uid); }
function pb_expense_before_delete(PDO $pdo, int $cid, array $row): void { pb_plb_entity_before_delete($pdo, $cid, 'expense', $row); }
function pb_draw_after_save(PDO $pdo, int $cid, string $uid, bool $isNew, array $vals): void { pb_plb_entity_after_save($pdo, $cid, 'owner_draw', 'ent_owner_draws', $uid); }
function pb_draw_before_delete(PDO $pdo, int $cid, array $row): void { pb_plb_entity_before_delete($pdo, $cid, 'owner_draw', $row); }

/** Loan helpers for app.php (numeric id of loan_entries by uid). */
function pb_plb_loan_post(PDO $pdo, array $customer, string $uid): void {
    $st = $pdo->prepare('SELECT id FROM loan_entries WHERE customer_id=? AND uid=?');
    $st->execute([(int)$customer['id'], $uid]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) pb_post_hook($pdo, $customer, 'loan', $id);
}
function pb_plb_loan_unpost(PDO $pdo, array $customer, string $uid): void {
    $st = $pdo->prepare('SELECT id FROM loan_entries WHERE customer_id=? AND uid=?');
    $st->execute([(int)$customer['id'], $uid]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) pb_unpost_hook($pdo, $customer, 'loan', $id);
}

pb_posting_register_loader('cheque_ev', 'pb_plb_load_cheque_ev');
pb_posting_register_loader('inst_pay', function (PDO $pdo, int $cid, int $id): ?array { return pb_plb_load_inst($pdo, $cid, $id, false); });
pb_posting_register_loader('inst_recv', function (PDO $pdo, int $cid, int $id): ?array { return pb_plb_load_inst($pdo, $cid, $id, true); });
pb_posting_register_loader('loan', 'pb_plb_load_loan');
pb_posting_register_loader('bpay', 'pb_plb_load_bpay');
pb_posting_register_loader('expense', 'pb_plb_load_expense');
pb_posting_register_loader('owner_draw', 'pb_plb_load_owner_draw');
