<?php
declare(strict_types=1);
/**
 * S35.T35.2 — weighing block (gross / tare / quality deduction / net) for purchases and sales. Active only when form.weigh = 1.
 * net = gross - tare - deduction is recomputed here; a client-sent net_weight that differs by more than 0.001 kg is refused.
 * total_weight/net_weight stay the source of truth for stock; gross_kg/tare_kg/quality_deduct_kg/weigh_ticket_no/vehicle_plate/driver_name are provenance.
 * Sack weights are company settings (app_settings k='weigh.sack_small_kg' / 'weigh.sack_large_kg', defaults 0.1 / 0.2 kg).
 */
require_once __DIR__ . '/form_settings.php';

const PB_WEIGH_DEFAULT_SMALL_KG = 0.1;
const PB_WEIGH_DEFAULT_LARGE_KG = 0.2;
const PB_WEIGH_TOL = 0.001;

/** [small, large] sack weights in kg for this company. */
function pb_weigh_sack_kg(PDO $pdo, int $cid): array {
    $s = (float)pb_app_setting_get($pdo, $cid, 'weigh.sack_small_kg', (string)PB_WEIGH_DEFAULT_SMALL_KG);
    $l = (float)pb_app_setting_get($pdo, $cid, 'weigh.sack_large_kg', (string)PB_WEIGH_DEFAULT_LARGE_KG);
    if ($s < 0 || $s > 100) $s = PB_WEIGH_DEFAULT_SMALL_KG;
    if ($l < 0 || $l > 100) $l = PB_WEIGH_DEFAULT_LARGE_KG;
    return [$s, $l];
}

/** payload: {small_kg, large_kg} — validated, stored per company. */
function pb_weigh_sack_save(PDO $pdo, int $cid, array $w): void {
    foreach (['small_kg' => 'sack_small_kg', 'large_kg' => 'sack_large_kg'] as $in => $k) {
        if (!array_key_exists($in, $w)) continue;
        if (!is_numeric($w[$in])) bad('وزن گونی نامعتبر است');
        $v = (float)$w[$in];
        if ($v < 0 || $v > 100) bad('وزن گونی باید بین ۰ تا ۱۰۰ کیلو باشد');
        pb_app_setting_set($pdo, $cid, 'weigh.' . $k, rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0');
    }
}

/** Per-request: when the weighing feature is on, the legacy tare() uses the company's sack weights. Call at the start of create actions. */
function pb_weigh_boot(PDO $pdo, int $cid): void {
    if (pb_form_on($pdo, $cid, 'weigh')) $GLOBALS['PB_SACK_KG'] = pb_weigh_sack_kg($pdo, $cid);
}

/**
 * Validates the weighing block of a payload. Returns null when the feature is off or no block was sent (document unchanged);
 * otherwise ['gross','tare','deduct','net','ticket','plate','driver']. $small/$large = sack counts used for the default tare.
 */
function pb_weigh_read(PDO $pdo, int $cid, array $p, float $small, float $large): ?array {
    if (!pb_form_on($pdo, $cid, 'weigh')) return null;
    if (!isset($p['gross_kg']) || $p['gross_kg'] === '' || $p['gross_kg'] === null) return null;
    foreach (['gross_kg', 'tare_kg', 'quality_deduct_kg'] as $k) {
        if (isset($p[$k]) && $p[$k] !== '' && !is_numeric($p[$k])) bad('مقدار وزن نامعتبر است');
    }
    $gross = (float)$p['gross_kg'];
    if ($gross <= 0 || $gross > 1e9) bad('وزن کل باید بزرگ‌تر از صفر باشد');
    $hasTare = isset($p['tare_kg']) && $p['tare_kg'] !== '' && $p['tare_kg'] !== null;
    $tare = $hasTare ? (float)$p['tare_kg'] : tare($small, $large);
    $deduct = (isset($p['quality_deduct_kg']) && $p['quality_deduct_kg'] !== '' && $p['quality_deduct_kg'] !== null) ? (float)$p['quality_deduct_kg'] : 0.0;
    if ($tare < 0 || $deduct < 0) bad('تارا و کسر کیفیت نمی‌توانند منفی باشند');
    $net = round($gross - $tare - $deduct, 3);
    if ($net <= 0) bad('وزن خالص باید بزرگ‌تر از صفر باشد');
    if (isset($p['net_weight']) && $p['net_weight'] !== '' && is_numeric($p['net_weight']) && abs((float)$p['net_weight'] - $net) > PB_WEIGH_TOL) {
        bad('وزن خالص با وزن کل منهای تارا و کسر کیفیت نمی‌خواند');
    }
    return [
        'gross' => round($gross, 3), 'tare' => round($tare, 3), 'deduct' => round($deduct, 3), 'net' => $net,
        'ticket' => str_or_null($p['weigh_ticket_no'] ?? null, 40), 'plate' => str_or_null($p['vehicle_plate'] ?? null, 20),
        'driver' => str_or_null($p['driver_name'] ?? null, 80),
    ];
}

/** Stores the provenance columns (call inside the document transaction). $table = 'purchases' | 'sales'. */
function pb_weigh_store(PDO $pdo, int $cid, string $table, int $id, ?array $w): void {
    if ($w === null || ($table !== 'purchases' && $table !== 'sales')) return;
    $pdo->prepare("UPDATE `$table` SET gross_kg=?, tare_kg=?, quality_deduct_kg=?, weigh_ticket_no=?, vehicle_plate=?, driver_name=? WHERE id=? AND customer_id=?")
        ->execute([$w['gross'], $w['tare'], $w['deduct'], $w['ticket'], $w['plate'], $w['driver'], $id, $cid]);
}
