<?php
declare(strict_types=1);
/**
 * S30.T30.4 — foreign-currency reports (GOLD + fx plan). One action: reports.fx {kind, from?, to?, fiscal_year_id?, status?, ccy?, account_id?, limit?}
 *  kind=ledger   : voucher lines carrying a currency (date, voucher no., account, ccy, ccy amount, rate, rial debit/credit)
 *  kind=trial    : per account x currency: signed currency balance (debit +, credit -) and rial debit/credit/balance
 *  kind=diff     : month x currency: realized (role fx_diff, settlement/adjust) and unrealized (role fx_unrealized, period-end revaluation) kept apart.
 *                  Legacy fields gain/loss/net/count = realized only (unchanged); added realized, unrealized, total (+ gain/loss splits). Currency: from the source
 *                  document (settlements) or from fx_revals.detail.items (revaluation vouchers); '' = could not be resolved.
 *  kind=reval    : fx_revals in [from,to] (as_of): status, rev_status, ar/ap up/down, net_delta, doc_count + unrealized gain/loss per currency (active rows only)
 *  kind=treasury : bank/cash accounts: currency balance and rial balance per treasury tafsili x currency
 * Read-only; every query is cid-scoped; voucher window shared with the ledger (pb_lr_vconds). Amounts: rial integers, currency decimals.
 */
require_once __DIR__ . '/ledger_reports.php';
require_once __DIR__ . '/fx_reval.php'; // pure report helpers (pb_fxr_report_rollup, pb_fxr_ccy_nets, ...)

function pb_fxrep_where(PDO $pdo, int $cid, array $customer, array $p): array {
    [$w, $a] = pb_lr_vconds($cid, $customer, $p, true);
    $w[] = 'l.customer_id=v.customer_id';
    $ccy = strtoupper(trim((string)($p['ccy'] ?? '')));
    if ($ccy !== '') { $w[] = 'l.ccy=?'; $a[] = $ccy; }
    return [$w, $a];
}

function pb_fxrep_sign(array $r): float { return ((int)$r['debit'] > 0 ? 1.0 : -1.0) * (float)$r['ccy_amount']; }

function pb_fxrep_ledger(PDO $pdo, int $cid, array $customer, array $p): array {
    [$w, $a] = pb_fxrep_where($pdo, $cid, $customer, $p);
    $w[] = "l.ccy IS NOT NULL AND l.ccy<>'' AND l.ccy_amount IS NOT NULL";
    $acc = (int)($p['account_id'] ?? 0);
    if ($acc > 0) { $sub = pb_lr_subtree($pdo, $cid, $acc); $w[] = 'l.account_id IN (' . pb_lr_ints($sub['ids']) . ')'; }
    $lim = max(1, min(2000, (int)($p['limit'] ?? 500)));
    $st = $pdo->prepare('SELECT v.id AS voucher_id, v.number, v.voucher_date, a.code AS account_code, a.name AS account_name, l.tafsili_id, l.description,
        l.debit, l.credit, l.ccy, l.ccy_amount, l.rate FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id JOIN accounts a ON a.id=l.account_id AND a.customer_id=v.customer_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY v.voucher_date, v.number, l.line_no LIMIT ' . $lim);
    $st->execute($a);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['voucher_id' => (int)$r['voucher_id'], 'number' => (int)$r['number'], 'date' => (string)$r['voucher_date'], 'jdate' => pb_lr_jdate((string)$r['voucher_date']),
            'account_code' => (string)$r['account_code'], 'account_name' => (string)$r['account_name'], 'description' => (string)($r['description'] ?? ''),
            'debit' => (int)$r['debit'], 'credit' => (int)$r['credit'], 'ccy' => (string)$r['ccy'], 'ccy_amount' => pb_fxrep_sign($r),
            'rate' => $r['rate'] === null ? null : (float)$r['rate']];
    }
    return ['kind' => 'ledger', 'rows' => $rows, 'truncated' => count($rows) >= $lim];
}

function pb_fxrep_trial(PDO $pdo, int $cid, array $customer, array $p): array {
    [$w, $a] = pb_fxrep_where($pdo, $cid, $customer, $p);
    $w[] = "l.ccy IS NOT NULL AND l.ccy<>'' AND l.ccy_amount IS NOT NULL";
    $st = $pdo->prepare('SELECT a.id AS account_id, a.code, a.name, l.ccy, SUM(CASE WHEN l.debit>0 THEN l.ccy_amount ELSE -l.ccy_amount END) AS ccy_bal,
        SUM(l.debit) AS debit, SUM(l.credit) AS credit FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id JOIN accounts a ON a.id=l.account_id AND a.customer_id=v.customer_id
        WHERE ' . implode(' AND ', $w) . ' GROUP BY a.id, a.code, a.name, l.ccy ORDER BY a.code, l.ccy');
    $st->execute($a);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $d = (int)$r['debit']; $c = (int)$r['credit']; $cb = round((float)$r['ccy_bal'], 4);
        $rows[] = ['account_id' => (int)$r['account_id'], 'account_code' => (string)$r['code'], 'account_name' => (string)$r['name'], 'ccy' => (string)$r['ccy'],
            'ccy_balance' => $cb, 'debit' => $d, 'credit' => $c, 'balance' => $d - $c, 'avg_rate' => abs($cb) > 0.00005 ? round(abs($d - $c) / abs($cb), 2) : null];
    }
    return ['kind' => 'trial', 'rows' => $rows];
}

/** Currency of the document that produced an fx_diff voucher ('' when unknown / revaluation). */
function pb_fxrep_src_ccy(PDO $pdo, int $cid, string $type, string $sid, array &$cache): string {
    $k = $type . '|' . $sid;
    if (isset($cache[$k])) return $cache[$k];
    $sql = [
        'fx_settle_recv' => 'SELECT ccy FROM sales WHERE id=? AND customer_id=?',
        'fx_settle_pay' => 'SELECT ccy FROM purchases WHERE id=? AND customer_id=?',
        'cheque_ev' => 'SELECT c.ccy FROM cheque_events e JOIN cheques c ON c.id=e.cheque_id AND c.customer_id=e.customer_id WHERE e.id=? AND e.customer_id=?',
        'inst_recv' => 'SELECT ccy FROM receipt_credit_inst WHERE id=? AND customer_id=?',
        'inst_pay' => 'SELECT ccy FROM credit_inst WHERE id=? AND customer_id=?',
    ][$type] ?? null;
    $v = '';
    if ($sql !== null && ctype_digit($sid)) {
        try { $q = $pdo->prepare($sql); $q->execute([(int)$sid, $cid]); $v = strtoupper(trim((string)$q->fetchColumn())); } catch (Throwable $e) { $v = ''; }
    }
    return $cache[$k] = $v;
}

/** Stored fx_revals.detail of one revaluation (decoded) or null; cached per request. */
function pb_fxrep_reval_detail(PDO $pdo, int $cid, int $id, array &$cache) {
    if (array_key_exists($id, $cache)) return $cache[$id];
    $v = null;
    try { $q = $pdo->prepare('SELECT detail FROM fx_revals WHERE id=? AND customer_id=?'); $q->execute([$id, $cid]); $d = $q->fetchColumn(); $v = $d ? json_decode((string)$d, true) : null; }
    catch (Throwable $e) { $v = null; }
    return $cache[$id] = $v;
}

function pb_fxrep_diff(PDO $pdo, int $cid, array $customer, array $p): array {
    [$w, $a] = pb_lr_vconds($cid, $customer, $p, true);
    $w[] = 'l.customer_id=v.customer_id';
    $w[] = 'a.system_role IN (?,?)'; $a[] = 'fx_diff'; $a[] = 'fx_unrealized';
    $st = $pdo->prepare('SELECT v.id AS vid, v.voucher_date, v.source_type, v.source_id, a.system_role AS role, l.debit, l.credit FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id
        JOIN accounts a ON a.id=l.account_id AND a.customer_id=v.customer_id WHERE ' . implode(' AND ', $w) . ' LIMIT 20000');
    $st->execute($a);
    $want = strtoupper(trim((string)($p['ccy'] ?? '')));
    $cache = []; $rcache = []; $flat = []; $rv = [];
    foreach ($st->fetchAll() as $r) {
        $m = substr((string)$r['voucher_date'], 0, 7); $role = (string)$r['role']; $stp = (string)$r['source_type'];
        if ($stp === 'fx_reval' || $stp === 'fx_reval_rev') { // revaluation voucher: split its net over currencies via the stored detail (also legacy vouchers that used fx_diff)
            $k = $role . '|' . $r['vid'];
            if (!isset($rv[$k])) $rv[$k] = ['month' => $m, 'role' => $role, 'sid' => (string)$r['source_id'], 'rev' => $stp === 'fx_reval_rev', 'net' => 0];
            $rv[$k]['net'] += (int)$r['credit'] - (int)$r['debit'];
            continue;
        }
        $c = $role === 'fx_diff' ? pb_fxrep_src_ccy($pdo, $cid, $stp, (string)$r['source_id'], $cache) : ''; // unrealized line outside a revaluation voucher (manual): unresolved
        if ($want !== '' && $c !== $want) continue;
        $flat[] = ['month' => $m, 'ccy' => $c, 'role' => $role, 'debit' => (int)$r['debit'], 'credit' => (int)$r['credit']];
    }
    foreach ($rv as $x) {
        $nets = ctype_digit($x['sid']) ? pb_fxr_ccy_nets(pb_fxrep_reval_detail($pdo, $cid, (int)$x['sid'], $rcache)) : [];
        foreach (pb_fxr_alloc_net($x['net'], $nets, $x['rev']) as $c => $n) {
            if ($want !== '' && (string)$c !== $want) continue;
            $flat[] = ['month' => $x['month'], 'ccy' => (string)$c, 'role' => $x['role'], 'debit' => $n < 0 ? -$n : 0, 'credit' => $n > 0 ? $n : 0];
        }
    }
    $r = pb_fxr_report_rollup($flat);
    foreach ($r['rows'] as &$x) $x['jmonth'] = substr(pb_lr_jdate($x['month'] . '-15'), 0, 7);
    unset($x);
    return ['kind' => 'diff'] + $r;
}

/** kind=reval: revaluations whose as_of lies in [from,to]; optional status=active|reversed filter. */
function pb_fxrep_reval(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_fxr_ensure($pdo);
    $w = ['customer_id=?']; $a = [$cid];
    if (!empty($p['from'])) { $w[] = 'as_of>=?'; $a[] = pb_voucher_date($p['from']); }
    if (!empty($p['to'])) { $w[] = 'as_of<=?'; $a[] = pb_voucher_date($p['to']); }
    $stt = (string)($p['status'] ?? '');
    if ($stt === 'active' || $stt === 'reversed') { $w[] = 'status=?'; $a[] = $stt; }
    $st = $pdo->prepare('SELECT id, as_of, status, rev_status, rev_date, ar_up, ar_down, ap_up, ap_down, net_delta, doc_count, detail FROM fx_revals WHERE ' . implode(' AND ', $w) . ' ORDER BY as_of, id LIMIT 500');
    $st->execute($a);
    $rows = []; $for = []; $tn = 0;
    foreach ($st->fetchAll() as $r) {
        $au = (int)$r['ar_up']; $ad = (int)$r['ar_down']; $pu = (int)$r['ap_up']; $pd = (int)$r['ap_down'];
        $net = (int)$r['net_delta'] !== 0 ? (int)$r['net_delta'] : ($au - $ad) - ($pu - $pd); // legacy rows have no stored net_delta
        $asOf = substr((string)$r['as_of'], 0, 10);
        $rows[] = ['id' => (int)$r['id'], 'as_of' => $asOf, 'jas_of' => pb_lr_jdate($asOf), 'status' => (string)$r['status'], 'rev_status' => (string)($r['rev_status'] ?? 'none'),
            'rev_date' => $r['rev_date'] ?? null, 'ar_up' => $au, 'ar_down' => $ad, 'ap_up' => $pu, 'ap_down' => $pd, 'net_delta' => $net, 'doc_count' => (int)$r['doc_count']];
        $for[] = ['status' => (string)$r['status'], 'detail' => $r['detail'] ? json_decode((string)$r['detail'], true) : null];
        if ((string)$r['status'] === 'active') $tn += $net;
    }
    return ['kind' => 'reval', 'rows' => $rows, 'by_ccy' => pb_fxr_reval_ccy_totals($for), 'total_net_delta' => $tn, 'truncated' => count($rows) >= 500];
}

function pb_fxrep_treasury(PDO $pdo, int $cid, array $customer, array $p): array {
    $ids = [];
    $q = $pdo->prepare("SELECT account_id FROM account_roles WHERE customer_id=? AND role IN ('bank','cash')");
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) { try { foreach (pb_lr_subtree($pdo, $cid, (int)$r['account_id'])['ids'] as $i) $ids[(int)$i] = true; } catch (Throwable $e) { /* missing account */ } }
    if (!$ids) return ['kind' => 'treasury', 'rows' => []];
    [$w, $a] = pb_fxrep_where($pdo, $cid, $customer, $p);
    $w[] = 'l.account_id IN (' . pb_lr_ints(array_keys($ids)) . ')';
    $st = $pdo->prepare("SELECT l.tafsili_id, t.kind, t.name, COALESCE(NULLIF(l.ccy,''),'') AS ccy,
        SUM(CASE WHEN l.ccy IS NULL OR l.ccy='' THEN 0 WHEN l.debit>0 THEN l.ccy_amount ELSE -l.ccy_amount END) AS ccy_bal, SUM(l.debit) AS debit, SUM(l.credit) AS credit
        FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id LEFT JOIN tafsili t ON t.id=l.tafsili_id AND t.customer_id=v.customer_id
        WHERE " . implode(' AND ', $w) . ' GROUP BY l.tafsili_id, t.kind, t.name, ccy ORDER BY t.kind, t.name, ccy');
    $st->execute($a);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['tafsili_id' => (int)$r['tafsili_id'], 'kind' => (string)($r['kind'] ?? ''), 'name' => (string)($r['name'] ?? '—'), 'ccy' => (string)$r['ccy'],
            'ccy_balance' => round((float)$r['ccy_bal'], 4), 'balance' => (int)$r['debit'] - (int)$r['credit']];
    }
    return ['kind' => 'treasury', 'rows' => $rows];
}

function pb_fxrep_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    if ((string)($customer['plan'] ?? '') !== 'fx') out(['error' => 'edition_required', 'need' => 'fx'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_voucher_ensure($pdo);
    $kind = (string)($p['kind'] ?? 'trial');
    if ($kind === 'ledger') out(pb_fxrep_ledger($pdo, $cid, $customer, $p));
    if ($kind === 'trial') out(pb_fxrep_trial($pdo, $cid, $customer, $p));
    if ($kind === 'diff') out(pb_fxrep_diff($pdo, $cid, $customer, $p));
    if ($kind === 'reval') out(pb_fxrep_reval($pdo, $cid, $customer, $p));
    if ($kind === 'treasury') out(pb_fxrep_treasury($pdo, $cid, $customer, $p));
    bad('نوع گزارش نامعتبر است');
}
