<?php
declare(strict_types=1);
/**
 * S31.T31.13 — KPI dashboard (read-only, all editions). Action: kpi.dashboard {from?, to?}  (dates YYYY-MM-DD; default = last 30 days)
 * Returns: sales{revenue,kg,count,prev_revenue,change_pct} (revenue = kg x unit price minus sales returns, stored units = toman),
 *   purchases{total,kg,count}, inventory{kg,value_est,lots} (value_est = remaining kg x purchase price: an ESTIMATE, excludes capitalized costs),
 *   receivables{overdue_sum,overdue_count,oldest_due,due_7d_sum}, payables{...same}, profit|null.
 * profit = gross margin (gold tier + profit.view only; from reports.margin totals converted to stored units); null otherwise. Server enforces profit.view.
 * Receivables/payables = unpaid credit + cheque installments (lump payments are settled by definition).
 */
function pb_kpi_sum(PDO $pdo, string $sql, array $a): array {
    $st = $pdo->prepare($sql);
    $st->execute($a);
    return $st->fetch() ?: [];
}

function pb_kpi_revenue(PDO $pdo, int $cid, string $from, string $to): array {
    $r = pb_kpi_sum($pdo, 'SELECT COALESCE(SUM(i.weight * COALESCE(i.item_price, s.price)),0) AS rev, COALESCE(SUM(i.weight),0) AS kg, COUNT(DISTINCT s.id) AS n
        FROM sale_items i JOIN sales s ON s.id=i.sale_id AND s.customer_id=i.customer_id WHERE i.customer_id=? AND s.date BETWEEN ? AND ?', [$cid, $from, $to]);
    $ret = ['rv' => 0, 'kg' => 0];
    try {
        $ret = pb_kpi_sum($pdo, 'SELECT COALESCE(SUM(i.kg * i.unit_price),0) AS rv, COALESCE(SUM(i.kg),0) AS kg FROM sale_return_items i
            JOIN sale_returns r ON r.id=i.return_id AND r.customer_id=i.customer_id WHERE i.customer_id=? AND r.date BETWEEN ? AND ?', [$cid, $from, $to]);
    } catch (Throwable $e) { /* returns table missing on very old installs */ }
    return ['revenue' => (float)$r['rev'] - (float)$ret['rv'], 'kg' => (float)$r['kg'] - (float)$ret['kg'], 'count' => (int)$r['n']];
}

/** Unpaid installments of $tables (each [table]) — overdue before $today, plus due within the next 7 days. */
function pb_kpi_dues(PDO $pdo, int $cid, array $tables, string $today): array {
    $in7 = date('Y-m-d', strtotime($today . ' +7 days'));
    $over = 0.0; $n = 0; $oldest = null; $soon = 0.0;
    $creditMap = [];
    try { $creditMap = pb_dues_credit_map($pdo, $cid); } catch (Throwable $e) { /* v71: return credit, same adjustment as the dues screen */ }
    foreach ($tables as $t) {
        $st = $pdo->prepare("SELECT id, amount, due_date FROM $t WHERE customer_id=? AND paid=0 AND due_date <= ?");
        $st->execute([$cid, $in7]);
        foreach ($st->fetchAll() as $r) {
            $amt = (float)$r['amount'] - (float)($creditMap[$t . '#' . (int)$r['id']] ?? 0.0);
            if ($amt < 0.5) continue;
            $d = (string)$r['due_date'];
            if ($d < $today) { $over += $amt; $n++; if ($oldest === null || $d < $oldest) $oldest = $d; }
            else $soon += $amt;
        }
    }
    return ['overdue_sum' => round($over, 2), 'overdue_count' => $n, 'oldest_due' => $oldest, 'due_7d_sum' => round($soon, 2)];
}

function pb_kpi_dashboard(PDO $pdo, int $cid, array $customer, array $p): array {
    $today = today();
    $to = !empty($p['to']) ? vdate($p['to']) : $today;
    $from = !empty($p['from']) ? vdate($p['from']) : date('Y-m-d', strtotime($to . ' -29 days'));
    if ($from > $to) bad('بازهٔ تاریخ نامعتبر است');
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $pTo = date('Y-m-d', strtotime($from . ' -1 day'));
    $pFrom = date('Y-m-d', strtotime($pTo . ' -' . ($days - 1) . ' days'));

    $cur = pb_kpi_revenue($pdo, $cid, $from, $to);
    $prev = pb_kpi_revenue($pdo, $cid, $pFrom, $pTo);
    $sales = ['revenue' => round($cur['revenue'], 2), 'kg' => round($cur['kg'], 3), 'count' => $cur['count'], 'prev_revenue' => round($prev['revenue'], 2),
        'change_pct' => $prev['revenue'] > 0 ? round(($cur['revenue'] - $prev['revenue']) * 100 / $prev['revenue'], 1) : null];

    $b = pb_kpi_sum($pdo, 'SELECT COALESCE(SUM(price * net_weight),0) AS t, COALESCE(SUM(net_weight),0) AS kg, COUNT(*) AS n FROM purchases WHERE customer_id=? AND date BETWEEN ? AND ? AND is_mix=0', [$cid, $from, $to]);
    $purchases = ['total' => round((float)$b['t'], 2), 'kg' => round((float)$b['kg'], 3), 'count' => (int)$b['n']];

    $inv = pb_kpi_sum($pdo, 'SELECT COALESCE(SUM(remaining),0) AS kg, COALESCE(SUM(remaining * price),0) AS v, COUNT(*) AS n FROM purchases WHERE customer_id=? AND remaining > 0', [$cid]);
    $inventory = ['kg' => round((float)$inv['kg'], 3), 'value_est' => round((float)$inv['v'], 2), 'lots' => (int)$inv['n'], 'estimate' => true];

    $profit = null;
    if (pb_tier_of($customer) === 'gold' && pb_staff_can($customer, 'profit.view')) {
        try {
            $fy = pb_kpi_sum($pdo, 'SELECT 1 AS x FROM fiscal_years WHERE customer_id=? LIMIT 1', [$cid]);
            if ($fy) {
                require_once __DIR__ . '/margin.php';
                $m = pb_mg_report($pdo, $cid, $customer, ['group' => 'type', 'from' => $from, 'to' => $to]);
                $mult = max(1, (int)pb_posting_unit_mult());
                $profit = ['gross' => round($m['totals']['margin'] / $mult, 2), 'margin_pct' => $m['totals']['margin_pct']];
            }
        } catch (Throwable $e) { $profit = null; }
    }
    return ['from' => $from, 'to' => $to, 'today' => $today, 'unit' => 'stored', 'sales' => $sales, 'purchases' => $purchases, 'inventory' => $inventory,
        'receivables' => pb_kpi_dues($pdo, $cid, ['receipt_credit_inst', 'receipt_cheque_inst'], $today),
        'payables' => pb_kpi_dues($pdo, $cid, ['credit_inst', 'cheque_inst'], $today), 'profit' => $profit];
}

function pb_kpi_action(string $action, array $p, PDO $pdo, array $customer): void {
    out(pb_kpi_dashboard($pdo, (int)$customer['id'], $customer, $p));
}
