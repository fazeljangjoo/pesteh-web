<?php
declare(strict_types=1);
/**
 * S31.T31.7 — lot tracing orchard → sale, and orchard flow report (yield, waste, sold, unsold). Both tiers, read-only, no new tables.
 * Origin model: harvests.lot_id marks a lot as 100% from (orchard, season). A separation spreads its input lots over ALL its output lots
 * pro rata by input kg (child share = Σ parent share × input kg / total input kg); a mix does the same by mix_sources.weight.
 * So every lot carries an origin vector {orchard|season => fraction}; sold / remaining / waste are apportioned by that fraction.
 * Waste of a separation (waste_kg, abnormal_kg) is apportioned to origins by their input-kg share of that separation.
 * NOT traced: processing orders and consignments (their lots keep no orchard origin).
 * Actions: lot_trace.get {lot_id|code, max_depth?}  |  reports.orchard_flow {orchard_id?, season_year?}  (revenue only with profit.view)
 */

/** Loads the whole lineage graph of a tenant (small business scale) into memory. */
function pb_lt_graph(PDO $pdo, int $cid): array {
    $g = ['harv' => [], 'sepIn' => [], 'sepOut' => [], 'sep' => [], 'mix' => [], 'lot' => [], 'orch' => []];
    $q = $pdo->prepare('SELECT h.lot_id, h.orchard_id, h.season_year, o.name FROM harvests h JOIN orchards o ON o.id=h.orchard_id AND o.customer_id=h.customer_id WHERE h.customer_id=? AND h.lot_id IS NOT NULL');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) { $k = (int)$r['orchard_id'] . '|' . (int)$r['season_year']; $g['harv'][(int)$r['lot_id']] = $k; $g['orch'][$k] = ['orchard_id' => (int)$r['orchard_id'], 'orchard' => (string)$r['name'], 'season_year' => (int)$r['season_year']]; }
    $q = $pdo->prepare('SELECT id, input_kg, waste_kg, abnormal_kg FROM separations WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) $g['sep'][(int)$r['id']] = ['input_kg' => (float)$r['input_kg'], 'waste_kg' => (float)$r['waste_kg'], 'abnormal_kg' => (float)$r['abnormal_kg']];
    $q = $pdo->prepare('SELECT separation_id, lot_id, kg FROM separation_inputs WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) $g['sepIn'][(int)$r['separation_id']][] = [(int)$r['lot_id'], (float)$r['kg']];
    $q = $pdo->prepare('SELECT separation_id, lot_id FROM separation_outputs WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) $g['sepOut'][(int)$r['separation_id']][] = (int)$r['lot_id'];
    $q = $pdo->prepare('SELECT mix_purchase_id, source_purchase_id, weight FROM mix_sources WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) $g['mix'][(int)$r['mix_purchase_id']][] = [(int)$r['source_purchase_id'], (float)$r['weight']];
    $q = $pdo->prepare('SELECT id, code, type_name, date, net_weight, remaining FROM purchases WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) $g['lot'][(int)$r['id']] = $r;
    // reverse index: output lot -> separation
    $g['outSep'] = [];
    foreach ($g['sepOut'] as $sid => $lots) foreach ($lots as $l) $g['outSep'][$l][] = $sid;
    return $g;
}

/** Origin vector of a lot: ['orchard|season' => fraction 0..1]. Memoized; cycle-safe. */
function pb_lt_vec(array &$g, int $lot, array &$memo = [], array $path = []): array {
    if (isset($memo[$lot])) return $memo[$lot];
    if (isset($path[$lot])) return [];
    $path[$lot] = true;
    $vec = [];
    if (isset($g['harv'][$lot])) {
        $vec[$g['harv'][$lot]] = 1.0;
    } else {
        $parts = [];
        foreach ($g['outSep'][$lot] ?? [] as $sid) foreach ($g['sepIn'][$sid] ?? [] as [$p, $kg]) $parts[] = [$p, $kg];
        if (!$parts && isset($g['mix'][$lot])) foreach ($g['mix'][$lot] as [$p, $w]) $parts[] = [$p, $w];
        $tot = 0.0; foreach ($parts as $x) $tot += $x[1];
        if ($tot > 0) {
            foreach ($parts as [$p, $w]) foreach (pb_lt_vec($g, $p, $memo, $path) as $k => $f) $vec[$k] = ($vec[$k] ?? 0.0) + $f * $w / $tot;
        }
    }
    return $memo[$lot] = $vec;
}

function pb_lt_sales(PDO $pdo, int $cid): array {
    $q = $pdo->prepare('SELECT si.purchase_id, si.weight, COALESCE(si.item_price, s.price) AS unit, s.id AS sale_id, s.date, s.buyer
        FROM sale_items si JOIN sales s ON s.id=si.sale_id AND s.customer_id=si.customer_id WHERE si.customer_id=?');
    $q->execute([$cid]);
    $by = [];
    foreach ($q->fetchAll() as $r) $by[(int)$r['purchase_id']][] = ['sale_id' => (int)$r['sale_id'], 'date' => $r['date'], 'buyer' => $r['buyer'], 'kg' => (float)$r['weight'], 'unit' => (float)$r['unit']];
    return $by;
}

function pb_lt_flow(PDO $pdo, int $cid, array $p, array $customer): array {
    $canProfit = pb_staff_can($customer, 'profit.view');
    $g = pb_lt_graph($pdo, $cid);
    $sales = pb_lt_sales($pdo, $cid);
    $memo = [];
    $fo = !empty($p['orchard_id']) ? (int)$p['orchard_id'] : 0;
    $fs = !empty($p['season_year']) ? (int)$p['season_year'] : 0;
    $rows = [];
    $row = static function (string $k) use (&$rows, $g): void {
        if (!isset($rows[$k])) $rows[$k] = $g['orch'][$k] + ['harvest_kg' => 0.0, 'to_separation_kg' => 0.0, 'waste_kg' => 0.0, 'abnormal_kg' => 0.0, 'sold_kg' => 0.0, 'remaining_kg' => 0.0, 'revenue' => 0.0];
    };
    $hq = $pdo->prepare('SELECT orchard_id, season_year, SUM(dry_kg) AS kg FROM harvests WHERE customer_id=? GROUP BY orchard_id, season_year');
    $hq->execute([$cid]);
    foreach ($hq->fetchAll() as $h) {
        $k = (int)$h['orchard_id'] . '|' . (int)$h['season_year'];
        if (!isset($g['orch'][$k])) continue; // harvest without a lot: no flow
        if (($fo && (int)$h['orchard_id'] !== $fo) || ($fs && (int)$h['season_year'] !== $fs)) continue;
        $row($k); $rows[$k]['harvest_kg'] = (float)$h['kg'];
    }
    foreach ($g['lot'] as $id => $lot) {
        $vec = pb_lt_vec($g, (int)$id, $memo);
        foreach ($vec as $k => $f) {
            if (!isset($rows[$k])) continue;
            $rows[$k]['remaining_kg'] += $f * max(0.0, (float)$lot['remaining']);
            foreach ($sales[(int)$id] ?? [] as $s) { $rows[$k]['sold_kg'] += $f * $s['kg']; $rows[$k]['revenue'] += $f * $s['kg'] * $s['unit']; }
        }
    }
    foreach ($g['sep'] as $sid => $s) {
        $in = 0.0; foreach ($g['sepIn'][$sid] ?? [] as [, $kg]) $in += $kg;
        if ($in <= 0) continue;
        foreach ($g['sepIn'][$sid] ?? [] as [$lot, $kg]) foreach (pb_lt_vec($g, $lot, $memo) as $k => $f) {
            if (!isset($rows[$k])) continue;
            $part = $f * $kg;
            $rows[$k]['to_separation_kg'] += $part;
            $rows[$k]['waste_kg'] += $s['waste_kg'] * $part / $in;
            $rows[$k]['abnormal_kg'] += $s['abnormal_kg'] * $part / $in;
        }
    }
    $items = [];
    foreach ($rows as $r) {
        $h = $r['harvest_kg'];
        $it = ['orchard_id' => $r['orchard_id'], 'orchard' => $r['orchard'], 'season_year' => $r['season_year'], 'harvest_kg' => round($h, 3),
            'to_separation_kg' => round($r['to_separation_kg'], 3), 'waste_kg' => round($r['waste_kg'], 3), 'abnormal_waste_kg' => round($r['abnormal_kg'], 3),
            'waste_pct_of_separated' => $r['to_separation_kg'] > 0 ? round($r['waste_kg'] / $r['to_separation_kg'] * 100, 2) : null,
            'sold_kg' => round($r['sold_kg'], 3), 'remaining_kg' => round($r['remaining_kg'], 3),
            'unaccounted_kg' => round($h - $r['sold_kg'] - $r['remaining_kg'] - $r['waste_kg'], 3),
            'net_yield_pct' => $h > 0 ? round(($h - $r['waste_kg']) / $h * 100, 2) : null];
        if ($canProfit) $it['revenue'] = round($r['revenue'], 2);
        $items[] = $it;
    }
    usort($items, static fn($a, $b) => [$a['orchard'], $a['season_year']] <=> [$b['orchard'], $b['season_year']]);
    return ['items' => $items, 'can_profit' => $canProfit, 'note' => 'سهم هر باغ در لات‌های جداسازی/مخلوط به نسبت وزن ورودی محاسبه می‌شود؛ فرآوری و امانی ردیابی نمی‌شوند'];
}

function pb_lt_trace(PDO $pdo, int $cid, array $p): array {
    $lot = (int)($p['lot_id'] ?? 0);
    if ($lot <= 0 && trim((string)($p['code'] ?? '')) !== '') $lot = pb_sep_lot_by_code($pdo, $cid, (string)$p['code'])['lot_id'];
    $g = pb_lt_graph($pdo, $cid);
    if (!isset($g['lot'][$lot])) bad('لات یافت نشد', 404);
    $sales = pb_lt_sales($pdo, $cid);
    $max = max(1, min(10, (int)($p['max_depth'] ?? 6)));
    $memo = [];
    $node = static function (int $id) use ($g, $sales, &$memo): array {
        $l = $g['lot'][$id] ?? ['code' => null, 'type_name' => '', 'date' => null, 'net_weight' => 0, 'remaining' => 0];
        $sold = 0.0; $list = [];
        foreach ($sales[$id] ?? [] as $s) { $sold += $s['kg']; $list[] = ['sale_id' => $s['sale_id'], 'date' => $s['date'], 'buyer' => $s['buyer'], 'kg' => $s['kg']]; }
        $origin = [];
        foreach (pb_lt_vec($g, $id, $memo) as $k => $f) $origin[] = $g['orch'][$k] + ['fraction' => round($f, 4)];
        return ['lot_id' => $id, 'code' => $l['code'], 'type_name' => (string)$l['type_name'], 'date' => $l['date'], 'kg' => (float)$l['net_weight'],
            'remaining' => (float)$l['remaining'], 'sold_kg' => round($sold, 3), 'sales' => $list, 'origin' => $origin];
    };
    // upstream: ancestors with the operation that produced the lot
    $up = []; $seen = [$lot => true]; $level = [$lot];
    for ($d = 1; $d <= $max && $level; $d++) {
        $next = [];
        foreach ($level as $id) {
            $edges = [];
            foreach ($g['outSep'][$id] ?? [] as $sid) foreach ($g['sepIn'][$sid] ?? [] as [$par, $kg]) $edges[] = ['separation', $sid, $par, $kg];
            foreach ($g['mix'][$id] ?? [] as [$par, $w]) $edges[] = ['mix', $id, $par, $w];
            foreach ($edges as [$via, $doc, $par, $kg]) {
                $up[] = ['depth' => $d, 'via' => $via, 'doc_id' => $doc, 'to_lot_id' => $id, 'kg' => $kg] + $node($par);
                if (!isset($seen[$par])) { $seen[$par] = true; $next[] = $par; }
            }
        }
        $level = $next;
    }
    // downstream: descendants
    $kids = [];
    foreach ($g['sepIn'] as $sid => $ins) foreach ($ins as [$par]) foreach ($g['sepOut'][$sid] ?? [] as $c) $kids[$par][] = ['separation', $sid, $c];
    foreach ($g['mix'] as $mid => $srcs) foreach ($srcs as [$par]) $kids[$par][] = ['mix', $mid, $mid];
    $down = []; $seen = [$lot => true]; $level = [$lot];
    for ($d = 1; $d <= $max && $level; $d++) {
        $next = [];
        foreach ($level as $id) foreach ($kids[$id] ?? [] as [$via, $doc, $c]) {
            $down[] = ['depth' => $d, 'via' => $via, 'doc_id' => $doc, 'from_lot_id' => $id] + $node($c);
            if (!isset($seen[$c])) { $seen[$c] = true; $next[] = $c; }
        }
        $level = $next;
    }
    // یک لات ممکن است از چند مسیر (مثلاً مخلوطِ دو خروجیِ یک جداسازی) چند بار در downstream بیاید؛ فروشش فقط یک بار شمرده شود
    $totalSold = 0.0; $counted = [];
    foreach (array_merge([$node($lot)], $down) as $n) {
        if (isset($counted[$n['lot_id']])) continue;
        $counted[$n['lot_id']] = true;
        $totalSold += $n['sold_kg'];
    }
    return ['lot' => $node($lot), 'upstream' => $up, 'downstream' => $down, 'descendant_sold_kg_total' => round($totalSold, 3)];
}

function pb_lt_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    if ($action === 'lot_trace.get') out(pb_lt_trace($pdo, $cid, $p));
    out(pb_lt_flow($pdo, $cid, $p, $customer));
}
