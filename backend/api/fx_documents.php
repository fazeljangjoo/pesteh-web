<?php
/**
 * S27.T27.1/T27.2 + S27b — foreign-currency documents (sales & purchases) — server core.
 *
 * Contract (docs/FX_CONTRACT.md, docs/MONEY_CONTRACT.md):
 *  - fx_rates.rate = RIALS per one unit of the currency.
 *  - Stored money unit = tenant money_unit: 'toman' until S29, then 'rial'.
 *    While toman is stored: stored price = price_ccy * rate / 10 (pb_fx_rate_in_stored_unit).
 *  - Document fields: ccy, fx_rate (rials per unit used), fx_rate_source ('server'|'offline'),
 *    fx_rate_entered_at, and the foreign amount (sales.total_ccy, purchases.price_ccy).
 *  - Rate policy (decided by the user): an OFFLINE document keeps the rate it was entered with
 *    (payload.offline_rate). Sync never changes it silently; mismatches are listed (fx.rate_mismatch.list).
 *    Changes after the fact only through adjustments (fx_adjust.php).
 *  - Rial plan and documents without currency (or IRT) are unchanged.
 */

/** Rate in rials per unit on or before $date (Y-m-d), or null. */
function pb_fx_rate_at(PDO $pdo, int $cid, string $ccy, string $date): ?float {
    $st = $pdo->prepare('SELECT rate FROM fx_rates WHERE customer_id=? AND ccy=? AND rate_date<=? ORDER BY rate_date DESC LIMIT 1');
    $st->execute([$cid, $ccy, $date]);
    $r = $st->fetchColumn();
    return $r === false ? null : (float)$r;
}

/** Convert a rial-per-unit rate to the tenant's stored money unit (toman = rial / 10). */
function pb_fx_rate_in_stored_unit(float $rateRial, string $unit): float {
    return $unit === 'rial' ? $rateRial : $rateRial / 10.0;
}

/**
 * Validate and price a foreign-currency document from the payload.
 * Returns null when the document is not foreign (rial plan, no ccy, or base IRT).
 * Returns ['ccy','rate_rial','source','entered_at','price_ccy','price_stored','decimals'].
 * No rate in the table and no offline rate => 422 rate_missing (nothing is written).
 */
function pb_fx_doc_currency(PDO $pdo, int $cid, array $payload, string $docDate, bool $isFx): ?array {
    if (!$isFx) return null;
    $code = strtoupper(trim((string)($payload['ccy'] ?? '')));
    if ($code === '' || $code === 'IRT') return null;
    $cur = pb_fx_currency_by_code($pdo, $cid, $code);
    if (!$cur || (int)$cur['active'] !== 1 || (int)$cur['is_base'] === 1) bad('ارز نامعتبر یا غیرفعال است');

    $offRate = nullableNum($payload['offline_rate'] ?? null);
    if ($offRate !== null) {
        // سند ثبت‌شده آفلاین: همان نرخ دستگاه معتبر است (سیاست نرخ، FX_CONTRACT)
        if ($offRate <= 0 || $offRate > 1e12) bad('نرخ آفلاین نامعتبر است');
        $rate = $offRate;
        $source = 'offline';
        $enteredAt = substr(trim((string)($payload['offline_rate_at'] ?? '')), 0, 30) ?: null;
    } else {
        $rate = pb_fx_rate_at($pdo, $cid, $code, $docDate);
        if ($rate === null || $rate <= 0) {
            out(['error' => 'rate_missing', 'ccy' => $code, 'rate_date' => $docDate,
                 'message' => 'نرخ این ارز برای تاریخ سند ثبت نشده است؛ ابتدا نرخ را ثبت کنید'], 422);
        }
        $source = 'server';
        $enteredAt = null;
    }
    $pc = nullableNum($payload['price_ccy'] ?? null);
    if ($pc === null || $pc <= 0 || $pc > 1e9) bad('فی ارزی نامعتبر است');
    $dec = (int)$cur['decimals'];
    $priceCcy = round($pc, $dec);
    $unit = pb_money_unit($pdo, $cid);
    $priceStored = round($priceCcy * pb_fx_rate_in_stored_unit($rate, $unit), 2);
    return ['ccy' => $code, 'rate_rial' => $rate, 'source' => $source, 'entered_at' => $enteredAt,
            'price_ccy' => $priceCcy, 'price_stored' => $priceStored, 'decimals' => $dec];
}

/** Stamp currency fields on a saved sale or purchase (same transaction). */
function pb_fx_doc_stamp(PDO $pdo, int $cid, string $table, int $id, array $fx, float $totalCcy): void {
    if ($table === 'sales') {
        $pdo->prepare('UPDATE sales SET ccy=?, fx_rate=?, total_ccy=?, fx_rate_source=?, fx_rate_entered_at=? WHERE id=? AND customer_id=?')
            ->execute([$fx['ccy'], $fx['rate_rial'], round($totalCcy, 4), $fx['source'], $fx['entered_at'], $id, $cid]);
    } elseif ($table === 'purchases') {
        $pdo->prepare('UPDATE purchases SET ccy=?, fx_rate=?, price_ccy=?, fx_rate_source=?, fx_rate_entered_at=? WHERE id=? AND customer_id=?')
            ->execute([$fx['ccy'], $fx['rate_rial'], $fx['price_ccy'], $fx['source'], $fx['entered_at'], $id, $cid]);
    }
}

/**
 * S27c.T27b.6/T27b.7 — foreign-currency documents are not editable in place.
 * Their rate is fixed by the rate policy; a rate change after posting goes through fx_adjust.php.
 * Refuses (422) when the stored document is foreign, or when an edit payload tries to turn a document into a foreign one.
 * $table is 'sales' or 'purchases' (fixed by the caller, never user input).
 */
function pb_fx_guard_no_edit(PDO $pdo, int $cid, string $table, int $id, array $payload): void {
    if ($table !== 'sales' && $table !== 'purchases') return;
    $newCcy = strtoupper(trim((string)($payload['ccy'] ?? '')));
    if ($newCcy !== '' && $newCcy !== 'IRT') {
        out(['error' => 'fx_edit_not_allowed', 'message' => 'ارز و نرخ سند بعد از ثبت قابل تغییر نیست؛ سند را حذف و دوباره ثبت کنید'], 422);
    }
    if ($id <= 0) return;
    try {
        $st = $pdo->prepare("SELECT ccy FROM {$table} WHERE id=? AND customer_id=?");
        $st->execute([$id, $cid]);
        $c = $st->fetchColumn();
    } catch (Throwable $e) {
        return; // ستون ccy هنوز ساخته نشده (قبل از مهاجرت): سند ارزی وجود ندارد
    }
    if ($c !== false && $c !== null && $c !== '' && strtoupper((string)$c) !== 'IRT') {
        out(['error' => 'fx_edit_not_allowed', 'message' => 'این سند ارزی است و نرخ آن ثابت است؛ ویرایش مستقیم ممکن نیست. برای اصلاح نرخ از سند تعدیل استفاده کنید یا سند را حذف و دوباره ثبت کنید'], 422);
    }
}
