<?php
declare(strict_types=1);
/**
 * S3.T1 — موتور قیمت‌گذاری درصدی (تابع خالص؛ بدون DB، بدون bad()/out()).
 * ورودی:  base_price (ریال/کیلو)، weight_kg، ref_gram (پیش‌فرض 50)،
 *         items: [{pct 0..100, q (قیمت کیلویی همان درصد؛ null = بدون تیک → در قیمت اصلی)، gram_mode (bool)، g (گرم مغز در ۱۰۰ گرم)}]
 * حالت A: میانگین = P·(1 − Σp_i/100) + Σ q_i·p_i/100       (فقط درصدهای دارای q)
 * حالت B: q مؤثر = (q / R) · g     (وقتی gram_mode فعال است؛ 0 < g ≤ 100)
 * گرد: میانگین کیلویی به ریال صحیح؛ مبلغ کل = round(میانگین گردشده × W).
 * خروجی: ['ok'=>true,'avg_per_kg'=>int,'total'=>int,'lines'=>[{pct,q,eff_q,gram_mode,g}]] یا ['ok'=>false,'error'=>'کد']
 */
function pb_price_blend(array $in): array {
    $err = static fn(string $c): array => ['ok' => false, 'error' => $c];
    foreach (['base_price', 'weight_kg'] as $k) {
        if (!isset($in[$k]) || !is_numeric($in[$k]) || (float)$in[$k] < 0) return $err("bad_$k");
    }
    $P = (float)$in['base_price'];
    $W = (float)$in['weight_kg'];
    $R = isset($in['ref_gram']) ? (float)$in['ref_gram'] : 50.0;
    if ($R <= 0) return $err('bad_ref_gram');
    $items = $in['items'] ?? [];
    if (!is_array($items)) return $err('bad_items');
    // خرید گرمیِ پسته اصلی: قیمت پایهٔ مؤثر = (P / R) · base_g
    $baseGm = !empty($in['base_gram_mode']);
    if ($baseGm) {
        $bg = isset($in['base_g']) && $in['base_g'] !== '' && is_numeric($in['base_g']) ? (float)$in['base_g'] : 0.0;
        if ($bg <= 0 || $bg > 100) return $err('bad_base_gram');
        $P = ($P / $R) * $bg;
    }
    $sumAll = 0.0; $sumPriced = 0.0; $acc = 0.0; $lines = [];
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['pct']) || !is_numeric($it['pct'])) return $err('bad_pct');
        $p = (float)$it['pct'];
        if ($p < 0 || $p > 100) return $err('bad_pct');
        $sumAll += $p;
        $q = $it['q'] ?? null;
        $gm = !empty($it['gram_mode']);
        $g = isset($it['g']) && $it['g'] !== null && $it['g'] !== '' ? (float)$it['g'] : null;
        if ($q === null || $q === '') { $lines[] = ['pct' => $p, 'q' => null, 'eff_q' => null, 'gram_mode' => $gm, 'g' => $g]; continue; }
        if (!is_numeric($q) || (float)$q < 0) return $err('bad_q');
        $q = (float)$q;
        $eff = $q;
        if ($gm) {
            if ($g === null || $g <= 0 || $g > 100) return $err('bad_gram');
            $eff = ($q / $R) * $g;
        }
        $sumPriced += $p;
        $acc += $eff * $p / 100.0;
        $lines[] = ['pct' => $p, 'q' => $q, 'eff_q' => $eff, 'gram_mode' => $gm, 'g' => $g];
    }
    if ($sumAll > 100.0000001) return $err('sum_over_100');
    $avg = (int)round(round($P * (1 - $sumPriced / 100.0) + $acc, 6));
    return ['ok' => true, 'avg_per_kg' => $avg, 'total' => (int)round(round($avg * $W, 6)), 'lines' => $lines];
}
