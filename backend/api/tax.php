<?php
declare(strict_types=1);
/**
 * S17.T17.1 — document tax calculation (GOLD). Pure integer math, no DB access (mirrored by tests/tax_selftest.py).
 *
 * Units: integers in the document's stored unit (posting converts to rial via pb_pl_rial); unit-agnostic here. Rates are "milli-percent": 9% = 9000 (same unit as posting field vat_rate_milli).
 * Line: base = gross - discount + surcharge (never negative); tax_status taxable|exempt|zero (exempt and zero => tax 0, kept apart for reports).
 * Rounding rule (fiscal_years.tax_rounding, DOCUMENTED, default 'line'):
 *   'line' = tax rounded per line (half away from zero) and summed;
 *   'doc'  = bases grouped per rate, tax rounded once per group (half away from zero) and summed.
 * Optional tax_round_to (1|10|100|1000 rial): the final tax of a line/group is rounded to that multiple.
 * Statutory rates are data (fiscal_years / item settings), never constants here.
 */

const PB_TAX_STATUSES = ['taxable', 'exempt', 'zero'];

/** round(a / b) half away from zero, b > 0, integers only. */
function pb_tax_div_round(int $a, int $b): int {
    if ($b <= 0) return 0;
    $neg = $a < 0;
    $q = intdiv(abs($a) * 2 + $b, $b * 2);
    return $neg ? -$q : $q;
}

function pb_tax_round_to(int $v, int $to): int {
    return $to > 1 ? pb_tax_div_round($v, $to) * $to : $v;
}

/** @return array{base:int,tax:int,total:int,status:string,rate_milli:int} */
function pb_tax_line(int $gross, int $discount, int $surcharge, string $status, int $rateMilli, int $roundTo = 1): array {
    if (!in_array($status, PB_TAX_STATUSES, true)) $status = 'taxable';
    $base = max(0, $gross - $discount + $surcharge);
    $tax = 0;
    if ($status === 'taxable' && $rateMilli > 0) $tax = pb_tax_round_to(pb_tax_div_round($base * $rateMilli, 100000), $roundTo);
    return ['base' => $base, 'tax' => $tax, 'total' => $base + $tax, 'status' => $status, 'rate_milli' => $status === 'taxable' ? $rateMilli : 0];
}

/**
 * Document totals. $lines: each [gross, discount, surcharge, status, rate_milli].
 * @return array{lines:array,before_tax:int,tax:int,after_tax:int}
 */
function pb_tax_doc(array $lines, string $rounding = 'line', int $roundTo = 1): array {
    $out = []; $before = 0; $tax = 0; $groups = [];
    foreach ($lines as $l) {
        $r = pb_tax_line((int)$l[0], (int)$l[1], (int)$l[2], (string)$l[3], (int)$l[4], $rounding === 'doc' ? 1 : $roundTo);
        $out[] = $r;
        $before += $r['base'];
        if ($r['tax'] > 0 || ($r['status'] === 'taxable' && $r['rate_milli'] > 0)) {
            $k = (string)$r['rate_milli'];
            $groups[$k] = ($groups[$k] ?? 0) + $r['base'];
        }
        if ($rounding !== 'doc') $tax += $r['tax'];
    }
    if ($rounding === 'doc') {
        foreach ($groups as $k => $b) $tax += pb_tax_round_to(pb_tax_div_round($b * (int)$k, 100000), $roundTo);
    }
    return ['lines' => $out, 'before_tax' => $before, 'tax' => $tax, 'after_tax' => $before + $tax];
}
