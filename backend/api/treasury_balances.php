<?php
declare(strict_types=1);
/**
 * S28.T28.1 — treasury balances per account and per currency (GOLD, read-only). Action: treasury.balances (perm accounting.view)
 *   {fiscal_year_id?, status? (posted|all|<voucher status>)}  -> {accounts[], by_ccy[], unit:'rial'}
 * accounts[]: kind cash|bank, uid, name, ccy, opening_rial, opening_ccy, rial_balance (int rial), ccy_balance (foreign units, 4 dp; for IRR = rial_balance),
 *             other_ccy_lines (voucher lines on the account whose ccy differs from the account ccy: should be 0, shown as a warning).
 * Balance = opening + Σ(debit − credit) of non-draft vouchers (same filters as reports.*). Foreign balance = opening_ccy + Σ signed ccy_amount of lines in the account ccy.
 */
require_once __DIR__ . '/ledger_reports.php';

function pb_tr_has_col(PDO $pdo, string $t, string $c): bool {
    try { $st = $pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c)); return (bool)($st && $st->fetch()); }
    catch (Throwable $e) { return false; }
}

function pb_tr_balances(PDO $pdo, int $cid, array $customer, array $p): array {
    $accounts = [];
    foreach (['cash' => 'ent_cash_boxes', 'bank' => 'ent_bank_accounts'] as $kind => $tbl) {
        if (!pb_taf_exists($pdo, $tbl)) continue;
        $hasCcy = pb_tr_has_col($pdo, $tbl, 'ccy');
        $hasOpC = pb_tr_has_col($pdo, $tbl, 'opening_ccy_amount');
        $hasOp = pb_tr_has_col($pdo, $tbl, 'opening_balance');
        $sel = 'uid, name, ' . ($hasCcy ? 'ccy' : "'IRR' AS ccy") . ', ' . ($hasOp ? 'opening_balance' : '0 AS opening_balance') . ', ' . ($hasOpC ? 'opening_ccy_amount' : 'NULL AS opening_ccy_amount');
        $st = $pdo->prepare("SELECT $sel FROM `$tbl` WHERE customer_id=? AND deleted_at IS NULL ORDER BY id");
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) {
            $c = strtoupper(trim((string)$r['ccy']));
            if ($c === '' || $c === 'IRT') $c = 'IRR';
            $accounts[$kind . ':' . $r['uid']] = ['kind' => $kind, 'uid' => (string)$r['uid'], 'name' => (string)$r['name'], 'ccy' => $c,
                'opening_rial' => (int)$r['opening_balance'], 'opening_ccy' => $r['opening_ccy_amount'] === null ? 0.0 : (float)$r['opening_ccy_amount'],
                'rial_balance' => (int)$r['opening_balance'], 'ccy_balance' => $c === 'IRR' ? 0.0 : ($r['opening_ccy_amount'] === null ? 0.0 : (float)$r['opening_ccy_amount']),
                'other_ccy_lines' => 0];
        }
    }
    // tafsili id -> account key
    $tmap = [];
    if ($accounts) {
        $st = $pdo->prepare("SELECT id, kind, ref_key FROM tafsili WHERE customer_id=? AND kind IN ('cash','bank')");
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $t) { $k = $t['kind'] . ':' . $t['ref_key']; if (isset($accounts[$k])) $tmap[(int)$t['id']] = $k; }
    }
    if ($tmap) {
        [$w, $a] = pb_lr_vconds($cid, $customer, $p, false);
        $w[] = 'vl.tafsili_id IN (' . pb_lr_ints(array_keys($tmap)) . ')';
        $sql = 'SELECT vl.tafsili_id, vl.ccy, COUNT(*) n, SUM(vl.debit) d, SUM(vl.credit) c, '
             . 'SUM(CASE WHEN vl.debit>0 THEN COALESCE(vl.ccy_amount,0) ELSE -COALESCE(vl.ccy_amount,0) END) ca '
             . 'FROM voucher_lines vl JOIN vouchers v ON v.id=vl.voucher_id AND v.customer_id=vl.customer_id WHERE ' . implode(' AND ', $w) . ' GROUP BY vl.tafsili_id, vl.ccy';
        $st = $pdo->prepare($sql);
        $st->execute($a);
        foreach ($st->fetchAll() as $r) {
            $k = $tmap[(int)$r['tafsili_id']];
            $acc =& $accounts[$k];
            $acc['rial_balance'] += (int)$r['d'] - (int)$r['c'];
            $lc = strtoupper(trim((string)($r['ccy'] ?? '')));
            if ($acc['ccy'] !== 'IRR') {
                if ($lc === $acc['ccy']) $acc['ccy_balance'] += (float)$r['ca'];
                else $acc['other_ccy_lines'] += (int)$r['n'];
            } elseif ($lc !== '' && $lc !== 'IRR') {
                $acc['other_ccy_lines'] += (int)$r['n'];
            }
            unset($acc);
        }
    }
    $by = [];
    foreach ($accounts as &$acc) {
        if ($acc['ccy'] === 'IRR') $acc['ccy_balance'] = (float)$acc['rial_balance'];
        else $acc['ccy_balance'] = round($acc['ccy_balance'], 4);
        $c = $acc['ccy'];
        if (!isset($by[$c])) $by[$c] = ['ccy' => $c, 'ccy_balance' => 0.0, 'rial_balance' => 0, 'accounts' => 0];
        $by[$c]['ccy_balance'] = round($by[$c]['ccy_balance'] + $acc['ccy_balance'], 4);
        $by[$c]['rial_balance'] += $acc['rial_balance'];
        $by[$c]['accounts']++;
    }
    unset($acc);
    ksort($by);
    return ['accounts' => array_values($accounts), 'by_ccy' => array_values($by), 'unit' => 'rial'];
}

function pb_tr_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_voucher_ensure($pdo);
    if ($action === 'treasury.balances') out(pb_tr_balances($pdo, $cid, $customer, $p));
    bad('عملیات نامعتبر', 404);
}
