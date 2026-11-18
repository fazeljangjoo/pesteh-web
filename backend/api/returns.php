<?php
require_once __DIR__ . '/ddl_guard.php';
/**
 * S04.T04.1 — Purchase returns (برگشت از خرید).
 *
 * Actions (app.php): purchase_returns.create | purchase_returns.delete | purchase_returns.list | purchase_returns.get
 *
 * Model: every purchase row is one lot, so items[].lot_id IS the reference to the original purchase.
 *  - create payload: {uid?, date?, note?, refund_mode: reduce_payable|cash_refund|cheque_back, refund_amount?, cheque_no?, cheque_due?,
 *                     items:[{lot_id|purchase_id, kg, small_sacks?, large_sacks?, unit_price?, warehouse_uid?}]}
 *  - stock: purchases.remaining (+ sacks) is decreased at once; ledger moves kind `return_out` (doc_type `preturn`) at lot cost
 *    (stock.php pb_stock_desired). Cannot return more than the lot's remaining, nor more than purchased minus earlier returns.
 *  - money (rule, see PROGRESS): return value V = SUM(kg*unit_price) is a credit against what we owe the supplier.
 *    refund_amount R (cash/cheque actually received back, 0 for reduce_payable) is a separate settlement: balance effect = V - R.
 *    Installments of the original purchase are NOT mutated; credit shows in parties.list (bought-=V, paid-=R), statement (events) and aging.
 *  - edit = delete + create (no update action). Delete restores lot stock exactly and reverses the ledger rows.
 *  - gold fields (tax_amount, voucher_id) are nullable and unused until S16.
 */

const PB_PRETURN_MODES = ['reduce_payable', 'cash_refund', 'cheque_back'];

function pb_preturn_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS purchase_returns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(40) NOT NULL,
        number INT UNSIGNED NOT NULL,
        party VARCHAR(150) NOT NULL,
        date DATE NOT NULL,
        refund_mode VARCHAR(20) NOT NULL DEFAULT 'reduce_payable',
        refund_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        cheque_no VARCHAR(60) NULL,
        cheque_due DATE NULL,
        note VARCHAR(500) NULL,
        tax_amount DECIMAL(18,2) NULL,
        voucher_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pr_uid (customer_id, uid),
        UNIQUE KEY uq_pr_number (customer_id, number),
        KEY idx_pr_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS purchase_return_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        return_id BIGINT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        warehouse_uid CHAR(36) NULL,
        kg DECIMAL(16,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit_price DECIMAL(18,4) NOT NULL,
        KEY idx_pri_ret (customer_id, return_id),
        KEY idx_pri_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_preturn_fail(PDO $pdo, string $msg, int $code = 400): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    bad($msg, $code);
}

/** Total value of one return = SUM(kg * unit_price) (same float arithmetic as parties.list). */
function pb_preturn_total(PDO $pdo, int $cid, int $retId): float {
    $st = $pdo->prepare('SELECT COALESCE(SUM(kg * unit_price), 0) FROM purchase_return_items WHERE customer_id=? AND return_id=?');
    $st->execute([$cid, $retId]);
    return (float)$st->fetchColumn();
}

function pb_preturn_create(PDO $pdo, int $cid, array $p, ?array $customer = null): array {
    $uid = (string)($p['uid'] ?? '');
    if ($uid === '') $uid = bin2hex(random_bytes(12));
    if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uid)) bad('شناسهٔ برگشت نامعتبر است');
    $ex = $pdo->prepare('SELECT id FROM purchase_returns WHERE customer_id=? AND uid=?');
    $ex->execute([$cid, $uid]);
    $exId = $ex->fetchColumn();
    if ($exId) return ['id' => (int)$exId]; // duplicate submit
    $date = vdate($p['date'] ?? null);
    if ($customer !== null) pb_fy_guard($pdo, $cid, $customer, $date); // S31.T31.2b (gold: open year + unlocked month)
    $note = str_or_null($p['note'] ?? null, 500);
    $mode = (string)($p['refund_mode'] ?? 'reduce_payable');
    if (!in_array($mode, PB_PRETURN_MODES, true)) bad('نوع تسویهٔ برگشت نامعتبر است');
    $items = $p['items'] ?? null;
    if (!is_array($items) || count($items) < 1 || count($items) > 20) bad('حداقل یک و حداکثر ۲۰ قلم برای برگشت لازم است');

    $pdo->beginTransaction();
    $party = null;
    $lines = [];
    $asked = []; // lot => [kg, small, large] accumulated within this request
    $lotRows = [];
    foreach ($items as $it) {
        if (!is_array($it)) pb_preturn_fail($pdo, 'قلم برگشت نامعتبر است');
        $lot = (int)($it['lot_id'] ?? $it['purchase_id'] ?? 0);
        $kg = num($it['kg'] ?? 0);
        if ($lot <= 0) pb_preturn_fail($pdo, 'خرید مورد برگشت را انتخاب کنید');
        if ($kg <= 0 || $kg > 1e9) pb_preturn_fail($pdo, 'وزن برگشت باید بزرگ‌تر از صفر باشد');
        $small = nullableNum($it['small_sacks'] ?? null) ?? 0.0;
        $large = nullableNum($it['large_sacks'] ?? null) ?? 0.0;
        if ($small < 0 || $large < 0 || $small > 1e7 || $large > 1e7) pb_preturn_fail($pdo, 'تعداد گونی نامعتبر است');
        if (!isset($lotRows[$lot])) {
            $q = $pdo->prepare('SELECT id, seller, price, net_weight, remaining, small_sacks_remaining, large_sacks_remaining, is_mix, ccy, fx_rate FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
            $q->execute([$lot, $cid]);
            $row = $q->fetch();
            if (!$row) pb_preturn_fail($pdo, 'خرید انتخابی یافت نشد', 404);
            if ((int)$row['is_mix'] === 3) pb_preturn_fail($pdo, 'این کالا امانی است؛ برای برگشت به مالک از بخش «امانی» استفاده کنید');
            if ((int)$row['is_mix'] === 1) pb_preturn_fail($pdo, 'برگشت از خرید فقط برای خرید معمولی ممکن است، نه مخلوط');
            $lotRows[$lot] = $row;
            $r = $pdo->prepare('SELECT COALESCE(SUM(kg),0) FROM purchase_return_items WHERE customer_id=? AND lot_id=?');
            $r->execute([$cid, $lot]);
            $lotRows[$lot]['_returned'] = (float)$r->fetchColumn();
        }
        $row = $lotRows[$lot];
        $key = partyKey($row['seller']);
        if ($key === '') pb_preturn_fail($pdo, 'این خرید فروشنده (طرف‌حساب) ندارد؛ ابتدا نام فروشنده را در خرید ثبت کنید');
        if ($party === null) $party = $key;
        elseif ($party !== $key) pb_preturn_fail($pdo, 'همهٔ اقلام یک برگشت باید مربوط به یک طرف‌حساب باشند');
        $a = $asked[$lot] ?? [0.0, 0.0, 0.0];
        $a[0] += $kg; $a[1] += $small; $a[2] += $large;
        $asked[$lot] = $a;
        if ($a[0] - (float)$row['remaining'] > 1e-6) pb_preturn_fail($pdo, 'وزن برگشت از موجودی باقی‌ماندهٔ این خرید بیشتر است');
        if ($a[0] + $row['_returned'] - (float)$row['net_weight'] > 1e-6) pb_preturn_fail($pdo, 'مجموع برگشت‌ها از وزن خریداری‌شده بیشتر می‌شود');
        if ($a[1] - (float)$row['small_sacks_remaining'] > 1e-6 || $a[2] - (float)$row['large_sacks_remaining'] > 1e-6) pb_preturn_fail($pdo, 'گونی برگشتی از گونی باقی‌ماندهٔ این خرید بیشتر است');
        $hasPrice = isset($it['unit_price']) && $it['unit_price'] !== '' && $it['unit_price'] !== null;
        $price = $hasPrice ? num($it['unit_price']) : (float)$row['price'];
        // S27c.T27b.4: foreign purchase => return price in the purchase currency, converted with the ORIGINAL rate (purchases.fx_rate)
        $upc = nullableNum($it['unit_price_ccy'] ?? null);
        if ($upc !== null) {
            $pc = (string)($row['ccy'] ?? '');
            $pr = (float)($row['fx_rate'] ?? 0);
            if ($pc === '' || $pc === 'IRT' || $pr <= 0) pb_preturn_fail($pdo, 'خرید اصلی ارزی نیست؛ فی برگشت را به ریال/تومان وارد کنید');
            if ($upc < 0 || $upc > 1e9) pb_preturn_fail($pdo, 'فی ارزی برگشت نامعتبر است');
            $price = round($upc * pb_fx_rate_in_stored_unit($pr, pb_money_unit($pdo, $cid)), 2);
        }
        if ($price < 0 || $price > 1e13) pb_preturn_fail($pdo, 'فی برگشت نامعتبر است');
        $lines[] = ['lot' => $lot, 'kg' => $kg, 'small' => $small, 'large' => $large, 'price' => $price,
            'wh' => str_or_null($it['warehouse_uid'] ?? null, 36)];
    }
    $total = 0.0;
    foreach ($lines as $l) $total += $l['kg'] * $l['price'];
    $refund = 0.0; $chequeNo = null; $chequeDue = null;
    if ($mode !== 'reduce_payable') {
        $refund = isset($p['refund_amount']) && $p['refund_amount'] !== '' ? num($p['refund_amount']) : $total;
        if ($refund < 0 || $refund - $total > 0.005) pb_preturn_fail($pdo, 'مبلغ بازپرداخت نمی‌تواند از ارزش برگشت بیشتر باشد');
        if ($mode === 'cheque_back') {
            $chequeNo = str_or_null($p['cheque_no'] ?? null, 60);
            $cd = $p['cheque_due'] ?? null;
            $chequeDue = ($cd === null || $cd === '') ? null : vdate($cd);
        }
    }
    // pick warehouse per item (stock of this lot in that warehouse must cover it)
    foreach ($lines as $i => $l) {
        $lines[$i]['wh'] = pb_stock_pick_wh_or_bad($pdo, $cid, $l['lot'], $l['kg'], $l['wh']);
    }
    $nq = $pdo->prepare('SELECT COALESCE(MAX(number),0)+1 FROM purchase_returns WHERE customer_id=?');
    $nq->execute([$cid]);
    $number = (int)$nq->fetchColumn();
    $pdo->prepare('INSERT INTO purchase_returns (customer_id, uid, number, party, date, refund_mode, refund_amount, cheque_no, cheque_due, note) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $uid, $number, $party, $date, $mode, $refund, $chequeNo, $chequeDue, $note]);
    $rid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO purchase_return_items (customer_id, return_id, lot_id, warehouse_uid, kg, small_sacks, large_sacks, unit_price) VALUES (?,?,?,?,?,?,?,?)');
    $dec = $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = GREATEST(0, small_sacks_remaining - ?),
        large_sacks_remaining = GREATEST(0, large_sacks_remaining - ?) WHERE id=? AND customer_id=?');
    foreach ($lines as $l) {
        $ins->execute([$cid, $rid, $l['lot'], $l['wh'], $l['kg'], $l['small'], $l['large'], $l['price']]);
        $dec->execute([$l['kg'], $l['small'], $l['large'], $l['lot'], $cid]);
    }
    pb_stock_post_doc($pdo, $cid, 'preturn', $rid);
    if ($customer !== null) {
        $tp = []; foreach ($lines as $l) $tp[(int)$l['lot']] = ($tp[(int)$l['lot']] ?? 0) + (float)$l['kg'] * (float)$l['price'];
        pb_tax_return_copy($pdo, $customer, 'purchase', 'purchase_returns', $rid, $tp); // S17.T17.1b: copy original tax
        pb_post_hook($pdo, $customer, 'preturn', $rid); // S16.T16.3
    }
    $pdo->commit();
    return ['id' => $rid, 'number' => $number, 'total' => $total, 'refund_amount' => $refund];
}

function pb_preturn_delete(PDO $pdo, int $cid, array $p, ?array $customer = null): array {
    $id = (int)($p['id'] ?? 0);
    if ($customer !== null) pb_pl_guard_doc($pdo, $cid, $customer, 'purchase_returns', $id); // S31.T31.2b
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT id FROM purchase_returns WHERE id=? AND customer_id=? FOR UPDATE');
    $q->execute([$id, $cid]);
    if (!$q->fetchColumn()) pb_preturn_fail($pdo, 'برگشت یافت نشد', 404);
    $it = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks FROM purchase_return_items WHERE return_id=? AND customer_id=?');
    $it->execute([$id, $cid]);
    foreach ($it->fetchAll() as $r) {
        // restoreStock(): remaining += kg; sacks capped at the purchased count
        restoreStock($pdo, $cid, (int)$r['lot_id'], (float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks']);
    }
    pb_stock_reverse_doc($pdo, $cid, 'preturn', $id);
    if ($customer !== null) pb_unpost_hook($pdo, $customer, 'preturn', $id); // S16.T16.3
    $pdo->prepare('DELETE FROM purchase_return_items WHERE return_id=? AND customer_id=?')->execute([$id, $cid]);
    $pdo->prepare('DELETE FROM purchase_returns WHERE id=? AND customer_id=?')->execute([$id, $cid]);
    $pdo->commit();
    return ['ok' => true];
}

function pb_preturn_row(array $r, float $total): array {
    return ['id' => (int)$r['id'], 'uid' => $r['uid'], 'number' => (int)$r['number'], 'party' => $r['party'], 'date' => $r['date'],
        'refund_mode' => $r['refund_mode'], 'refund_amount' => (float)$r['refund_amount'], 'cheque_no' => $r['cheque_no'], 'cheque_due' => $r['cheque_due'],
        'note' => $r['note'], 'total' => $total, 'net_effect' => $total - (float)$r['refund_amount'],
        'tax_amount' => $r['tax_amount'] === null ? null : (float)$r['tax_amount'], 'voucher_id' => $r['voucher_id'] === null ? null : (int)$r['voucher_id']];
}

function pb_preturn_list(PDO $pdo, int $cid, array $p): array {
    $sql = 'SELECT r.*, COALESCE((SELECT SUM(i.kg * i.unit_price) FROM purchase_return_items i WHERE i.return_id=r.id AND i.customer_id=r.customer_id),0) AS total
        FROM purchase_returns r WHERE r.customer_id=?';
    $args = [$cid];
    if (trim((string)($p['party'] ?? '')) !== '') { $sql .= ' AND r.party=?'; $args[] = partyKey($p['party']); }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($p[$k])) { $sql .= " AND r.date $op ?"; $args[] = vdate($p[$k]); }
    }
    $sql .= ' ORDER BY r.date DESC, r.id DESC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[] = pb_preturn_row($r, (float)$r['total']);
    return ['items' => $out];
}

function pb_preturn_get(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT * FROM purchase_returns WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $r = $st->fetch();
    if (!$r) bad('برگشت یافت نشد', 404);
    $it = $pdo->prepare('SELECT i.lot_id, i.warehouse_uid, i.kg, i.small_sacks, i.large_sacks, i.unit_price, pu.code, pu.type_name
        FROM purchase_return_items i LEFT JOIN purchases pu ON pu.id=i.lot_id AND pu.customer_id=i.customer_id
        WHERE i.return_id=? AND i.customer_id=? ORDER BY i.id');
    $it->execute([(int)$r['id'], $cid]);
    $items = [];
    foreach ($it->fetchAll() as $x) {
        $items[] = ['lot_id' => (int)$x['lot_id'], 'code' => $x['code'], 'type_name' => $x['type_name'], 'warehouse_uid' => $x['warehouse_uid'],
            'kg' => (float)$x['kg'], 'small_sacks' => (float)$x['small_sacks'], 'large_sacks' => (float)$x['large_sacks'],
            'unit_price' => (float)$x['unit_price'], 'amount' => (float)$x['kg'] * (float)$x['unit_price']];
    }
    return pb_preturn_row($r, pb_preturn_total($pdo, $cid, (int)$r['id'])) + ['items' => $items];
}

/* ---- party account integration (parties.list / statement / aging) ---- */

/** Per party: [value V, refunded R] of all returns (same float arithmetic as the statement events). */
function pb_preturn_party_sums(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT r.party, r.refund_amount, COALESCE((SELECT SUM(i.kg * i.unit_price) FROM purchase_return_items i WHERE i.return_id=r.id AND i.customer_id=r.customer_id),0) AS total
        FROM purchase_returns r WHERE r.customer_id=?');
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

/** Statement events (hook of pb_party_event_hooks): return = debit (+V), refund received = credit (-R). */
function pb_preturn_party_events(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT r.id, r.number, r.party, r.date, r.refund_mode, r.refund_amount, COALESCE((SELECT SUM(i.kg * i.unit_price) FROM purchase_return_items i WHERE i.return_id=r.id AND i.customer_id=r.customer_id),0) AS total
        FROM purchase_returns r WHERE r.customer_id=?');
    $st->execute([$cid]);
    $ev = [];
    foreach ($st->fetchAll() as $r) {
        $k = partyKey($r['party']);
        $ev[] = ['key' => $k, 'date' => (string)$r['date'], 'type' => 'purchase_return', 'ref' => (string)$r['number'], 'label' => 'برگشت از خرید',
            'effect' => (float)$r['total'], 'doc_id' => (int)$r['id'], 'ord' => 1];
        if ((float)$r['refund_amount'] > 0) {
            $ev[] = ['key' => $k, 'date' => (string)$r['date'], 'type' => 'purchase_return_refund', 'ref' => (string)$r['number'],
                'label' => $r['refund_mode'] === 'cheque_back' ? 'بازپرداخت برگشت (چک)' : 'بازپرداخت برگشت (نقد)',
                'effect' => -(float)$r['refund_amount'], 'doc_id' => (int)$r['id'], 'ord' => 2];
        }
    }
    return $ev;
}

/** Aging: lowers the outstanding of each purchase doc by (return value - refund share) of that lot. $docs is keyed 'p'.id. */
function pb_preturn_apply_docs(PDO $pdo, int $cid, array &$docs): void {
    $st = $pdo->prepare('SELECT r.id, r.refund_amount, i.lot_id, i.kg * i.unit_price AS amt FROM purchase_returns r
        JOIN purchase_return_items i ON i.return_id=r.id AND i.customer_id=r.customer_id WHERE r.customer_id=?');
    $st->execute([$cid]);
    $rows = $st->fetchAll();
    $tot = [];
    foreach ($rows as $r) $tot[(int)$r['id']] = ($tot[(int)$r['id']] ?? 0.0) + (float)$r['amt'];
    foreach ($rows as $r) {
        $k = 'p' . (int)$r['lot_id'];
        if (!isset($docs[$k])) continue;
        $t = $tot[(int)$r['id']];
        $share = $t > 0 ? (float)$r['refund_amount'] * ((float)$r['amt'] / $t) : 0.0;
        $docs[$k]['ret'] = ($docs[$k]['ret'] ?? 0.0) + (float)$r['amt'] - $share;
    }
}
