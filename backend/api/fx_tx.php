<?php
/**
 * S28.T28.2 — foreign-currency receipts / payments / cheques (fx plan only).
 *
 * Line tables (lump_tx, credit_inst, cheque_inst, receipt_lump_tx, receipt_credit_inst, receipt_cheque_inst)
 * gain nullable ccy, ccy_amount (DECIMAL 20,4), fx_rate (rials per unit, DECIMAL 18,6). NULL ccy = rial line.
 * The stored `amount` stays the rial-equivalent in the tenant stored unit (pb_money_unit), so every existing
 * report, statement and posting rule keeps working unchanged:
 *   amount = payload.amount if given (>0; the rial actually moved = «مبلغ پایه کاربردی»), else round(ccy_amount * rate).
 * Rate: payload.fx_rate, else amount/ccy_amount (when both given), else latest fx_rates row <= line date, else 422 rate_missing.
 * Rial plan or empty/IRR/IRT ccy: line is stored as a plain rial line (fields stay NULL).
 */
require_once __DIR__ . '/fx_documents.php';

const PB_FX_TX_TABLES = ['lump_tx', 'credit_inst', 'cheque_inst', 'receipt_lump_tx', 'receipt_credit_inst', 'receipt_cheque_inst'];

/** Validate the currency fields of one line; returns null (rial line) or ['ccy','ccy_amount','fx_rate','amount']. */
function pb_fx_tx_resolve(PDO $pdo, int $cid, array $t, string $date): ?array {
    $code = strtoupper(trim((string)($t['ccy'] ?? '')));
    if ($code === '' || $code === 'IRR' || $code === 'IRT') return null;
    $st = $pdo->prepare('SELECT plan FROM customers WHERE id=?');
    $st->execute([$cid]);
    if ((string)$st->fetchColumn() !== 'fx') return null; // rial plan: fields ignored
    $cur = pb_fx_currency_by_code($pdo, $cid, $code);
    if (!$cur || (int)$cur['active'] !== 1 || (int)$cur['is_base'] === 1) bad('ارز نامعتبر یا غیرفعال است');
    $dec = (int)$cur['decimals'];
    $ca = nullableNum($t['ccy_amount'] ?? null);
    if ($ca === null || $ca <= 0 || $ca > 1e12) bad('مبلغ ارزی نامعتبر است');
    $ca = round($ca, $dec);
    $explicit = nullableNum($t['amount'] ?? null);
    if ($explicit !== null && ($explicit <= 0 || $explicit > 1e15)) $explicit = null;
    $unit = pb_money_unit($pdo, $cid);
    $rate = nullableNum($t['fx_rate'] ?? null);
    if ($rate !== null && ($rate <= 0 || $rate > 1e12)) bad('نرخ نامعتبر است');
    if ($rate === null && $explicit !== null) {
        // effective rate from the rial actually moved: stored unit -> rials per unit
        $rate = ($explicit * ($unit === 'toman' ? 10 : 1)) / $ca;
    }
    if ($rate === null) {
        $rate = pb_fx_rate_at($pdo, $cid, $code, $date);
        if ($rate === null || $rate <= 0) {
            out(['error' => 'rate_missing', 'ccy' => $code, 'rate_date' => $date,
                 'message' => 'نرخ این ارز برای تاریخ ثبت نشده است؛ ابتدا نرخ را ثبت کنید'], 422);
        }
    }
    $amount = $explicit !== null ? round($explicit, 2) : round($ca * pb_fx_rate_in_stored_unit($rate, $unit), 2);
    if ($amount <= 0 || $amount > 1e15) bad('مبلغ ریالی معادل نامعتبر است');
    return ['ccy' => $code, 'ccy_amount' => $ca, 'fx_rate' => round($rate, 6), 'amount' => $amount];
}

/** After a line INSERT: stamp currency fields + rial equivalent on the new row (same transaction). */
function pb_fx_tx_stamp(PDO $pdo, int $cid, string $table, int $id, array $t, string $date): void {
    if (!in_array($table, PB_FX_TX_TABLES, true) || $id <= 0) return;
    // v71: a paid row keeps the return credit it consumed (edit of the sale/purchase re-inserts rows from the payload)
    $cu = nullableNum($t['credit_used'] ?? null);
    if ($cu !== null && $cu > 0 && !empty($t['paid']) && $cu <= 1e15) {
        $pdo->prepare("UPDATE `$table` SET credit_used=? WHERE id=? AND customer_id=?")->execute([round($cu, 2), $id, $cid]);
    }
    // v71: a foreign-currency line fully covered by return credit has amount 0 / ccy_amount 0: the strict validation below
    // (ccy_amount > 0) would reject it and block the sync of the whole document, so keep the currency label with 0 instead.
    if ($cu !== null && $cu > 0 && !empty($t['paid']) && num($t['amount'] ?? 0) < 0.5) {
        $code = strtoupper(trim((string)($t['ccy'] ?? '')));
        $rate0 = nullableNum($t['fx_rate'] ?? null);
        if ($code !== '' && $code !== 'IRR' && $code !== 'IRT' && $rate0 !== null && $rate0 > 0 && $rate0 <= 1e12) {
            $pl = $pdo->prepare('SELECT plan FROM customers WHERE id=?');
            $pl->execute([$cid]);
            if ((string)$pl->fetchColumn() === 'fx') {
                $pdo->prepare("UPDATE `$table` SET ccy=?, ccy_amount=0, fx_rate=? WHERE id=? AND customer_id=?")->execute([$code, round($rate0, 6), $id, $cid]);
            }
        }
        return;
    }
    $fx = pb_fx_tx_resolve($pdo, $cid, $t, $date);
    if (!$fx) return;
    $pdo->prepare("UPDATE `$table` SET ccy=?, ccy_amount=?, fx_rate=?, amount=? WHERE id=? AND customer_id=?")
        ->execute([$fx['ccy'], $fx['ccy_amount'], $fx['fx_rate'], $fx['amount'], $id, $cid]);
}
