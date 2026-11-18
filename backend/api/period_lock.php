<?php
declare(strict_types=1);
/**
 * S31.T31.2 — monthly period lock (GOLD). One company setting app_settings 'period.lock_through' = 'YYYY-MM-DD' (Gregorian):
 * every document/voucher dated on or before it is immutable (enforced inside pb_fy_guard, i.e. wherever the fiscal-year guard runs:
 * purchases/sales create, vouchers save/delete/status, posting unpost). Closing moves the lock FORWARD to the end of a Jalali month;
 * reopening (OWNER only; absent from the permission map => staff denied) moves it back to the end of the month before the given one
 * and records a mandatory reason (setting period.reopen_last = latest only, v is VARCHAR(255)) besides the audit-log entry.
 * Actions: period_lock.get (accounting.view) | period_lock.close (accounting.post) | period_lock.reopen (owner).
 */
require_once __DIR__ . '/jdate.php';

function pb_pl_through(PDO $pdo, int $cid): string {
    $v = pb_app_setting_get($pdo, $cid, 'period.lock_through', '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}

/** Throws (bad) when $date is inside the locked range. Call after the fiscal-year check. */
function pb_pl_guard(PDO $pdo, int $cid, string $date): void {
    $t = pb_pl_through($pdo, $cid);
    if ($t !== '' && substr($date, 0, 10) <= $t) bad('دورهٔ این سند بسته شده است (تا ' . $t . '). برای تغییر، مالک باید دوره را باز کند');
}

/** Gregorian last day of Jalali month ($jy,$jm). */
function pb_pl_month_end(int $jy, int $jm): string {
    if ($jm < 1 || $jm > 12 || $jy < 1300 || $jy > 1700) bad('ماه نامعتبر است');
    $ny = $jm === 12 ? $jy + 1 : $jy; $nm = $jm === 12 ? 1 : $jm + 1;
    return date('Y-m-d', strtotime(pb_j2g_str($ny, $nm, 1) . ' -1 day'));
}

/** Gold: the STORED date of an existing document must not be in a closed/locked period (edit/delete paths). */
function pb_pl_guard_doc(PDO $pdo, int $cid, array $customer, string $table, int $id): void {
    if (!in_array($table, ['purchases', 'sales', 'sale_returns', 'purchase_returns'], true)) return;
    if (!pb_fy_is_gold($customer)) return;
    $st = $pdo->prepare("SELECT `date` FROM `$table` WHERE id=? AND customer_id=?");
    $st->execute([$id, $cid]);
    $d = $st->fetchColumn();
    if ($d !== false && $d !== null && $d !== '') pb_fy_guard($pdo, $cid, $customer, (string)$d);
}

function pb_pl_get(PDO $pdo, int $cid): array {
    $t = pb_pl_through($pdo, $cid);
    $j = null;
    if ($t !== '') { $a = pb_g2j((int)substr($t, 0, 4), (int)substr($t, 5, 2), (int)substr($t, 8, 2)); $j = ['jy' => $a[0], 'jm' => $a[1], 'jd' => $a[2]]; }
    $last = json_decode(pb_app_setting_get($pdo, $cid, 'period.reopen_last', '{}'), true);
    $st = $pdo->prepare("SELECT COUNT(*) FROM vouchers WHERE customer_id=? AND status='draft'" . ($t !== '' ? ' AND voucher_date<=?' : ''));
    $st->execute($t !== '' ? [$cid, $t] : [$cid]);
    return ['lock_through' => $t === '' ? null : $t, 'jalali' => $j, 'draft_vouchers_in_locked' => $t === '' ? 0 : (int)$st->fetchColumn(), 'last_reopen' => is_array($last) && $last ? $last : null];
}

function pb_pl_close(PDO $pdo, int $cid, array $p): array {
    $end = pb_pl_month_end((int)($p['jy'] ?? 0), (int)($p['jm'] ?? 0));
    $cur = pb_pl_through($pdo, $cid);
    if ($cur !== '' && $end <= $cur) bad('این ماه قبلاً بسته شده است');
    pb_app_setting_set($pdo, $cid, 'period.lock_through', $end);
    return pb_pl_get($pdo, $cid);
}

function pb_pl_reopen(PDO $pdo, int $cid, array $customer, array $p): array {
    if ((int)($customer['_staff_id'] ?? 0) > 0) bad('فقط مالک می‌تواند دوره را باز کند', 403);
    $jy = (int)($p['jy'] ?? 0); $jm = (int)($p['jm'] ?? 0);
    $reason = trim((string)($p['reason'] ?? ''));
    if (mb_strlen($reason) < 3) bad('دلیل بازگشایی الزامی است');
    $reason = mb_substr($reason, 0, 50);
    $first = pb_j2g_str($jy, $jm, 1); pb_pl_month_end($jy, $jm); // validates
    $cur = pb_pl_through($pdo, $cid);
    if ($cur === '' || $first > $cur) bad('این ماه بسته نیست');
    $prev = date('Y-m-d', strtotime($first . ' -1 day'));
    $pdo->beginTransaction();
    pb_app_setting_set($pdo, $cid, 'period.lock_through', $prev);
    pb_app_setting_set($pdo, $cid, 'period.reopen_last', json_encode(['at' => date('Y-m-d H:i'), 'to' => $prev, 'reason' => $reason], JSON_UNESCAPED_UNICODE));
    $pdo->commit();
    return pb_pl_get($pdo, $cid);
}

function pb_period_lock_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    switch ($action) {
        case 'period_lock.get': out(pb_pl_get($pdo, $cid)); break;
        case 'period_lock.close': out(pb_pl_close($pdo, $cid, $p)); break;
        case 'period_lock.reopen': $r = pb_pl_reopen($pdo, $cid, $customer, $p); require_once __DIR__ . '/fx_reval.php'; pb_fxr_materialize_quiet($pdo, $customer); out($r); break; // step 5: pending fx auto-reversals may be postable now
    }
    bad('عملیات نامعتبر', 404);
}
