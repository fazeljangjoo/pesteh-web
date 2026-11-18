<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S18.T18.3 — financial statements (GOLD, read-only from vouchers). Actions:
 *   reports.income  {fiscal_year_id?, from?, to?, status?, cost_center_id?}  صورت سود و زیان
 *   reports.balance {fiscal_year_id?, to? (as-of, default FY end), status?}    ترازنامه
 *   reports.cc_pnl  {fiscal_year_id?, from?, to?, status?}                      سود و زیان به تفکیک مرکز هزینه
 *   reports.instant {}                                                         کارت «سود و زیان لحظه‌ای» (سال مالی جاری)
 *   statements.map {}  /  statements.map_save {lines:[{id,label,prefixes}]}     نگاشت حساب→سطر (قابل ویرایش؛ حسابدار بازبینی کند)
 * Mapping: statement_lines(prefixes = comma separated account codes); an account goes to the line with the LONGEST matching prefix;
 * unmapped accounts fall into a synthetic «سایر …» line so totals never lose money. Memo accounts (type memo) are excluded.
 * Balance sheet: assets = liabilities + equity + prior-years result (unclosed until S19 closing) + current-year result — holds by construction
 * (every voucher balances) and is reported in check{}. Amounts: integer rial. Gross margin by lot/type/ounce: margin.php (reports.margin, T18.3b).
 */
require_once __DIR__ . '/ledger_reports.php';

function pb_st_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS statement_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        statement VARCHAR(4) NOT NULL,
        line_key VARCHAR(30) NOT NULL,
        label VARCHAR(120) NOT NULL,
        kind VARCHAR(10) NOT NULL,
        section VARCHAR(10) NOT NULL DEFAULT '',
        sort_no SMALLINT NOT NULL DEFAULT 0,
        prefixes VARCHAR(200) NOT NULL DEFAULT '',
        UNIQUE KEY uq_stl (customer_id, statement, line_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Seed defaults once per customer (accountant reviews via statements.map_save). */
function pb_st_seed(PDO $pdo, int $cid): void {
    static $done = [];
    if (isset($done[$cid])) return;
    pb_st_ensure($pdo);
    $st = $pdo->prepare('SELECT COUNT(*) FROM statement_lines WHERE customer_id=?');
    $st->execute([$cid]);
    if ((int)$st->fetchColumn() === 0) {
        $rows = [
            ['is', 'sales', 'درآمد فروش (خالص)', 'revenue', 'gross', '41'], ['is', 'cogs', 'بهای تمام‌شده کالای فروش‌رفته', 'expense', 'gross', '51'],
            ['is', 'opex', 'هزینه‌های عملیاتی', 'expense', 'op', '52'], ['is', 'other_income', 'سایر درآمدها', 'revenue', 'other', '42'],
            ['bs', 'cur_assets', 'دارایی‌های جاری', 'asset', '', '11'], ['bs', 'inventory', 'موجودی کالا', 'asset', '', '12'],
            ['bs', 'cur_liab', 'بدهی‌های جاری', 'liability', '', '21'], ['bs', 'lt_liab', 'بدهی‌های بلندمدت', 'liability', '', '22'],
            ['bs', 'equity', 'سرمایه و سود انباشته', 'equity', '', '31'],
        ];
        $ins = $pdo->prepare('INSERT IGNORE INTO statement_lines (customer_id, statement, line_key, label, kind, section, sort_no, prefixes) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($rows as $i => $r) $ins->execute([$cid, $r[0], $r[1], $r[2], $r[3], $r[4], $i + 1, $r[5]]);
    }
    $done[$cid] = true;
}

function pb_st_lines(PDO $pdo, int $cid, string $stmt): array {
    $st = $pdo->prepare('SELECT * FROM statement_lines WHERE customer_id=? AND statement=? ORDER BY sort_no, id');
    $st->execute([$cid, $stmt]);
    return $st->fetchAll();
}

function pb_st_natural(string $kind, int $d, int $c): int { return in_array($kind, ['asset', 'expense'], true) ? $d - $c : $c - $d; }

/** account_id => [debit, credit] for the voucher scope $p (+ optional date window overrides). */
function pb_st_sums(PDO $pdo, int $cid, array $customer, array $p, array $types): array {
    [$vw, $va] = pb_lr_vconds($cid, $customer, $p, true);
    [$lw, $la] = pb_lr_lconds($p, null, null);
    $st = $pdo->prepare('SELECT l.account_id, COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id
        JOIN accounts a ON a.id=l.account_id AND a.customer_id=l.customer_id WHERE ' . implode(' AND ', array_merge($vw, $lw, ["a.type IN ('" . implode("','", $types) . "')"])) . ' GROUP BY l.account_id');
    $st->execute(array_merge($va, $la));
    $m = [];
    foreach ($st->fetchAll() as $r) $m[(int)$r['account_id']] = [(int)$r['d'], (int)$r['c']];
    return $m;
}

/** Put account sums on statement lines. @return array{lines: array, total_by_kind: array} */
function pb_st_assign(PDO $pdo, int $cid, string $stmt, array $sums): array {
    $accs = [];
    $st = $pdo->prepare('SELECT id, code, name, type FROM accounts WHERE customer_id=?');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $a) $accs[(int)$a['id']] = $a;
    $defs = pb_st_lines($pdo, $cid, $stmt);
    $out = [];
    foreach ($defs as $d) $out[$d['line_key']] = ['key' => $d['line_key'], 'label' => $d['label'], 'kind' => $d['kind'], 'section' => $d['section'], 'amount' => 0, 'accounts' => [], 'synthetic' => false];
    $other = ['asset' => 'سایر دارایی‌ها', 'liability' => 'سایر بدهی‌ها', 'equity' => 'سایر حقوق صاحبان سرمایه', 'revenue' => 'سایر درآمدها (نگاشت‌نشده)', 'expense' => 'سایر هزینه‌ها (نگاشت‌نشده)'];
    foreach ($sums as $aid => [$dd, $cc]) {
        if (!isset($accs[$aid])) continue;
        $a = $accs[$aid];
        $best = null; $bl = -1;
        foreach ($defs as $d) {
            foreach (array_filter(array_map('trim', explode(',', (string)$d['prefixes']))) as $pf) {
                if (strncmp((string)$a['code'], $pf, strlen($pf)) === 0 && strlen($pf) > $bl) { $bl = strlen($pf); $best = $d['line_key']; }
            }
        }
        $type = (string)$a['type'];
        if ($best === null) {
            $best = 'other_' . $type;
            if (!isset($out[$best])) $out[$best] = ['key' => $best, 'label' => $other[$type] ?? $type, 'kind' => $type, 'section' => $type === 'revenue' ? 'other' : ($type === 'expense' ? 'op' : ''), 'amount' => 0, 'accounts' => [], 'synthetic' => true];
        }
        $amt = pb_st_natural($out[$best]['kind'], $dd, $cc);
        $out[$best]['amount'] += $amt;
        $out[$best]['accounts'][] = ['id' => $aid, 'code' => (string)$a['code'], 'name' => (string)$a['name'], 'amount' => $amt];
    }
    return array_values($out);
}

function pb_st_fy(PDO $pdo, int $cid, array $customer, array $p): array {
    $fy = (int)($p['fiscal_year_id'] ?? 0);
    if ($fy > 0) { pb_fy_require($customer, $fy); $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE id=? AND customer_id=?'); $st->execute([$fy, $cid]); }
    else { $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE customer_id=? ORDER BY is_current DESC, start_date DESC LIMIT 1'); $st->execute([$cid]); }
    $r = $st->fetch();
    if (!$r) bad('سال مالی یافت نشد', 404);
    pb_fy_require($customer, (int)$r['id']);
    return $r;
}

function pb_st_sum_kind(array $lines, string $kind, ?string $section = null): int {
    $t = 0;
    foreach ($lines as $l) if ($l['kind'] === $kind && ($section === null || $l['section'] === $section)) $t += $l['amount'];
    return $t;
}

function pb_st_income_calc(PDO $pdo, int $cid, array $customer, array $p): array {
    $lines = pb_st_assign($pdo, $cid, 'is', pb_st_sums($pdo, $cid, $customer, $p, ['revenue', 'expense']));
    $gross = pb_st_sum_kind($lines, 'revenue', 'gross') - pb_st_sum_kind($lines, 'expense', 'gross');
    $op = $gross - pb_st_sum_kind($lines, 'expense', 'op');
    $net = $op + pb_st_sum_kind($lines, 'revenue', 'other') - pb_st_sum_kind($lines, 'expense', 'other');
    $rev = pb_st_sum_kind($lines, 'revenue');
    $exp = pb_st_sum_kind($lines, 'expense');
    return ['lines' => $lines, 'gross_profit' => $gross, 'operating_profit' => $op, 'net_profit' => $net, 'total_revenue' => $rev, 'total_expense' => $exp,
        'check' => ['net_equals_rev_minus_exp' => $net === $rev - $exp]];
}

function pb_st_income(PDO $pdo, int $cid, array $customer, array $p): array {
    $fy = pb_st_fy($pdo, $cid, $customer, $p);
    $q = $p + [];
    unset($q['fiscal_year_id']);
    if (empty($q['from'])) $q['from'] = (string)$fy['start_date'];
    if (empty($q['to'])) $q['to'] = (string)$fy['end_date'];
    $r = pb_st_income_calc($pdo, $cid, $customer, $q);
    return ['fiscal_year' => ['id' => (int)$fy['id'], 'name' => (string)$fy['name']], 'from' => $q['from'], 'to' => $q['to'], 'unit' => 'rial', 'status_filter' => (string)($p['status'] ?? 'posted')] + $r;
}

function pb_st_balance(PDO $pdo, int $cid, array $customer, array $p): array {
    $fy = pb_st_fy($pdo, $cid, $customer, $p);
    $to = !empty($p['to']) ? pb_voucher_date($p['to']) : (string)$fy['end_date'];
    $base = ['status' => $p['status'] ?? 'posted', 'to' => $to];
    if (!empty($p['cost_center_id'])) $base['cost_center_id'] = (int)$p['cost_center_id'];
    $lines = pb_st_assign($pdo, $cid, 'bs', pb_st_sums($pdo, $cid, $customer, $base, ['asset', 'liability', 'equity']));
    $fyStart = (string)$fy['start_date'];
    $cur = pb_st_income_calc($pdo, $cid, $customer, $base + ['from' => $fyStart]);
    $all = pb_st_income_calc($pdo, $cid, $customer, $base);
    $curNet = $cur['net_profit'];
    $prior = $all['net_profit'] - $curNet; // all periods up to `to` minus current year (no closing entries yet)
    $A = pb_st_sum_kind($lines, 'asset');
    $L = pb_st_sum_kind($lines, 'liability');
    $E = pb_st_sum_kind($lines, 'equity');
    return ['fiscal_year' => ['id' => (int)$fy['id'], 'name' => (string)$fy['name']], 'as_of' => $to, 'unit' => 'rial', 'status_filter' => (string)($p['status'] ?? 'posted'), 'lines' => $lines,
        'totals' => ['assets' => $A, 'liabilities' => $L, 'equity' => $E, 'prior_years_result' => $prior, 'current_year_result' => $curNet, 'liab_equity_result' => $L + $E + $prior + $curNet],
        'check' => ['balanced' => $A === $L + $E + $prior + $curNet]];
}

function pb_st_cc_pnl(PDO $pdo, int $cid, array $customer, array $p): array {
    $fy = pb_st_fy($pdo, $cid, $customer, $p);
    $q = $p;
    unset($q['fiscal_year_id']);
    if (empty($q['from'])) $q['from'] = (string)$fy['start_date'];
    if (empty($q['to'])) $q['to'] = (string)$fy['end_date'];
    [$vw, $va] = pb_lr_vconds($cid, $customer, $q, true);
    $st = $pdo->prepare("SELECT l.cost_center_id, a.type, COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id
        JOIN accounts a ON a.id=l.account_id AND a.customer_id=l.customer_id WHERE " . implode(' AND ', $vw) . " AND a.type IN ('revenue','expense') GROUP BY l.cost_center_id, a.type");
    $st->execute($va);
    $m = [];
    foreach ($st->fetchAll() as $r) {
        $k = $r['cost_center_id'] === null ? 0 : (int)$r['cost_center_id'];
        if (!isset($m[$k])) $m[$k] = ['cost_center_id' => $k ?: null, 'name' => $k ? '#' . $k : 'بدون مرکز هزینه', 'revenue' => 0, 'expense' => 0];
        if ($r['type'] === 'revenue') $m[$k]['revenue'] += (int)$r['c'] - (int)$r['d'];
        else $m[$k]['expense'] += (int)$r['d'] - (int)$r['c'];
    }
    if (pb_lr_has_cc($pdo) && count($m) > 0) {
        $ids = array_filter(array_keys($m));
        if ($ids) {
            $q2 = $pdo->prepare('SELECT id, code, name FROM ent_cost_centers WHERE customer_id=? AND id IN (' . pb_lr_ints($ids) . ')');
            $q2->execute([$cid]);
            foreach ($q2->fetchAll() as $c) $m[(int)$c['id']]['name'] = trim($c['code'] . ' ' . $c['name']);
        }
    }
    $rows = [];
    $tr = $te = 0;
    foreach ($m as $r) { $r['net'] = $r['revenue'] - $r['expense']; $tr += $r['revenue']; $te += $r['expense']; $rows[] = $r; }
    usort($rows, static function ($x, $y) { return strcmp((string)$x['name'], (string)$y['name']); });
    return ['fiscal_year' => ['id' => (int)$fy['id'], 'name' => (string)$fy['name']], 'from' => $q['from'], 'to' => $q['to'], 'unit' => 'rial', 'rows' => $rows,
        'totals' => ['revenue' => $tr, 'expense' => $te, 'net' => $tr - $te]];
}

function pb_st_instant(PDO $pdo, int $cid, array $customer): array {
    $r = pb_st_income($pdo, $cid, $customer, ['status' => 'posted']);
    return ['fiscal_year' => $r['fiscal_year'], 'from' => $r['from'], 'to' => $r['to'], 'unit' => 'rial', 'revenue' => $r['total_revenue'], 'expense' => $r['total_expense'],
        'gross_profit' => $r['gross_profit'], 'net_profit' => $r['net_profit']];
}

function pb_st_map_save(PDO $pdo, int $cid, array $p): array {
    $lines = $p['lines'] ?? null;
    if (!is_array($lines)) bad('داده نامعتبر است');
    $up = $pdo->prepare('UPDATE statement_lines SET label=?, prefixes=? WHERE id=? AND customer_id=?');
    foreach ($lines as $l) {
        $label = trim((string)($l['label'] ?? ''));
        $pf = preg_replace('/[^0-9,]/', '', (string)($l['prefixes'] ?? ''));
        if ($label === '') bad('عنوان سطر خالی است');
        $up->execute([mb_substr($label, 0, 120), mb_substr((string)$pf, 0, 200), (int)($l['id'] ?? 0), $cid]);
    }
    return pb_st_map_get($pdo, $cid);
}

function pb_st_map_get(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT id, statement, line_key, label, kind, section, sort_no, prefixes FROM statement_lines WHERE customer_id=? ORDER BY statement, sort_no');
    $st->execute([$cid]);
    return ['items' => $st->fetchAll()];
}

function pb_st_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_acc_seed($pdo, $cid);
    pb_voucher_ensure($pdo);
    pb_st_seed($pdo, $cid);
    switch ($action) {
        case 'reports.income': out(pb_st_income($pdo, $cid, $customer, $p));
        case 'reports.balance': out(pb_st_balance($pdo, $cid, $customer, $p));
        case 'reports.cc_pnl': out(pb_st_cc_pnl($pdo, $cid, $customer, $p));
        case 'reports.instant': out(pb_st_instant($pdo, $cid, $customer));
        case 'reports.margin': require_once __DIR__ . '/margin.php'; out(pb_mg_report($pdo, $cid, $customer, $p));
        case 'statements.map': out(pb_st_map_get($pdo, $cid));
        case 'statements.map_save': out(pb_st_map_save($pdo, $cid, $p));
    }
    bad('عملیات نامعتبر', 404);
}
