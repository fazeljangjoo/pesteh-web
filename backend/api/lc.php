<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S31.T31.12 — Letters of credit (اعتبار اسنادی) with deadlines. FX plan only. Informational tracking: no stock/ledger effect.
 * Each LC is linked to one export sale (sale_id required; keeps backup FK remap simple).
 * Actions: lc.list {status?, sale_id?} | lc.save {id?, sale_id, lc_no, issuing_bank, advising_bank?, ccy_code, amount, open_date?, expiry_date,
 *   latest_ship_date?, presentation_days?, note?} | lc.set_status {id, status, ship_date?, presented_date?} | lc.delete {id} | lc.deadlines {days?}
 * Deadlines: expiry, latest shipment, presentation deadline = ship_date + presentation_days (default 21), never after expiry.
 * presentation_days default is configurable data (UCP 600 suggests 21 days; accountant/bank letter confirms -> SIGNOFF_ITEMS).
 */
const PB_LC_STATUS = ['open', 'shipped', 'presented', 'paid', 'expired', 'cancelled'];
const PB_LC_DEFAULT_PRESENTATION_DAYS = 21;

function pb_lc_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS letters_of_credit (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        sale_id INT UNSIGNED NOT NULL,
        lc_no VARCHAR(40) NOT NULL,
        issuing_bank VARCHAR(100) NOT NULL,
        advising_bank VARCHAR(100) NULL,
        ccy_code VARCHAR(8) NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        open_date DATE NULL,
        expiry_date DATE NOT NULL,
        latest_ship_date DATE NULL,
        presentation_days SMALLINT UNSIGNED NOT NULL DEFAULT 21,
        status VARCHAR(12) NOT NULL DEFAULT 'open',
        ship_date DATE NULL,
        presented_date DATE NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_lc_no (customer_id, lc_no),
        KEY idx_lc_sale (customer_id, sale_id),
        KEY idx_lc_exp (customer_id, status, expiry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Presentation deadline = ship_date + days, capped at expiry; null until shipped. */
function pb_lc_present_deadline(array $r): ?string {
    if (empty($r['ship_date'])) return null;
    $d = date('Y-m-d', strtotime($r['ship_date'] . ' +' . (int)$r['presentation_days'] . ' days'));
    return $d > $r['expiry_date'] ? (string)$r['expiry_date'] : $d;
}

/** Adds computed deadline info relative to today. */
function pb_lc_decorate(array $r): array {
    $t = today();
    $r['present_deadline'] = pb_lc_present_deadline($r);
    $next = null; $kind = null;
    if (in_array($r['status'], ['open', 'shipped'], true)) {
        $cands = [];
        if ($r['status'] === 'open' && !empty($r['latest_ship_date'])) $cands['ship'] = $r['latest_ship_date'];
        if ($r['status'] === 'shipped' && $r['present_deadline']) $cands['present'] = $r['present_deadline'];
        $cands['expiry'] = $r['expiry_date'];
        asort($cands);
        $kind = array_key_first($cands);
        $next = $cands[$kind];
    }
    $r['next_deadline'] = $next;
    $r['next_kind'] = $kind;
    $r['days_left'] = $next ? (int)floor((strtotime($next) - strtotime($t)) / 86400) : null;
    $r['overdue'] = $next !== null && $next < $t;
    return $r;
}

function pb_lc_row(PDO $pdo, int $cid, int $id): array {
    $st = $pdo->prepare('SELECT * FROM letters_of_credit WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) bad('اعتبار اسنادی یافت نشد', 404);
    return $r;
}

function pb_lc_list(PDO $pdo, int $cid, array $p): array {
    $w = ['l.customer_id=?']; $a = [$cid];
    $s = trim((string)($p['status'] ?? ''));
    if ($s !== '') { if (!in_array($s, PB_LC_STATUS, true)) bad('وضعیت نامعتبر است'); $w[] = 'l.status=?'; $a[] = $s; }
    if (!empty($p['sale_id'])) { $w[] = 'l.sale_id=?'; $a[] = (int)$p['sale_id']; }
    $st = $pdo->prepare('SELECT l.*, s.buyer, s.date AS sale_date FROM letters_of_credit l LEFT JOIN sales s ON s.id=l.sale_id AND s.customer_id=l.customer_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY l.expiry_date ASC, l.id ASC LIMIT 500');
    $st->execute($a);
    return ['items' => array_map('pb_lc_decorate', $st->fetchAll()), 'statuses' => PB_LC_STATUS];
}

function pb_lc_save(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $saleId = (int)($p['sale_id'] ?? 0);
    $st = $pdo->prepare('SELECT id FROM sales WHERE id=? AND customer_id=?');
    $st->execute([$saleId, $cid]);
    if (!$st->fetch()) bad('فروش یافت نشد', 404);
    $no = str_or_null($p['lc_no'] ?? null, 40);
    $bank = str_or_null($p['issuing_bank'] ?? null, 100);
    if ($no === null) bad('شمارهٔ اعتبار اسنادی را وارد کنید');
    if ($bank === null) bad('بانک گشایش‌کننده را وارد کنید');
    $ccy = strtoupper(trim((string)($p['ccy_code'] ?? '')));
    if (!preg_match('/^[A-Z]{3,8}$/', $ccy)) bad('کد ارز نامعتبر است');
    $amount = round((float)($p['amount'] ?? 0), 2);
    if ($amount <= 0 || $amount > 1e13) bad('مبلغ نامعتبر است');
    $open = ($p['open_date'] ?? '') === '' || $p['open_date'] === null ? null : vdate($p['open_date']);
    $exp = vdateReq($p['expiry_date'] ?? null);
    $ship = ($p['latest_ship_date'] ?? '') === '' || $p['latest_ship_date'] === null ? null : vdate($p['latest_ship_date']);
    if ($open !== null && $open > $exp) bad('تاریخ گشایش بعد از انقضا است');
    if ($ship !== null && $ship > $exp) bad('مهلت حمل بعد از انقضا است');
    $days = (int)($p['presentation_days'] ?? PB_LC_DEFAULT_PRESENTATION_DAYS);
    if ($days < 1 || $days > 365) bad('مهلت ارائه مدارک نامعتبر است');
    $note = str_or_null($p['note'] ?? null, 500);
    $dup = $pdo->prepare('SELECT id FROM letters_of_credit WHERE customer_id=? AND lc_no=? AND id<>?');
    $dup->execute([$cid, $no, $id]);
    if ($dup->fetch()) bad('این شمارهٔ اعتبار اسنادی قبلاً ثبت شده است');
    $adv = str_or_null($p['advising_bank'] ?? null, 100);
    if ($id > 0) {
        pb_lc_row($pdo, $cid, $id);
        $pdo->prepare('UPDATE letters_of_credit SET sale_id=?, lc_no=?, issuing_bank=?, advising_bank=?, ccy_code=?, amount=?, open_date=?, expiry_date=?, latest_ship_date=?,
            presentation_days=?, note=?, updated_at=NOW() WHERE id=? AND customer_id=?')
            ->execute([$saleId, $no, $bank, $adv, $ccy, $amount, $open, $exp, $ship, $days, $note, $id, $cid]);
    } else {
        $pdo->prepare('INSERT INTO letters_of_credit (customer_id, sale_id, lc_no, issuing_bank, advising_bank, ccy_code, amount, open_date, expiry_date, latest_ship_date,
            presentation_days, status, note, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,\'open\',?,NOW(),NOW())')
            ->execute([$cid, $saleId, $no, $bank, $adv, $ccy, $amount, $open, $exp, $ship, $days, $note]);
        $id = (int)$pdo->lastInsertId();
    }
    return pb_lc_decorate(pb_lc_row($pdo, $cid, $id));
}

function pb_lc_set_status(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $r = pb_lc_row($pdo, $cid, $id);
    $s = trim((string)($p['status'] ?? ''));
    if (!in_array($s, PB_LC_STATUS, true)) bad('وضعیت نامعتبر است');
    $ship = $r['ship_date']; $pres = $r['presented_date'];
    if ($s === 'shipped') $ship = vdate($p['ship_date'] ?? null);
    if ($s === 'presented') { if (!$ship) bad('ابتدا تاریخ حمل را ثبت کنید'); $pres = vdate($p['presented_date'] ?? null); }
    if ($s === 'open') { $ship = null; $pres = null; }
    $pdo->prepare('UPDATE letters_of_credit SET status=?, ship_date=?, presented_date=?, updated_at=NOW() WHERE id=? AND customer_id=?')
        ->execute([$s, $ship, $pres, $id, $cid]);
    return pb_lc_decorate(pb_lc_row($pdo, $cid, $id));
}

/** Open/shipped LCs whose next deadline is within $days (or already overdue), soonest first. */
function pb_lc_deadlines(PDO $pdo, int $cid, int $days): array {
    $days = max(0, min(365, $days));
    $all = pb_lc_list($pdo, $cid, [])['items'];
    $out = array_values(array_filter($all, static fn($r) => $r['next_deadline'] !== null && $r['days_left'] <= $days));
    usort($out, static fn($a, $b) => strcmp((string)$a['next_deadline'], (string)$b['next_deadline']));
    return ['items' => $out, 'within_days' => $days, 'overdue_count' => count(array_filter($out, static fn($r) => $r['overdue']))];
}

function pb_lc_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (pb_plan_of($customer) !== 'fx') out(['error' => 'edition_required', 'need' => 'fx'], 403);
    $cid = (int)$customer['id'];
    pb_lc_ensure($pdo);
    switch ($action) {
        case 'lc.save': out(pb_lc_save($pdo, $cid, $p));
        case 'lc.set_status': out(pb_lc_set_status($pdo, $cid, $p));
        case 'lc.delete':
            pb_lc_row($pdo, $cid, (int)($p['id'] ?? 0));
            $pdo->prepare('DELETE FROM letters_of_credit WHERE id=? AND customer_id=?')->execute([(int)$p['id'], $cid]);
            out(['ok' => true]);
        case 'lc.deadlines': out(pb_lc_deadlines($pdo, $cid, (int)($p['days'] ?? 30)));
        default: out(pb_lc_list($pdo, $cid, $p));
    }
}
