<?php
declare(strict_types=1);
/**
 * S17.T17.2 — VAT report (GOLD). Actions: reports.vat | vat.settle
 *
 * reports.vat {period: month|quarter|year|custom, jy, m (1-12), q (1-4), from, to (Y-m-d, custom), status: all|posted, csv?:1}
 *   Amounts are integer RIAL (stored toman x pb_posting_unit_mult()). Two independent views that must agree:
 *   - LEDGER: movement of the accounts mapped to roles vat_out / vat_in (voucher_lines), split by source_type, settlement
 *     vouchers (source_type 'vat_settle') kept apart from activity; opening/closing balances read straight from the ledger.
 *   - DOCUMENTS: sales / sale_returns / purchases / purchase_returns of the period grouped by tax status
 *     (taxable | exempt | zero | unspecified = legacy document without a stored choice) with base and tax per document.
 *   check.* compares them (docs vs the ledger lines posted from the same document types). Manual vouchers on VAT accounts are shown as
 *   `manual` and never counted as a difference.
 * vat.settle {same period params, date?, status?: temporary|final}  optional settlement voucher for the period:
 *   offsets min(output, input) activity: Dr vat_out / Cr vat_in. What is left stays on vat_out (payable) or vat_in (refundable).
 *   Idempotent per period (source_id = "from..to"): re-running replaces the voucher lines.
 * Statutory rates are data (fiscal_years) — nothing hard-coded here.
 */
require_once __DIR__ . '/jdate.php';
require_once __DIR__ . '/tax.php';

const PB_VAT_DOC_TYPES = ['sale', 'sreturn', 'purchase', 'preturn'];
const PB_VAT_SETTLE = 'vat_settle';
const PB_VAT_DETAIL_LIMIT = 5000;

function pb_vat_date(string $s, string $what): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) bad($what . ' نامعتبر است');
    return $s;
}

/** @return array{0:string,1:string,2:string} [from, to, label] (Gregorian Y-m-d) */
function pb_vat_period(array $p): array {
    $kind = (string)($p['period'] ?? 'year');
    $jy = (int)($p['jy'] ?? 0);
    if ($jy <= 0) $jy = pb_jyear_of(date('Y-m-d'));
    if ($jy < 1300 || $jy > 1600) bad('سال نامعتبر است');
    if ($kind === 'custom') {
        $from = pb_vat_date((string)($p['from'] ?? ''), 'تاریخ شروع');
        $to = pb_vat_date((string)($p['to'] ?? ''), 'تاریخ پایان');
        if ($from > $to) bad('تاریخ شروع بعد از پایان است');
        return [$from, $to, $from . ' تا ' . $to];
    }
    if ($kind === 'month' || $kind === 'quarter') {
        if ($kind === 'month') { $m1 = (int)($p['m'] ?? 0); $m2 = $m1; if ($m1 < 1 || $m1 > 12) bad('ماه نامعتبر است'); $label = "ماه $m1 سال $jy"; }
        else { $q = (int)($p['q'] ?? 0); if ($q < 1 || $q > 4) bad('فصل نامعتبر است'); $m1 = $q * 3 - 2; $m2 = $q * 3; $label = "فصل $q سال $jy"; }
        $from = pb_j2g_str($jy, $m1, 1);
        $next = $m2 === 12 ? pb_j2g_str($jy + 1, 1, 1) : pb_j2g_str($jy, $m2 + 1, 1);
        return [$from, date('Y-m-d', strtotime($next . ' -1 day')), $label];
    }
    [$s, $e] = pb_jyear_bounds($jy);
    return [$s, $e, "سال $jy"];
}

function pb_vat_status_sql(array $p): string {
    return (((string)($p['status'] ?? 'all') === 'posted') ? " AND v.status<>'draft'" : '') . " AND v.source_type NOT IN ('fy_open','fy_close')"; // T19.2b: carry vouchers are not VAT activity
}

/** [role => [source_type => [debit, credit]]] for movements inside [from,to]. */
function pb_vat_ledger_moves(PDO $pdo, int $cid, string $from, string $to, string $stSql): array {
    $q = $pdo->prepare("SELECT r.role, v.source_type, COALESCE(SUM(l.debit),0) AS d, COALESCE(SUM(l.credit),0) AS c
        FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id
        JOIN account_roles r ON r.account_id=l.account_id AND r.customer_id=l.customer_id
        WHERE l.customer_id=? AND r.role IN ('vat_out','vat_in') AND v.voucher_date>=? AND v.voucher_date<=?$stSql GROUP BY r.role, v.source_type");
    $q->execute([$cid, $from, $to]);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[(string)$r['role']][(string)$r['source_type']] = [(int)$r['d'], (int)$r['c']];
    return $out;
}

/** Natural balance of a VAT role up to (and including, or excluding) a date: vat_out = credit-debit, vat_in = debit-credit. */
function pb_vat_ledger_balance(PDO $pdo, int $cid, string $role, string $date, bool $inclusive, string $stSql): int {
    $op = $inclusive ? '<=' : '<';
    $q = $pdo->prepare("SELECT COALESCE(SUM(l.debit),0) AS d, COALESCE(SUM(l.credit),0) AS c
        FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id
        JOIN account_roles r ON r.account_id=l.account_id AND r.customer_id=l.customer_id
        WHERE l.customer_id=? AND r.role=? AND v.voucher_date $op ?$stSql");
    $q->execute([$cid, $role, $date]);
    $r = $q->fetch();
    return $role === 'vat_out' ? (int)$r['c'] - (int)$r['d'] : (int)$r['d'] - (int)$r['c'];
}

/** Per-role activity numbers (natural sign: output = credit-debit, input = debit-credit). */
function pb_vat_activity(array $moves, string $role): array {
    $act = 0; $settle = 0; $manual = 0; $bySrc = [];
    foreach ($moves[$role] ?? [] as $src => [$d, $c]) {
        $v = $role === 'vat_out' ? $c - $d : $d - $c;
        if ($src === PB_VAT_SETTLE) { $settle += $v; continue; }
        $act += $v;
        if ($src === 'manual') $manual += $v;
        $bySrc[$src] = $v;
    }
    $docs = 0;
    foreach (PB_VAT_DOC_TYPES as $t) $docs += $bySrc[$t] ?? 0;
    return ['activity' => $act, 'settled' => $settle, 'manual' => $manual, 'from_documents' => $docs, 'by_source' => $bySrc];
}

/** Sum of pb_pl_rial(qty*price) per item row (same per-item rounding as the posting loaders). */
function pb_vat_items_rial(PDO $pdo, string $sql, array $args): int {
    $q = $pdo->prepare($sql);
    $q->execute($args);
    $t = 0;
    foreach ($q->fetchAll() as $r) $t += pb_pl_rial((float)$r['q'] * (float)$r['p']);
    return $t;
}

/** Documents of the period with base + tax (rial). Returns [rows, totals]. */
function pb_vat_documents(PDO $pdo, int $cid, string $from, string $to): array {
    $rows = [];
    $rate = static function (string $date) use ($pdo, $cid): int { return pb_pl_vat_milli($pdo, $cid, $date); };
    $legacyTax = static function (int $base, string $date) use ($rate): int { return pb_tax_div_round($base * $rate($date), 100000); };

    $sq = $pdo->prepare('SELECT s.id, s.date, s.buyer, s.discount, s.tax_status, s.total_before_tax, s.total_tax
        FROM sales s WHERE s.customer_id=? AND s.date>=? AND s.date<=? ORDER BY s.date, s.id');
    $sq->execute([$cid, $from, $to]);
    foreach ($sq->fetchAll() as $d) {
        $has = $d['total_tax'] !== null;
        $base = $d['total_before_tax'] !== null ? pb_pl_rial($d['total_before_tax']) : max(0, pb_vat_items_rial($pdo, 'SELECT weight AS q, item_price AS p FROM sale_items WHERE sale_id=? AND customer_id=?', [(int)$d['id'], $cid]) - pb_pl_rial($d['discount'] ?? 0));
        $tax = $has ? pb_pl_rial($d['total_tax']) : $legacyTax($base, (string)$d['date']);
        $rows[] = ['type' => 'sale', 'id' => (int)$d['id'], 'date' => (string)$d['date'], 'party' => (string)($d['buyer'] ?? ''), 'status' => $d['tax_status'] ?: 'unspecified', 'base' => $base, 'tax' => $tax];
    }
    $rq = $pdo->prepare('SELECT r.id, r.date, r.party, r.tax_amount
        FROM sale_returns r WHERE r.customer_id=? AND r.date>=? AND r.date<=? ORDER BY r.date, r.id');
    $rq->execute([$cid, $from, $to]);
    foreach ($rq->fetchAll() as $d) {
        $base = pb_vat_items_rial($pdo, 'SELECT kg AS q, unit_price AS p FROM sale_return_items WHERE return_id=? AND customer_id=?', [(int)$d['id'], $cid]);
        $tax = $d['tax_amount'] !== null ? pb_pl_rial($d['tax_amount']) : $legacyTax($base, (string)$d['date']);
        $rows[] = ['type' => 'sreturn', 'id' => (int)$d['id'], 'date' => (string)$d['date'], 'party' => (string)($d['party'] ?? ''), 'status' => $tax > 0 || $d['tax_amount'] === null ? 'taxable' : 'exempt', 'base' => -$base, 'tax' => -$tax];
    }
    $pq = $pdo->prepare('SELECT id, date, seller, price, net_weight, discount, tax_status, total_before_tax, total_tax FROM purchases WHERE customer_id=? AND is_mix=0 AND date>=? AND date<=? ORDER BY date, id');
    $pq->execute([$cid, $from, $to]);
    foreach ($pq->fetchAll() as $d) {
        $has = $d['total_tax'] !== null;
        $base = $d['total_before_tax'] !== null ? pb_pl_rial($d['total_before_tax']) : max(0, pb_pl_rial((float)$d['price'] * (float)$d['net_weight']) - pb_pl_rial($d['discount'] ?? 0));
        $tax = $has ? pb_pl_rial($d['total_tax']) : $legacyTax($base, (string)$d['date']);
        $rows[] = ['type' => 'purchase', 'id' => (int)$d['id'], 'date' => (string)$d['date'], 'party' => (string)($d['seller'] ?? ''), 'status' => $d['tax_status'] ?: 'unspecified', 'base' => $base, 'tax' => $tax];
    }
    $rq = $pdo->prepare('SELECT r.id, r.date, r.party, r.tax_amount
        FROM purchase_returns r WHERE r.customer_id=? AND r.date>=? AND r.date<=? ORDER BY r.date, r.id');
    $rq->execute([$cid, $from, $to]);
    foreach ($rq->fetchAll() as $d) {
        $base = pb_vat_items_rial($pdo, 'SELECT kg AS q, unit_price AS p FROM purchase_return_items WHERE return_id=? AND customer_id=?', [(int)$d['id'], $cid]);
        $tax = $d['tax_amount'] !== null ? pb_pl_rial($d['tax_amount']) : $legacyTax($base, (string)$d['date']);
        $rows[] = ['type' => 'preturn', 'id' => (int)$d['id'], 'date' => (string)$d['date'], 'party' => (string)($d['party'] ?? ''), 'status' => $tax > 0 || $d['tax_amount'] === null ? 'taxable' : 'exempt', 'base' => -$base, 'tax' => -$tax];
    }
    return $rows;
}

/** Sums documents by side (sales|purchases) and tax status. */
function pb_vat_doc_totals(array $docs): array {
    $t = ['sales' => ['taxable' => 0, 'exempt' => 0, 'zero' => 0, 'unspecified' => 0, 'tax' => 0, 'count' => 0],
          'purchases' => ['taxable' => 0, 'exempt' => 0, 'zero' => 0, 'unspecified' => 0, 'tax' => 0, 'count' => 0]];
    foreach ($docs as $d) {
        $side = in_array($d['type'], ['sale', 'sreturn'], true) ? 'sales' : 'purchases';
        $st = isset($t[$side][$d['status']]) ? $d['status'] : 'unspecified';
        $t[$side][$st] += (int)$d['base'];
        $t[$side]['tax'] += (int)$d['tax'];
        $t[$side]['count']++;
    }
    return $t;
}

function pb_vat_csv(array $rep): string {
    $esc = static function ($v): string { $s = (string)$v; return (strpbrk($s, ",\"\n\r") !== false) ? '"' . str_replace('"', '""', $s) . '"' : $s; };
    $lines = [];
    $lines[] = implode(',', array_map($esc, ['type', 'id', 'date', 'party', 'tax_status', 'base_rial', 'tax_rial']));
    foreach ($rep['documents'] as $d) $lines[] = implode(',', array_map($esc, [$d['type'], $d['id'], $d['date'], $d['party'], $d['status'], $d['base'], $d['tax']]));
    $lines[] = '';
    $lines[] = implode(',', array_map($esc, ['summary', 'output_vat', 'input_vat', 'net_payable']));
    $lines[] = implode(',', array_map($esc, ['ledger', $rep['ledger']['output']['activity'], $rep['ledger']['input']['activity'], $rep['summary']['net_payable']]));
    return implode("\r\n", $lines) . "\r\n";
}

function pb_vat_report(PDO $pdo, array $customer, array $p): array {
    $cid = (int)$customer['id'];
    [$from, $to, $label] = pb_vat_period($p);
    $stSql = pb_vat_status_sql($p);
    $warnings = [];
    $miss = pb_acc_roles_missing($pdo, $cid, ['vat_out', 'vat_in']);
    if ($miss) $warnings[] = ['code' => 'roles_missing', 'detail' => 'حساب نقش‌های مالیات تنظیم نشده: ' . implode('، ', $miss)];

    $moves = pb_vat_ledger_moves($pdo, $cid, $from, $to, $stSql);
    $ledger = [];
    foreach (['output' => 'vat_out', 'input' => 'vat_in'] as $key => $role) {
        $a = pb_vat_activity($moves, $role);
        $a['opening'] = pb_vat_ledger_balance($pdo, $cid, $role, $from, false, $stSql);
        $a['closing'] = pb_vat_ledger_balance($pdo, $cid, $role, $to, true, $stSql);
        $a['closing_check'] = $a['opening'] + $a['activity'] + $a['settled'] === $a['closing']; // movement reproduces the ledger balance
        $ledger[$key] = $a;
    }
    $docs = pb_vat_documents($pdo, $cid, $from, $to);
    $dt = pb_vat_doc_totals($docs);

    // line-level detail of the VAT lines themselves (voucher view)
    $dq = $pdo->prepare("SELECT v.id, v.number, v.voucher_date, v.status, v.source_type, v.source_id, v.description, r.role, l.debit, l.credit
        FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id
        JOIN account_roles r ON r.account_id=l.account_id AND r.customer_id=l.customer_id
        WHERE l.customer_id=? AND r.role IN ('vat_out','vat_in') AND v.voucher_date>=? AND v.voucher_date<=?$stSql
        ORDER BY v.voucher_date, v.id, l.line_no LIMIT " . PB_VAT_DETAIL_LIMIT);
    $dq->execute([$cid, $from, $to]);
    $lines = [];
    foreach ($dq->fetchAll() as $r) {
        $amt = $r['role'] === 'vat_out' ? (int)$r['credit'] - (int)$r['debit'] : (int)$r['debit'] - (int)$r['credit'];
        $lines[] = ['voucher_id' => (int)$r['id'], 'number' => $r['number'] === null ? null : (int)$r['number'], 'date' => (string)$r['voucher_date'], 'status' => (string)$r['status'],
            'source_type' => (string)$r['source_type'], 'source_id' => $r['source_id'], 'description' => (string)($r['description'] ?? ''), 'side' => $r['role'] === 'vat_out' ? 'output' : 'input', 'amount' => $amt];
    }

    $outV = $ledger['output']['activity']; $inV = $ledger['input']['activity'];
    $net = $outV - $inV;
    $check = ['output_docs_vs_ledger' => $dt['sales']['tax'] - $ledger['output']['from_documents'], 'input_docs_vs_ledger' => $dt['purchases']['tax'] - $ledger['input']['from_documents']];
    $check['ok'] = $check['output_docs_vs_ledger'] === 0 && $check['input_docs_vs_ledger'] === 0 && $ledger['output']['closing_check'] && $ledger['input']['closing_check'];
    if ($check['output_docs_vs_ledger'] !== 0 || $check['input_docs_vs_ledger'] !== 0) $warnings[] = ['code' => 'docs_vs_ledger', 'detail' => 'مالیات اسناد با دفتر کل برابر نیست: اسناد بدون سند حسابداری، ثبت نشده یا نیازمند بازسازی (accounting.rebuild)'];
    if (($dt['sales']['unspecified'] ?? 0) !== 0 || ($dt['purchases']['unspecified'] ?? 0) !== 0) $warnings[] = ['code' => 'unspecified_status', 'detail' => 'اسناد بدون وضعیت مالیاتی (قدیمی) با نرخ سال مالی محاسبه شده‌اند'];

    $rep = ['period' => ['from' => $from, 'to' => $to, 'label' => $label], 'unit' => 'rial', 'status_filter' => (string)($p['status'] ?? 'all') === 'posted' ? 'posted' : 'all',
        'summary' => ['output_vat' => $outV, 'input_vat' => $inV, 'net_payable' => $net, 'net_refundable' => $net < 0 ? -$net : 0, 'payable' => $net > 0 ? $net : 0,
            'taxable_sales' => $dt['sales']['taxable'], 'exempt_sales' => $dt['sales']['exempt'], 'zero_rated_sales' => $dt['sales']['zero'], 'unspecified_sales' => $dt['sales']['unspecified'],
            'taxable_purchases' => $dt['purchases']['taxable'], 'exempt_purchases' => $dt['purchases']['exempt'], 'zero_rated_purchases' => $dt['purchases']['zero'], 'unspecified_purchases' => $dt['purchases']['unspecified'],
            'settled_output' => $ledger['output']['settled'], 'settled_input' => $ledger['input']['settled']],
        'ledger' => $ledger, 'documents_totals' => $dt, 'documents' => $docs, 'lines' => $lines, 'lines_truncated' => count($lines) >= PB_VAT_DETAIL_LIMIT,
        'check' => $check, 'warnings' => $warnings];
    if (!empty($p['csv'])) $rep['csv'] = pb_vat_csv($rep);
    return $rep;
}

/** Optional settlement voucher: offsets min(output, input) of the period (see header). */
function pb_vat_settle(PDO $pdo, array $customer, array $p): array {
    $cid = (int)$customer['id'];
    [$from, $to, $label] = pb_vat_period($p);
    $miss = pb_acc_roles_missing($pdo, $cid, ['vat_out', 'vat_in']);
    if ($miss) bad('حساب نقش‌های مالیات تنظیم نشده: ' . implode('، ', $miss));
    $stSql = pb_vat_status_sql($p);
    $moves = pb_vat_ledger_moves($pdo, $cid, $from, $to, $stSql);
    $outV = pb_vat_activity($moves, 'vat_out')['activity'];
    $inV = pb_vat_activity($moves, 'vat_in')['activity'];
    $amount = min($outV, $inV);
    $srcId = $from . '..' . $to;
    $ex = $pdo->prepare('SELECT id, status FROM vouchers WHERE customer_id=? AND source_type=? AND source_id=? LIMIT 1');
    $ex->execute([$cid, PB_VAT_SETTLE, $srcId]);
    $old = $ex->fetch();
    if ($amount <= 0) bad('برای این دوره مالیات قابل تسویه (هم فروش و هم خرید) وجود ندارد');
    $acc = $pdo->prepare('SELECT role, account_id FROM account_roles WHERE customer_id=? AND role IN (\'vat_out\',\'vat_in\')');
    $acc->execute([$cid]);
    $ids = [];
    foreach ($acc->fetchAll() as $r) $ids[(string)$r['role']] = (int)$r['account_id'];
    $date = isset($p['date']) && $p['date'] !== '' ? pb_vat_date((string)$p['date'], 'تاریخ سند') : $to;
    $status = $old ? (string)$old['status'] : ((string)($p['status'] ?? 'temporary') === 'final' ? 'final' : 'temporary');
    $lines = [
        ['account_id' => $ids['vat_out'], 'debit' => $amount, 'credit' => 0, 'description' => 'تسویه مالیات فروش با خرید'],
        ['account_id' => $ids['vat_in'], 'debit' => 0, 'credit' => $amount, 'description' => 'تسویه مالیات خرید با فروش'],
    ];
    $vid = pb_voucher_write($pdo, $customer, ['date' => $date, 'status' => $status, 'source_type' => PB_VAT_SETTLE, 'source_id' => $srcId,
        'description' => 'تسویه مالیات بر ارزش افزوده ' . $label], $lines, $old ? (int)$old['id'] : 0);
    return ['voucher_id' => $vid, 'amount' => $amount, 'replaced' => (bool)$old, 'net_after' => ['payable' => max(0, $outV - $amount), 'refundable' => max(0, $inV - $amount)], 'period' => ['from' => $from, 'to' => $to]];
}

/** Integrity issues for accounting.integrity: all-time docs vs VAT ledger lines of the same document types. */
function pb_vat_integrity(PDO $pdo, array $customer): array {
    $cid = (int)$customer['id'];
    $issues = [];
    $from = '1900-01-01'; $to = '2999-12-31';
    $moves = pb_vat_ledger_moves($pdo, $cid, $from, $to, '');
    $dt = pb_vat_doc_totals(pb_vat_documents($pdo, $cid, $from, $to));
    $o = pb_vat_activity($moves, 'vat_out')['from_documents'];
    $i = pb_vat_activity($moves, 'vat_in')['from_documents'];
    if ($dt['sales']['tax'] !== $o) $issues[] = ['code' => 'vat_output_mismatch', 'level' => 'warning', 'detail' => 'مالیات فروش اسناد ' . $dt['sales']['tax'] . ' ≠ دفتر ' . $o];
    if ($dt['purchases']['tax'] !== $i) $issues[] = ['code' => 'vat_input_mismatch', 'level' => 'warning', 'detail' => 'مالیات خرید اسناد ' . $dt['purchases']['tax'] . ' ≠ دفتر ' . $i];
    return $issues;
}

function pb_vat_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_voucher_ensure($pdo);
    if ($action === 'reports.vat') out(pb_vat_report($pdo, $customer, $p));
    if ($action === 'vat.settle') out(pb_vat_settle($pdo, $customer, $p));
    bad('عملیات نامعتبر', 404);
}
