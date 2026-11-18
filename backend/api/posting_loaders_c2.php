<?php
declare(strict_types=1);
/**
 * S16.T16.5b — posting loaders "C2" (GOLD): consignment memo accounts (B7).
 * Doc types (source_type = doc type, source_id = row id):
 *  - cons_in  consignments.id (direction in) at create: consigned-in goods are NOT our inventory (B7). Memo (انتظامی) only:
 *             Dr memo_consigned_in / Cr memo_consigned_in_contra at the agreed value of the lots.
 *             Value basis: purchase_on_sale -> agreed price per kg x net kg (the lot price); commission -> lot price is 0, so no memo voucher
 *             (quantity is still tracked in consignment_items; amount-based memo for commission consignments is a SIGNOFF item).
 * Sales of consigned lots (is_mix=3) never reach the sale loader (posting_loaders_a returns null): no COGS, no revenue here.
 * Settlement / commission income / payroll / orchard costs are NOT in this file yet (T16.5b remainder, see PROGRESS).
 * Hooks: pb_plc_post / pb_plc_unpost (posting_loaders_c1.php) inside the consignment transaction, no-op for green.
 * Requires posting_loaders_a.php (pb_pl_*), posting_loaders_b.php and posting_loaders_c1.php (pb_plc_post/unpost).
 */

/** Memo value (rial) of the consigned lots of an IN consignment; 0 when none. */
function pb_plc2_cons_in_value(PDO $pdo, int $cid, int $consId): int {
    $q = $pdo->prepare('SELECT p.price, p.net_weight FROM consignment_items ci
        JOIN purchases p ON p.id=ci.lot_id AND p.customer_id=ci.customer_id
        WHERE ci.consignment_id=? AND ci.customer_id=? AND p.is_mix=3');
    $q->execute([$consId, $cid]);
    $value = 0;
    foreach ($q->fetchAll() as $r) {
        $value += pb_pl_rial(round((float)$r['price'] * (float)$r['net_weight'], 2));
    }
    return $value;
}

function pb_plc2_load_cons_in(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM consignments WHERE id=? AND customer_id=? AND direction='in'");
    $st->execute([$id, $cid]);
    $c = $st->fetch();
    if (!$c) return null;
    $value = pb_plc2_cons_in_value($pdo, $cid, $id);
    if ($value <= 0) return null; // commission consignments: quantity only, no memo amount (SIGNOFF)
    return ['date' => (string)$c['cons_date'], 'description' => 'کالای امانی نزد ما #' . $id . ' ' . (string)$c['party'],
        'fields' => ['value' => $value], 'dims' => []];
}

pb_posting_register_loader('cons_in', 'pb_plc2_load_cons_in');

/* ---- T16.5b part 2: consignment IN settlement (sale of consigned goods, payment to owner) + consignment OUT memo ---- */
/*
 * cons_sale  sales.id (companion of 'sale', chained in pb_post_hook/pb_unpost_hook): consigned-in lots sold in that sale (B7).
 *            Dr ar buyer = gross / Cr ap owner = owed / Cr commission_income = our commission (commission mode) or margin (purchase_on_sale).
 *            purchase_on_sale sold below the agreed price: shortfall -> Dr cogs (loss). No VAT/discount on the consigned part (SIGNOFF).
 *            Receipts saved with the sale are carried here ONLY when the sale has no own goods (the plain 'sale' voucher then does not exist).
 *            Known gap: several owners in one sale -> everything is credited to the FIRST owner; sale returns of consigned lots post nothing.
 * cons_pay   consignment_payments.id: Dr ap owner / Cr cash (SIGNOFF: paid in cash).
 * cons_out   consignments.id (direction out) at create: memo of OUR goods at the consignee at lot cost (goods stay in inventory, B7).
 * cons_out_sold  consignment_sales.id / cons_out_ret  consignment_returns.id: release of that memo at the same cost.
 */

/** [gross, owed, comm, loss, owner_name] for consigned-in items of sale $saleId; null when none. */
function pb_plc2_sale_split(PDO $pdo, int $cid, int $saleId): ?array {
    $it = $pdo->prepare('SELECT purchase_id, weight, item_price FROM sale_items WHERE sale_id=? AND customer_id=?');
    $it->execute([$saleId, $cid]);
    $gross = 0; $owed = 0; $comm = 0; $loss = 0; $owner = null; $found = false;
    foreach ($it->fetchAll() as $i) {
        $l = pb_pl_lot($pdo, $cid, (int)$i['purchase_id']);
        if (!$l || $l[3] !== 3) continue;
        $cq = $pdo->prepare('SELECT cs.* FROM consignment_items ci JOIN consignments cs ON cs.id=ci.consignment_id AND cs.customer_id=ci.customer_id
            WHERE ci.lot_id=? AND ci.customer_id=? ORDER BY ci.id LIMIT 1');
        $cq->execute([(int)$i['purchase_id'], $cid]);
        $c = $cq->fetch();
        if (!$c) continue;
        $found = true;
        $w = (float)$i['weight'];
        $g = pb_pl_rial($w * (float)$i['item_price']);
        if ((string)$c['settlement_mode'] === 'purchase_on_sale') {
            $o = pb_pl_rial($w * (float)$c['agreed_price']);
            $m = $g - $o;
        } else {
            $m = $c['commission_type'] === 'per_kg' ? pb_pl_rial($w * (float)$c['commission_value']) : pb_pl_rial($g * (float)$c['commission_value'] / 100.0);
            if ($m > $g) $m = $g;
            $o = $g - $m;
        }
        $gross += $g; $owed += $o;
        if ($m >= 0) $comm += $m; else $loss += -$m;
        if ($owner === null) $owner = (string)$c['party'];
    }
    return $found ? [$gross, $owed, $comm, $loss, $owner] : null;
}

function pb_plc2_load_cons_sale(PDO $pdo, int $cid, int $id): ?array {
    $s = $pdo->prepare('SELECT * FROM sales WHERE id=? AND customer_id=?');
    $s->execute([$id, $cid]);
    $sale = $s->fetch();
    if (!$sale) return null;
    $sp = pb_plc2_sale_split($pdo, $cid, $id);
    if ($sp === null) return null;
    $own = pb_pl_load_sale($pdo, $cid, $id) !== null; // plain voucher exists => it carries the receipts
    [$cash, $bank, $chq] = $own ? [0, 0, 0] : pb_pl_settle($pdo, $cid, true, $id);
    return ['date' => (string)$sale['date'], 'description' => 'فروش کالای امانی #' . $id . ' ' . (string)($sale['buyer'] ?? ''),
        'fields' => ['gross' => $sp[0], 'owed' => $sp[1], 'comm' => $sp[2], 'loss' => $sp[3], 'recv_cash' => $cash, 'recv_bank' => $bank, 'cheque_in' => $chq],
        'dims' => ['party' => pb_pl_party_tid($pdo, $cid, $sale['buyer'] ?? null), 'owner' => pb_pl_party_tid($pdo, $cid, $sp[4])]];
}

function pb_plc2_load_cons_pay(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT p.*, c.party FROM consignment_payments p JOIN consignments c ON c.id=p.consignment_id AND c.customer_id=p.customer_id WHERE p.id=? AND p.customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) return null;
    return ['date' => (string)$r['pay_date'], 'description' => 'پرداخت به مالک امانی #' . (int)$r['consignment_id'] . ' ' . (string)$r['party'],
        'fields' => ['amount' => pb_pl_rial($r['amount'])], 'dims' => ['owner' => pb_pl_party_tid($pdo, $cid, $r['party'])]];
}

/** Cost (rial) of $kg of own lot $lot; 0 when unknown. */
function pb_plc2_out_cost(PDO $pdo, int $cid, int $lot, float $kg): int {
    $v = pb_pl_lot_value($pdo, $cid, $lot, $kg);
    return $v === null ? 0 : $v[0];
}

function pb_plc2_load_cons_out(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM consignments WHERE id=? AND customer_id=? AND direction='out'");
    $st->execute([$id, $cid]);
    $c = $st->fetch();
    if (!$c) return null;
    $q = $pdo->prepare('SELECT lot_id, kg FROM consignment_items WHERE consignment_id=? AND customer_id=?');
    $q->execute([$id, $cid]);
    $value = 0;
    foreach ($q->fetchAll() as $r) $value += pb_plc2_out_cost($pdo, $cid, (int)$r['lot_id'], (float)$r['kg']);
    if ($value <= 0) return null;
    return ['date' => (string)$c['cons_date'], 'description' => 'کالای ما نزد امانت‌گیرنده #' . $id . ' ' . (string)$c['party'],
        'fields' => ['value' => $value], 'dims' => []];
}

function pb_plc2_load_out_release(PDO $pdo, int $cid, int $id, bool $sold): ?array {
    $st = $pdo->prepare($sold ? 'SELECT lot_id, kg, sold_date AS d FROM consignment_sales WHERE id=? AND customer_id=?'
        : 'SELECT lot_id, kg, ret_date AS d FROM consignment_returns WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) return null;
    $value = pb_plc2_out_cost($pdo, $cid, (int)$r['lot_id'], (float)$r['kg']);
    if ($value <= 0) return null;
    return ['date' => (string)$r['d'], 'description' => ($sold ? 'فروش' : 'برگشت') . ' کالای امانی ارسالی #' . $id,
        'fields' => ['value' => $value], 'dims' => []];
}

pb_posting_register_loader('cons_sale', 'pb_plc2_load_cons_sale');
pb_posting_register_loader('cons_pay', 'pb_plc2_load_cons_pay');
pb_posting_register_loader('cons_out', 'pb_plc2_load_cons_out');
pb_posting_register_loader('cons_out_sold', function (PDO $pdo, int $cid, int $id): ?array { return pb_plc2_load_out_release($pdo, $cid, $id, true); });
pb_posting_register_loader('cons_out_ret', function (PDO $pdo, int $cid, int $id): ?array { return pb_plc2_load_out_release($pdo, $cid, $id, false); });
