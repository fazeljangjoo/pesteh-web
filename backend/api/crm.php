<?php
/**
 * S03.T03.3 — CRM dashboard.
 *
 * crm.dashboard {days?: inactive threshold (default 60), top?: N (default 10), from?, to?, as_of?}
 *   -> {as_of, overdue_tasks[], today_tasks[], upcoming_tasks[], inactive_customers[], top_customers[], stages{}, profit_included}
 * Notes/tasks themselves are generic entities (crm_notes, crm_tasks) via entity.list/save/delete (perm crm.view / crm.edit).
 * Profit figures (top_customers[].profit, .margin_pct) are included ONLY when the caller has profit.view.
 * Profit here = revenue − Σ(sale_items.weight × purchases.price): a rough per-customer figure (no capitalized costs); flagged by profit_approx.
 */

function pb_crm_dashboard(PDO $pdo, int $cid, array $customer, array $p): void {
    $asOf = pb_party_opt_date($p['as_of'] ?? null, 'تاریخ گزارش') ?? today();
    $from = pb_party_opt_date($p['from'] ?? null, 'تاریخ شروع');
    $to = pb_party_opt_date($p['to'] ?? null, 'تاریخ پایان');
    $days = max(1, min(3650, (int)($p['days'] ?? 60)));
    $topN = max(1, min(100, (int)($p['top'] ?? 10)));
    $canProfit = pb_staff_can($customer, 'profit.view');
    pb_party_sync($pdo, $cid);

    // --- follow-up tasks (open ones only) ---
    $tasks = ['overdue_tasks' => [], 'today_tasks' => [], 'upcoming_tasks' => []];
    $weekEnd = date('Y-m-d', strtotime($asOf . ' 00:00:00 UTC') + 7 * 86400);
    $st = $pdo->prepare('SELECT uid, party, due_at, text, assignee_staff FROM ent_crm_tasks
        WHERE customer_id=? AND deleted_at IS NULL AND (done IS NULL OR done=0) AND due_at IS NOT NULL ORDER BY due_at ASC LIMIT 500');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        $d = substr((string)$r['due_at'], 0, 10);
        $row = ['uid' => $r['uid'], 'party' => $r['party'], 'due_at' => $r['due_at'], 'text' => $r['text'], 'assignee_staff' => $r['assignee_staff']];
        if ($d < $asOf) $tasks['overdue_tasks'][] = $row;
        elseif ($d === $asOf) $tasks['today_tasks'][] = $row;
        elseif ($d <= $weekEnd) $tasks['upcoming_tasks'][] = $row;
    }

    // --- party master (stage / active) ---
    $stages = ['lead' => 0, 'active' => 0, 'inactive' => 0, 'lost' => 0];
    $meta = [];
    $st = $pdo->prepare('SELECT name, stage, active, tags, phone FROM parties WHERE customer_id=?');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        $stage = in_array($r['stage'] ?? '', PB_PARTY_STAGES, true) ? $r['stage'] : 'active';
        $stages[$stage]++;
        $meta[partyKey($r['name'])] = ['stage' => $stage, 'active' => (int)$r['active'] === 1, 'tags' => pb_party_tags_decode($r['tags'] ?? null), 'phone' => $r['phone']];
    }

    // --- sales aggregates per buyer ---
    $agg = []; // key => [revenue, kg, count, last]
    $w = 'customer_id=?'; $args = [$cid];
    $st = $pdo->prepare("SELECT buyer, date, net_weight, price FROM sales WHERE $w");
    $st->execute($args);
    foreach ($st->fetchAll() as $r) {
        $k = partyKey($r['buyer']);
        if ($k === '') continue;
        if (!isset($agg[$k])) $agg[$k] = ['revenue' => 0.0, 'kg' => 0.0, 'count' => 0, 'last' => '', 'cost' => 0.0, 'p_revenue' => 0.0, 'p_kg' => 0.0, 'p_count' => 0];
        $d = (string)$r['date'];
        if ($d > $agg[$k]['last']) $agg[$k]['last'] = $d;
        $rev = (float)$r['net_weight'] * (float)$r['price'];
        if (($from === null || $d >= $from) && ($to === null || $d <= $to)) {
            $agg[$k]['p_revenue'] += $rev; $agg[$k]['p_kg'] += (float)$r['net_weight']; $agg[$k]['p_count']++;
        }
    }
    if ($canProfit) {
        $st = $pdo->prepare('SELECT s.buyer AS buyer, s.date AS date, SUM(si.weight * ' . PB_BROKER_COST_SQL . ') + COALESCE(MAX(CASE WHEN s.broker_payer=\'us\' THEN s.broker_amount END),0) AS cost FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.customer_id = si.customer_id
            JOIN purchases p ON p.id = si.purchase_id AND p.customer_id = si.customer_id
            WHERE si.customer_id = ? GROUP BY s.id, s.buyer, s.date');
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) {
            $k = partyKey($r['buyer']);
            $d = (string)$r['date'];
            if (!isset($agg[$k])) continue;
            if (($from === null || $d >= $from) && ($to === null || $d <= $to)) $agg[$k]['cost'] += (float)$r['cost'];
        }
    }

    // --- inactive customers: had sales, none since (as_of - days); lost / disabled parties are skipped ---
    $limit = date('Y-m-d', strtotime($asOf . ' 00:00:00 UTC') - $days * 86400);
    $inactive = [];
    foreach ($agg as $k => $a) {
        $m = $meta[$k] ?? ['stage' => 'active', 'active' => true, 'tags' => [], 'phone' => null];
        if ($m['stage'] === 'lost' || !$m['active']) continue;
        if ($a['last'] !== '' && $a['last'] < $limit) {
            $inactive[] = ['party' => $k, 'last_sale' => $a['last'],
                'days_since' => (int)floor((strtotime($asOf . ' 00:00:00 UTC') - strtotime($a['last'] . ' 00:00:00 UTC')) / 86400),
                'stage' => $m['stage'], 'phone' => $m['phone']];
        }
    }
    usort($inactive, fn($x, $y) => strcmp($x['last_sale'], $y['last_sale']));

    // --- top customers by revenue in the period ---
    $top = [];
    foreach ($agg as $k => $a) {
        if ($a['p_count'] === 0) continue;
        $row = ['party' => $k, 'revenue' => $a['p_revenue'], 'kg' => $a['p_kg'], 'sales_count' => $a['p_count'],
            'stage' => ($meta[$k]['stage'] ?? 'active'), 'tags' => ($meta[$k]['tags'] ?? [])];
        if ($canProfit) {
            $row['profit'] = $a['p_revenue'] - $a['cost'];
            $row['margin_pct'] = $a['p_revenue'] > 0 ? round(($a['p_revenue'] - $a['cost']) * 100 / $a['p_revenue'], 1) : 0.0;
        }
        $top[] = $row;
    }
    usort($top, fn($x, $y) => $y['revenue'] <=> $x['revenue']);
    $top = array_slice($top, 0, $topN);

    $res = $tasks + ['as_of' => $asOf, 'inactive_days' => $days, 'inactive_customers' => $inactive, 'top_customers' => $top,
        'stages' => $stages, 'profit_included' => $canProfit];
    if ($canProfit) $res['profit_approx'] = true;
    out($res);
}
