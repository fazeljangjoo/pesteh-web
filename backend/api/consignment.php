<?php
declare(strict_types=1);
require_once __DIR__ . '/code_kind.php'; // T1: حرف کد پسته
require_once __DIR__ . '/ddl_guard.php';
/**
 * S08.T08.1 — consignment (امانی) IN: goods owned by others held in our warehouse. Both tiers.
 *
 * Model (DECISIONS B7):
 *  - A consigned lot is a `purchases` row with is_mix=3 (= ownership 'consigned_in'). is_mix=3 keeps it OUT of every `is_mix=0` query
 *    (purchases list, payables, party statement, payments) and out of mix/separation inputs, purchase returns and purchases.delete,
 *    while sales, stock moves, warehouses and barcode codes (KH<id>) work unchanged. It is never valued in OUR inventory:
 *    lot price = 0 (commission mode) or the agreed price (purchase_on_sale); stock moves carry kind consign_in / consign_out.
 *  - Everything money-related is DERIVED from sales at read time (no drift, nothing to re-post):
 *      net revenue N = Σ round(kg × sale unit price,2) of sale_items on the consignment's lots − Σ round(kg × unit_price,2) of sale returns.
 *      commission mode:   commission = percent: round(N × pct/100, 2) | per_kg: round(net_kg × value, 2);  owed to owner = round(N − commission, 2)
 *      purchase_on_sale:  owed to owner = round(net_kg × agreed_price, 2); our margin = N − owed (no commission).
 *      balance payable to owner = owed − Σ consignment_payments.
 *    Our profit on a consigned sale line = commission (or margin); `pb_cons_cost_sql()` gives the effective cost per kg for sales.list/get so
 *    profit = revenue − cost works with the existing client formula.
 *  - Direction 'out' (our goods held by others, T08.2) lives in consignment_out.php; the entry points below dispatch on consignments.direction.
 */
require_once __DIR__ . '/consignment_out.php';
const PB_CONS_MODES = ['commission', 'purchase_on_sale'];
const PB_CONS_LOT_KIND = 3; // purchases.is_mix value of a consigned-in lot

function pb_cons_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS consignments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        direction VARCHAR(4) NOT NULL DEFAULT 'in',
        party VARCHAR(150) NOT NULL,
        cons_date DATE NOT NULL,
        warehouse_uid CHAR(36) NULL,
        settlement_mode VARCHAR(20) NOT NULL DEFAULT 'commission',
        commission_type VARCHAR(10) NOT NULL DEFAULT 'percent',
        commission_value DECIMAL(14,4) NOT NULL DEFAULT 0,
        agreed_price DECIMAL(16,2) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cons_uid (customer_id, uid),
        KEY idx_cons_cust (customer_id, cons_date),
        KEY idx_cons_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS consignment_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        consignment_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        KEY idx_ci_cons (customer_id, consignment_id),
        KEY idx_ci_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS consignment_returns (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        consignment_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        ret_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cr_uid (customer_id, uid),
        KEY idx_cr_cons (customer_id, consignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS consignment_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        consignment_id INT UNSIGNED NOT NULL,
        pay_date DATE NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cp_uid (customer_id, uid),
        KEY idx_cp_cons (customer_id, consignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_cons_fail(PDO $pdo, string $msg, int $code = 400): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    bad($msg, $code);
}

/**
 * SQL expression (aliases: si = sale_items, p = purchases) = effective COST per kg of a sold lot.
 * Own lots: p.price. Consigned-in lots: purchase_on_sale → agreed price; commission → sale unit price − commission per kg
 * (so revenue − cost = our commission).
 */
function pb_cons_cost_sql(): string {
    $unit = 'COALESCE(si.item_price, (SELECT sx.price FROM sales sx WHERE sx.id=si.sale_id AND sx.customer_id=si.customer_id))';
    return "(CASE WHEN p.is_mix=" . PB_CONS_LOT_KIND . " THEN COALESCE((SELECT CASE cs.settlement_mode
            WHEN 'purchase_on_sale' THEN cs.agreed_price
            ELSE (CASE cs.commission_type WHEN 'percent' THEN $unit * (1 - cs.commission_value / 100) ELSE $unit - cs.commission_value END) END
        FROM consignment_items ci JOIN consignments cs ON cs.id=ci.consignment_id AND cs.customer_id=ci.customer_id
        WHERE ci.lot_id=p.id AND ci.customer_id=p.customer_id LIMIT 1), p.price) ELSE p.price END)";
}

/**
 * S36: settlement terms of every consigned lot for the offline apps (backup.export `docs.cons_terms`).
 * One row per consignment_items row: id = consignment_items.id, lot_id + the terms that pb_cons_cost_sql() reads, so the app can
 * compute the same effective cost per kg (purchase_on_sale → agreed_price; commission → sale price minus commission).
 */
function pb_cons_terms_export(PDO $pdo, int $cid): array {
    pb_cons_ensure($pdo);
    $st = $pdo->prepare('SELECT ci.id, ci.lot_id, cs.settlement_mode, cs.commission_type, cs.commission_value, cs.agreed_price
        FROM consignment_items ci JOIN consignments cs ON cs.id=ci.consignment_id AND cs.customer_id=ci.customer_id
        WHERE ci.customer_id=? ORDER BY ci.id');
    $st->execute([$cid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['id' => (int)$r['id'], 'lot_id' => (int)$r['lot_id'], 'settlement_mode' => (string)$r['settlement_mode'],
            'commission_type' => (string)$r['commission_type'], 'commission_value' => (float)$r['commission_value'],
            'agreed_price' => $r['agreed_price'] === null ? null : (float)$r['agreed_price']];
    }
    return $out;
}

function pb_cons_row(array $r): array {
    return ['id' => (int)$r['id'], 'uid' => $r['uid'], 'direction' => $r['direction'], 'party' => $r['party'], 'date' => $r['cons_date'],
        'warehouse_uid' => $r['warehouse_uid'], 'settlement_mode' => $r['settlement_mode'], 'commission_type' => $r['commission_type'],
        'commission_value' => (float)$r['commission_value'], 'agreed_price' => $r['agreed_price'] === null ? null : (float)$r['agreed_price'],
        'status' => $r['status'], 'note' => $r['note']];
}

/** @return array{0: array, 1: array} [consignment row (raw), lot ids] or bad(404) */
function pb_cons_load(PDO $pdo, int $cid, int $id, bool $lock = false): array {
    $st = $pdo->prepare('SELECT * FROM consignments WHERE id=? AND customer_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id, $cid]);
    $c = $st->fetch();
    if (!$c) pb_cons_fail($pdo, 'امانی یافت نشد', 404);
    $li = $pdo->prepare('SELECT lot_id FROM consignment_items WHERE customer_id=? AND consignment_id=? ORDER BY id');
    $li->execute([$cid, $id]);
    return [$c, array_map('intval', $li->fetchAll(PDO::FETCH_COLUMN))];
}

/** Money/quantity position of one consignment, derived from sales, sale returns, owner returns and payments. */
function pb_cons_calc(PDO $pdo, int $cid, array $c, array $lots): array {
    $id = (int)$c['id'];
    $recv = ['kg' => 0.0, 'remaining' => 0.0];
    $in = $pdo->prepare('SELECT COALESCE(SUM(kg),0) FROM consignment_items WHERE customer_id=? AND consignment_id=?');
    $in->execute([$cid, $id]);
    $recv['kg'] = (float)$in->fetchColumn();
    $soldKg = 0.0; $gross = 0.0; $retKg = 0.0; $retRev = 0.0; $remaining = 0.0;
    if ($lots) {
        $ph = implode(',', array_fill(0, count($lots), '?'));
        $rm = $pdo->prepare("SELECT COALESCE(SUM(remaining),0) FROM purchases WHERE customer_id=? AND id IN ($ph)");
        $rm->execute(array_merge([$cid], $lots));
        $remaining = (float)$rm->fetchColumn();
        $q = $pdo->prepare("SELECT si.weight, COALESCE(si.item_price, s.price) AS unit FROM sale_items si
            JOIN sales s ON s.id=si.sale_id AND s.customer_id=si.customer_id WHERE si.customer_id=? AND si.purchase_id IN ($ph)");
        $q->execute(array_merge([$cid], $lots));
        foreach ($q->fetchAll() as $r) { $soldKg += (float)$r['weight']; $gross += round((float)$r['weight'] * (float)$r['unit'], 2); }
        try {
            $q2 = $pdo->prepare("SELECT kg, unit_price FROM sale_return_items WHERE customer_id=? AND lot_id IN ($ph)");
            $q2->execute(array_merge([$cid], $lots));
            foreach ($q2->fetchAll() as $r) { $retKg += (float)$r['kg']; $retRev += round((float)$r['kg'] * (float)$r['unit_price'], 2); }
        } catch (Throwable $e) { /* sale returns not migrated */ }
    }
    $ow = $pdo->prepare('SELECT COALESCE(SUM(kg),0) FROM consignment_returns WHERE customer_id=? AND consignment_id=?');
    $ow->execute([$cid, $id]);
    $toOwnerKg = (float)$ow->fetchColumn();
    $pay = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM consignment_payments WHERE customer_id=? AND consignment_id=?');
    $pay->execute([$cid, $id]);
    $paid = round((float)$pay->fetchColumn(), 2);

    $netKg = round($soldKg - $retKg, 3);
    $netRev = round($gross - $retRev, 2);
    $mode = (string)$c['settlement_mode'];
    $commission = 0.0; $margin = 0.0;
    if ($mode === 'purchase_on_sale') {
        $owed = round($netKg * (float)$c['agreed_price'], 2);
        $margin = round($netRev - $owed, 2);
    } else {
        $commission = $c['commission_type'] === 'per_kg' ? round($netKg * (float)$c['commission_value'], 2) : round($netRev * (float)$c['commission_value'] / 100.0, 2);
        $owed = round($netRev - $commission, 2);
    }
    return ['received_kg' => round($recv['kg'], 3), 'sold_kg' => round($soldKg, 3), 'sale_returned_kg' => round($retKg, 3), 'net_sold_kg' => $netKg,
        'returned_to_owner_kg' => round($toOwnerKg, 3), 'remaining_kg' => round($remaining, 3),
        'gross_revenue' => round($gross, 2), 'returned_revenue' => round($retRev, 2), 'net_revenue' => $netRev,
        'commission' => $commission, 'our_margin' => $margin, 'owed_to_owner' => $owed, 'paid' => $paid, 'balance' => round($owed - $paid, 2),
        'our_income' => $mode === 'purchase_on_sale' ? $margin : $commission,
        'check' => ['revenue_split' => round($netRev - ($mode === 'purchase_on_sale' ? $owed + $margin : $owed + $commission), 2)]]; // must be 0.00
}

function pb_cons_refresh_status(PDO $pdo, int $cid, array $c, array $calc): string {
    $left = $calc['remaining_kg'];
    $recv = $calc['received_kg'];
    $status = $left <= 0.0005 ? 'closed' : (abs($recv - $left) <= 0.0005 ? 'open' : 'partial');
    if ($status !== $c['status']) $pdo->prepare('UPDATE consignments SET status=? WHERE id=? AND customer_id=?')->execute([$status, (int)$c['id'], $cid]);
    return $status;
}

/** consignments.create {uid?, direction:'in', party, date?, warehouse_uid?, settlement_mode, commission_type?, commission_value?, agreed_price?, note?, items:[{type_name,kg,small_sacks?,large_sacks?,ounce?,gram?,ounce_uid?,category_id?}]} */
function pb_cons_create(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM consignments WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $eid = $ex->fetchColumn();
        if ($eid) return pb_cons_get($pdo, $cid, ['id' => (int)$eid]); // idempotent replay (offline queue)
    }
    $dir = (string)($p['direction'] ?? 'in');
    if ($dir === 'out') return pb_consout_create($pdo, $cid, $p, $uid); // S08.T08.2: our goods at a consignee
    if ($dir !== 'in') bad('جهت امانی نامعتبر است');
    $party = str_or_null($p['party'] ?? null, 150);
    if ($party === null) bad('نام صاحب کالا را وارد کنید');
    $mode = (string)($p['settlement_mode'] ?? 'commission');
    if (!in_array($mode, PB_CONS_MODES, true)) bad('روش تسویه نامعتبر است');
    $ctype = (string)($p['commission_type'] ?? 'percent');
    if (!in_array($ctype, ['percent', 'per_kg'], true)) bad('نوع کمیسیون نامعتبر است');
    $cval = num($p['commission_value'] ?? 0);
    $agreed = nullableNum($p['agreed_price'] ?? null);
    if ($mode === 'commission') {
        if ($cval < 0 || ($ctype === 'percent' && $cval > 100) || $cval > 1e9) bad('مقدار کمیسیون نامعتبر است');
    } else {
        if ($agreed === null || $agreed <= 0 || $agreed > 1e13) bad('برای «خرید هنگام فروش» قیمت توافقی هر کیلو را وارد کنید');
        $cval = 0.0; $ctype = 'percent';
    }
    $items = $p['items'] ?? [];
    if (!is_array($items) || count($items) < 1) bad('حداقل یک قلم کالا ثبت کنید');
    if (count($items) > 100) bad('تعداد اقلام بیش از حد مجاز است');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $note = str_or_null($p['note'] ?? null, 500);
    $wh = null;
    if (!empty($p['warehouse_uid']) && is_string($p['warehouse_uid'])) $wh = pb_wh_require($pdo, $cid, $p['warehouse_uid']);
    $fc = $pdo->prepare('SELECT id FROM categories WHERE customer_id=? ORDER BY id LIMIT 1');
    $fc->execute([$cid]);
    $firstCat = (int)$fc->fetchColumn();

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO consignments (customer_id, uid, direction, party, cons_date, warehouse_uid, settlement_mode, commission_type, commission_value, agreed_price, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, 'in', $party, $date, $wh, $mode, $ctype, $cval, $mode === 'purchase_on_sale' ? $agreed : null, $note]);
        $id = (int)$pdo->lastInsertId();
        $insLot = $pdo->prepare('INSERT INTO purchases (customer_id, code, category_id, type_name, date, price, total_weight, small_sack_count, large_sack_count, small_sacks_remaining, large_sacks_remaining, net_weight, remaining, seller, note, ounce, gram, is_mix) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,' . PB_CONS_LOT_KIND . ')');
        $insItem = $pdo->prepare('INSERT INTO consignment_items (customer_id, consignment_id, lot_id, kg, small_sacks, large_sacks) VALUES (?,?,?,?,?,?)');
        foreach ($items as $o) {
            if (!is_array($o)) pb_cons_fail($pdo, 'قلم نامعتبر است');
            $kg = num($o['kg'] ?? 0);
            $name = str_or_null($o['type_name'] ?? null, 100);
            if ($kg <= 0 || $kg > 1e9) pb_cons_fail($pdo, 'وزن هر قلم باید بیشتر از صفر باشد');
            if ($name === null) pb_cons_fail($pdo, 'نوع/نام هر قلم را وارد کنید');
            $sm = num($o['small_sacks'] ?? 0); $lg = num($o['large_sacks'] ?? 0);
            if ($sm < 0 || $lg < 0 || $sm > 1e7 || $lg > 1e7) pb_cons_fail($pdo, 'تعداد گونی نامعتبر است');
            $cat = (int)($o['category_id'] ?? 0);
            if ($cat <= 0) $cat = $firstCat;
            if ($cat <= 0 || !ownsCategory($pdo, $cid, $cat)) pb_cons_fail($pdo, 'دستهٔ کالا یافت نشد', 404);
            $total = round($kg + $sm * 0.1 + $lg * 0.2, 3); // gross = net + sack tare (same rule as purchases)
            $price = $mode === 'purchase_on_sale' ? (float)$agreed : 0.0;
            $insLot->execute([$cid, null, $cat, $name, $date, $price, $total, $sm, $lg, $sm, $lg, $kg, $kg, $party, $note,
                nullableNum($o['ounce'] ?? null), nullableNum($o['gram'] ?? null)]);
            $lotId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE purchases SET code=? WHERE id=? AND customer_id=?')->execute([pb_code_with_id('C', (int)$lotId), $lotId, $cid]);
            $lp = ['warehouse_uid' => $wh];
            if (array_key_exists('ounce_uid', $o)) $lp['ounce_uid'] = $o['ounce_uid'];
            pb_wh_apply_lot($pdo, $cid, $lotId, $lp, true);
            $insItem->execute([$cid, $id, $lotId, $kg, $sm, $lg]);
        }
        pb_stock_post_doc($pdo, $cid, 'consign', $id);
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'cons_in', $id); // S16.T16.5b (gold): memo only
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cons_get($pdo, $cid, ['id' => $id]);
}

/** True when anything was sold / returned / paid on the consignment (then it cannot be deleted). Returns a reason or null. */
function pb_cons_in_use(PDO $pdo, int $cid, int $id, array $lots): ?string {
    if ($lots) {
        $ph = implode(',', array_fill(0, count($lots), '?'));
        $q = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE customer_id=? AND id IN ($ph) AND ABS(net_weight - remaining) > 0.000001");
        $q->execute(array_merge([$cid], $lots));
        if ((int)$q->fetchColumn() > 0) return 'از این امانی فروش یا برگشت به مالک انجام شده است';
        foreach (['sale_items' => 'purchase_id', 'sale_return_items' => 'lot_id', 'proforma_items' => 'lot_id', 'stock_transfers' => 'lot_id'] as $t => $col) {
            try {
                $q = $pdo->prepare("SELECT 1 FROM $t WHERE customer_id=? AND $col IN ($ph) LIMIT 1");
                $q->execute(array_merge([$cid], $lots));
                if ($q->fetchColumn()) return 'کالای این امانی در فروش/انتقال استفاده شده است';
            } catch (Throwable $e) { /* optional table absent / other column name */ }
        }
    }
    $q = $pdo->prepare('SELECT 1 FROM consignment_payments WHERE customer_id=? AND consignment_id=? LIMIT 1');
    $q->execute([$cid, $id]);
    if ($q->fetchColumn()) return 'برای این امانی پرداخت ثبت شده است';
    return null;
}

function pb_cons_delete(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        [$c, $lots] = pb_cons_load($pdo, $cid, $id, true);
        if ($c['direction'] === 'out') { pb_consout_delete_in_tx($pdo, $cid, $c); $pdo->commit(); return ['ok' => true]; } // S08.T08.2
        $why = pb_cons_in_use($pdo, $cid, $id, $lots);
        if ($why !== null) pb_cons_fail($pdo, $why . '؛ حذف ممکن نیست', 409);
        $rets = $pdo->prepare('SELECT id FROM consignment_returns WHERE customer_id=? AND consignment_id=?');
        $rets->execute([$cid, $id]);
        foreach ($rets->fetchAll(PDO::FETCH_COLUMN) as $rid) pb_stock_reverse_doc($pdo, $cid, 'consign_ret', (int)$rid);
        pb_stock_reverse_doc($pdo, $cid, 'consign', $id);
        if (function_exists('pb_plc_unpost')) pb_plc_unpost($pdo, $cid, 'cons_in', $id); // S16.T16.5b
        foreach ($lots as $lot) {
            pb_stock_drop_lot_transfers($pdo, $cid, $lot);
            $pdo->prepare('DELETE FROM purchases WHERE id=? AND customer_id=? AND is_mix=' . PB_CONS_LOT_KIND)->execute([$lot, $cid]);
        }
        foreach (['consignment_items', 'consignment_returns', 'consignment_payments'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE customer_id=? AND consignment_id=?")->execute([$cid, $id]);
        }
        $pdo->prepare('DELETE FROM consignments WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}

/** consignments.get {id} -> consignment + lots + returns + payments + position (calc). */
function pb_cons_get(PDO $pdo, int $cid, array $p): array {
    [$c, $lots] = pb_cons_load($pdo, $cid, (int)($p['id'] ?? 0));
    if ($c['direction'] === 'out') return pb_consout_get($pdo, $cid, $c); // S08.T08.2
    $row = pb_cons_row($c);
    $li = $pdo->prepare('SELECT i.lot_id, i.kg, i.small_sacks, i.large_sacks, p.code, p.type_name, p.remaining, p.small_sacks_remaining, p.large_sacks_remaining
        FROM consignment_items i LEFT JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.customer_id=? AND i.consignment_id=? ORDER BY i.id');
    $li->execute([$cid, $row['id']]);
    $row['lots'] = [];
    foreach ($li->fetchAll() as $r) $row['lots'][] = ['lot_id' => (int)$r['lot_id'], 'code' => $r['code'], 'type_name' => $r['type_name'], 'kg' => (float)$r['kg'],
        'small_sacks' => (float)$r['small_sacks'], 'large_sacks' => (float)$r['large_sacks'], 'remaining' => $r['remaining'] === null ? null : (float)$r['remaining'],
        'small_sacks_remaining' => $r['small_sacks_remaining'] === null ? null : (float)$r['small_sacks_remaining'],
        'large_sacks_remaining' => $r['large_sacks_remaining'] === null ? null : (float)$r['large_sacks_remaining']];
    $rt = $pdo->prepare('SELECT id, uid, lot_id, kg, small_sacks, large_sacks, ret_date, note FROM consignment_returns WHERE customer_id=? AND consignment_id=? ORDER BY ret_date, id');
    $rt->execute([$cid, $row['id']]);
    $row['returns'] = [];
    foreach ($rt->fetchAll() as $r) $row['returns'][] = ['id' => (int)$r['id'], 'uid' => $r['uid'], 'lot_id' => (int)$r['lot_id'], 'kg' => (float)$r['kg'],
        'small_sacks' => (float)$r['small_sacks'], 'large_sacks' => (float)$r['large_sacks'], 'date' => $r['ret_date'], 'note' => $r['note']];
    $py = $pdo->prepare('SELECT id, uid, pay_date, amount, note FROM consignment_payments WHERE customer_id=? AND consignment_id=? ORDER BY pay_date, id');
    $py->execute([$cid, $row['id']]);
    $row['payments'] = [];
    foreach ($py->fetchAll() as $r) $row['payments'][] = ['id' => (int)$r['id'], 'uid' => $r['uid'], 'date' => $r['pay_date'], 'amount' => (float)$r['amount'], 'note' => $r['note']];
    $row['position'] = pb_cons_calc($pdo, $cid, $c, $lots);
    $row['status'] = pb_cons_refresh_status($pdo, $cid, $c, $row['position']);
    $row['deletable'] = pb_cons_in_use($pdo, $cid, $row['id'], $lots) === null;
    return $row;
}

/** consignments.list {from?, to?, party?, status?} */
function pb_cons_list(PDO $pdo, int $cid, array $p): array {
    $sql = 'SELECT * FROM consignments WHERE customer_id=?';
    $args = [$cid];
    if (($p['from'] ?? '') !== '') { $sql .= ' AND cons_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND cons_date <= ?'; $args[] = vdate($p['to']); }
    if (($p['party'] ?? '') !== '') { $sql .= ' AND party = ?'; $args[] = (string)$p['party']; }
    $st = $pdo->prepare($sql . ' ORDER BY cons_date DESC, id DESC LIMIT 500');
    $st->execute($args);
    $items = [];
    foreach ($st->fetchAll() as $c) {
        if ($c['direction'] === 'out') { // S08.T08.2
            $x = pb_consout_list_row($pdo, $cid, $c);
            if (($p['status'] ?? '') !== '' && $x['status'] !== (string)$p['status']) continue;
            $items[] = $x;
            continue;
        }
        $li = $pdo->prepare('SELECT lot_id FROM consignment_items WHERE customer_id=? AND consignment_id=?');
        $li->execute([$cid, (int)$c['id']]);
        $calc = pb_cons_calc($pdo, $cid, $c, array_map('intval', $li->fetchAll(PDO::FETCH_COLUMN)));
        $c['status'] = pb_cons_refresh_status($pdo, $cid, $c, $calc);
        if (($p['status'] ?? '') !== '' && $c['status'] !== (string)$p['status']) continue;
        $x = pb_cons_row($c);
        $x['received_kg'] = $calc['received_kg'];
        $x['remaining_kg'] = $calc['remaining_kg'];
        $x['net_sold_kg'] = $calc['net_sold_kg'];
        $x['owed_to_owner'] = $calc['owed_to_owner'];
        $x['paid'] = $calc['paid'];
        $x['balance'] = $calc['balance'];
        $items[] = $x;
    }
    return ['items' => $items];
}

/** consignments.return {consignment_id, lot_id, kg, small_sacks?, large_sacks?, date?, note?, uid?, warehouse_uid?} — give goods back to the owner (consign_out). */
function pb_cons_return(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    $cons = (int)($p['consignment_id'] ?? 0);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM consignment_returns WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        if ($ex->fetchColumn()) return pb_cons_get($pdo, $cid, ['id' => $cons]);
    }
    if ($cons > 0 && pb_consout_is_out($pdo, $cid, $cons)) return pb_consout_return($pdo, $cid, $p, $uid, $cons); // S08.T08.2: unsold goods back to us
    $lot = (int)($p['lot_id'] ?? 0);
    $kg = num($p['kg'] ?? 0);
    $sm = num($p['small_sacks'] ?? 0); $lg = num($p['large_sacks'] ?? 0);
    if ($kg <= 0 || $kg > 1e9) bad('وزن برگشتی باید بیشتر از صفر باشد');
    if ($sm < 0 || $lg < 0) bad('تعداد گونی نامعتبر است');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $pdo->beginTransaction();
    try {
        [$c, $lots] = pb_cons_load($pdo, $cid, $cons, true);
        if (!in_array($lot, $lots, true)) pb_cons_fail($pdo, 'این لات جزو این امانی نیست', 404);
        $lk = $pdo->prepare('SELECT remaining, type_name, small_sacks_remaining, large_sacks_remaining FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $lk->execute([$lot, $cid]);
        $row = $lk->fetch();
        if (!$row) pb_cons_fail($pdo, 'لات یافت نشد', 404);
        if ($kg - (float)$row['remaining'] > 1e-6) pb_cons_fail($pdo, 'موجودی «' . $row['type_name'] . '» برای برگشت کافی نیست');
        checkSacksAvailable($pdo, $row, $sm, $lg);
        $wh = pb_stock_pick_wh_or_bad($pdo, $cid, $lot, $kg, isset($p['warehouse_uid']) && is_string($p['warehouse_uid']) ? $p['warehouse_uid'] : null);
        $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?')
            ->execute([$kg, $sm, $lg, $lot, $cid]);
        $pdo->prepare('INSERT INTO consignment_returns (customer_id, uid, consignment_id, lot_id, kg, small_sacks, large_sacks, warehouse_uid, ret_date, note) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, $cons, $lot, $kg, $sm, $lg, $wh, $date, str_or_null($p['note'] ?? null, 500)]);
        pb_stock_post_doc($pdo, $cid, 'consign_ret', (int)$pdo->lastInsertId());
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cons_get($pdo, $cid, ['id' => $cons]);
}

/** consignments.return_delete {id} — undo a return to the owner (goods come back into stock). */
function pb_cons_return_delete(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM consignment_returns WHERE id=? AND customer_id=? FOR UPDATE');
        $st->execute([$id, $cid]);
        $r = $st->fetch();
        if (!$r) pb_cons_fail($pdo, 'برگشت یافت نشد', 404);
        if (pb_consout_is_out($pdo, $cid, (int)$r['consignment_id'])) { // S08.T08.2: stock never left our ownership; only the ledger moves
            pb_consout_return_undo($pdo, $cid, $r);
        } else {
        $pdo->prepare('UPDATE purchases SET remaining = remaining + ?, small_sacks_remaining = small_sacks_remaining + ?, large_sacks_remaining = large_sacks_remaining + ? WHERE id=? AND customer_id=?')
            ->execute([(float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks'], (int)$r['lot_id'], $cid]);
        pb_stock_reverse_doc($pdo, $cid, 'consign_ret', $id);
        $pdo->prepare('DELETE FROM consignment_returns WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        }
        $pdo->commit();
        $cons = (int)$r['consignment_id'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cons_get($pdo, $cid, ['id' => $cons]);
}

/** consignments.pay {consignment_id, amount, date?, note?, uid?} — settlement payment to the owner. */
function pb_cons_pay(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    $cons = (int)($p['consignment_id'] ?? 0);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM consignment_payments WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        if ($ex->fetchColumn()) return pb_cons_get($pdo, $cid, ['id' => $cons]);
    }
    $amt = num($p['amount'] ?? 0);
    if ($amt <= 0 || $amt > 1e13) bad('مبلغ پرداخت نامعتبر است');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    [$pc] = pb_cons_load($pdo, $cid, $cons);
    if ($pc['direction'] === 'out') bad('برای امانیِ ارسالی پرداخت ثبت نمی‌شود؛ دریافت وجه را در همان فروش/دریافت‌ها ثبت کنید'); // S08.T08.2
    // پرداخت + سند حسابداری در یک تراکنش: اگر ثبت سند خطا بدهد (مثلاً حساب نقش‌ها تنظیم نشده)، ردیف پرداخت هم برنگردد و بدون سند نماند
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO consignment_payments (customer_id, uid, consignment_id, pay_date, amount, note) VALUES (?,?,?,?,?,?)')
            ->execute([$cid, $uid, $cons, $date, round($amt, 2), str_or_null($p['note'] ?? null, 500)]);
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'cons_pay', (int)$pdo->lastInsertId()); // S16.T16.5b (gold): Dr owner payable / Cr cash
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cons_get($pdo, $cid, ['id' => $cons]);
}

function pb_cons_pay_delete(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT consignment_id FROM consignment_payments WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $cons = $st->fetchColumn();
    if ($cons === false) bad('پرداخت یافت نشد', 404);
    $pdo->beginTransaction();
    try {
        if (function_exists('pb_plc_unpost')) pb_plc_unpost($pdo, $cid, 'cons_pay', (int)$p['id']); // S16.T16.5b
        $pdo->prepare('DELETE FROM consignment_payments WHERE id=? AND customer_id=?')->execute([(int)$p['id'], $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cons_get($pdo, $cid, ['id' => (int)$cons]);
}

/** consignments.statement {party} — per-owner statement: every consignment of that owner + totals (what we owe him). */
function pb_cons_statement(PDO $pdo, int $cid, array $p): array {
    if ((string)($p['direction'] ?? 'in') === 'out') return pb_consout_statement($pdo, $cid, $p); // S08.T08.2
    $party = str_or_null($p['party'] ?? null, 150);
    if ($party === null) bad('نام صاحب کالا را وارد کنید');
    $st = $pdo->prepare('SELECT id FROM consignments WHERE customer_id=? AND party=? AND direction=\'in\' ORDER BY cons_date, id');
    $st->execute([$cid, $party]);
    $rows = [];
    $tot = ['received_kg' => 0.0, 'net_sold_kg' => 0.0, 'remaining_kg' => 0.0, 'returned_to_owner_kg' => 0.0, 'net_revenue' => 0.0, 'commission' => 0.0,
        'our_margin' => 0.0, 'owed_to_owner' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $g = pb_cons_get($pdo, $cid, ['id' => (int)$id]);
        $rows[] = ['id' => $g['id'], 'date' => $g['date'], 'status' => $g['status'], 'settlement_mode' => $g['settlement_mode'], 'position' => $g['position']];
        foreach ($tot as $k => $_) $tot[$k] += (float)$g['position'][$k];
    }
    foreach ($tot as $k => $v) $tot[$k] = round($v, in_array($k, ['received_kg', 'net_sold_kg', 'remaining_kg', 'returned_to_owner_kg'], true) ? 3 : 2);
    return ['party' => $party, 'consignments' => $rows, 'totals' => $tot];
}

/** reports.consigned_stock {} — kg/sacks of consigned goods still in our warehouse, by owner and by owner×type. NOT part of own inventory. */
function pb_cons_stock_report(PDO $pdo, int $cid, array $p): array {
    if ((string)($p['direction'] ?? 'in') === 'out') return pb_consout_stock_report($pdo, $cid); // S08.T08.2
    $st = $pdo->prepare('SELECT c.party, p.type_name, COUNT(*) AS lots, SUM(p.remaining) AS kg, SUM(p.small_sacks_remaining) AS s, SUM(p.large_sacks_remaining) AS l
        FROM consignment_items i JOIN consignments c ON c.id=i.consignment_id AND c.customer_id=i.customer_id
        JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id
        WHERE i.customer_id=? AND c.direction=\'in\' GROUP BY c.party, p.type_name HAVING SUM(p.remaining) > 0.0005 ORDER BY c.party, p.type_name');
    $st->execute([$cid]);
    $by = []; $total = 0.0;
    foreach ($st->fetchAll() as $r) {
        $k = (string)$r['party'];
        if (!isset($by[$k])) $by[$k] = ['party' => $k, 'kg' => 0.0, 'small_sacks' => 0.0, 'large_sacks' => 0.0, 'types' => []];
        $by[$k]['kg'] += (float)$r['kg']; $by[$k]['small_sacks'] += (float)$r['s']; $by[$k]['large_sacks'] += (float)$r['l'];
        $by[$k]['types'][] = ['type_name' => $r['type_name'], 'lots' => (int)$r['lots'], 'kg' => round((float)$r['kg'], 3)];
        $total += (float)$r['kg'];
    }
    foreach ($by as &$o) { $o['kg'] = round($o['kg'], 3); $o['small_sacks'] = round($o['small_sacks'], 2); $o['large_sacks'] = round($o['large_sacks'], 2); }
    unset($o);
    return ['owners' => array_values($by), 'total_kg' => round($total, 3)];
}
