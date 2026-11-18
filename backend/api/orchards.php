<?php
declare(strict_types=1);
require_once __DIR__ . '/code_kind.php'; // T1: حرف کد پسته
require_once __DIR__ . '/ddl_guard.php';
/**
 * S10.T10.1 — orchards (باغ): orchards, season costs, harvests → stock lots, and per-season cost allocation. Both tiers.
 *
 * Tables:
 *  - orchards            master data (name unique per customer, ownership own|lease, cost_center_id nullable for Gold S15).
 *  - orchard_costs       season costs by category (water|labor|fertilizer|pesticide|rent|machinery|other). Blocked once the season is allocated.
 *  - harvests            one harvest = one purchase lot (is_mix=0, code H<id> (قدیمی: OR<id>)) with dry_kg as net weight; harvests.lot_id links it.
 *                        The link harvests.lot_id → lot IS the source-orchard link (no purchases column needed; backup-safe).
 *  - orchard_allocations one row per (orchard, season): blocks double allocation; stores basis, residual rounding and totals.
 *
 * Allocation (orchards.allocate):
 *  - basis weight: season costs split by dry_kg. basis market: dry_kg × market_per_kg (market value, no cost pool).
 *  - each lot price per kg = round(share / dry_kg, 2); the lot cost = round(price × dry_kg, 2), which is what stock values.
 *    Harvest.cost stores that exact lot cost. Σ lot cost vs. the pool differs only by rounding; it is stored as `residual`.
 *  - refused when a harvest lot was already used (sold / mixed / separated / transferred): then its stock valuation would change.
 * Stock: harvest = doc 'purchase' (purchases row). Re-costing reverses and re-posts that doc.
 */

const PB_ORCH_CATEGORIES = ['water', 'labor', 'fertilizer', 'pesticide', 'rent', 'machinery', 'other'];

function pb_orch_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS orchards (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        name VARCHAR(120) NOT NULL,
        location VARCHAR(200) NULL,
        area_ha DECIMAL(10,2) NOT NULL DEFAULT 0,
        tree_count INT UNSIGNED NOT NULL DEFAULT 0,
        variety VARCHAR(100) NULL,
        ownership VARCHAR(8) NOT NULL DEFAULT 'own',
        note VARCHAR(500) NULL,
        cost_center_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_orch_name (customer_id, name),
        UNIQUE KEY uq_orch_uid (customer_id, uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS orchard_costs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        orchard_id INT UNSIGNED NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        category VARCHAR(12) NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        cost_date DATE NOT NULL,
        party VARCHAR(150) NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ocost_uid (customer_id, uid),
        KEY idx_ocost_season (customer_id, orchard_id, season_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS harvests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        orchard_id INT UNSIGNED NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        harvest_date DATE NOT NULL,
        wet_kg DECIMAL(14,3) NOT NULL,
        dry_kg DECIMAL(14,3) NOT NULL,
        lot_id INT UNSIGNED NULL,
        cost DECIMAL(18,2) NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_harv_uid (customer_id, uid),
        KEY idx_harv_season (customer_id, orchard_id, season_year),
        KEY idx_harv_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS orchard_allocations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        orchard_id INT UNSIGNED NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        basis VARCHAR(8) NOT NULL,
        market_per_kg DECIMAL(18,2) NULL,
        pool_cost DECIMAL(18,2) NOT NULL,
        allocated_cost DECIMAL(18,2) NOT NULL,
        residual DECIMAL(18,2) NOT NULL DEFAULT 0,
        dry_kg DECIMAL(14,3) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_oalloc_season (customer_id, orchard_id, season_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_orch_row(array $r): array {
    return ['id' => (int)$r['id'], 'uid' => $r['uid'] ?? null, 'name' => $r['name'], 'location' => $r['location'], 'area_ha' => (float)$r['area_ha'],
        'tree_count' => (int)$r['tree_count'], 'variety' => $r['variety'], 'ownership' => $r['ownership'], 'note' => $r['note'],
        'cost_center_id' => $r['cost_center_id'] === null ? null : (int)$r['cost_center_id']];
}

function pb_orch_load(PDO $pdo, int $cid, int $id): array {
    $st = $pdo->prepare('SELECT * FROM orchards WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $o = $st->fetch();
    if (!$o) bad('باغ یافت نشد', 404);
    return $o;
}

function pb_orch_allocation(PDO $pdo, int $cid, int $orchardId, int $season): ?array {
    $st = $pdo->prepare('SELECT * FROM orchard_allocations WHERE customer_id=? AND orchard_id=? AND season_year=?');
    $st->execute([$cid, $orchardId, $season]);
    $r = $st->fetch();
    return $r ?: null;
}

/** orchards.list {} — orchards with season count and lifetime dry kg. */
function pb_orch_list(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT o.*, (SELECT COUNT(DISTINCT h.season_year) FROM harvests h WHERE h.customer_id=o.customer_id AND h.orchard_id=o.id) AS seasons,
        (SELECT COALESCE(SUM(h.dry_kg),0) FROM harvests h WHERE h.customer_id=o.customer_id AND h.orchard_id=o.id) AS dry_kg
        FROM orchards o WHERE o.customer_id=? ORDER BY o.name, o.id');
    $st->execute([$cid]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $x = pb_orch_row($r);
        $x['seasons'] = (int)$r['seasons'];
        $x['dry_kg'] = round((float)$r['dry_kg'], 3);
        $items[] = $x;
    }
    return ['items' => $items];
}

/** orchards.save {id?, uid?, name, location?, area_ha?, tree_count?, variety?, ownership?, note?, cost_center_id?} */
function pb_orch_save(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    $name = str_or_null($p['name'] ?? null, 120);
    if ($name === null) bad('نام باغ را وارد کنید');
    $own = (string)($p['ownership'] ?? 'own');
    if (!in_array($own, ['own', 'lease'], true)) bad('نوع مالکیت نامعتبر است');
    $area = num($p['area_ha'] ?? 0);
    $trees = (int)($p['tree_count'] ?? 0);
    if ($area < 0 || $area > 1e7 || $trees < 0 || $trees > 1e9) bad('مساحت یا تعداد درخت نامعتبر است');
    $cc = isset($p['cost_center_id']) && $p['cost_center_id'] !== '' ? (int)$p['cost_center_id'] : null;
    $vals = [str_or_null($p['location'] ?? null, 200), $area, $trees, str_or_null($p['variety'] ?? null, 100), $own, str_or_null($p['note'] ?? null, 500), $cc];
    $dup = $pdo->prepare('SELECT id FROM orchards WHERE customer_id=? AND name=?' . (isset($p['id']) ? ' AND id<>?' : ''));
    $dupArgs = [$cid, $name]; if (isset($p['id'])) $dupArgs[] = (int)$p['id'];
    $dup->execute($dupArgs);
    if ($dup->fetchColumn()) bad('باغی با این نام وجود دارد');
    if (!empty($p['id'])) {
        $o = pb_orch_load($pdo, $cid, (int)$p['id']);
        $pdo->prepare('UPDATE orchards SET name=?, location=?, area_ha=?, tree_count=?, variety=?, ownership=?, note=?, cost_center_id=? WHERE id=? AND customer_id=?')
            ->execute(array_merge([$name], $vals, [(int)$o['id'], $cid]));
        return pb_orch_get($pdo, $cid, ['id' => (int)$o['id']]);
    }
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM orchards WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $eid = $ex->fetchColumn();
        if ($eid) return pb_orch_get($pdo, $cid, ['id' => (int)$eid]);
    }
    $pdo->prepare('INSERT INTO orchards (customer_id, uid, name, location, area_ha, tree_count, variety, ownership, note, cost_center_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute(array_merge([$cid, $uid, $name], $vals));
    return pb_orch_get($pdo, $cid, ['id' => (int)$pdo->lastInsertId()]);
}

/** orchards.delete {id} — only an orchard with no costs and no harvests. */
function pb_orch_delete(PDO $pdo, int $cid, array $p): array {
    $o = pb_orch_load($pdo, $cid, (int)($p['id'] ?? 0));
    $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM harvests WHERE customer_id=? AND orchard_id=?) + (SELECT COUNT(*) FROM orchard_costs WHERE customer_id=? AND orchard_id=?)');
    $st->execute([$cid, (int)$o['id'], $cid, (int)$o['id']]);
    if ((int)$st->fetchColumn() > 0) bad('باغ دارای هزینه یا برداشت است و قابل حذف نیست');
    $pdo->prepare('DELETE FROM orchards WHERE id=? AND customer_id=?')->execute([(int)$o['id'], $cid]);
    return ['ok' => true];
}

/** orchards.get {id} — orchard + per-season summary (costs by category, harvests, allocation). */
function pb_orch_get(PDO $pdo, int $cid, array $p): array {
    $o = pb_orch_load($pdo, $cid, (int)($p['id'] ?? 0));
    $oid = (int)$o['id'];
    $row = pb_orch_row($o);
    $seasons = [];
    $years = $pdo->prepare('SELECT season_year FROM harvests WHERE customer_id=? AND orchard_id=? UNION SELECT season_year FROM orchard_costs WHERE customer_id=? AND orchard_id=? ORDER BY season_year');
    $years->execute([$cid, $oid, $cid, $oid]);
    $costQ = $pdo->prepare('SELECT category, SUM(amount) AS amt FROM orchard_costs WHERE customer_id=? AND orchard_id=? AND season_year=? GROUP BY category');
    $harvQ = $pdo->prepare('SELECT h.id, h.uid, h.harvest_date, h.wet_kg, h.dry_kg, h.lot_id, h.cost, h.note, p.code, p.price
        FROM harvests h LEFT JOIN purchases p ON p.id=h.lot_id AND p.customer_id=h.customer_id WHERE h.customer_id=? AND h.orchard_id=? AND h.season_year=? ORDER BY h.harvest_date, h.id');
    $costIdQ = $pdo->prepare('SELECT id FROM orchard_costs WHERE customer_id=? AND orchard_id=? AND season_year=? ORDER BY id');
    foreach ($years->fetchAll(PDO::FETCH_COLUMN) as $y) {
        $y = (int)$y;
        $costs = []; $costTotal = 0.0;
        $costIdQ->execute([$cid, $oid, $y]);
        $costIds = array_map('intval', $costIdQ->fetchAll(PDO::FETCH_COLUMN)); // T16.7b: for voucher links (orch_cost)
        $costQ->execute([$cid, $oid, $y]);
        foreach ($costQ->fetchAll() as $c) { $costs[$c['category']] = round((float)$c['amt'], 2); $costTotal += (float)$c['amt']; }
        $harvests = []; $dry = 0.0; $wet = 0.0;
        $harvQ->execute([$cid, $oid, $y]);
        foreach ($harvQ->fetchAll() as $h) {
            $dry += (float)$h['dry_kg']; $wet += (float)$h['wet_kg'];
            $harvests[] = ['id' => (int)$h['id'], 'uid' => $h['uid'], 'date' => $h['harvest_date'], 'wet_kg' => (float)$h['wet_kg'], 'dry_kg' => (float)$h['dry_kg'],
                'lot_id' => $h['lot_id'] === null ? null : (int)$h['lot_id'], 'code' => $h['code'], 'cost' => $h['cost'] === null ? null : (float)$h['cost'], 'note' => $h['note']];
        }
        $alloc = pb_orch_allocation($pdo, $cid, $oid, $y);
        $seasons[] = ['season_year' => $y, 'costs' => $costs, 'cost_ids' => $costIds, 'cost_total' => round($costTotal, 2), 'wet_kg' => round($wet, 3), 'dry_kg' => round($dry, 3),
            'dry_wet_ratio' => $wet > 0 ? round($dry / $wet, 4) : null,
            'cost_per_kg' => $dry > 0 ? round($costTotal / $dry, 2) : null,
            'yield_per_ha' => (float)$o['area_ha'] > 0 ? round($dry / (float)$o['area_ha'], 3) : null,
            'yield_per_tree' => (int)$o['tree_count'] > 0 ? round($dry / (int)$o['tree_count'], 3) : null,
            'allocated' => $alloc !== null, 'allocation' => $alloc, 'harvests' => $harvests];
    }
    $row['seasons'] = $seasons;
    return $row;
}

/** orchard_costs.add {orchard_id, season_year, category, amount, date?, party?, note?, uid?} — blocked once the season is allocated. */
function pb_orch_cost_add(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT orchard_id FROM orchard_costs WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $eo = $ex->fetchColumn();
        if ($eo) return pb_orch_get($pdo, $cid, ['id' => (int)$eo]);
    }
    $o = pb_orch_load($pdo, $cid, (int)($p['orchard_id'] ?? 0));
    $season = (int)($p['season_year'] ?? 0);
    if ($season < 1300 || $season > 1600) bad('سال زراعی نامعتبر است');
    if (pb_orch_allocation($pdo, $cid, (int)$o['id'], $season) !== null) bad('هزینه‌های این فصل تسهیم شده‌اند؛ هزینه‌ی جدید قابل ثبت نیست');
    $cat = (string)($p['category'] ?? '');
    if (!in_array($cat, PB_ORCH_CATEGORIES, true)) bad('دستهٔ هزینه نامعتبر است');
    $amt = num($p['amount'] ?? 0);
    if ($amt <= 0 || $amt > 1e13) bad('مبلغ هزینه باید بیشتر از صفر باشد');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $pdo->beginTransaction();
    try {
    $pdo->prepare('INSERT INTO orchard_costs (customer_id, uid, orchard_id, season_year, category, amount, cost_date, party, note, cost_center_uid) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $uid, (int)$o['id'], $season, $cat, round($amt, 2), $date, str_or_null($p['party'] ?? null, 150), str_or_null($p['note'] ?? null, 500), pb_cc_check($pdo, $cid, $p['cost_center_uid'] ?? null)]);
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'orch_cost', (int)$pdo->lastInsertId()); // S16.T16.5b (gold)
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_orch_get($pdo, $cid, ['id' => (int)$o['id']]);
}

/** orchard_costs.delete {id} — blocked once the season is allocated. */
function pb_orch_cost_delete(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT orchard_id, season_year FROM orchard_costs WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $c = $st->fetch();
    if (!$c) bad('هزینه یافت نشد', 404);
    if (pb_orch_allocation($pdo, $cid, (int)$c['orchard_id'], (int)$c['season_year']) !== null) bad('هزینه‌های این فصل تسهیم شده‌اند و قابل حذف نیستند');
    if (function_exists('pb_plc_unpost')) pb_plc_unpost($pdo, $cid, 'orch_cost', (int)($p['id'] ?? 0)); // S16.T16.5b
    $pdo->prepare('DELETE FROM orchard_costs WHERE id=? AND customer_id=?')->execute([(int)($p['id'] ?? 0), $cid]);
    return pb_orch_get($pdo, $cid, ['id' => (int)$c['orchard_id']]);
}

/** harvests.create {orchard_id, season_year, date?, wet_kg, dry_kg, warehouse_uid, category_id?, note?, uid?} — creates the stock lot. */
function pb_orch_harvest_create(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT orchard_id FROM harvests WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $eo = $ex->fetchColumn();
        if ($eo) return pb_orch_get($pdo, $cid, ['id' => (int)$eo]);
    }
    $o = pb_orch_load($pdo, $cid, (int)($p['orchard_id'] ?? 0));
    $season = (int)($p['season_year'] ?? 0);
    if ($season < 1300 || $season > 1600) bad('سال زراعی نامعتبر است');
    if (pb_orch_allocation($pdo, $cid, (int)$o['id'], $season) !== null) bad('این فصل تسهیم شده؛ برداشت جدید قابل ثبت نیست');
    $dry = num($p['dry_kg'] ?? 0); $wet = num($p['wet_kg'] ?? 0);
    if ($dry <= 0 || $dry > 1e9) bad('وزن خشک باید بیشتر از صفر باشد');
    if ($wet < $dry || $wet > 1e9) bad('وزن تر باید از وزن خشک بیشتر یا برابر باشد');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    if (empty($p['warehouse_uid']) || !is_string($p['warehouse_uid'])) bad('انبار محل نگهداری را انتخاب کنید');
    $wh = pb_wh_require($pdo, $cid, $p['warehouse_uid']);
    $cat = (int)($p['category_id'] ?? 0);
    if ($cat <= 0) {
        $fc = $pdo->prepare('SELECT id FROM categories WHERE customer_id=? ORDER BY id LIMIT 1');
        $fc->execute([$cid]);
        $cat = (int)$fc->fetchColumn();
    }
    if ($cat <= 0 || !ownsCategory($pdo, $cid, $cat)) bad('دستهٔ کالا یافت نشد', 404);
    $note = str_or_null($p['note'] ?? null, 500);
    $pdo->beginTransaction();
    try {
        // lot: is_mix=0 purchase (cost 0 until the season is allocated; the stock move is re-posted then)
        $ins = $pdo->prepare('INSERT INTO purchases (customer_id, code, category_id, type_name, date, price, total_weight, small_sack_count, large_sack_count, small_sacks_remaining, large_sacks_remaining, net_weight, remaining, seller, note, is_mix) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)');
        $ins->execute([$cid, null, $cat, 'برداشت باغ ' . $o['name'], $date, 0.0, $wet, 0, 0, 0, 0, $dry, $dry, null, $note]);
        $lotId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE purchases SET code=? WHERE id=? AND customer_id=?')->execute([pb_code_with_id('H', (int)$lotId), $lotId, $cid]);
        pb_wh_apply_lot($pdo, $cid, $lotId, ['warehouse_uid' => $wh], true);
        $pdo->prepare('INSERT INTO harvests (customer_id, uid, orchard_id, season_year, harvest_date, wet_kg, dry_kg, lot_id, note) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, (int)$o['id'], $season, $date, round($wet, 3), round($dry, 3), $lotId, $note]);
        pb_stock_post_doc($pdo, $cid, 'purchase', $lotId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_orch_get($pdo, $cid, ['id' => (int)$o['id']]);
}

/** orchards.allocate {orchard_id, season_year, basis: weight|market, market_per_kg?}
 *  Once per (orchard, season). Each harvest lot gets price/kg = round(share / dry_kg, 2); lot cost = round(price × dry_kg, 2). */
function pb_orch_allocate(PDO $pdo, int $cid, array $p): array {
    $o = pb_orch_load($pdo, $cid, (int)($p['orchard_id'] ?? 0));
    $oid = (int)$o['id'];
    $season = (int)($p['season_year'] ?? 0);
    $basis = (string)($p['basis'] ?? 'weight');
    if (!in_array($basis, ['weight', 'market'], true)) bad('روش تسهیم نامعتبر است');
    $market = num($p['market_per_kg'] ?? 0);
    if ($basis === 'market' && ($market <= 0 || $market > 1e9)) bad('قیمت بازار هر کیلو را وارد کنید');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM orchard_allocations WHERE customer_id=? AND orchard_id=? AND season_year=? FOR UPDATE');
        $lock->execute([$cid, $oid, $season]);
        if ($lock->fetchColumn()) bad('هزینه‌های این فصل قبلاً تسهیم شده‌اند');
        $hq = $pdo->prepare('SELECT id, lot_id, dry_kg FROM harvests WHERE customer_id=? AND orchard_id=? AND season_year=? ORDER BY id');
        $hq->execute([$cid, $oid, $season]);
        $hs = $hq->fetchAll();
        if (!$hs) bad('برداشتی برای این فصل ثبت نشده است');
        $cq = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM orchard_costs WHERE customer_id=? AND orchard_id=? AND season_year=?');
        $cq->execute([$cid, $oid, $season]);
        $pool = round((float)$cq->fetchColumn(), 2);
        $dryTotal = 0.0;
        $lotChk = $pdo->prepare('SELECT remaining, net_weight, type_name FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        foreach ($hs as $h) {
            $lotChk->execute([(int)$h['lot_id'], $cid]);
            $lot = $lotChk->fetch();
            if (!$lot) bad('لات برداشت یافت نشد', 404);
            if ((float)$lot['remaining'] + 1e-6 < (float)$lot['net_weight']) bad('بخشی از «' . $lot['type_name'] . '» فروخته یا تبدیل شده؛ تسهیم ممکن نیست');
            $dryTotal += (float)$h['dry_kg'];
        }
        // target share per harvest (money), then price per kg on the 2-decimal grid
        $shares = []; $acc = 0.0; $big = 0;
        foreach ($hs as $i => $h) {
            $shares[$i] = $basis === 'market' ? round((float)$h['dry_kg'] * $market, 2) : round($pool * (float)$h['dry_kg'] / $dryTotal, 2);
            $acc += $shares[$i];
            if ((float)$h['dry_kg'] > (float)$hs[$big]['dry_kg']) $big = $i;
        }
        if ($basis === 'weight') $shares[$big] = round($shares[$big] + ($pool - $acc), 2);
        $upPrice = $pdo->prepare('UPDATE purchases SET price=? WHERE id=? AND customer_id=?');
        $upH = $pdo->prepare('UPDATE harvests SET cost=? WHERE id=? AND customer_id=?');
        $allocated = 0.0;
        foreach ($hs as $i => $h) {
            $dry = (float)$h['dry_kg'];
            $price = round($shares[$i] / $dry, 2);
            $lotCost = round($price * $dry, 2);
            $allocated += $lotCost;
            $upPrice->execute([$price, (int)$h['lot_id'], $cid]);
            $upH->execute([$lotCost, (int)$h['id'], $cid]);
            pb_stock_reverse_doc($pdo, $cid, 'purchase', (int)$h['lot_id']); // re-cost: valuation moves change
            pb_stock_post_doc($pdo, $cid, 'purchase', (int)$h['lot_id']);
        }
        $allocated = round($allocated, 2);
        $residual = $basis === 'weight' ? round($pool - $allocated, 2) : 0.0;
        $pdo->prepare('INSERT INTO orchard_allocations (customer_id, orchard_id, season_year, basis, market_per_kg, pool_cost, allocated_cost, residual, dry_kg, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $oid, $season, $basis, $basis === 'market' ? $market : null, $basis === 'weight' ? $pool : round($allocated, 2), $allocated, $residual, round($dryTotal, 3), date('Y-m-d H:i:s')]); // تاریخ سند حسابداری از created_at می‌آید ⇒ ساعت تهران
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'orch_alloc', (int)$pdo->lastInsertId()); // S16.T16.5b (gold)
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_orch_get($pdo, $cid, ['id' => $oid]);
}

/** reports.orchard_performance {orchard_id?, season_year?} — per orchard & season.
 *  Yield / cost per kg: any stock viewer. Revenue, selling expense and profit: only with profit.view (server-enforced).
 *  Profit = Σ sales of its lots − season costs − selling expenses (broker commission we pay on those sales, apportioned by kg).
 *  Costs are the full season pool (allocated), as the stage defines it. */
function pb_orch_performance(PDO $pdo, int $cid, array $p, array $customer): array {
    $canProfit = pb_staff_can($customer, 'profit.view');
    $args = [$cid];
    $sql = 'SELECT * FROM orchards WHERE customer_id=?';
    if (!empty($p['orchard_id'])) { $sql .= ' AND id=?'; $args[] = (int)$p['orchard_id']; }
    $os = $pdo->prepare($sql . ' ORDER BY name, id');
    $os->execute($args);
    $season = !empty($p['season_year']) ? (int)$p['season_year'] : null;
    $items = [];
    foreach ($os->fetchAll() as $o) {
        $oid = (int)$o['id'];
        $area = (float)$o['area_ha']; $trees = (int)$o['tree_count'];
        $ys = $pdo->prepare('SELECT DISTINCT season_year FROM harvests WHERE customer_id=? AND orchard_id=?' . ($season ? ' AND season_year=?' : '') . ' ORDER BY season_year');
        $ys->execute($season ? [$cid, $oid, $season] : [$cid, $oid]);
        $prevYield = null;
        foreach ($ys->fetchAll(PDO::FETCH_COLUMN) as $y) {
            $y = (int)$y;
            $hq = $pdo->prepare('SELECT lot_id, wet_kg, dry_kg FROM harvests WHERE customer_id=? AND orchard_id=? AND season_year=?');
            $hq->execute([$cid, $oid, $y]);
            $wet = 0.0; $dry = 0.0; $lots = [];
            foreach ($hq->fetchAll() as $h) { $wet += (float)$h['wet_kg']; $dry += (float)$h['dry_kg']; if ($h['lot_id']) $lots[] = (int)$h['lot_id']; }
            $cq = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM orchard_costs WHERE customer_id=? AND orchard_id=? AND season_year=?');
            $cq->execute([$cid, $oid, $y]);
            $costs = round((float)$cq->fetchColumn(), 2);
            // sales of this season's lots
            $soldKg = 0.0; $revenue = 0.0; $expense = 0.0;
            if ($lots) {
                $ph = implode(',', array_fill(0, count($lots), '?'));
                $sq = $pdo->prepare("SELECT si.sale_id, si.weight, COALESCE(si.item_price, s.price) AS unit FROM sale_items si
                    JOIN sales s ON s.id=si.sale_id AND s.customer_id=si.customer_id WHERE si.customer_id=? AND si.purchase_id IN ($ph)");
                $sq->execute(array_merge([$cid], $lots));
                $bySale = [];
                foreach ($sq->fetchAll() as $r) {
                    $w = (float)$r['weight'];
                    $soldKg += $w; $revenue += round($w * (float)$r['unit'], 2);
                    $bySale[(int)$r['sale_id']] = ($bySale[(int)$r['sale_id']] ?? 0.0) + $w;
                }
                foreach ($bySale as $sid => $orchKg) {
                    $tq = $pdo->prepare('SELECT COALESCE(SUM(weight),0) FROM sale_items WHERE customer_id=? AND sale_id=?');
                    $tq->execute([$cid, $sid]);
                    $saleKg = (float)$tq->fetchColumn();
                    $bq = $pdo->prepare("SELECT COALESCE(broker_amount,0) FROM sales WHERE customer_id=? AND id=? AND broker_payer='us'");
                    $bq->execute([$cid, $sid]);
                    $amt = (float)$bq->fetchColumn();
                    if ($amt > 0 && $saleKg > 0) $expense += $amt * $orchKg / $saleKg;
                }
            }
            $revenue = round($revenue, 2); $expense = round($expense, 2);
            $profit = round($revenue - $costs - $expense, 2);
            $yield = $area > 0 ? round($dry / $area, 3) : null;
            $row = ['orchard_id' => $oid, 'orchard' => $o['name'], 'season_year' => $y, 'wet_kg' => round($wet, 3), 'dry_kg' => round($dry, 3),
                'dry_wet_ratio' => $wet > 0 ? round($dry / $wet, 4) : null,
                'yield_per_ha' => $yield, 'yield_per_tree' => $trees > 0 ? round($dry / $trees, 3) : null,
                'yield_change_pct' => ($prevYield !== null && $yield !== null && $prevYield > 0) ? round(($yield - $prevYield) / $prevYield * 100, 2) : null,
                'costs' => $costs, 'cost_per_kg' => $dry > 0 ? round($costs / $dry, 2) : null,
                'sold_kg' => round($soldKg, 3), 'unsold_kg' => round($dry - $soldKg, 3)];
            if ($canProfit) {
                $row['revenue'] = $revenue; $row['selling_expense'] = $expense; $row['profit'] = $profit;
                $row['margin_pct'] = $revenue > 0 ? round($profit / $revenue * 100, 2) : null;
            }
            $items[] = $row;
            $prevYield = $yield;
        }
    }
    return ['items' => $items, 'can_profit' => $canProfit];
}
