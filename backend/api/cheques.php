<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
require_once __DIR__ . '/dues_credit.php'; // v71: pb_dues_set_paid
/**
 * S06.T06.1 — unified cheque register. Both tiers.
 * `cheques` mirrors every row of cheque_inst (issued, purchases side) and receipt_cheque_inst (received, sales side).
 * The legacy rows are re-created whenever a payment/receipt is saved (new ids), so the register is reconciled LAZILY by
 * pb_cheque_sync() (idempotent): missing rows are backfilled, orphans are re-linked by (direction, number, bank, amount, due_date),
 * un-transitioned orphans are dropped. Legacy `paid` stays consistent: paid=1 only when status = cleared.
 *
 * received: in_hand -> deposited | endorsed(party) | returned_to_payer ; deposited -> cleared | bounced
 * issued:   pending -> cleared | bounced | cancelled
 * Terminal: cleared, bounced, endorsed, returned_to_payer, cancelled.
 * Bounce = the legacy paid flag stays 0, so the receivable/payable is automatically outstanding again; the event records a reinstatement note.
 * Endorsement does NOT post a payment to the endorsed party yet (accounting follows in S16 via pb_on_cheque_event).
 */
const PB_CHEQUE_SRC = ['cheque_inst' => 'issued', 'receipt_cheque_inst' => 'received'];
const PB_CHEQUE_FLOW = [
    'received' => [
        'in_hand' => ['deposited', 'endorsed', 'returned_to_payer'],
        'deposited' => ['cleared', 'bounced'],
    ],
    'issued' => ['pending' => ['cleared', 'bounced', 'cancelled']],
];
const PB_CHEQUE_STATUS_LABELS = [
    'in_hand' => 'نزد ما', 'deposited' => 'وصول‌شده به بانک (در جریان)', 'cleared' => 'پاس‌شده', 'bounced' => 'برگشتی',
    'endorsed' => 'خرج‌شده', 'returned_to_payer' => 'عودت به صادرکننده', 'pending' => 'صادرشده (در انتظار)', 'cancelled' => 'ابطال‌شده',
];

function pb_cheque_ensure(PDO $pdo): void {
    static $fxCols = false; // S28.T28.2: older registers get the currency columns once per request
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS cheques (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        direction VARCHAR(10) NOT NULL,
        number VARCHAR(60) NULL,
        bank VARCHAR(100) NULL,
        branch VARCHAR(100) NULL,
        sayad_id VARCHAR(32) NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        due_date DATE NOT NULL,
        party VARCHAR(150) NULL,
        status VARCHAR(20) NOT NULL,
        source_table VARCHAR(30) NULL,
        source_id INT UNSIGNED NULL,
        endorsed_to VARCHAR(150) NULL,
        note VARCHAR(500) NULL,
        reinstated TINYINT(1) NOT NULL DEFAULT 0,
        ccy VARCHAR(8) NULL,
        ccy_amount DECIMAL(20,4) NULL,
        fx_rate DECIMAL(18,6) NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_cheque_src (customer_id, source_table, source_id),
        KEY idx_cheque_c (customer_id, status, due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$fxCols) {
        foreach ([['ccy', 'VARCHAR(8) NULL'], ['ccy_amount', 'DECIMAL(20,4) NULL'], ['fx_rate', 'DECIMAL(18,6) NULL']] as [$c, $def]) {
            $ex = $pdo->query("SHOW COLUMNS FROM cheques LIKE '$c'");
            if (!($ex && $ex->fetch())) $pdo->exec("ALTER TABLE cheques ADD COLUMN `$c` $def");
        }
        $fxCols = true;
    }
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS cheque_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        cheque_id INT UNSIGNED NOT NULL,
        from_status VARCHAR(20) NULL,
        to_status VARCHAR(20) NOT NULL,
        event_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        bank_ref VARCHAR(64) NULL,
        bank_charge BIGINT NULL,
        settle_rate DECIMAL(18,6) NULL,
        created_at DATETIME NULL,
        KEY idx_cheque_ev (customer_id, cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { // S30.T30.3e: settlement rate (rial per foreign unit) of a clearing event
        $ex = $pdo->query("SHOW COLUMNS FROM cheque_events LIKE 'settle_rate'");
        if (!($ex && $ex->fetch())) $pdo->exec('ALTER TABLE cheque_events ADD COLUMN settle_rate DECIMAL(18,6) NULL');
    } catch (Throwable $e) { error_log('[cheques] settle_rate column: ' . $e->getMessage()); }
}

/** Gold hook point (S16 posts vouchers here). Intentionally empty. */
if (!function_exists('pb_on_cheque_event')) {
    function pb_on_cheque_event(PDO $pdo, int $cid, array $cheque, array $event): void {}
}

function pb_cheque_event(PDO $pdo, int $cid, int $chequeId, ?string $from, string $to, string $date, ?string $note, ?string $bankRef = null, int $bankCharge = 0, bool $strict = false, ?float $settleRate = null): int {
    $hasBank = pb_cheque_event_has_bank($pdo);
    $sr = null; // S30.T30.3e: clearing of a foreign cheque => settlement rate (explicit, else registered rate on the event date)
    if ($to === 'cleared') {
        try {
            $q = $pdo->prepare('SELECT ccy, ccy_amount FROM cheques WHERE id=? AND customer_id=?');
            $q->execute([$chequeId, $cid]);
            $cq = $q->fetch();
            $cc = $cq ? strtoupper(trim((string)($cq['ccy'] ?? ''))) : '';
            if ($cc !== '' && $cc !== 'IRR' && $cc !== 'IRT' && (float)$cq['ccy_amount'] > 0) {
                $sr = ($settleRate !== null && $settleRate > 0) ? $settleRate : (function_exists('pb_fx_rate_at') ? pb_fx_rate_at($pdo, $cid, $cc, $date) : null);
            }
        } catch (Throwable $e) { $sr = null; }
    }
    $pdo->prepare('INSERT INTO cheque_events (customer_id, cheque_id, from_status, to_status, event_date, note, created_at' . ($hasBank ? ', bank_ref, bank_charge' : '') . ') VALUES (?,?,?,?,?,?,NOW()' . ($hasBank ? ',?,?' : '') . ')')
        ->execute(array_merge([$cid, $chequeId, $from, $to, $date, $note], $hasBank ? [$bankRef, $bankCharge > 0 ? $bankCharge : null] : []));
    $evId = (int)$pdo->lastInsertId();
    if ($evId > 0 && $sr !== null && $sr > 0) { try { $pdo->prepare('UPDATE cheque_events SET settle_rate=? WHERE id=? AND customer_id=?')->execute([$sr, $evId, $cid]); } catch (Throwable $e) { /* column missing: carried at document rate */ } }
    // S16.T16.4 (gold): every lifecycle event (also those created by the legacy dues screen via pb_cheque_sync) posts one voucher
    if ($evId > 0 && function_exists('pb_plb_cheque_event_posted')) pb_plb_cheque_event_posted($pdo, $cid, $evId, $strict);
    return $evId;
}

function pb_cheque_event_has_bank(PDO $pdo): bool {
    static $has = null;
    if ($has === null) {
        try { $q = $pdo->query("SHOW COLUMNS FROM cheque_events LIKE 'bank_charge'"); $has = (bool)($q && $q->fetch()); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/**
 * v71: `amount` here is the PHYSICAL cheque amount = line amount + credit_used (a cleared line is reduced by the return credit it consumed,
 * but `cheques.amount` keeps the face value) — otherwise orphan re-link by pb_cheque_key() would miss and duplicate the cheque.
 * Current legacy cheque rows as list of [source_table, id, direction, number, bank, amount, due_date, party, paid]. */
function pb_cheque_sources(PDO $pdo, int $cid): array {
    $out = [];
    $q = [
        'cheque_inst' => "SELECT x.id, x.cheque_no, x.bank_name, (x.amount + COALESCE(x.credit_used, 0)) AS amount, x.due_date, x.paid, x.ccy, x.ccy_amount, x.fx_rate, p.seller AS party FROM cheque_inst x
            JOIN payments pay ON pay.id=x.payment_id AND pay.customer_id=x.customer_id JOIN purchases p ON p.id=pay.purchase_id AND p.customer_id=x.customer_id WHERE x.customer_id=?",
        'receipt_cheque_inst' => "SELECT x.id, x.cheque_no, x.bank_name, (x.amount + COALESCE(x.credit_used, 0)) AS amount, x.due_date, x.paid, x.ccy, x.ccy_amount, x.fx_rate, s.buyer AS party FROM receipt_cheque_inst x
            JOIN receipts rec ON rec.id=x.receipt_id AND rec.customer_id=x.customer_id JOIN sales s ON s.id=rec.sale_id AND s.customer_id=x.customer_id WHERE x.customer_id=?",
    ];
    foreach ($q as $tbl => $sql) {
        $st = $pdo->prepare($sql);
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) {
            $out[] = ['t' => $tbl, 'id' => (int)$r['id'], 'dir' => PB_CHEQUE_SRC[$tbl], 'no' => trim((string)($r['cheque_no'] ?? '')), 'bank' => trim((string)($r['bank_name'] ?? '')),
                'amount' => (float)$r['amount'], 'due' => (string)$r['due_date'], 'party' => trim((string)($r['party'] ?? '')), 'paid' => (int)$r['paid'],
                'ccy' => ($r['ccy'] ?? '') === '' ? null : (string)$r['ccy'], 'ccy_amount' => $r['ccy_amount'] === null ? null : (float)$r['ccy_amount'], 'fx_rate' => $r['fx_rate'] === null ? null : (float)$r['fx_rate']]; // S28.T28.2
        }
    }
    return $out;
}

function pb_cheque_key(string $dir, string $no, string $bank, float $amount, string $due): string {
    return $dir . '|' . $no . '|' . $bank . '|' . number_format($amount, 2, '.', '') . '|' . $due;
}

/** Idempotent reconcile of the register with the legacy installment rows. Call at the start of every cheques.* action. */
function pb_cheque_sync(PDO $pdo, int $cid): void {
    $src = pb_cheque_sources($pdo, $cid);
    $st = $pdo->prepare('SELECT * FROM cheques WHERE customer_id=?');
    $st->execute([$cid]);
    $reg = $st->fetchAll();
    $bySrc = [];
    $orphans = [];
    $live = [];
    foreach ($src as $s) $live[$s['t'] . '#' . $s['id']] = $s;
    foreach ($reg as $c) {
        $k = ($c['source_table'] ?? '') . '#' . ($c['source_id'] ?? '');
        if ($c['source_table'] !== null && isset($live[$k])) $bySrc[$k] = $c;
        elseif ($c['source_table'] !== null) $orphans[] = $c; // source row was re-created or deleted
    }
    // orphans: index by natural key for re-linking
    $orphanByKey = [];
    foreach ($orphans as $o) $orphanByKey[pb_cheque_key((string)$o['direction'], trim((string)$o['number']), trim((string)$o['bank']), (float)$o['amount'], (string)$o['due_date'])][] = $o;
    $used = [];
    foreach ($src as $s) {
        $k = $s['t'] . '#' . $s['id'];
        if (isset($bySrc[$k])) continue;
        $nk = pb_cheque_key($s['dir'], $s['no'], $s['bank'], $s['amount'], $s['due']);
        if (!empty($orphanByKey[$nk])) { // re-link (keeps status + history across doc edits / backup restore)
            $o = array_shift($orphanByKey[$nk]);
            $used[(int)$o['id']] = true;
            $pdo->prepare('UPDATE cheques SET source_table=?, source_id=?, party=?, ccy=?, ccy_amount=?, fx_rate=?, updated_at=NOW() WHERE id=? AND customer_id=?')->execute([$s['t'], $s['id'], $s['party'], $s['ccy'], $s['ccy_amount'], $s['fx_rate'], (int)$o['id'], $cid]);
            $o['source_table'] = $s['t']; $o['source_id'] = $s['id'];
            $bySrc[$k] = $o;
            continue;
        }
        $status = $s['paid'] === 1 ? 'cleared' : ($s['dir'] === 'received' ? 'in_hand' : 'pending');
        $pdo->prepare('INSERT IGNORE INTO cheques (customer_id, direction, number, bank, amount, due_date, party, status, source_table, source_id, ccy, ccy_amount, fx_rate, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute([$cid, $s['dir'], $s['no'] === '' ? null : $s['no'], $s['bank'] === '' ? null : $s['bank'], $s['amount'], $s['due'], $s['party'] === '' ? null : $s['party'], $status, $s['t'], $s['id'], $s['ccy'], $s['ccy_amount'], $s['fx_rate']]);
        $id = (int)$pdo->lastInsertId();
        if ($id > 0) pb_cheque_event($pdo, $cid, $id, null, $status, date('Y-m-d'), $status === 'cleared' ? 'ثبت اولیه (قبلاً تسویه‌شده)' : 'ثبت اولیه');
    }
    // orphans nobody re-linked: drop the untouched ones, keep (detached) those with real history
    foreach ($orphans as $o) {
        if (isset($used[(int)$o['id']])) continue;
        $init = in_array($o['status'], ['in_hand', 'pending'], true);
        $q = $pdo->prepare('SELECT COUNT(*) FROM cheque_events WHERE customer_id=? AND cheque_id=?');
        $q->execute([$cid, (int)$o['id']]);
        if ($init && (int)$q->fetchColumn() <= 1) {
            $pdo->prepare('DELETE FROM cheque_events WHERE customer_id=? AND cheque_id=?')->execute([$cid, (int)$o['id']]);
            $pdo->prepare('DELETE FROM cheques WHERE id=? AND customer_id=?')->execute([(int)$o['id'], $cid]);
        } elseif ($o['source_id'] !== null) {
            $pdo->prepare('UPDATE cheques SET source_table=NULL, source_id=NULL, updated_at=NOW() WHERE id=? AND customer_id=?')->execute([(int)$o['id'], $cid]);
        }
    }
    // legacy paid flag <-> status consistency (paid=1 only when cleared)
    foreach ($bySrc as $k => $c) {
        $s = $live[$k];
        $status = (string)$c['status'];
        $legacyPaid = $s['paid'] === 1;
        if ($legacyPaid && in_array($status, ['in_hand', 'pending', 'deposited'], true)) { // marked paid on the old dues screen
            $pdo->prepare("UPDATE cheques SET status='cleared', updated_at=NOW() WHERE id=? AND customer_id=?")->execute([(int)$c['id'], $cid]);
            pb_cheque_event($pdo, $cid, (int)$c['id'], $status, 'cleared', date('Y-m-d'), 'تسویه از صفحهٔ سررسیدها');
        } elseif (!$legacyPaid && $status === 'cleared') { // un-marked on the old dues screen
            $back = $c['direction'] === 'received' ? 'in_hand' : 'pending';
            $pdo->prepare('UPDATE cheques SET status=?, updated_at=NOW() WHERE id=? AND customer_id=?')->execute([$back, (int)$c['id'], $cid]);
            pb_cheque_event($pdo, $cid, (int)$c['id'], 'cleared', $back, date('Y-m-d'), 'لغو تسویه از صفحهٔ سررسیدها');
        } elseif ($legacyPaid && $status !== 'cleared') { // terminal non-cleared must not count as paid
            pb_dues_set_paid($pdo, $cid, (string)$s['t'], (int)$s['id'], false); // v71
        }
    }
}

function pb_cheque_row(array $r): array {
    return ['id' => (int)$r['id'], 'direction' => $r['direction'], 'number' => $r['number'], 'bank' => $r['bank'], 'branch' => $r['branch'] ?? null,
        'sayad_id' => $r['sayad_id'] ?? null, 'amount' => (float)$r['amount'], 'due_date' => $r['due_date'], 'party' => $r['party'],
        'status' => $r['status'], 'status_label' => PB_CHEQUE_STATUS_LABELS[$r['status']] ?? $r['status'], 'endorsed_to' => $r['endorsed_to'], 'note' => $r['note'],
        'reinstated' => (int)$r['reinstated'] === 1, 'source_table' => $r['source_table'], 'source_id' => $r['source_id'] === null ? null : (int)$r['source_id'],
        'next' => PB_CHEQUE_FLOW[$r['direction']][$r['status']] ?? [],
        'ccy' => ($r['ccy'] ?? '') === '' ? null : $r['ccy'], 'ccy_amount' => isset($r['ccy_amount']) ? (float)$r['ccy_amount'] : null, 'fx_rate' => isset($r['fx_rate']) ? (float)$r['fx_rate'] : null]; // S28.T28.2
}

/** cheques.list {direction?, status?, from?, to?, party?, q?} */
function pb_cheques_list(PDO $pdo, int $cid, array $p): array {
    pb_cheque_sync($pdo, $cid);
    $sql = 'SELECT * FROM cheques WHERE customer_id=?';
    $args = [$cid];
    if (in_array($p['direction'] ?? '', ['received', 'issued'], true)) { $sql .= ' AND direction=?'; $args[] = $p['direction']; }
    if (isset($p['status']) && $p['status'] !== '') {
        if (!isset(PB_CHEQUE_STATUS_LABELS[(string)$p['status']])) bad('وضعیت نامعتبر است');
        $sql .= ' AND status=?'; $args[] = $p['status'];
    }
    if (($p['from'] ?? '') !== '') { $sql .= ' AND due_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND due_date <= ?'; $args[] = vdate($p['to']); }
    if (($p['party'] ?? '') !== '') { $sql .= ' AND party=?'; $args[] = (string)$p['party']; }
    if (($p['q'] ?? '') !== '') { $sql .= ' AND (number LIKE ? OR bank LIKE ? OR party LIKE ?)'; $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$p['q']) . '%'; array_push($args, $l, $l, $l); }
    $st = $pdo->prepare($sql . ' ORDER BY due_date ASC, id ASC LIMIT 2000');
    $st->execute($args);
    $items = array_map('pb_cheque_row', $st->fetchAll());
    $sum = $pdo->prepare('SELECT direction, status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS s FROM cheques WHERE customer_id=? GROUP BY direction, status');
    $sum->execute([$cid]);
    $counts = [];
    foreach ($sum->fetchAll() as $r) $counts[] = ['direction' => $r['direction'], 'status' => $r['status'], 'count' => (int)$r['n'], 'amount' => (float)$r['s']];
    return ['items' => $items, 'summary' => $counts];
}

function pb_cheques_get(PDO $pdo, int $cid, array $p): array {
    pb_cheque_sync($pdo, $cid);
    $st = $pdo->prepare('SELECT * FROM cheques WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $r = $st->fetch();
    if (!$r) bad('چک یافت نشد', 404);
    $ev = $pdo->prepare('SELECT id, from_status, to_status, event_date, note FROM cheque_events WHERE customer_id=? AND cheque_id=? ORDER BY id');
    $ev->execute([$cid, (int)$r['id']]);
    $events = $ev->fetchAll();
    foreach ($events as &$e) { $e['id'] = (int)$e['id']; $e['to_label'] = PB_CHEQUE_STATUS_LABELS[$e['to_status']] ?? $e['to_status']; }
    return ['cheque' => pb_cheque_row($r), 'events' => $events];
}

/** cheques.transition {id, to, date?, note?, endorsed_to? (required for endorsed), bank_account? (uid), bank_charge? (toman, bounced only)} */
function pb_cheques_transition(PDO $pdo, int $cid, array $p): array {
    pb_cheque_sync($pdo, $cid);
    $id = (int)($p['id'] ?? 0);
    $to = (string)($p['to'] ?? '');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : date('Y-m-d');
    $note = str_or_null($p['note'] ?? null, 500);
    $endorsed = str_or_null($p['endorsed_to'] ?? null, 150);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM cheques WHERE id=? AND customer_id=? FOR UPDATE');
        $st->execute([$id, $cid]);
        $c = $st->fetch();
        if (!$c) { $pdo->rollBack(); bad('چک یافت نشد', 404); }
        // ارسال دوبارهٔ همان تغییر وضعیت (صف آفلاین بعد از قطع اتصالِ پس از اجرا): خطا نیست؛ وضعیت فعلی برگردانده می‌شود
        if ((string)$c['status'] === $to && $to !== '') { $pdo->rollBack(); return pb_cheques_get($pdo, $cid, ['id' => $id]); }
        $allowed = PB_CHEQUE_FLOW[$c['direction']][$c['status']] ?? [];
        if (!in_array($to, $allowed, true)) {
            $pdo->rollBack();
            bad('تغییر وضعیت از «' . (PB_CHEQUE_STATUS_LABELS[$c['status']] ?? $c['status']) . '» به «' . (PB_CHEQUE_STATUS_LABELS[$to] ?? $to) . '» مجاز نیست', 409);
        }
        if ($to === 'endorsed' && ($endorsed === null || trim($endorsed) === '')) { $pdo->rollBack(); bad('نام طرفی که چک به او خرج شده را وارد کنید'); }
        $reinstated = $to === 'bounced' ? 1 : (int)$c['reinstated'];
        $pdo->prepare('UPDATE cheques SET status=?, endorsed_to=?, reinstated=?, updated_at=NOW() WHERE id=? AND customer_id=?')
            ->execute([$to, $to === 'endorsed' ? $endorsed : $c['endorsed_to'], $reinstated, $id, $cid]);
        // legacy flag: paid=1 only when cleared
        if ($c['source_table'] !== null && isset(PB_CHEQUE_SRC[$c['source_table']]) && $c['source_id'] !== null) {
            pb_dues_set_paid($pdo, $cid, (string)$c['source_table'], (int)$c['source_id'], $to === 'cleared'); // v71: restores the original amount when un-paid (credit_used)
        }
        $auto = $to === 'bounced'
            ? ($c['direction'] === 'received' ? 'چک برگشت خورد؛ مبلغ دوباره به‌عنوان مطالبه از ' : 'چک برگشت خورد؛ مبلغ دوباره به‌عنوان بدهی به ') . ($c['party'] ?? '—') . ' برقرار شد'
            : null;
        $fullNote = $note !== null && $auto !== null ? $auto . ' — ' . $note : ($note ?? $auto);
        $bankRef = str_or_null($p['bank_account'] ?? null, 64); // S16.T16.4: bank account uid that receives/pays the cheque
        $bankCharge = $to === 'bounced' ? max(0, (int)round((float)($p['bank_charge'] ?? 0))) : 0; // toman
        pb_cheque_event($pdo, $cid, $id, (string)$c['status'], $to, $date, $fullNote, $bankRef, $bankCharge, true, (float)($p['settle_rate'] ?? 0) > 0 ? (float)$p['settle_rate'] : null); // S30.T30.3e: optional settle_rate (rial per foreign unit)
        $from = (string)$c['status'];
        $c['status'] = $to;
        pb_on_cheque_event($pdo, $cid, $c, ['from' => $from, 'to' => $to, 'date' => $date, 'note' => $fullNote, 'endorsed_to' => $endorsed]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cheques_get($pdo, $cid, ['id' => $id]);
}

const PB_CHEQUE_OPEN = ['received' => ['in_hand', 'deposited'], 'issued' => ['pending']];

/**
 * S06.T06.3 — cheques.report {kind, from?, to?, direction?}
 * kind: due_week | due_month | in_bank | bounced | by_party | by_bank | summary
 * Every report is computed from the same `cheques` rows as the register; `total`/`count` always equal the sum of the returned rows
 * and `register_total` is an independent SUM() over the same filter so the client/audit can verify they match.
 */
function pb_cheques_report(PDO $pdo, int $cid, array $p): array {
    pb_cheque_sync($pdo, $cid);
    $kind = (string)($p['kind'] ?? '');
    $dir = in_array($p['direction'] ?? '', ['received', 'issued'], true) ? (string)$p['direction'] : '';
    $today = date('Y-m-d');
    $from = ($p['from'] ?? '') !== '' ? vdate($p['from']) : null;
    $to = ($p['to'] ?? '') !== '' ? vdate($p['to']) : null;
    $openSql = "((direction='received' AND status IN ('in_hand','deposited')) OR (direction='issued' AND status='pending'))";
    $where = 'customer_id=?';
    $args = [$cid];
    $title = '';
    $isSummary = false;
    switch ($kind) {
        case 'due_week':
        case 'due_month':
            $days = $kind === 'due_week' ? 7 : 30;
            $from = $from ?? $today;
            $to = $to ?? date('Y-m-d', strtotime($from . " +{$days} days"));
            $where .= " AND $openSql AND due_date >= ? AND due_date <= ?";
            array_push($args, $from, $to);
            $title = $kind === 'due_week' ? 'چک‌های سررسید هفتهٔ پیش‌رو' : 'چک‌های سررسید ماه پیش‌رو';
            break;
        case 'in_bank':
            $where .= " AND direction='received' AND status='deposited'";
            $title = 'چک‌های در جریان وصول (نزد بانک)';
            break;
        case 'bounced':
            $where .= " AND status='bounced'";
            $title = 'فهرست چک‌های برگشتی';
            break;
        case 'by_party':
            $title = 'دفتر چک به تفکیک طرف حساب';
            break;
        case 'by_bank':
            $title = 'دفتر چک به تفکیک بانک';
            break;
        case 'summary':
            $isSummary = true;
            $title = 'خلاصهٔ چک‌های دریافتی و پرداختی';
            break;
        default:
            bad('نوع گزارش نامعتبر است');
    }
    if ($dir !== '') { $where .= ' AND direction=?'; $args[] = $dir; }
    if (in_array($kind, ['bounced', 'by_party', 'by_bank', 'summary', 'in_bank'], true)) {
        if ($from !== null) { $where .= ' AND due_date >= ?'; $args[] = $from; }
        if ($to !== null) { $where .= ' AND due_date <= ?'; $args[] = $to; }
    }
    $st = $pdo->prepare("SELECT * FROM cheques WHERE $where ORDER BY due_date ASC, id ASC LIMIT 5000");
    $st->execute($args);
    $rows = array_map('pb_cheque_row', $st->fetchAll());
    $sum = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS s FROM cheques WHERE $where");
    $sum->execute($args);
    $reg = $sum->fetch();
    $total = 0.0;
    foreach ($rows as $r) $total += $r['amount'];
    $out = ['kind' => $kind, 'title' => $title, 'from' => $from, 'to' => $to, 'direction' => $dir, 'generated' => $today,
        'count' => count($rows), 'total' => $total, 'register_count' => (int)$reg['n'], 'register_total' => (float)$reg['s'],
        'truncated' => (int)$reg['n'] > count($rows)];
    if ($isSummary) {
        $g = [];
        foreach ($rows as $r) {
            $k = $r['direction'] . '|' . $r['status'];
            if (!isset($g[$k])) $g[$k] = ['direction' => $r['direction'], 'status' => $r['status'], 'status_label' => $r['status_label'], 'count' => 0, 'amount' => 0.0];
            $g[$k]['count']++;
            $g[$k]['amount'] += $r['amount'];
        }
        $out['groups'] = array_values($g);
        $recv = ['open_count' => 0, 'open_amount' => 0.0, 'count' => 0, 'amount' => 0.0];
        $iss = $recv;
        foreach ($rows as $r) {
            $b = &$recv;
            if ($r['direction'] === 'issued') { unset($b); $b = &$iss; }
            $b['count']++; $b['amount'] += $r['amount'];
            if (in_array($r['status'], PB_CHEQUE_OPEN[$r['direction']], true)) { $b['open_count']++; $b['open_amount'] += $r['amount']; }
            unset($b);
        }
        $out['received'] = $recv;
        $out['issued'] = $iss;
        $out['net_open'] = $recv['open_amount'] - $iss['open_amount'];
        return $out;
    }
    if ($kind === 'by_party' || $kind === 'by_bank') {
        $key = $kind === 'by_party' ? 'party' : 'bank';
        $g = [];
        foreach ($rows as $r) {
            $n = trim((string)($r[$key] ?? '')) !== '' ? (string)$r[$key] : '— بدون نام —';
            if (!isset($g[$n])) $g[$n] = ['name' => $n, 'received_count' => 0, 'received_amount' => 0.0, 'issued_count' => 0, 'issued_amount' => 0.0,
                'open_amount' => 0.0, 'bounced_amount' => 0.0, 'cheques' => []];
            $d = $r['direction'];
            $g[$n][$d . '_count']++;
            $g[$n][$d . '_amount'] += $r['amount'];
            if (in_array($r['status'], PB_CHEQUE_OPEN[$d], true)) $g[$n]['open_amount'] += $r['amount'];
            if ($r['status'] === 'bounced') $g[$n]['bounced_amount'] += $r['amount'];
            $g[$n]['cheques'][] = $r;
        }
        ksort($g);
        $out['groups'] = array_values($g);
        return $out;
    }
    $out['items'] = $rows;
    return $out;
}
