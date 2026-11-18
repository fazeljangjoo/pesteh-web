<?php
declare(strict_types=1);
/**
 * S16.T16.5b (orchard part) — posting loaders "C3" (GOLD): orchard costs (orchards.php).
 *  - orch_cost   orchard_costs.id at create: season cost is an expense until allocated (Dr orchard_cost 5205 / Cr cash, SIGNOFF: paid on the spot).
 *  - orch_alloc  orchard_allocations.id at allocate: the allocated part of the season pool is capitalized into the harvest lots
 *                (Dr inventory / Cr orchard_cost). The unallocated residual (market basis) stays in orchard_cost (SIGNOFF).
 * Harvest lots get no 'purchase' voucher (orchards never call pb_post_hook for them), so orch_alloc is their only inventory posting.
 * Requires posting_loaders_a.php (pb_pl_rial) and posting_loaders_c1.php (pb_plc_post / pb_plc_unpost).
 */

function pb_plc3_load_orch_cost(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM orchard_costs WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $c = $st->fetch();
    if (!$c) return null;
    return ['date' => (string)$c['cost_date'], 'description' => 'هزینهٔ باغ #' . $id . ' ' . (string)$c['category'],
        'fields' => ['amount' => pb_pl_rial($c['amount'] ?? 0)], 'dims' => []];
}

function pb_plc3_load_orch_alloc(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM orchard_allocations WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $a = $st->fetch();
    if (!$a) return null;
    return ['date' => substr((string)$a['created_at'], 0, 10), 'description' => 'تسهیم هزینهٔ باغ #' . $id . ' (' . (string)$a['season_year'] . ')',
        'fields' => ['alloc' => pb_pl_rial($a['allocated_cost'] ?? 0)], 'dims' => []];
}

pb_posting_register_loader('orch_cost', 'pb_plc3_load_orch_cost');
pb_posting_register_loader('orch_alloc', 'pb_plc3_load_orch_alloc');
