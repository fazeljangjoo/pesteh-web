<?php
/**
 * S03.T03.2 — Party statement (صورت‌حساب طرف‌حساب) + aging (سن بدهی/مطالبات).
 *
 * parties.statement {party, from?, to?}
 *   -> {party, opening, brought_forward, rows[], total_debit, total_credit, closing, closing_without_opening, side}
 * parties.aging {as_of?, party?}
 *   -> {as_of, debtors[], creditors[], totals{debtors{..}, creditors{..}}}
 *
 * Sign convention (same as parties.list): balance > 0  => party owes us (بدهکار), balance < 0 => we owe party (بستانکار).
 * debit  = increases what the party owes us (sale, repay_out, loan given, ...); credit = decreases it (purchase, receipt, ...).
 * Closing balance == parties.list balanceWithOpening (same formulas and the same float arithmetic, no rounding).
 * Returns (S04) plug in through pb_party_event_hooks(): each hook is fn(PDO,int $cid): array of events.
 * Event shape: ['key','date','type','ref','label','effect'(+debit/-credit),'doc_id','ord'].
 */

/** Extension point for returns & other future document types (S04). Return a list of callables. */
function pb_party_event_hooks(): array { // S04.T04.1 / T04.2
    $h = [];
    if (function_exists('pb_preturn_party_events')) $h[] = 'pb_preturn_party_events';
    if (function_exists('pb_sreturn_party_events')) $h[] = 'pb_sreturn_party_events';
    if (function_exists('pb_broker_party_events')) $h[] = 'pb_broker_party_events'; // S05.T05.1
    if (function_exists('pb_proc_party_events')) $h[] = 'pb_proc_party_events'; // S09.T09.2 processing fees
    return $h;
}

/** All open/paid document data of a tenant, grouped by normalized party key. */
function pb_party_collect(PDO $pdo, int $cid): array {
    $docs = []; // ['p'.id | 's'.id => doc]
    $st = $pdo->prepare("SELECT id, seller AS party, date, type_name AS label, net_weight AS net, price, ccy, price_ccy FROM purchases WHERE customer_id=? AND is_mix=0");
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        $docs['p' . (int)$r['id']] = ['kind' => 'purchase', 'id' => (int)$r['id'], 'key' => partyKey($r['party']), 'date' => (string)$r['date'],
            'label' => 'خرید ' . (string)$r['label'], 'total' => (float)$r['net'] * (float)$r['price'], 'settled' => 0.0, 'unpaid' => [],
            'ccy' => ($r['ccy'] ?? '') === '' ? null : (string)$r['ccy'], 'ccy_amt' => ($r['ccy'] ?? '') === '' ? null : round((float)$r['net'] * (float)$r['price_ccy'], 4)]; // S28.T28.3
    }
    $st = $pdo->prepare("SELECT id, buyer AS party, date, net_weight AS net, price, ccy, total_ccy FROM sales WHERE customer_id=?");
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        $docs['s' . (int)$r['id']] = ['kind' => 'sale', 'id' => (int)$r['id'], 'key' => partyKey($r['party']), 'date' => (string)$r['date'],
            'label' => 'فروش', 'total' => (float)$r['net'] * (float)$r['price'], 'settled' => 0.0, 'unpaid' => [],
            'ccy' => ($r['ccy'] ?? '') === '' ? null : (string)$r['ccy'], 'ccy_amt' => ($r['ccy'] ?? '') === '' ? null : round((float)$r['total_ccy'], 4)]; // S28.T28.3
    }
    $events = [];
    // settlement rows: [table, header table, header fk, doc prefix, header type col, type value, staged value, date col, ref col, label]
    $src = [
        ['lump_tx', 'payments', 'payment_id', 'purchase_id', 'p', 'pay_type', 'lump', null, 'date', 'NULL', 'پرداخت یکجا'],
        ['credit_inst', 'payments', 'payment_id', 'purchase_id', 'p', 'pay_type', 'staged', 'credit', 'due_date', 'NULL', 'پرداخت قسط'],
        ['cheque_inst', 'payments', 'payment_id', 'purchase_id', 'p', 'pay_type', 'staged', 'cheque', 'due_date', 'x.cheque_no', 'پرداخت چک'],
        ['receipt_lump_tx', 'receipts', 'receipt_id', 'sale_id', 's', 'recv_type', 'lump', null, 'date', 'NULL', 'دریافت یکجا'],
        ['receipt_credit_inst', 'receipts', 'receipt_id', 'sale_id', 's', 'recv_type', 'staged', 'credit', 'due_date', 'NULL', 'دریافت قسط'],
        ['receipt_cheque_inst', 'receipts', 'receipt_id', 'sale_id', 's', 'recv_type', 'staged', 'cheque', 'due_date', 'x.cheque_no', 'دریافت چک'],
    ];
    foreach ($src as [$tx, $hdr, $fk, $docCol, $pre, $typeCol, $typeVal, $stagedVal, $dateCol, $refExpr, $label]) {
        $sql = "SELECT h.$docCol AS doc, x.id, x.amount, x.$dateCol AS d, x.paid, x.ccy AS ccy, x.ccy_amount AS ccy_amount, $refExpr AS ref FROM $tx x JOIN $hdr h ON h.id = x.$fk AND h.customer_id = x.customer_id"
             . " WHERE x.customer_id = ? AND h.$typeCol = " . $pdo->quote($typeVal) . ($stagedVal ? " AND h.staged_type = " . $pdo->quote($stagedVal) : '');
        $q = $pdo->prepare($sql);
        $q->execute([$cid]);
        foreach ($q->fetchAll() as $r) {
            $k = $pre . (int)$r['doc'];
            if (!isset($docs[$k])) continue; // e.g. mix purchases are not part of the party account
            $amt = (float)$r['amount'];
            if ((int)$r['paid'] === 1) {
                $docs[$k]['settled'] += $amt;
                $events[] = ['key' => $docs[$k]['key'], 'date' => (string)$r['d'], 'type' => $pre === 'p' ? 'payment' : 'receipt', 'ref' => $r['ref'],
                    'label' => $label . ' — ' . $docs[$k]['label'], 'effect' => $pre === 'p' ? $amt : -$amt, 'doc_id' => (int)$r['doc'], 'ord' => 2,
                    'ccy' => ($r['ccy'] ?? '') === '' ? null : (string)$r['ccy'], 'ccy_amount' => ($r['ccy'] ?? '') === '' ? null : (float)$r['ccy_amount']];
            } else {
                $docs[$k]['unpaid'][] = ['date' => (string)$r['d'], 'amount' => $amt];
            }
        }
    }
    if (function_exists('pb_preturn_apply_docs')) pb_preturn_apply_docs($pdo, $cid, $docs); // S04.T04.1: adds docs[].ret (aging only)
    if (function_exists('pb_sreturn_apply_docs')) pb_sreturn_apply_docs($pdo, $cid, $docs); // S04.T04.2
    foreach ($docs as $d) {
        $events[] = ['key' => $d['key'], 'date' => $d['date'], 'type' => $d['kind'], 'ref' => null, 'label' => $d['label'],
            'effect' => $d['kind'] === 'sale' ? $d['total'] : -$d['total'], 'doc_id' => $d['id'], 'ord' => 1,
            'ccy' => $d['ccy'] ?? null, 'ccy_amount' => $d['ccy_amt'] ?? null];
    }
    // loans (given/repay_out increase what they owe us; taken/repay_in decrease)
    $loanDocs = []; // key|kind => ['sum'=>, 'first'=>date]
    $st = $pdo->prepare('SELECT id, uid, party, kind, amount, date, note FROM loan_entries WHERE customer_id=?');
    $st->execute([$cid]);
    $lbl = ['given' => 'قرض اعطایی', 'taken' => 'قرض دریافتی', 'repay_in' => 'بازپرداخت دریافتی از طرف‌حساب', 'repay_out' => 'بازپرداخت به طرف‌حساب'];
    $eff = ['given' => 1, 'repay_out' => 1, 'taken' => -1, 'repay_in' => -1];
    foreach ($st->fetchAll() as $r) {
        if (!isset($eff[$r['kind']])) continue;
        $k = partyKey($r['party']);
        $amt = (float)$r['amount'];
        $events[] = ['key' => $k, 'date' => (string)$r['date'], 'type' => 'loan_' . $r['kind'], 'ref' => null,
            'label' => $lbl[$r['kind']] . ($r['note'] ? ' — ' . $r['note'] : ''), 'effect' => $eff[$r['kind']] * $amt, 'doc_id' => (int)$r['id'], 'ord' => 3];
        $lk = $k . '|' . $r['kind'];
        if (!isset($loanDocs[$lk])) $loanDocs[$lk] = ['key' => $k, 'kind' => $r['kind'], 'sum' => 0.0, 'first' => (string)$r['date']];
        $loanDocs[$lk]['sum'] += $amt;
        if ((string)$r['date'] < $loanDocs[$lk]['first']) $loanDocs[$lk]['first'] = (string)$r['date'];
    }
    $loanDues = []; // key|kind => [unpaid dues]
    $st = $pdo->prepare('SELECT d.due_date, d.amount, d.paid, l.party, l.kind FROM loan_dues d JOIN loan_entries l ON l.uid = d.loan_uid AND l.customer_id = d.customer_id WHERE d.customer_id=?');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        if ((int)$r['paid'] === 1) continue;
        $loanDues[partyKey($r['party']) . '|' . $r['kind']][] = ['date' => (string)$r['due_date'], 'amount' => (float)$r['amount']];
    }
    foreach (pb_party_event_hooks() as $hook) {
        foreach ($hook($pdo, $cid) as $e) { $e['key'] = partyKey($e['key'] ?? ''); $events[] = $e; }
    }
    return ['docs' => array_values($docs), 'events' => $events, 'loanDocs' => $loanDocs, 'loanDues' => $loanDues];
}

/** Master (opening balance) of a party key: signed (+ debtor / − creditor). */
function pb_party_opening(PDO $pdo, int $cid, ?string $onlyKey = null): array {
    pb_party_sync($pdo, $cid);
    $st = $pdo->prepare('SELECT name, opening_balance, opening_side FROM parties WHERE customer_id=?');
    $st->execute([$cid]);
    $res = [];
    foreach ($st->fetchAll() as $r) {
        $k = partyKey($r['name']);
        if ($onlyKey !== null && $k !== $onlyKey) continue;
        $b = (int)$r['opening_balance'];
        $res[$k] = $r['opening_side'] === 'creditor' ? -$b : $b;
    }
    return $res;
}

/**
 * S28.T28.3 — THE party balance. Single source for statement + other reports.
 * $events: pb_party_collect()['events'] already filtered to one party (optionally date-limited by the caller).
 * Returns ['rial' => signed rial-equivalent total incl. opening (+ debtor / − creditor),
 *          'by_ccy' => [code => ['amount' => signed foreign amount, 'rial' => signed rial equivalent]]].
 * Events without ccy are rial events: they only count in 'rial' (and opening is rial).
 */
function pb_party_balance(array $events, float $opening = 0.0): array {
    $rial = $opening;
    $by = [];
    foreach ($events as $e) {
        $rial += (float)$e['effect'];
        $c = $e['ccy'] ?? null;
        if ($c === null || $c === '') continue;
        if (!isset($by[$c])) $by[$c] = ['amount' => 0.0, 'rial' => 0.0];
        $sign = (float)$e['effect'] < 0 ? -1 : 1;
        $by[$c]['amount'] += $sign * abs((float)($e['ccy_amount'] ?? 0));
        $by[$c]['rial'] += (float)$e['effect'];
    }
    ksort($by);
    return ['rial' => $rial, 'by_ccy' => $by];
}

/**
 * T30.3b — ONE entry point for "balance of party/parties" (event engine + opening). Reports must call this, not re-sum events.
 * $key = partyKey() of one party, or null for all parties. Returns [key => pb_party_balance() result].
 */
function pb_party_balances(PDO $pdo, int $cid, ?string $key = null): array {
    $open = pb_party_opening($pdo, $cid);
    $by = [];
    foreach (pb_party_collect($pdo, $cid)['events'] as $e) {
        if ($key !== null && $e['key'] !== $key) continue;
        $by[$e['key']][] = $e;
    }
    $keys = $key !== null ? [$key] : array_unique(array_merge(array_keys($open), array_keys($by)));
    $out = [];
    foreach ($keys as $k) $out[$k] = pb_party_balance($by[$k] ?? [], (float)($open[$k] ?? 0));
    return $out;
}
/** Ledger side (gold): AR/AP net per party name from voucher lines, same keys as pb_party_balances(). */
function pb_party_ledger_balances(PDO $pdo, int $cid): array {
    $q = $pdo->prepare("SELECT t.name, COALESCE(SUM(l.debit-l.credit),0) AS net FROM voucher_lines l JOIN account_roles r ON r.account_id=l.account_id AND r.customer_id=l.customer_id
        JOIN tafsili t ON t.id=l.tafsili_id AND t.customer_id=l.customer_id WHERE l.customer_id=? AND r.role IN ('ar','ap') GROUP BY t.id, t.name");
    $q->execute([$cid]);
    $bk = [];
    foreach ($q->fetchAll() as $r) { $k = partyKey($r['name']); $bk[$k] = ($bk[$k] ?? 0) + (int)$r['net']; }
    return $bk;
}

function pb_party_opt_date($v, string $label): ?string {
    if ($v === null || $v === '') return null;
    $v = (string)$v;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) bad($label . ' نامعتبر است');
    return $v;
}

function pb_party_statement(PDO $pdo, int $cid, array $p): void {
    $key = partyKey($p['party'] ?? '');
    if ($key === '' || mb_strlen($key) > 150) bad('نام طرف‌حساب را وارد کنید');
    $from = pb_party_opt_date($p['from'] ?? null, 'تاریخ شروع');
    $to = pb_party_opt_date($p['to'] ?? null, 'تاریخ پایان');
    if ($from !== null && $to !== null && $from > $to) bad('بازهٔ تاریخ نامعتبر است');
    $opening = pb_party_opening($pdo, $cid, $key)[$key] ?? 0;
    $ev = array_values(array_filter(pb_party_collect($pdo, $cid)['events'], fn($e) => $e['key'] === $key));
    $balances = pb_party_balance($ev, (float)$opening); // all time, all currencies (S28.T28.3); same math as pb_party_balances()
    $fccy = strtoupper(trim((string)($p['ccy'] ?? ''))); // '' = all, 'IRR' = rial events only, else that currency
    if ($fccy !== '') {
        $ev = array_values(array_filter($ev, fn($e) => $fccy === 'IRR' ? (($e['ccy'] ?? '') === '') : (($e['ccy'] ?? '') === $fccy)));
        if ($fccy !== 'IRR') $opening = 0; // opening balance is a rial figure
    }
    usort($ev, fn($a, $b) => [$a['date'], $a['ord'], $a['doc_id']] <=> [$b['date'], $b['ord'], $b['doc_id']]);
    $bf = (float)$opening;      // brought forward: opening + everything before $from
    $closingAll = (float)$opening;
    $rows = []; $td = 0.0; $tc = 0.0;
    $bal = null;
    foreach ($ev as $e) {
        $closingAll += $e['effect'];
        if ($from !== null && $e['date'] < $from) { $bf += $e['effect']; continue; }
        if ($to !== null && $e['date'] > $to) continue;
        if ($bal === null) $bal = $bf;
        $bal += $e['effect'];
        $debit = $e['effect'] > 0 ? $e['effect'] : 0.0;
        $credit = $e['effect'] < 0 ? -$e['effect'] : 0.0;
        $td += $debit; $tc += $credit;
        $rows[] = ['date' => $e['date'], 'type' => $e['type'], 'ref' => $e['ref'], 'label' => $e['label'], 'doc_id' => $e['doc_id'],
            'debit' => $debit, 'credit' => $credit, 'balance' => $bal,
            'ccy' => $e['ccy'] ?? null, 'ccy_amount' => $e['ccy_amount'] ?? null];
    }
    $closing = $bf + $td - $tc;
    out(['party' => $key, 'from' => $from, 'to' => $to, 'opening' => $opening, 'brought_forward' => $bf, 'rows' => $rows,
        'total_debit' => $td, 'total_credit' => $tc, 'closing' => $closing,
        'closing_without_opening' => $closing - $opening, 'balance_all_time' => $closingAll,
        'ccy_filter' => $fccy === '' ? null : $fccy, 'balances' => $balances,
        'side' => $closing > 0 ? 'debtor' : ($closing < 0 ? 'creditor' : 'settled')]);
}

/** Split outstanding amount over unpaid dated installments (earliest first); remainder (or overpay) sits on $docDate. */
function pb_aging_alloc(float $outstanding, array $unpaid, string $docDate): array {
    if (abs($outstanding) < 0.5) return [];
    if ($outstanding < 0) return [['date' => $docDate, 'amount' => $outstanding]];
    usort($unpaid, fn($a, $b) => strcmp($a['date'], $b['date']));
    $items = []; $rem = $outstanding;
    foreach ($unpaid as $u) {
        if ($rem <= 0.0) break;
        $t = min($rem, $u['amount']);
        if ($t > 0) { $items[] = ['date' => $u['date'], 'amount' => $t]; $rem -= $t; }
    }
    if ($rem > 0.5) $items[] = ['date' => $docDate, 'amount' => $rem];
    return $items;
}

function pb_party_aging(PDO $pdo, int $cid, array $p): void {
    $asOf = pb_party_opt_date($p['as_of'] ?? null, 'تاریخ گزارش') ?? today();
    $only = isset($p['party']) && trim((string)$p['party']) !== '' ? partyKey($p['party']) : null;
    $data = pb_party_collect($pdo, $cid);
    $asTs = strtotime($asOf . ' 00:00:00 UTC');
    $new = fn() => ['not_due' => 0.0, 'b0_30' => 0.0, 'b31_60' => 0.0, 'b61_90' => 0.0, 'b90p' => 0.0, 'opening' => 0.0, 'total' => 0.0];
    $acc = ['debtors' => [], 'creditors' => []];
    $add = function (string $side, string $key, string $date, float $amt, bool $isOpening = false) use (&$acc, $new, $asTs, $only) {
        if ($only !== null && $key !== $only) return;
        if (!isset($acc[$side][$key])) $acc[$side][$key] = ['party' => $key] + $new();
        $r = &$acc[$side][$key];
        $days = $isOpening ? 9999 : (int)floor(($asTs - strtotime($date . ' 00:00:00 UTC')) / 86400);
        if ($days <= 0) $r['not_due'] += $amt;
        if ($days <= 30) $r['b0_30'] += $amt; elseif ($days <= 60) $r['b31_60'] += $amt; elseif ($days <= 90) $r['b61_90'] += $amt; else $r['b90p'] += $amt;
        if ($isOpening) $r['opening'] += $amt;
        $r['total'] += $amt;
    };
    foreach ($data['docs'] as $d) {
        $items = pb_aging_alloc($d['total'] - $d['settled'] - ($d['ret'] ?? 0.0), $d['unpaid'], $d['date']);
        foreach ($items as $it) $add($d['kind'] === 'sale' ? 'debtors' : 'creditors', $d['key'], $it['date'], $it['amount']);
    }
    $sum = [];
    foreach ($data['loanDocs'] as $lk => $l) $sum[$l['key']][$l['kind']] = $l;
    foreach ($sum as $key => $kinds) {
        foreach (['given' => ['repay_in', 'debtors'], 'taken' => ['repay_out', 'creditors']] as $kind => [$rep, $side]) {
            $out = ($kinds[$kind]['sum'] ?? 0.0) - ($kinds[$rep]['sum'] ?? 0.0);
            $first = $kinds[$kind]['first'] ?? ($kinds[$rep]['first'] ?? $asOf);
            foreach (pb_aging_alloc($out, $data['loanDues'][$key . '|' . $kind] ?? [], $first) as $it) $add($side, $key, $it['date'], $it['amount']);
        }
    }
    // Opening balances: no due date => treated as oldest (90+ bucket), also reported in `opening`.
    foreach (pb_party_opening($pdo, $cid, $only) as $key => $o) {
        if ($o > 0) $add('debtors', $key, $asOf, (float)$o, true);
        elseif ($o < 0) $add('creditors', $key, $asOf, (float)-$o, true);
    }
    $res = [];
    foreach (['debtors', 'creditors'] as $side) {
        $rows = array_values(array_filter($acc[$side], fn($r) => abs($r['total']) >= 0.5));
        usort($rows, fn($a, $b) => abs($b['total']) <=> abs($a['total']));
        $tot = $new();
        foreach ($rows as $r) foreach ($tot as $f => $_) $tot[$f] += $r[$f];
        $res[$side] = $rows; $res['totals'][$side] = $tot;
    }
    out(['as_of' => $asOf, 'debtors' => $res['debtors'], 'creditors' => $res['creditors'], 'totals' => $res['totals']]);
}
