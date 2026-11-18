<?php
require_once __DIR__ . '/ddl_guard.php';
/**
 * S04.T04.2 — Sales returns (برگشت از فروش). Mirror of returns.php (purchase returns).
 *
 * Actions (app.php): sale_returns.create | sale_returns.delete | sale_returns.list | sale_returns.get | sale_returns.summary | sale_returns.profit_lines
 *
 * Model: items[].sale_id references the sale (one sale = one sale_items row; legacy multi-item sales may pass purchase_id/lot_id to pick the row).
 *  - create payload: {uid?, date?, note?, refund_mode: reduce_receivable|cash_refund|cheque_given, refund_amount?, cheque_no?, cheque_due?,
 *                     items:[{sale_id, lot_id?|purchase_id?, kg, small_sacks?, large_sacks?, unit_price?, warehouse_uid?}]}
 *  - stock: goods re-enter the SAME lot (purchases.remaining + sacks, sacks capped at purchased count); ledger moves kind `return_in`
 *    (doc_type `sreturn`) at ORIGINAL lot cost (stock.php pb_stock_desired). Cannot return more than sold minus earlier returns (kg and sacks).
 *  - money: value V = SUM(kg*unit_price) (default unit_price = sale price) is a credit against what the customer owes us.
 *    refund_amount R (cash/cheque we actually paid back, 0 for reduce_receivable) is a separate settlement: balance effect = -(V - R).
 *    Receipt installments/cheques of the original sale are NOT mutated; credit shows in parties.list (sold-=V, received-=R), statement and aging.
 *  - profit: sale_returns.summary {from?,to?} gives revenue_reversed (V), cogs_reversed (kg*lot cost), profit_reversed = V - cogs.
 *    Profit after return = profit - profit_reversed (= profit as if the sold qty were reduced).
 *  - edit = delete + create. Delete restores stock exactly (lot remaining back down) and reverses ledger rows.
 *  - sales.update / sales.delete are blocked while the sale has returns (delete the returns first).
 */

const PB_SRETURN_MODES = ['reduce_receivable', 'cash_refund', 'cheque_given'];

function pb_sreturn_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS sale_returns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(40) NOT NULL,
        number INT UNSIGNED NOT NULL,
        party VARCHAR(150) NOT NULL,
        date DATE NOT NULL,
        refund_mode VARCHAR(20) NOT NULL DEFAULT 'reduce_receivable',
        refund_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        cheque_no VARCHAR(60) NULL,
        cheque_due DATE NULL,
        note VARCHAR(500) NULL,
        tax_amount DECIMAL(18,2) NULL,
        voucher_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sr_uid (customer_id, uid),
        UNIQUE KEY uq_sr_number (customer_id, number),
        KEY idx_sr_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS sale_return_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        return_id BIGINT UNSIGNED NOT NULL,
        sale_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        warehouse_uid CHAR(36) NULL,
        kg DECIMAL(16,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit_price DECIMAL(18,4) NOT NULL,
        KEY idx_sri_ret (customer_id, return_id),
        KEY idx_sri_sale (customer_id, sale_id),
        KEY idx_sri_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_sreturn_fail(PDO $pdo, string $msg, int $code = 400): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    bad($msg, $code);
}

/** True when the sale has at least one return (used to block sales.update / sales.delete). */
function pb_sreturn_sale_has_returns(PDO $pdo, int $cid, int $saleId): bool {
    $st = $pdo->prepare('SELECT 1 FROM sale_return_items WHERE customer_id=? AND sale_id=? LIMIT 1');
    $st->execute([$cid, $saleId]);
    return (bool)$st->fetchColumn();
}

/** True when any sale return refers to this lot (purchases.delete must not orphan them). */
function pb_sreturn_lot_has_returns(PDO $pdo, int $cid, int $lotId): bool {
    $st = $pdo->prepare('SELECT 1 FROM sale_return_items WHERE customer_id=? AND lot_id=? LIMIT 1');
    $st->execute([$cid, $lotId]);
    return (bool)$st->fetchColumn();
}

function pb_sreturn_total(PDO $pdo, int $cid, int $retId): float {
    $st = $pdo->prepare('SELECT COALESCE(SUM(kg * unit_price), 0) FROM sale_return_items WHERE customer_id=? AND return_id=?');
    $st->execute([$cid, $retId]);
    return (float)$st->fetchColumn();
}

function pb_sreturn_create(PDO $pdo, int $cid, array $p, ?array $customer = null): array {
    $uid = (string)($p['uid'] ?? '');
    if ($uid === '') $uid = bin2hex(random_bytes(12));
    if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uid)) bad('شناسهٔ برگشت نامعتبر است');
    $ex = $pdo->prepare('SELECT id FROM sale_returns WHERE customer_id=? AND uid=?');
    $ex->execute([$cid, $uid]);
    $exId = $ex->fetchColumn();
    if ($exId) return ['id' => (int)$exId]; // duplicate submit
    $date = vdate($p['date'] ?? null);
    if ($customer !== null) pb_fy_guard($pdo, $cid, $customer, $date); // S31.T31.2b (gold: open year + unlocked month)
    $note = str_or_null($p['note'] ?? null, 500);
    $mode = (string)($p['refund_mode'] ?? 'reduce_receivable');
    if (!in_array($mode, PB_SRETURN_MODES, true)) bad('نوع تسویهٔ برگشت نامعتبر است');
    $items = $p['items'] ?? null;
    if (!is_array($items) || count($items) < 1 || count($items) > 20) bad('حداقل یک و حداکثر ۲۰ قلم برای برگشت لازم است');

    $pdo->beginTransaction();
    $party = null;
    $lines = [];
    $asked = [];   // "saleId:lot" => [kg, small, large] accumulated within this request
    $itemRows = []; // "saleId:lot" => sale item row + _returned
    foreach ($items as $it) {
        if (!is_array($it)) pb_sreturn_fail($pdo, 'قلم برگشت نامعتبر است');
        $saleId = (int)($it['sale_id'] ?? 0);
        $kg = num($it['kg'] ?? 0);
        if ($saleId <= 0) pb_sreturn_fail($pdo, 'فروش مورد برگشت را انتخاب کنید');
        if ($kg <= 0 || $kg > 1e9) pb_sreturn_fail($pdo, 'وزن برگشت باید بزرگ‌تر از صفر باشد');
        $small = nullableNum($it['small_sacks'] ?? null) ?? 0.0;
        $large = nullableNum($it['large_sacks'] ?? null) ?? 0.0;
        if ($small < 0 || $large < 0 || $small > 1e7 || $large > 1e7) pb_sreturn_fail($pdo, 'تعداد گونی نامعتبر است');
        $wantLot = (int)($it['lot_id'] ?? $it['purchase_id'] ?? 0);
        $sq = $pdo->prepare('SELECT s.id, s.buyer, si.purchase_id, si.weight, si.item_price, si.small_sacks, si.large_sacks, si.warehouse_uid, s.price AS sale_price, s.ccy AS sale_ccy, s.fx_rate AS sale_fx_rate
            FROM sales s JOIN sale_items si ON si.sale_id=s.id AND si.customer_id=s.customer_id
            WHERE s.id=? AND s.customer_id=? AND (?=0 OR si.purchase_id=?) ORDER BY si.id FOR UPDATE');
        $sq->execute([$saleId, $cid, $wantLot, $wantLot]);
        $cand = $sq->fetchAll();
        if (!$cand) pb_sreturn_fail($pdo, 'فروش انتخابی یافت نشد', 404);
        if (count($cand) > 1) pb_sreturn_fail($pdo, 'این فروش چندقلمی است؛ قلم (خرید) را مشخص کنید');
        $row = $cand[0];
        $lot = (int)$row['purchase_id'];
        $k = $saleId . ':' . $lot;
        if (!isset($itemRows[$k])) {
            $lc = $pdo->prepare('SELECT id FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
            $lc->execute([$lot, $cid]);
            if (!$lc->fetchColumn()) pb_sreturn_fail($pdo, 'خریدِ مربوط به این فروش یافت نشد');
            $r = $pdo->prepare('SELECT COALESCE(SUM(kg),0), COALESCE(SUM(small_sacks),0), COALESCE(SUM(large_sacks),0) FROM sale_return_items WHERE customer_id=? AND sale_id=? AND lot_id=?');
            $r->execute([$cid, $saleId, $lot]);
            $sums = $r->fetch(PDO::FETCH_NUM);
            $row['_ret'] = [(float)$sums[0], (float)$sums[1], (float)$sums[2]];
            $itemRows[$k] = $row;
        }
        $row = $itemRows[$k];
        $key = partyKey($row['buyer']);
        if ($key === '') pb_sreturn_fail($pdo, 'این فروش خریدار (طرف‌حساب) ندارد؛ ابتدا نام خریدار را در فروش ثبت کنید');
        if ($party === null) $party = $key;
        elseif ($party !== $key) pb_sreturn_fail($pdo, 'همهٔ اقلام یک برگشت باید مربوط به یک طرف‌حساب باشند');
        $a = $asked[$k] ?? [0.0, 0.0, 0.0];
        $a[0] += $kg; $a[1] += $small; $a[2] += $large;
        $asked[$k] = $a;
        if ($a[0] + $row['_ret'][0] - (float)$row['weight'] > 1e-6) pb_sreturn_fail($pdo, 'مجموع برگشت‌ها از وزن فروخته‌شده بیشتر می‌شود');
        if ($a[1] + $row['_ret'][1] - (float)($row['small_sacks'] ?? 0) > 1e-6 || $a[2] + $row['_ret'][2] - (float)($row['large_sacks'] ?? 0) > 1e-6) {
            pb_sreturn_fail($pdo, 'گونی برگشتی از گونی فروخته‌شده بیشتر می‌شود');
        }
        $hasPrice = isset($it['unit_price']) && $it['unit_price'] !== '' && $it['unit_price'] !== null;
        $sold = $row['item_price'] !== null ? (float)$row['item_price'] : (float)$row['sale_price'];
        $price = $hasPrice ? num($it['unit_price']) : $sold;
        // S27c.T27b.4: foreign sale => the return price may be given in the sale's currency (unit_price_ccy) and is converted
        // with the ORIGINAL document rate (sales.fx_rate), never today's rate. Default price (= sold price) already is that.
        $upc = nullableNum($it['unit_price_ccy'] ?? null);
        if ($upc !== null) {
            $sc = (string)($row['sale_ccy'] ?? '');
            $sr = (float)($row['sale_fx_rate'] ?? 0);
            if ($sc === '' || $sc === 'IRT' || $sr <= 0) pb_sreturn_fail($pdo, 'فروش اصلی ارزی نیست؛ فی برگشت را به ریال/تومان وارد کنید');
            if ($upc < 0 || $upc > 1e9) pb_sreturn_fail($pdo, 'فی ارزی برگشت نامعتبر است');
            $price = round($upc * pb_fx_rate_in_stored_unit($sr, pb_money_unit($pdo, $cid)), 2);
        }
        if ($price < 0 || $price > 1e13) pb_sreturn_fail($pdo, 'فی برگشت نامعتبر است');
        $wh = str_or_null($it['warehouse_uid'] ?? null, 36);
        if ($wh !== null) pb_wh_require($pdo, $cid, $wh);
        else $wh = ($row['warehouse_uid'] ?? null) ?: null; // back to where it was sold from
        $lines[] = ['sale' => $saleId, 'lot' => $lot, 'kg' => $kg, 'small' => $small, 'large' => $large, 'price' => $price, 'wh' => $wh];
    }
    $total = 0.0;
    foreach ($lines as $l) $total += $l['kg'] * $l['price'];
    $refund = 0.0; $chequeNo = null; $chequeDue = null;
    if ($mode !== 'reduce_receivable') {
        $refund = isset($p['refund_amount']) && $p['refund_amount'] !== '' ? num($p['refund_amount']) : $total;
        if ($refund < 0 || $refund - $total > 0.005) pb_sreturn_fail($pdo, 'مبلغ بازپرداخت نمی‌تواند از ارزش برگشت بیشتر باشد');
        if ($mode === 'cheque_given') {
            $chequeNo = str_or_null($p['cheque_no'] ?? null, 60);
            $cd = $p['cheque_due'] ?? null;
            $chequeDue = ($cd === null || $cd === '') ? null : vdate($cd);
        }
    }
    $nq = $pdo->prepare('SELECT COALESCE(MAX(number),0)+1 FROM sale_returns WHERE customer_id=?');
    $nq->execute([$cid]);
    $number = (int)$nq->fetchColumn();
    $pdo->prepare('INSERT INTO sale_returns (customer_id, uid, number, party, date, refund_mode, refund_amount, cheque_no, cheque_due, note) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $uid, $number, $party, $date, $mode, $refund, $chequeNo, $chequeDue, $note]);
    $rid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO sale_return_items (customer_id, return_id, sale_id, lot_id, warehouse_uid, kg, small_sacks, large_sacks, unit_price) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($lines as $l) {
        $ins->execute([$cid, $rid, $l['sale'], $l['lot'], $l['wh'], $l['kg'], $l['small'], $l['large'], $l['price']]);
        restoreStock($pdo, $cid, $l['lot'], $l['kg'], $l['small'], $l['large']); // remaining += kg (sacks capped at purchased)
    }
    pb_stock_post_doc($pdo, $cid, 'sreturn', $rid);
    if ($customer !== null) {
        $tp = []; foreach ($lines as $l) $tp[(int)$l['sale']] = ($tp[(int)$l['sale']] ?? 0) + (float)$l['kg'] * (float)$l['price'];
        pb_tax_return_copy($pdo, $customer, 'sale', 'sale_returns', $rid, $tp); // S17.T17.1b: copy original tax
        pb_post_hook($pdo, $customer, 'sreturn', $rid); // S16.T16.3
    }
    $pdo->commit();
    return ['id' => $rid, 'number' => $number, 'total' => $total, 'refund_amount' => $refund];
}

function pb_sreturn_delete(PDO $pdo, int $cid, array $p, ?array $customer = null): array {
    $id = (int)($p['id'] ?? 0);
    if ($customer !== null) pb_pl_guard_doc($pdo, $cid, $customer, 'sale_returns', $id); // S31.T31.2b
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT id FROM sale_returns WHERE id=? AND customer_id=? FOR UPDATE');
    $q->execute([$id, $cid]);
    if (!$q->fetchColumn()) pb_sreturn_fail($pdo, 'برگشت یافت نشد', 404);
    $it = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks FROM sale_return_items WHERE return_id=? AND customer_id=?');
    $it->execute([$id, $cid]);
    $dec = $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = GREATEST(0, small_sacks_remaining - ?),
        large_sacks_remaining = GREATEST(0, large_sacks_remaining - ?) WHERE id=? AND customer_id=?');
    foreach ($it->fetchAll() as $r) {
        $lk = $pdo->prepare('SELECT remaining FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $lk->execute([(int)$r['lot_id'], $cid]);
        $rem = $lk->fetchColumn();
        if ($rem !== false && (float)$r['kg'] - (float)$rem > 1e-6) {
            pb_sreturn_fail($pdo, 'کالای برگشتی دوباره فروخته یا مصرف شده؛ حذف این برگشت ممکن نیست');
        }
        $dec->execute([(float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks'], (int)$r['lot_id'], $cid]);
    }
    pb_stock_reverse_doc($pdo, $cid, 'sreturn', $id);
    if ($customer !== null) pb_unpost_hook($pdo, $customer, 'sreturn', $id); // S16.T16.3
    $pdo->prepare('DELETE FROM sale_return_items WHERE return_id=? AND customer_id=?')->execute([$id, $cid]);
    $pdo->prepare('DELETE FROM sale_returns WHERE id=? AND customer_id=?')->execute([$id, $cid]);
    $pdo->commit();
    return ['ok' => true];
}

function pb_sreturn_row(array $r, float $total): array {
    return ['id' => (int)$r['id'], 'uid' => $r['uid'], 'number' => (int)$r['number'], 'party' => $r['party'], 'date' => $r['date'],
        'refund_mode' => $r['refund_mode'], 'refund_amount' => (float)$r['refund_amount'], 'cheque_no' => $r['cheque_no'], 'cheque_due' => $r['cheque_due'],
        'note' => $r['note'], 'total' => $total, 'net_effect' => $total - (float)$r['refund_amount'],
        'tax_amount' => $r['tax_amount'] === null ? null : (float)$r['tax_amount'], 'voucher_id' => $r['voucher_id'] === null ? null : (int)$r['voucher_id']];
}

function pb_sreturn_list(PDO $pdo, int $cid, array $p): array {
    $sql = 'SELECT r.*, COALESCE((SELECT SUM(i.kg * i.unit_price) FROM sale_return_items i WHERE i.return_id=r.id AND i.customer_id=r.customer_id),0) AS total
        FROM sale_returns r WHERE r.customer_id=?';
    $args = [$cid];
    if (trim((string)($p['party'] ?? '')) !== '') { $sql .= ' AND r.party=?'; $args[] = partyKey($p['party']); }
    if (!empty($p['sale_id'])) { $sql .= ' AND EXISTS (SELECT 1 FROM sale_return_items x WHERE x.return_id=r.id AND x.customer_id=r.customer_id AND x.sale_id=?)'; $args[] = (int)$p['sale_id']; }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($p[$k])) { $sql .= " AND r.date $op ?"; $args[] = vdate($p[$k]); }
    }
    $sql .= ' ORDER BY r.date DESC, r.id DESC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[] = pb_sreturn_row($r, (float)$r['total']);
    return ['items' => $out];
}

function pb_sreturn_get(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT * FROM sale_returns WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $r = $st->fetch();
    if (!$r) bad('برگشت یافت نشد', 404);
    $it = $pdo->prepare('SELECT i.sale_id, i.lot_id, i.warehouse_uid, i.kg, i.small_sacks, i.large_sacks, i.unit_price, pu.code, pu.type_name, pu.price AS cost
        FROM sale_return_items i LEFT JOIN purchases pu ON pu.id=i.lot_id AND pu.customer_id=i.customer_id
        WHERE i.return_id=? AND i.customer_id=? ORDER BY i.id');
    $it->execute([(int)$r['id'], $cid]);
    $items = [];
    foreach ($it->fetchAll() as $x) {
        $items[] = ['sale_id' => (int)$x['sale_id'], 'lot_id' => (int)$x['lot_id'], 'code' => $x['code'], 'type_name' => $x['type_name'], 'warehouse_uid' => $x['warehouse_uid'],
            'kg' => (float)$x['kg'], 'small_sacks' => (float)$x['small_sacks'], 'large_sacks' => (float)$x['large_sacks'],
            'unit_price' => (float)$x['unit_price'], 'amount' => (float)$x['kg'] * (float)$x['unit_price'],
            'cost' => $x['cost'] === null ? null : (float)$x['cost']];
    }
    return pb_sreturn_row($r, pb_sreturn_total($pdo, $cid, (int)$r['id'])) + ['items' => $items];
}

/**
 * ردیف‌های برگشت از فروش برای گزارش سود و زیان (به تاریخ برگشت).
 * هر ردیف = یک قلم برگشتی با همان «بهای واحدِ» فروش اصلی (pb_cons_cost_sql) + هزینهٔ جانبیِ خرید به‌ازای کیلو،
 * تا برگشت دقیقاً همان سودی را خنثی کند که فروش اصلی ساخته بود (قبلاً فقط p.price بدون هزینهٔ جانبی کم می‌شد).
 * فیلتر: from / to (تاریخ برگشت)، code (کد پسته).
 */
function pb_sreturn_profit_lines(PDO $pdo, int $cid, array $p): array {
    $cost = pb_cons_cost_sql(); // aliases: p = purchases (lot), si = sale_items
    $sql = "SELECT r.id AS return_id, r.number, r.date, i.sale_id, i.lot_id, i.kg, i.unit_price,
            p.code, p.type_name, p.base_type, p.labor_cost AS p_labor_cost, p.transport_cost AS p_transport_cost, p.net_weight AS p_net_weight,
            p.price_usd AS cost_price_usd, $cost AS cost_price, c.name AS category_name, s.usd_rate AS sale_usd_rate, s.date AS sale_date
        FROM sale_returns r
        JOIN sale_return_items i ON i.return_id=r.id AND i.customer_id=r.customer_id
        JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id
        LEFT JOIN categories c ON c.id=p.category_id AND c.customer_id=p.customer_id
        LEFT JOIN sales s ON s.id=i.sale_id AND s.customer_id=i.customer_id
        LEFT JOIN sale_items si ON si.sale_id=i.sale_id AND si.purchase_id=i.lot_id AND si.customer_id=i.customer_id
        WHERE r.customer_id=?";
    $args = [$cid];
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($p[$k])) { $sql .= " AND r.date $op ?"; $args[] = vdate($p[$k]); }
    }
    $sql .= ' ORDER BY r.date, r.id, i.id';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $q = strtolower(trim((string)($p['code'] ?? '')));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if ($q !== '' && strpos(strtolower((string)$r['code']), $q) === false) continue;
        $net = (float)($r['p_net_weight'] ?? 0);
        $extra = $net > 0 ? ((float)($r['p_labor_cost'] ?? 0) + (float)($r['p_transport_cost'] ?? 0)) / $net : 0.0;
        $out[] = [
            'return_id' => (int)$r['return_id'], 'number' => (int)$r['number'], 'date' => (string)$r['date'],
            'sale_id' => (int)$r['sale_id'], 'lot_id' => (int)$r['lot_id'],
            'kg' => (float)$r['kg'], 'unit_price' => (float)$r['unit_price'],
            'cost_price' => (float)$r['cost_price'], 'extra_per_kg' => $extra,
            'code' => $r['code'], 'type_name' => $r['type_name'], 'base_type' => $r['base_type'], 'category_name' => $r['category_name'],
            'cost_price_usd' => $r['cost_price_usd'] === null ? null : (float)$r['cost_price_usd'],
            'usd_rate' => $r['sale_usd_rate'] === null ? null : (float)$r['sale_usd_rate'], 'sale_date' => $r['sale_date'] === null ? null : (string)$r['sale_date'],
        ];
    }
    return ['items' => $out];
}

/** Profit adjustment of returns in a period: revenue and COGS (purchase price + purchase extras per kg) are reversed; labor/transport recorded on the original sale itself stay (they were really spent). */
function pb_sreturn_summary(PDO $pdo, int $cid, array $p): array {
    $lines = pb_sreturn_profit_lines($pdo, $cid, $p)['items'];
    $rev = 0.0; $cogs = 0.0; $ids = [];
    foreach ($lines as $l) {
        $rev += $l['kg'] * $l['unit_price'];
        $cogs += $l['kg'] * ($l['cost_price'] + $l['extra_per_kg']);
        $ids[$l['return_id']] = true;
    }
    return ['count' => count($ids), 'revenue_reversed' => $rev, 'cogs_reversed' => $cogs, 'profit_reversed' => $rev - $cogs];
}

/* ---- party account integration (parties.list / statement / aging) ---- */

/** Per party: [value V, refunded R] of all sale returns. */
function pb_sreturn_party_sums(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT r.party, r.refund_amount, COALESCE((SELECT SUM(i.kg * i.unit_price) FROM sale_return_items i WHERE i.return_id=r.id AND i.customer_id=r.customer_id),0) AS total
        FROM sale_returns r WHERE r.customer_id=?');
    $st->execute([$cid]);
    $res = [];
    foreach ($st->fetchAll() as $r) {
        $k = partyKey($r['party']);
        if (!isset($res[$k])) $res[$k] = ['value' => 0.0, 'refunded' => 0.0];
        $res[$k]['value'] += (float)$r['total'];
        $res[$k]['refunded'] += (float)$r['refund_amount'];
    }
    return $res;
}

/** Statement events: return = credit (-V: customer owes less), refund paid out = debit (+R). */
function pb_sreturn_party_events(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT r.id, r.number, r.party, r.date, r.refund_mode, r.refund_amount, COALESCE((SELECT SUM(i.kg * i.unit_price) FROM sale_return_items i WHERE i.return_id=r.id AND i.customer_id=r.customer_id),0) AS total
        FROM sale_returns r WHERE r.customer_id=?');
    $st->execute([$cid]);
    $ev = [];
    foreach ($st->fetchAll() as $r) {
        $k = partyKey($r['party']);
        $ev[] = ['key' => $k, 'date' => (string)$r['date'], 'type' => 'sale_return', 'ref' => (string)$r['number'], 'label' => 'برگشت از فروش',
            'effect' => -(float)$r['total'], 'doc_id' => (int)$r['id'], 'ord' => 1];
        if ((float)$r['refund_amount'] > 0) {
            $ev[] = ['key' => $k, 'date' => (string)$r['date'], 'type' => 'sale_return_refund', 'ref' => (string)$r['number'],
                'label' => $r['refund_mode'] === 'cheque_given' ? 'بازپرداخت برگشت (چک)' : 'بازپرداخت برگشت (نقد)',
                'effect' => (float)$r['refund_amount'], 'doc_id' => (int)$r['id'], 'ord' => 2];
        }
    }
    return $ev;
}

/** Aging: lowers the outstanding of each sale doc by (return value - refund share). $docs is keyed 's'.id. */
function pb_sreturn_apply_docs(PDO $pdo, int $cid, array &$docs): void {
    $st = $pdo->prepare('SELECT r.id, r.refund_amount, i.sale_id, i.kg * i.unit_price AS amt FROM sale_returns r
        JOIN sale_return_items i ON i.return_id=r.id AND i.customer_id=r.customer_id WHERE r.customer_id=?');
    $st->execute([$cid]);
    $rows = $st->fetchAll();
    $tot = [];
    foreach ($rows as $r) $tot[(int)$r['id']] = ($tot[(int)$r['id']] ?? 0.0) + (float)$r['amt'];
    foreach ($rows as $r) {
        $k = 's' . (int)$r['sale_id'];
        if (!isset($docs[$k])) continue;
        $t = $tot[(int)$r['id']];
        $share = $t > 0 ? (float)$r['refund_amount'] * ((float)$r['amt'] / $t) : 0.0;
        $docs[$k]['ret'] = ($docs[$k]['ret'] ?? 0.0) + (float)$r['amt'] - $share;
    }
}
