<?php
declare(strict_types=1);
/**
 * S35.T35.7a — market payment terms: pure calculations (no writes). Active from the form switch form.pay_terms (checked by the action).
 * - Weighted-average due date: amount-weighted mean of the installment due dates (integer day arithmetic, half up).
 * - Cash equivalent («معادل نقد») = present value of the installments at a company MONTHLY discount rate, setting
 *   `pay.monthly_discount_pct` (percent). Empty setting = not configured → cash_equiv = null (never a hard-coded rate).
 *   Day count: actual days / 30, compounded monthly (accountant confirms: SIGNOFF_ITEMS).
 * - Sayad id (شناسهٔ صیاد) format check only: 16 digits after Persian/Arabic digit + space/dash cleanup. No external lookup.
 * Pure functions take plain values so tests/pay_terms_selftest.php runs without a database.
 */
require_once __DIR__ . '/money_unit.php';

/** Installments: [['amount' => int|numeric, 'due_date' => 'YYYY-MM-DD'], ...] → [['amount' => int, 'due' => 'YYYY-MM-DD'], ...]. Throws InvalidArgumentException. */
function pb_pay_norm(array $items): array {
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) throw new InvalidArgumentException('قسط نامعتبر است');
        $amt = $it['amount'] ?? 0;
        if (!is_numeric($amt) || (float)$amt < 0 || (float)$amt > 1.0e13) throw new InvalidArgumentException('مبلغ قسط نامعتبر است');
        $due = (string)($it['due_date'] ?? '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $due, new DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $due) throw new InvalidArgumentException('تاریخ سررسید نامعتبر است');
        $out[] = ['amount' => (int)round((float)$amt), 'due' => $due];
    }
    return $out;
}

/** Epoch day (UTC midnight) of a Y-m-d string. */
function pb_pay_day(string $ymd): int {
    return intdiv((int)(new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->format('U'), 86400);
}

/** Total of the installments (integer). */
function pb_pay_total(array $norm): int {
    $t = 0;
    foreach ($norm as $r) $t += $r['amount'];
    return $t;
}

/** Amount-weighted average due date (Y-m-d) or null when the total is 0. Integer arithmetic, half up. */
function pb_pay_avg_due(array $norm): ?string {
    $total = pb_pay_total($norm);
    if ($total <= 0) return null;
    $days = array_map(static fn(array $r): int => pb_pay_day($r['due']), $norm);
    $base = min($days);
    $sumOff = 0;
    foreach ($norm as $i => $r) $sumOff += $r['amount'] * ($days[$i] - $base);
    $off = intdiv($sumOff * 2 + $total, 2 * $total); // round(sumOff / total) half up, exact for non-negative ints
    return gmdate('Y-m-d', ($base + $off) * 86400);
}

/** Cash equivalent: present value at the monthly discount rate (percent) as of $asOf. Null when the rate is not configured. */
function pb_pay_cash_equiv(array $norm, ?float $monthlyPct, string $asOf): ?int {
    if ($monthlyPct === null) return null;
    $r = $monthlyPct / 100.0;
    $now = pb_pay_day($asOf);
    $pv = 0.0;
    foreach ($norm as $row) {
        $months = max(0, pb_pay_day($row['due']) - $now) / 30.0;
        $pv += $row['amount'] / (1.0 + $r) ** $months;
    }
    return (int)round($pv);
}

/** Sayad id digits: Persian/Arabic digits → ASCII, spaces and dashes removed. Returns the cleaned string (may be invalid). */
function pb_pay_sayad_digits(string $raw): string {
    $s = strtr(trim($raw), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    return str_replace([' ', '-'], '', $s);
}

/** Sayad id check: 16 digits after pb_pay_sayad_digits(). Format only. */
function pb_pay_sayad_ok(string $raw): bool {
    return (bool)preg_match('/^\d{16}$/', pb_pay_sayad_digits($raw));
}

/** Save-path check for one cheque line: null when empty; the 16-digit form when valid; invalid → rollback + refuse (format only). */
function pb_pay_sayad_check(PDO $pdo, array $t): ?string {
    $raw = $t['sayad_id'] ?? null;
    if ($raw === null || trim((string)$raw) === '') return null;
    if (!pb_pay_sayad_ok((string)$raw)) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        bad('شناسهٔ صیاد باید ۱۶ رقم باشد');
    }
    return pb_pay_sayad_digits((string)$raw);
}

/** Stores the sayad id of a cheque line (cheque_inst | receipt_cheque_inst) after its insert. No-op when null. */
function pb_pay_sayad_set(PDO $pdo, int $cid, string $table, int $rowId, ?string $digits): void {
    if ($digits === null || !in_array($table, ['cheque_inst', 'receipt_cheque_inst'], true)) return;
    $pdo->prepare("UPDATE `$table` SET sayad_id=? WHERE id=? AND customer_id=?")->execute([$digits, $rowId, $cid]);
}

/** Warning only (never blocks): balance after the document is above the party's credit limit. Limit null/0 = no limit. */
function pb_pay_over_limit(float $balanceAfter, ?float $creditLimit): bool {
    return $creditLimit !== null && $creditLimit > 0 && $balanceAfter > $creditLimit;
}

/** Monthly discount rate from company settings: null = not configured; invalid stored value → null (never a guess). */
function pb_pay_rate_of(PDO $pdo, int $cid): ?float {
    $raw = trim(pb_app_setting_get($pdo, $cid, 'pay.monthly_discount_pct', ''));
    if ($raw === '' || !is_numeric($raw) || (float)$raw < 0 || (float)$raw > 100) return null;
    return (float)$raw;
}

/** Action pay.terms {items:[{amount,due_date}], as_of?:Y-m-d} → weighted due date, total, cash equivalent. Read-only; needs form.pay_terms on. */
function pb_pay_terms_action(PDO $pdo, int $cid, array $p): array {
    if (!pb_form_on($pdo, $cid, 'pay_terms')) bad('بخش شرایط پرداخت در تنظیمات فرم‌ها روشن نیست');
    try {
        $norm = pb_pay_norm(is_array($p['items'] ?? null) ? $p['items'] : []);
    } catch (InvalidArgumentException $e) {
        bad($e->getMessage());
    }
    $asOf = (string)($p['as_of'] ?? gmdate('Y-m-d'));
    $rate = pb_pay_rate_of($pdo, $cid);
    return [
        'total' => pb_pay_total($norm),
        'avg_due' => pb_pay_avg_due($norm),
        'rate_monthly_pct' => $rate,
        'cash_equiv' => pb_pay_cash_equiv($norm, $rate, $asOf),
        'as_of' => $asOf,
    ];
}

/** Action pay.rate.save {rate: number|''} (perm settings.manage). '' clears the setting (= not configured). Stores percent as plain text. */
function pb_pay_rate_save(PDO $pdo, int $cid, array $p): array {
    $raw = trim((string)($p['rate'] ?? ''));
    if ($raw !== '') {
        $raw = strtr($raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.', '.' => '.']);
        if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 100) bad('نرخ تنزیل ماهانه باید عددی بین ۰ و ۱۰۰ باشد');
        $raw = rtrim(rtrim(number_format((float)$raw, 4, '.', ''), '0'), '.');
    }
    pb_app_setting_set($pdo, $cid, 'pay.monthly_discount_pct', $raw);
    return ['rate_monthly_pct' => $raw === '' ? null : (float)$raw];
}

/** Receivable from a party as parties.list defines it: sold − received (+ loans given − repaid to us). Null-safe; unknown party = 0. */
function pb_party_receivable(array $sums, string $partyName): float {
    $a = $sums[partyKey($partyName)] ?? null;
    if ($a === null) return 0.0;
    return ((float)$a['sold'] - (float)$a['received']) + ((float)$a['loanGiven'] - (float)$a['repaidToUs']);
}

/** Action pay.credit_check {party, amount}: receivable before/after the document + credit limit. WARNING ONLY (never refuses). Read-only. */
function pb_pay_credit_check(PDO $pdo, int $cid, array $p): array {
    $name = trim((string)($p['party'] ?? ''));
    if ($name === '') bad('طرف حساب را وارد کنید');
    $add = is_numeric($p['amount'] ?? null) ? (float)$p['amount'] : 0.0;
    $before = pb_party_receivable(pb_party_balance_sums($pdo, $cid), $name);
    $after = $before + $add;
    $limit = null;
    $st = $pdo->prepare('SELECT name, credit_limit FROM parties WHERE customer_id=?');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        if ($r['credit_limit'] !== null && partyKey((string)$r['name']) === partyKey($name)) $limit = (float)$r['credit_limit'];
    }
    return ['before' => $before, 'after' => $after, 'credit_limit' => $limit, 'over_limit' => pb_pay_over_limit($after, $limit)];
}
