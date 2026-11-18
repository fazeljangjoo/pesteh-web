<?php
declare(strict_types=1);
/**
 * Return credit on dues (سررسیدها). Pure function (no DB) so it can be tested from CLI: backend/tests/dues_credit_test.php
 *
 * credit(party) = Σ return value − Σ refund already paid back (never negative), per side:
 *   receivable dues <- sale returns credit, payable dues <- purchase returns credit.
 * It is applied to UNPAID cash/instalment/cheque rows of purchases and sales only (not loans, not payroll),
 * nearest due date first. A row is reduced, never split; a row fully covered disappears from the list.
 * Same algorithm as lib/return_calc.dart applyReturnCredit() in the Flutter app.
 *
 * v71: when a reduced row is marked paid, the reduction is made REAL: amount := amount - credit_used, credit_used := take
 * (un-pay restores). The consumed part is then subtracted from the pool (Σ credit_used per party/side) so the same credit
 * is never applied to the next instalment again.
 *
 * @param array $items  rows shaped like dues.list items (table,id,receivable,party,amount,date,paid,...)
 * @param array $recv   pb_sreturn_party_sums() result:  [partyKey => ['value'=>float,'refunded'=>float]]
 * @param array $pay    pb_preturn_party_sums() result
 * @param array $used   pb_dues_credit_used_sums() result: ['r'=>[party=>Σcredit_used], 'p'=>[...]] (optional)
 */
const PB_DUES_CREDIT_TABLES = ['lump_tx', 'credit_inst', 'cheque_inst', 'receipt_lump_tx', 'receipt_credit_inst', 'receipt_cheque_inst'];

/**
 * Core allocation (pure). Returns [item index => take] for every eligible unpaid row that receives credit.
 * Rows are taken nearest due date first (ties: original order).
 */
function pb_dues_credit_allocate(array $items, array $recv, array $pay, array $used = []): array {
    $pool = ['r' => [], 'p' => []];
    foreach ($recv as $k => $v) { $c = (float)$v['value'] - (float)$v['refunded'] - (float)($used['r'][(string)$k] ?? 0); if ($c > 0) $pool['r'][(string)$k] = $c; }
    foreach ($pay as $k => $v)  { $c = (float)$v['value'] - (float)$v['refunded'] - (float)($used['p'][(string)$k] ?? 0); if ($c > 0) $pool['p'][(string)$k] = $c; }
    if (!$pool['r'] && !$pool['p']) return [];
    $idx = [];
    foreach ($items as $i => $it) {
        if (in_array($it['table'] ?? '', PB_DUES_CREDIT_TABLES, true) && empty($it['paid'])) $idx[] = $i;
    }
    usort($idx, function ($a, $b) use ($items) {
        $c = strcmp((string)$items[$a]['date'], (string)$items[$b]['date']);
        return $c !== 0 ? $c : $a <=> $b;
    });
    $take = [];
    foreach ($idx as $i) {
        $side = !empty($items[$i]['receivable']) ? 'r' : 'p';
        $party = (string)$items[$i]['party'];
        $c = $pool[$side][$party] ?? 0.0;
        if ($c <= 0) continue;
        $amt = (float)$items[$i]['amount'];
        $t = min($c, $amt);
        if ($t <= 0) continue;
        $pool[$side][$party] = $c - $t;
        $take[$i] = $t;
    }
    return $take;
}

function pb_dues_apply_return_credit(array $items, array $recv, array $pay, array $used = []): array {
    $take = pb_dues_credit_allocate($items, $recv, $pay, $used);
    if (!$take) return $items;
    $out = [];
    foreach ($items as $i => $it) {
        if (isset($take[$i])) {
            $amt = (float)$it['amount'];
            $it['orig_amount'] = $amt;
            $it['return_credit'] = $take[$i];
            $it['amount'] = $amt - $take[$i];
            if ($it['amount'] < 0.5) continue; // fully covered: disappears
        }
        $out[] = $it;
    }
    return $out;
}

/** Table meta: [party column expr, join sql, receivable?, date column]. */
function pb_dues_credit_meta(): array {
    $pay = 'JOIN payments pay ON pay.id = x.payment_id AND pay.customer_id = x.customer_id JOIN purchases p ON p.id = pay.purchase_id AND p.customer_id = x.customer_id';
    $rec = 'JOIN receipts rec ON rec.id = x.receipt_id AND rec.customer_id = x.customer_id JOIN sales s ON s.id = rec.sale_id AND s.customer_id = x.customer_id';
    return [
        'lump_tx'             => ['p.seller', $pay, false, 'x.date', "pay.pay_type IN ('lump','mixed')"],
        'credit_inst'         => ['p.seller', $pay, false, 'x.due_date', "(pay.pay_type='mixed' OR (pay.pay_type='staged' AND pay.staged_type='credit'))"],
        'cheque_inst'         => ['p.seller', $pay, false, 'x.due_date', "(pay.pay_type='mixed' OR (pay.pay_type='staged' AND pay.staged_type='cheque'))"],
        'receipt_lump_tx'     => ['s.buyer', $rec, true, 'x.date', "rec.recv_type='lump'"],
        'receipt_credit_inst' => ['s.buyer', $rec, true, 'x.due_date', "rec.recv_type='staged' AND rec.staged_type='credit'"],
        'receipt_cheque_inst' => ['s.buyer', $rec, true, 'x.due_date', "rec.recv_type='staged' AND rec.staged_type='cheque'"],
    ];
}

/** Σ credit_used per party and side: ['r'=>[party=>sum], 'p'=>[party=>sum]]. */
function pb_dues_credit_used_sums(PDO $pdo, int $cid): array {
    $res = ['r' => [], 'p' => []];
    foreach (pb_dues_credit_meta() as $t => [$pcol, $join, $recv, $dcol, $cond]) {
        $st = $pdo->prepare("SELECT $pcol AS party, SUM(COALESCE(x.credit_used,0)) AS u FROM `$t` x $join WHERE x.customer_id = ? AND x.paid = 1 AND COALESCE(x.credit_used,0) > 0 AND $cond GROUP BY $pcol");
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) {
            $k = partyKey($r['party']);
            $res[$recv ? 'r' : 'p'][$k] = ($res[$recv ? 'r' : 'p'][$k] ?? 0.0) + (float)$r['u'];
        }
    }
    return $res;
}

/** Eligible UNPAID rows (the six tables) as dues.list-shaped items (table,id,receivable,party,amount,date,paid). */
function pb_dues_credit_rows(PDO $pdo, int $cid): array {
    $out = [];
    foreach (pb_dues_credit_meta() as $t => [$pcol, $join, $recv, $dcol, $cond]) {
        $st = $pdo->prepare("SELECT x.id, x.amount, $dcol AS d, $pcol AS party FROM `$t` x $join WHERE x.customer_id = ? AND (x.paid IS NULL OR x.paid <> 1) AND $cond");
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) {
            $out[] = ['table' => $t, 'id' => (int)$r['id'], 'receivable' => $recv, 'party' => partyKey($r['party']),
                'amount' => (float)$r['amount'], 'date' => (string)$r['d'], 'paid' => false];
        }
    }
    return $out;
}

/** Map "table#id" => take (credit that the unpaid row would consume right now). */
function pb_dues_credit_map(PDO $pdo, int $cid): array {
    $items = pb_dues_credit_rows($pdo, $cid);
    $take = pb_dues_credit_allocate($items, pb_sreturn_party_sums($pdo, $cid), pb_preturn_party_sums($pdo, $cid), pb_dues_credit_used_sums($pdo, $cid));
    $map = [];
    foreach ($take as $i => $t) $map[$items[$i]['table'] . '#' . $items[$i]['id']] = $t;
    return $map;
}

/** Rows' ccy_amount follows the rial amount (fx rows): amount / (fx_rate in stored unit). */
function pb_dues_credit_ccy(PDO $pdo, int $cid, array $row, float $newAmount): ?float {
    if ($row['ccy_amount'] === null) return null;
    $rate = (float)($row['fx_rate'] ?? 0);
    if ($rate > 0) {
        $unit = function_exists('pb_money_unit') ? pb_money_unit($pdo, $cid) : 'rial';
        $r = function_exists('pb_fx_rate_in_stored_unit') ? pb_fx_rate_in_stored_unit($rate, $unit) : $rate;
        if ($r > 0) return round($newAmount / $r, 4);
    }
    $old = (float)$row['amount'];
    return $old > 0 ? round((float)$row['ccy_amount'] * $newAmount / $old, 4) : (float)$row['ccy_amount'];
}

/**
 * Set paid flag of one of the six rows with return-credit accounting.
 *  paid=1 (was unpaid): take = pb_dues_credit_map()[row]; amount -= take; credit_used = take; ccy_amount follows.
 *  paid=0 (was paid with credit_used>0): amount += credit_used; credit_used = 0; ccy_amount follows.
 * Already in the requested state => no change (idempotent, never reduces twice).
 */
function pb_dues_set_paid(PDO $pdo, int $cid, string $table, int $id, bool $paid, ?array $creditMap = null): void {
    if (!in_array($table, PB_DUES_CREDIT_TABLES, true)) throw new InvalidArgumentException('bad table');
    $st = $pdo->prepare("SELECT amount, paid, ccy_amount, fx_rate, COALESCE(credit_used,0) AS cu FROM `$table` WHERE id=? AND customer_id=? FOR UPDATE");
    $st->execute([$id, $cid]);
    $row = $st->fetch();
    if (!$row) return;
    $isPaid = (int)$row['paid'] === 1;
    if ($paid === $isPaid) return;
    if ($paid) {
        $map = $creditMap ?? pb_dues_credit_map($pdo, $cid);
        $take = (float)($map[$table . '#' . $id] ?? 0.0);
        if ($take > 0.004) {
            $take = min($take, (float)$row['amount']);
            $new = round((float)$row['amount'] - $take, 2);
            $ccy = pb_dues_credit_ccy($pdo, $cid, $row, $new);
            $pdo->prepare("UPDATE `$table` SET paid=1, amount=?, credit_used=?, ccy_amount=? WHERE id=? AND customer_id=?")
                ->execute([$new, round($take, 2), $ccy, $id, $cid]);
            return;
        }
        $pdo->prepare("UPDATE `$table` SET paid=1 WHERE id=? AND customer_id=?")->execute([$id, $cid]);
        return;
    }
    $cu = (float)$row['cu'];
    if ($cu > 0.004) {
        $orig = round((float)$row['amount'] + $cu, 2);
        $ccy = pb_dues_credit_ccy($pdo, $cid, $row, $orig);
        $pdo->prepare("UPDATE `$table` SET paid=0, amount=?, credit_used=0, ccy_amount=? WHERE id=? AND customer_id=?")
            ->execute([$orig, $ccy, $id, $cid]);
        return;
    }
    $pdo->prepare("UPDATE `$table` SET paid=0 WHERE id=? AND customer_id=?")->execute([$id, $cid]);
}

/** Apply return credit to the unpaid fixed-key items of a list (assistant/reminders): items need table,id,receivable,party,amount,date,paid. */
function pb_dues_credit_adjust_items(PDO $pdo, int $cid, array $items): array {
    return pb_dues_apply_return_credit($items, pb_sreturn_party_sums($pdo, $cid), pb_preturn_party_sums($pdo, $cid), pb_dues_credit_used_sums($pdo, $cid));
}
