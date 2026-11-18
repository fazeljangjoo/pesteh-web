<?php
declare(strict_types=1);
/**
 * S18.T18.2 — trial balance (تراز آزمایشی, GOLD, read-only). Action: reports.trial (perm accounting.view)
 *   {level: 1|2|3|tafsili (default 2), columns: 2|4|6 (default 6), fiscal_year_id?, from?, to?, status? (see ledger_reports.php), hide_zero? (default 1),
 *    account_id? (restrict to a subtree), tafsili_id?, cost_center_id?, csv?}
 *   columns=2: closing balance (debit/credit) · 4: period turnover + closing balance · 6: opening balance + period turnover + closing balance.
 *   level 1/2/3 roll leaf accounts up to the ancestor at that level (a leaf above that level is shown as itself); level=tafsili splits leaf accounts by tafsili.
 *   Opening = lines before `from` in the same scope (same rule as the ledger => ledger closing == trial closing for the same account).
 *   Result: rows[], totals{col: sum}, check{opening,period,closing: bool} (every column pair must balance), balanced.
 * Amounts are integer rial.
 */
require_once __DIR__ . '/ledger_reports.php';

function pb_tb_rows(PDO $pdo, int $cid, array $customer, array $p, bool $opening, ?array $accIds, bool $byTaf): array {
    [$vw, $va] = pb_lr_vconds($cid, $customer, $p, !$opening);
    if ($opening) { $vw[] = 'v.voucher_date<?'; $va[] = pb_voucher_date($p['from']); }
    [$lw, $la] = pb_lr_lconds($p, $accIds, null);
    $cols = 'l.account_id' . ($byTaf ? ', l.tafsili_id' : '');
    $st = $pdo->prepare("SELECT $cols, COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id WHERE "
        . implode(' AND ', array_merge($vw, $lw)) . " GROUP BY $cols");
    $st->execute(array_merge($va, $la));
    $m = [];
    foreach ($st->fetchAll() as $r) $m[(int)$r['account_id'] . ':' . ($byTaf ? (int)($r['tafsili_id'] ?? 0) : 0)] = [(int)$r['d'], (int)$r['c']];
    return $m;
}

function pb_tb_report(PDO $pdo, int $cid, array $customer, array $p): array {
    $lv = (string)($p['level'] ?? '2');
    if (!in_array($lv, ['1', '2', '3', 'tafsili'], true)) bad('سطح نامعتبر است');
    $cols = (int)($p['columns'] ?? 6);
    if (!in_array($cols, [2, 4, 6], true)) bad('تعداد ستون نامعتبر است');
    $byTaf = $lv === 'tafsili';
    $level = $byTaf ? 99 : (int)$lv;
    $hide = !isset($p['hide_zero']) || !empty($p['hide_zero']);

    $st = $pdo->prepare('SELECT id, code, name, level, parent_id FROM accounts WHERE customer_id=?');
    $st->execute([$cid]);
    $by = [];
    foreach ($st->fetchAll() as $r) $by[(int)$r['id']] = $r;
    $accIds = !empty($p['account_id']) ? pb_lr_subtree($pdo, $cid, (int)$p['account_id'])['ids'] : null;

    $hasFrom = !empty($p['from']);
    $op = $hasFrom ? pb_tb_rows($pdo, $cid, $customer, $p, true, $accIds, $byTaf) : [];
    $pe = pb_tb_rows($pdo, $cid, $customer, $p, false, $accIds, $byTaf);
    $tafNames = [];
    if ($byTaf) {
        $q = $pdo->prepare('SELECT id, code, name FROM tafsili WHERE customer_id=?');
        $q->execute([$cid]);
        foreach ($q->fetchAll() as $t) $tafNames[(int)$t['id']] = $t;
    }
    // roll up: key = target account id (+ tafsili)
    $agg = [];
    foreach ([[$op, 0], [$pe, 1]] as [$set, $k]) {
        foreach ($set as $key => $dc) {
            [$aid, $tid] = array_map('intval', explode(':', $key));
            $x = $aid;
            while (isset($by[$x]) && (int)$by[$x]['level'] > $level && $by[$x]['parent_id'] !== null && isset($by[(int)$by[$x]['parent_id']])) $x = (int)$by[$x]['parent_id'];
            if (!isset($by[$x])) continue;
            $kk = $x . ':' . ($byTaf ? $tid : 0);
            if (!isset($agg[$kk])) $agg[$kk] = ['acc' => $x, 'taf' => $byTaf ? $tid : 0, 'o' => 0, 'pd' => 0, 'pc' => 0];
            if ($k === 0) $agg[$kk]['o'] += $dc[0] - $dc[1];
            else { $agg[$kk]['pd'] += $dc[0]; $agg[$kk]['pc'] += $dc[1]; }
        }
    }
    $rows = [];
    $tot = ['open_debit' => 0, 'open_credit' => 0, 'period_debit' => 0, 'period_credit' => 0, 'close_debit' => 0, 'close_credit' => 0];
    foreach ($agg as $a) {
        $cl = $a['o'] + $a['pd'] - $a['pc'];
        if ($hide && $a['o'] === 0 && $a['pd'] === 0 && $a['pc'] === 0 && $cl === 0) continue;
        $acc = $by[$a['acc']];
        $r = ['account_id' => (int)$acc['id'], 'code' => (string)$acc['code'], 'name' => (string)$acc['name'], 'level' => (int)$acc['level'],
            'tafsili_id' => $a['taf'] ?: null, 'tafsili_code' => $a['taf'] ? ($tafNames[$a['taf']]['code'] ?? null) : null, 'tafsili_name' => $a['taf'] ? ($tafNames[$a['taf']]['name'] ?? null) : null,
            'open_debit' => max($a['o'], 0), 'open_credit' => max(-$a['o'], 0), 'period_debit' => $a['pd'], 'period_credit' => $a['pc'],
            'close_debit' => max($cl, 0), 'close_credit' => max(-$cl, 0)];
        foreach ($tot as $k => $_) $tot[$k] += $r[$k];
        $rows[] = $r;
    }
    usort($rows, static function ($x, $y) { return strcmp($x['code'] . '|' . ($x['tafsili_code'] ?? ''), $y['code'] . '|' . ($y['tafsili_code'] ?? '')); });
    $check = ['opening' => $tot['open_debit'] === $tot['open_credit'], 'period' => $tot['period_debit'] === $tot['period_credit'], 'closing' => $tot['close_debit'] === $tot['close_credit']];
    $need = $cols === 2 ? ['closing'] : ($cols === 4 ? ['period', 'closing'] : ['opening', 'period', 'closing']);
    $ok = true;
    foreach ($need as $n) if (!$check[$n]) $ok = false;
    $rep = ['level' => $lv, 'columns' => $cols, 'rows' => $rows, 'totals' => $tot, 'check' => $check, 'balanced' => $ok, 'count' => count($rows), 'unit' => 'rial',
        'status_filter' => (string)($p['status'] ?? 'posted')];
    if (!empty($p['csv'])) {
        $head = ['code', 'name'];
        if ($byTaf) $head = ['code', 'name', 'tafsili'];
        $sets = $cols === 2 ? [['close_debit', 'close_credit']] : ($cols === 4 ? [['period_debit', 'period_credit'], ['close_debit', 'close_credit']]
            : [['open_debit', 'open_credit'], ['period_debit', 'period_credit'], ['close_debit', 'close_credit']]);
        foreach ($sets as $s) foreach ($s as $f) $head[] = $f;
        $out = [];
        foreach (array_merge($rows, [['code' => '', 'name' => 'جمع', 'tafsili_code' => null, 'tafsili_name' => null] + $tot]) as $r) {
            $line = [$r['code'], $r['name']];
            if ($byTaf) $line[] = trim(($r['tafsili_code'] ?? '') . ' ' . ($r['tafsili_name'] ?? ''));
            foreach ($sets as $s) foreach ($s as $f) $line[] = $r[$f];
            $out[] = $line;
        }
        $rep['csv'] = pb_lr_csv($head, $out);
    }
    return $rep;
}

function pb_tb_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_voucher_ensure($pdo);
    if ($action === 'reports.trial') out(pb_tb_report($pdo, $cid, $customer, $p));
    bad('عملیات نامعتبر', 404);
}
