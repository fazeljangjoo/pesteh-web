<?php
declare(strict_types=1);
/**
 * S35.T35.4 — optional daily price table (grade_prices) + «قیمت پیشنهادی روز». Active only when form.price_table = 1 (suggest returns
 * {price:null} when off; the table itself can always be edited). Actions: grade_prices.list | save | bulk | delete | suggest.
 * Row = (type_name, ounce_uid NULL, grade_key NULL, price_per_kg BIGINT stored money unit, price_date Y-m-d). One row per
 * (type, ounce, grade, date): saving the same key again replaces the price. A suggestion is NEVER forced on a document.
 */
require_once __DIR__ . '/form_settings.php';

function pb_gp_clean(array $r): array {
    $type = trim((string)($r['type_name'] ?? ''));
    if ($type === '' || mb_strlen($type) > 100) bad('نام نوع پسته نامعتبر است');
    $ou = isset($r['ounce_uid']) && $r['ounce_uid'] !== '' ? (string)$r['ounce_uid'] : null;
    if ($ou !== null && !preg_match('/^[A-Za-z0-9_-]{8,36}$/', $ou)) bad('شناسهٔ اونس نامعتبر است');
    $gk = isset($r['grade_key']) && $r['grade_key'] !== '' ? (string)$r['grade_key'] : null;
    if ($gk !== null && !preg_match('/^[a-z0-9_]{1,40}$/', $gk)) bad('درجهٔ نامعتبر است');
    $price = $r['price_per_kg'] ?? null;
    if (!is_numeric($price) || (float)$price <= 0 || (float)$price > 9.0e15) bad('قیمت هر کیلو نامعتبر است');
    $d = (string)($r['price_date'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) bad('تاریخ قیمت نامعتبر است');
    return [$type, $ou, $gk, (int)round((float)$price), $d];
}

function pb_gp_upsert(PDO $pdo, int $cid, array $r): int {
    [$type, $ou, $gk, $price, $d] = pb_gp_clean($r);
    $st = $pdo->prepare('SELECT id FROM grade_prices WHERE customer_id=? AND type_name=? AND ounce_uid <=> ? AND grade_key <=> ? AND price_date=? ORDER BY id LIMIT 1');
    $st->execute([$cid, $type, $ou, $gk, $d]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE grade_prices SET price_per_kg=? WHERE id=? AND customer_id=?')->execute([$price, $id, $cid]);
        return (int)$id;
    }
    $pdo->prepare('INSERT INTO grade_prices (customer_id, type_name, ounce_uid, grade_key, price_per_kg, price_date) VALUES (?,?,?,?,?,?)')->execute([$cid, $type, $ou, $gk, $price, $d]);
    return (int)$pdo->lastInsertId();
}

function pb_gp_list(PDO $pdo, int $cid, array $p): array {
    $lim = max(1, min(2000, (int)($p['limit'] ?? 500)));
    $sql = 'SELECT id, type_name, ounce_uid, grade_key, price_per_kg, price_date FROM grade_prices WHERE customer_id=?';
    $args = [$cid];
    if (!empty($p['type_name'])) { $sql .= ' AND type_name=?'; $args[] = (string)$p['type_name']; }
    $sql .= " ORDER BY price_date DESC, id DESC LIMIT $lim";
    $st = $pdo->prepare($sql); $st->execute($args);
    $o = [];
    foreach ($st->fetchAll() as $r) {
        $o[] = ['id' => (int)$r['id'], 'type_name' => $r['type_name'], 'ounce_uid' => $r['ounce_uid'], 'grade_key' => $r['grade_key'],
                'price_per_kg' => (int)$r['price_per_kg'], 'price_date' => $r['price_date']];
    }
    return ['rows' => $o, 'active' => pb_form_on($pdo, $cid, 'price_table')];
}

function pb_gp_save(PDO $pdo, int $cid, array $p): array {
    return ['id' => pb_gp_upsert($pdo, $cid, $p)];
}

/** payload.rows = [{type_name, ounce_uid?, grade_key?, price_per_kg, price_date}] (max 500) — all or nothing; used by Excel import. */
function pb_gp_bulk(PDO $pdo, int $cid, array $p): array {
    $rows = $p['rows'] ?? null;
    if (!is_array($rows) || !$rows || count($rows) > 500) bad('فهرست قیمت‌ها نامعتبر است');
    foreach ($rows as $r) { if (!is_array($r)) bad('ردیف قیمت نامعتبر است'); pb_gp_clean($r); }
    $pdo->beginTransaction();
    try { foreach ($rows as $r) pb_gp_upsert($pdo, $cid, $r); $pdo->commit(); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['saved' => count($rows)];
}

function pb_gp_delete(PDO $pdo, int $cid, array $p): array {
    $pdo->prepare('DELETE FROM grade_prices WHERE id=? AND customer_id=?')->execute([(int)($p['id'] ?? 0), $cid]);
    return ['ok' => true];
}

/**
 * Latest price on/before `date` for type (+ounce, +grade). More specific rows (matching ounce/grade) win over generic ones,
 * then the newest date. Returns {price:int|null, price_date, grade_key, ounce_uid}; price null when the feature is off or nothing matches.
 */
function pb_gp_suggest(PDO $pdo, int $cid, array $p): array {
    $none = ['price' => null];
    if (!pb_form_on($pdo, $cid, 'price_table')) return $none;
    $type = trim((string)($p['type_name'] ?? ''));
    $d = (string)($p['date'] ?? date('Y-m-d'));
    if ($type === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $none;
    $ou = (string)($p['ounce_uid'] ?? ''); $gk = (string)($p['grade_key'] ?? '');
    $st = $pdo->prepare('SELECT ounce_uid, grade_key, price_per_kg, price_date FROM grade_prices WHERE customer_id=? AND type_name=? AND price_date<=?
        AND (ounce_uid IS NULL OR ounce_uid=?) AND (grade_key IS NULL OR grade_key=?)
        ORDER BY ((ounce_uid IS NOT NULL) + (grade_key IS NOT NULL)) DESC, price_date DESC, id DESC LIMIT 1');
    $st->execute([$cid, $type, $d, $ou, $gk]);
    $r = $st->fetch();
    if (!$r) return $none;
    return ['price' => (int)$r['price_per_kg'], 'price_date' => $r['price_date'], 'grade_key' => $r['grade_key'], 'ounce_uid' => $r['ounce_uid']];
}
