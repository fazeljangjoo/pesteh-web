<?php
/**
 * S25.T25.2 — currencies (ارزها): fx.currencies.list | save | deactivate.
 * Gate: require_feature(edition, 'fx.rates') in app.php. Every query is scoped by customer_id (cid).
 * Base currency = code IRT (ریال پایه، کدِ قدیمی برای سازگاری با داده و زرین‌پال). It is never created, edited in code, or deactivated.
 */
require_once __DIR__ . '/plan_upgrade.php'; // pb_plan_fx_ensure_tables (currencies + fx_rates DDL)

/** [code, name, symbol, decimals, is_base] — seeded per fx-plan customer (INSERT IGNORE, idempotent). */
const PB_FX_DEFAULTS = [
    ['IRT', 'ریال (پایه)', 'ریال', 0, 1],
    ['USD', 'دلار آمریکا', '$', 2, 0],
    ['EUR', 'یورو', '€', 2, 0],
    ['AED', 'درهم امارات', 'د.إ', 2, 0],
    ['GBP', 'پوند انگلیس', '£', 2, 0],
    ['IQD', 'دینار عراق', 'ع.د', 0, 0],
    ['TRY', 'لیر ترکیه', '₺', 2, 0],
    ['CNY', 'یوان چین', '¥', 2, 0],
];

/** Codes that belong to the base currency and cannot be created by users. */
function pb_fx_reserved_codes(): array { return ['IRT', 'IRR', 'RIAL']; }

/** Pure validator: 2–8 Latin letters after trim+upper. Returns the normalized code or null. */
function pb_fx_validate_code(string $code): ?string {
    $c = strtoupper(trim($code));
    return preg_match('/^[A-Z]{2,8}$/', $c) ? $c : null;
}

/** Pure validator: integer 0..4 decimals or null. */
function pb_fx_validate_decimals($v): ?int {
    if (!is_numeric($v)) return null;
    $d = (int)$v;
    return ($d >= 0 && $d <= 4 && (float)$v === (float)$d) ? $d : null;
}

/** Seed default currencies for one customer (idempotent). */
function pb_fx_seed_defaults(PDO $pdo, int $cid): void {
    pb_plan_fx_ensure_tables($pdo);
    $ins = $pdo->prepare('INSERT IGNORE INTO currencies (customer_id, code, name, symbol, decimals, is_base, active, created_at) VALUES (?,?,?,?,?,?,1,NOW())');
    foreach (PB_FX_DEFAULTS as [$code, $name, $sym, $dec, $base]) {
        $ins->execute([$cid, $code, $name, $sym, $dec, $base]);
    }
}

/** Row shape returned to clients (types cast; base flag as bool). */
function pb_fx_currency_shape(array $r): array {
    return [
        'id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'],
        'symbol' => (string)($r['symbol'] ?? ''), 'decimals' => (int)$r['decimals'],
        'is_base' => (int)$r['is_base'] === 1, 'active' => (int)$r['active'] === 1,
    ];
}

function pb_fx_currency_find(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT id, code, name, symbol, decimals, is_base, active FROM currencies WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    return $r ? $r : null;
}

/** Active currency code lookup for documents/rates: returns the row or null (scoped). */
function pb_fx_currency_by_code(PDO $pdo, int $cid, string $code): ?array {
    $st = $pdo->prepare('SELECT id, code, name, symbol, decimals, is_base, active FROM currencies WHERE customer_id=? AND code=?');
    $st->execute([$cid, $code]);
    $r = $st->fetch();
    return $r ? $r : null;
}

function pb_fx_currencies_list(PDO $pdo, int $cid): array {
    pb_fx_seed_defaults($pdo, $cid);
    $st = $pdo->prepare('SELECT id, code, name, symbol, decimals, is_base, active FROM currencies WHERE customer_id=? ORDER BY is_base DESC, code');
    $st->execute([$cid]);
    return ['currencies' => array_map('pb_fx_currency_shape', $st->fetchAll())];
}

/** fx.currencies.save {id?, code (new only), name, symbol?, decimals}. Code is immutable after creation. */
function pb_fx_currency_save(PDO $pdo, int $cid, array $p): array {
    pb_fx_seed_defaults($pdo, $cid);
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) bad('نام ارز نامعتبر است (۱ تا ۶۰ نویسه)');
    $symbol = trim((string)($p['symbol'] ?? ''));
    if (mb_strlen($symbol) > 8) bad('نماد ارز بیش از ۸ نویسه است');
    $dec = pb_fx_validate_decimals($p['decimals'] ?? 2);
    if ($dec === null) bad('تعداد اعشار باید عدد صحیح بین ۰ تا ۴ باشد');

    $id = (int)($p['id'] ?? 0);
    if ($id > 0) {
        $row = pb_fx_currency_find($pdo, $cid, $id);
        if (!$row) bad('ارز یافت نشد', 404);
        if ((int)$row['is_base'] === 1 && $dec !== 0) bad('اعشار ریال پایه باید صفر باشد');
        $pdo->prepare('UPDATE currencies SET name=?, symbol=?, decimals=? WHERE id=? AND customer_id=?')
            ->execute([$name, $symbol, $dec, $id, $cid]);
    } else {
        $code = pb_fx_validate_code((string)($p['code'] ?? ''));
        if ($code === null) bad('کد ارز باید ۲ تا ۸ حرف لاتین باشد');
        if (in_array($code, pb_fx_reserved_codes(), true)) bad('این کد برای ارز پایه رزرو است');
        if (pb_fx_currency_by_code($pdo, $cid, $code)) bad('این کد ارز قبلاً ثبت شده است', 409);
        $pdo->prepare('INSERT INTO currencies (customer_id, code, name, symbol, decimals, is_base, active, created_at) VALUES (?,?,?,?,?,0,1,NOW())')
            ->execute([$cid, $code, $name, $symbol, $dec]);
        $id = (int)$pdo->lastInsertId();
    }
    return ['currency' => pb_fx_currency_shape(pb_fx_currency_find($pdo, $cid, $id))];
}

/** fx.currencies.deactivate {id}: soft disable. The base currency cannot be deactivated. Documents keep their codes. */
function pb_fx_currency_deactivate(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $row = $id > 0 ? pb_fx_currency_find($pdo, $cid, $id) : null;
    if (!$row) bad('ارز یافت نشد', 404);
    if ((int)$row['is_base'] === 1) bad('ارز پایه قابل غیرفعال‌سازی نیست');
    $pdo->prepare('UPDATE currencies SET active=0 WHERE id=? AND customer_id=?')->execute([$id, $cid]);
    return ['ok' => true, 'id' => $id];
}
