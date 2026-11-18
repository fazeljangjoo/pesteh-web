<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S08.T08.2 — consignment OUT (امانیِ ارسالی): OUR goods held by others (consignees). Both tiers. Loaded by consignment.php.
 *
 * Model (DECISIONS B7), reusing the T08.1 tables with consignments.direction='out':
 *  - Lots are our own existing purchases rows (is_mix 0/1/2). Sending does NOT change purchases.remaining or price: the goods are still
 *    ours and still valued. The stock ledger moves them from a real warehouse into ONE virtual warehouse per customer
 *    (ent_warehouses.type='consignment', «نزد امانت‌گیرندگان») with stock docs `cons_send` / `cons_back` (kinds consignee_out/_in,
 *    consignee_ret_out/_in). pb_stock_pick_wh() ignores that warehouse for automatic picking, so ordinary sales/mixes never use it.
 *  - consignment_items = sent lines (+ warehouse_uid = source warehouse); consignment_returns = unsold goods back to OUR warehouse;
 *    consignment_sales = «sold» reports (v27). A report creates an ordinary sale through pb_sale_create_core() taking the goods from the
 *    virtual warehouse (sale_items.warehouse_uid = virtual), so revenue, receipts, COGS and profit are exactly the normal sale flow.
 *      settlement_mode 'to_consignee'  -> sale.buyer = consignee;  'via_consignee' -> sale.buyer = end customer (given in the report).
 *  - outstanding kg = sent − sold − returned (per consignment and per lot). Profit exists ONLY on sold lines (sales); goods at the
 *    consignee only carry `outstanding_value` at lot cost. Money collected from the consignee/customer uses the normal sale receipts.
 *  - A consignment with sold/returned lines cannot be deleted. Sales linked to a consignment cannot be edited (sales.update refuses);
 *    deleting such a sale (sales.delete) puts the goods back at the consignee and drops the link (pb_consout_on_sale_delete).
 */
const PB_CONSOUT_MODES = ['to_consignee', 'via_consignee'];

function pb_consout_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS consignment_sales (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        consignment_id INT UNSIGNED NOT NULL,
        sale_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        sold_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_csl_uid (customer_id, uid),
        UNIQUE KEY uq_csl_sale (customer_id, sale_id),
        KEY idx_csl_cons (customer_id, consignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->query("SHOW COLUMNS FROM consignment_items LIKE 'warehouse_uid'");
    if (!($st && $st->fetch())) $pdo->exec('ALTER TABLE consignment_items ADD COLUMN warehouse_uid CHAR(36) NULL');
}

/** The customer's virtual warehouse for goods at consignees (created/re-activated on demand). */
function pb_consout_wh_uid(PDO $pdo, int $cid): string {
    $st = $pdo->prepare("SELECT uid, active FROM ent_warehouses WHERE customer_id=? AND type='consignment' AND deleted_at IS NULL ORDER BY id LIMIT 1");
    $st->execute([$cid]);
    $r = $st->fetch();
    if ($r) {
        if ((int)$r['active'] !== 1) {
            $rev = pb_entity_next_rev($pdo, $cid, 'warehouses');
            $pdo->prepare('UPDATE ent_warehouses SET active=1, rev=? WHERE customer_id=? AND uid=?')->execute([$rev, $cid, $r['uid']]);
        }
        return (string)$r['uid'];
    }
    $uid = pb_uuid4();
    $rev = pb_entity_next_rev($pdo, $cid, 'warehouses');
    $pdo->prepare('INSERT INTO ent_warehouses (uid, customer_id, rev, created_by, name, type, active, is_default) VALUES (?,?,?,?,?,?,1,0)')
        ->execute([$uid, $cid, $rev, null, 'نزد امانت‌گیرندگان', 'consignment']);
    return $uid;
}

/** sales.create / sales.update: the virtual warehouse can only be used by consignments.sold. */
function pb_consout_guard_payload(PDO $pdo, int $cid, array $payload): void {
    $w = $payload['warehouse_uid'] ?? null;
    if (!is_string($w) || $w === '') return;
    if (in_array($w, pb_wh_virtual_uids($pdo, $cid), true)) bad('موجودیِ نزد امانت‌گیرنده فقط از طریق «گزارش فروش امانی» قابل فروش است');
}

/** sales.update: a sale created by a consignee report is edited only by deleting it and reporting again. */
function pb_consout_guard_sale_edit(PDO $pdo, int $cid, int $saleId): void {
    try {
        $q = $pdo->prepare('SELECT 1 FROM consignment_sales WHERE customer_id=? AND sale_id=? LIMIT 1');
        $q->execute([$cid, $saleId]);
        if ($q->fetchColumn()) bad('این فروش از «گزارش فروشِ امانی» ثبت شده و قابل ویرایش نیست؛ آن را حذف کنید و دوباره از صفحهٔ امانی ثبت کنید');
    } catch (PDOException $e) { /* table not migrated yet */ }
}

/** sales.delete (inside its transaction): drop the link; the sale's ledger reversal puts the goods back into the virtual warehouse. */
function pb_consout_on_sale_delete(PDO $pdo, int $cid, int $saleId): void {
    try {
        if (function_exists('pb_plc_unpost')) { // S16.T16.5b: release vouchers of the links about to disappear
            $lk = $pdo->prepare('SELECT id FROM consignment_sales WHERE customer_id=? AND sale_id=?');
            $lk->execute([$cid, $saleId]);
            foreach ($lk->fetchAll(PDO::FETCH_COLUMN) as $lid) pb_plc_unpost($pdo, $cid, 'cons_out_sold', (int)$lid);
        }
        $pdo->prepare('DELETE FROM consignment_sales WHERE customer_id=? AND sale_id=?')->execute([$cid, $saleId]);
    } catch (PDOException $e) { /* table not migrated yet */ }
}

function pb_consout_is_out(PDO $pdo, int $cid, int $consId): bool {
    $st = $pdo->prepare('SELECT direction FROM consignments WHERE id=? AND customer_id=?');
    $st->execute([$consId, $cid]);
    return $st->fetchColumn() === 'out';
}

/** Stock-ledger move set for docs cons_send (id = consignment) and cons_back (id = consignment_returns row). Item = [lot,kind,kg,small,large,cost,date,warehouse]. */
function pb_consout_desired(PDO $pdo, int $cid, string $docType, int $docId): array {
    $out = [];
    if ($docType === 'cons_send') {
        $cd = $pdo->prepare('SELECT cons_date, direction FROM consignments WHERE id=? AND customer_id=?');
        $cd->execute([$docId, $cid]);
        $c = $cd->fetch();
        if (!$c || $c['direction'] !== 'out') return [];
        $virt = pb_consout_wh_uid($pdo, $cid);
        $it = $pdo->prepare('SELECT i.lot_id, i.kg, i.small_sacks, i.large_sacks, i.warehouse_uid, ' . PB_BROKER_COST_SQL . ' AS price FROM consignment_items i
            LEFT JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.consignment_id=? AND i.customer_id=? ORDER BY i.id');
        $it->execute([$docId, $cid]);
        foreach ($it->fetchAll() as $r) {
            $cost = $r['price'] === null ? null : (float)$r['price'];
            $out[] = [(int)$r['lot_id'], 'consignee_out', -(float)$r['kg'], -(float)$r['small_sacks'], -(float)$r['large_sacks'], $cost, (string)$c['cons_date'], ($r['warehouse_uid'] ?? null) ?: null];
            $out[] = [(int)$r['lot_id'], 'consignee_in', (float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks'], $cost, (string)$c['cons_date'], $virt];
        }
    } elseif ($docType === 'cons_back') {
        $st = $pdo->prepare('SELECT r.lot_id, r.kg, r.small_sacks, r.large_sacks, r.warehouse_uid, r.ret_date, c.direction, ' . PB_BROKER_COST_SQL . ' AS price
            FROM consignment_returns r JOIN consignments c ON c.id=r.consignment_id AND c.customer_id=r.customer_id
            LEFT JOIN purchases p ON p.id=r.lot_id AND p.customer_id=r.customer_id WHERE r.id=? AND r.customer_id=?');
        $st->execute([$docId, $cid]);
        $r = $st->fetch();
        if (!$r || $r['direction'] !== 'out') return [];
        $virt = pb_consout_wh_uid($pdo, $cid);
        $cost = $r['price'] === null ? null : (float)$r['price'];
        $out[] = [(int)$r['lot_id'], 'consignee_ret_out', -(float)$r['kg'], -(float)$r['small_sacks'], -(float)$r['large_sacks'], $cost, (string)$r['ret_date'], $virt];
        $out[] = [(int)$r['lot_id'], 'consignee_ret_in', (float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks'], $cost, (string)$r['ret_date'], ($r['warehouse_uid'] ?? null) ?: null];
    }
    return $out;
}

/** Sale returns (end customer gave goods back) of the given sales: [sale_id => ['kg','rev','back']]; `back` = kg returned INTO the virtual warehouse (i.e. back at the consignee). */
function pb_consout_sale_returns(PDO $pdo, int $cid, array $saleIds): array {
    $res = [];
    if (!$saleIds) return $res;
    $virt = pb_wh_virtual_uids($pdo, $cid);
    try {
        $ph = implode(',', array_fill(0, count($saleIds), '?'));
        $q = $pdo->prepare("SELECT sale_id, SUM(kg), SUM(kg * unit_price), SUM(CASE WHEN warehouse_uid = ? THEN kg ELSE 0 END) FROM sale_return_items
            WHERE customer_id=? AND sale_id IN ($ph) GROUP BY sale_id");
        $q->execute(array_merge([$virt[0] ?? ''], [$cid], array_map('intval', $saleIds)));
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $res[(int)$r[0]] = ['kg' => (float)$r[1], 'rev' => (float)$r[2], 'back' => (float)$r[3]];
    } catch (Throwable $e) { /* sale returns not migrated */ }
    return $res;
}

/**
 * sent / sold / returned kg of one lot in one consignment. outstanding = sent − sold + back − returned, where `back` = sold goods that the
 * end customer returned INTO the consignee warehouse (sale return default). $lock = locking reads (latest committed rows) inside a transaction.
 */
function pb_consout_lot_position(PDO $pdo, int $cid, int $consId, int $lot, bool $lock = false): array {
    $suffix = $lock ? ' FOR UPDATE' : '';
    $q = $pdo->prepare('SELECT COALESCE(SUM(kg),0) FROM consignment_items WHERE customer_id=? AND consignment_id=? AND lot_id=?' . $suffix);
    $q->execute([$cid, $consId, $lot]);
    $sent = (float)$q->fetchColumn();
    $q = $pdo->prepare('SELECT sale_id, kg FROM consignment_sales WHERE customer_id=? AND consignment_id=? AND lot_id=?' . $suffix);
    $q->execute([$cid, $consId, $lot]);
    $sold = 0.0; $ids = [];
    foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) { $ids[] = (int)$r[0]; $sold += (float)$r[1]; }
    $back = 0.0;
    foreach (pb_consout_sale_returns($pdo, $cid, $ids) as $x) $back += $x['back'];
    $q = $pdo->prepare('SELECT COALESCE(SUM(kg),0) FROM consignment_returns WHERE customer_id=? AND consignment_id=? AND lot_id=?' . $suffix);
    $q->execute([$cid, $consId, $lot]);
    $ret = (float)$q->fetchColumn();
    return ['sent' => round($sent, 3), 'sold' => round($sold, 3), 'back' => round($back, 3), 'returned' => round($ret, 3), 'outstanding' => round($sent - $sold + $back - $ret, 3)];
}

/** consignments.create (direction 'out') {party, date?, settlement_mode?, note?, items:[{lot_id, kg, small_sacks?, large_sacks?, warehouse_uid?}]} */
function pb_consout_create(PDO $pdo, int $cid, array $p, ?string $uid): array {
    $party = str_or_null($p['party'] ?? null, 150);
    if ($party === null) bad('نام امانت‌گیرنده را وارد کنید');
    $mode = (string)($p['settlement_mode'] ?? '');
    if ($mode === '' || $mode === 'commission') $mode = 'to_consignee';
    if (!in_array($mode, PB_CONSOUT_MODES, true)) bad('روش فروش امانی نامعتبر است');
    $items = $p['items'] ?? [];
    if (!is_array($items) || count($items) < 1) bad('حداقل یک قلم کالا را انتخاب کنید');
    if (count($items) > 100) bad('تعداد اقلام بیش از حد مجاز است');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $note = str_or_null($p['note'] ?? null, 500);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO consignments (customer_id, uid, direction, party, cons_date, warehouse_uid, settlement_mode, commission_type, commission_value, agreed_price, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, 'out', $party, $date, null, $mode, 'percent', 0, null, $note]);
        $id = (int)$pdo->lastInsertId();
        $insItem = $pdo->prepare('INSERT INTO consignment_items (customer_id, consignment_id, lot_id, kg, small_sacks, large_sacks, warehouse_uid) VALUES (?,?,?,?,?,?,?)');
        $seen = [];
        foreach ($items as $o) {
            if (!is_array($o)) pb_cons_fail($pdo, 'قلم نامعتبر است');
            $lot = (int)($o['lot_id'] ?? 0);
            $kg = round(num($o['kg'] ?? 0), 3);
            if ($lot <= 0) pb_cons_fail($pdo, 'جنس را از انبار انتخاب کنید');
            if ($kg <= 0 || $kg > 1e9) pb_cons_fail($pdo, 'وزن هر قلم باید بیشتر از صفر باشد');
            if (isset($seen[$lot])) pb_cons_fail($pdo, 'یک جنس را فقط یک بار در هر امانی بیاورید');
            $seen[$lot] = true;
            $sm = num($o['small_sacks'] ?? 0); $lg = num($o['large_sacks'] ?? 0);
            if ($sm < 0 || $lg < 0 || $sm > 1e7 || $lg > 1e7) pb_cons_fail($pdo, 'تعداد گونی نامعتبر است');
            $lk = $pdo->prepare('SELECT remaining, small_sacks_remaining, large_sacks_remaining, type_name, is_mix FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
            $lk->execute([$lot, $cid]);
            $row = $lk->fetch();
            if (!$row) pb_cons_fail($pdo, 'قلم انتخاب‌شده در انبار یافت نشد', 404);
            if ((int)$row['is_mix'] === PB_CONS_LOT_KIND) pb_cons_fail($pdo, 'کالای امانیِ دیگران را نمی‌توان به امانت داد');
            if ($kg - (float)$row['remaining'] > 1e-6) pb_cons_fail($pdo, 'موجودی «' . $row['type_name'] . '» برای ارسال کافی نیست');
            checkSacksAvailable($pdo, $row, $sm, $lg);
            $whReq = isset($o['warehouse_uid']) && is_string($o['warehouse_uid']) && $o['warehouse_uid'] !== '' ? $o['warehouse_uid'] : null;
            if ($whReq !== null && in_array($whReq, pb_wh_virtual_uids($pdo, $cid), true)) pb_cons_fail($pdo, 'انبار مبدأ نامعتبر است');
            $src = pb_stock_pick_wh_or_bad($pdo, $cid, $lot, $kg, $whReq);
            $insItem->execute([$cid, $id, $lot, $kg, $sm, $lg, $src]);
        }
        pb_stock_post_doc($pdo, $cid, 'cons_send', $id);
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'cons_out', $id); // S16.T16.5b (gold): memo at lot cost
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cons_get($pdo, $cid, ['id' => $id]);
}

/** Position of one OUT consignment (+ per-lot lines and money on sold lines only). */
function pb_consout_calc(PDO $pdo, int $cid, array $c): array {
    $id = (int)$c['id'];
    // sold lines (normal sale rows) with their sale returns: revenue/cost/profit are NET of returned goods
    $sales = []; $soldBy = []; $backBy = [];
    $sq = $pdo->prepare('SELECT cs.id AS link_id, cs.uid, cs.sale_id, cs.lot_id, cs.sold_date, si.weight, COALESCE(si.item_price, s.price) AS unit, s.buyer, ' . PB_BROKER_COST_SQL . ' AS cost
        FROM consignment_sales cs JOIN sale_items si ON si.sale_id=cs.sale_id AND si.customer_id=cs.customer_id
        JOIN sales s ON s.id=cs.sale_id AND s.customer_id=cs.customer_id
        JOIN purchases p ON p.id=si.purchase_id AND p.customer_id=si.customer_id
        WHERE cs.customer_id=? AND cs.consignment_id=? ORDER BY cs.sold_date, cs.id');
    $sq->execute([$cid, $id]);
    $rows = $sq->fetchAll();
    $rets = pb_consout_sale_returns($pdo, $cid, array_map(static fn($r) => (int)$r['sale_id'], $rows));
    $rev = 0.0; $cost = 0.0; $soldKg = 0.0; $srKg = 0.0; $srRev = 0.0;
    foreach ($rows as $r) {
        $lot = (int)$r['lot_id']; $w = (float)$r['weight'];
        $rt = $rets[(int)$r['sale_id']] ?? ['kg' => 0.0, 'rev' => 0.0, 'back' => 0.0];
        $line = round($w * (float)$r['unit'], 2) - round($rt['rev'], 2);
        $cl = round(($w - $rt['kg']) * (float)$r['cost'], 2);
        $rev += $line; $cost += $cl; $soldKg += $w; $srKg += $rt['kg']; $srRev += $rt['rev'];
        $soldBy[$lot] = ($soldBy[$lot] ?? 0.0) + $w; $backBy[$lot] = ($backBy[$lot] ?? 0.0) + $rt['back'];
        $sales[] = ['id' => (int)$r['link_id'], 'uid' => $r['uid'], 'sale_id' => (int)$r['sale_id'], 'lot_id' => $lot, 'date' => $r['sold_date'],
            'kg' => round($w, 3), 'returned_kg' => round($rt['kg'], 3), 'unit_price' => (float)$r['unit'], 'revenue' => round($line, 2), 'cost' => $cl,
            'profit' => round($line - $cl, 2), 'buyer' => $r['buyer']];
    }
    $retBy = [];
    $q = $pdo->prepare('SELECT lot_id, SUM(kg) FROM consignment_returns WHERE customer_id=? AND consignment_id=? GROUP BY lot_id');
    $q->execute([$cid, $id]);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $retBy[(int)$r[0]] = (float)$r[1];
    $li = $pdo->prepare('SELECT i.lot_id, SUM(i.kg) AS kg, p.code, p.type_name, ' . PB_BROKER_COST_SQL . ' AS cost FROM consignment_items i
        LEFT JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.customer_id=? AND i.consignment_id=?
        GROUP BY i.lot_id, p.code, p.type_name, p.price, p.broker_payer, p.broker_amount, p.net_weight ORDER BY MIN(i.id)');
    $li->execute([$cid, $id]);
    $lots = []; $sent = 0.0; $out = 0.0; $outValue = 0.0; $backKg = 0.0;
    foreach ($li->fetchAll() as $r) {
        $lot = (int)$r['lot_id'];
        $s = (float)$r['kg']; $so = $soldBy[$lot] ?? 0.0; $bk = $backBy[$lot] ?? 0.0; $re = $retBy[$lot] ?? 0.0; $o = round($s - $so + $bk - $re, 3);
        $lots[] = ['lot_id' => $lot, 'code' => $r['code'], 'type_name' => $r['type_name'], 'sent_kg' => round($s, 3), 'sold_kg' => round($so, 3),
            'back_kg' => round($bk, 3), 'returned_kg' => round($re, 3), 'outstanding_kg' => $o];
        $sent += $s; $out += $o; $backKg += $bk; $outValue += $o * (float)($r['cost'] ?? 0);
    }
    $retKg = array_sum($retBy);
    return ['sent_kg' => round($sent, 3), 'sold_kg' => round($soldKg, 3), 'returned_kg' => round($retKg, 3), 'outstanding_kg' => round($out, 3),
        'sold_revenue' => round($rev, 2), 'sold_cost' => round($cost, 2), 'profit' => round($rev - $cost, 2), 'outstanding_value' => round($outValue, 2),
        'sale_returned_kg' => round($srKg, 3), 'sale_returned_revenue' => round($srRev, 2), 'back_at_consignee_kg' => round($backKg, 3),
        'check' => ['kg' => round($sent - $soldKg + $backKg - $retKg - $out, 3)], // must be 0.000
        'lots' => $lots, 'sales' => $sales];
}

function pb_consout_status(PDO $pdo, int $cid, array $c, array $calc): string {
    $left = $calc['outstanding_kg']; $sent = $calc['sent_kg'];
    $status = $left <= 0.0005 ? 'closed' : (abs($sent - $left) <= 0.0005 ? 'open' : 'partial');
    if ($status !== $c['status']) $pdo->prepare('UPDATE consignments SET status=? WHERE id=? AND customer_id=?')->execute([$status, (int)$c['id'], $cid]);
    return $status;
}

/** consignments.get for an OUT consignment (called by pb_cons_get). */
function pb_consout_get(PDO $pdo, int $cid, array $c): array {
    $row = pb_cons_row($c);
    $pos = pb_consout_calc($pdo, $cid, $c);
    $row['lots'] = $pos['lots'];
    $row['sales'] = $pos['sales'];
    unset($pos['lots'], $pos['sales']);
    $rt = $pdo->prepare('SELECT id, uid, lot_id, kg, small_sacks, large_sacks, warehouse_uid, ret_date, note FROM consignment_returns WHERE customer_id=? AND consignment_id=? ORDER BY ret_date, id');
    $rt->execute([$cid, $row['id']]);
    $row['returns'] = [];
    foreach ($rt->fetchAll() as $r) $row['returns'][] = ['id' => (int)$r['id'], 'uid' => $r['uid'], 'lot_id' => (int)$r['lot_id'], 'kg' => (float)$r['kg'],
        'small_sacks' => (float)$r['small_sacks'], 'large_sacks' => (float)$r['large_sacks'], 'warehouse_uid' => $r['warehouse_uid'], 'date' => $r['ret_date'], 'note' => $r['note']];
    $row['payments'] = [];
    $row['position'] = $pos;
    $row['status'] = pb_consout_status($pdo, $cid, $c, $pos);
    $row['deletable'] = !$row['sales'] && !$row['returns'];
    return $row;
}

/** list row extras for an OUT consignment (called by pb_cons_list). */
function pb_consout_list_row(PDO $pdo, int $cid, array $c): array {
    $calc = pb_consout_calc($pdo, $cid, $c);
    $c['status'] = pb_consout_status($pdo, $cid, $c, $calc);
    $x = pb_cons_row($c);
    foreach (['sent_kg', 'sold_kg', 'returned_kg', 'outstanding_kg', 'sold_revenue', 'profit', 'outstanding_value'] as $k) $x[$k] = $calc[$k];
    $x['remaining_kg'] = $calc['outstanding_kg']; // same name the IN rows use
    $x['net_sold_kg'] = $calc['sold_kg'];
    return $x;
}

/** consignments.sold {consignment_id, lot_id, kg, price, date?, buyer? (required for via_consignee), small_sacks?, large_sacks?, note?, uid?, receipt?, usd_rate?, ...sale fields} */
function pb_consout_sold(PDO $pdo, int $cid, array $p, array $customer): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    $consId = (int)($p['consignment_id'] ?? 0);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT consignment_id, sale_id FROM consignment_sales WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        if ($r = $ex->fetch()) { $g = pb_cons_get($pdo, $cid, ['id' => (int)$r['consignment_id']]); $g['sale_id'] = (int)$r['sale_id']; return $g; } // idempotent replay
    }
    $st = $pdo->prepare('SELECT * FROM consignments WHERE id=? AND customer_id=?');
    $st->execute([$consId, $cid]);
    $c = $st->fetch();
    if (!$c) bad('امانی یافت نشد', 404);
    if ($c['direction'] !== 'out') bad('گزارش فروش فقط برای امانیِ ارسالی (کالای ما نزد دیگران) است');
    $lot = (int)($p['lot_id'] ?? 0);
    $kg = round(num($p['kg'] ?? $p['weight'] ?? 0), 3);
    $price = num($p['price'] ?? $p['item_price'] ?? 0);
    if ($kg <= 0 || $kg > 1e9) bad('وزن فروش باید بیشتر از صفر باشد');
    if ($price <= 0 || $price > 1e13) bad('فی فروش نامعتبر است');
    $pos = pb_consout_lot_position($pdo, $cid, $consId, $lot);
    if ($pos['sent'] <= 0) bad('این جنس جزو این امانی نیست', 404);
    if ($kg - $pos['outstanding'] > 1e-6) bad('وزن فروش از مانده نزد امانت‌گیرنده بیشتر است (مانده: ' . rtrim(rtrim(number_format($pos['outstanding'], 3, '.', ''), '0'), '.') . ' کیلو)');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $note = trim((string)($p['note'] ?? ''));
    if ($c['settlement_mode'] === 'via_consignee') {
        $buyer = str_or_null($p['buyer'] ?? null, 150);
        if ($buyer === null) bad('نام مشتریِ نهایی را وارد کنید');
        $note = trim(mb_substr($note, 0, 450) . ' (از طریق امانت‌گیرنده: ' . $c['party'] . ')');
    } else {
        $buyer = (string)$c['party'];
        $note = mb_substr($note, 0, 480);
    }
    $sp = $p;
    foreach (['uid', 'consignment_id', 'lot_id', 'kg'] as $k) unset($sp[$k]);
    $sp['purchase_id'] = $lot;
    $sp['weight'] = $kg;
    $sp['price'] = $price;
    $sp['item_price'] = $price;
    $sp['date'] = $date;
    $sp['buyer'] = $buyer;
    $sp['note'] = $note === '' ? null : $note;
    $sp['warehouse_uid'] = pb_consout_wh_uid($pdo, $cid); // goods are taken from the virtual warehouse
    $res = pb_sale_create_core($pdo, $cid, $sp, $customer, function (int $saleId) use ($pdo, $cid, $consId, $lot, $kg, $uid, $date): void {
        // inside the sale transaction: serialize on the consignment, re-check the outstanding with locking reads, then link
        $lk = $pdo->prepare('SELECT id FROM consignments WHERE id=? AND customer_id=? FOR UPDATE');
        $lk->execute([$consId, $cid]);
        $now = pb_consout_lot_position($pdo, $cid, $consId, $lot, true);
        if ($kg - $now['outstanding'] > 1e-6) pb_cons_fail($pdo, 'وزن فروش از مانده نزد امانت‌گیرنده بیشتر است');
        $pdo->prepare('INSERT INTO consignment_sales (customer_id, uid, consignment_id, sale_id, lot_id, kg, sold_date) VALUES (?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, $consId, $saleId, $lot, $kg, $date]);
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'cons_out_sold', (int)$pdo->lastInsertId()); // S16.T16.5b: memo release
    });
    $g = pb_cons_get($pdo, $cid, ['id' => $consId]);
    $g['sale_id'] = (int)$res['id'];
    return $g;
}

/** consignments.return for an OUT consignment: unsold goods come back into one of OUR warehouses. */
function pb_consout_return(PDO $pdo, int $cid, array $p, ?string $uid, int $consId): array {
    $lot = (int)($p['lot_id'] ?? 0);
    $kg = round(num($p['kg'] ?? 0), 3);
    $sm = num($p['small_sacks'] ?? 0); $lg = num($p['large_sacks'] ?? 0);
    if ($kg <= 0 || $kg > 1e9) bad('وزن برگشتی باید بیشتر از صفر باشد');
    if ($sm < 0 || $lg < 0 || $sm > 1e7 || $lg > 1e7) bad('تعداد گونی نامعتبر است');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $dest = null;
    if (isset($p['warehouse_uid']) && is_string($p['warehouse_uid']) && $p['warehouse_uid'] !== '') {
        $dest = pb_wh_require($pdo, $cid, $p['warehouse_uid']);
        if (in_array($dest, pb_wh_virtual_uids($pdo, $cid), true)) bad('انبار مقصد نامعتبر است');
    }
    $pdo->beginTransaction();
    try {
        $lk = $pdo->prepare('SELECT id FROM consignments WHERE id=? AND customer_id=? FOR UPDATE');
        $lk->execute([$consId, $cid]);
        if (!$lk->fetchColumn()) pb_cons_fail($pdo, 'امانی یافت نشد', 404);
        $pos = pb_consout_lot_position($pdo, $cid, $consId, $lot, true);
        if ($pos['sent'] <= 0) pb_cons_fail($pdo, 'این جنس جزو این امانی نیست', 404);
        if ($kg - $pos['outstanding'] > 1e-6) pb_cons_fail($pdo, 'وزن برگشتی از مانده نزد امانت‌گیرنده بیشتر است (مانده: ' . rtrim(rtrim(number_format($pos['outstanding'], 3, '.', ''), '0'), '.') . ' کیلو)');
        if ($dest === null) $dest = pb_stock_lot_wh($pdo, $cid, $lot);
        $pdo->prepare('INSERT INTO consignment_returns (customer_id, uid, consignment_id, lot_id, kg, small_sacks, large_sacks, warehouse_uid, ret_date, note) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, $consId, $lot, $kg, $sm, $lg, $dest, $date, str_or_null($p['note'] ?? null, 500)]);
        $retId = (int)$pdo->lastInsertId();
        pb_stock_post_doc($pdo, $cid, 'cons_back', $retId);
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'cons_out_ret', $retId); // S16.T16.5b: memo release
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cons_get($pdo, $cid, ['id' => $consId]);
}

/** Undo a return of an OUT consignment inside the caller's transaction (goods go back to the consignee). Refused when they were already used. */
function pb_consout_return_undo(PDO $pdo, int $cid, array $r): void {
    $id = (int)$r['id'];
    $bal = pb_stock_wh_balances($pdo, $cid, (int)$r['lot_id']);
    $dest = (string)($r['warehouse_uid'] ?? '') !== '' ? (string)$r['warehouse_uid'] : pb_stock_lot_wh($pdo, $cid, (int)$r['lot_id']);
    $have = $bal[$dest]['kg'] ?? 0.0;
    if ((float)$r['kg'] - $have > PB_STOCK_EPS) pb_cons_fail($pdo, 'بخشی از کالای برگشتی از انبار مقصد مصرف یا منتقل شده است؛ حذف برگشت ممکن نیست', 409);
    pb_stock_reverse_doc($pdo, $cid, 'cons_back', $id);
    if (function_exists('pb_plc_unpost')) pb_plc_unpost($pdo, $cid, 'cons_out_ret', $id); // S16.T16.5b
    $pdo->prepare('DELETE FROM consignment_returns WHERE id=? AND customer_id=?')->execute([$id, $cid]);
}

/** Delete an OUT consignment inside the caller's transaction (only when nothing was sold or returned). */
function pb_consout_delete_in_tx(PDO $pdo, int $cid, array $c): void {
    $id = (int)$c['id'];
    foreach (['consignment_sales' => 'برای این امانی فروش ثبت شده است', 'consignment_returns' => 'از این امانی برگشت ثبت شده است'] as $t => $why) {
        $q = $pdo->prepare("SELECT 1 FROM $t WHERE customer_id=? AND consignment_id=? LIMIT 1");
        $q->execute([$cid, $id]);
        if ($q->fetchColumn()) pb_cons_fail($pdo, $why . '؛ حذف ممکن نیست', 409);
    }
    pb_stock_reverse_doc($pdo, $cid, 'cons_send', $id);
    if (function_exists('pb_plc_unpost')) pb_plc_unpost($pdo, $cid, 'cons_out', $id); // S16.T16.5b
    $pdo->prepare('DELETE FROM consignment_items WHERE customer_id=? AND consignment_id=?')->execute([$cid, $id]);
    $pdo->prepare('DELETE FROM consignments WHERE id=? AND customer_id=?')->execute([$id, $cid]);
}

/** consignments.statement {party, direction:'out'} — per consignee: sent, sold, returned, outstanding (kg) + sold revenue/profit + value at consignee. */
function pb_consout_statement(PDO $pdo, int $cid, array $p): array {
    $party = str_or_null($p['party'] ?? null, 150);
    if ($party === null) bad('نام امانت‌گیرنده را وارد کنید');
    $st = $pdo->prepare("SELECT * FROM consignments WHERE customer_id=? AND party=? AND direction='out' ORDER BY cons_date, id");
    $st->execute([$cid, $party]);
    $rows = [];
    $tot = ['sent_kg' => 0.0, 'sold_kg' => 0.0, 'returned_kg' => 0.0, 'outstanding_kg' => 0.0, 'sold_revenue' => 0.0, 'sold_cost' => 0.0, 'profit' => 0.0, 'outstanding_value' => 0.0];
    $byType = [];
    foreach ($st->fetchAll() as $c) {
        $calc = pb_consout_calc($pdo, $cid, $c);
        $status = pb_consout_status($pdo, $cid, $c, $calc);
        foreach ($calc['lots'] as $l) {
            $k = (string)$l['type_name'];
            if (!isset($byType[$k])) $byType[$k] = ['type_name' => $k, 'sent_kg' => 0.0, 'sold_kg' => 0.0, 'returned_kg' => 0.0, 'outstanding_kg' => 0.0];
            foreach (['sent_kg', 'sold_kg', 'returned_kg', 'outstanding_kg'] as $f) $byType[$k][$f] += (float)$l[$f];
        }
        $pos = $calc; unset($pos['lots'], $pos['sales']);
        $rows[] = ['id' => (int)$c['id'], 'date' => $c['cons_date'], 'status' => $status, 'settlement_mode' => $c['settlement_mode'], 'position' => $pos];
        foreach ($tot as $k => $_) $tot[$k] += (float)$calc[$k];
    }
    foreach ($tot as $k => $v) $tot[$k] = round($v, substr($k, -3) === '_kg' ? 3 : 2);
    foreach ($byType as &$b) foreach (['sent_kg', 'sold_kg', 'returned_kg', 'outstanding_kg'] as $f) $b[$f] = round($b[$f], 3);
    unset($b);
    return ['party' => $party, 'direction' => 'out', 'consignments' => $rows, 'totals' => $tot, 'by_type' => array_values($byType)];
}

/** reports.consigned_stock {direction:'out'} — our goods still at consignees by consignee × type (still OUR inventory, valued at lot cost). */
function pb_consout_stock_report(PDO $pdo, int $cid): array {
    $st = $pdo->prepare("SELECT * FROM consignments WHERE customer_id=? AND direction='out' ORDER BY party, cons_date, id");
    $st->execute([$cid]);
    $by = []; $total = 0.0; $totalValue = 0.0;
    foreach ($st->fetchAll() as $c) {
        $calc = pb_consout_calc($pdo, $cid, $c);
        pb_consout_status($pdo, $cid, $c, $calc);
        if ($calc['outstanding_kg'] <= 0.0005) continue;
        $k = (string)$c['party'];
        if (!isset($by[$k])) $by[$k] = ['party' => $k, 'kg' => 0.0, 'value' => 0.0, 'types' => []];
        $by[$k]['kg'] += $calc['outstanding_kg'];
        $by[$k]['value'] += $calc['outstanding_value'];
        foreach ($calc['lots'] as $l) {
            if ($l['outstanding_kg'] <= 0.0005) continue;
            $t = (string)$l['type_name'];
            $by[$k]['types'][$t] = ($by[$k]['types'][$t] ?? 0.0) + $l['outstanding_kg'];
        }
        $total += $calc['outstanding_kg']; $totalValue += $calc['outstanding_value'];
    }
    foreach ($by as &$o) {
        $types = [];
        foreach ($o['types'] as $t => $kg) $types[] = ['type_name' => $t, 'kg' => round($kg, 3)];
        $o['types'] = $types; $o['kg'] = round($o['kg'], 3); $o['value'] = round($o['value'], 2);
    }
    unset($o);
    return ['direction' => 'out', 'owners' => array_values($by), 'total_kg' => round($total, 3), 'total_value' => round($totalValue, 2)];
}
