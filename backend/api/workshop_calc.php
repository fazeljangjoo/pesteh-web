<?php
declare(strict_types=1);
/**
 * کارگاه داخلی (مرحلهٔ ۱) — منطق خالص تراز. بدون وابستگی به دیتابیس؛ منبع حقیقت.
 * آینه‌ها: Dart `lib/workshop_calc.dart` (اندروید و ویندوز)؛ تست مشترک: tests/workshop_cases.json.
 *
 * فرمول‌های اکسل («کنترل ورود و خروج تولید»):
 *   H = وزن ورودی خط · I سالم · J بدشکل و ریز · K پوست · L مغز · M خاک · N بدرنگ · O افت وزن · F وزن خالص اولیه
 *   P = J+K+L+M+N+O · Q = I+P · R = H−Q · S = I/H · T = F−H
 *   U = |R| < 0.01 ? «تراز صحیح» : «نیاز به بررسی»
 * محاسبه روی گرمِ صحیح (۳ رقم اعشار کیلو) انجام می‌شود تا مرز ۰٫۰۱ در ممیز شناور جابه‌جا نشود: |R| باید *کمتر* از ۱۰ گرم باشد.
 */
const PB_WS_TOL_G = 10;      // 0.01 kg
const PB_WS_GRADES = ['sound', 'deformed_fine', 'hull', 'kernel', 'soil', 'discolored', 'custom']; // custom = «خروجی جدید» (نام آزاد، چند ردیف مجاز؛ مرحلهٔ ۴)
const PB_WS_GRADE_LABELS = [
    'sound' => 'سالم', 'deformed_fine' => 'بدشکل و ریز', 'hull' => 'پوست', 'kernel' => 'مغز', 'soil' => 'خاک', 'discolored' => 'بدرنگ', 'custom' => 'سایر',
];
const PB_WS_OP_TYPES = ['dehull', 'desoil', 'split', 'fines', 'crack'];
const PB_WS_OP_LABELS = [
    'dehull' => 'پوست‌گیری', 'desoil' => 'خاک‌گیری', 'split' => 'جداسازی پوست و مغز', 'fines' => 'ریزبرداری و بدشکل', 'crack' => 'شکست',
];

function pb_ws_g($kg): int { return (int)round(((float)$kg) * 1000); }

/**
 * @param float|int|string $inputKg  H
 * @param array $outByGrade          ['sound'=>kg, 'deformed_fine'=>kg, 'hull'=>kg, 'kernel'=>kg, 'soil'=>kg, 'discolored'=>kg, 'custom'=>Σ همهٔ ردیف‌های custom] (کلید غایب = ۰)
 * @param float|int|string $lossKg   O
 * @param float|int|string|null $initialKg F (null = ثبت‌نشده)
 * @return array{input_kg:float,sound_kg:float,waste_kg:float,loss_kg:float,out_plus_loss_kg:float,diff_kg:float,sound_pct:?float,undelivered_kg:?float,ok:bool,status:string,label:string}
 */
function pb_ws_balance($inputKg, array $outByGrade, $lossKg = 0, $initialKg = null): array {
    $h = pb_ws_g($inputKg);
    $o = pb_ws_g($lossKg);
    $g = [];
    foreach (PB_WS_GRADES as $k) $g[$k] = pb_ws_g($outByGrade[$k] ?? 0);
    $p = $g['deformed_fine'] + $g['hull'] + $g['kernel'] + $g['soil'] + $g['discolored'] + $g['custom'] + $o; // P (شامل افت و مجموع ردیف‌های custom)
    $q = $g['sound'] + $p;                                                                  // Q
    $r = $h - $q;                                                                           // R
    $ok = abs($r) < PB_WS_TOL_G;
    return [
        'input_kg' => $h / 1000, 'sound_kg' => $g['sound'] / 1000, 'waste_kg' => $p / 1000, 'loss_kg' => $o / 1000,
        'out_plus_loss_kg' => $q / 1000, 'diff_kg' => $r / 1000,
        'sound_pct' => $h > 0 ? round($g['sound'] / $h * 100, 3) : null,
        'undelivered_kg' => ($initialKg === null || $initialKg === '') ? null : (pb_ws_g($initialKg) - $h) / 1000,
        'ok' => $ok, 'status' => $ok ? 'ok' : 'check', 'label' => $ok ? 'تراز صحیح' : 'نیاز به بررسی',
    ];
}

/**
 * مرحلهٔ ۲ — بهای تمام‌شدهٔ کارگاه داخلی (فرمول اکسل؛ ریال). تابع خالص، منبع حقیقت.
 *   W = H*V · Z = H*X + Y · AF = J*AA + K*AC + L*AE + M*AD + N*AB (افت O ارزش ندارد) · AG = W + Z − AF · AH = AG/I (I=0 ⇒ null)
 * مبالغ تا ۲ رقم اعشار گرد می‌شوند؛ AF مجموع «بهای گردشدهٔ لات‌های فرعی» است و AG = W + Z − AF دقیقاً حفظ می‌شود
 * (باقی‌ماندهٔ گرد کردن در لات سالم می‌ماند). AG منفی مجاز است ⇒ فقط warning='negative_cost'، رد نمی‌شود.
 * آینه‌ها: Dart `lib/workshop_calc.dart` (دو پروژه)؛ تست مشترک: tests/workshop_cost_cases.json.
 */
const PB_WS_PRICE_KEYS = ['deformed_fine' => 'price_deformed', 'hull' => 'price_hull', 'kernel' => 'price_kernel', 'soil' => 'price_soil', 'discolored' => 'price_discolored'];

function pb_ws_r2(float $v): float { $r = round($v, 2); return $r == 0.0 ? 0.0 : $r; }

/**
 * @param float|int|string $inputKg H
 * @param array $outByGrade کیلوی هر دسته (مثل pb_ws_balance)
 * @param array $pr ['buy_per_kg'=>V,'wage_per_kg'=>X,'other_cost'=>Y,'price_deformed'=>AA,'price_discolored'=>AB,'price_hull'=>AC,'price_soil'=>AD,'price_kernel'=>AE] (غایب/null = ۰)
 *   مرحلهٔ ۴: کلید اختیاری 'outs' => [['grade'=>,'kg'=>,'price'=>],…] (به ترتیب ثبت). اگر هست AF = Σ r2(kg×price) روی ردیف‌های غیرسالم (هر ردیف جدا، شامل چند ردیف custom)
 *   و کلیدهای price_* نادیده‌اند؛ اگر نیست رفتار قدیمی (قیمت هر دسته × وزن دسته) دقیقاً حفظ می‌شود. sub_values همیشه ۵ کلید استاندارد دارد؛ کلید 'custom' فقط وقتی ردیف custom هست.
 * @return array{value_in:float,cost_total:float,recovery_value:float,cost_sound:float,cost_per_kg_sound:?float,sub_values:array,sound_kg:float,warning:?string}
 */
function pb_ws_cost($inputKg, array $outByGrade, array $pr): array {
    $n = fn($k) => (float)($pr[$k] ?? 0);
    $h = pb_ws_g($inputKg) / 1000;
    $w = pb_ws_r2($h * $n('buy_per_kg'));
    $z = pb_ws_r2($h * $n('wage_per_kg') + $n('other_cost'));
    $af = 0.0; $sub = [];
    if (isset($pr['outs']) && is_array($pr['outs'])) {
        foreach (array_keys(PB_WS_PRICE_KEYS) as $grade) $sub[$grade] = 0.0;
        foreach ($pr['outs'] as $o) {
            $grade = (string)($o['grade'] ?? '');
            if ($grade === 'sound') continue;
            $v = pb_ws_r2((pb_ws_g($o['kg'] ?? 0) / 1000) * (float)($o['price'] ?? 0));
            $sub[$grade] = pb_ws_r2(($sub[$grade] ?? 0.0) + $v);
            $af += $v;
        }
    } else {
        foreach (PB_WS_PRICE_KEYS as $grade => $key) {
            $kg = pb_ws_g($outByGrade[$grade] ?? 0) / 1000;
            $v = pb_ws_r2($kg * $n($key));
            $sub[$grade] = $v;
            $af += $v;
        }
    }
    $af = pb_ws_r2($af);
    $ag = pb_ws_r2($w + $z - $af);
    $i = pb_ws_g($outByGrade['sound'] ?? 0) / 1000;
    return ['value_in' => $w, 'cost_total' => $z, 'recovery_value' => $af, 'cost_sound' => $ag,
        'cost_per_kg_sound' => $i > 0 ? round($ag / $i, 4) : null, 'sub_values' => $sub, 'sound_kg' => $i,
        'warning' => $ag < 0 ? 'negative_cost' : null];
}

/**
 * تخصیص بها به لات‌های خروجی: لات فرعی = kg×قیمت دسته (گرد ۲رقم)، لات‌های سالم = AG به نسبت وزن
 * (باقی‌ماندهٔ گرد کردن روی آخرین لات سالم) ⇒ Σ همهٔ cost ها = W + Z (و Σ سالم = AG دقیق).
 * @param array $outs [['grade'=>..,'kg'=>..,'price'=>..?], ...] به ترتیب ثبت. 'price' (اختیاری، مرحلهٔ ۴) قیمت هر کیلوی همان ردیف است؛ غایب/null ⇒ قیمت
 *   دستهٔ استاندارد از $pr (price_*) و برای custom صفر.
 * @return float[] cost هر خروجی (هم‌اندیس ورودی)
 */
function pb_ws_alloc(array $outs, array $pr, array $cost): array {
    $res = array_fill(0, count($outs), 0.0);
    $soundIdx = []; $soundKg = 0.0;
    foreach ($outs as $i => $o) {
        $g = (string)$o['grade'];
        if ($g === 'sound') { $soundIdx[] = $i; $soundKg += (float)$o['kg']; continue; }
        $price = ($o['price'] ?? null) !== null ? (float)$o['price'] : (float)(isset(PB_WS_PRICE_KEYS[$g]) ? ($pr[PB_WS_PRICE_KEYS[$g]] ?? 0) : 0);
        $res[$i] = pb_ws_r2((float)$o['kg'] * $price);
    }
    // AF واقعی = مجموع بهای لات‌های فرعی گردشده (ممکن است با فرمول تجمیعی چند سنت فرق کند)
    $afLots = 0.0;
    foreach ($outs as $i => $o) if ((string)$o['grade'] !== 'sound') $afLots += $res[$i];
    $pool = pb_ws_r2($cost['value_in'] + $cost['cost_total'] - $afLots);
    $left = $pool;
    foreach ($soundIdx as $k => $i) {
        if ($k === count($soundIdx) - 1) { $res[$i] = pb_ws_r2($left); break; }
        $part = $soundKg > 0 ? pb_ws_r2($pool * ((float)$outs[$i]['kg'] / $soundKg)) : 0.0;
        $res[$i] = $part; $left -= $part;
    }
    return $res;
}

/**
 * مرحلهٔ ۳-الف — گزارش خط شکست (برگهٔ اکسل «گزارش خط شکست»). تابع خالص، منبع حقیقت.
 *   N = L*M/100 (برآورد مغز خروجی) · X = U/T*60 (ظرفیت واقعی kg/h؛ T خالی/۰ ⇒ null) · AJ = L · AL = L−U
 *   AP = M − (AK*100)/AJ  (ستون بدون سرتیتر؛ معنی تأییدنشده ⇒ کلید `ap_unconfirmed`؛ AK یا AJ خالی/۰ ⇒ null)
 *   loss_pct = AL/AJ*100 (افت درصدی؛ متن نیازمندی «افت کیلویی و درصدی»؛ AO اکسل تأییدنشده است)
 *   stop_count (AN) = تعداد توقف‌ها · stop_total_min = مجموع دقیقهٔ توقف‌ها (فیلد جدید؛ از فهرست توقف‌ها)
 *   S2 (افزودنی): sub_sum_kg=ΣAA..AH · sub_mismatch · non_kernel_kg=L−U · real_loss_kg/pct=L−(U+V+W) · over_output · shortfall_kg/pct=N−U · sound_pct_input=AA/L · sound_pct_kernel=AA/U.
 *   U خالی ⇒ کلیدهای real_loss/shortfall/sound_pct_kernel/sub_mismatch = null؛ V و W خالی = ۰.
 * kg روی گرم صحیح، دقیقه روی صدم صحیح؛ خروجی‌ها ۳ رقم اعشار. آینه: Dart `lib/workshop_calc.dart`؛ تست مشترک: tests/crack_cases.json.
 * @param array{input_kg?:mixed,gram_pct?:mixed,kernel_kg?:mixed,run_minutes?:mixed,initial_kernel_kg?:mixed,stops?:array} $in  stops = [دقیقه, ...] یا [['minutes'=>..], ...]
 */
function pb_cr_calc(array $in): array {
    $nul = fn($v) => ($v === null || $v === '') ? null : (float)$v;
    $gn = fn($v) => ($v === null || $v === '') ? null : pb_ws_g($v); // گرم صحیح یا null (خالی)
    $lg = pb_ws_g($in['input_kg'] ?? 0);
    $ug = pb_ws_g($in['kernel_kg'] ?? 0);
    $uN = $gn($in['kernel_kg'] ?? null); // U خالی ⇒ null (برای کلیدهای تراز)
    $m = $nul($in['gram_pct'] ?? null);
    $t = $nul($in['run_minutes'] ?? null);
    $ak = $nul($in['initial_kernel_kg'] ?? null);
    $stopCents = 0; $stopN = 0;
    foreach (($in['stops'] ?? []) as $s) {
        $mn = is_array($s) ? ($s['minutes'] ?? 0) : $s;
        $stopCents += (int)round(((float)$mn) * 100);
        $stopN++;
    }
    $lossG = $lg - $ug;
    // ── S2: تراز واقعی (افزودنی) ──
    $subG = 0; $subAny = false;
    foreach (['sound_kg', 'wheat_kg', 'super_fine_kg', 'flower_kg', 'split_kg', 'unbreakable_kg', 'pink_kg', 'waste_kernel_kg'] as $k) { // AA..AH
        $g = $gn($in[$k] ?? null);
        if ($g !== null) { $subG += $g; $subAny = true; }
    }
    $aaG = $gn($in['sound_kg'] ?? null);
    $vG = $gn($in['hull_kg'] ?? null) ?? 0;  // V خالی = ۰
    $wG = $gn($in['soil_kg'] ?? null) ?? 0;  // W خالی = ۰
    $nG = $m === null ? null : (int)round($lg * $m / 100);
    $realG = $uN === null ? null : $lg - ($uN + $vG + $wG);
    $shortG = ($nG === null || $uN === null) ? null : $nG - $uN;
    $mismatch = null;
    if ($uN !== null && $subAny) {
        $mismatch = ($subG > 0 && $uN > 0 && abs($uN - $subG) > max(1000, $uN * 0.001));
    }
    return [
        'estimated_kernel_kg' => $nG === null ? null : $nG / 1000,                                   // N
        'capacity_kgh'        => ($t === null || $t <= 0) ? null : round(($ug / 1000) * 60 / $t, 3), // X
        'input_kg'            => $lg / 1000,                                                         // AJ
        'loss_kg'             => $lossG / 1000,                                                      // AL
        'loss_pct'            => $lg > 0 ? round($lossG / $lg * 100, 3) : null,
        'ap_unconfirmed'      => ($m === null || $ak === null || $lg <= 0) ? null : round($m - ($ak * 100) / ($lg / 1000), 3), // AP
        'stop_count'          => $stopN,                                                             // AN
        'stop_total_min'      => $stopCents / 100,
        // S2 — کلیدهای جدید
        'sub_sum_kg'          => $subAny ? $subG / 1000 : null,                                      // AA+…+AH
        'sub_mismatch'        => $mismatch,                                                          // true/false/null
        'non_kernel_kg'       => $lossG / 1000,                                                      // L−U (= loss_kg)
        'real_loss_kg'        => $realG === null ? null : $realG / 1000,                             // L−(U+V+W)
        'real_loss_pct'       => ($realG === null || $lg <= 0) ? null : round($realG / $lg * 100, 3),
        'over_output'         => $realG !== null && $realG < 0,
        'shortfall_kg'        => $shortG === null ? null : $shortG / 1000,                           // N−U
        'shortfall_pct'       => ($shortG === null || $nG <= 0) ? null : round($shortG / $nG * 100, 3),
        'sound_pct_input'     => ($aaG === null || $lg <= 0) ? null : round($aaG / $lg * 100, 3),   // AA/L
        'sound_pct_kernel'    => ($aaG === null || $uN === null || $uN <= 0) ? null : round($aaG / $uN * 100, 3), // AA/U
    ];
}

/**
 * مرحلهٔ ۴ — «فایل خام شکست کارگاه‌ها» (کارگاه بیرونی با قبض؛ برگهٔ اکسل A..K). تابع خالص، منبع حقیقت.
 *   E = وزن پستهٔ ارسالی (کیلو) · F = گرم پستهٔ ارسالی (٪) · G = F*E/100 (وزن مغز برآوردی)
 *   H = مغز دریافتی (کیلو) · I = گرم دریافتی (٪) · J = G−H (تخمین مغز باقی‌مانده) · K = F−I (افت؛ نقطهٔ درصد گرم، نه کیلو)
 * ردیف جمع (اکسل): E=ΣE · G=ΣG · H=ΣH · J=G−H · K=میانگین F − میانگین I (S3-الف؛ درصدها جمع نمی‌شوند). برای هر ردیف هم J و K با همین فرمول‌ها مشتق می‌شوند
 * (اکسل فقط G را برای هر ردیف فرمول دارد؛ مشتق‌کردن J/K با جمع‌ها سازگار است: ΣG−ΣH = Σ(G−H)).
 * مقدار خالی ⇒ null (G: F خالی؛ J: G یا H خالی؛ K: F یا I خالی). kg روی گرم صحیح؛ خروجی ۳ رقم. آینه: Dart `outsideCalc` / `outsideTotals`؛ تست مشترک: tests/outside_cases.json.
 * @param array{sent_kg?:mixed,sent_gram_pct?:mixed,recv_kernel_kg?:mixed,recv_gram_pct?:mixed} $in
 */
function pb_ow_calc(array $in): array {
    $nul = fn($v) => ($v === null || $v === '') ? null : (float)$v;
    $e = pb_ws_g($in['sent_kg'] ?? 0);
    $f = $nul($in['sent_gram_pct'] ?? null);
    $h = $nul($in['recv_kernel_kg'] ?? null);
    $i = $nul($in['recv_gram_pct'] ?? null);
    $gG = $f === null ? null : (int)round($e * $f / 100);
    $hG = $h === null ? null : pb_ws_g($h);
    return [
        'est_kernel_kg'       => $gG === null ? null : $gG / 1000,                                  // G
        'remaining_kernel_kg' => ($gG === null || $hG === null) ? null : ($gG - $hG) / 1000,       // J
        'loss_gram'           => ($f === null || $i === null) ? null : round($f - $i, 3),          // K
    ];
}

/**
 * ردیف جمع «فایل خام شکست کارگاه‌ها». @param array[] $rows هر ردیف: خروجی ذخیره‌شدهٔ sent_kg/sent_gram_pct/recv_kernel_kg/recv_gram_pct
 * S3-الف: گرم‌ها درصدند و جمع‌شان معنا ندارد ⇒ `sent_gram_avg`/`recv_gram_avg` = میانگین ساده F و I **فقط روی ردیف‌هایی که هر دو را دارند**
 * (`gram_pair_count` = تعداد آن ردیف‌ها) و `loss_gram` = sent_gram_avg − recv_gram_avg (نقطهٔ درصد؛ برابر میانگین K ردیف‌های جفت‌دار، با خطای گرد‌کردن ≤ ۰٫۰۰۱).
 * کلیدهای قدیمی `sent_gram_sum`/`recv_gram_sum` (جمع روی همهٔ ردیف‌های دارای مقدار) برای سازگاری می‌مانند ولی UI نباید استفاده کند. بدون جفت ⇒ سه کلید جدید null.
 * محاسبهٔ درصدها روی «هزارم» صحیح (۳ رقم اعشار) تا خطای ممیز شناور نباشد. آینه: Dart `outsideTotals`؛ تست مشترک: tests/outside_cases.json.
 */
function pb_ow_totals(array $rows): array {
    $eG = 0; $gG = 0; $hG = 0; $f = 0; $i = 0; $hasF = false; $hasI = false; $pf = 0; $pi = 0; $pn = 0;
    $has = fn($v) => $v !== null && $v !== '';
    foreach ($rows as $r) {
        $c = pb_ow_calc($r);
        $eG += pb_ws_g($r['sent_kg'] ?? 0);
        if ($c['est_kernel_kg'] !== null) $gG += pb_ws_g($c['est_kernel_kg']);
        if ($has($r['recv_kernel_kg'] ?? null)) $hG += pb_ws_g($r['recv_kernel_kg']);
        $fm = $has($r['sent_gram_pct'] ?? null) ? (int)round((float)$r['sent_gram_pct'] * 1000) : null;
        $im = $has($r['recv_gram_pct'] ?? null) ? (int)round((float)$r['recv_gram_pct'] * 1000) : null;
        if ($fm !== null) { $f += $fm; $hasF = true; }
        if ($im !== null) { $i += $im; $hasI = true; }
        if ($fm !== null && $im !== null) { $pf += $fm; $pi += $im; $pn++; }
    }
    $fAvg = $pn > 0 ? (int)round($pf / $pn) : null; $iAvg = $pn > 0 ? (int)round($pi / $pn) : null;
    return [
        'sent_kg' => $eG / 1000, 'est_kernel_kg' => $gG / 1000, 'recv_kernel_kg' => $hG / 1000,
        'remaining_kernel_kg' => ($gG - $hG) / 1000,
        'sent_gram_sum' => $hasF ? $f / 1000 : null, 'recv_gram_sum' => $hasI ? $i / 1000 : null, // قدیمی؛ UI استفاده نکند
        'sent_gram_avg' => $fAvg === null ? null : $fAvg / 1000,
        'recv_gram_avg' => $iAvg === null ? null : $iAvg / 1000,
        'gram_pair_count' => $pn,
        'loss_gram' => $fAvg === null ? null : ($fAvg - $iAvg) / 1000,
    ];
}

/**
 * مرحلهٔ ۵ — «موجودی پستهٔ خرجی» (نمای جدا از موجودی انبار اصلی؛ ترکیب خودکار + تعدیل دستی). تابع خالص، منبع حقیقت.
 *   source=outside (کارگاه بیرونی/paid، proc_orders): in_progress ⇒ ارسالی=input_kg، برگشتی=۰، مانده=ارسالی
 *       done ⇒ برگشتی=output_kg (مغز+پوست+خاک و همهٔ خروجی‌های ثبت‌شده که به انبار برمی‌گردند)، ضایعات=waste_kg، مانده=ارسالی−برگشتی−ضایعات
 *   source=crack (خط شکست، crack_runs): ارسالی=input_kg، برگشتی=kernel_kg+hull_kg+soil_kg (U+V+W اکسل؛ ستون‌های AA..AH تفکیک مغزند و جمع نمی‌شوند؛ hull_left_kg جمع نمی‌شود)، مانده=ارسالی−برگشتی (شامل افت)
 *   source=adjust (تعدیل دستی): kind=in ⇒ ارسالی=kg · kind=out ⇒ برگشتی=kg
 * kg روی گرم صحیح؛ خروجی ۳ رقم. هیچ مبلغی در این گزارش نیست. آینه: Dart `feedCalc` / `feedTotals`؛ تست مشترک: tests/feed_cases.json.
 * @param array{source?:string,status?:string,kind?:string,input_kg?:mixed,output_kg?:mixed,waste_kg?:mixed,kernel_kg?:mixed,hull_kg?:mixed,soil_kg?:mixed,kg?:mixed} $in
 */
function pb_feed_calc(array $in): array {
    $src = (string)($in['source'] ?? '');
    $sent = 0; $ret = 0; $waste = 0;
    if ($src === 'outside') {
        $sent = pb_ws_g($in['input_kg'] ?? 0);
        if (($in['status'] ?? '') === 'done') { $ret = pb_ws_g($in['output_kg'] ?? 0); $waste = pb_ws_g($in['waste_kg'] ?? 0); }
    } elseif ($src === 'crack') {
        $sent = pb_ws_g($in['input_kg'] ?? 0);
        $ret = pb_ws_g($in['kernel_kg'] ?? 0) + pb_ws_g($in['hull_kg'] ?? 0) + pb_ws_g($in['soil_kg'] ?? 0);
    } elseif ($src === 'adjust') {
        $g = pb_ws_g($in['kg'] ?? 0);
        if (($in['kind'] ?? '') === 'out') $ret = $g; else $sent = $g;
    }
    return ['sent_kg' => $sent / 1000, 'returned_kg' => $ret / 1000, 'waste_kg' => $waste / 1000, 'remaining_kg' => ($sent - $ret - $waste) / 1000];
}

/**
 * جمع‌ها + `summary` کارت صفحهٔ اصلی. هر ردیف: خروجی pb_feed_calc + کلید `workshop` (متن).
 * workshops_count = تعداد کارگاه‌های متمایز با مانده ≠ ۰ · open_count = تعداد ردیف‌های با مانده > ۰.
 * @param array[] $rows
 */
function pb_feed_totals(array $rows): array {
    $s = 0; $r = 0; $w = 0; $open = 0; $ws = [];
    foreach ($rows as $x) {
        $sg = pb_ws_g($x['sent_kg'] ?? 0); $rg = pb_ws_g($x['returned_kg'] ?? 0); $wg = pb_ws_g($x['waste_kg'] ?? 0);
        $s += $sg; $r += $rg; $w += $wg;
        $rem = $sg - $rg - $wg;
        if ($rem > 0) $open++;
        if ($rem !== 0 && trim((string)($x['workshop'] ?? '')) !== '') $ws[trim((string)$x['workshop'])] = true;
    }
    $bal = ($s - $r - $w) / 1000;
    return [
        'sent_kg' => $s / 1000, 'returned_kg' => $r / 1000, 'waste_kg' => $w / 1000, 'remaining_kg' => $bal,
        'summary' => ['total_sent_kg' => $s / 1000, 'total_returned_kg' => $r / 1000, 'total_balance_kg' => $bal, 'workshops_count' => count($ws), 'open_count' => $open],
    ];
}

/**
 * مرحلهٔ ۶ — «کارتکس مالک» (برگهٔ اکسل «کارتکس»: A تاریخ · B خرید/فروش · C مالک · D عدل · E ورود · F خروج · G انس · H گرم · I دهنبست · J مانده · K توضیحات).
 * تابع خالص، منبع حقیقت. منبع فعلی فقط «کارگاه بیرونی با قبض» است (تصمیم کاربر): ارسال = خروج (F) · دریافت = ورود (E)؛ ضایعات برنمی‌گردد (فقط در توضیحات).
 * هر رویداد: {date 'YYYY-MM-DD', ord (ترتیب هم‌تاریخ؛ کوچک‌تر اول), event (send|receive|crack|adjust)، owner, bale_count?, in_kg?, out_kg?, gram_pct?, note?, ref_id?}.
 * مانده (J) = مانده قبلیِ **همان مالک** + E − F (تجمعی به‌ازای هر مالک، به ترتیب date سپس ord سپس ترتیب ورودی).
 * `from`: رویدادهای قبل از آن ردیف نمی‌شوند ولی در `opening_kg` (مانده افتتاحیه) هر مالک و مانده تجمعی می‌آیند · `to`: رویدادهای بعد از آن حذف می‌شوند.
 * S3-ب: `balance_total_kg` هر ردیف = مانده تجمعی **همهٔ مالک‌ها** با همان ترتیب (date، ord، ترتیب ورودی) = زنجیرهٔ J اکسل؛ رویدادهای قبل از `from` هم در آن لحاظ می‌شوند. با یک مالک برابر `balance_kg` است. `totals.balance_total_kg`/`opening_total_kg` افزودنی‌اند (آخرین مانده سراسری / افتتاحیهٔ کل).
 * ستون‌های G (انس) و I (دهنبست) همیشه null (تصمیم کاربر). H = گرم٪ از قبض (۳ رقم) یا null. هیچ قیمتی در ورودی/خروجی نیست.
 * kg روی گرم صحیح، خروجی ۳ رقم. آینه: Dart `cartexBuild`؛ تست مشترک: tests/cartex_cases.json.
 * خروجی: {rows:[{date,event,owner,bale_count,in_kg,out_kg,ounce,gram_pct,dahanbast,balance_kg,note,ref_id}], owners:[{owner,opening_kg,in_kg,out_kg,balance_kg}] (به ترتیب اولین ظهور), totals:{opening_kg,in_kg,out_kg,balance_kg}}
 * @param array[] $events
 */
function pb_cartex_build(array $events, ?string $from = null, ?string $to = null): array {
    $from = ($from === null || $from === '') ? null : $from;
    $to = ($to === null || $to === '') ? null : $to;
    $idx = [];
    foreach (array_values($events) as $i => $e) $idx[] = ['i' => $i, 'e' => $e];
    usort($idx, function ($a, $b) {
        $c = strcmp((string)($a['e']['date'] ?? ''), (string)($b['e']['date'] ?? ''));
        if ($c !== 0) return $c;
        $o = (int)($a['e']['ord'] ?? 0) <=> (int)($b['e']['ord'] ?? 0);
        return $o !== 0 ? $o : $a['i'] <=> $b['i'];
    });
    $bal = []; $open = []; $inn = []; $out = []; $order = []; $rows = []; $gbal = 0; // S3-ب: مانده سراسری (همهٔ مالک‌ها) مثل زنجیرهٔ J اکسل
    foreach ($idx as $x) {
        $e = $x['e'];
        $d = (string)($e['date'] ?? '');
        if ($to !== null && $d > $to) continue;
        $o = trim((string)($e['owner'] ?? ''));
        if (!isset($bal[$o])) { $bal[$o] = 0; $open[$o] = 0; $inn[$o] = 0; $out[$o] = 0; $order[] = $o; }
        $ig = pb_ws_g($e['in_kg'] ?? 0); $og = pb_ws_g($e['out_kg'] ?? 0);
        $bal[$o] += $ig - $og; $gbal += $ig - $og;
        if ($from !== null && $d < $from) { $open[$o] += $ig - $og; continue; }
        $inn[$o] += $ig; $out[$o] += $og;
        $gp = $e['gram_pct'] ?? null;
        $bc = $e['bale_count'] ?? null;
        $rows[] = ['date' => $d, 'event' => (string)($e['event'] ?? ''), 'owner' => $o,
            'bale_count' => ($bc === null || $bc === '') ? null : (float)$bc,
            'in_kg' => $ig / 1000, 'out_kg' => $og / 1000, 'ounce' => null,
            'gram_pct' => ($gp === null || $gp === '') ? null : round((float)$gp, 3), 'dahanbast' => null,
            'balance_kg' => $bal[$o] / 1000, 'balance_total_kg' => $gbal / 1000, 'note' => (string)($e['note'] ?? ''), 'ref_id' => $e['ref_id'] ?? null];
    }
    $owners = []; $to_ = ['o' => 0, 'i' => 0, 'u' => 0, 'b' => 0];
    foreach ($order as $o) {
        $owners[] = ['owner' => $o, 'opening_kg' => $open[$o] / 1000, 'in_kg' => $inn[$o] / 1000, 'out_kg' => $out[$o] / 1000, 'balance_kg' => $bal[$o] / 1000];
        $to_['o'] += $open[$o]; $to_['i'] += $inn[$o]; $to_['u'] += $out[$o]; $to_['b'] += $bal[$o];
    }
    return ['rows' => $rows, 'owners' => $owners,
        'totals' => ['opening_kg' => $to_['o'] / 1000, 'in_kg' => $to_['i'] / 1000, 'out_kg' => $to_['u'] / 1000, 'balance_kg' => $to_['b'] / 1000,
            'opening_total_kg' => $to_['o'] / 1000, 'balance_total_kg' => $gbal / 1000]]; // دو کلید آخر S3-ب (افزودنی)
}

/**
 * مرحلهٔ ۶-۲ — «خلاصهٔ مدیریتی» (برگهٔ اکسل؛ جمع ستون‌های «کنترل ورود و خروج تولید») — کارگاه داخلی. تابع خالص، منبع حقیقت.
 * نام ستون‌ها (تصمیم کاربر؛ مطابق برگهٔ ورودی/خروجی): F تحویل انبار · H ورود خط · I سالم · J بدشکل و ریز · K پوست · L مغز · M خاک · N بدرنگ · O افت وزن
 * · P جمع ضایعات (J..O) · R اختلاف تراز · T مانده تحویل‌نشده · درصد بازده سالم = ΣI/ΣH. «سایر ضایعات» حذف شده (تکرار N بود).
 * هر ردیف با pb_ws_balance حساب می‌شود (P/R همان فرمول مرحلهٔ ۱)؛ جمع روی گرم صحیح. بدون قیمت. آینه: Dart `wsumInternal`؛ تست: tests/summary_cases.json.
 * @param array[] $rows هر ردیف: {initial_kg?, input_kg, loss_kg, sound_kg, deformed_fine_kg, hull_kg, kernel_kg, soil_kg, discolored_kg}
 */
function pb_wsum_internal(array $rows): array {
    $t = ['f' => 0, 'h' => 0, 'i' => 0, 'j' => 0, 'k' => 0, 'l' => 0, 'm' => 0, 'n' => 0, 'o' => 0, 'p' => 0, 'r' => 0, 't' => 0];
    $check = 0;
    foreach ($rows as $x) {
        $ini = $x['initial_kg'] ?? null;
        $b = pb_ws_balance($x['input_kg'] ?? 0, ['sound' => $x['sound_kg'] ?? 0, 'deformed_fine' => $x['deformed_fine_kg'] ?? 0, 'hull' => $x['hull_kg'] ?? 0,
            'kernel' => $x['kernel_kg'] ?? 0, 'soil' => $x['soil_kg'] ?? 0, 'discolored' => $x['discolored_kg'] ?? 0], $x['loss_kg'] ?? 0, $ini);
        if ($ini !== null && $ini !== '') { $t['f'] += pb_ws_g($ini); $t['t'] += pb_ws_g($ini) - pb_ws_g($x['input_kg'] ?? 0); }
        $t['h'] += pb_ws_g($x['input_kg'] ?? 0); $t['i'] += pb_ws_g($x['sound_kg'] ?? 0); $t['j'] += pb_ws_g($x['deformed_fine_kg'] ?? 0);
        $t['k'] += pb_ws_g($x['hull_kg'] ?? 0); $t['l'] += pb_ws_g($x['kernel_kg'] ?? 0); $t['m'] += pb_ws_g($x['soil_kg'] ?? 0);
        $t['n'] += pb_ws_g($x['discolored_kg'] ?? 0); $t['o'] += pb_ws_g($x['loss_kg'] ?? 0);
        $t['p'] += pb_ws_g($b['waste_kg']); $t['r'] += pb_ws_g($b['diff_kg']);
        if (!$b['ok']) $check++;
    }
    return ['orders_count' => count($rows), 'delivered_kg' => $t['f'] / 1000, 'input_kg' => $t['h'] / 1000, 'sound_kg' => $t['i'] / 1000,
        'deformed_fine_kg' => $t['j'] / 1000, 'hull_kg' => $t['k'] / 1000, 'kernel_kg' => $t['l'] / 1000, 'soil_kg' => $t['m'] / 1000,
        'discolored_kg' => $t['n'] / 1000, 'loss_kg' => $t['o'] / 1000, 'waste_total_kg' => $t['p'] / 1000, 'diff_kg' => $t['r'] / 1000,
        'undelivered_kg' => $t['t'] / 1000, 'sound_pct' => $t['h'] > 0 ? round($t['i'] / $t['h'] * 100, 3) : null, 'check_count' => $check];
}

/**
 * مرحلهٔ ۶-۲ — بخش «خط شکست» در خلاصهٔ مدیریتی (تصمیم کاربر: دخیل باشد). تابع خالص.
 * ورودی = ΣL (input) · مغز خروجی = ΣU (kernel) · پوست = ΣV · خاک = ΣW · افت = Σ(L−U) (AL) · درصد افت = افت/ورودی · درصد مغز = مغز/ورودی.
 * «برگشتی همهٔ خروجی‌ها» (تصمیم کاربر) = مغز + پوست + خاک + مجموع زیرمجموعه‌های مغز (AA..AH: سالم، گندمی، سوپر ریز، گلدار، لپه و شکسته، ناشکن، کال و صورتی، ضایعاتی).
 * ⚠️ اگر زیرمجموعه‌ها بخشی از وزن مغز (U) باشند این مجموع دوبار می‌شمارد؛ برای همین اجزا جدا هم برمی‌گردند (kernel_kg، sub_kernel_kg). hull_left_kg جمع نمی‌شود.
 * ظرفیت (kg/h) = Σ مغز نوبت‌هایی که زمان کارکرد > ۰ دارند × ۶۰ ÷ Σ دقیقهٔ همان نوبت‌ها. دقیقه روی صدم صحیح. آینه: Dart `wsumCrack`.
 * @param array[] $rows {input_kg, kernel_kg, hull_kg, soil_kg, sound_kg, wheat_kg, super_fine_kg, flower_kg, split_kg, unbreakable_kg, pink_kg, waste_kernel_kg, run_minutes?, stop_minutes?}
 */
const PB_WSUM_SUB_KEYS = ['sound_kg', 'wheat_kg', 'super_fine_kg', 'flower_kg', 'split_kg', 'unbreakable_kg', 'pink_kg', 'waste_kernel_kg'];
function pb_wsum_crack(array $rows): array {
    $in = 0; $k = 0; $h = 0; $s = 0; $sub = 0; $capK = 0; $capM = 0; $runM = 0; $stopM = 0;
    foreach ($rows as $x) {
        $in += pb_ws_g($x['input_kg'] ?? 0); $k += pb_ws_g($x['kernel_kg'] ?? 0); $h += pb_ws_g($x['hull_kg'] ?? 0); $s += pb_ws_g($x['soil_kg'] ?? 0);
        foreach (PB_WSUM_SUB_KEYS as $key) $sub += pb_ws_g($x[$key] ?? 0);
        $m = (int)round(((float)($x['run_minutes'] ?? 0)) * 100);
        $runM += max(0, $m); $stopM += (int)round(((float)($x['stop_minutes'] ?? 0)) * 100);
        if ($m > 0) { $capK += pb_ws_g($x['kernel_kg'] ?? 0); $capM += $m; }
    }
    return ['runs_count' => count($rows), 'input_kg' => $in / 1000, 'kernel_kg' => $k / 1000, 'hull_kg' => $h / 1000, 'soil_kg' => $s / 1000,
        'sub_kernel_kg' => $sub / 1000, 'returned_all_kg' => ($k + $h + $s + $sub) / 1000, 'loss_kg' => ($in - $k) / 1000,
        'loss_pct' => $in > 0 ? round(($in - $k) / $in * 100, 3) : null, 'kernel_pct' => $in > 0 ? round($k / $in * 100, 3) : null,
        'capacity_kgh' => $capM > 0 ? round(($capK / 1000) * 60 / ($capM / 100), 3) : null,
        'run_minutes' => $runM / 100, 'stop_minutes' => $stopM / 100];
}

/**
 * مرحلهٔ ۶-۲ — «مقایسهٔ کارگاه‌ها/اپراتورها». تابع خالص: آیتم‌ها را به‌ازای (source, name) گروه می‌کند و شاخص‌ها را می‌دهد.
 * آیتم: {source (internal|outside|crack), name, input_kg, good_kg, loss_kg, cap_kg?, cap_min?, gram_loss?}
 *   good_kg: داخلی = سالم · بیرونی (فقط سفارش‌های دریافت‌شده) = خروجی برگشتی · خط شکست = مغز خروجی.
 *   loss_kg: داخلی = افت وزن (O) · بیرونی = ضایعات ثبت‌شده · شکست = ورودی−مغز (AL).
 *   cap_kg/cap_min: فقط نوبت‌هایی با زمان کارکرد > ۰ (برای ظرفیت kg/h) · gram_loss: افت گرم بیرونی (نقطهٔ درصد؛ F−I) یا null.
 * خروجی هر گروه: {source,name,count,input_kg,good_kg,loss_kg,yield_pct,loss_pct,capacity_kgh,avg_gram_loss}؛ مرتب: بازده نزولی (null آخر) ← نام ← source.
 * بدون قیمت. آینه: Dart `wcmpBuild`؛ تست: tests/compare_cases.json.
 * @param array[] $items
 */
function pb_wcmp_build(array $items): array {
    $g = []; $order = [];
    foreach ($items as $x) {
        $key = (string)($x['source'] ?? '') . "\x1f" . trim((string)($x['name'] ?? ''));
        if (!isset($g[$key])) { $g[$key] = ['source' => (string)($x['source'] ?? ''), 'name' => trim((string)($x['name'] ?? '')), 'n' => 0, 'in' => 0, 'good' => 0, 'loss' => 0, 'ck' => 0, 'cm' => 0, 'gl' => 0, 'gn' => 0]; $order[] = $key; }
        $r = &$g[$key];
        $r['n']++; $r['in'] += pb_ws_g($x['input_kg'] ?? 0); $r['good'] += pb_ws_g($x['good_kg'] ?? 0); $r['loss'] += pb_ws_g($x['loss_kg'] ?? 0);
        $cm = (int)round(((float)($x['cap_min'] ?? 0)) * 100);
        if ($cm > 0) { $r['ck'] += pb_ws_g($x['cap_kg'] ?? 0); $r['cm'] += $cm; }
        $gl = $x['gram_loss'] ?? null;
        if ($gl !== null && $gl !== '') { $r['gl'] += (int)round(((float)$gl) * 1000); $r['gn']++; }
        unset($r);
    }
    $rows = [];
    foreach ($order as $key) {
        $r = $g[$key];
        $rows[] = ['source' => $r['source'], 'name' => $r['name'], 'count' => $r['n'], 'input_kg' => $r['in'] / 1000, 'good_kg' => $r['good'] / 1000, 'loss_kg' => $r['loss'] / 1000,
            'yield_pct' => $r['in'] > 0 ? round($r['good'] / $r['in'] * 100, 3) : null, 'loss_pct' => $r['in'] > 0 ? round($r['loss'] / $r['in'] * 100, 3) : null,
            'capacity_kgh' => $r['cm'] > 0 ? round(($r['ck'] / 1000) * 60 / ($r['cm'] / 100), 3) : null,
            'avg_gram_loss' => $r['gn'] > 0 ? round($r['gl'] / 1000 / $r['gn'], 3) : null];
    }
    usort($rows, function ($a, $b) {
        if (($a['yield_pct'] === null) !== ($b['yield_pct'] === null)) return $a['yield_pct'] === null ? 1 : -1;
        if ($a['yield_pct'] !== null && $a['yield_pct'] != $b['yield_pct']) return $a['yield_pct'] < $b['yield_pct'] ? 1 : -1;
        $c = strcmp($a['name'], $b['name']);
        return $c !== 0 ? $c : strcmp($a['source'], $b['source']);
    });
    return $rows;
}
