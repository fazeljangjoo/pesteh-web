<?php
declare(strict_types=1);
/**
 * Stock moves ledger (Z-Limit S01.T01.2).
 *
 * `stock_moves` is an append-only audit trail of every kg/sack change of a lot (purchases row). `purchases.remaining`
 * stays the cached value; `stock.reconcile` compares the two. Documents (purchase, mix, sale) are re-posted idempotently:
 * pb_stock_post_doc() builds the move set the document SHOULD have from the current DB rows, and when it differs from
 * the active moves it REVERSES them (negated rows, reversal_of) and appends the new set. Nothing is silently updated.
 *
 * Quantities are signed (+in / -out). Warehouse/ounce refer to entities by uid (NULL warehouse lot == default one).
 * Kinds used now: purchase, mix_in, mix_out, sale, adjust (backfill remainder), transfer_in/transfer_out (T01.3). Reserved:
 * sep_in/sep_out, return_in/return_out, consign_in/consign_out, proc_in/proc_out.
 *
 * T01.3 design choice: NO lot_locations table. Per-lot per-warehouse stock is DERIVED from Σ stock_moves grouped by
 * warehouse_uid (pb_stock_wh_balances). A transfer is two moves (out of A, into B) so the lot's total/`remaining` never
 * changes. Sale items / mix sources remember the warehouse they were taken from (sale_items|mix_sources.warehouse_uid,
 * NULL = lot's home warehouse = purchases.warehouse_uid); pb_stock_pick_wh() chooses/validates it.
 */

require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/brokers.php'; // S05.T05.1: PB_BROKER_COST_SQL
require_once __DIR__ . '/consignment_out.php'; // S08.T08.2: pb_consout_desired (docs cons_send/cons_back)

const PB_STOCK_EPS = 0.0005;

/** Lazy backfill: the first stock-touching request of a customer builds its ledger from history. Call OUTSIDE a transaction. */
function pb_stock_ensure(PDO $pdo, int $cid): void {
    $st = $pdo->prepare('SELECT 1 FROM stock_state WHERE customer_id=?');
    $st->execute([$cid]);
    if ($st->fetchColumn()) return;
    pb_stock_rebuild($pdo, $cid);
}

/** Drops and rebuilds the customer's ledger from purchases / mix_sources / sale_items (also used after backup.import). */
function pb_stock_rebuild(PDO $pdo, int $cid): void {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM stock_moves WHERE customer_id=?')->execute([$cid]);
        $pdo->prepare('DELETE FROM stock_state WHERE customer_id=?')->execute([$cid]);
        $lots = $pdo->prepare('SELECT id, is_mix FROM purchases WHERE customer_id=? ORDER BY date, id');
        $lots->execute([$cid]);
        foreach ($lots->fetchAll() as $l) {
            if ((int)$l['is_mix'] === 2 || (int)$l['is_mix'] === 3) continue; // separation outputs (S07) / consigned lots (S08) are posted by their own document
            pb_stock_post_doc($pdo, $cid, ((int)$l['is_mix'] === 1) ? 'mix' : 'purchase', (int)$l['id']);
        }
        $sales = $pdo->prepare('SELECT id FROM sales WHERE customer_id=? ORDER BY date, id');
        $sales->execute([$cid]);
        foreach ($sales->fetchAll() as $s) pb_stock_post_doc($pdo, $cid, 'sale', (int)$s['id']);
        $trs = $pdo->prepare('SELECT id FROM stock_transfers WHERE customer_id=? ORDER BY move_date, id');
        $trs->execute([$cid]);
        foreach ($trs->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'transfer', (int)$t['id']);
        $prs = $pdo->prepare('SELECT id FROM purchase_returns WHERE customer_id=? ORDER BY date, id'); // S04.T04.1
        $prs->execute([$cid]);
        foreach ($prs->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'preturn', (int)$t['id']);
        $srs = $pdo->prepare('SELECT id FROM sale_returns WHERE customer_id=? ORDER BY date, id'); // S04.T04.2
        $srs->execute([$cid]);
        foreach ($srs->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'sreturn', (int)$t['id']);
        try { // S08.T08.1 consignment receipts and returns to the owner
            $cns = $pdo->prepare('SELECT id FROM consignments WHERE customer_id=? ORDER BY cons_date, id');
            $cns->execute([$cid]);
            foreach ($cns->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'consign', (int)$t['id']);
            $crs = $pdo->prepare('SELECT id FROM consignment_returns WHERE customer_id=? ORDER BY ret_date, id');
            $crs->execute([$cid]);
            foreach ($crs->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'consign_ret', (int)$t['id']);
            $cns->execute([$cid]);
            foreach ($cns->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'cons_send', (int)$t['id']); // S08.T08.2 (no-op for direction 'in')
            $crs->execute([$cid]);
            foreach ($crs->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'cons_back', (int)$t['id']);
        } catch (Throwable $e) { /* tables not migrated yet */ }
        $seps = $pdo->prepare('SELECT id FROM separations WHERE customer_id=? ORDER BY sep_date, id'); // S07.T07.1
        try { $seps->execute([$cid]); foreach ($seps->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'sep', (int)$t['id']); } catch (Throwable $e) { /* table not migrated yet */ }
        $procs = $pdo->prepare('SELECT id FROM proc_orders WHERE customer_id=? ORDER BY order_date, id'); // S09.T09.1
        try { $procs->execute([$cid]); foreach ($procs->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'proc', (int)$t['id']); } catch (Throwable $e) { /* table not migrated yet */ }
        $crl = $pdo->prepare('SELECT DISTINCT run_id FROM crack_run_lots WHERE customer_id=? ORDER BY run_id'); // S5
        try { $crl->execute([$cid]); foreach ($crl->fetchAll() as $t) pb_stock_post_doc($pdo, $cid, 'crack', (int)$t['run_id']); } catch (Throwable $e) { /* table not migrated yet */ }
        // history may disagree with the cached remaining (old edits): record the difference explicitly, never hide it
        foreach (pb_stock_lot_sums($pdo, $cid) as $r) {
            $dk = (float)$r['remaining'] - (float)$r['s_kg'];
            $ds = (float)$r['small_sacks_remaining'] - (float)$r['s_small'];
            $dl = (float)$r['large_sacks_remaining'] - (float)$r['s_large'];
            if (abs($dk) > PB_STOCK_EPS || abs($ds) > PB_STOCK_EPS || abs($dl) > PB_STOCK_EPS) {
                pb_stock_insert($pdo, $cid, (int)$r['id'], pb_stock_lot_wh($pdo, $cid, (int)$r['id']), $r['ounce_uid'] ?? null,
                    'adjust', 'backfill', (int)$r['id'], $dk, $ds, $dl, null, date('Y-m-d'), null);
            }
        }
        $pdo->prepare('INSERT INTO stock_state (customer_id, built_at) VALUES (?, NOW())')->execute([$cid]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pb_stock_lot_wh(PDO $pdo, int $cid, int $lotId): string {
    $st = $pdo->prepare('SELECT warehouse_uid FROM purchases WHERE id=? AND customer_id=?');
    $st->execute([$lotId, $cid]);
    $w = $st->fetchColumn();
    return (is_string($w) && $w !== '') ? $w : pb_wh_default_uid($pdo, $cid);
}

function pb_stock_insert(PDO $pdo, int $cid, int $lot, ?string $wh, ?string $ounce, string $kind, string $docType, int $docId,
                         float $kg, float $small, float $large, ?float $cost, string $date, ?int $reversalOf): int {
    $pdo->prepare('INSERT INTO stock_moves (customer_id, lot_id, warehouse_uid, ounce_uid, kind, doc_type, doc_id, qty_kg, small_sacks, large_sacks, unit_cost, move_date, reversal_of)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $lot, $wh, $ounce, $kind, $docType, $docId, round($kg, 3), round($small, 3), round($large, 3),
                   $cost === null ? null : round($cost, 4), $date, $reversalOf]);
    return (int)$pdo->lastInsertId();
}

/** Σ moves per lot next to the cached values. */
function pb_stock_lot_sums(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT p.id, p.code, p.type_name, p.ounce_uid, p.remaining, p.small_sacks_remaining, p.large_sacks_remaining,
            COALESCE(SUM(m.qty_kg),0) AS s_kg, COALESCE(SUM(m.small_sacks),0) AS s_small, COALESCE(SUM(m.large_sacks),0) AS s_large
        FROM purchases p LEFT JOIN stock_moves m ON m.lot_id=p.id AND m.customer_id=p.customer_id
        WHERE p.customer_id=? GROUP BY p.id, p.code, p.type_name, p.ounce_uid, p.remaining, p.small_sacks_remaining, p.large_sacks_remaining');
    $st->execute([$cid]);
    return $st->fetchAll();
}

/** The move set a document should have, from the current DB rows. Each item: [lot, kind, kg, small, large, cost, date]. */
function pb_stock_desired(PDO $pdo, int $cid, string $docType, int $docId): array {
    $out = [];
    if ($docType === 'purchase' || $docType === 'mix') {
        $st = $pdo->prepare('SELECT id, date, ' . PB_BROKER_COST_SQL . ' AS price, net_weight, small_sack_count, large_sack_count, is_mix FROM purchases p WHERE p.id=? AND p.customer_id=?');
        $st->execute([$docId, $cid]);
        $p = $st->fetch();
        if (!$p) return [];
        if ((int)$p['is_mix'] === 2) return []; // separation output lot: moves belong to doc_type 'sep'
        if ((int)$p['is_mix'] === 3) return []; // consigned-in lot: moves belong to doc_type 'consign' (S08)
        $isMix = (int)$p['is_mix'] === 1;
        if ($isMix !== ($docType === 'mix')) return [];
        $out[] = [$docId, $isMix ? 'mix_in' : 'purchase', (float)$p['net_weight'], (float)$p['small_sack_count'], (float)$p['large_sack_count'], (float)$p['price'], (string)$p['date'], null];
        if ($isMix) {
            $ms = $pdo->prepare('SELECT source_purchase_id, weight, small_sacks, large_sacks, warehouse_uid FROM mix_sources WHERE mix_purchase_id=? AND customer_id=? ORDER BY id');
            $ms->execute([$docId, $cid]);
            foreach ($ms->fetchAll() as $s) {
                $cs = $pdo->prepare('SELECT ' . PB_BROKER_COST_SQL . ' FROM purchases p WHERE p.id=? AND p.customer_id=?');
                $cs->execute([(int)$s['source_purchase_id'], $cid]);
                $c = $cs->fetchColumn();
                $out[] = [(int)$s['source_purchase_id'], 'mix_out', -(float)$s['weight'], -(float)($s['small_sacks'] ?? 0), -(float)($s['large_sacks'] ?? 0),
                          $c === false ? null : (float)$c, (string)$p['date'], ($s['warehouse_uid'] ?? null) ?: null];
            }
        }
    } elseif ($docType === 'consign') { // S08.T08.1: goods of others arrive (consign_in); unit_cost NULL = not our valuation
        $cd = $pdo->prepare("SELECT cons_date FROM consignments WHERE id=? AND customer_id=? AND direction='in'"); // S08.T08.2: out-consignments post as cons_send
        $cd->execute([$docId, $cid]);
        $d = $cd->fetchColumn();
        if ($d === false) return [];
        $ci = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks FROM consignment_items WHERE consignment_id=? AND customer_id=? ORDER BY id');
        $ci->execute([$docId, $cid]);
        foreach ($ci->fetchAll() as $r) $out[] = [(int)$r['lot_id'], 'consign_in', (float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks'], null, (string)$d, null];
    } elseif ($docType === 'consign_ret') { // S08.T08.1: goods handed back to the owner (consign_out)
        $cr = $pdo->prepare("SELECT r.lot_id, r.kg, r.small_sacks, r.large_sacks, r.warehouse_uid, r.ret_date FROM consignment_returns r
            JOIN consignments c ON c.id=r.consignment_id AND c.customer_id=r.customer_id AND c.direction='in' WHERE r.id=? AND r.customer_id=?"); // S08.T08.2: out-returns post as cons_back
        $cr->execute([$docId, $cid]);
        $r = $cr->fetch();
        if (!$r) return [];
        $out[] = [(int)$r['lot_id'], 'consign_out', -(float)$r['kg'], -(float)$r['small_sacks'], -(float)$r['large_sacks'], null, (string)$r['ret_date'], ($r['warehouse_uid'] ?? null) ?: null];
    } elseif ($docType === 'cons_send' || $docType === 'cons_back') { // S08.T08.2: our goods to/from the virtual «at consignees» warehouse
        $out = pb_consout_desired($pdo, $cid, $docType, $docId);
    } elseif ($docType === 'sep') { // S07.T07.1: inputs leave (sep_out), outputs arrive (sep_in)
        $sd = $pdo->prepare('SELECT sep_date FROM separations WHERE id=? AND customer_id=?');
        $sd->execute([$docId, $cid]);
        $d = $sd->fetchColumn();
        if ($d === false) return [];
        $ins = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks, warehouse_uid, unit_cost FROM separation_inputs WHERE separation_id=? AND customer_id=? ORDER BY id');
        $ins->execute([$docId, $cid]);
        foreach ($ins->fetchAll() as $r) {
            $out[] = [(int)$r['lot_id'], 'sep_out', -(float)$r['kg'], -(float)($r['small_sacks'] ?? 0), -(float)($r['large_sacks'] ?? 0), (float)$r['unit_cost'], (string)$d, ($r['warehouse_uid'] ?? null) ?: null];
        }
        $os = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks, cost FROM separation_outputs WHERE separation_id=? AND customer_id=? ORDER BY id');
        $os->execute([$docId, $cid]);
        foreach ($os->fetchAll() as $r) {
            $kg = (float)$r['kg'];
            $out[] = [(int)$r['lot_id'], 'sep_in', $kg, (float)$r['small_sacks'], (float)$r['large_sacks'], $kg > 0 ? (float)$r['cost'] / $kg : null, (string)$d, null];
        }
    } elseif ($docType === 'proc') { // S09.T09.1: processing orders (paid / received), see processing.php
        $out = pb_proc_desired($pdo, $cid, $docId);
    } elseif ($docType === 'crack') { // S5: خروجی‌های خط شکست که لات شده‌اند (crack_run_lots): لات مبدأ crack_out، هر لات جدید crack_in؛ گونی صفر
        try {
            $rd = $pdo->prepare('SELECT run_date FROM crack_runs WHERE id=? AND customer_id=?');
            $rd->execute([$docId, $cid]);
            $d = $rd->fetchColumn();
            if ($d === false) return [];
            $ls = $pdo->prepare('SELECT lot_id, kg, source_lot_id, source_warehouse_uid FROM crack_run_lots WHERE run_id=? AND customer_id=? ORDER BY id');
            $ls->execute([$docId, $cid]);
            $rows = $ls->fetchAll();
        } catch (Throwable $e) { return []; } // table not migrated yet
        $bySrc = [];
        foreach ($rows as $r) {
            $k = (int)$r['source_lot_id'];
            if (!isset($bySrc[$k])) $bySrc[$k] = ['g' => 0, 'wh' => ($r['source_warehouse_uid'] ?? null) ?: null];
            $bySrc[$k]['g'] += (int)round(((float)$r['kg']) * 1000);
        }
        try { // CP1/D3: مصرف واقعی از لات مبدأ (در حالت full شامل افت) از crack_pools؛ استخر نبود ⇒ همان جمع لات‌ها
            $pq = $pdo->prepare('SELECT source_lot_id, input_g FROM crack_pools WHERE run_id=? AND customer_id=?');
            $pq->execute([$docId, $cid]);
            $pr = $pq->fetch();
            if ($pr && isset($bySrc[(int)$pr['source_lot_id']])) $bySrc[(int)$pr['source_lot_id']]['g'] = (int)$pr['input_g'];
        } catch (Throwable $e) { /* crack_pools not migrated yet */ }
        foreach ($bySrc as $sid => $b) {
            $cs = $pdo->prepare('SELECT ' . PB_BROKER_COST_SQL . ' FROM purchases p WHERE p.id=? AND p.customer_id=?');
            $cs->execute([(int)$sid, $cid]);
            $c = $cs->fetchColumn();
            $out[] = [(int)$sid, 'crack_out', -($b['g'] / 1000), 0.0, 0.0, $c === false ? null : (float)$c, (string)$d, $b['wh']];
        }
        foreach ($rows as $r) $out[] = [(int)$r['lot_id'], 'crack_in', (float)$r['kg'], 0.0, 0.0, 0.0, (string)$d, null]; // بها صفر (قیمت را مدیر بعداً ثبت می‌کند)
    } elseif ($docType === 'sale') {
        $st = $pdo->prepare('SELECT date FROM sales WHERE id=? AND customer_id=?');
        $st->execute([$docId, $cid]);
        $d = $st->fetchColumn();
        if ($d === false) return [];
        $it = $pdo->prepare('SELECT si.purchase_id, si.weight, si.small_sacks, si.large_sacks, si.warehouse_uid, ' . PB_BROKER_COST_SQL . ' AS price FROM sale_items si
            LEFT JOIN purchases p ON p.id=si.purchase_id AND p.customer_id=si.customer_id WHERE si.sale_id=? AND si.customer_id=? ORDER BY si.id');
        $it->execute([$docId, $cid]);
        foreach ($it->fetchAll() as $r) {
            $out[] = [(int)$r['purchase_id'], 'sale', -(float)$r['weight'], -(float)($r['small_sacks'] ?? 0), -(float)($r['large_sacks'] ?? 0),
                      $r['price'] === null ? null : (float)$r['price'], (string)$d, ($r['warehouse_uid'] ?? null) ?: null];
        }
    } elseif ($docType === 'preturn') { // S04.T04.1 purchase return: stock out at lot cost
        $st = $pdo->prepare('SELECT date FROM purchase_returns WHERE id=? AND customer_id=?');
        $st->execute([$docId, $cid]);
        $d = $st->fetchColumn();
        if ($d === false) return [];
        $it = $pdo->prepare('SELECT i.lot_id, i.kg, i.small_sacks, i.large_sacks, i.warehouse_uid, ' . PB_BROKER_COST_SQL . ' AS price FROM purchase_return_items i
            LEFT JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.return_id=? AND i.customer_id=? ORDER BY i.id');
        $it->execute([$docId, $cid]);
        foreach ($it->fetchAll() as $r) {
            $out[] = [(int)$r['lot_id'], 'return_out', -(float)$r['kg'], -(float)$r['small_sacks'], -(float)$r['large_sacks'],
                      $r['price'] === null ? null : (float)$r['price'], (string)$d, ($r['warehouse_uid'] ?? null) ?: null];
        }
    } elseif ($docType === 'sreturn') { // S04.T04.2 sales return: stock back into the SAME lot at ORIGINAL (purchase) cost
        $st = $pdo->prepare('SELECT date FROM sale_returns WHERE id=? AND customer_id=?');
        $st->execute([$docId, $cid]);
        $d = $st->fetchColumn();
        if ($d === false) return [];
        $it = $pdo->prepare('SELECT i.lot_id, i.kg, i.small_sacks, i.large_sacks, i.warehouse_uid, ' . PB_BROKER_COST_SQL . ' AS price FROM sale_return_items i
            LEFT JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.return_id=? AND i.customer_id=? ORDER BY i.id');
        $it->execute([$docId, $cid]);
        foreach ($it->fetchAll() as $r) {
            $out[] = [(int)$r['lot_id'], 'return_in', (float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks'],
                      $r['price'] === null ? null : (float)$r['price'], (string)$d, ($r['warehouse_uid'] ?? null) ?: null];
        }
    } elseif ($docType === 'count') { // S19.T19.1 stock count adjustments
        require_once __DIR__ . '/stock_count.php';
        $out = pb_sc_desired($pdo, $cid, $docId);
    } elseif ($docType === 'transfer') {
        $st = $pdo->prepare('SELECT t.*, ' . PB_BROKER_COST_SQL . ' AS price FROM stock_transfers t LEFT JOIN purchases p ON p.id=t.lot_id AND p.customer_id=t.customer_id WHERE t.id=? AND t.customer_id=?');
        $st->execute([$docId, $cid]);
        $t = $st->fetch();
        if (!$t || (int)$t['cancelled'] === 1) return [];
        $c = $t['price'] === null ? null : (float)$t['price'];
        $out[] = [(int)$t['lot_id'], 'transfer_out', -(float)$t['qty_kg'], -(float)$t['small_sacks'], -(float)$t['large_sacks'], $c, (string)$t['move_date'], (string)$t['from_warehouse_uid']];
        $out[] = [(int)$t['lot_id'], 'transfer_in', (float)$t['qty_kg'], (float)$t['small_sacks'], (float)$t['large_sacks'], $c, (string)$t['move_date'], (string)$t['to_warehouse_uid']];
    }
    return $out;
}

function pb_stock_sig(array $rows): string {
    $parts = [];
    foreach ($rows as $r) $parts[] = implode('|', $r);
    sort($parts);
    return implode(';', $parts);
}

/** Idempotent (re)posting of one document. No-op when the ledger already matches; otherwise reverse + repost. */
function pb_stock_post_doc(PDO $pdo, int $cid, string $docType, int $docId): void {
    $want = pb_stock_desired($pdo, $cid, $docType, $docId);
    $st = $pdo->prepare('SELECT * FROM stock_moves WHERE customer_id=? AND doc_type=? AND doc_id=? AND reversed=0 AND reversal_of IS NULL ORDER BY id');
    $st->execute([$cid, $docType, $docId]);
    $active = $st->fetchAll();
    $wantSig = [];
    foreach ($want as $w) {
        $wantSig[] = [$w[0], $w[1], number_format($w[2], 3, '.', ''), number_format($w[3], 3, '.', ''), number_format($w[4], 3, '.', ''), $w[7] ?? pb_stock_lot_wh($pdo, $cid, $w[0]), $w[6]];
    }
    $haveSig = [];
    foreach ($active as $a) {
        $haveSig[] = [(int)$a['lot_id'], $a['kind'], number_format((float)$a['qty_kg'], 3, '.', ''), number_format((float)$a['small_sacks'], 3, '.', ''),
                      number_format((float)$a['large_sacks'], 3, '.', ''), (string)$a['warehouse_uid'], (string)$a['move_date']];
    }
    if (pb_stock_sig($wantSig) === pb_stock_sig($haveSig)) return;
    pb_stock_reverse_doc($pdo, $cid, $docType, $docId);
    foreach ($want as $i => $w) {
        $ou = $pdo->prepare('SELECT ounce_uid FROM purchases WHERE id=? AND customer_id=?');
        $ou->execute([$w[0], $cid]);
        $ouid = $ou->fetchColumn();
        pb_stock_insert($pdo, $cid, $w[0], $wantSig[$i][5], is_string($ouid) ? $ouid : null, $w[1], $docType, $docId, $w[2], $w[3], $w[4], $w[5], $w[6], null);
    }
}

/** Appends negated rows for every still-active move of the document and flags the originals as reversed. */
function pb_stock_reverse_doc(PDO $pdo, int $cid, string $docType, int $docId): void {
    $st = $pdo->prepare('SELECT * FROM stock_moves WHERE customer_id=? AND doc_type=? AND doc_id=? AND reversed=0 AND reversal_of IS NULL ORDER BY id');
    $st->execute([$cid, $docType, $docId]);
    foreach ($st->fetchAll() as $a) {
        pb_stock_insert($pdo, $cid, (int)$a['lot_id'], $a['warehouse_uid'], $a['ounce_uid'], (string)$a['kind'], $docType, $docId,
            -(float)$a['qty_kg'], -(float)$a['small_sacks'], -(float)$a['large_sacks'], $a['unit_cost'] === null ? null : (float)$a['unit_cost'],
            (string)$a['move_date'], (int)$a['id']);
        $pdo->prepare('UPDATE stock_moves SET reversed=1 WHERE id=? AND customer_id=?')->execute([(int)$a['id'], $cid]);
    }
}

/** stock.reconcile: lots whose Σmoves differ from the cached remaining (kg = real mismatch; sacks reported apart). */
function pb_stock_reconcile(PDO $pdo, int $cid): array {
    $mis = [];
    $sack = [];
    $n = 0;
    foreach (pb_stock_lot_sums($pdo, $cid) as $r) {
        $n++;
        $dk = (float)$r['remaining'] - (float)$r['s_kg'];
        if (abs($dk) > PB_STOCK_EPS) {
            $mis[] = ['lot_id' => (int)$r['id'], 'code' => $r['code'], 'type_name' => $r['type_name'],
                      'cached_kg' => (float)$r['remaining'], 'ledger_kg' => round((float)$r['s_kg'], 3), 'diff_kg' => round($dk, 3)];
        }
        $ds = (float)$r['small_sacks_remaining'] - (float)$r['s_small'];
        $dl = (float)$r['large_sacks_remaining'] - (float)$r['s_large'];
        if (abs($ds) > PB_STOCK_EPS || abs($dl) > PB_STOCK_EPS) {
            $sack[] = ['lot_id' => (int)$r['id'], 'code' => $r['code'], 'diff_small' => round($ds, 3), 'diff_large' => round($dl, 3)];
        }
    }
    $orph = $pdo->prepare('SELECT m.lot_id, SUM(m.qty_kg) AS s FROM stock_moves m LEFT JOIN purchases p ON p.id=m.lot_id AND p.customer_id=m.customer_id
        WHERE m.customer_id=? AND p.id IS NULL GROUP BY m.lot_id HAVING ABS(SUM(m.qty_kg)) > ?');
    $orph->execute([$cid, PB_STOCK_EPS]);
    $orphans = [];
    foreach ($orph->fetchAll() as $o) $orphans[] = ['lot_id' => (int)$o['lot_id'], 'ledger_kg' => round((float)$o['s'], 3)];
    return ['lots' => $n, 'mismatch_count' => count($mis), 'mismatches' => $mis, 'orphan_moves' => $orphans, 'sack_mismatches' => $sack,
            'ok' => !$mis && !$orphans];
}

/* ---------------- S01.T01.3: per-warehouse stock, picking, transfers ---------------- */

/**
 * Stock of one lot per warehouse, derived from the ledger: [warehouse_uid => ['kg'=>, 'small'=>, 'large'=>]].
 * $excludeDoc = [docType, docId] leaves out that document's still-active moves (edit of a sale/mix checks against
 * the stock as if the old version did not exist).
 */
function pb_stock_wh_balances(PDO $pdo, int $cid, int $lotId, ?array $excludeDoc = null): array {
    $st = $pdo->prepare('SELECT warehouse_uid, SUM(qty_kg) AS kg, SUM(small_sacks) AS s, SUM(large_sacks) AS l FROM stock_moves
        WHERE customer_id=? AND lot_id=? GROUP BY warehouse_uid');
    $st->execute([$cid, $lotId]);
    $bal = [];
    foreach ($st->fetchAll() as $r) {
        $bal[(string)$r['warehouse_uid']] = ['kg' => (float)$r['kg'], 'small' => (float)$r['s'], 'large' => (float)$r['l']];
    }
    if ($excludeDoc) {
        $ex = $pdo->prepare('SELECT warehouse_uid, SUM(qty_kg) AS kg, SUM(small_sacks) AS s, SUM(large_sacks) AS l FROM stock_moves
            WHERE customer_id=? AND lot_id=? AND doc_type=? AND doc_id=? AND reversed=0 AND reversal_of IS NULL GROUP BY warehouse_uid');
        $ex->execute([$cid, $lotId, $excludeDoc[0], $excludeDoc[1]]);
        foreach ($ex->fetchAll() as $r) {
            $w = (string)$r['warehouse_uid'];
            if (!isset($bal[$w])) $bal[$w] = ['kg' => 0.0, 'small' => 0.0, 'large' => 0.0];
            $bal[$w]['kg'] -= (float)$r['kg'];
            $bal[$w]['small'] -= (float)$r['s'];
            $bal[$w]['large'] -= (float)$r['l'];
        }
    }
    foreach ($bal as $w => $b) {
        if (abs($b['kg']) <= PB_STOCK_EPS && abs($b['small']) <= PB_STOCK_EPS && abs($b['large']) <= PB_STOCK_EPS) unset($bal[$w]);
    }
    return $bal;
}

/**
 * Chooses (or validates) the warehouse a sale item / mix source is taken from. Returns the warehouse uid, or throws
 * RuntimeException(message). Explicit $requested must be ours and hold enough kg. Without it: the only warehouse that
 * holds enough; when several do, the caller must choose (old clients keep working for lots that were never transferred).
 */
function pb_stock_pick_wh(PDO $pdo, int $cid, int $lotId, float $kg, ?string $requested, ?array $excludeDoc = null): string {
    $bal = pb_stock_wh_balances($pdo, $cid, $lotId, $excludeDoc);
    $atConsignee = false; // S08.T08.2: goods at consignees are still in `remaining` but are never picked automatically
    if ($requested === null || $requested === '') {
        foreach (pb_wh_virtual_uids($pdo, $cid) as $vu) if (isset($bal[$vu])) { unset($bal[$vu]); $atConsignee = true; }
    }
    if ($requested !== null && $requested !== '') {
        if (!pb_is_uid($requested)) throw new RuntimeException('انبار نامعتبر است');
        $own = $pdo->prepare('SELECT 1 FROM ent_warehouses WHERE customer_id=? AND uid=? AND deleted_at IS NULL');
        $own->execute([$cid, $requested]);
        if (!$own->fetchColumn()) throw new RuntimeException('انبار یافت نشد');
        $have = $bal[$requested]['kg'] ?? 0.0;
        if ($kg - $have > PB_STOCK_EPS) throw new RuntimeException('موجودی این قلم در انبار انتخاب‌شده کافی نیست');
        return $requested;
    }
    $ok = [];
    foreach ($bal as $w => $b) if ($b['kg'] + PB_STOCK_EPS >= $kg) $ok[] = $w;
    if (count($ok) === 1) return $ok[0];
    if (count($ok) > 1) throw new RuntimeException('این قلم در چند انبار موجود است؛ انبار را انتخاب کنید');
    if ($atConsignee) throw new RuntimeException('موجودیِ در دسترس این قلم کافی نیست (بخشی از آن نزد امانت‌گیرنده است)');
    if (count($bal) <= 1) return pb_stock_lot_wh($pdo, $cid, $lotId); // nothing to choose; global stock checks already ran
    throw new RuntimeException('موجودی هیچ انبار به‌تنهایی کافی نیست؛ ابتدا بین انبارها انتقال دهید');
}

/** Same as pb_stock_pick_wh but rolls back the open transaction and answers 400 on failure. */
function pb_stock_pick_wh_or_bad(PDO $pdo, int $cid, int $lotId, float $kg, ?string $requested, ?array $excludeDoc = null): string {
    try {
        return pb_stock_pick_wh($pdo, $cid, $lotId, $kg, $requested, $excludeDoc);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        bad($e->getMessage());
    }
}

function pb_stock_transfer_row(array $t): array {
    return ['id' => (int)$t['id'], 'uid' => $t['uid'], 'lot_id' => (int)$t['lot_id'], 'from_warehouse_uid' => $t['from_warehouse_uid'],
            'to_warehouse_uid' => $t['to_warehouse_uid'], 'kg' => (float)$t['qty_kg'], 'small_sacks' => (float)$t['small_sacks'],
            'large_sacks' => (float)$t['large_sacks'], 'date' => $t['move_date'], 'note' => $t['note'], 'cancelled' => (int)$t['cancelled'] === 1];
}

/** stock.transfer {uid?, lot_id, from_warehouse_uid, to_warehouse_uid, kg, small_sacks?, large_sacks?, date?, note?} */
function pb_stock_transfer_create(PDO $pdo, int $cid, array $payload): array {
    $uid = $payload['uid'] ?? null;
    if ($uid === null || $uid === '') $uid = pb_uuid4();
    if (!pb_is_uid($uid)) bad('شناسهٔ انتقال نامعتبر است');
    $ex = $pdo->prepare('SELECT * FROM stock_transfers WHERE customer_id=? AND uid=?');
    $ex->execute([$cid, $uid]);
    if ($row = $ex->fetch()) return ['id' => (int)$row['id'], 'uid' => $uid, 'duplicate' => true]; // idempotent retry
    $lot = (int)($payload['lot_id'] ?? 0);
    $from = pb_wh_require($pdo, $cid, $payload['from_warehouse_uid'] ?? null);
    $to = pb_wh_require($pdo, $cid, $payload['to_warehouse_uid'] ?? null);
    if ($from === $to) bad('انبار مبدأ و مقصد باید متفاوت باشند');
    $virt = pb_wh_virtual_uids($pdo, $cid);
    if (in_array($from, $virt, true) || in_array($to, $virt, true)) bad('انبار «نزد امانت‌گیرندگان» فقط از صفحهٔ امانی قابل استفاده است'); // S08.T08.2
    $kg = round(num($payload['kg'] ?? 0), 3);
    $small = round(max(0.0, (float)(nullableNum($payload['small_sacks'] ?? null) ?? 0)), 3);
    $large = round(max(0.0, (float)(nullableNum($payload['large_sacks'] ?? null) ?? 0)), 3);
    if ($kg <= 0 || $kg > 1e9) bad('وزن انتقال نامعتبر است');
    $date = vdate($payload['date'] ?? null);
    $note = str_or_null($payload['note'] ?? null, 500);

    $pdo->beginTransaction();
    $lk = $pdo->prepare('SELECT id FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
    $lk->execute([$lot, $cid]);
    if (!$lk->fetch()) { $pdo->rollBack(); bad('قلم یافت نشد', 404); }
    $bal = pb_stock_wh_balances($pdo, $cid, $lot);
    $have = $bal[$from] ?? ['kg' => 0.0, 'small' => 0.0, 'large' => 0.0];
    if ($kg - $have['kg'] > PB_STOCK_EPS) { $pdo->rollBack(); bad('موجودی این قلم در انبار مبدأ کافی نیست'); }
    if ($small - $have['small'] > PB_STOCK_EPS || $large - $have['large'] > PB_STOCK_EPS) { $pdo->rollBack(); bad('تعداد گونی در انبار مبدأ کافی نیست'); }
    $pdo->prepare('INSERT INTO stock_transfers (customer_id, uid, lot_id, from_warehouse_uid, to_warehouse_uid, qty_kg, small_sacks, large_sacks, move_date, note)
        VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$cid, $uid, $lot, $from, $to, $kg, $small, $large, $date, $note]);
    $id = (int)$pdo->lastInsertId();
    pb_stock_post_doc($pdo, $cid, 'transfer', $id);
    $pdo->commit();
    return ['id' => $id, 'uid' => $uid];
}

/** stock.transfer_cancel {id|uid}: reverses the two moves; refused when the destination no longer holds the goods. */
function pb_stock_transfer_cancel(PDO $pdo, int $cid, array $payload): void {
    $pdo->beginTransaction();
    if (!empty($payload['uid'])) {
        $st = $pdo->prepare('SELECT * FROM stock_transfers WHERE customer_id=? AND uid=? FOR UPDATE');
        $st->execute([$cid, (string)$payload['uid']]);
    } else {
        $st = $pdo->prepare('SELECT * FROM stock_transfers WHERE customer_id=? AND id=? FOR UPDATE');
        $st->execute([$cid, (int)($payload['id'] ?? 0)]);
    }
    $t = $st->fetch();
    if (!$t) { $pdo->rollBack(); bad('انتقال یافت نشد', 404); }
    if ((int)$t['cancelled'] === 1) { $pdo->commit(); return; }
    $bal = pb_stock_wh_balances($pdo, $cid, (int)$t['lot_id']);
    $have = $bal[(string)$t['to_warehouse_uid']] ?? ['kg' => 0.0, 'small' => 0.0, 'large' => 0.0];
    if ((float)$t['qty_kg'] - $have['kg'] > PB_STOCK_EPS) { $pdo->rollBack(); bad('بخشی از این کالا در انبار مقصد مصرف یا منتقل شده است؛ لغو انتقال ممکن نیست'); }
    $pdo->prepare('UPDATE stock_transfers SET cancelled=1 WHERE id=? AND customer_id=?')->execute([(int)$t['id'], $cid]);
    pb_stock_post_doc($pdo, $cid, 'transfer', (int)$t['id']); // desired = [] -> reverses
    $pdo->commit();
}

/** stock.transfers {lot_id?, warehouse_uid?, include_cancelled?} + stock.by_warehouse {lot_id?}: read side for the clients. */
function pb_stock_transfers_list(PDO $pdo, int $cid, array $payload): array {
    $sql = 'SELECT * FROM stock_transfers WHERE customer_id=?';
    $args = [$cid];
    if (!empty($payload['lot_id'])) { $sql .= ' AND lot_id=?'; $args[] = (int)$payload['lot_id']; }
    if (!empty($payload['warehouse_uid'])) { $sql .= ' AND (from_warehouse_uid=? OR to_warehouse_uid=?)'; $args[] = (string)$payload['warehouse_uid']; $args[] = (string)$payload['warehouse_uid']; }
    if (empty($payload['include_cancelled'])) $sql .= ' AND cancelled=0';
    $st = $pdo->prepare($sql . ' ORDER BY move_date DESC, id DESC LIMIT 500');
    $st->execute($args);
    return array_map('pb_stock_transfer_row', $st->fetchAll());
}

/** Stock of lots per warehouse: [{lot_id, warehouse_uid, kg, small_sacks, large_sacks}] (only non-zero rows). */
function pb_stock_by_warehouse(PDO $pdo, int $cid, ?int $lotId): array {
    $sql = 'SELECT lot_id, warehouse_uid, SUM(qty_kg) AS kg, SUM(small_sacks) AS s, SUM(large_sacks) AS l FROM stock_moves WHERE customer_id=?';
    $args = [$cid];
    if ($lotId) { $sql .= ' AND lot_id=?'; $args[] = $lotId; }
    $st = $pdo->prepare($sql . ' GROUP BY lot_id, warehouse_uid ORDER BY lot_id');
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (abs((float)$r['kg']) <= PB_STOCK_EPS && abs((float)$r['s']) <= PB_STOCK_EPS && abs((float)$r['l']) <= PB_STOCK_EPS) continue;
        $out[] = ['lot_id' => (int)$r['lot_id'], 'warehouse_uid' => $r['warehouse_uid'], 'kg' => round((float)$r['kg'], 3),
                  'small_sacks' => round((float)$r['s'], 3), 'large_sacks' => round((float)$r['l'], 3)];
    }
    return $out;
}

/** Deleting an untouched lot also removes its transfers (their moves are reversed first so the ledger stays an audit trail). */
function pb_stock_drop_lot_transfers(PDO $pdo, int $cid, int $lotId): void {
    $st = $pdo->prepare('SELECT id FROM stock_transfers WHERE customer_id=? AND lot_id=?');
    $st->execute([$cid, $lotId]);
    foreach ($st->fetchAll() as $t) {
        pb_stock_reverse_doc($pdo, $cid, 'transfer', (int)$t['id']);
        $pdo->prepare('DELETE FROM stock_transfers WHERE id=? AND customer_id=?')->execute([(int)$t['id'], $cid]);
    }
}

/* ---------------- T01.5 stock reports (read-only, online) ----------------
 * reports.stock_ledger {warehouse_uid?, lot_id?, type_name?, ounce_uid?, from?, to?} -> opening / in / out / closing + rows
 * reports.stock_balance {by: warehouse|ounce|type, ...filters, to?} -> items [{key,label,kg,small_sacks,large_sacks,lots}] + totals
 * reports.stock_mix     {by: warehouse|ounce|type, ...filters}        -> same items + percent of positive kg
 * Totals come from SQL aggregates (not from the row list), so truncation of rows never changes the numbers.
 */

function pb_stock_rpt_date($v): ?string {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

/** WHERE over stock_moves m LEFT JOIN purchases p. Returns [sql, args]; date bounds are added by the caller. */
function pb_stock_rpt_where(PDO $pdo, int $cid, array $f): array {
    $sql = 'm.customer_id=?';
    $args = [$cid];
    if (!empty($f['lot_id'])) { $sql .= ' AND m.lot_id=?'; $args[] = (int)$f['lot_id']; }
    if (!empty($f['warehouse_uid'])) {
        $sql .= ' AND COALESCE(m.warehouse_uid, ' . $pdo->quote(pb_wh_default_uid($pdo, $cid)) . ')=?';
        $args[] = (string)$f['warehouse_uid'];
    }
    if (isset($f['type_name']) && $f['type_name'] !== '') { $sql .= ' AND p.type_name=?'; $args[] = (string)$f['type_name']; }
    if (!empty($f['ounce_uid'])) { $sql .= ' AND p.ounce_uid=?'; $args[] = (string)$f['ounce_uid']; }
    return [$sql, $args];
}

function pb_stock_rpt_from(): string {
    return ' FROM stock_moves m LEFT JOIN purchases p ON p.id=m.lot_id AND p.customer_id=m.customer_id ';
}

function pb_stock_rpt_key(PDO $pdo, int $cid, string $by): string {
    if ($by === 'ounce') return 'p.ounce_uid';
    if ($by === 'type') return 'p.type_name';
    return 'COALESCE(m.warehouse_uid, ' . $pdo->quote(pb_wh_default_uid($pdo, $cid)) . ')';
}

/** Display names for warehouse / ounce uids (deleted entities included so old lots stay labelled). */
function pb_stock_rpt_names(PDO $pdo, int $cid, string $by): array {
    $map = [];
    if ($by === 'warehouse') {
        $st = $pdo->prepare('SELECT uid, name FROM ent_warehouses WHERE customer_id=?');
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) $map[(string)$r['uid']] = (string)$r['name'];
    } elseif ($by === 'ounce') {
        $st = $pdo->prepare('SELECT uid, code FROM ent_ounces WHERE customer_id=?');
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) $map[(string)$r['uid']] = (string)$r['code'];
    }
    return $map;
}

function pb_stock_rpt_by(array $f): string {
    $by = (string)($f['by'] ?? 'warehouse');
    return in_array($by, ['warehouse', 'ounce', 'type'], true) ? $by : 'warehouse';
}

/** Balance per warehouse / ounce / type (as of `to`, if given). Only non-zero groups are returned. */
function pb_stock_balance(PDO $pdo, int $cid, array $f): array {
    $by = pb_stock_rpt_by($f);
    $to = pb_stock_rpt_date($f['to'] ?? null);
    [$where, $args] = pb_stock_rpt_where($pdo, $cid, $f);
    if ($to) { $where .= ' AND m.move_date<=?'; $args[] = $to; }
    $key = pb_stock_rpt_key($pdo, $cid, $by);
    $st = $pdo->prepare("SELECT $key AS k, SUM(m.qty_kg) AS kg, SUM(m.small_sacks) AS s, SUM(m.large_sacks) AS l, COUNT(DISTINCT m.lot_id) AS lots, SUM(m.qty_kg*COALESCE(m.unit_cost,0)) AS val"
        . pb_stock_rpt_from() . "WHERE $where GROUP BY k ORDER BY k");
    $st->execute($args);
    $names = pb_stock_rpt_names($pdo, $cid, $by);
    $items = [];
    $total = 0.0;
    $totS = 0.0;
    $totL = 0.0;
    $totV = 0.0; // S30.T30.6b: inventory value at lot cost (stored money unit); consignment moves (NULL cost) count 0
    foreach ($st->fetchAll() as $r) {
        $kg = (float)$r['kg']; $s = (float)$r['s']; $l = (float)$r['l']; $v = (float)$r['val'];
        if (abs($kg) <= PB_STOCK_EPS && abs($s) <= PB_STOCK_EPS && abs($l) <= PB_STOCK_EPS) continue;
        $k = $r['k'] === null ? '' : (string)$r['k'];
        $label = $by === 'type' ? ($k !== '' ? $k : 'بدون نوع') : (($names[$k] ?? '') !== '' ? $names[$k] : ($k !== '' ? $k : 'بدون نام'));
        $items[] = ['key' => $k, 'label' => $label, 'kg' => round($kg, 3), 'small_sacks' => round($s, 3),
                    'large_sacks' => round($l, 3), 'lots' => (int)$r['lots'], 'value' => round($v, 2)];
        $total += $kg; $totS += $s; $totL += $l; $totV += $v;
    }
    $out = ['by' => $by, 'items' => $items,
            'total' => ['kg' => round($total, 3), 'small_sacks' => round($totS, 3), 'large_sacks' => round($totL, 3), 'value' => round($totV, 2)]];
    // Cross-check with the cached lot remaining (what inventory_screen sums). Only comparable without warehouse/date filters.
    if (empty($f['warehouse_uid']) && !$to) {
        $cf = ['lot_id' => $f['lot_id'] ?? null, 'type_name' => $f['type_name'] ?? null, 'ounce_uid' => $f['ounce_uid'] ?? null];
        $cw = 'p.customer_id=?'; $ca = [$cid];
        if (!empty($cf['lot_id'])) { $cw .= ' AND p.id=?'; $ca[] = (int)$cf['lot_id']; }
        if ($cf['type_name'] !== null && $cf['type_name'] !== '') { $cw .= ' AND p.type_name=?'; $ca[] = (string)$cf['type_name']; }
        if (!empty($cf['ounce_uid'])) { $cw .= ' AND p.ounce_uid=?'; $ca[] = (string)$cf['ounce_uid']; }
        $cs = $pdo->prepare("SELECT COALESCE(SUM(p.remaining),0) AS rem FROM purchases p WHERE $cw");
        $cs->execute($ca);
        $cached = round((float)$cs->fetchColumn(), 3);
        $out['cached_kg'] = $cached;
        $out['diff_kg'] = round($total - $cached, 3);
    }
    return $out;
}

/** Composition (% of positive kg) by warehouse / ounce / type. */
function pb_stock_mix(PDO $pdo, int $cid, array $f): array {
    $res = pb_stock_balance($pdo, $cid, $f);
    $tot = 0.0;
    foreach ($res['items'] as $it) if ($it['kg'] > 0) $tot += $it['kg'];
    foreach ($res['items'] as &$it) {
        $it['percent'] = $tot > PB_STOCK_EPS && $it['kg'] > 0 ? round($it['kg'] / $tot * 100, 2) : 0.0;
    }
    unset($it);
    $res['positive_kg'] = round($tot, 3);
    return $res;
}

/** Ledger (گردش انبار): opening + in − out = closing, with running balance per movement row. */
function pb_stock_ledger(PDO $pdo, int $cid, array $f): array {
    $from = pb_stock_rpt_date($f['from'] ?? null);
    $to = pb_stock_rpt_date($f['to'] ?? null);
    [$where, $args] = pb_stock_rpt_where($pdo, $cid, $f);
    $from_sql = pb_stock_rpt_from();
    $sumCols = 'COALESCE(SUM(m.qty_kg),0) AS kg, COALESCE(SUM(m.small_sacks),0) AS s, COALESCE(SUM(m.large_sacks),0) AS l';
    $opening = ['kg' => 0.0, 's' => 0.0, 'l' => 0.0];
    if ($from) {
        $st = $pdo->prepare("SELECT $sumCols$from_sql WHERE $where AND m.move_date<?");
        $st->execute(array_merge($args, [$from]));
        $r = $st->fetch();
        $opening = ['kg' => (float)$r['kg'], 's' => (float)$r['s'], 'l' => (float)$r['l']];
    }
    $range = '';
    $rargs = $args;
    if ($from) { $range .= ' AND m.move_date>=?'; $rargs[] = $from; }
    if ($to) { $range .= ' AND m.move_date<=?'; $rargs[] = $to; }
    $pos = fn(string $c): string => "COALESCE(SUM(CASE WHEN m.$c>0 THEN m.$c ELSE 0 END),0)";
    $neg = fn(string $c): string => "COALESCE(SUM(CASE WHEN m.$c<0 THEN -m.$c ELSE 0 END),0)";
    $st = $pdo->prepare('SELECT ' . $pos('qty_kg') . ' AS in_kg, ' . $neg('qty_kg') . ' AS out_kg, '
        . $pos('small_sacks') . ' AS in_s, ' . $neg('small_sacks') . ' AS out_s, '
        . $pos('large_sacks') . ' AS in_l, ' . $neg('large_sacks') . ' AS out_l'
        // برگشتِ اصلاح/حذف اسناد (reversal) و ردیف اصلیِ برگشت‌خورده در یک روز و خنثی‌اند؛ در «ورودی/خروجی» دوبار شمرده نشوند
        // (مانده‌های افتتاحی/پایانی از همهٔ ردیف‌ها حساب می‌شود و تغییری نمی‌کند)
        . $from_sql . "WHERE $where$range AND m.reversed=0 AND m.reversal_of IS NULL");
    $st->execute($rargs);
    $t = $st->fetch();
    $in = ['kg' => (float)$t['in_kg'], 's' => (float)$t['in_s'], 'l' => (float)$t['in_l']];
    $out = ['kg' => (float)$t['out_kg'], 's' => (float)$t['out_s'], 'l' => (float)$t['out_l']];
    $closing = ['kg' => $opening['kg'] + $in['kg'] - $out['kg'], 's' => $opening['s'] + $in['s'] - $out['s'],
                'l' => $opening['l'] + $in['l'] - $out['l']];

    $defq = $pdo->quote(pb_wh_default_uid($pdo, $cid));
    $st = $pdo->prepare("SELECT m.id, m.move_date, m.kind, m.doc_type, m.doc_id, m.lot_id, p.code AS lot_code, p.type_name,
            COALESCE(m.warehouse_uid, $defq) AS warehouse_uid, m.qty_kg, m.small_sacks, m.large_sacks, m.reversed, m.reversal_of$from_sql"
        . "WHERE $where$range ORDER BY m.move_date, m.id LIMIT 2001");
    $st->execute($rargs);
    $all = $st->fetchAll();
    $truncated = count($all) > 2000;
    $rows = [];
    $bal = $opening['kg'];
    foreach (array_slice($all, 0, 2000) as $r) {
        $bal += (float)$r['qty_kg'];
        $rows[] = ['id' => (int)$r['id'], 'date' => (string)$r['move_date'], 'kind' => (string)$r['kind'],
                   'doc_type' => (string)$r['doc_type'], 'doc_id' => (int)$r['doc_id'], 'lot_id' => (int)$r['lot_id'],
                   'lot_code' => (string)($r['lot_code'] ?? ''), 'type_name' => (string)($r['type_name'] ?? ''),
                   'warehouse_uid' => (string)$r['warehouse_uid'], 'kg' => round((float)$r['qty_kg'], 3),
                   'small_sacks' => round((float)$r['small_sacks'], 3), 'large_sacks' => round((float)$r['large_sacks'], 3),
                   'balance_kg' => round($bal, 3),
                   'reversed' => (int)$r['reversed'] === 1, 'is_reversal' => $r['reversal_of'] !== null];
    }
    $rnd = fn(array $a): array => ['kg' => round($a['kg'], 3), 'small_sacks' => round($a['s'], 3), 'large_sacks' => round($a['l'], 3)];
    return ['from' => $from, 'to' => $to,
            'opening' => $rnd($opening), 'in' => $rnd($in), 'out' => $rnd($out), 'closing' => $rnd($closing),
            'rows' => $rows, 'truncated' => $truncated];
}
