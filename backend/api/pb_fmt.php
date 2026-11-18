<?php
declare(strict_types=1);
/**
 * S35.T35.10 — universal formatter (server text: assistant, PDF/CSV text, notifications).
 * Mirrors app/lib/fmt.dart and server/app/fmt.js; shared vectors: tests/fmt_vectors.json (run tests/fmt_selftest.php).
 * Storage unit = tenant money_unit ('rial'|'toman'); display unit = company setting `display_unit`. The unit label is shown by default.
 * Pure functions: no behavior change for existing callers (nothing calls this yet; T35.11/12 adopt it).
 */

function pb_fmt_unit($u): string { return $u === 'rial' ? 'rial' : 'toman'; }

/** Display unit of a tenant: setting display_unit, default = stored unit (so nothing changes until the user picks). */
function pb_fmt_display_unit(PDO $pdo, int $cid): string {
    $stored = pb_money_unit($pdo, $cid);
    $d = pb_app_setting_get($pdo, $cid, 'display_unit', '');
    return $d === 'rial' || $d === 'toman' ? $d : $stored;
}

function pb_fmt_digits(string $s, string $digits): string {
    if ($digits !== 'fa') return $s;
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $o = '';
    foreach (str_split($s) as $ch) {
        if ($ch >= '0' && $ch <= '9') $o .= $fa[(int)$ch];
        elseif ($ch === ',') $o .= '٬';
        elseif ($ch === '.') $o .= '٫';
        else $o .= $ch;
    }
    return $o;
}

/** Grouped number, trailing decimal zeros trimmed. */
function pb_fmt_num($v, int $dec = 0, string $digits = 'fa'): string {
    $v = (float)$v;
    if (!is_finite($v)) return '';
    $s = number_format(abs($v), $dec, '.', ',');
    if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
    if ($v < 0 && preg_match('/[1-9]/', $s)) $s = '-' . $s;
    return pb_fmt_digits($s, $digits);
}

function pb_fmt_to_display($stored, string $storedUnit, string $displayUnit): int {
    $v = (float)$stored;
    if ($storedUnit === $displayUnit) return (int)round($v);
    return $storedUnit === 'rial' ? (int)round($v / 10) : (int)round($v * 10); // PHP round() = half away from zero
}

function pb_fmt_money($stored, string $storedUnit, string $displayUnit, string $digits = 'fa', bool $label = true): string {
    $su = pb_fmt_unit($storedUnit);
    $du = pb_fmt_unit($displayUnit);
    $s = pb_fmt_num(pb_fmt_to_display($stored, $su, $du), 0, $digits);
    return $label ? $s . ' ' . ($du === 'rial' ? 'ریال' : 'تومان') : $s;
}

function pb_fmt_kg($v, string $digits = 'fa', int $dec = 3, bool $label = true): string {
    $s = pb_fmt_num($v, $dec, $digits);
    return $label ? $s . ' کیلو' : $s;
}

function pb_fmt_percent($v, string $digits = 'fa', int $dec = 2): string {
    return pb_fmt_num($v, $dec, $digits) . ($digits === 'fa' ? '٪' : '%');
}

/** Typed text (any digits/separators, DISPLAY unit) -> stored amount; null when empty/invalid. */
function pb_fmt_parse_money(?string $text, string $storedUnit, string $displayUnit): ?int {
    $t = strtr((string)$text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => ',', '،' => ',', '٫' => '.']);
    $t = preg_replace('/[^0-9.\-]/', '', $t);
    if ($t === '' || $t === '-' || $t === '.' || !is_numeric($t)) return null;
    $n = (float)$t;
    $su = pb_fmt_unit($storedUnit);
    $du = pb_fmt_unit($displayUnit);
    if ($su === $du) return (int)round($n);
    return $su === 'rial' ? (int)round($n * 10) : (int)round($n / 10);
}
