<?php
declare(strict_types=1);
/** تست خودکار lot_cartex (بدون DB). اجرا: php lot_cartex_selftest.php [مسیر cases.json]  — روی هاست: فایل JSON را کنار این فایل بگذارید؛ بعد از اجرا حذف کنید. */
require_once __DIR__ . '/lot_cartex.php';
$cands = [$argv[1] ?? '', __DIR__ . '/lot_cartex_cases.json', __DIR__ . '/../../../WINDOWS/test/lot_cartex_cases.json'];
$file = '';
foreach ($cands as $c) if ($c !== '' && is_file($c)) { $file = $c; break; }
if ($file === '') { echo "lot_cartex_cases.json پیدا نشد\n"; exit(2); }
$doc = json_decode((string)file_get_contents($file), true);
$project = function (array $res): array {
    $ts = [];
    foreach ($res['types'] as $t) {
        $ts[] = ['type_name' => $t['type_name'], 'total_kg' => $t['total_kg'],
            'overview' => array_map(fn($r) => [$r['party'], $r['weight_kg'], $r['remaining_kg'], $r['exit_label']], $t['overview']),
            'normal' => array_map(fn($r) => [$r['party'], $r['weight_kg'], $r['remaining_kg'], $r['exit_type'], $r['exit_date'], $r['ounce'], $r['gram']], $t['normal']),
            'exited' => array_map(fn($r) => [$r['party'], $r['weight_kg'], $r['exit_type'], $r['exit_date']], $t['exited']),
            'kot' => array_map(fn($k) => ['kot_id' => $k['kot_id'], 'total_kg' => $k['total_kg'], 'color_key' => $k['color_key'],
                'weight_kg' => $k['weight_kg'], 'ounce' => $k['ounce'], 'gram' => $k['gram'],
                'items' => array_map(fn($r) => [$r['party'], $r['weight_kg'], $r['ounce'], $r['gram'], $r['remaining_kg']], $k['items'])], $t['kot']),
            'processed' => array_map(fn($g) => ['group_id' => $g['group_id'], 'kind' => $g['kind'], 'order_id' => $g['order_id'], 'color_key' => $g['color_key'],
                'in_kg' => $g['in_kg'], 'out_kg' => $g['out_kg'],
                'items' => array_map(fn($r) => [$r['party'], $r['weight_kg']], $g['items']),
                'outputs' => array_map(fn($o) => [$o['code'], $o['weight_kg'], $o['ounce'], $o['gram']], $g['outputs'])], $t['processed'])];
    }
    return ['types' => $ts];
};
$norm = fn($v) => json_decode(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
$eq = function ($a, $b) use (&$eq): bool {
    if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b)) return false;
        foreach ($a as $k => $v) if (!array_key_exists($k, $b) || !$eq($v, $b[$k])) return false;
        return true;
    }
    if (is_numeric($a) && is_numeric($b)) return abs((float)$a - (float)$b) < 1e-9;
    return $a === $b;
};
$fail = 0;
if (($argv[2] ?? '') === '--dump') { // چاپ خروجی فعلی برای بازتولید expect
    $o = [];
    foreach ($doc['cases'] as $c) $o[$c['name']] = $norm($project(pb_lot_cartex_build($c['lots'], $c['mix_sources'], $c['proc_inputs'], $c['sale_items'], $c['opts'], $c['proc_outputs'] ?? [])));
    echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); exit(0);
}
foreach ($doc['cases'] as $c) {
    $got = $project(pb_lot_cartex_build($c['lots'], $c['mix_sources'], $c['proc_inputs'], $c['sale_items'], $c['opts'], $c['proc_outputs'] ?? []));
    $ok = $eq($norm($got), $c['expect']);
    echo ($ok ? 'OK   ' : 'FAIL ') . $c['name'] . "\n";
    if (!$ok) { $fail++; echo '  got: ' . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n  exp: " . json_encode($c['expect'], JSON_UNESCAPED_UNICODE) . "\n"; }
}
// بازه: ۱۴۰۵ ⇒ ۱ مهر ۱۴۰۵ تا ۳۱ شهریور ۱۴۰۶
$r = pb_lot_cartex_range(1405, 7, 1);
$rangeOk = $r['from'] === '2026-09-23' && $r['to'] === '2027-09-22' && $r['label'] === 'سال پسته‌ای ۱۴۰۵–۱۴۰۶';
echo ($rangeOk ? 'OK   ' : 'FAIL ') . "range_1405 " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
if (!$rangeOk) $fail++;
echo $fail === 0 ? "ALL OK\n" : "FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
