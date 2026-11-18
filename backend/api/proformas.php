<?php
require_once __DIR__ . '/ddl_guard.php';
/**
 * S04.T04.3 — Pro-forma sales invoices (پیش‌فاکتور). NO stock / accounting effect until converted.
 *
 * Actions (app.php): proformas.save | proformas.set_status | proformas.delete | proformas.list | proformas.get | proformas.convert
 *  - save payload: {id? | uid?, party, date?, valid_until?, reserve? (0|1 soft reservation flag, default 0), note?, terms?,
 *                   items:[{lot_id?, description?, kg, unit_price, discount?}]}   discount = line amount (not percent); line = kg*unit_price - discount
 *    id given => update (replace items; not allowed once converted). uid is the idempotency key of a new document.
 *  - status stored: draft|sent|accepted|converted|cancelled. `expired` is COMPUTED server-side (status draft|sent and valid_until < today);
 *    list/get return status (effective) + status_raw. Accepted proformas do not expire.
 *  - convert: one sale per item (a sale has exactly one lot item), all-or-nothing. Every item needs lot_id. Sale price per kg =
 *    ROUND((kg*unit_price - discount)/kg, 2) (sale_items.item_price has 2 decimals). Links both ways: proforma_items.sale_id and sales.proforma_id.
 *    Second convert is blocked (row lock + status check). Deleting a converted sale unlinks it and reopens the proforma (status accepted) -- see pb_proforma_on_sale_delete.
 *  - soft reservation: pb_proforma_reserved_kg($lot) = kg of live (draft|sent|accepted, not expired) proformas with reserve=1. Informational only; sales are not blocked.
 */

const PB_PROFORMA_STATUSES = ['draft', 'sent', 'accepted', 'converted', 'cancelled'];

function pb_proforma_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS proformas (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(40) NOT NULL,
        number INT UNSIGNED NOT NULL,
        party VARCHAR(150) NOT NULL,
        date DATE NOT NULL,
        valid_until DATE NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        reserve TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(500) NULL,
        terms VARCHAR(1000) NULL,
        converted_at DATETIME NULL,
        tax_amount DECIMAL(18,2) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pf_uid (customer_id, uid),
        UNIQUE KEY uq_pf_number (customer_id, number),
        KEY idx_pf_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS proforma_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        proforma_id BIGINT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NULL,
        description VARCHAR(200) NULL,
        kg DECIMAL(16,3) NOT NULL,
        unit_price DECIMAL(18,4) NOT NULL,
        discount DECIMAL(18,2) NOT NULL DEFAULT 0,
        sale_id INT UNSIGNED NULL,
        KEY idx_pfi_pf (customer_id, proforma_id),
        KEY idx_pfi_lot (customer_id, lot_id),
        KEY idx_pfi_sale (customer_id, sale_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c = $pdo->query("SHOW COLUMNS FROM sales LIKE 'proforma_id'")->fetch();
    if (!$c) $pdo->exec('ALTER TABLE sales ADD COLUMN proforma_id BIGINT UNSIGNED NULL');
}

function pb_proforma_fail(PDO $pdo, string $msg, int $code = 400): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    bad($msg, $code);
}

function pb_proforma_effective(string $status, ?string $validUntil): string {
    if (($status === 'draft' || $status === 'sent') && $validUntil !== null && $validUntil !== '' && $validUntil < date('Y-m-d')) return 'expired';
    return $status;
}

function pb_proforma_row(array $r, float $total): array {
    $vu = $r['valid_until'] === null ? null : (string)$r['valid_until'];
    return ['id' => (int)$r['id'], 'uid' => $r['uid'], 'number' => (int)$r['number'], 'party' => $r['party'], 'date' => $r['date'],
        'valid_until' => $vu, 'status' => pb_proforma_effective((string)$r['status'], $vu), 'status_raw' => $r['status'],
        'reserve' => (int)$r['reserve'] === 1, 'note' => $r['note'], 'terms' => $r['terms'], 'total' => $total,
        'converted_at' => $r['converted_at'], 'tax_amount' => $r['tax_amount'] === null ? null : (float)$r['tax_amount']];
}

function pb_proforma_total(PDO $pdo, int $cid, int $id): float {
    $st = $pdo->prepare('SELECT COALESCE(SUM(kg * unit_price - discount), 0) FROM proforma_items WHERE customer_id=? AND proforma_id=?');
    $st->execute([$cid, $id]);
    return (float)$st->fetchColumn();
}

/** Validates and normalizes items; returns list of rows. */
function pb_proforma_read_items(PDO $pdo, int $cid, $items): array {
    if (!is_array($items) || count($items) < 1 || count($items) > 50) pb_proforma_fail($pdo, 'حداقل یک و حداکثر ۵۰ قلم برای پیش‌فاکتور لازم است');
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) pb_proforma_fail($pdo, 'قلم پیش‌فاکتور نامعتبر است');
        $kg = num($it['kg'] ?? 0);
        $price = num($it['unit_price'] ?? 0);
        $disc = nullableNum($it['discount'] ?? null) ?? 0.0;
        if ($kg <= 0 || $kg > 1e9) pb_proforma_fail($pdo, 'وزن قلم باید بزرگ‌تر از صفر باشد');
        if ($price <= 0 || $price > 1e13) pb_proforma_fail($pdo, 'فی قلم نامعتبر است');
        if ($disc < 0 || $disc - $kg * $price > 0.005) pb_proforma_fail($pdo, 'تخفیف قلم نامعتبر است');
        $lot = (int)($it['lot_id'] ?? $it['purchase_id'] ?? 0);
        if ($lot > 0) {
            $q = $pdo->prepare('SELECT id FROM purchases WHERE id=? AND customer_id=?');
            $q->execute([$lot, $cid]);
            if (!$q->fetchColumn()) pb_proforma_fail($pdo, 'خرید (لات) انتخابی یافت نشد', 404);
        }
        $desc = str_or_null($it['description'] ?? null, 200);
        if ($lot <= 0 && $desc === null) pb_proforma_fail($pdo, 'برای هر قلم، لات انبار یا شرح کالا لازم است');
        $out[] = ['lot' => $lot > 0 ? $lot : null, 'desc' => $desc, 'kg' => $kg, 'price' => $price, 'disc' => $disc];
    }
    return $out;
}

function pb_proforma_save(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $party = partyKey($p['party'] ?? '');
    if ($party === '' || mb_strlen($party) > 150) bad('نام مشتری را وارد کنید');
    $date = vdate($p['date'] ?? null);
    $vuRaw = $p['valid_until'] ?? null;
    $validUntil = ($vuRaw === null || $vuRaw === '') ? null : vdate($vuRaw);
    if ($validUntil !== null && $validUntil < $date) bad('تاریخ اعتبار نمی‌تواند قبل از تاریخ پیش‌فاکتور باشد');
    $reserve = !empty($p['reserve']) ? 1 : 0;
    $note = str_or_null($p['note'] ?? null, 500);
    $terms = str_or_null($p['terms'] ?? null, 1000);
    $pdo->beginTransaction();
    $lines = pb_proforma_read_items($pdo, $cid, $p['items'] ?? null);
    if ($id > 0) {
        $q = $pdo->prepare('SELECT id, status FROM proformas WHERE id=? AND customer_id=? FOR UPDATE');
        $q->execute([$id, $cid]);
        $row = $q->fetch();
        if (!$row) pb_proforma_fail($pdo, 'پیش‌فاکتور یافت نشد', 404);
        if ($row['status'] === 'converted') pb_proforma_fail($pdo, 'پیش‌فاکتور تبدیل‌شده قابل ویرایش نیست');
        $pdo->prepare('UPDATE proformas SET party=?, date=?, valid_until=?, reserve=?, note=?, terms=? WHERE id=? AND customer_id=?')
            ->execute([$party, $date, $validUntil, $reserve, $note, $terms, $id, $cid]);
        $pdo->prepare('DELETE FROM proforma_items WHERE proforma_id=? AND customer_id=?')->execute([$id, $cid]);
        $nq = $pdo->prepare('SELECT number FROM proformas WHERE id=? AND customer_id=?'); $nq->execute([$id, $cid]); $number = (int)$nq->fetchColumn(); // T23.3: prepared + tenant-scoped
    } else {
        $uid = (string)($p['uid'] ?? '');
        if ($uid === '') $uid = bin2hex(random_bytes(12));
        if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uid)) pb_proforma_fail($pdo, 'شناسهٔ پیش‌فاکتور نامعتبر است');
        $ex = $pdo->prepare('SELECT id, number FROM proformas WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $e = $ex->fetch();
        if ($e) { $pdo->rollBack(); return ['id' => (int)$e['id'], 'number' => (int)$e['number']]; } // duplicate submit
        $nq = $pdo->prepare('SELECT COALESCE(MAX(number),0)+1 FROM proformas WHERE customer_id=?');
        $nq->execute([$cid]);
        $number = (int)$nq->fetchColumn();
        $pdo->prepare('INSERT INTO proformas (customer_id, uid, number, party, date, valid_until, status, reserve, note, terms) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, $number, $party, $date, $validUntil, 'draft', $reserve, $note, $terms]);
        $id = (int)$pdo->lastInsertId();
    }
    $ins = $pdo->prepare('INSERT INTO proforma_items (customer_id, proforma_id, lot_id, description, kg, unit_price, discount) VALUES (?,?,?,?,?,?,?)');
    foreach ($lines as $l) $ins->execute([$cid, $id, $l['lot'], $l['desc'], $l['kg'], $l['price'], $l['disc']]);
    $pdo->commit();
    return ['id' => $id, 'number' => $number, 'total' => pb_proforma_total($pdo, $cid, $id)];
}

function pb_proforma_set_status(PDO $pdo, int $cid, array $p): array {
    $to = (string)($p['status'] ?? '');
    if (!in_array($to, ['draft', 'sent', 'accepted', 'cancelled'], true)) bad('وضعیت نامعتبر است (تبدیل به فروش با عملیات تبدیل انجام می‌شود)');
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT id, status FROM proformas WHERE id=? AND customer_id=? FOR UPDATE');
    $q->execute([(int)($p['id'] ?? 0), $cid]);
    $row = $q->fetch();
    if (!$row) pb_proforma_fail($pdo, 'پیش‌فاکتور یافت نشد', 404);
    if ($row['status'] === 'converted') pb_proforma_fail($pdo, 'پیش‌فاکتور تبدیل‌شده قابل تغییر وضعیت نیست');
    $pdo->prepare('UPDATE proformas SET status=? WHERE id=? AND customer_id=?')->execute([$to, (int)$row['id'], $cid]);
    $pdo->commit();
    return ['ok' => true];
}

function pb_proforma_delete(PDO $pdo, int $cid, array $p): array {
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT id, status FROM proformas WHERE id=? AND customer_id=? FOR UPDATE');
    $q->execute([(int)($p['id'] ?? 0), $cid]);
    $row = $q->fetch();
    if (!$row) pb_proforma_fail($pdo, 'پیش‌فاکتور یافت نشد', 404);
    if ($row['status'] === 'converted') pb_proforma_fail($pdo, 'پیش‌فاکتور تبدیل‌شده حذف نمی‌شود (ابتدا فروش‌های آن را حذف کنید)');
    $pdo->prepare('DELETE FROM proforma_items WHERE proforma_id=? AND customer_id=?')->execute([(int)$row['id'], $cid]);
    $pdo->prepare('DELETE FROM proformas WHERE id=? AND customer_id=?')->execute([(int)$row['id'], $cid]);
    $pdo->commit();
    return ['ok' => true];
}

function pb_proforma_list(PDO $pdo, int $cid, array $p): array {
    $sql = 'SELECT r.*, COALESCE((SELECT SUM(i.kg * i.unit_price - i.discount) FROM proforma_items i WHERE i.proforma_id=r.id AND i.customer_id=r.customer_id),0) AS total
        FROM proformas r WHERE r.customer_id=?';
    $args = [$cid];
    if (trim((string)($p['party'] ?? '')) !== '') { $sql .= ' AND r.party=?'; $args[] = partyKey($p['party']); }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($p[$k])) { $sql .= " AND r.date $op ?"; $args[] = vdate($p[$k]); }
    }
    $sql .= ' ORDER BY r.date DESC, r.id DESC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $want = (string)($p['status'] ?? '');
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $row = pb_proforma_row($r, (float)$r['total']);
        if ($want !== '' && $row['status'] !== $want) continue; // filter on the EFFECTIVE status (expired included)
        $out[] = $row;
    }
    return ['items' => $out];
}

function pb_proforma_get(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT * FROM proformas WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $r = $st->fetch();
    if (!$r) bad('پیش‌فاکتور یافت نشد', 404);
    $it = $pdo->prepare('SELECT i.lot_id, i.description, i.kg, i.unit_price, i.discount, i.sale_id, pu.code, pu.type_name, pu.remaining
        FROM proforma_items i LEFT JOIN purchases pu ON pu.id=i.lot_id AND pu.customer_id=i.customer_id
        WHERE i.proforma_id=? AND i.customer_id=? ORDER BY i.id');
    $it->execute([(int)$r['id'], $cid]);
    $items = [];
    foreach ($it->fetchAll() as $x) {
        $items[] = ['lot_id' => $x['lot_id'] === null ? null : (int)$x['lot_id'], 'description' => $x['description'], 'code' => $x['code'], 'type_name' => $x['type_name'],
            'kg' => (float)$x['kg'], 'unit_price' => (float)$x['unit_price'], 'discount' => (float)$x['discount'],
            'amount' => (float)$x['kg'] * (float)$x['unit_price'] - (float)$x['discount'],
            'sale_id' => $x['sale_id'] === null ? null : (int)$x['sale_id'], 'lot_remaining' => $x['remaining'] === null ? null : (float)$x['remaining']];
    }
    return pb_proforma_row($r, pb_proforma_total($pdo, $cid, (int)$r['id'])) + ['items' => $items];
}

/** convert: {id, date?, warehouse_uid? (applied to all items), usd_rate?}. Returns {sale_ids:[...]}. */
function pb_proforma_convert(PDO $pdo, int $cid, array $p, bool $isFx): array {
    $id = (int)($p['id'] ?? 0);
    $sDate = vdate($p['date'] ?? null);
    $reqWh = str_or_null($p['warehouse_uid'] ?? null, 36);
    if ($reqWh !== null) pb_wh_require($pdo, $cid, $reqWh);
    $usd = readUsdRate($p, $isFx);
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT * FROM proformas WHERE id=? AND customer_id=? FOR UPDATE');
    $q->execute([$id, $cid]);
    $pf = $q->fetch();
    if (!$pf) pb_proforma_fail($pdo, 'پیش‌فاکتور یافت نشد', 404);
    if ($pf['status'] === 'converted') pb_proforma_fail($pdo, 'این پیش‌فاکتور قبلاً به فروش تبدیل شده است');
    if ($pf['status'] === 'cancelled') pb_proforma_fail($pdo, 'پیش‌فاکتور لغوشده قابل تبدیل نیست');
    if (pb_proforma_effective((string)$pf['status'], $pf['valid_until'] === null ? null : (string)$pf['valid_until']) === 'expired') {
        pb_proforma_fail($pdo, 'مهلت اعتبار این پیش‌فاکتور گذشته است؛ ابتدا آن را ویرایش و تمدید کنید');
    }
    $its = $pdo->prepare('SELECT id, lot_id, kg, unit_price, discount FROM proforma_items WHERE proforma_id=? AND customer_id=? ORDER BY id');
    $its->execute([$id, $cid]);
    $items = $its->fetchAll();
    foreach ($items as $i) if ($i['lot_id'] === null) pb_proforma_fail($pdo, 'همهٔ اقلام باید به لات انبار وصل باشند تا تبدیل ممکن شود');
    $note = 'پیش‌فاکتور شمارهٔ ' . (int)$pf['number'];
    $saleIds = [];
    foreach ($items as $i) {
        $lot = (int)$i['lot_id'];
        $kg = (float)$i['kg'];
        $net = (float)$i['kg'] * (float)$i['unit_price'] - (float)$i['discount'];
        $price = round($net / $kg, 2);
        if ($price <= 0) pb_proforma_fail($pdo, 'مبلغ قلم پس از تخفیف باید مثبت باشد');
        $lk = $pdo->prepare('SELECT remaining FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $lk->execute([$lot, $cid]);
        $rem = $lk->fetchColumn();
        if ($rem === false) pb_proforma_fail($pdo, 'خرید (لات) قلم در انبار یافت نشد');
        if ($kg - (float)$rem > 1e-6) pb_proforma_fail($pdo, 'وزن فروش از موجودی این قلم بیشتر است');
        $wh = pb_stock_pick_wh_or_bad($pdo, $cid, $lot, $kg, $reqWh);
        $pdo->prepare('INSERT INTO sales (customer_id, date, price, total_weight, small_sack_count, large_sack_count, net_weight, buyer, note, usd_rate, labor_cost, transport_cost, proforma_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $sDate, $price, $kg, 0, 0, $kg, $pf['party'], $note, $usd, null, null, $id]);
        $saleId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (customer_id, sale_id, purchase_id, weight, item_price, small_sacks, large_sacks, warehouse_uid) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$cid, $saleId, $lot, $kg, $price, null, null, $wh]);
        $pdo->prepare('UPDATE purchases SET remaining = remaining - ? WHERE id=? AND customer_id=?')->execute([$kg, $lot, $cid]);
        $pdo->prepare('UPDATE proforma_items SET sale_id=? WHERE id=? AND customer_id=?')->execute([$saleId, (int)$i['id'], $cid]);
        pb_stock_post_doc($pdo, $cid, 'sale', $saleId);
        $saleIds[] = $saleId;
    }
    $pdo->prepare("UPDATE proformas SET status='converted', converted_at=NOW() WHERE id=? AND customer_id=?")->execute([$id, $cid]);
    $pdo->commit();
    return ['ok' => true, 'sale_ids' => $saleIds];
}

/** Hook of sales.delete: unlinks the sale from its proforma; when no linked sale remains the proforma becomes `accepted` again. */
function pb_proforma_on_sale_delete(PDO $pdo, int $cid, int $saleId): void {
    $st = $pdo->prepare('SELECT DISTINCT proforma_id FROM proforma_items WHERE customer_id=? AND sale_id=?');
    $st->execute([$cid, $saleId]);
    $pfs = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$pfs) return;
    $pdo->prepare('UPDATE proforma_items SET sale_id=NULL WHERE customer_id=? AND sale_id=?')->execute([$cid, $saleId]);
    foreach ($pfs as $pid) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM proforma_items WHERE customer_id=? AND proforma_id=? AND sale_id IS NOT NULL');
        $c->execute([$cid, (int)$pid]);
        if ((int)$c->fetchColumn() === 0) {
            $pdo->prepare("UPDATE proformas SET status='accepted', converted_at=NULL WHERE id=? AND customer_id=? AND status='converted'")->execute([(int)$pid, $cid]);
        }
    }
}

/** Soft reservation (informational): kg of a lot promised by live proformas with reserve=1. */
function pb_proforma_reserved_kg(PDO $pdo, int $cid, int $lotId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(i.kg),0) FROM proforma_items i JOIN proformas f ON f.id=i.proforma_id AND f.customer_id=i.customer_id
        WHERE i.customer_id=? AND i.lot_id=? AND f.reserve=1 AND f.status IN ('draft','sent','accepted') AND (f.valid_until IS NULL OR f.valid_until >= ?)");
    $st->execute([$cid, $lotId, date('Y-m-d')]);
    return (float)$st->fetchColumn();
}

/**
 * S04.T04.4c — offline convert: the app already created the sales (sales.create) and now links them.
 * payload: id, links:[{pos (0-based item order by id), sale_id}]. Idempotent; stock is NOT touched (the sales did it).
 */
function pb_proforma_link_sales(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $links = $p['links'] ?? null;
    if (!is_array($links) || count($links) < 1 || count($links) > 50) bad('اتصال فروش نامعتبر است');
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT id, status FROM proformas WHERE id=? AND customer_id=? FOR UPDATE');
    $q->execute([$id, $cid]);
    $pf = $q->fetch();
    if (!$pf) pb_proforma_fail($pdo, 'پیش‌فاکتور یافت نشد', 404);
    if ($pf['status'] === 'converted') { $pdo->rollBack(); return ['ok' => true, 'already' => true]; }
    $its = $pdo->prepare('SELECT id FROM proforma_items WHERE proforma_id=? AND customer_id=? ORDER BY id');
    $its->execute([$id, $cid]);
    $itemIds = $its->fetchAll(PDO::FETCH_COLUMN);
    foreach ($links as $l) {
        if (!is_array($l)) pb_proforma_fail($pdo, 'اتصال فروش نامعتبر است');
        $pos = (int)($l['pos'] ?? -1);
        $sid = (int)($l['sale_id'] ?? 0);
        if ($pos < 0 || $pos >= count($itemIds) || $sid <= 0) pb_proforma_fail($pdo, 'اتصال فروش نامعتبر است');
        $sc = $pdo->prepare('SELECT id FROM sales WHERE id=? AND customer_id=?');
        $sc->execute([$sid, $cid]);
        if (!$sc->fetchColumn()) pb_proforma_fail($pdo, 'فروش مربوط به پیش‌فاکتور یافت نشد', 404);
        $pdo->prepare('UPDATE proforma_items SET sale_id=? WHERE id=? AND customer_id=?')->execute([$sid, (int)$itemIds[$pos], $cid]);
        $pdo->prepare('UPDATE sales SET proforma_id=? WHERE id=? AND customer_id=?')->execute([$id, $sid, $cid]);
    }
    $pdo->prepare("UPDATE proformas SET status='converted', converted_at=NOW() WHERE id=? AND customer_id=?")->execute([$id, $cid]);
    $pdo->commit();
    return ['ok' => true];
}
