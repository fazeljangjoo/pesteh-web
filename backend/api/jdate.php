<?php
declare(strict_types=1);
/** Jalali <-> Gregorian helpers (S15.T15.1). Arithmetic 33-year algorithm (same family as jdf), valid for the practical range 1300..1500. */

/** @return int[] [jy, jm, jd] */
function pb_g2j(int $gy, int $gm, int $gd): array {
    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
    $jy = -1595 + 33 * intdiv($days, 12053);
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + $days % 31;
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days - 186) % 30;
    }
    return [$jy, $jm, $jd];
}

/** @return int[] [gy, gm, gd] */
function pb_j2g(int $jy, int $jm, int $jd): array {
    $jy += 1595;
    $days = -355668 + 365 * $jy + intdiv($jy, 33) * 8 + intdiv($jy % 33 + 3, 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186);
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $days--;
        $gy += 100 * intdiv($days, 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
    $sal = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    while ($gm < 13 && $gd > $sal[$gm]) {
        $gd -= $sal[$gm];
        $gm++;
    }
    return [$gy, $gm, $gd];
}

/** 'YYYY-MM-DD' (Gregorian) of a Jalali date. */
function pb_j2g_str(int $jy, int $jm, int $jd): string {
    [$y, $m, $d] = pb_j2g($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/** Jalali year of a Gregorian 'YYYY-MM-DD'. */
function pb_jyear_of(string $ymd): int {
    return pb_g2j((int)substr($ymd, 0, 4), (int)substr($ymd, 5, 2), (int)substr($ymd, 8, 2))[0];
}

/** [start, end] Gregorian strings of Jalali year $jy (1 Farvardin .. last day of Esfand). */
function pb_jyear_bounds(int $jy): array {
    $start = pb_j2g_str($jy, 1, 1);
    $end = date('Y-m-d', strtotime(pb_j2g_str($jy + 1, 1, 1) . ' -1 day'));
    return [$start, $end];
}
