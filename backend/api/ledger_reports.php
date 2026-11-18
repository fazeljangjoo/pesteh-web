<?php
declare(strict_types=1);
/**
 * S18.T18.1 — journal / general ledger / subsidiary ledger / tafsili statement (GOLD, read-only).
 * Actions (perm accounting.view):
 *   reports.journal {fiscal_year_id?, from?, to?, no_from?, no_to?, status?, account_id?, tafsili_id?, cost_center_id?, limit?, offset?, csv?}
 *       دفتر روزنامه: vouchers (paged by voucher) with ALL their lines; line filters (account subtree / tafsili / cost center) select vouchers.
 *   reports.ledger  {account_id (any level; descendants included), same filters, tafsili_id? (extra filter)}
 *       دفتر کل (level 2) / معین (level 3) — the level is simply the level of account_id; returns `children` (direct sub-accounts summary) for drill-down.
 *   reports.tafsili {tafsili_id, account_id?, same filters}  صورت‌حساب تفصیلی.
 * status: posted (default = temporary+final) | all (incl. drafts) | final | temporary | draft.
 * Amounts: INTEGER rial exactly as stored in voucher_lines. balance = running (debit - credit): positive => بدهکار, negative => بستانکار.
 * Opening = lines BEFORE `from` inside the same filter scope (so closing = opening + period; same numbers the trial balance (T18.2) must give).
 * Pagination: limit (default 100, max 500) / offset; running balance of page N is exact (prefix sum computed in SQL). csv=1 returns whole result (max PB_LR_MAX_ROWS) as `csv`.
 * Drill-down: every row carries voucher_id, number, source_type, source_id.
 */
require_once __DIR__ . '/fiscal.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/vouchers.php';
require_once __DIR__ . '/jdate.php';

const PB_LR_MAX_ROWS = 20000;
const PB_LR_ORDER = 'ORDER BY v.voucher_date, COALESCE(v.number, 4294967295), v.id, l.line_no, l.id';

function pb_lr_jdate(string $ymd): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) return $ymd;
    $j = pb_g2j((int)$m[1], (int)$m[2], (int)$m[3]);
    return sprintf('%04d/%02d/%02d', $j[0], $j[1], $j[2]);
}

function pb_lr_side(int $bal): string { return $bal > 0 ? 'debit' : ($bal < 0 ? 'credit' : 'zero'); }

function pb_lr_bal(int $debit, int $credit): array {
    $b = $debit - $credit;
    return ['debit' => $debit, 'credit' => $credit, 'balance' => $b, 'side' => pb_lr_side($b), 'amount' => abs($b)];
}

/** Voucher-level conditions on alias v. $range=false => no date/number window (used for opening). */
function pb_lr_vconds(int $cid, array $customer, array $p, bool $range): array {
    $w = ['v.customer_id=?'];
    $a = [$cid];
    $fy = (int)($p['fiscal_year_id'] ?? 0);
    if ($fy > 0) { pb_fy_require($customer, $fy); $w[] = 'v.fiscal_year_id=?'; $a[] = $fy; }
    $allowed = $customer['_allowed_fy'] ?? null;
    if ((int)($customer['_staff_id'] ?? 0) > 0 && $allowed !== null) {
        $ids = array_values(array_map('intval', (array)$allowed));
        $w[] = $ids ? 'v.fiscal_year_id IN (' . implode(',', $ids) . ')' : '1=0';
    }
    // T19.2b: fy_open/fy_close are year-boundary carry vouchers; an all-years scope (no fiscal_year_id) already contains the prior years, so counting them would double/zero balances
    if ($fy <= 0 && empty($p['with_carry'])) $w[] = "v.source_type NOT IN ('fy_open','fy_close')";
    $st = (string)($p['status'] ?? 'posted');
    if ($st === 'posted') $w[] = "v.status<>'draft'";
    elseif (in_array($st, PB_VOUCHER_STATUSES, true)) { $w[] = 'v.status=?'; $a[] = $st; }
    elseif ($st !== 'all') bad('وضعیت نامعتبر است');
    if ($range) {
        if (!empty($p['from'])) { $w[] = 'v.voucher_date>=?'; $a[] = pb_voucher_date($p['from']); }
        if (!empty($p['to'])) { $w[] = 'v.voucher_date<=?'; $a[] = pb_voucher_date($p['to']); }
        if (isset($p['no_from']) && $p['no_from'] !== '') { $w[] = 'v.number>=?'; $a[] = max(0, (int)$p['no_from']); }
        if (isset($p['no_to']) && $p['no_to'] !== '') { $w[] = 'v.number<=?'; $a[] = max(0, (int)$p['no_to']); }
    }
    return [$w, $a];
}

/** Account id + all descendants (ids) and its row. */
function pb_lr_subtree(PDO $pdo, int $cid, int $accId): array {
    $st = $pdo->prepare('SELECT id, code, name, level, parent_id, nature, type FROM accounts WHERE customer_id=?');
    $st->execute([$cid]);
    $rows = $st->fetchAll();
    $by = [];
    $kids = [];
    foreach ($rows as $r) { $by[(int)$r['id']] = $r; $kids[(int)($r['parent_id'] ?? 0)][] = (int)$r['id']; }
    if (!isset($by[$accId])) bad('حساب یافت نشد', 404);
    $ids = [];
    $stack = [$accId];
    while ($stack) {
        $x = array_pop($stack);
        if (isset($ids[$x])) continue;
        $ids[$x] = true;
        foreach ($kids[$x] ?? [] as $k) $stack[] = $k;
    }
    return ['ids' => array_keys($ids), 'acc' => $by[$accId], 'by' => $by, 'kids' => $kids];
}

function pb_lr_ints(array $ids): string { return implode(',', array_map('intval', $ids)); }

/** Line-level conditions (alias l). */
function pb_lr_lconds(array $p, ?array $accIds, ?int $tafsili): array {
    $w = [];
    $a = [];
    if ($accIds !== null) $w[] = $accIds ? 'l.account_id IN (' . pb_lr_ints($accIds) . ')' : '1=0';
    $t = $tafsili ?? (int)($p['tafsili_id'] ?? 0);
    if ($t > 0) { $w[] = 'l.tafsili_id=?'; $a[] = $t; }
    $cc = (int)($p['cost_center_id'] ?? 0);
    if ($cc > 0) { $w[] = 'l.cost_center_id=?'; $a[] = $cc; }
    return [$w, $a];
}

function pb_lr_limit(array $p, int $def, int $max): array {
    if (!empty($p['csv'])) return [PB_LR_MAX_ROWS, 0];
    return [max(1, min($max, (int)($p['limit'] ?? $def))), max(0, (int)($p['offset'] ?? 0))];
}

function pb_lr_csv(array $head, array $rows): string {
    $esc = static function ($v): string { $s = (string)$v; return (strpbrk($s, ",\"\n\r") !== false) ? '"' . str_replace('"', '""', $s) . '"' : $s; };
    $out = [implode(',', array_map($esc, $head))];
    foreach ($rows as $r) $out[] = implode(',', array_map($esc, $r));
    return "\xEF\xBB\xBF" . implode("\r\n", $out) . "\r\n";
}

function pb_lr_has_cc(PDO $pdo): bool { return pb_taf_exists($pdo, 'ent_cost_centers'); }

/** Row select for line-level reports. */
function pb_lr_select(PDO $pdo): array {
    $cc = pb_lr_has_cc($pdo);
    $sel = 'l.id AS line_id, l.voucher_id, l.line_no, l.account_id, l.tafsili_id, l.cost_center_id, l.debit, l.credit, l.description, l.ccy, l.ccy_amount, l.rate,
        v.number, v.voucher_date, v.status, v.source_type, v.source_id, v.fiscal_year_id, v.description AS vdesc,
        a.code AS account_code, a.name AS account_name, t.code AS tafsili_code, t.name AS tafsili_name' . ($cc ? ', cc.name AS cost_center_name' : ', NULL AS cost_center_name');
    $from = 'FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id
        JOIN accounts a ON a.id=l.account_id AND a.customer_id=l.customer_id
        LEFT JOIN tafsili t ON t.id=l.tafsili_id AND t.customer_id=l.customer_id'
        . ($cc ? ' LEFT JOIN ent_cost_centers cc ON cc.id=l.cost_center_id AND cc.customer_id=l.customer_id' : '');
    return [$sel, $from];
}

function pb_lr_row_out(array $r, ?int $balance): array {
    $o = [
        'line_id' => (int)$r['line_id'], 'voucher_id' => (int)$r['voucher_id'], 'number' => $r['number'] === null ? null : (int)$r['number'],
        'date' => (string)$r['voucher_date'], 'jdate' => pb_lr_jdate((string)$r['voucher_date']), 'status' => (string)$r['status'],
        'source_type' => (string)$r['source_type'], 'source_id' => $r['source_id'], 'fiscal_year_id' => (int)$r['fiscal_year_id'],
        'line_no' => (int)$r['line_no'], 'voucher_description' => (string)($r['vdesc'] ?? ''), 'description' => (string)($r['description'] ?? ''),
        'account_id' => (int)$r['account_id'], 'account_code' => (string)$r['account_code'], 'account_name' => (string)$r['account_name'],
        'tafsili_id' => $r['tafsili_id'] === null ? null : (int)$r['tafsili_id'], 'tafsili_code' => $r['tafsili_code'], 'tafsili_name' => $r['tafsili_name'],
        'cost_center_id' => $r['cost_center_id'] === null ? null : (int)$r['cost_center_id'], 'cost_center_name' => $r['cost_center_name'],
        'debit' => (int)$r['debit'], 'credit' => (int)$r['credit'],
    ];
    if ($r['ccy'] !== null && $r['ccy'] !== '') { $o['ccy'] = (string)$r['ccy']; $o['ccy_amount'] = $r['ccy_amount'] === null ? null : (float)$r['ccy_amount']; $o['rate'] = $r['rate'] === null ? null : (float)$r['rate']; }
    if ($balance !== null) { $o['balance'] = $balance; $o['side'] = pb_lr_side($balance); }
    return $o;
}

/**
 * Shared engine for ledger + tafsili: opening, paged rows with exact running balance, period totals, closing.
 * @param int[]|null $accIds accounts in scope (null = all), $tafsili forced tafsili (or null => p.tafsili_id)
 */
function pb_lr_lines_report(PDO $pdo, int $cid, array $customer, array $p, ?array $accIds, ?int $tafsili): array {
    [$vw, $va] = pb_lr_vconds($cid, $customer, $p, true);
    [$lw, $la] = pb_lr_lconds($p, $accIds, $tafsili);
    $where = 'WHERE ' . implode(' AND ', array_merge($vw, $lw));
    $args = array_merge($va, $la);
    [$sel, $from] = pb_lr_select($pdo);
    [$limit, $off] = pb_lr_limit($p, 100, 500);

    // opening: everything before `from` in the same scope (fy / status / account / tafsili / cost center)
    $open = ['debit' => 0, 'credit' => 0];
    if (!empty($p['from'])) {
        [$ow, $oa] = pb_lr_vconds($cid, $customer, $p, false);
        $ow[] = 'v.voucher_date<?';
        $oa[] = pb_voucher_date($p['from']);
        $st = $pdo->prepare('SELECT COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id WHERE '
            . implode(' AND ', array_merge($ow, $lw)));
        $st->execute(array_merge($oa, $la));
        $o = $st->fetch();
        $open = ['debit' => (int)$o['d'], 'credit' => (int)$o['c']];
    }
    $opening = pb_lr_bal($open['debit'], $open['credit']);

    $st = $pdo->prepare('SELECT COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c, COUNT(*) n FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id ' . $where);
    $st->execute($args);
    $tot = $st->fetch();
    $period = pb_lr_bal((int)$tot['d'], (int)$tot['c']);
    $count = (int)$tot['n'];

    $prefix = 0;
    if ($off > 0) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(x.d - x.c),0) FROM (SELECT l.debit AS d, l.credit AS c ' . $from . ' ' . $where . ' ' . PB_LR_ORDER . " LIMIT $off) x");
        $st->execute($args);
        $prefix = (int)$st->fetchColumn();
    }
    $st = $pdo->prepare('SELECT ' . $sel . ' ' . $from . ' ' . $where . ' ' . PB_LR_ORDER . " LIMIT $limit OFFSET $off");
    $st->execute($args);
    $bal = $opening['balance'] + $prefix;
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $bal += (int)$r['debit'] - (int)$r['credit'];
        $rows[] = pb_lr_row_out($r, $bal);
    }
    $closing = pb_lr_bal($opening['debit'] + $period['debit'], $opening['credit'] + $period['credit']);
    return ['opening' => $opening, 'period' => $period, 'closing' => $closing, 'rows' => $rows, 'count' => $count, 'limit' => $limit, 'offset' => $off,
        'unit' => 'rial', 'status_filter' => (string)($p['status'] ?? 'posted')];
}

function pb_lr_lines_csv(array $rep): string {
    $rows = [['', '', '', '', '', 'مانده اول دوره', $rep['opening']['debit'], $rep['opening']['credit'], $rep['opening']['balance'], $rep['opening']['side']]];
    foreach ($rep['rows'] as $r) {
        $rows[] = [$r['number'] ?? '', $r['jdate'], $r['account_code'] . ' ' . $r['account_name'], trim(($r['tafsili_code'] ?? '') . ' ' . ($r['tafsili_name'] ?? '')),
            $r['source_type'], $r['description'] !== '' ? $r['description'] : $r['voucher_description'], $r['debit'], $r['credit'], $r['balance'], $r['side']];
    }
    $rows[] = ['', '', '', '', '', 'جمع گردش', $rep['period']['debit'], $rep['period']['credit'], $rep['period']['balance'], $rep['period']['side']];
    $rows[] = ['', '', '', '', '', 'مانده پایان', $rep['closing']['debit'], $rep['closing']['credit'], $rep['closing']['balance'], $rep['closing']['side']];
    return pb_lr_csv(['voucher_no', 'date', 'account', 'tafsili', 'source', 'description', 'debit', 'credit', 'balance', 'side'], $rows);
}

function pb_lr_ledger(PDO $pdo, int $cid, array $customer, array $p): array {
    $accId = (int)($p['account_id'] ?? 0);
    if ($accId <= 0) bad('حساب را انتخاب کنید');
    $tree = pb_lr_subtree($pdo, $cid, $accId);
    $rep = pb_lr_lines_report($pdo, $cid, $customer, $p, $tree['ids'], null);
    $a = $tree['acc'];
    $rep['account'] = ['id' => (int)$a['id'], 'code' => (string)$a['code'], 'name' => (string)$a['name'], 'level' => (int)$a['level'], 'nature' => (string)$a['nature'], 'type' => (string)$a['type'],
        'is_leaf' => count($tree['ids']) === 1];

    // direct children summary (drill-down kol -> moin -> leaf); opening/period/closing per child
    $children = [];
    if (count($tree['ids']) > 1) {
        [$ow, $oa] = pb_lr_vconds($cid, $customer, $p, true);
        [$lw, $la] = pb_lr_lconds($p, null, null);
        $sumBy = function (array $vw, array $va) use ($pdo, $lw, $la, $tree): array {
            $st = $pdo->prepare('SELECT l.account_id, COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id WHERE '
                . implode(' AND ', array_merge($vw, $lw, ['l.account_id IN (' . pb_lr_ints($tree['ids']) . ')'])) . ' GROUP BY l.account_id');
            $st->execute(array_merge($va, $la));
            $m = [];
            foreach ($st->fetchAll() as $r) $m[(int)$r['account_id']] = [(int)$r['d'], (int)$r['c']];
            return $m;
        };
        $per = $sumBy($ow, $oa);
        $opn = [];
        if (!empty($p['from'])) {
            [$bw, $ba] = pb_lr_vconds($cid, $customer, $p, false);
            $bw[] = 'v.voucher_date<?';
            $ba[] = pb_voucher_date($p['from']);
            $opn = $sumBy($bw, $ba);
        }
        $own = [];
        foreach ($tree['kids'][$accId] ?? [] as $kid) {
            $sub = pb_lr_subtree($pdo, $cid, $kid)['ids'];
            $od = $oc = $pd = $pc = 0;
            foreach ($sub as $sid) {
                if (isset($opn[$sid])) { $od += $opn[$sid][0]; $oc += $opn[$sid][1]; }
                if (isset($per[$sid])) { $pd += $per[$sid][0]; $pc += $per[$sid][1]; }
            }
            $k = $tree['by'][$kid];
            $own[] = ['id' => $kid, 'code' => (string)$k['code'], 'name' => (string)$k['name'], 'level' => (int)$k['level'],
                'opening' => pb_lr_bal($od, $oc), 'period' => pb_lr_bal($pd, $pc), 'closing' => pb_lr_bal($od + $pd, $oc + $pc)];
        }
        usort($own, static function ($x, $y) { return strcmp($x['code'], $y['code']); });
        $children = $own;
    }
    $rep['children'] = $children;
    if (!empty($p['csv'])) $rep['csv'] = pb_lr_lines_csv($rep);
    return $rep;
}

function pb_lr_tafsili(PDO $pdo, int $cid, array $customer, array $p): array {
    $tid = (int)($p['tafsili_id'] ?? 0);
    if ($tid <= 0) bad('تفصیلی را انتخاب کنید');
    $st = $pdo->prepare('SELECT id, kind, code, name FROM tafsili WHERE id=? AND customer_id=?');
    $st->execute([$tid, $cid]);
    $t = $st->fetch();
    if (!$t) bad('تفصیلی یافت نشد', 404);
    $accIds = null;
    $acc = null;
    if (!empty($p['account_id'])) {
        $tree = pb_lr_subtree($pdo, $cid, (int)$p['account_id']);
        $accIds = $tree['ids'];
        $acc = ['id' => (int)$tree['acc']['id'], 'code' => (string)$tree['acc']['code'], 'name' => (string)$tree['acc']['name']];
    }
    $rep = pb_lr_lines_report($pdo, $cid, $customer, $p, $accIds, $tid);
    $rep['tafsili'] = ['id' => (int)$t['id'], 'kind' => (string)$t['kind'], 'code' => (string)$t['code'], 'name' => (string)$t['name']];
    $rep['account'] = $acc;

    // per-account breakdown (period + opening) so the statement shows which accounts the party moved through
    [$vw, $va] = pb_lr_vconds($cid, $customer, $p, true);
    [$lw, $la] = pb_lr_lconds($p, $accIds, $tid);
    $st = $pdo->prepare('SELECT a.id, a.code, a.name, COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c FROM voucher_lines l
        JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id JOIN accounts a ON a.id=l.account_id AND a.customer_id=l.customer_id
        WHERE ' . implode(' AND ', array_merge($vw, $lw)) . ' GROUP BY a.id, a.code, a.name ORDER BY a.code');
    $st->execute(array_merge($va, $la));
    $by = [];
    foreach ($st->fetchAll() as $r) $by[] = ['account_id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'period' => pb_lr_bal((int)$r['d'], (int)$r['c'])];
    $rep['by_account'] = $by;
    if (!empty($p['csv'])) $rep['csv'] = pb_lr_lines_csv($rep);
    return $rep;
}

function pb_lr_journal(PDO $pdo, int $cid, array $customer, array $p): array {
    $p['with_carry'] = 1; // the journal lists every voucher, including fy_open / fy_close
    [$vw, $va] = pb_lr_vconds($cid, $customer, $p, true);
    $accIds = null;
    if (!empty($p['account_id'])) $accIds = pb_lr_subtree($pdo, $cid, (int)$p['account_id'])['ids'];
    [$lw, $la] = pb_lr_lconds($p, $accIds, null);
    if ($lw) { // line filters select vouchers; all lines of a selected voucher are still shown
        $vw[] = 'EXISTS (SELECT 1 FROM voucher_lines x WHERE x.voucher_id=v.id AND x.customer_id=v.customer_id AND ' . str_replace('l.', 'x.', implode(' AND ', $lw)) . ')';
        $va = array_merge($va, $la);
    }
    $where = 'WHERE ' . implode(' AND ', $vw);
    [$limit, $off] = pb_lr_limit($p, 30, 200);
    $st = $pdo->prepare('SELECT COUNT(*) FROM vouchers v ' . $where);
    $st->execute($va);
    $count = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c FROM vouchers v JOIN voucher_lines l ON l.voucher_id=v.id AND l.customer_id=v.customer_id ' . $where);
    $st->execute($va);
    $t = $st->fetch();
    $st = $pdo->prepare('SELECT v.* FROM vouchers v ' . $where . " ORDER BY v.voucher_date, COALESCE(v.number, 4294967295), v.id LIMIT $limit OFFSET $off");
    $st->execute($va);
    $vs = $st->fetchAll();
    $ids = array_map(static function ($r) { return (int)$r['id']; }, $vs);
    $lines = [];
    if ($ids) {
        [$sel, $from] = pb_lr_select($pdo);
        $q = $pdo->prepare('SELECT ' . $sel . ' ' . $from . ' WHERE l.customer_id=? AND l.voucher_id IN (' . pb_lr_ints($ids) . ') ORDER BY l.voucher_id, l.line_no, l.id');
        $q->execute([$cid]);
        foreach ($q->fetchAll() as $r) $lines[(int)$r['voucher_id']][] = pb_lr_row_out($r, null);
    }
    $items = [];
    foreach ($vs as $v) {
        $h = pb_voucher_head_out($v);
        $h['jdate'] = pb_lr_jdate($h['date']);
        $ls = $lines[(int)$v['id']] ?? [];
        $h['lines'] = $ls;
        $h['debit'] = array_sum(array_column($ls, 'debit'));
        $h['credit'] = array_sum(array_column($ls, 'credit'));
        $items[] = $h;
    }
    $rep = ['items' => $items, 'count' => $count, 'limit' => $limit, 'offset' => $off, 'unit' => 'rial', 'status_filter' => (string)($p['status'] ?? 'posted'),
        'totals' => ['debit' => (int)$t['d'], 'credit' => (int)$t['c'], 'balanced' => (int)$t['d'] === (int)$t['c']]];
    if (!empty($p['csv'])) {
        $rows = [];
        foreach ($items as $h) foreach ($h['lines'] as $l) {
            $rows[] = [$h['number'] ?? '', $h['jdate'], $h['status'], $h['source_type'], $l['line_no'], $l['account_code'] . ' ' . $l['account_name'],
                trim(($l['tafsili_code'] ?? '') . ' ' . ($l['tafsili_name'] ?? '')), $l['description'] !== '' ? $l['description'] : $h['description'], $l['debit'], $l['credit']];
        }
        $rep['csv'] = pb_lr_csv(['voucher_no', 'date', 'status', 'source', 'line', 'account', 'tafsili', 'description', 'debit', 'credit'], $rows);
    }
    return $rep;
}

function pb_lr_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_voucher_ensure($pdo);
    if ($action === 'reports.journal') out(pb_lr_journal($pdo, $cid, $customer, $p));
    if ($action === 'reports.ledger') out(pb_lr_ledger($pdo, $cid, $customer, $p));
    if ($action === 'reports.tafsili') out(pb_lr_tafsili($pdo, $cid, $customer, $p));
    bad('عملیات نامعتبر', 404);
}
