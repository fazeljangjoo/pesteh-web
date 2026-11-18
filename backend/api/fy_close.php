<?php
declare(strict_types=1);
/**
 * S19.T19.2 (part 1) — year-end: preview, closing entry, lock, reopen (GOLD, perm accounting.close). Actions (payload {id} = fiscal year id):
 *   fy.close_preview  blocking[] (draft vouchers, unbalanced vouchers, missing retained role) + warnings[] (stock reconcile mismatches, sales without voucher) + P&L result.
 *   fy.close          open -> closed. ONE final closing voucher at FY end (source_type 'fy_close', source_id = FY id): every revenue/expense account is zeroed,
 *                     the difference goes to role `retained` (P&L summary account is NOT used: DECISIONS_TAKEN). Idempotent (rewrites the same voucher), resumable.
 *   fy.lock           closed -> locked.     fy.reopen  closed|locked -> open, only for the LATEST closed/locked year; removes its closing voucher; audit-logged.
 *   fy.open_next      (T19.2b) closed|locked FY -> ONE final opening voucher (source_type 'fy_open', source_id = closed FY id) dated at the next FY start (created when missing):
 *                     every asset/liability/equity/memo balance of the closed FY per (account, tafsili[, ccy]) is carried over. Party balances = AR/AP tafsili lines, open cheques =
 *                     cheque-role accounts, inventory = inventory accounts (the lot subledger lives in purchases/stock_moves and is not FY-bound). Idempotent (rewrites the same
 *                     voucher) and VERIFIED: opening lines re-read from the DB must equal the prior closing balance sheet, else rollback (409).
 *   fy.open_undo      removes the opening voucher of the next year (needed before fy.reopen).
 * Carry vouchers (fy_open/fy_close) are excluded from all-years scopes (reports without fiscal_year_id: income statement, instant P&L, cumulative balance sheet, VAT, integrity checks),
 * and included in per-FY scopes (trial balance / ledger of one FY show opening + closing entries). See pb_lr_vconds.
 */
require_once __DIR__ . '/fiscal.php';
require_once __DIR__ . '/vouchers.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/posting.php';

function pb_fc_year(PDO $pdo, int $cid, array $customer, array $p): array {
    $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $fy = $st->fetch();
    if (!$fy) bad('سال مالی یافت نشد', 404);
    pb_fy_require($customer, (int)$fy['id']);
    return $fy;
}

/** [account_id, net(debit-credit)] of every revenue/expense account in the FY (non-draft, closing voucher excluded). */
function pb_fc_pl_nets(PDO $pdo, int $cid, int $fyId): array {
    $st = $pdo->prepare("SELECT l.account_id, COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS net FROM voucher_lines l
        JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id JOIN accounts a ON a.id=l.account_id AND a.customer_id=l.customer_id
        WHERE v.customer_id=? AND v.fiscal_year_id=? AND v.status<>'draft' AND v.source_type<>'fy_close' AND a.type IN ('revenue','expense')
        GROUP BY l.account_id HAVING net<>0");
    $st->execute([$cid, $fyId]);
    return $st->fetchAll();
}

/** Closing balance-sheet balances of one FY (draft excluded; includes its own fy_open/fy_close): key "acc:taf:ccy" => [account_id, tafsili_id, ccy, net(debit-credit), ccy_net]. */
function pb_fc_balances(PDO $pdo, int $cid, int $fyId): array {
    $st = $pdo->prepare("SELECT l.account_id, COALESCE(l.tafsili_id,0) AS taf, COALESCE(l.ccy,'') AS ccy, SUM(l.debit) - SUM(l.credit) AS net,
            SUM(CASE WHEN l.ccy IS NULL THEN 0 WHEN l.debit>0 THEN COALESCE(l.ccy_amount,0) ELSE -COALESCE(l.ccy_amount,0) END) AS cnet
        FROM voucher_lines l JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id JOIN accounts a ON a.id=l.account_id AND a.customer_id=l.customer_id
        WHERE v.customer_id=? AND v.fiscal_year_id=? AND v.status<>'draft' AND a.type IN ('asset','liability','equity','memo')
        GROUP BY l.account_id, COALESCE(l.tafsili_id,0), COALESCE(l.ccy,'') HAVING SUM(l.debit) - SUM(l.credit) <> 0");
    $st->execute([$cid, $fyId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['account_id'] . ':' . $r['taf'] . ':' . $r['ccy']] = [(int)$r['account_id'], (int)$r['taf'], (string)$r['ccy'], (int)$r['net'], (string)$r['cnet']];
    return $out;
}

function pb_fc_preview(PDO $pdo, int $cid, array $customer, array $fy): array {
    pb_voucher_ensure($pdo);
    $id = (int)$fy['id'];
    $block = [];
    $warn = [];
    $q = $pdo->prepare("SELECT COUNT(*) FROM vouchers WHERE customer_id=? AND fiscal_year_id=? AND status='draft'");
    $q->execute([$cid, $id]);
    if (($n = (int)$q->fetchColumn()) > 0) $block[] = ['code' => 'draft_vouchers', 'count' => $n, 'message' => $n . ' سند پیش‌نویس در این سال وجود دارد'];
    $q = $pdo->prepare('SELECT COUNT(*) FROM (SELECT v.id FROM vouchers v JOIN voucher_lines l ON l.voucher_id=v.id AND l.customer_id=v.customer_id
        WHERE v.customer_id=? AND v.fiscal_year_id=? GROUP BY v.id HAVING SUM(l.debit)<>SUM(l.credit)) x');
    $q->execute([$cid, $id]);
    if (($n = (int)$q->fetchColumn()) > 0) $block[] = ['code' => 'unbalanced', 'count' => $n, 'message' => $n . ' سند نامتوازن وجود دارد'];
    if (pb_acc_roles_missing($pdo, $cid, ['retained'])) $block[] = ['code' => 'role_retained', 'count' => 1, 'message' => 'حساب نقش «سود (زیان) انباشته» تنظیم نشده است'];
    $rec = pb_stock_reconcile($pdo, $cid);
    $mm = isset($rec['mismatches']) && is_array($rec['mismatches']) ? count($rec['mismatches']) : (isset($rec['items']) && is_array($rec['items']) ? count($rec['items']) : 0);
    if ($mm > 0) $warn[] = ['code' => 'stock_mismatch', 'count' => $mm, 'message' => $mm . ' قلم با دفتر موجودی انبار مغایرت دارد'];
    $q = $pdo->prepare("SELECT COUNT(*) FROM sales s WHERE s.customer_id=? AND s.date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.customer_id=s.customer_id AND v.source_type='sale' AND v.source_id=CAST(s.id AS CHAR))");
    $q->execute([$cid, $fy['start_date'], $fy['end_date']]);
    if (($n = (int)$q->fetchColumn()) > 0) $warn[] = ['code' => 'sales_unposted', 'count' => $n, 'message' => $n . ' فروش بدون سند حسابداری'];
    // cheques: open (unsettled) cheques are carried by the cheque accounts; overdue ones and register/book mismatches are flagged (T19.2b)
    $info = [];
    try {
        $q = $pdo->prepare("SELECT direction, status, COUNT(*) n, COALESCE(SUM(amount),0) amt FROM cheques WHERE customer_id=? AND status IN ('in_hand','deposited','pending') GROUP BY direction, status");
        $q->execute([$cid]);
        $open = 0;
        foreach ($q->fetchAll() as $r) { $open += (int)$r['n']; $info[] = ['direction' => (string)$r['direction'], 'status' => (string)$r['status'], 'count' => (int)$r['n'], 'amount' => (string)$r['amt']]; }
        $q = $pdo->prepare("SELECT COUNT(*) FROM cheques WHERE customer_id=? AND status IN ('in_hand','deposited','pending') AND due_date<=?");
        $q->execute([$cid, $fy['end_date']]);
        if (($n = (int)$q->fetchColumn()) > 0) $warn[] = ['code' => 'cheques_due', 'count' => $n, 'message' => $n . ' چک با سررسید تا پایان سال هنوز وصول/پرداخت نشده است (به سال بعد منتقل می‌شود)'];
        $q = $pdo->prepare('SELECT COUNT(*) FROM fiscal_years WHERE customer_id=? AND start_date>?');
        $q->execute([$cid, $fy['end_date']]);
        if ((int)$q->fetchColumn() === 0) { // register is "now": comparable with the book only for the latest year
            $mult = pb_posting_unit_mult();
            $bal = pb_fc_balances($pdo, $cid, $id);
            $roleAcc = [];
            $ra = $pdo->prepare('SELECT role, account_id FROM account_roles WHERE customer_id=?');
            $ra->execute([$cid]);
            foreach ($ra->fetchAll() as $r) $roleAcc[(string)$r['role']] = (int)$r['account_id'];
            foreach ([['cheque_in_hand', 'received', 'in_hand', 1], ['cheque_collect', 'received', 'deposited', 1], ['cheque_payable', 'issued', 'pending', -1]] as [$role, $dir, $stt, $sign]) {
                if (!isset($roleAcc[$role])) continue;
                $book = 0;
                foreach ($bal as $b) if ($b[0] === $roleAcc[$role]) $book += $b[3];
                $rg = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM cheques WHERE customer_id=? AND direction=? AND status=?');
                $rg->execute([$cid, $dir, $stt]);
                $reg = (int)round((float)$rg->fetchColumn() * $mult);
                if ($sign * $book !== $reg) { $warn[] = ['code' => 'cheque_register', 'count' => 1, 'message' => 'مانده دفتری حساب چک (' . $role . ') با دفتر چک مغایرت دارد']; }
            }
        }
    } catch (Throwable $e) { /* cheques module optional */ }
    require_once __DIR__ . '/fx_reval.php';
    $fxw = pb_fxr_close_warning($pdo, $cid, $customer, (string)$fy['end_date']); // S30.T30.2: year-end revaluation not done
    if ($fxw) $warn[] = $fxw;
    $rev = 0; $exp = 0;
    foreach (pb_fc_pl_nets($pdo, $cid, $id) as $r) { if ((int)$r['net'] < 0) $rev += -(int)$r['net']; else $exp += (int)$r['net']; }
    return ['fiscal_year' => ['id' => $id, 'name' => (string)$fy['name'], 'status' => (string)$fy['status']], 'blocking' => $block, 'warnings' => $warn, 'can_close' => count($block) === 0 && $fy['status'] === 'open',
        'result' => ['credit_nets' => $rev, 'debit_nets' => $exp, 'net_profit' => $rev - $exp], 'open_cheques' => $info, 'unit' => 'rial'];
}

function pb_fc_close(PDO $pdo, array $customer, array $fy): void {
    $cid = (int)$customer['id'];
    $id = (int)$fy['id'];
    if ($fy['status'] !== 'open') return; // idempotent: already closed/locked
    $pv = pb_fc_preview($pdo, $cid, $customer, $fy);
    if ($pv['blocking']) bad($pv['blocking'][0]['message'], 409);
    $pdo->beginTransaction();
    try {
        $lines = [];
        $d = 0; $c = 0;
        foreach (pb_fc_pl_nets($pdo, $cid, $id) as $r) {
            $net = (int)$r['net'];
            if ($net > 0) { $lines[] = ['account_id' => (int)$r['account_id'], 'tafsili_id' => 0, 'cost_center_id' => 0, 'debit' => 0, 'credit' => $net, 'description' => 'بستن حساب موقت']; $c += $net; }
            else { $lines[] = ['account_id' => (int)$r['account_id'], 'tafsili_id' => 0, 'cost_center_id' => 0, 'debit' => -$net, 'credit' => 0, 'description' => 'بستن حساب موقت']; $d += -$net; }
        }
        if ($lines) {
            $ra = $pdo->prepare('SELECT account_id FROM account_roles WHERE customer_id=? AND role=?');
            $ra->execute([$cid, 'retained']);
            $retained = (int)$ra->fetchColumn();
            if ($retained <= 0) { $pdo->rollBack(); bad('حساب سود (زیان) انباشته تنظیم نشده است', 409); }
            $diff = $d - $c; // debits of revenue closing - credits of expense closing: >0 = profit
            if ($diff > 0) $lines[] = ['account_id' => $retained, 'tafsili_id' => 0, 'cost_center_id' => 0, 'debit' => 0, 'credit' => $diff, 'description' => 'انتقال سود دوره به سود (زیان) انباشته'];
            elseif ($diff < 0) $lines[] = ['account_id' => $retained, 'tafsili_id' => 0, 'cost_center_id' => 0, 'debit' => -$diff, 'credit' => 0, 'description' => 'انتقال زیان دوره به سود (زیان) انباشته'];
            $old = pb_posting_find($pdo, $cid, 'fy_close', (string)$id);
            pb_voucher_write($pdo, $customer, ['date' => (string)$fy['end_date'], 'description' => 'سند اختتامیه سال مالی ' . $fy['name'], 'status' => 'final', 'source_type' => 'fy_close', 'source_id' => (string)$id], $lines, $old ? (int)$old['id'] : 0);
        }
        $pdo->prepare("UPDATE fiscal_years SET status='closed' WHERE id=? AND customer_id=?")->execute([$id, $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    pb_audit($pdo, $customer, 'fy.close', 'fiscal_year', (string)$id, 'بستن سال مالی ' . $fy['name']);
}

function pb_fc_reopen(PDO $pdo, array $customer, array $fy): void {
    $cid = (int)$customer['id'];
    $id = (int)$fy['id'];
    if ($fy['status'] === 'open') return;
    $st = $pdo->prepare("SELECT id FROM fiscal_years WHERE customer_id=? AND status<>'open' ORDER BY start_date DESC LIMIT 1");
    $st->execute([$cid]);
    if ((int)$st->fetchColumn() !== $id) bad('فقط آخرین سال مالی بسته‌شده قابل بازگشایی است', 409);
    $q = $pdo->prepare("SELECT COUNT(*) FROM vouchers v JOIN fiscal_years f ON f.id=v.fiscal_year_id AND f.customer_id=v.customer_id WHERE v.customer_id=? AND v.source_type='fy_open' AND f.start_date>?");
    $q->execute([$cid, $fy['start_date']]);
    if ((int)$q->fetchColumn() > 0) bad('سند افتتاحیهٔ سال بعد صادر شده است؛ ابتدا آن را حذف کنید', 409);
    $pdo->prepare("UPDATE fiscal_years SET status='open' WHERE id=? AND customer_id=?")->execute([$id, $cid]);
    pb_unpost_document($pdo, $customer, 'fy_close', (string)$id);
    pb_audit($pdo, $customer, 'fy.reopen', 'fiscal_year', (string)$id, 'بازگشایی سال مالی ' . $fy['name']);
}

/** The FY that starts the day after $fy ends (created when missing, like fiscal_years.create). */
function pb_fc_next_year(PDO $pdo, int $cid, array $fy): array {
    $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE customer_id=? AND start_date>? ORDER BY start_date ASC LIMIT 1');
    $st->execute([$cid, $fy['end_date']]);
    $nx = $st->fetch();
    if (!$nx) {
        $id = pb_fy_insert($pdo, $cid, pb_jyear_of((string)$fy['end_date']) + 1, false);
        $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $nx = $st->fetch();
    }
    if (date('Y-m-d', strtotime((string)$fy['end_date'] . ' +1 day')) !== (string)$nx['start_date']) bad('سال مالی بعد بلافاصله پس از این سال شروع نمی‌شود (فاصله در سال‌های مالی)', 409);
    return $nx;
}

function pb_fc_open_next(PDO $pdo, array $customer, array $fy): array {
    $cid = (int)$customer['id'];
    $id = (int)$fy['id'];
    if ($fy['status'] === 'open') bad('ابتدا سال مالی را ببندید', 409);
    $nx = pb_fc_next_year($pdo, $cid, $fy);
    pb_fy_require($customer, (int)$nx['id']);
    if ($nx['status'] !== 'open') bad('سال مالی بعد بسته یا قفل است؛ سند افتتاحیه ثبت نمی‌شود', 409);
    $bal = pb_fc_balances($pdo, $cid, $id);
    $sum = 0;
    foreach ($bal as $b) $sum += $b[3];
    if ($sum !== 0) bad('تراز ترازنامهٔ پایان سال صفر نیست (' . $sum . ')؛ سند اختتامیه یا سندهای سال را بررسی کنید', 409);
    $lines = [];
    $assets = 0; $liab = 0; $equity = 0; $memo = 0;
    $types = [];
    $tq = $pdo->prepare('SELECT id, type FROM accounts WHERE customer_id=?');
    $tq->execute([$cid]);
    foreach ($tq->fetchAll() as $r) $types[(int)$r['id']] = (string)$r['type'];
    foreach ($bal as $b) {
        [$acc, $taf, $ccy, $net, $cnet] = $b;
        $ln = ['account_id' => $acc, 'tafsili_id' => $taf, 'cost_center_id' => 0, 'debit' => $net > 0 ? $net : 0, 'credit' => $net < 0 ? -$net : 0, 'description' => 'مانده اول دوره'];
        if ($ccy !== '' && (float)$cnet != 0.0 && (((float)$cnet > 0) === ($net > 0))) {
            $ln['ccy'] = $ccy;
            $ln['ccy_amount'] = (string)abs((float)$cnet);
            $ln['rate'] = (string)round(abs($net) / abs((float)$cnet), 6);
        }
        $lines[] = $ln;
        $t = $types[$acc] ?? '';
        if ($t === 'asset') $assets += $net; elseif ($t === 'liability') $liab -= $net; elseif ($t === 'equity') $equity -= $net; else $memo += $net;
    }
    $res = ['assets' => $assets, 'liabilities' => $liab, 'equity' => $equity, 'lines' => count($lines), 'voucher_id' => 0, 'next_fiscal_year_id' => (int)$nx['id']];
    if (!$lines) return $res; // nothing to carry (empty books)
    $pdo->beginTransaction();
    try {
        $old = pb_posting_find($pdo, $cid, 'fy_open', (string)$id);
        $vid = pb_voucher_write($pdo, $customer, ['date' => (string)$nx['start_date'], 'description' => 'سند افتتاحیه سال مالی ' . $nx['name'], 'status' => 'final',
            'source_type' => 'fy_open', 'source_id' => (string)$id, 'max_lines' => 50000], $lines, $old ? (int)$old['id'] : 0);
        // verify: opening lines re-read from the DB == prior closing balances (account, tafsili, ccy)
        $q = $pdo->prepare('SELECT account_id, COALESCE(tafsili_id,0) taf, COALESCE(ccy,\'\') ccy, SUM(debit) - SUM(credit) net FROM voucher_lines WHERE voucher_id=? AND customer_id=? GROUP BY account_id, COALESCE(tafsili_id,0), COALESCE(ccy,\'\')');
        $q->execute([$vid, $cid]);
        $got = [];
        foreach ($q->fetchAll() as $r) $got[$r['account_id'] . ':' . $r['taf'] . ':' . $r['ccy']] = (int)$r['net'];
        $bad = 0;
        foreach ($bal as $k => $b) { if (!isset($got[$k]) || $got[$k] !== $b[3]) $bad++; }
        if ($bad > 0 || count($got) !== count($bal)) { $pdo->rollBack(); bad('مغایرت سند افتتاحیه با مانده‌های پایان سال قبل (' . $bad . ' مورد)؛ سند صادر نشد', 409); }
        $pdo->commit();
        $res['voucher_id'] = $vid;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    pb_audit($pdo, $customer, 'fy.open_next', 'fiscal_year', (string)$id, 'سند افتتاحیه ' . $nx['name'] . ' از ' . $fy['name']);
    return $res;
}

function pb_fc_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_ensure($pdo);
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_acc_seed($pdo, $cid);
    pb_voucher_ensure($pdo);
    $fy = pb_fc_year($pdo, $cid, $customer, $p);
    if ($action === 'fy.close_preview') out(pb_fc_preview($pdo, $cid, $customer, $fy));
    if ($action === 'fy.close') pb_fc_close($pdo, $customer, $fy);
    elseif ($action === 'fy.open_next') {
        $r = pb_fc_open_next($pdo, $customer, $fy);
        require_once __DIR__ . '/fx_reval.php'; pb_fxr_materialize_quiet($pdo, $customer); // step 5: pending auto-reversals of fx revaluations whose year now exists (never breaks this action)
        $l = pb_fy_list($pdo, $cid, $customer);
        out(is_array($l) ? $l + ['opening' => $r] : ['opening' => $r]);
    } elseif ($action === 'fy.open_undo') {
        $nx = pb_fc_next_year($pdo, $cid, $fy);
        if ($nx['status'] !== 'open') bad('سال مالی بعد بسته یا قفل است', 409);
        pb_unpost_document($pdo, $customer, 'fy_open', (string)$fy['id']);
        pb_audit($pdo, $customer, 'fy.open_undo', 'fiscal_year', (string)$fy['id'], 'حذف سند افتتاحیهٔ سال بعد از ' . $fy['name']);
    } elseif ($action === 'fy.lock') {
        if ($fy['status'] === 'open') bad('ابتدا سال مالی را ببندید', 409);
        $pdo->prepare("UPDATE fiscal_years SET status='locked' WHERE id=? AND customer_id=?")->execute([(int)$fy['id'], $cid]);
        pb_audit($pdo, $customer, 'fy.lock', 'fiscal_year', (string)$fy['id'], 'قفل سال مالی ' . $fy['name']);
    } elseif ($action === 'fy.reopen') pb_fc_reopen($pdo, $customer, $fy);
    else bad('عملیات نامعتبر', 404);
    out(pb_fy_list($pdo, $cid, $customer));
}
