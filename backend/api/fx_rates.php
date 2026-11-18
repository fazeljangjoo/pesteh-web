<?php
/**
 * S25.T25.3 — daily exchange rates (نرخ روزانه): fx.rates.list | save | delete | get_at.
 * Rate = rials per one unit of the currency (DECIMAL(18,6)). Dates are Gregorian 'Y-m-d' internally;
 * clients may send rate_date_jalali 'YYYY/MM/DD' which is converted here (jdate.php).
 * Gate: require_feature(edition, 'fx.rates') in app.php. Every query is scoped by customer_id.
 */
require_once __DIR__ . '/fx_currencies.php';
require_once __DIR__ . '/jdate.php';

/** Pure validator: positive number up to 1e12, returned as a 6-decimal string, or null. */
function pb_fx_validate_rate($v): ?string {
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if (!is_finite($f) || $f <= 0 || $f > 1e12) return null;
    return sprintf('%.6F', $f);
}

/**
 * Pure validator: returns 'Y-m-d' from {rate_date} (Gregorian) or {rate_date_jalali} ('YYYY/MM/DD' or 'YYYY-MM-DD'), or null.
 * Jalali is converted with pb_j2g_str; the result must be a real calendar date.
 */
function pb_fx_normalize_date(array $p): ?string {
    if (!empty($p['rate_date_jalali'])) {
        if (!preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', trim((string)$p['rate_date_jalali']), $m)) return null;
        $jy = (int)$m[1]; $jm = (int)$m[2]; $jd = (int)$m[3];
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;
        $g = pb_j2g_str($jy, $jm, $jd);
    } else {
        $g = trim((string)($p['rate_date'] ?? ''));
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $g, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $g : null;
}

function pb_fx_rates_list(PDO $pdo, int $cid, array $p): array {
    pb_fx_seed_defaults($pdo, $cid);
    $sql = 'SELECT id, ccy, rate_date, rate, source FROM fx_rates WHERE customer_id=?';
    $args = [$cid];
    $ccy = strtoupper(trim((string)($p['ccy'] ?? '')));
    if ($ccy !== '') { $sql .= ' AND ccy=?'; $args[] = $ccy; }
    $sql .= ' ORDER BY rate_date DESC, ccy LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'ccy' => (string)$r['ccy'], 'rate_date' => (string)$r['rate_date'],
        'rate' => (string)$r['rate'], 'source' => (string)($r['source'] ?? '')], $st->fetchAll());
    return ['rates' => $rows];
}

/** fx.rates.save {ccy, rate, rate_date | rate_date_jalali, source?}: one rate per currency per day (upsert). */
function pb_fx_rate_save(PDO $pdo, int $cid, array $p): array {
    pb_fx_seed_defaults($pdo, $cid);
    $ccy = strtoupper(trim((string)($p['ccy'] ?? '')));
    $cur = $ccy !== '' ? pb_fx_currency_by_code($pdo, $cid, $ccy) : null;
    if (!$cur) bad('ارز نامعتبر است');
    if ((int)$cur['is_base'] === 1) bad('برای ریال پایه نرخ ثبت نمی‌شود');
    if ((int)$cur['active'] !== 1) bad('این ارز غیرفعال است');
    $rate = pb_fx_validate_rate($p['rate'] ?? null);
    if ($rate === null) bad('نرخ باید عدد مثبت باشد');
    $date = pb_fx_normalize_date($p);
    if ($date === null) bad('تاریخ نرخ نامعتبر است');
    $source = mb_substr(trim((string)($p['source'] ?? '')), 0, 60);
    $pdo->prepare('INSERT INTO fx_rates (customer_id, ccy, rate_date, rate, source, created_at) VALUES (?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE rate=VALUES(rate), source=VALUES(source)')
        ->execute([$cid, $ccy, $date, $rate, $source]);
    $st = $pdo->prepare('SELECT id, ccy, rate_date, rate, source FROM fx_rates WHERE customer_id=? AND ccy=? AND rate_date=?');
    $st->execute([$cid, $ccy, $date]);
    $r = $st->fetch();
    return ['rate' => ['id' => (int)$r['id'], 'ccy' => (string)$r['ccy'], 'rate_date' => (string)$r['rate_date'],
        'rate' => (string)$r['rate'], 'source' => (string)($r['source'] ?? '')]];
}

function pb_fx_rate_delete(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    if ($id <= 0) bad('شناسه نرخ نامعتبر است');
    $st = $pdo->prepare('DELETE FROM fx_rates WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    if ($st->rowCount() === 0) bad('نرخ یافت نشد', 404);
    return ['ok' => true, 'id' => $id];
}

/**
 * fx.rates.get_at {ccy, rate_date | rate_date_jalali}: latest rate on or before the date.
 * Base currency returns 1. Missing rate => 422 {error:'rate_missing'} (documents must not be saved without a rate).
 */
function pb_fx_rate_get_at(PDO $pdo, int $cid, array $p): array {
    $ccy = strtoupper(trim((string)($p['ccy'] ?? '')));
    $cur = $ccy !== '' ? pb_fx_currency_by_code($pdo, $cid, $ccy) : null;
    if (!$cur) bad('ارز نامعتبر است');
    $date = pb_fx_normalize_date($p);
    if ($date === null) bad('تاریخ نامعتبر است');
    if ((int)$cur['is_base'] === 1) return ['ccy' => $ccy, 'rate' => '1.000000', 'rate_date' => $date, 'base' => true];
    $st = $pdo->prepare('SELECT rate, rate_date FROM fx_rates WHERE customer_id=? AND ccy=? AND rate_date<=? ORDER BY rate_date DESC LIMIT 1');
    $st->execute([$cid, $ccy, $date]);
    $r = $st->fetch();
    if (!$r) out(['error' => 'rate_missing', 'ccy' => $ccy, 'rate_date' => $date, 'message' => 'نرخ این ارز برای این تاریخ ثبت نشده است'], 422);
    return ['ccy' => $ccy, 'rate' => (string)$r['rate'], 'rate_date' => (string)$r['rate_date'], 'base' => false];
}
