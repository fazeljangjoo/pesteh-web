<?php
declare(strict_types=1);
require_once __DIR__ . '/code_kind.php'; // T1: حرف کد پسته
require_once __DIR__ . '/ddl_guard.php';
/**
 * S07.T07.1 — multi-output separation (کوتریزی / جداسازی) v2. Both tiers.
 * Legacy single-output mixes are untouched (purchases.is_mix=1, mix_sources); a separation is the general case:
 * N input lots -> M output lots (+ waste). Output lots are `purchases` rows with is_mix=2 (so every existing sale/stock/transfer path works,
 * but they are NOT plain purchases: no seller/payment, excluded from `is_mix=0` queries, and never edited as mixes).
 *
 * Mass balance: Σinput kg = Σoutput kg + waste_kg (± tolerance_kg setting).
 * Cost (lot-specific, DECISIONS A8/B9): distributable = Σ(input kg × input unit cost) + labor + transport − abnormal_cost
 *   where abnormal waste = max(0, waste − abnormal_pct% × Σinput kg) valued at the average input cost and EXPENSED (not capitalized);
 *   normal waste stays in the output cost (absorbed). Allocation by_weight | by_value (by_value needs alloc_price on every output).
 *   Output cost is rounded to 2 decimals per line, remainder pushed to the largest output, so
 *   Σ output cost + abnormal_cost = Σ input cost + labor + transport EXACTLY (check block in separations.get).
 * Output lot tracking code `K-<code of largest input>-<n>`; lot_lineage(parent,child) stores lineage (queried both ways, mixes included).
 * stock_moves: doc_type 'sep', kinds sep_out (inputs) / sep_in (outputs), reversed/reposted by pb_stock_post_doc.
 * Edit/delete only while no output lot has been sold/consumed (remaining = net_weight and no sale/mix/separation references).
 */
const PB_SEP_DEFAULT_TOLERANCE = 0.0015;
const PB_SEP_DEFAULT_ABNORMAL_PCT = 5.0; // business default (NOT statutory); changeable per customer via separations.settings_save

function pb_sep_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS separations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        sep_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        labor_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
        transport_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
        waste_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        cost_alloc VARCHAR(10) NOT NULL DEFAULT 'by_weight',
        warehouse_uid CHAR(36) NULL,
        input_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        input_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        abnormal_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        abnormal_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sep_uid (customer_id, uid),
        KEY idx_sep_cust (customer_id, sep_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS separation_inputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        separation_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NULL,
        large_sacks DECIMAL(10,2) NULL,
        warehouse_uid CHAR(36) NULL,
        unit_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
        cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        KEY idx_si_sep (customer_id, separation_id),
        KEY idx_si_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS separation_outputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        separation_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        type_name VARCHAR(100) NOT NULL,
        ounce_uid CHAR(36) NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        alloc_price DECIMAL(16,2) NULL,
        cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        KEY idx_so_sep (customer_id, separation_id),
        KEY idx_so_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS lot_lineage (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        separation_id INT UNSIGNED NOT NULL,
        parent_lot_id INT UNSIGNED NOT NULL,
        child_lot_id INT UNSIGNED NOT NULL,
        parent_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        KEY idx_ll_parent (customer_id, parent_lot_id),
        KEY idx_ll_child (customer_id, child_lot_id),
        KEY idx_ll_sep (customer_id, separation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS separation_settings (
        customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
        tolerance_kg DECIMAL(10,4) NOT NULL DEFAULT 0.0015,
        abnormal_pct DECIMAL(6,2) NOT NULL DEFAULT 5.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_sep_fail(PDO $pdo, string $msg, int $code = 400): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    bad($msg, $code);
}

function pb_sep_settings(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT tolerance_kg, abnormal_pct FROM separation_settings WHERE customer_id=?');
    $st->execute([$cid]);
    $r = $st->fetch();
    return ['tolerance_kg' => $r ? (float)$r['tolerance_kg'] : PB_SEP_DEFAULT_TOLERANCE, 'abnormal_pct' => $r ? (float)$r['abnormal_pct'] : PB_SEP_DEFAULT_ABNORMAL_PCT];
}

/** separations.settings_save {tolerance_kg?, abnormal_pct?} */
function pb_sep_settings_save(PDO $pdo, int $cid, array $p): array {
    $cur = pb_sep_settings($pdo, $cid);
    $tol = array_key_exists('tolerance_kg', $p) ? num($p['tolerance_kg']) : $cur['tolerance_kg'];
    $pct = array_key_exists('abnormal_pct', $p) ? num($p['abnormal_pct']) : $cur['abnormal_pct'];
    if ($tol < 0 || $tol > 1000) bad('حد مجاز اختلاف وزن نامعتبر است');
    if ($pct < 0 || $pct > 100) bad('درصد ضایعات عادی باید بین ۰ تا ۱۰۰ باشد');
    $pdo->prepare('INSERT INTO separation_settings (customer_id, tolerance_kg, abnormal_pct) VALUES (?,?,?) ON DUPLICATE KEY UPDATE tolerance_kg=VALUES(tolerance_kg), abnormal_pct=VALUES(abnormal_pct)')
        ->execute([$cid, $tol, $pct]);
    return pb_sep_settings($pdo, $cid);
}

/** True when any output lot of the separation was sold / consumed / re-separated / mixed. */
function pb_sep_outputs_used(PDO $pdo, int $cid, int $sepId): ?string {
    $o = $pdo->prepare('SELECT o.lot_id, p.code, p.net_weight, p.remaining, p.small_sack_count, p.small_sacks_remaining, p.large_sack_count, p.large_sacks_remaining
        FROM separation_outputs o LEFT JOIN purchases p ON p.id=o.lot_id AND p.customer_id=o.customer_id WHERE o.customer_id=? AND o.separation_id=?');
    $o->execute([$cid, $sepId]);
    foreach ($o->fetchAll() as $r) {
        $code = (string)($r['code'] ?? $r['lot_id']);
        if ($r['net_weight'] === null) continue;
        if (abs((float)$r['net_weight'] - (float)$r['remaining']) > 1e-6
            || abs((float)$r['small_sack_count'] - (float)$r['small_sacks_remaining']) > 1e-6
            || abs((float)$r['large_sack_count'] - (float)$r['large_sacks_remaining']) > 1e-6) return $code;
        foreach ([['sale_items', 'purchase_id', ''], ['mix_sources', 'source_purchase_id', ''], ['separation_inputs', 'lot_id', ' AND separation_id<>' . $sepId],
                  ['purchase_return_items', 'lot_id', ''], ['proc_inputs', 'lot_id', ''], ['consignment_items', 'lot_id', '']] as [$t, $col, $extra]) {
            try {
                $q = $pdo->prepare("SELECT 1 FROM $t WHERE customer_id=? AND $col=?$extra LIMIT 1");
                $q->execute([$cid, (int)$r['lot_id']]);
                if ($q->fetchColumn()) return $code;
            } catch (Throwable $e) { /* optional table absent */ }
        }
    }
    return null;
}

function pb_sep_next_code(PDO $pdo, int $cid, string $parentCode, int $sepId, array &$taken, string $letter = 'S'): string {
    $base = pb_code_chain($letter, $parentCode, $sepId); // T1: S-<کد والد>-n (کارگاه: W)؛ خروجیِ خروجی همان زنجیره را ادامه می‌دهد: S-KH00012-1-2؛ قدیمی K-… هم ادامه می‌یابد
    $n = 1;
    $chk = $pdo->prepare('SELECT 1 FROM purchases WHERE customer_id=? AND code=? LIMIT 1');
    while (true) {
        $c = $base . '-' . $n;
        $chk->execute([$cid, $c]);
        if (!isset($taken[$c]) && !$chk->fetchColumn()) { $taken[$c] = true; return $c; }
        $n++;
    }
}

/** Undo a separation's effects (inputs back to stock, output lots + moves + lineage removed). Caller owns the transaction. */
function pb_sep_revert(PDO $pdo, int $cid, int $sepId): void {
    $in = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks FROM separation_inputs WHERE customer_id=? AND separation_id=?');
    $in->execute([$cid, $sepId]);
    foreach ($in->fetchAll() as $r) restoreStock($pdo, $cid, (int)$r['lot_id'], (float)$r['kg'], $r['small_sacks'], $r['large_sacks']);
    $out = $pdo->prepare('SELECT lot_id FROM separation_outputs WHERE customer_id=? AND separation_id=?');
    $out->execute([$cid, $sepId]);
    $lots = $out->fetchAll(PDO::FETCH_COLUMN);
    foreach ($lots as $lid) pb_stock_drop_lot_transfers($pdo, $cid, (int)$lid);
    pb_stock_reverse_doc($pdo, $cid, 'sep', $sepId);
    foreach ($lots as $lid) $pdo->prepare('DELETE FROM purchases WHERE id=? AND customer_id=? AND is_mix=2')->execute([(int)$lid, $cid]);
    foreach (['lot_lineage', 'separation_outputs', 'separation_inputs'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE customer_id=? AND separation_id=?")->execute([$cid, $sepId]);
    }
}

/** Validates the payload, takes stock, creates output lots, costs, lineage and moves for separation $sepId (row already inserted). */
function pb_sep_apply(PDO $pdo, int $cid, int $sepId, array $p, string $date): void {
    $cfg = pb_sep_settings($pdo, $cid);
    $inputs = normalizeMixSources($p['inputs'] ?? []);
    $outs = $p['outputs'] ?? [];
    if (!is_array($outs) || count($outs) < 1) pb_sep_fail($pdo, 'حداقل یک خروجی ثبت کنید');
    if (count($outs) > 100) pb_sep_fail($pdo, 'تعداد خروجی‌ها بیش از حد مجاز است');
    $labor = num($p['labor_cost'] ?? 0);
    $transport = num($p['transport_cost'] ?? 0);
    $waste = num($p['waste_kg'] ?? 0);
    if ($labor < 0 || $transport < 0 || $labor > 1e13 || $transport > 1e13) pb_sep_fail($pdo, 'هزینهٔ دستمزد یا حمل نامعتبر است');
    if ($waste < 0 || $waste > 1e9) pb_sep_fail($pdo, 'وزن ضایعات نامعتبر است');
    $alloc = (string)($p['cost_alloc'] ?? 'by_weight');
    if (!in_array($alloc, ['by_weight', 'by_value'], true)) pb_sep_fail($pdo, 'روش تسهیم هزینه نامعتبر است');

    // inputs: lock, check stock, cost
    $lock = $pdo->prepare('SELECT p.is_mix, p.remaining, p.type_name, p.code, p.category_id, p.small_sacks_remaining, p.large_sacks_remaining, ' . PB_BROKER_COST_SQL . ' AS cost
        FROM purchases p WHERE p.id=? AND p.customer_id=? FOR UPDATE');
    $sumKg = 0.0; $sumCost = 0.0; $rows = []; $big = null; $firstCat = 0;
    foreach ($inputs as $s) {
        $w = (float)$s['weight'];
        if ($w <= 0) continue;
        $lock->execute([$s['purchase_id'], $cid]);
        $row = $lock->fetch();
        if (!$row) pb_sep_fail($pdo, 'یکی از ورودی‌ها یافت نشد', 404);
        if ((int)$row['is_mix'] === 3) pb_sep_fail($pdo, 'کالای امانی قابل جداسازی نیست');
        if ($w - (float)$row['remaining'] > 1e-6) pb_sep_fail($pdo, 'موجودی «' . $row['type_name'] . '» کافی نیست');
        checkSacksAvailable($pdo, $row, $s['small_sacks'], $s['large_sacks']);
        $unit = (float)$row['cost'];
        $rows[] = ['s' => $s, 'w' => $w, 'unit' => $unit, 'cost' => round($w * $unit, 2), 'code' => (string)($row['code'] ?? $s['purchase_id'])];
        $sumKg += $w; $sumCost += $w * $unit;
        if ($big === null || $w > $big['w']) $big = ['w' => $w, 'code' => (string)($row['code'] ?? $s['purchase_id'])];
        if ($firstCat === 0) $firstCat = (int)$row['category_id'];
    }
    if ($sumKg <= 0) pb_sep_fail($pdo, 'حداقل از یک ورودی وزن بردارید');
    $inCost = round($sumCost, 2);

    // outputs: validate + mass balance
    $outKg = 0.0; $outs2 = [];
    foreach ($outs as $o) {
        if (!is_array($o)) pb_sep_fail($pdo, 'خروجی نامعتبر است');
        $kg = num($o['kg'] ?? 0);
        $name = str_or_null($o['type_name'] ?? null, 100);
        if ($kg <= 0 || $kg > 1e9) pb_sep_fail($pdo, 'وزن هر خروجی باید بیشتر از صفر باشد');
        if ($name === null) pb_sep_fail($pdo, 'نام/نوع هر خروجی را وارد کنید');
        $sm = num($o['small_sacks'] ?? 0); $lg = num($o['large_sacks'] ?? 0);
        if ($sm < 0 || $lg < 0) pb_sep_fail($pdo, 'تعداد گونی نامعتبر است');
        $ap = nullableNum($o['alloc_price'] ?? null);
        if ($alloc === 'by_value' && ($ap === null || $ap <= 0)) pb_sep_fail($pdo, 'برای تسهیم بر اساس ارزش، فی مبنا را برای همهٔ خروجی‌ها وارد کنید');
        $cat = (int)($o['category_id'] ?? 0);
        if ($cat <= 0) $cat = $firstCat;
        if (!ownsCategory($pdo, $cid, $cat)) pb_sep_fail($pdo, 'دستهٔ خروجی یافت نشد', 404);
        $outKg += $kg;
        $outs2[] = ['o' => $o, 'kg' => $kg, 'name' => $name, 'sm' => $sm, 'lg' => $lg, 'ap' => $ap, 'cat' => $cat];
    }
    $diff = $sumKg - $outKg - $waste;
    if (abs($diff) > $cfg['tolerance_kg'] + 1e-9) {
        $f = function ($v) { return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.'); };
        pb_sep_fail($pdo, $diff > 0
            ? 'وزن ورودی ' . $f($sumKg) . ' کیلو است ولی خروجی + ضایعات فقط ' . $f($outKg + $waste) . ' کیلو می‌شود؛ ' . $f($diff) . ' کیلو اختلاف ثبت‌نشده دارید'
            : 'خروجی + ضایعات (' . $f($outKg + $waste) . ' کیلو) از وزن ورودی (' . $f($sumKg) . ' کیلو) بیشتر است؛ ' . $f(-$diff) . ' کیلو اضافه است');
    }

    // abnormal waste: expensed; normal waste absorbed
    $abKg = max(0.0, $waste - $sumKg * $cfg['abnormal_pct'] / 100.0);
    $abCost = round($abKg * ($sumCost / $sumKg), 2);
    $distributable = round($inCost + $labor + $transport - $abCost, 2);
    if ($distributable < 0) pb_sep_fail($pdo, 'هزینهٔ قابل تسهیم منفی شد؛ ورودی‌ها را بررسی کنید');

    // allocate (2-decimal lines, remainder to the largest output so the sum is exact)
    $wts = []; $sumW = 0.0;
    foreach ($outs2 as $i => $x) { $wts[$i] = $alloc === 'by_value' ? $x['kg'] * (float)$x['ap'] : $x['kg']; $sumW += $wts[$i]; }
    $costs = []; $acc = 0.0; $bigIdx = 0;
    foreach ($outs2 as $i => $x) {
        $costs[$i] = round($distributable * $wts[$i] / $sumW, 2);
        $acc += $costs[$i];
        if ($wts[$i] > $wts[$bigIdx]) $bigIdx = $i;
    }
    $costs[$bigIdx] = round($costs[$bigIdx] + ($distributable - $acc), 2);

    // persist inputs + take stock
    $insIn = $pdo->prepare('INSERT INTO separation_inputs (customer_id, separation_id, lot_id, kg, small_sacks, large_sacks, warehouse_uid, unit_cost, cost) VALUES (?,?,?,?,?,?,?,?,?)');
    $rem = $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?');
    foreach ($rows as $r) {
        $s = $r['s'];
        $wh = pb_stock_pick_wh_or_bad($pdo, $cid, (int)$s['purchase_id'], $r['w'], $s['warehouse_uid'] ?? null);
        $insIn->execute([$cid, $sepId, $s['purchase_id'], $r['w'], $s['small_sacks'], $s['large_sacks'], $wh, round($r['unit'], 4), $r['cost']]);
        $rem->execute([$r['w'], (float)($s['small_sacks'] ?? 0), (float)($s['large_sacks'] ?? 0), $s['purchase_id'], $cid]);
    }

    // output lots
    $sepWh = null;
    if (!empty($p['warehouse_uid']) && is_string($p['warehouse_uid'])) $sepWh = pb_wh_require($pdo, $cid, $p['warehouse_uid']);
    $taken = [];
    $insLot = $pdo->prepare('INSERT INTO purchases (customer_id, code, category_id, type_name, date, price, total_weight, small_sack_count, large_sack_count, small_sacks_remaining, large_sacks_remaining, net_weight, remaining, seller, note, ounce, gram, usd_rate, price_usd, waste, base_type, is_mix) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,2)');
    $insOut = $pdo->prepare('INSERT INTO separation_outputs (customer_id, separation_id, lot_id, type_name, ounce_uid, kg, small_sacks, large_sacks, alloc_price, cost) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $insLin = $pdo->prepare('INSERT INTO lot_lineage (customer_id, separation_id, parent_lot_id, child_lot_id, parent_kg) VALUES (?,?,?,?,?)');
    $note = str_or_null($p['note'] ?? null, 500);
    foreach ($outs2 as $i => $x) {
        $code = pb_sep_next_code($pdo, $cid, (string)$big['code'], $sepId, $taken);
        $price = round($costs[$i] / $x['kg'], 2);
        $ounce = nullableNum($x['o']['ounce'] ?? null);
        $gram = nullableNum($x['o']['gram'] ?? null);
        $insLot->execute([$cid, $code, $x['cat'], $x['name'], $date, $price, $x['kg'], $x['sm'], $x['lg'], $x['sm'], $x['lg'], $x['kg'], $x['kg'],
            null, $note, $ounce, $gram, null, null, null, null]);
        $lotId = (int)$pdo->lastInsertId();
        $lp = ['warehouse_uid' => $sepWh];
        if (array_key_exists('ounce_uid', $x['o'])) $lp['ounce_uid'] = $x['o']['ounce_uid'];
        pb_wh_apply_lot($pdo, $cid, $lotId, $lp, true);
        $ouid = $pdo->prepare('SELECT ounce_uid FROM purchases WHERE id=? AND customer_id=?');
        $ouid->execute([$lotId, $cid]);
        $insOut->execute([$cid, $sepId, $lotId, $x['name'], $ouid->fetchColumn() ?: null, $x['kg'], $x['sm'], $x['lg'], $x['ap'], $costs[$i]]);
        foreach ($rows as $r) $insLin->execute([$cid, $sepId, $r['s']['purchase_id'], $lotId, $r['w']]);
    }
    $pdo->prepare('UPDATE separations SET labor_cost=?, transport_cost=?, waste_kg=?, cost_alloc=?, warehouse_uid=?, input_kg=?, input_cost=?, abnormal_kg=?, abnormal_cost=?, note=? WHERE id=? AND customer_id=?')
        ->execute([$labor, $transport, $waste, $alloc, $sepWh, $sumKg, $inCost, $abKg, $abCost, $note, $sepId, $cid]);
    pb_stock_post_doc($pdo, $cid, 'sep', $sepId);
    if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'sep', $sepId); // S16.T16.5a (gold): replaces the voucher on update
}

/** separations.create {uid?, date?, note?, labor_cost?, transport_cost?, waste_kg?, cost_alloc?, warehouse_uid?, inputs:[{purchase_id,weight,small_sacks?,large_sacks?,warehouse_uid?}], outputs:[{type_name,kg,small_sacks?,large_sacks?,alloc_price?,ounce?,gram?,ounce_uid?,category_id?}]} */
function pb_sep_create(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM separations WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $id = $ex->fetchColumn();
        if ($id) return pb_sep_get($pdo, $cid, ['id' => (int)$id]); // idempotent replay (offline queue)
    }
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO separations (customer_id, uid, sep_date) VALUES (?,?,?)')->execute([$cid, $uid, $date]);
        $id = (int)$pdo->lastInsertId();
        pb_sep_apply($pdo, $cid, $id, $p, $date);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_sep_get($pdo, $cid, ['id' => $id]);
}

/** separations.update {id, ...same as create}. Only while no output was sold/consumed; output lots are re-created (new ids), tracking codes are re-derived. */
function pb_sep_update(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, sep_date FROM separations WHERE id=? AND customer_id=? FOR UPDATE');
        $st->execute([$id, $cid]);
        $s = $st->fetch();
        if (!$s) pb_sep_fail($pdo, 'جداسازی یافت نشد', 404);
        $used = pb_sep_outputs_used($pdo, $cid, $id);
        if ($used !== null) pb_sep_fail($pdo, 'از خروجی «' . $used . '» فروش یا مصرف شده است؛ ویرایش ممکن نیست', 409);
        pb_sep_revert($pdo, $cid, $id);
        $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : (string)$s['sep_date'];
        $pdo->prepare('UPDATE separations SET sep_date=? WHERE id=? AND customer_id=?')->execute([$date, $id, $cid]);
        pb_sep_apply($pdo, $cid, $id, $p, $date);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_sep_get($pdo, $cid, ['id' => $id]);
}

function pb_sep_delete(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM separations WHERE id=? AND customer_id=? FOR UPDATE');
        $st->execute([$id, $cid]);
        if (!$st->fetchColumn()) pb_sep_fail($pdo, 'جداسازی یافت نشد', 404);
        $used = pb_sep_outputs_used($pdo, $cid, $id);
        if ($used !== null) pb_sep_fail($pdo, 'از خروجی «' . $used . '» فروش یا مصرف شده است؛ حذف ممکن نیست', 409);
        pb_sep_revert($pdo, $cid, $id);
        if (function_exists('pb_plc_unpost')) pb_plc_unpost($pdo, $cid, 'sep', $id); // S16.T16.5a
        $pdo->prepare('DELETE FROM separations WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}

function pb_sep_row(array $r): array {
    return ['id' => (int)$r['id'], 'uid' => $r['uid'], 'date' => $r['sep_date'], 'note' => $r['note'], 'labor_cost' => (float)$r['labor_cost'], 'transport_cost' => (float)$r['transport_cost'],
        'waste_kg' => (float)$r['waste_kg'], 'cost_alloc' => $r['cost_alloc'], 'warehouse_uid' => $r['warehouse_uid'], 'input_kg' => (float)$r['input_kg'],
        'input_cost' => (float)$r['input_cost'], 'abnormal_kg' => (float)$r['abnormal_kg'], 'abnormal_cost' => (float)$r['abnormal_cost']];
}

/** separations.get {id} -> separation + inputs + outputs (with codes) + `check` (cost + mass reconciliation) + `editable`. */
function pb_sep_get(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT * FROM separations WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $s = $st->fetch();
    if (!$s) bad('جداسازی یافت نشد', 404);
    $id = (int)$s['id'];
    $in = $pdo->prepare('SELECT i.lot_id, p.code, p.type_name, i.kg, i.small_sacks, i.large_sacks, i.warehouse_uid, i.unit_cost, i.cost
        FROM separation_inputs i LEFT JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.customer_id=? AND i.separation_id=? ORDER BY i.id');
    $in->execute([$cid, $id]);
    $inputs = [];
    foreach ($in->fetchAll() as $r) $inputs[] = ['lot_id' => (int)$r['lot_id'], 'code' => $r['code'], 'type_name' => $r['type_name'], 'kg' => (float)$r['kg'],
        'small_sacks' => $r['small_sacks'] === null ? null : (float)$r['small_sacks'], 'large_sacks' => $r['large_sacks'] === null ? null : (float)$r['large_sacks'],
        'warehouse_uid' => $r['warehouse_uid'], 'unit_cost' => (float)$r['unit_cost'], 'cost' => (float)$r['cost']];
    $o = $pdo->prepare('SELECT o.lot_id, p.code, p.price, p.remaining, o.type_name, o.ounce_uid, o.kg, o.small_sacks, o.large_sacks, o.alloc_price, o.cost
        FROM separation_outputs o LEFT JOIN purchases p ON p.id=o.lot_id AND p.customer_id=o.customer_id WHERE o.customer_id=? AND o.separation_id=? ORDER BY o.id');
    $o->execute([$cid, $id]);
    $outputs = [];
    foreach ($o->fetchAll() as $r) $outputs[] = ['lot_id' => (int)$r['lot_id'], 'code' => $r['code'], 'type_name' => $r['type_name'], 'ounce_uid' => $r['ounce_uid'], 'kg' => (float)$r['kg'],
        'small_sacks' => (float)$r['small_sacks'], 'large_sacks' => (float)$r['large_sacks'], 'alloc_price' => $r['alloc_price'] === null ? null : (float)$r['alloc_price'],
        'cost' => (float)$r['cost'], 'unit_cost' => (float)$r['kg'] > 0 ? round((float)$r['cost'] / (float)$r['kg'], 4) : 0.0, 'remaining' => $r['remaining'] === null ? null : (float)$r['remaining']];
    $sumInCost = 0.0; $sumInKg = 0.0; foreach ($inputs as $r) { $sumInCost += $r['cost']; $sumInKg += $r['kg']; }
    $sumOutCost = 0.0; $sumOutKg = 0.0; foreach ($outputs as $r) { $sumOutCost += $r['cost']; $sumOutKg += $r['kg']; }
    $cap = (float)$s['labor_cost'] + (float)$s['transport_cost'];
    $row = pb_sep_row($s);
    $row['inputs'] = $inputs;
    $row['outputs'] = $outputs;
    $row['check'] = ['input_kg' => round($sumInKg, 3), 'output_kg' => round($sumOutKg, 3), 'waste_kg' => (float)$s['waste_kg'], 'mass_diff_kg' => round($sumInKg - $sumOutKg - (float)$s['waste_kg'], 3),
        'input_cost' => round($sumInCost, 2), 'capitalized' => round($cap, 2), 'abnormal_cost' => (float)$s['abnormal_cost'], 'output_cost' => round($sumOutCost, 2),
        'cost_diff' => round($sumOutCost + (float)$s['abnormal_cost'] - $sumInCost - $cap, 2)]; // must be 0.00
    $row['editable'] = pb_sep_outputs_used($pdo, $cid, $id) === null;
    return $row;
}

/** separations.list {from?, to?} */
function pb_sep_list(PDO $pdo, int $cid, array $p): array {
    $sql = 'SELECT s.*, (SELECT COUNT(*) FROM separation_outputs o WHERE o.customer_id=s.customer_id AND o.separation_id=s.id) AS n_out,
        (SELECT COUNT(*) FROM separation_inputs i WHERE i.customer_id=s.customer_id AND i.separation_id=s.id) AS n_in,
        (SELECT COALESCE(SUM(o.kg),0) FROM separation_outputs o WHERE o.customer_id=s.customer_id AND o.separation_id=s.id) AS out_kg
        FROM separations s WHERE s.customer_id=?';
    $args = [$cid];
    if (($p['from'] ?? '') !== '') { $sql .= ' AND s.sep_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND s.sep_date <= ?'; $args[] = vdate($p['to']); }
    $st = $pdo->prepare($sql . ' ORDER BY s.sep_date DESC, s.id DESC LIMIT 1000');
    $st->execute($args);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $x = pb_sep_row($r);
        $x['inputs_count'] = (int)$r['n_in'];
        $x['outputs_count'] = (int)$r['n_out'];
        $x['output_kg'] = (float)$r['out_kg'];
        $items[] = $x;
    }
    return ['items' => $items, 'settings' => pb_sep_settings($pdo, $cid)];
}

/** separations.lineage {lot_id, direction?: up|down|both (default both), max_depth?} — ancestors (what it was made of) / descendants (what was made of it); separations AND legacy mixes. */
function pb_sep_lineage(PDO $pdo, int $cid, array $p): array {
    $lot = (int)($p['lot_id'] ?? 0);
    if ($lot <= 0 && ($p['code'] ?? '') !== '') $lot = pb_sep_lot_by_code($pdo, $cid, (string)$p['code'])['lot_id'];
    $dir = (string)($p['direction'] ?? 'both');
    $max = max(1, min(10, (int)($p['max_depth'] ?? 6)));
    $info = $pdo->prepare('SELECT id, code, type_name, net_weight, remaining, is_mix FROM purchases WHERE id=? AND customer_id=?');
    $info->execute([$lot, $cid]);
    $self = $info->fetch();
    if (!$self) bad('لات یافت نشد', 404);
    $edges = function (bool $up) use ($pdo, $cid): callable {
        return function (int $id) use ($pdo, $cid, $up): array {
            $res = [];
            $col = $up ? 'child_lot_id' : 'parent_lot_id';
            $oth = $up ? 'parent_lot_id' : 'child_lot_id';
            $q = $pdo->prepare("SELECT $oth AS other, separation_id, parent_kg FROM lot_lineage WHERE customer_id=? AND $col=?");
            $q->execute([$cid, $id]);
            foreach ($q->fetchAll() as $r) $res[] = ['other' => (int)$r['other'], 'via' => 'separation', 'doc_id' => (int)$r['separation_id'], 'kg' => (float)$r['parent_kg']];
            $col2 = $up ? 'mix_purchase_id' : 'source_purchase_id';
            $oth2 = $up ? 'source_purchase_id' : 'mix_purchase_id';
            $m = $pdo->prepare("SELECT $oth2 AS other, mix_purchase_id, weight FROM mix_sources WHERE customer_id=? AND $col2=?");
            $m->execute([$cid, $id]);
            foreach ($m->fetchAll() as $r) $res[] = ['other' => (int)$r['other'], 'via' => 'mix', 'doc_id' => (int)$r['mix_purchase_id'], 'kg' => (float)$r['weight']];
            return $res;
        };
    };
    $walk = function (bool $up) use ($edges, $lot, $max): array {
        $next = $edges($up);
        $seen = [$lot => true];
        $level = [$lot];
        $out = [];
        for ($d = 1; $d <= $max && $level; $d++) {
            $new = [];
            foreach ($level as $id) {
                foreach ($next($id) as $e) {
                    $o = $e['other'];
                    $new[$o] = true;
                    $out[] = ['lot_id' => $o, 'from_lot_id' => $id, 'depth' => $d, 'via' => $e['via'], 'doc_id' => $e['doc_id'], 'kg' => $e['kg']];
                }
            }
            $level = [];
            foreach (array_keys($new) as $o) if (!isset($seen[$o])) { $seen[$o] = true; $level[] = $o; }
        }
        return $out;
    };
    $fill = function (array $nodes) use ($pdo, $cid): array {
        $q = $pdo->prepare('SELECT code, type_name, net_weight, remaining FROM purchases WHERE id=? AND customer_id=?');
        foreach ($nodes as &$n) {
            $q->execute([$n['lot_id'], $cid]);
            $r = $q->fetch();
            $n['code'] = $r ? $r['code'] : null;
            $n['type_name'] = $r ? $r['type_name'] : null;
            $n['net_weight'] = $r ? (float)$r['net_weight'] : null;
            $n['remaining'] = $r ? (float)$r['remaining'] : null;
        }
        return $nodes;
    };
    $res = ['lot' => ['lot_id' => (int)$self['id'], 'code' => $self['code'], 'type_name' => $self['type_name'], 'net_weight' => (float)$self['net_weight'],
        'remaining' => (float)$self['remaining'], 'kind' => ['purchase', 'mix', 'separation', 'consigned'][min(3, (int)$self['is_mix'])]]];
    $tree = function (array $nodes) use ($lot): array { // nested view: children keyed by the lot they were reached from
        $by = [];
        foreach ($nodes as $n) $by[$n['from_lot_id']][] = $n;
        $build = function (int $id, int $depth) use (&$build, $by): array {
            $o = [];
            foreach ($by[$id] ?? [] as $n) {
                if ($n['depth'] !== $depth + 1) continue;
                $n['children'] = $build((int)$n['lot_id'], $depth + 1);
                $o[] = $n;
            }
            return $o;
        };
        return $build($lot, 0);
    };
    if ($dir === 'up' || $dir === 'both') { $res['ancestors'] = $fill($walk(true)); $res['ancestors_tree'] = $tree($res['ancestors']); }
    if ($dir === 'down' || $dir === 'both') { $res['descendants'] = $fill($walk(false)); $res['descendants_tree'] = $tree($res['descendants']); }
    return $res;
}

/** Normalizes a scanned/typed tracking code (Persian digits, spaces, case). Hyphens are kept: K-KH00012-1. */
function pb_sep_norm_code(string $raw): string {
    $raw = strtr($raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    return preg_replace('/[^A-Z0-9\-]/', '', strtoupper(trim($raw))) ?? '';
}

/** lots.find_code {code} — scan lookup for KH.. / MX.. / K-.. codes (also tolerates a missing hyphen set: tries hyphen-less match). */
function pb_sep_lot_by_code(PDO $pdo, int $cid, string $raw): array {
    $c = pb_sep_norm_code($raw);
    if ($c === '') bad('کد نامعتبر است');
    $q = $pdo->prepare('SELECT id, code, type_name, net_weight, remaining, price, is_mix, date, warehouse_uid FROM purchases WHERE customer_id=? AND code=? LIMIT 1');
    $q->execute([$cid, $c]);
    $r = $q->fetch();
    if (!$r) {
        $q2 = $pdo->prepare("SELECT id, code, type_name, net_weight, remaining, price, is_mix, date, warehouse_uid FROM purchases WHERE customer_id=? AND REPLACE(code,'-','')=? LIMIT 1");
        $q2->execute([$cid, str_replace('-', '', $c)]);
        $r = $q2->fetch();
    }
    if (!$r) bad('لاتی با این کد یافت نشد', 404);
    return ['lot_id' => (int)$r['id'], 'code' => $r['code'], 'type_name' => $r['type_name'], 'net_weight' => (float)$r['net_weight'], 'remaining' => (float)$r['remaining'],
        'price' => (float)$r['price'], 'date' => $r['date'], 'warehouse_uid' => $r['warehouse_uid'], 'kind' => ['purchase', 'mix', 'separation', 'consigned'][min(3, (int)$r['is_mix'])]];
}

/**
 * reports.separation_yield {from?, to?, group?: job|supplier|type|ounce|output_type|period}
 * yield% = Σoutput kg / Σinput kg, waste% = waste / Σinput kg. Per supplier/type a job's outputs and waste are attributed to its inputs
 * pro rata to input kg (so Σ over groups = Σ over jobs). `ounce` groups OUTPUT kg by the output lot's ounce; `period` = Gregorian YYYY-MM (client shows Jalali).
 * `totals` always equals the sum of the jobs (register check: totals.input_kg = Σ separations.input_kg in range).
 */
function pb_sep_yield_report(PDO $pdo, int $cid, array $p): array {
    $group = (string)($p['group'] ?? 'job');
    if (!in_array($group, ['job', 'supplier', 'type', 'ounce', 'output_type', 'period'], true)) bad('نوع گروه‌بندی نامعتبر است');
    $sql = 'SELECT id, sep_date, input_kg, waste_kg, abnormal_kg, abnormal_cost, input_cost, labor_cost, transport_cost FROM separations WHERE customer_id=?';
    $args = [$cid];
    if (($p['from'] ?? '') !== '') { $sql .= ' AND sep_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND sep_date <= ?'; $args[] = vdate($p['to']); }
    $st = $pdo->prepare($sql . ' ORDER BY sep_date, id LIMIT 5000');
    $st->execute($args);
    $jobs = $st->fetchAll();
    $inQ = $pdo->prepare('SELECT i.kg, p.seller, p.type_name FROM separation_inputs i LEFT JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.customer_id=? AND i.separation_id=?');
    $outQ = $pdo->prepare('SELECT o.kg, o.type_name, o.cost, p.ounce FROM separation_outputs o LEFT JOIN purchases p ON p.id=o.lot_id AND p.customer_id=o.customer_id WHERE o.customer_id=? AND o.separation_id=?');
    $groups = [];
    $tot = ['jobs' => 0, 'input_kg' => 0.0, 'output_kg' => 0.0, 'waste_kg' => 0.0, 'abnormal_kg' => 0.0, 'abnormal_cost' => 0.0];
    $add = function (string $key, string $label, float $in, float $out, float $waste, float $ab) use (&$groups): void {
        if (!isset($groups[$key])) $groups[$key] = ['key' => $key, 'label' => $label, 'jobs' => 0, 'input_kg' => 0.0, 'output_kg' => 0.0, 'waste_kg' => 0.0, 'abnormal_kg' => 0.0];
        $g = &$groups[$key];
        $g['jobs']++; $g['input_kg'] += $in; $g['output_kg'] += $out; $g['waste_kg'] += $waste; $g['abnormal_kg'] += $ab;
    };
    foreach ($jobs as $j) {
        $sid = (int)$j['id'];
        $inKg = (float)$j['input_kg'];
        $waste = (float)$j['waste_kg'];
        $outQ->execute([$cid, $sid]);
        $outs = $outQ->fetchAll();
        $outKg = 0.0; foreach ($outs as $o) $outKg += (float)$o['kg'];
        $ab = (float)$j['abnormal_kg'];
        $tot['jobs']++; $tot['input_kg'] += $inKg; $tot['output_kg'] += $outKg; $tot['waste_kg'] += $waste; $tot['abnormal_kg'] += $ab; $tot['abnormal_cost'] += (float)$j['abnormal_cost'];
        if ($group === 'job') {
            $groups[(string)$sid] = ['key' => (string)$sid, 'label' => 'جداسازی ' . $sid, 'separation_id' => $sid, 'date' => $j['sep_date'], 'jobs' => 1, 'input_kg' => $inKg, 'output_kg' => $outKg,
                'waste_kg' => $waste, 'abnormal_kg' => $ab, 'labor_cost' => (float)$j['labor_cost'], 'transport_cost' => (float)$j['transport_cost']];
        } elseif ($group === 'period') {
            $add(substr((string)$j['sep_date'], 0, 7), substr((string)$j['sep_date'], 0, 7), $inKg, $outKg, $waste, $ab);
        } elseif ($group === 'supplier' || $group === 'type') {
            $inQ->execute([$cid, $sid]);
            foreach ($inQ->fetchAll() as $i) {
                $sh = $inKg > 0 ? (float)$i['kg'] / $inKg : 0.0;
                $lab = $group === 'supplier' ? (trim((string)($i['seller'] ?? '')) !== '' ? (string)$i['seller'] : '— بدون فروشنده —') : (string)($i['type_name'] ?? '—');
                $add($lab, $lab, (float)$i['kg'], $outKg * $sh, $waste * $sh, $ab * $sh);
            }
        } elseif ($group === 'output_type') {
            foreach ($outs as $o) $add((string)$o['type_name'], (string)$o['type_name'], 0.0, (float)$o['kg'], 0.0, 0.0);
        } else { // ounce (output side)
            foreach ($outs as $o) {
                $k = $o['ounce'] === null ? '—' : rtrim(rtrim(number_format((float)$o['ounce'], 3, '.', ''), '0'), '.');
                $add($k, $k === '—' ? 'بدون انس' : 'انس ' . $k, 0.0, (float)$o['kg'], 0.0, 0.0);
            }
        }
    }
    $rows = [];
    foreach ($groups as $g) {
        $base = $group === 'ounce' || $group === 'output_type' ? $tot['input_kg'] : $g['input_kg'];
        $g['yield_pct'] = $base > 0 ? round($g['output_kg'] / $base * 100, 2) : null;
        $g['waste_pct'] = ($group === 'ounce' || $group === 'output_type' || $g['input_kg'] <= 0) ? null : round($g['waste_kg'] / $g['input_kg'] * 100, 2);
        foreach (['input_kg', 'output_kg', 'waste_kg', 'abnormal_kg'] as $k) $g[$k] = round($g[$k], 3);
        $rows[] = $g;
    }
    usort($rows, function ($a, $b) use ($group) { return $group === 'job' ? ((int)$a['key'] <=> (int)$b['key']) : strcmp((string)$a['key'], (string)$b['key']); });
    $tot['yield_pct'] = $tot['input_kg'] > 0 ? round($tot['output_kg'] / $tot['input_kg'] * 100, 2) : null;
    $tot['waste_pct'] = $tot['input_kg'] > 0 ? round($tot['waste_kg'] / $tot['input_kg'] * 100, 2) : null;
    foreach (['input_kg', 'output_kg', 'waste_kg', 'abnormal_kg'] as $k) $tot[$k] = round($tot[$k], 3);
    $reg = $pdo->prepare('SELECT COALESCE(SUM(input_kg),0) FROM separations WHERE customer_id=?' . (($p['from'] ?? '') !== '' ? ' AND sep_date >= ?' : '') . (($p['to'] ?? '') !== '' ? ' AND sep_date <= ?' : ''));
    $reg->execute($args);
    return ['group' => $group, 'from' => $p['from'] ?? null, 'to' => $p['to'] ?? null, 'rows' => $rows, 'totals' => $tot, 'register_input_kg' => round((float)$reg->fetchColumn(), 3)];
}
