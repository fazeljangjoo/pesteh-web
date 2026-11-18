<?php
declare(strict_types=1);
/**
 * S35.T35.7h step 1 — mixed payment in ONE document: cash (lump) + credit installments + cheques.
 * payment.pay_type = 'mixed'; payload arrays: lump_lines (same rows as payment.lump), credit_lines and cheque_lines (same rows as payment.installments).
 * Statements are copies of savePayment()'s branches (the single-type branches are NOT changed).
 * Posting: unchanged engine (see note below). Gold gate removed in step 3.
 */

/* S35.T35.7h step 3: no separate posting needed — pb_pl_settle() reads lump_tx and cheque_inst by document (pay_type-independent), installments post when marked paid (inst_pay), the rest stays payable. Oracle scenario 012 checks it. Gold gate lifted. */

/** Inserts the three line kinds of a mixed payment under $paymentId. */
function pb_mixed_insert_lines(PDO $pdo, int $cid, int $paymentId, array $p): void {
    $lump = $pdo->prepare('INSERT INTO lump_tx (customer_id, payment_id, from_account, payer_name, to_account, seller_name, amount, date, paid) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach (payRows($p['lump_lines'] ?? []) as $t) {
        $lump->execute([$cid, $paymentId, str_or_null($t['from_account'] ?? null, 150), str_or_null($t['payer_name'] ?? null, 150),
            str_or_null($t['to_account'] ?? null, 150), str_or_null($t['seller_name'] ?? null, 150), vamount($t['amount'] ?? 0),
            vdate($t['date'] ?? null), !empty($t['paid']) ? 1 : 0]);
        pb_fx_tx_stamp($pdo, $cid, 'lump_tx', (int)$pdo->lastInsertId(), $t, (string)(vdate($t['date'] ?? null) ?: date('Y-m-d'))); // S28.T28.2
    }
    $credit = $pdo->prepare('INSERT INTO credit_inst (customer_id, payment_id, due_date, amount, from_account, payer_name, to_account, seller_name, paid) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach (payRows($p['credit_lines'] ?? []) as $t) {
        $credit->execute([$cid, $paymentId, vdateReq($t['due_date'] ?? null), vamount($t['amount'] ?? 0), str_or_null($t['from_account'] ?? null, 150),
            str_or_null($t['payer_name'] ?? null, 150), str_or_null($t['to_account'] ?? null, 150), str_or_null($t['seller_name'] ?? null, 150), !empty($t['paid']) ? 1 : 0]);
        pb_fx_tx_stamp($pdo, $cid, 'credit_inst', (int)$pdo->lastInsertId(), $t, (string)vdateReq($t['due_date'] ?? null)); // S28.T28.2
    }
    $cheque = $pdo->prepare('INSERT INTO cheque_inst (customer_id, payment_id, due_date, amount, cheque_no, bank_name, from_account, payer_name, to_account, seller_name, paid) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach (payRows($p['cheque_lines'] ?? []) as $t) {
        $sayad = pb_pay_sayad_check($pdo, $t); // S35.T35.7c
        $cheque->execute([$cid, $paymentId, vdateReq($t['due_date'] ?? null), vamount($t['amount'] ?? 0), str_or_null($t['cheque_no'] ?? null, 60),
            str_or_null($t['bank_name'] ?? null, 100), str_or_null($t['from_account'] ?? null, 150), str_or_null($t['payer_name'] ?? null, 150),
            str_or_null($t['to_account'] ?? null, 150), str_or_null($t['seller_name'] ?? null, 150), !empty($t['paid']) ? 1 : 0]);
        pb_pay_sayad_set($pdo, $cid, 'cheque_inst', (int)$pdo->lastInsertId(), $sayad); // S35.T35.7c
        pb_fx_tx_stamp($pdo, $cid, 'cheque_inst', (int)$pdo->lastInsertId(), $t, (string)vdateReq($t['due_date'] ?? null)); // S28.T28.2
    }
}
