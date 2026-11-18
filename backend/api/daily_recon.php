<?php
declare(strict_types=1);
/**
 * S31.T31.4 — daily reconciliation dashboard (GOLD, read-only): action daily_recon.get {date?: 'YYYY-MM-DD' (default today)}.
 * Sections (each row: book = ledger, other = operational figure, diff, level ok|warning|error):
 *  - cash:   per cash-box tafsili: ledger balance up to date + that day's debit/credit; negative balance = warning.
 *  - bank:   per bank tafsili: ledger balance up to date vs imported statement balance (only when statement lines exist).
 *  - parties: AR/AP ledger per party vs party-statement engine (as of NOW), only differing parties listed (max 100).
 *  - inventory: inventory ledger vs lot valuation (as of NOW). cheques: ledger vs cheque register (as of NOW).
 *  - integrity: summary of accounting.integrity (errors/warnings + first 10 issues).
 * Balances use non-draft vouchers and ignore fy_open/fy_close only where integrity does (inventory/cheques); cash/bank include opening entries.
 */
const PB_DR_TOL = 1; // rial; rounding noise between stored unit * multiplier and integer ledger

function pb_dr_level(int $diff): string { return abs($diff) > PB_DR_TOL ? 'warning' : 'ok'; }

function pb_dr_role_net(PDO $pdo, int $cid, array $roles): int {
    $in = implode(',', array_fill(0, count($roles), '?'));
    $q = $pdo->prepare("SELECT COALESCE(SUM(l.debit-l.credit),0) FROM voucher_lines l JOIN account_roles r ON r.account_id=l.account_id AND r.customer_id=l.customer_id
        JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id WHERE l.customer_id=? AND r.role IN ($in) AND v.source_type NOT IN ('fy_open','fy_close')");
    $q->execute(array_merge([$cid], $roles));
    return (int)$q->fetchColumn();
}

function pb_dr_treasury(PDO $pdo, int $cid, string $kind, string $date): array {
    $q = $pdo->prepare("SELECT t.id, t.name, t.ref_key FROM tafsili t WHERE t.customer_id=? AND t.kind=? AND t.active=1 ORDER BY t.name");
    $q->execute([$cid, $kind]);
    $rows = [];
    $bal = $pdo->prepare("SELECT COALESCE(SUM(l.debit-l.credit),0) AS bal,
            COALESCE(SUM(CASE WHEN v.voucher_date=? THEN l.debit ELSE 0 END),0) AS dd, COALESCE(SUM(CASE WHEN v.voucher_date=? THEN l.credit ELSE 0 END),0) AS dc
        FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id WHERE l.customer_id=? AND l.tafsili_id=? AND v.status<>'draft' AND v.voucher_date<=?");
    $hasStmt = $kind === 'bank';
    foreach ($q->fetchAll() as $t) {
        $bal->execute([$date, $date, $cid, (int)$t['id'], $date]);
        $b = $bal->fetch();
        $row = ['name' => $t['name'], 'ref_key' => $t['ref_key'], 'book' => (int)$b['bal'], 'day_debit' => (int)$b['dd'], 'day_credit' => (int)$b['dc'], 'other' => null, 'diff' => 0, 'level' => 'ok', 'note' => ''];
        if ($kind === 'cash' && $row['book'] < 0) { $row['level'] = 'warning'; $row['note'] = 'مانده منفی صندوق'; }
        if ($hasStmt && $t['ref_key'] !== null) {
            $s = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(amount),0) FROM bank_stmt_lines WHERE customer_id=? AND bank_uid=? AND line_date<=?');
            $s->execute([$cid, $t['ref_key'], $date]);
            [$n, $sum] = array_map('intval', $s->fetch(PDO::FETCH_NUM));
            if ($n > 0) {
                $row['other'] = $sum; $row['diff'] = $sum - $row['book'];
                if (abs($row['diff']) > PB_DR_TOL) { $row['level'] = 'warning'; $row['note'] = 'مانده دفتر با صورت‌حساب بانک نمی‌خواند'; }
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

function pb_dr_parties(PDO $pdo, int $cid): array {
    $exp = [];
    foreach (pb_party_balances($pdo, $cid) as $k => $b) $exp[$k] = (int)pb_pl_rial((float)$b['rial']);
    $bk = pb_party_ledger_balances($pdo, $cid);
    $total = 0; $bad = []; $nbad = 0;
    foreach (array_unique(array_merge(array_keys($exp), array_keys($bk))) as $k) {
        $e = $exp[$k] ?? 0; $b = $bk[$k] ?? 0;
        if ($e === 0 && $b === 0) continue;
        $total++;
        if (abs($b - $e) > PB_DR_TOL) { $nbad++; if (count($bad) < 100) $bad[] = ['party' => $k, 'book' => $b, 'other' => $e, 'diff' => $b - $e]; }
    }
    return ['checked' => $total, 'mismatched' => $nbad, 'rows' => $bad];
}

function pb_dr_inventory(PDO $pdo, int $cid): array {
    $book = pb_dr_role_net($pdo, $cid, ['inventory', 'inventory_processed']);
    $lq = $pdo->prepare('SELECT id, remaining FROM purchases WHERE customer_id=? AND remaining>0 AND is_mix<>3');
    $lq->execute([$cid]);
    $val = 0;
    foreach ($lq->fetchAll() as $lot) {
        $l = pb_pl_lot($pdo, $cid, (int)$lot['id']);
        if ($l && $l[1] > 0) $val += pb_posting_divround(pb_posting_mul($l[0], (int)round((float)$lot['remaining'] * 1000)), $l[1]);
    }
    return ['book' => $book, 'other' => $val, 'diff' => $book - $val, 'level' => pb_dr_level($book - $val)];
}

function pb_dr_cheques(PDO $pdo, int $cid): array {
    $mult = pb_posting_unit_mult();
    $rows = [];
    foreach ([['cheque_in_hand', 'received', 'in_hand', 1], ['cheque_collect', 'received', 'deposited', 1], ['cheque_payable', 'issued', 'pending', -1]] as [$role, $dir, $st, $sign]) {
        $r = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM cheques WHERE customer_id=? AND direction=? AND status=?');
        $r->execute([$cid, $dir, $st]);
        $reg = (int)round((float)$r->fetchColumn() * $mult);
        $book = $sign * pb_dr_role_net($pdo, $cid, [$role]);
        $rows[] = ['role' => $role, 'book' => $book, 'other' => $reg, 'diff' => $book - $reg, 'level' => pb_dr_level($book - $reg)];
    }
    return $rows;
}

function pb_dr_get(PDO $pdo, array $customer, array $p): array {
    $cid = (int)$customer['id'];
    $date = isset($p['date']) && $p['date'] !== '' ? (string)$p['date'] : date('Y-m-d');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) bad('تاریخ نامعتبر است');
    $out = ['date' => $date, 'as_of_now_note' => $date !== date('Y-m-d') ? 'طرف‌حساب، موجودی انبار و چک‌ها همیشه تا لحظهٔ اجرا محاسبه می‌شوند' : ''];
    $sec = static function (string $name, callable $fn) use (&$out) {
        try { $out[$name] = $fn(); } catch (Throwable $e) { $out[$name] = ['error' => 'محاسبه ممکن نشد']; } // each section isolated
    };
    $sec('cash', fn() => pb_dr_treasury($pdo, $cid, 'cash', $date));
    $sec('bank', fn() => pb_dr_treasury($pdo, $cid, 'bank', $date));
    $sec('parties', fn() => pb_dr_parties($pdo, $cid));
    $sec('inventory', fn() => pb_dr_inventory($pdo, $cid));
    $sec('cheques', fn() => pb_dr_cheques($pdo, $cid));
    $sec('integrity', function () use ($pdo, $customer, $p) {
        $r = pb_acct_integrity($pdo, $customer, []);
        return ['clean' => $r['clean'], 'errors' => $r['errors'], 'warnings' => $r['warnings'], 'issues' => array_slice($r['issues'], 0, 10)];
    });
    // alerts: one flat list for the UI badge
    $alerts = [];
    foreach (['cash', 'bank'] as $k) foreach (($out[$k] ?? []) as $r) if (is_array($r) && ($r['level'] ?? '') !== 'ok') $alerts[] = ['section' => $k, 'title' => $r['name'], 'detail' => $r['note'], 'diff' => $r['diff']];
    if (($out['parties']['mismatched'] ?? 0) > 0) $alerts[] = ['section' => 'parties', 'title' => 'طرف‌حساب‌ها', 'detail' => $out['parties']['mismatched'] . ' طرف‌حساب با دفتر نمی‌خواند', 'diff' => 0];
    if (($out['inventory']['level'] ?? 'ok') !== 'ok') $alerts[] = ['section' => 'inventory', 'title' => 'موجودی انبار', 'detail' => 'دفتر با ارزش انبار نمی‌خواند', 'diff' => $out['inventory']['diff']];
    foreach (($out['cheques'] ?? []) as $r) if (is_array($r) && ($r['level'] ?? '') !== 'ok') $alerts[] = ['section' => 'cheques', 'title' => $r['role'], 'detail' => 'دفتر با دفتر چک نمی‌خواند', 'diff' => $r['diff']];
    if (($out['integrity']['errors'] ?? 0) > 0) $alerts[] = ['section' => 'integrity', 'title' => 'یکپارچگی حسابداری', 'detail' => $out['integrity']['errors'] . ' خطا', 'diff' => 0];
    $out['alerts'] = $alerts;
    $out['clean'] = count($alerts) === 0;
    return $out;
}

function pb_dr_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_voucher_ensure($pdo);
    if (function_exists('pb_bank_recon_ensure')) pb_bank_recon_ensure($pdo);
    out(pb_dr_get($pdo, $customer, $p));
}
