<?php
declare(strict_types=1);
/**
 * S16.T16.5a — posting loaders "C1" (GOLD): separation (B9) and processing (B8).
 * Doc types (source_type = doc type, source_id = row id):
 *  - sep       separations.id: inputs leave inventory (raw / processed by lot kind), outputs enter inventory_processed.
 *              Output value = inputs + labor + transport - abnormal waste (exact identity, B9/A8); normal waste is absorbed,
 *              abnormal waste (separations.abnormal_cost) is expensed to waste_loss (5102). Labor+transport are credited to cash (paid on the spot, SIGNOFF).
 *  - proc_send proc_orders (direction paid) at create: our lots go to the processor -> inventory_at_processor. Deleted with the order.
 *  - proc_recv proc_orders (paid) at receive: outputs enter inventory_processed at input cost + fee, fee payable to the processor (ap, party tafsili).
 *  - proc_toll proc_orders (received = toll) at receive: service revenue (fee + waste charge) against the customer (ar). Goods are NOT inventory (B8);
 *              memo-account tracking of consigned goods is T16.5b. Service VAT is not posted (SIGNOFF).
 * Input kind: purchases.is_mix 2 = processed, anything else raw (3 = consigned never reaches here: modules refuse it).
 * Hooks run INSIDE the document transaction (pb_plc_post / pb_plc_unpost), no-op for green.
 * Requires posting_loaders_a.php (pb_pl_*) and posting_loaders_b.php (pb_plb_customer).
 */

/** [raw_rial, proc_rial] of a list of {lot_id, cost} rows (cost = stored toman, 2 decimals). */
function pb_plc_split_inputs(PDO $pdo, int $cid, array $rows): array {
    $raw = 0; $proc = 0;
    $q = $pdo->prepare('SELECT is_mix FROM purchases WHERE id=? AND customer_id=?');
    foreach ($rows as $r) {
        $q->execute([(int)$r['lot_id'], $cid]);
        $k = $q->fetchColumn();
        $v = pb_pl_rial($r['cost'] ?? 0);
        if ($k !== false && (int)$k === 2) $proc += $v; else $raw += $v;
    }
    return [$raw, $proc];
}

function pb_plc_load_sep(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM separations WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $s = $st->fetch();
    if (!$s) return null;
    $in = $pdo->prepare('SELECT lot_id, cost FROM separation_inputs WHERE separation_id=? AND customer_id=?');
    $in->execute([$id, $cid]);
    [$raw, $proc] = pb_plc_split_inputs($pdo, $cid, $in->fetchAll());
    return ['date' => (string)$s['sep_date'], 'description' => 'جداسازی #' . $id,
        'fields' => ['in_raw' => $raw, 'in_proc' => $proc, 'labor' => pb_pl_rial($s['labor_cost'] ?? 0), 'transport' => pb_pl_rial($s['transport_cost'] ?? 0),
            'abnormal' => pb_pl_rial($s['abnormal_cost'] ?? 0)],
        'dims' => []];
}

function pb_plc_order(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM proc_orders WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $o = $st->fetch();
    return $o ?: null;
}

function pb_plc_proc_inputs(PDO $pdo, int $cid, int $id): array {
    $in = $pdo->prepare('SELECT lot_id, cost FROM proc_inputs WHERE order_id=? AND customer_id=?');
    $in->execute([$id, $cid]);
    return pb_plc_split_inputs($pdo, $cid, $in->fetchAll());
}

function pb_plc_load_proc_send(PDO $pdo, int $cid, int $id): ?array {
    $o = pb_plc_order($pdo, $cid, $id);
    if (!$o || (string)$o['direction'] !== 'paid') return null;
    [$raw, $proc] = pb_plc_proc_inputs($pdo, $cid, $id);
    return ['date' => (string)$o['order_date'], 'description' => 'ارسال به فرآوری #' . $id . ' ' . (string)$o['party'],
        'fields' => ['in_raw' => $raw, 'in_proc' => $proc], 'dims' => []];
}

function pb_plc_load_proc_recv(PDO $pdo, int $cid, int $id): ?array {
    $o = pb_plc_order($pdo, $cid, $id);
    if (!$o || (string)$o['direction'] !== 'paid' || $o['receive_date'] === null) return null;
    [$raw, $proc] = pb_plc_proc_inputs($pdo, $cid, $id);
    return ['date' => (string)$o['receive_date'], 'description' => 'دریافت از فرآوری #' . $id . ' ' . (string)$o['party'],
        'fields' => ['in_raw' => $raw, 'in_proc' => $proc, 'fee' => pb_pl_rial($o['fee_amount'] ?? 0)],
        'dims' => ['party' => pb_pl_party_tid($pdo, $cid, (string)$o['party'])]];
}

function pb_plc_load_proc_toll(PDO $pdo, int $cid, int $id): ?array {
    $o = pb_plc_order($pdo, $cid, $id);
    if (!$o || (string)$o['direction'] !== 'received' || $o['receive_date'] === null) return null;
    return ['date' => (string)$o['receive_date'], 'description' => 'کرایه فراوری امانی #' . $id . ' ' . (string)$o['party'],
        'fields' => ['charge' => pb_pl_rial($o['charge_amount'] ?? 0)],
        'dims' => ['party' => pb_pl_party_tid($pdo, $cid, (string)$o['party'])]];
}

pb_posting_register_loader('sep', 'pb_plc_load_sep');
pb_posting_register_loader('proc_send', 'pb_plc_load_proc_send');
pb_posting_register_loader('proc_recv', 'pb_plc_load_proc_recv');
pb_posting_register_loader('proc_toll', 'pb_plc_load_proc_toll');

/** Hook for separation / processing documents (call inside the document transaction). No-op for green. */
function pb_plc_post(PDO $pdo, int $cid, string $type, int $id): void {
    $cu = pb_plb_customer($pdo, $cid);
    if (!$cu || !pb_fy_is_gold($cu)) return;
    pb_acc_seed($pdo, $cid);
    pb_post_hook($pdo, $cu, $type, $id);
}

function pb_plc_unpost(PDO $pdo, int $cid, string $type, int $id): void {
    $cu = pb_plb_customer($pdo, $cid);
    if (!$cu || !pb_fy_is_gold($cu)) return;
    pb_unpost_hook($pdo, $cu, $type, $id);
}
