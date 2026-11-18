<?php
declare(strict_types=1);
/**
 * S18.T18.3b — gross margin (GOLD, read-only). Action: reports.margin {group: lot|type|ounce, fiscal_year_id?, from?, to?}
 * Revenue = (item_price ?? sale price) x kg of each sale item minus sales-return value; COGS = ORIGINAL lot cost of the kg (pb_pl_lot_value, A8).
 * Consigned lots (B7) are skipped. Amounts: integer rial (stored toman x pb_posting_unit_mult()). Computed from documents (not vouchers).
 */
require_once __DIR__ . '/statements.php';
require_once __DIR__ . '/posting.php';
require_once __DIR__ . '/crack_pool.php'; // CP2

function pb_mg_report(PDO $pdo, int $cid, array $customer, array $p): array {
    $fy = pb_st_fy($pdo, $cid, $customer, $p);
    $from = !empty($p['from']) ? (string)$p['from'] : (string)$fy['start_date'];
    $to = !empty($p['to']) ? (string)$p['to'] : (string)$fy['end_date'];
    $group = in_array((string)($p['group'] ?? 'type'), ['lot', 'type', 'ounce'], true) ? (string)$p['group'] : 'type';
    $rows = [];
    $add = static function (array $lot, float $kg, int $rev, int $cost, int $sign) use (&$rows, $group): void {
        $key = $group === 'lot' ? (string)$lot['id'] : ($group === 'type' ? (string)$lot['type_name'] : ($lot['ounce'] === null ? '-' : rtrim(rtrim((string)$lot['ounce'], '0'), '.')));
        $label = $group === 'lot' ? trim((string)($lot['code'] ?? '') . ' ' . (string)$lot['type_name']) : ($group === 'type' ? (string)$lot['type_name'] : ($key === '-' ? 'بدون اونس' : 'اونس ' . $key));
        if (!isset($rows[$key])) $rows[$key] = ['key' => $key, 'label' => $label, 'kg' => 0.0, 'revenue' => 0, 'cogs' => 0];
        $rows[$key]['kg'] += $sign * $kg;
        $rows[$key]['revenue'] += $sign * $rev;
        $rows[$key]['cogs'] += $sign * $cost;
    };
    $m = pb_posting_unit_mult();
    $cl = pb_cp_lot_costs($pdo, $cid); // CP2/D7: لات‌های استخر خط شکست ⇒ COGS از سهم‌بندی (نه بهای ۰ لات)
    $q = $pdo->prepare('SELECT i.purchase_id, i.weight, COALESCE(i.item_price, s.price) AS pr, p.id, p.code, p.type_name, p.ounce, p.is_mix FROM sale_items i JOIN sales s ON s.id=i.sale_id AND s.customer_id=i.customer_id JOIN purchases p ON p.id=i.purchase_id AND p.customer_id=i.customer_id WHERE i.customer_id=? AND s.date BETWEEN ? AND ?');
    $q->execute([$cid, $from, $to]);
    foreach ($q->fetchAll() as $r) {
        if (isset($cl[(int)$r['purchase_id']])) continue; // CP2: پایین‌تر
        $kg = (float)$r['weight'];
        $v = pb_pl_lot_value($pdo, $cid, (int)$r['purchase_id'], $kg);
        if ($v === null) continue;
        $add($r, $kg, (int)round((float)$r['pr'] * $kg * $m), (int)$v[0], 1);
    }
    $q = $pdo->prepare('SELECT i.kg, i.unit_price, p.id, p.code, p.type_name, p.ounce, p.is_mix, i.lot_id FROM sale_return_items i JOIN sale_returns r ON r.id=i.return_id AND r.customer_id=i.customer_id JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.customer_id=? AND r.date BETWEEN ? AND ?');
    $q->execute([$cid, $from, $to]);
    foreach ($q->fetchAll() as $r) {
        if (isset($cl[(int)$r['lot_id']])) continue; // CP2: پایین‌تر
        $kg = (float)$r['kg'];
        $v = pb_pl_lot_value($pdo, $cid, (int)$r['lot_id'], $kg);
        if ($v === null) continue;
        $add($r, $kg, (int)round((float)$r['unit_price'] * $kg * $m), (int)$v[0], -1);
    }
    if ($cl) { // CP2: COGS هر ردیف = C_lot × (درآمد ردیف ÷ درآمد کل لات)، تجمعی؛ جمع همهٔ زمان‌ها = C_lot. فقط ردیف‌های داخل بازه گزارش می‌شوند.
        $byLot = [];
        foreach (pb_cp_lot_rows($pdo, $cid, array_keys($cl)) as $x) $byLot[$x['lot']][] = $x;
        foreach ($byLot as $lotId => $xs) {
            $signed = [];
            foreach ($xs as $x) $signed[] = ($x['k'] === 0 ? 1 : -1) * $x['rev'];
            $costs = pb_cp_row_costs($cl[$lotId]['C'], $cl[$lotId]['R'], $signed);
            foreach ($xs as $i => $x) {
                if ($x['date'] < $from || $x['date'] > $to) continue;
                $sg = $x['k'] === 0 ? 1 : -1;
                $add($x['info'], $x['kg'], $x['rev'], $costs[$i] * $sg, $sg);
            }
        }
    }
    $out = [];
    $tk = 0.0;
    $tr = $tc = 0;
    foreach ($rows as $r) {
        $r['kg'] = round((float)$r['kg'], 3);
        $r['margin'] = $r['revenue'] - $r['cogs'];
        $r['margin_pct'] = $r['revenue'] > 0 ? round($r['margin'] * 100 / $r['revenue'], 1) : null;
        $r['per_kg'] = $r['kg'] > 0 ? (int)round($r['margin'] / $r['kg']) : null;
        $tk += $r['kg'];
        $tr += $r['revenue'];
        $tc += $r['cogs'];
        $out[] = $r;
    }
    usort($out, static function ($a, $b) { return $b['margin'] <=> $a['margin']; });
    return ['fiscal_year' => ['id' => (int)$fy['id'], 'name' => (string)$fy['name']], 'from' => $from, 'to' => $to, 'unit' => 'rial', 'group' => $group, 'rows' => $out,
        'totals' => ['kg' => round($tk, 3), 'revenue' => $tr, 'cogs' => $tc, 'margin' => $tr - $tc, 'margin_pct' => $tr > 0 ? round(($tr - $tc) * 100 / $tr, 1) : null]];
}
