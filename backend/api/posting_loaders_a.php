<?php
declare(strict_types=1);
/**
 * S16.T16.3 — posting loaders "A" (GOLD): purchase, sale, preturn (purchase return), sreturn (sales return).
 * Each loader turns a document into {date, description, fields:{int..}, dims:{party,broker}} for posting_rules.json.
 * Units: stored amounts are toman; vouchers are integer RIAL = stored x pb_posting_unit_mult() (DECISIONS Q1, default 10).
 * Lot cost (A8): purchase lot = price*kg + broker(payer us) + labor + transport; other lots = ledger inbound unit_cost*kg.
 * COGS / inventory-back use muldiv(lot_cost, kg_g, lot_qty_g) (integer round-half-up), i.e. ORIGINAL lot cost.
 * Required by posting.php. Hooks are called by the document actions inside their transaction.
 */

function pb_posting_unit_mult(): int { return (int)($GLOBALS['PB_UNIT_MULT'] ?? 10); } // S29.T29.2: 1 once the tenant is rial (bound per request), else 10

function pb_pl_rial($toman): int { return (int)round((float)$toman * pb_posting_unit_mult()); }

/** tafsili id of a party by name (0 when missing; line is then posted without tafsili). */
function pb_pl_party_tid(PDO $pdo, int $cid, ?string $name): int {
    $name = trim((string)$name);
    if ($name === '') return 0;
    $st = $pdo->prepare("SELECT id FROM tafsili WHERE customer_id=? AND kind='party' AND ref_key=? LIMIT 1");
    $st->execute([$cid, mb_substr($name, 0, 150)]);
    return (int)$st->fetchColumn();
}

/** VAT rate (thousandths of a percent) of the fiscal year containing $date; 0 when VAT is off. Data, never hard-coded. */
function pb_pl_vat_milli(PDO $pdo, int $cid, string $date): int {
    $st = $pdo->prepare('SELECT vat_enabled, vat_rate, vat_levy_rate FROM fiscal_years WHERE customer_id=? AND start_date<=? AND end_date>=? ORDER BY is_current DESC LIMIT 1');
    $st->execute([$cid, $date, $date]);
    $r = $st->fetch();
    if (!$r || (int)$r['vat_enabled'] !== 1 || $r['vat_rate'] === null) return 0;
    return (int)round(((float)$r['vat_rate'] + (float)($r['vat_levy_rate'] ?? 0)) * 1000);
}

function pb_pl_taf_kind(PDO $pdo, int $cid, $tid): string {
    if (!$tid) return 'bank';
    $st = $pdo->prepare('SELECT kind FROM tafsili WHERE id=? AND customer_id=?');
    $st->execute([(int)$tid, $cid]);
    return (string)$st->fetchColumn() === 'cash' ? 'cash' : 'bank';
}

function pb_pl_broker_us(array $r): int {
    return (($r['broker_payer'] ?? '') === 'us' && $r['broker_amount'] !== null) ? pb_pl_rial($r['broker_amount']) : 0;
}

/** [cost_total_rial, qty_g, processed(0|1), kind(is_mix)] of a lot; null when lot missing. */
function pb_pl_lot(PDO $pdo, int $cid, int $lotId): ?array {
    static $cache = [];
    $k = $cid . ':' . $lotId;
    if (isset($cache[$k])) return $cache[$k];
    $st = $pdo->prepare('SELECT net_weight, price, labor_cost, transport_cost, is_mix, broker_payer, broker_amount FROM purchases WHERE id=? AND customer_id=?');
    $st->execute([$lotId, $cid]);
    $p = $st->fetch();
    if (!$p) return null;
    $net = (float)$p['net_weight'];
    $kind = (int)$p['is_mix'];
    if ($kind === 0) {
        $total = pb_pl_rial((float)$p['price'] * $net) + pb_pl_broker_us($p) + pb_pl_rial($p['labor_cost'] ?? 0) + pb_pl_rial($p['transport_cost'] ?? 0);
    } else {
        $q = $pdo->prepare('SELECT unit_cost FROM stock_moves WHERE customer_id=? AND lot_id=? AND qty_kg>0 AND unit_cost IS NOT NULL AND reversal_of IS NULL ORDER BY reversed, id LIMIT 1');
        $q->execute([$cid, $lotId]);
        $uc = $q->fetchColumn();
        $total = $uc === false ? 0 : pb_pl_rial((float)$uc * $net);
    }
    return $cache[$k] = [$total, (int)round($net * 1000), $kind === 2 ? 1 : 0, $kind];
}

/** Original-lot-cost of $kg taken from / returned to a lot. Returns [rial, processed]. null = consigned lot (not ours, B7). */
function pb_pl_lot_value(PDO $pdo, int $cid, int $lotId, float $kg): ?array {
    $l = pb_pl_lot($pdo, $cid, $lotId);
    if (!$l || $l[3] === 3 || $l[1] <= 0) return null;
    return [pb_posting_divround(pb_posting_mul($l[0], (int)round($kg * 1000)), $l[1]), $l[2]];
}

/** Paid/received rows of a payment (purchase) or receipt (sale) saved with the document. [cash, bank, cheque] in rial. */
function pb_pl_settle(PDO $pdo, int $cid, bool $sale, int $docId): array {
    [$hdr, $key, $lump, $chq] = $sale ? ['receipts', 'sale_id', 'receipt_lump_tx', 'receipt_cheque_inst'] : ['payments', 'purchase_id', 'lump_tx', 'cheque_inst'];
    $idc = $sale ? 'receipt_id' : 'payment_id';
    $tcol = $sale ? 'to_account' : 'from_account';
    $cash = 0; $bank = 0; $cheque = 0;
    try {
        $st = $pdo->prepare("SELECT t.amount, t.treasury_tafsili_id AS tid FROM $lump t JOIN $hdr h ON h.id=t.$idc AND h.customer_id=t.customer_id WHERE h.$key=? AND h.customer_id=? AND t.paid=1" . (function_exists('pb_plc5_excl_sql') ? pb_plc5_excl_sql($pdo, $sale) : '')); // S30.T30.1: same-currency fx lines post via fx_settle_*
        $st->execute([$docId, $cid]);
        foreach ($st->fetchAll() as $r) {
            if (pb_pl_taf_kind($pdo, $cid, $r['tid']) === 'cash') $cash += pb_pl_rial($r['amount']); else $bank += pb_pl_rial($r['amount']);
        }
        $st = $pdo->prepare("SELECT COALESCE(SUM(t.amount),0) FROM $chq t JOIN $hdr h ON h.id=t.$idc AND h.customer_id=t.customer_id WHERE h.$key=? AND h.customer_id=?");
        $st->execute([$docId, $cid]);
        $cheque = pb_pl_rial($st->fetchColumn());
    } catch (Throwable $e) {
        error_log('[posting_settle] ' . $e->getMessage()); // treasury column missing => treated as 0
    }
    return [$cash, $bank, $cheque];
}

/** S35.T35.5: weighbridge cost counts as our transport-type expense only when we pay it (side_cost_payer <> 'party'); party-charged costs are not posted (SIGNOFF). */
function pb_pl_weigh_own(array $d): float {
    return (($d['side_cost_payer'] ?? null) === 'party') ? 0.0 : (float)($d['weighbridge_cost'] ?? 0);
}

function pb_pl_load_purchase(PDO $pdo, int $cid, int $id, ?array $pv = null): ?array {
    // $pv (S35.T35.9d dry-run): ['row'=>purchase columns,'settle'=>[cash,bank,cheque] rial]; nothing is read from / written to the DB for the document itself
    if ($pv !== null) { $p = $pv['row'] + ['is_mix' => 0, 'code' => '', 'seller' => '']; }
    else {
        $st = $pdo->prepare('SELECT * FROM purchases WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $p = $st->fetch();
    }
    if (!$p || (int)$p['is_mix'] !== 0) return null; // mix/separation/consigned lots: other tasks
    $date = (string)$p['date'];
    [$cash, $bank, $chq] = $pv !== null ? $pv['settle'] : pb_pl_settle($pdo, $cid, false, $id);
    $bid = (int)($p['broker_id'] ?? 0);
    $bn = null;
    if ($bid > 0) { $b = pb_broker_party($pdo, $cid, $bid); $bn = $b ? (string)$b['name'] : null; }
    return ['date' => $date, 'description' => 'خرید ' . (string)$p['code'] . ' ' . (string)($p['seller'] ?? ''),
        'fields' => ['goods' => pb_pl_rial((float)$p['price'] * (float)$p['net_weight']), 'discount' => pb_pl_rial($p['discount'] ?? 0),
            'labor' => pb_pl_rial($p['labor_cost'] ?? 0), 'transport' => pb_pl_rial(($p['transport_cost'] ?? 0) + pb_pl_weigh_own($p)), 'broker_us' => pb_pl_broker_us($p),
            'vat_rate_milli' => pb_pl_vat_milli($pdo, $cid, $date), 'has_tax' => ($p['total_tax'] ?? null) === null ? 0 : 1, 'tax_amount' => ($p['total_tax'] ?? null) === null ? 0 : pb_pl_rial($p['total_tax']), 'paid_cash' => $cash, 'paid_bank' => $bank, 'cheque_out' => $chq],
        'dims' => ['party' => pb_pl_party_tid($pdo, $cid, $p['seller'] ?? null), 'broker' => pb_pl_party_tid($pdo, $cid, $bn)]];
}

function pb_pl_load_sale(PDO $pdo, int $cid, int $id, ?array $pv = null): ?array {
    // $pv (S35.T35.9d dry-run): ['row'=>sale columns,'items'=>[{purchase_id,weight,item_price}],'settle'=>[cash,bank,cheque] rial]
    if ($pv !== null) { $s = $pv['row']; $itemRows = $pv['items']; }
    else {
        $st = $pdo->prepare('SELECT * FROM sales WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $s = $st->fetch();
        if (!$s) return null;
        $it = $pdo->prepare('SELECT purchase_id, weight, item_price FROM sale_items WHERE sale_id=? AND customer_id=?');
        $it->execute([$id, $cid]);
        $itemRows = $it->fetchAll();
    }
    $goods = 0; $raw = 0; $proc = 0; $own = 0; $consigned = 0;
    foreach ($itemRows as $i) {
        $goods += pb_pl_rial((float)$i['weight'] * (float)$i['item_price']);
        $lk = pb_pl_lot($pdo, $cid, (int)$i['purchase_id']);
        if ($lk && $lk[3] === 3) { $goods -= pb_pl_rial((float)$i['weight'] * (float)$i['item_price']); $consigned++; continue; } // consigned-in: companion 'cons_sale' (T16.5b)
        $v = pb_pl_lot_value($pdo, $cid, (int)$i['purchase_id'], (float)$i['weight']);
        if ($v === null) return null;
        $own++;
        if ($v[1] === 1) $proc += $v[0]; else $raw += $v[0];
    }
    if ($consigned > 0 && $own === 0) return null; // pure consigned sale: everything is in 'cons_sale'
    $date = (string)$s['date'];
    [$cash, $bank, $chq] = $pv !== null ? $pv['settle'] : pb_pl_settle($pdo, $cid, true, $id);
    $bid = (int)($s['broker_id'] ?? 0);
    $bn = null;
    if ($bid > 0) { $b = pb_broker_party($pdo, $cid, $bid); $bn = $b ? (string)$b['name'] : null; }
    return ['date' => $date, 'description' => 'فروش #' . ($id > 0 ? $id : '—') . ' ' . (string)($s['buyer'] ?? ''),
        'fields' => ['goods' => $goods, 'discount' => pb_pl_rial($s['discount'] ?? 0), 'vat_rate_milli' => pb_pl_vat_milli($pdo, $cid, $date),
            'has_tax' => ($s['total_tax'] ?? null) === null ? 0 : 1, 'tax_amount' => ($s['total_tax'] ?? null) === null ? 0 : pb_pl_rial($s['total_tax']), // T17.1c stored document tax (gold)
            'cogs_raw' => $raw, 'cogs_proc' => $proc, 'broker_us' => pb_pl_broker_us($s), 'labor' => pb_pl_rial($s['labor_cost'] ?? 0),
            'transport' => pb_pl_rial(($s['transport_cost'] ?? 0) + pb_pl_weigh_own($s)), 'recv_cash' => $cash, 'recv_bank' => $bank, 'cheque_in' => $chq],
        'dims' => ['party' => pb_pl_party_tid($pdo, $cid, $s['buyer'] ?? null), 'broker' => pb_pl_party_tid($pdo, $cid, $bn)]];
}

/** Shared by preturn/sreturn: header + items => common fields. */
function pb_pl_load_return(PDO $pdo, int $cid, int $id, bool $sale): ?array {
    $h = $pdo->prepare('SELECT * FROM ' . ($sale ? 'sale_returns' : 'purchase_returns') . ' WHERE id=? AND customer_id=?');
    $h->execute([$id, $cid]);
    $r = $h->fetch();
    if (!$r) return null;
    $it = $pdo->prepare('SELECT lot_id, kg, unit_price FROM ' . ($sale ? 'sale_return_items' : 'purchase_return_items') . ' WHERE return_id=? AND customer_id=?');
    $it->execute([$id, $cid]);
    $goods = 0; $raw = 0; $proc = 0;
    foreach ($it->fetchAll() as $i) {
        $goods += pb_pl_rial((float)$i['kg'] * (float)$i['unit_price']);
        if ($sale) {
            $v = pb_pl_lot_value($pdo, $cid, (int)$i['lot_id'], (float)$i['kg']);
            if ($v === null) return null;
            if ($v[1] === 1) $proc += $v[0]; else $raw += $v[0];
        }
    }
    $date = (string)$r['date'];
    $mode = (string)$r['refund_mode'];
    $refund = pb_pl_rial($r['refund_amount'] ?? 0);
    $isChq = ($mode === 'cheque_given' || $mode === 'cheque_back');
    $f = ['goods' => $goods, 'vat_rate_milli' => pb_pl_vat_milli($pdo, $cid, $date),
        'has_tax' => $r['tax_amount'] === null ? 0 : 1, 'tax_amount' => $r['tax_amount'] === null ? 0 : pb_pl_rial($r['tax_amount']),
        'refund_cash' => ($mode === 'cash_refund') ? $refund : 0, 'refund_cheque' => $isChq ? $refund : 0];
    if ($sale) { $f['inv_raw'] = $raw; $f['inv_proc'] = $proc; }
    return ['date' => $date, 'description' => ($sale ? 'برگشت از فروش ' : 'برگشت از خرید ') . (int)$r['number'],
        'fields' => $f, 'dims' => ['party' => pb_pl_party_tid($pdo, $cid, $r['party'] ?? null)]];
}

pb_posting_register_loader('purchase', 'pb_pl_load_purchase');
pb_posting_register_loader('sale', 'pb_pl_load_sale');
pb_posting_register_loader('preturn', function (PDO $pdo, int $cid, int $id): ?array { return pb_pl_load_return($pdo, $cid, $id, false); });
pb_posting_register_loader('sreturn', function (PDO $pdo, int $cid, int $id): ?array { return pb_pl_load_return($pdo, $cid, $id, true); });
