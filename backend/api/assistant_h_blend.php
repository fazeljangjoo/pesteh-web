<?php
declare(strict_types=1);
/**
 * کوت‌ریزی معکوس (blend): کاربر چند جنس (لات) از انبار را انتخاب می‌کند و وزن نهایی + گرم و/یا انس هدف را می‌دهد؛
 * سیستم می‌گوید از هر جنس چند کیلو بردارد. فقط‌خواندنی: هیچ چیزی در دیتابیس نوشته یا کم نمی‌شود.
 *
 * مدل ریاضی (همان تعریف کوت‌ریزی مستقیم در assistant_h_quota.php): میانگین گرم/انس = میانگین وزنیِ لات‌ها.
 *   مجهول‌ها: وزن برداشتی هر لات wᵢ با 0 ≤ wᵢ ≤ موجودیᵢ
 *   قیدها:    Σwᵢ = W ؛ Σgᵢwᵢ = G·W (اگر گرم هدف داده شده) ؛ Σoᵢwᵢ = O·W (اگر انس هدف داده شده)
 * حل: شمارش همه‌ی رأس‌های چندوجهی جواب (حداکثر ۱۰ جنس ⇒ چند هزار حالت؛ بدون کتابخانه‌ی LP).
 *   - اگر جواب دقیق هست: چند گزینه‌ی مشخص (ارزان‌ترین، کمترین تعداد جنس، اول قدیمی‌ترین‌ها، متعادل) و اگر بیش از یک جواب
 *     متمایز باشد از کاربر پرسیده می‌شود کدام را می‌خواهد.
 *   - اگر جواب دقیق نیست: نزدیک‌ترین ترکیب (و در صورت دو هدف، گزینه‌ی «اولویت با گرم/انس») + بازه‌ی قابل‌دستیابی.
 * اجناسی که گرم یا انس ثبت‌شده ندارند: هشدار داده می‌شود و کاربر می‌تواند مقدار را دستی بدهد (فقط برای همین محاسبه) یا جنس را کنار بگذارد.
 *
 * پروتکل: فقط بلوک‌های موجود (text, notice, buttons, choice, form, table, card) و params فقط رشته‌ی تخت؛
 *   مرحله‌ها با params.do (چون assistant.php فقط step های start/answer/send/poll را می‌پذیرد) ⇒ نیازی به آپدیت کلاینت‌ها نیست.
 * بخش‌های blend_* خالص‌اند (بدون DB).
 */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }
require_once __DIR__ . '/assistant_h_common.php';
require_once __DIR__ . '/assistant_h_quota.php';

// ====================================================================== هستهٔ ریاضی (خالص)

/**
 * حذف قیدهای وابسته (حذف گاوس). rows: [[coef(n), rhs], ...]. خروجی: قیدهای مستقل هم‌ارز، یا null اگر ناسازگار.
 */
function blend_reduce(array $rows, int $n): ?array {
    $mat = [];
    foreach ($rows as $r) $mat[] = array_merge(array_values($r[0]), [(float)$r[1]]);
    $m = count($mat);
    $r = 0;
    for ($c = 0; $c < $n && $r < $m; $c++) {
        $best = $r;
        for ($i = $r + 1; $i < $m; $i++) if (abs($mat[$i][$c]) > abs($mat[$best][$c])) $best = $i;
        if (abs($mat[$best][$c]) < 1e-9) continue;
        [$mat[$r], $mat[$best]] = [$mat[$best], $mat[$r]];
        $pv = $mat[$r][$c];
        for ($j = $c; $j <= $n; $j++) $mat[$r][$j] /= $pv;
        for ($i = $r + 1; $i < $m; $i++) {
            $f = $mat[$i][$c];
            if ($f == 0.0) continue;
            for ($j = $c; $j <= $n; $j++) $mat[$i][$j] -= $f * $mat[$r][$j];
        }
        $r++;
    }
    for ($i = $r; $i < $m; $i++) {
        if (abs($mat[$i][$n]) > 1e-7) return null; // 0 = c ≠ 0
    }
    $out = [];
    for ($i = 0; $i < $r; $i++) $out[] = [array_slice($mat[$i], 0, $n), $mat[$i][$n]];
    return $out;
}

function blend_dot(array $a, array $p): float {
    $s = 0.0;
    foreach ($p as $i => $v) $s += $a[$i] * $v;
    return $s;
}

/**
 * سیمپلکس دوفازی (تابلوی چگال، قاعده‌ی بلند + فال‌بک Bland).
 * min c·x  با  A x = b  و  0 ≤ x ≤ u  (u[j] = INF یعنی بدون سقف).
 * سقف‌ها با متغیر کمکی (x_j + s = u_j) مدل می‌شوند؛ برای چند ده جنس، میلی‌ثانیه‌ای است.
 * خروجی: ['status' => optimal|infeasible|unbounded|iterlimit, 'x' => [...]]
 */
function blend_lp(array $A, array $b, array $c, array $u): array {
    $m = count($A);
    $n = count($c);
    $Bv = [];
    foreach ($u as $j => $uj) if (is_finite($uj)) $Bv[] = $j;
    $nb = count($Bv);
    $R = $m + $nb;
    $C = $n + $nb + $m;     // ستون‌ها: x | کمکیِ سقف | مصنوعی
    $lim = $n + $nb;        // فقط این ستون‌ها وارد پایه می‌شوند
    $T = array_fill(0, $R, array_fill(0, $C + 1, 0.0));
    $basis = [];
    for ($i = 0; $i < $m; $i++) {
        $sg = $b[$i] < 0 ? -1.0 : 1.0;
        for ($j = 0; $j < $n; $j++) $T[$i][$j] = $sg * $A[$i][$j];
        $T[$i][$n + $nb + $i] = 1.0;
        $T[$i][$C] = $sg * $b[$i];
        $basis[$i] = $n + $nb + $i;
    }
    foreach ($Bv as $k => $j) {
        $row = $m + $k;
        $T[$row][$j] = 1.0;
        $T[$row][$n + $k] = 1.0;
        $T[$row][$C] = $u[$j];
        $basis[$row] = $n + $k;
    }

    $pivot = function (int $r, int $col, ?array &$z) use (&$T, &$basis, $R, $C): void {
        $pv = $T[$r][$col];
        $nz = [];
        for ($j = 0; $j <= $C; $j++) {
            if ($T[$r][$j] != 0.0) { $T[$r][$j] /= $pv; $nz[] = $j; }
        }
        $T[$r][$col] = 1.0;
        for ($i = 0; $i < $R; $i++) {
            if ($i === $r) continue;
            $f = $T[$i][$col];
            if ($f == 0.0) continue;
            foreach ($nz as $j) $T[$i][$j] -= $f * $T[$r][$j];
            $T[$i][$col] = 0.0;
        }
        if ($z !== null) {
            $f = $z[$col];
            if ($f != 0.0) {
                foreach ($nz as $j) $z[$j] -= $f * $T[$r][$j];
                $z[$col] = 0.0;
            }
        }
        $basis[$r] = $col;
    };

    $run = function (array $cost) use (&$T, &$basis, $R, $C, $lim, $pivot): string {
        $z = $cost;
        for ($i = 0; $i < $R; $i++) {
            $cb = $cost[$basis[$i]];
            if ($cb == 0.0) continue;
            for ($j = 0; $j <= $C; $j++) $z[$j] -= $cb * $T[$i][$j];
        }
        $bland = 4 * ($R + $C) + 50;
        $maxIt = 60 * ($R + $C) + 2000;
        for ($it = 0; $it < $maxIt; $it++) {
            $col = -1;
            if ($it < $bland) {
                $best = -1e-9;
                for ($j = 0; $j < $lim; $j++) if ($z[$j] < $best) { $best = $z[$j]; $col = $j; }
            } else {
                for ($j = 0; $j < $lim; $j++) if ($z[$j] < -1e-9) { $col = $j; break; }
            }
            if ($col < 0) return 'optimal';
            $row = -1; $minr = INF;
            for ($i = 0; $i < $R; $i++) {
                $a = $T[$i][$col];
                if ($a > 1e-9) {
                    $r = $T[$i][$C] / $a;
                    if ($r < $minr - 1e-12 || (abs($r - $minr) <= 1e-12 && $row >= 0 && $basis[$i] < $basis[$row])) { $minr = $r; $row = $i; }
                }
            }
            if ($row < 0) return 'unbounded';
            $pivot($row, $col, $z);
        }
        return 'iterlimit';
    };

    // فاز ۱: مجموع متغیرهای مصنوعی
    $c1 = array_fill(0, $C, 0.0);
    for ($i = 0; $i < $m; $i++) $c1[$n + $nb + $i] = 1.0;
    $c1[$C] = 0.0;
    $st = $run($c1);
    if ($st !== 'optimal') return ['status' => $st, 'x' => []];
    $art = 0.0;
    for ($i = 0; $i < $R; $i++) if ($basis[$i] >= $lim) $art += $T[$i][$C];
    if ($art > 1e-7) return ['status' => 'infeasible', 'x' => []];
    // مصنوعیِ باقی‌مانده در پایه (با مقدار صفر) را بیرون بیاور
    for ($i = 0; $i < $R; $i++) {
        if ($basis[$i] < $lim) continue;
        for ($j = 0; $j < $lim; $j++) {
            if (abs($T[$i][$j]) > 1e-9) { $nz = null; $pivot($i, $j, $nz); break; }
        }
    }
    // فاز ۲
    $c2 = array_fill(0, $C + 1, 0.0);
    foreach ($c as $j => $v) $c2[$j] = (float)$v;
    $st = $run($c2);
    if ($st !== 'optimal') return ['status' => $st, 'x' => []];
    $x = array_fill(0, $n, 0.0);
    for ($i = 0; $i < $R; $i++) if ($basis[$i] < $n) $x[$basis[$i]] = max(0.0, min(is_finite($u[$basis[$i]]) ? $u[$basis[$i]] : INF, $T[$i][$C]));
    return ['status' => 'optimal', 'x' => $x];
}

/** بردار هزینه را به بیشینه‌ی قدر مطلق ۱ مقیاس می‌کند */
function blend_norm(array $v): array {
    $m = 0.0;
    foreach ($v as $x) $m = max($m, abs($x));
    if ($m <= 0) return array_map(fn($x) => 0.0, $v);
    return array_map(fn($x) => $x / $m, $v);
}

function blend_far(array $p, array $q, float $W): bool {
    foreach ($p as $i => $v) if (abs($v - $q[$i]) * $W >= 0.005) return true;
    return false;
}

/**
 * حل اصلی. kg=موجودی هر لات، price=فی هر کیلو، age=سن به روز، g/o=گرم/انس مؤثر هر لات،
 * W=وزن نهایی، G/O=هدف (null = مهم نیست).
 * خروجی: mode = insufficient | exact | closest ؛ cands = [{obj,label,p(کسر از وزن نهایی)}] ؛ range برای حالت closest.
 */
function blend_solve(array $kg, array $price, array $age, array $g, array $o, float $W, ?float $G, ?float $O): array {
    $n = count($kg);
    $u = [];
    $sumU = 0.0;
    foreach ($kg as $k) { $u[] = $k / $W; $sumU += $k / $W; }
    if ($sumU < 1 - 1e-9) return ['mode' => 'insufficient', 'cands' => []];

    $ones = array_fill(0, $n, 1.0);
    $rowW = [$ones, 1.0];
    $costN = blend_norm($price);
    $ageN = blend_norm($age);
    $eps = 1e-6;

    // LP روی قیدهای برابری (مستقل‌شده) با سقف دلخواه
    $lp = function (array $rows, array $cvec, ?array $uu = null) use ($n, $u): ?array {
        $red = blend_reduce($rows, $n);
        if ($red === null) return null;
        $A = array_map(fn($r) => $r[0], $red);
        $b = array_map(fn($r) => $r[1], $red);
        $r = blend_lp($A, $b, $cvec, $uu ?? $u);
        return $r['status'] === 'optimal' ? $r['x'] : null;
    };

    $all = [$rowW];
    if ($G !== null) $all[] = [$g, $G];
    if ($O !== null) $all[] = [$o, $O];

    $first = $lp($all, $costN);
    if ($first !== null) {
        // جواب دقیق هست؛ آیا یکتاست؟ (کمینه و بیشینه‌ی یک جهت عمومی)
        $dir = [];
        for ($i = 0; $i < $n; $i++) $dir[] = (float)((($i * 7919 + 13) % 101) + 1);
        $dir = blend_norm($dir);
        $pa = $lp($all, $dir);
        $pb = $lp($all, array_map(fn($x) => -$x, $dir));
        if ($pa === null || $pb === null || !blend_far($pa, $pb, $W)) {
            return ['mode' => 'exact', 'multi' => false, 'cands' => [['obj' => 'cost', 'label' => 'ترکیب پیشنهادی', 'p' => $first]]];
        }
        $pCost = $lp($all, array_map(fn($c) => $c, $costN)) ?? $first;
        $pOld = $lp($all, array_map(fn($a, $c) => -$a + $eps * $c, $ageN, $costN)) ?? $first;
        $pYoung = $lp($all, array_map(fn($a, $c) => $a + $eps * $c, $ageN, $costN)) ?? $first;
        $pMax = $lp($all, array_map(fn($c) => -$c, $costN)) ?? $first;
        // کمترین تعداد جنس: از جواب ارزان شروع و تا جای ممکن جنس حذف می‌شود (ابتکاری، حداکثر ۴۰ تلاش)
        $cur = $pCost; $uu = $u; $tries = 0;
        do {
            $changed = false;
            $used = array_keys(array_filter($cur, fn($v) => $v > 1e-9));
            usort($used, fn($a, $b) => $cur[$a] <=> $cur[$b]);
            if (count($used) <= 1) break;
            foreach ($used as $j) {
                if ($tries++ >= 40) break 2;
                $u2 = $uu; $u2[$j] = 0.0;
                $r = $lp($all, $costN, $u2);
                if ($r !== null) { $uu = $u2; $cur = $r; $changed = true; break; }
            }
        } while ($changed);
        $pts = [$pCost, $pMax, $pOld, $pYoung, $pa, $pb];
        $avg = array_fill(0, $n, 0.0);
        foreach ($pts as $p) foreach ($p as $i => $v) $avg[$i] += $v / count($pts);
        return ['mode' => 'exact', 'multi' => true, 'cands' => [
            ['obj' => 'cost', 'label' => 'ارزان‌ترین ترکیب', 'p' => $pCost],
            ['obj' => 'few',  'label' => 'کمترین تعداد جنس', 'p' => $cur],
            ['obj' => 'old',  'label' => 'اول از قدیمی‌ترین اجناس', 'p' => $pOld],
            ['obj' => 'mid',  'label' => 'ترکیب متعادل (حد وسط حالت‌های ممکن)', 'p' => $avg],
        ]];
    }

    // جواب دقیق نیست ⇒ نزدیک‌ترین (مینیمم مجموع انحراف نسبی) با متغیرهای انحراف
    $devRows = function (bool $withG, bool $withO) use ($n, $g, $o, $G, $O): array {
        // خروجی: [ردیف‌ها (طول n+k), تعداد متغیرهای اضافه، شاخص‌های انحراف]
        $rows = []; $k = 0; $extra = [];
        if ($withG && $G !== null) { $extra[] = ['g', $k, $k + 1]; $k += 2; }
        if ($withO && $O !== null) { $extra[] = ['o', $k, $k + 1]; $k += 2; }
        foreach ($extra as [$kind, $pi, $ni]) {
            $v = $kind === 'g' ? $g : $o; $T = $kind === 'g' ? $G : $O;
            $line = array_fill(0, $n + $k, 0.0);
            for ($i = 0; $i < $n; $i++) $line[$i] = $v[$i] / $T;
            $line[$n + $pi] = -1.0; $line[$n + $ni] = 1.0;
            $rows[] = [$line, 1.0];
        }
        return [$rows, $k];
    };
    $solveDev = function (array $exactRows, bool $withG, bool $withO) use ($n, $u, $costN, $eps, $devRows): ?array {
        $red = $exactRows ? blend_reduce($exactRows, $n) : [];
        if ($red === null) return null;
        [$dr, $k] = $devRows($withG, $withO);
        $rows = [];
        foreach ($red as $r) $rows[] = [array_merge($r[0], array_fill(0, $k, 0.0)), $r[1]];
        foreach ($dr as $r) $rows[] = $r;
        $A = array_map(fn($r) => $r[0], $rows);
        $b = array_map(fn($r) => $r[1], $rows);
        $c = array_merge(array_map(fn($x) => $eps * $x, $costN), array_fill(0, $k, 1.0));
        $uu = array_merge($u, array_fill(0, $k, INF));
        $r = blend_lp($A, $b, $c, $uu);
        return $r['status'] === 'optimal' ? array_slice($r['x'], 0, $n) : null;
    };

    $near = $solveDev([$rowW], $G !== null, $O !== null);
    if ($near === null) return ['mode' => 'insufficient', 'cands' => []];
    $cands = [['obj' => 'near', 'label' => 'نزدیک‌ترین ترکیب ممکن', 'p' => $near]];
    if ($G !== null && $O !== null) {
        $pg = $solveDev([$rowW, [$g, $G]], false, true);
        if ($pg !== null) $cands[] = ['obj' => 'prio_g', 'label' => 'گرم دقیقاً برسد؛ انس تا جای ممکن نزدیک باشد', 'p' => $pg];
        $po = $solveDev([$rowW, [$o, $O]], true, false);
        if ($po !== null) $cands[] = ['obj' => 'prio_o', 'label' => 'انس دقیقاً برسد؛ گرم تا جای ممکن نزدیک باشد', 'p' => $po];
    }
    $range = ['g' => [0.0, 0.0], 'o' => [0.0, 0.0]];
    foreach (['g' => $g, 'o' => $o] as $key => $v) {
        $lo = $lp([$rowW], $v);
        $hi = $lp([$rowW], array_map(fn($x) => -$x, $v));
        if ($lo !== null && $hi !== null) $range[$key] = [blend_dot($v, $lo), blend_dot($v, $hi)];
    }
    return ['mode' => 'closest', 'cands' => $cands, 'range' => $range];
}

/** کسرها → وزن (کیلو) با گرد به ۰٫۰۱ طوری که جمع دقیقاً W بماند و از [0, موجودی] بیرون نزند */
function blend_weights(array $p, array $kg, float $W): array {
    $w = [];
    foreach ($p as $i => $v) $w[$i] = min($kg[$i], max(0.0, round($v * $W, 2)));
    $diff = round($W - array_sum($w), 2);
    if (abs($diff) >= 0.005) {
        // باقی‌مانده را روی لاتی بریز که بیشترین جا را دارد
        $order = array_keys($w);
        usort($order, fn($a, $b) => ($diff > 0 ? ($kg[$b] - $w[$b]) <=> ($kg[$a] - $w[$a]) : $w[$b] <=> $w[$a]));
        foreach ($order as $i) {
            $room = $diff > 0 ? $kg[$i] - $w[$i] : $w[$i];
            $take = min(abs($diff), $room);
            $w[$i] = round($w[$i] + ($diff > 0 ? $take : -$take), 2);
            $diff = round($diff + ($diff > 0 ? -$take : $take), 2);
            if (abs($diff) < 0.005) break;
        }
    }
    return $w;
}

// ====================================================================== داده و پارامترها

function as_blend_cfg(): array {
    $c = (array)(as_config()['blend'] ?? []);
    return [
        'max_items' => max(2, min(300, (int)($c['max_items'] ?? 200))), // سقف ایمنی سرور (نه محدودیت عملی کاربر)
        'list_max'  => max(20, min(1000, (int)($c['list_max'] ?? 400))),
        // single = فرم تک‌ردیفی ساده (params.values)؛ repeat1 = همان فرم تکرارشونده‌ی کوت‌ریزی با دقیقاً یک ردیف (params.rows) — اگر روی کلاینتی فرم ساده درست رندر نشد
        'form_mode' => (($c['form_mode'] ?? 'single') === 'repeat1') ? 'repeat1' : 'single',
    ];
}

/** فرم هدف/دستی؛ در حالت repeat1 فقط یک ردیفِ ثابت (بدون دکمه‌ی افزودن/حذف مؤثر) */
function as_blend_form(string $title, array $fields, array $submit): array {
    $opts = [];
    if (as_blend_cfg()['form_mode'] === 'repeat1') {
        $opts = ['repeat' => ['min' => 1, 'max' => 1, 'add_label' => 'افزودن ردیف', 'remove_label' => 'حذف ردیف']];
    }
    return blk_form($title, $fields, $submit, $opts);
}

/** مقادیر فرم: یا params.values (فرم ساده) یا ردیف اول params.rows (repeat1)؛ نبودن = null */
function as_blend_form_values(array $params): ?array {
    if (isset($params['values']) && is_array($params['values'])) return $params['values'];
    if (isset($params['rows']) && is_array($params['rows']) && isset($params['rows'][0]) && is_array($params['rows'][0])) return $params['rows'][0];
    return null;
}

/** لات‌های دارای موجودی همین مشتری (مخلوط‌ها هم)؛ کلید = هش سروری */
function as_blend_lots(PDO $pdo, int $cid): array {
    $lim = as_blend_cfg()['list_max'];
    $st = $pdo->prepare('SELECT p.id, p.code, p.date, p.type_name, p.remaining, p.price, p.ounce, p.gram, p.is_mix, c.name AS category_name
        FROM purchases p JOIN categories c ON c.id = p.category_id AND c.customer_id = p.customer_id
        WHERE p.customer_id = ? AND p.remaining > 0.000001 ORDER BY p.date DESC, p.id DESC LIMIT ' . ($lim + 1));
    $st->execute([$cid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $truncated = count($rows) > $lim;
    if ($truncated) array_pop($rows);
    $today = strtotime(as_rd_date_offset(0));
    $map = [];
    foreach ($rows as $r) {
        $key = as_rd_key($cid, 'blot', (string)(int)$r['id']);
        $g = $r['gram'] === null ? null : (float)$r['gram'];
        $o = $r['ounce'] === null ? null : (float)$r['ounce'];
        if ($g !== null && !($g > 0 && $g <= 100)) $g = null;      // صفر/نامعتبر = ثبت‌نشده
        if ($o !== null && !($o > 0 && $o <= QUOTA_MAX_OUNCE)) $o = null;
        $ts = strtotime(substr((string)$r['date'], 0, 10));
        $map[$key] = [
            'key' => $key, 'id' => (int)$r['id'], 'code' => (string)($r['code'] ?? ''), 'type' => (string)$r['type_name'],
            'cat' => (string)$r['category_name'], 'kg' => (float)$r['remaining'], 'price' => (float)$r['price'],
            'g' => $g, 'o' => $o, 'mix' => (int)$r['is_mix'] === 1,
            'age' => $ts ? max(0.0, ($today - $ts) / 86400) : 0.0,
        ];
    }
    return ['lots' => $map, 'truncated' => $truncated];
}

function as_blend_name(array $l): string {
    $code = $l['code'] !== '' ? $l['code'] . ' · ' : '';
    return $code . ($l['mix'] ? '(مخلوط) ' : '') . $l['type'];
}

function blend_numstr(float $x): string {
    $s = rtrim(rtrim(number_format($x, 6, '.', ''), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

/** وضعیت از params تخت: sel (کلیدها با ویرگول)، w/g/o، man ("کلید~گرم~انس|…") */
function as_blend_state(array $params, array $lots): array {
    $max = as_blend_cfg()['max_items'];
    $raw = isset($params['sel']) && is_string($params['sel']) ? $params['sel'] : '';
    if (strlen($raw) > 17 * $max + 20) as_bad('درخواست نامعتبر است', 400);
    $sel = []; $dropped = 0;
    foreach (explode(',', $raw) as $k) {
        if (!preg_match('/^[0-9a-f]{16}$/', $k)) continue;
        if (!isset($lots[$k])) { $dropped++; continue; }
        if (!in_array($k, $sel, true)) $sel[] = $k;
    }
    $sel = array_slice($sel, 0, $max);
    $get =function (string $k) use ($params): ?float {
        if (!isset($params[$k]) || !is_string($params[$k]) || $params[$k] === '') return null;
        return quota_norm_number($params[$k]);
    };
    $man = [];
    $mraw = isset($params['man']) && is_string($params['man']) ? $params['man'] : '';
    if (strlen($mraw) <= 40 * $max + 20 && $mraw !== '') {
        foreach (explode('|', $mraw) as $e) {
            if (!preg_match('/^([0-9a-f]{16})~([0-9.]*)~([0-9.]*)$/', $e, $mm)) continue;
            if (!in_array($mm[1], $sel, true)) continue;
            $man[$mm[1]] = [$mm[2] === '' ? null : (float)$mm[2], $mm[3] === '' ? null : (float)$mm[3]];
        }
    }
    return ['sel' => $sel, 'w' => $get('w'), 'g' => $get('g'), 'o' => $get('o'), 'man' => $man, 'dropped' => $dropped];
}

/** params تخت برای اکشن‌های بعدی */
function as_blend_p(array $st, array $extra = []): array {
    $p = ['sel' => implode(',', $st['sel'])];
    foreach (['w', 'g', 'o'] as $k) if ($st[$k] !== null) $p[$k] = blend_numstr((float)$st[$k]);
    $parts = [];
    foreach ($st['man'] as $k => $v) {
        if (!in_array($k, $st['sel'], true)) continue;
        $parts[] = $k . '~' . ($v[0] === null ? '' : blend_numstr($v[0])) . '~' . ($v[1] === null ? '' : blend_numstr($v[1]));
    }
    if ($parts) $p['man'] = implode('|', $parts);
    return $p + array_map('strval', $extra);
}

function as_blend_act(array $params): array {
    return as_action('blend', 'answer', $params);
}

function as_blend_nav(array $st, bool $withEdit = true): array {
    $items = [];
    if ($withEdit) {
        $items[] = ['id' => 'targets', 'label' => 'تغییر هدف', 'icon' => '🎯', 'action' => as_blend_act(as_blend_p($st, ['do' => 'targets']))];
        $items[] = ['id' => 'pick', 'label' => 'تغییر اجناس', 'icon' => '🌰', 'action' => as_blend_act(as_blend_p($st, ['do' => 'pick']))];
    }
    $items[] = ['id' => 'blend', 'label' => 'محاسبه جدید', 'icon' => '⚖️', 'action' => as_action('blend', 'start')];
    $items[] = ['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')];
    return blk_buttons($items);
}

function as_blend_missing_text(array $l): string {
    $m = [];
    if ($l['g'] === null) $m[] = 'گرم';
    if ($l['o'] === null) $m[] = 'انس';
    return implode(' و ', $m) . ' ثبت نشده';
}

// ====================================================================== صفحه‌ها

/** گرم/انس مؤثر یک لات (ثبت‌شده یا دستی) برای نمایش */
function as_blend_eff(array $l, array $st): array {
    $m = $st['man'][$l['key']] ?? [null, null];
    return ['g' => $l['g'] ?? $m[0], 'o' => $l['o'] ?? $m[1], 'mg' => $l['g'] === null && $m[0] !== null, 'mo' => $l['o'] === null && $m[1] !== null];
}

/** انتخاب اجناس (هر بار یک جنس اضافه می‌شود؛ بدون محدودیت تعداد). فرم دستی همان‌جا زیر جنسِ ناقص نمایش داده می‌شود. */
function as_blend_screen_pick(array $lots, bool $truncated, array $st, ?string $warn = null): array {
    $cfg = as_blend_cfg();
    $blocks = [];
    if ($warn) $blocks[] = blk_notice($warn, 'warning');
    $n = count($st['sel']);
    if ($n === 0) {
        $blocks[] = blk_text('چند جنس از انبار را انتخاب کنید (دست‌کم ۲ جنس؛ هر تعداد که بخواهید). بعد وزن نهایی و گرم و/یا انس مورد نظر را می‌گیرم و می‌گویم از هر جنس چند کیلو بردارید.');
    } else {
        $rows = [];
        $tot = 0.0;
        $incomplete = [];
        foreach ($st['sel'] as $k) {
            $l = $lots[$k];
            $e = as_blend_eff($l, $st);
            $rows[] = [as_blend_name($l), as_num($l['kg'], 2),
                $e['g'] === null ? '—' : as_num($e['g'], 2) . ($e['mg'] ? ' ✎' : ''),
                $e['o'] === null ? '—' : as_num($e['o'], 2) . ($e['mo'] ? ' ✎' : '')];
            $tot += $l['kg'];
            if ($e['g'] === null || $e['o'] === null) $incomplete[] = [$l, $e];
        }
        $blocks[] = blk_table('اجناس انتخاب‌شده (' . as_num((float)$n) . ')', [
            ['key' => 'name', 'label' => 'جنس'], ['key' => 'kg', 'label' => 'موجودی (کیلو)', 'align' => 'end'],
            ['key' => 'g', 'label' => 'گرم', 'align' => 'end'], ['key' => 'o', 'label' => 'انس', 'align' => 'end'],
        ], $rows, ['جمع', as_num($tot, 2), '', '']);
        foreach ($incomplete as [$l, $e]) {
            $fields = []; $miss = [];
            if ($e['g'] === null) { $fields[] = ['name' => 'gram', 'label' => 'گرم این جنس', 'kind' => 'number', 'hint' => 'درصد مغزدهی؛ بین ۰ تا ۱۰۰']; $miss[] = 'گرم'; }
            if ($e['o'] === null) { $fields[] = ['name' => 'ounce', 'label' => 'انس این جنس', 'kind' => 'number']; $miss[] = 'انس'; }
            $blocks[] = blk_notice('⚠ «' . as_blend_name($l) . '»: ' . implode(' و ', $miss) . ' ثبت نشده است. اگر هدف شما به آن نیاز دارد همین‌جا دستی وارد کنید (فقط برای همین محاسبه؛ ذخیره نمی‌شود). می‌توانید خالی بگذارید و جنس را بدون آن نگه دارید یا حذفش کنید.', 'warning');
            $blocks[] = as_blend_form('ورود دستی — ' . as_blend_name($l), $fields,
                ['label' => 'ثبت مقدار', 'action' => as_blend_act(as_blend_p($st, ['do' => 'pickman', 'lot' => $l['key']]))]);
        }
    }
    if ($n >= 2) {
        $blocks[] = blk_buttons([['id' => 'next', 'label' => 'ادامه — تعیین وزن و گرم/انس', 'icon' => '✅',
            'action' => as_blend_act(as_blend_p($st, ['do' => 'targets']))]]);
    }
    if ($n > 0) {
        $rm = [];
        foreach ($st['sel'] as $k) {
            $left = array_values(array_diff($st['sel'], [$k]));
            $rm[] = ['id' => 'rm_' . $k, 'label' => 'حذف ' . as_blend_name($lots[$k]), 'icon' => '❌',
                'action' => as_blend_act(as_blend_p(['sel' => $left] + $st, ['do' => 'pick']))];
        }
        $blocks[] = blk_buttons($rm);
    }
    if ($n < $cfg['max_items']) {
        $items = [];
        foreach ($lots as $k => $l) {
            if (in_array($k, $st['sel'], true)) continue;
            $sub = [$l['cat'], as_num($l['kg'], 2) . ' کیلو'];
            $sub[] = $l['g'] === null ? '⚠ گرم ثبت نشده' : 'گرم ' . as_num($l['g'], 2);
            $sub[] = $l['o'] === null ? '⚠ انس ثبت نشده' : 'انس ' . as_num($l['o'], 2);
            $items[] = ['label' => as_blend_name($l), 'sublabel' => implode(' · ', $sub),
                'action' => as_blend_act(as_blend_p(['sel' => array_merge($st['sel'], [$k])] + $st, ['do' => 'pick']))];
        }
        if ($items) {
            $blocks[] = blk_choice($n === 0 ? 'جنس اول را انتخاب کنید' : 'افزودن جنس دیگر', $items, true);
            if ($truncated) $blocks[] = blk_notice('فهرست به ' . as_num((float)$cfg['list_max']) . ' جنس اخیر محدود شده است.', 'info');
        } elseif ($n === 0) {
            $blocks[] = blk_notice('در حال حاضر هیچ جنسی با موجودی در انبار ثبت نشده است.', 'info');
        }
    } else {
        $blocks[] = blk_notice('به سقف ایمنی سرور (' . as_num((float)$cfg['max_items']) . ' جنس) رسیده‌اید.', 'info');
    }
    $blocks[] = blk_buttons([
        ['id' => 'blend', 'label' => 'شروع دوباره', 'icon' => '🔁', 'action' => as_action('blend', 'start')],
        ['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')],
    ]);
    return as_response($blocks, ['data_note' => null]);
}

function as_blend_screen_targets(array $st): array {
    return as_response([
        blk_text('وزن نهایی مورد نظر را بدهید و حداقل یکی از «گرم» یا «انس» را. اگر فقط یکی مهم است، دیگری را خالی بگذارید.'),
        as_blend_form('هدف ترکیب', [
            ['name' => 'weight', 'label' => 'وزن نهایی (کیلو)', 'kind' => 'number'],
            ['name' => 'gram', 'label' => 'گرم هدف (اختیاری)', 'kind' => 'number', 'hint' => 'درصد مغزدهی؛ مثلاً ۴۵'],
            ['name' => 'ounce', 'label' => 'انس هدف (اختیاری)', 'kind' => 'number', 'hint' => 'اگر تعداد دانه در ۱۴۲٫۵ گرم را می‌دانید، بر ۵ تقسیم کنید'],
        ], ['label' => 'محاسبه', 'action' => as_blend_act(as_blend_p($st, ['do' => 'targets']))]),
        blk_buttons([
            ['id' => 'pick', 'label' => 'تغییر اجناس', 'icon' => '🌰', 'action' => as_blend_act(as_blend_p($st, ['do' => 'pick']))],
            ['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')],
        ]),
    ], ['data_note' => null]);
}

/** فرم ورود دستی گرم/انس برای جنسی که ثبت ندارد */
function as_blend_screen_manual(array $st, array $l, bool $needG, bool $needO): array {
    $fields = [];
    $miss = [];
    if ($needG) { $fields[] = ['name' => 'gram', 'label' => 'گرم این جنس', 'kind' => 'number', 'hint' => 'درصد مغزدهی؛ بین ۰ تا ۱۰۰']; $miss[] = 'گرم'; }
    if ($needO) { $fields[] = ['name' => 'ounce', 'label' => 'انس این جنس', 'kind' => 'number']; $miss[] = 'انس'; }
    $drop = array_values(array_diff($st['sel'], [$l['key']]));
    return as_response([
        blk_notice('⚠ برای «' . as_blend_name($l) . '» ' . implode(' و ', $miss) . ' ثبت نشده است. می‌توانید مقدار را دستی بدهید (فقط برای همین محاسبه استفاده می‌شود و جایی ذخیره نمی‌شود) یا این جنس را کنار بگذارید.', 'warning'),
        as_blend_form('مقدار دستی — ' . as_blend_name($l), $fields,
            ['label' => 'ادامه', 'action' => as_blend_act(as_blend_p($st, ['do' => 'manual', 'lot' => $l['key']]))]),
        blk_buttons([
            ['id' => 'drop', 'label' => 'کنار گذاشتن این جنس', 'icon' => '🚫', 'action' => as_blend_act(as_blend_p(['sel' => $drop] + $st, ['do' => 'run']))],
            ['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')],
        ]),
    ], ['data_note' => null]);
}

/** نمایش یک ترکیب (نتیجه) */
function as_blend_screen_result(array $st, array $lots, array $eff, array $solve, array $cand, bool $canSwitch): array {
    $W = (float)$st['w'];
    $ks = $st['sel'];
    $kg = []; $prices = [];
    foreach ($ks as $k) { $kg[] = $lots[$k]['kg']; $prices[] = $lots[$k]['price']; }
    $w = blend_weights($cand['p'], $kg, $W);
    $sumW = array_sum($w);
    $avg = fn(string $m) => $sumW > 0 ? array_sum(array_map(fn($i) => $eff[$ks[$i]][$m] * $w[$i], array_keys($ks))) / $sumW : 0.0;
    $aG = $avg('g'); $aO = $avg('o');
    $aP = $sumW > 0 ? array_sum(array_map(fn($i) => $prices[$i] * $w[$i], array_keys($ks))) / $sumW : 0.0;

    $blocks = [];
    if ($solve['mode'] === 'closest') {
        $msg = 'ترکیبی که دقیقاً به هدف شما برسد با اجناس انتخاب‌شده وجود ندارد؛ نزدیک‌ترین ترکیب ممکن در ادامه آمده است.';
        $r = $solve['range'];
        $bits = [];
        if ($st['g'] !== null) $bits[] = 'گرم بین ' . as_num($r['g'][0], 2) . ' تا ' . as_num($r['g'][1], 2);
        if ($st['o'] !== null) $bits[] = 'انس بین ' . as_num($r['o'][0], 2) . ' تا ' . as_num($r['o'][1], 2);
        if ($bits) $msg .= ' در این وزن، با همین اجناس ' . implode(' و ', $bits) . ' قابل دستیابی است. برای رسیدن به هدف، جنس دیگری اضافه کنید یا هدف را تغییر دهید.';
        $blocks[] = blk_notice($msg, 'warning');
    }
    $tone = function (?float $target, float $actual): ?string {
        if ($target === null) return null;
        return abs($actual - $target) <= 0.005 * max(1.0, abs($target)) ? 'pos' : 'warn';
    };
    $lines = [['label' => 'وزن نهایی (کیلو)', 'value' => as_num($sumW, 2), 'raw' => round($sumW, 3)]];
    $gl = ['label' => 'گرم حاصل', 'value' => as_num($aG, 2) . ($st['g'] !== null ? ' (هدف ' . as_num($st['g'], 2) . ')' : ''), 'raw' => $aG];
    if (($t = $tone($st['g'], $aG)) !== null) $gl['tone'] = $t;
    $ol = ['label' => 'انس حاصل', 'value' => as_num($aO, 2) . ($st['o'] !== null ? ' (هدف ' . as_num($st['o'], 2) . ')' : ''), 'raw' => $aO];
    if (($t = $tone($st['o'], $aO)) !== null) $ol['tone'] = $t;
    if ($st['g'] !== null) $lines[] = $gl; else $lines[] = ['label' => 'گرم حاصل (هدف نبوده)', 'value' => as_num($aG, 2), 'raw' => $aG];
    if ($st['o'] !== null) $lines[] = $ol; else $lines[] = ['label' => 'انس حاصل (هدف نبوده)', 'value' => as_num($aO, 2), 'raw' => $aO];
    $lines[] = ['label' => 'قیمت میانگین هر کیلو', 'value' => as_money($aP, 0), 'raw' => $aP];
    $blocks[] = blk_card($cand['label'], $lines);

    $rows = []; $unused = [];
    foreach ($ks as $i => $k) {
        $l = $lots[$k];
        if ($w[$i] < 0.005) { $unused[] = as_blend_name($l); continue; }
        $e = $eff[$k];
        $rows[] = [as_blend_name($l), as_num($w[$i], 2), as_num($l['kg'] - $w[$i], 2),
            $st['g'] === null && $l['g'] === null ? '—' : as_num($e['g'], 2) . ($e['mg'] ? ' ✎' : ''),
            $st['o'] === null && $l['o'] === null ? '—' : as_num($e['o'], 2) . ($e['mo'] ? ' ✎' : '')];
    }
    $blocks[] = blk_table('از هر جنس چقدر بردارید', [
        ['key' => 'name', 'label' => 'جنس'], ['key' => 'take', 'label' => 'برداشت (کیلو)', 'align' => 'end'],
        ['key' => 'left', 'label' => 'می‌ماند (کیلو)', 'align' => 'end'],
        ['key' => 'g', 'label' => 'گرم', 'align' => 'end'], ['key' => 'o', 'label' => 'انس', 'align' => 'end'],
    ], $rows, ['جمع', as_num($sumW, 2), '', '', '']);
    if ($unused) $blocks[] = blk_text('از این اجناس برداشتی لازم نیست: ' . implode('، ', $unused));
    if (array_filter($eff, fn($e) => $e['mg'] || $e['mo'])) {
        $blocks[] = blk_notice('✎ یعنی گرم/انس آن جنس را دستی داده‌اید (ذخیره نشده است).', 'info');
    }
    $blocks[] = blk_notice('این فقط محاسبه است: چیزی از انبار کم نشده. برای ثبت، مخلوط را در خود برنامه بسازید. ضایعات در محاسبه لحاظ نشده است.', 'info');

    $nav = [];
    if ($canSwitch) $nav[] = ['id' => 'other', 'label' => 'ترکیب دیگر', 'icon' => '🔀', 'action' => as_blend_act(as_blend_p($st, ['do' => 'run']))];
    $blocks[] = blk_buttons($nav);
    $blocks[] = as_blend_nav($st);
    return as_response(array_values(array_filter($blocks, fn($b) => !($b['type'] === 'buttons' && !$b['items']))), ['data_note' => null]);
}

/** انتخاب میان چند جواب */
function as_blend_screen_choose(array $st, array $lots, array $eff, array $solve, array $dist): array {
    $W = (float)$st['w'];
    $ks = $st['sel'];
    $kg = []; $prices = [];
    foreach ($ks as $k) { $kg[] = $lots[$k]['kg']; $prices[] = $lots[$k]['price']; }
    $items = [];
    foreach ($dist as $c) {
        $w = blend_weights($c['p'], $kg, $W);
        $parts = [];
        foreach ($ks as $i => $k) if ($w[$i] >= 0.005) $parts[] = as_blend_name($lots[$k]) . ': ' . as_num($w[$i], 2);
        $avgP = array_sum(array_map(fn($i) => $prices[$i] * $w[$i], array_keys($ks))) / max(1e-9, array_sum($w));
        $items[] = ['label' => $c['label'],
            'sublabel' => as_num((float)count($parts)) . ' جنس · فی میانگین ' . as_num($avgP, 0) . ' · ' . implode(' | ', $parts),
            'action' => as_blend_act(as_blend_p($st, ['do' => 'choose', 'obj' => $c['obj']]))];
    }
    $head = $solve['mode'] === 'exact'
        ? 'با اجناس انتخاب‌شده چند ترکیب دقیقاً به هدف شما می‌رسد (هر ترکیبی بین این‌ها هم جواب است). کدام را می‌خواهید؟'
        : 'ترکیبی که هم گرم و هم انس را دقیقاً بدهد وجود ندارد. کدام را ترجیح می‌دهید؟';
    return as_response([
        blk_text($head),
        blk_choice('یک ترکیب را انتخاب کنید', $items, false),
        as_blend_nav($st),
    ], ['data_note' => null]);
}

// ====================================================================== خط لولهٔ محاسبه

function as_blend_run(array $st, array $lots, ?string $obj): array {
    // گرم/انس مؤثر هر لات (ثبت‌شده، وگرنه دستی)؛ نبودنِ لازم ⇒ فرم دستی
    $eff = [];
    foreach ($st['sel'] as $k) {
        $l = $lots[$k];
        $m = $st['man'][$k] ?? [null, null];
        $g = $l['g'] ?? $m[0];
        $o = $l['o'] ?? $m[1];
        $needG = $st['g'] !== null && $g === null;
        $needO = $st['o'] !== null && $o === null;
        if ($needG || $needO) return as_blend_screen_manual($st, $l, $needG, $needO);
        $eff[$k] = ['g' => $g ?? 0.0, 'o' => $o ?? 0.0, 'mg' => $l['g'] === null && $g !== null, 'mo' => $l['o'] === null && $o !== null];
    }
    $W = (float)$st['w'];
    $kg = $pr = $age = $g = $o = [];
    foreach ($st['sel'] as $k) {
        $kg[] = $lots[$k]['kg']; $pr[] = $lots[$k]['price']; $age[] = $lots[$k]['age'];
        $g[] = $eff[$k]['g']; $o[] = $eff[$k]['o'];
    }
    $solve = blend_solve($kg, $pr, $age, $g, $o, $W, $st['g'], $st['o']);
    if ($solve['mode'] === 'insufficient') {
        return as_response([
            blk_notice('موجودی اجناس انتخاب‌شده (' . as_num(array_sum($kg), 2) . ' کیلو) از وزن خواسته‌شده (' . as_num($W, 2) . ' کیلو) کمتر است.', 'error'),
            as_blend_nav($st),
        ], ['data_note' => null]);
    }
    // متمایزها (اختلاف کمتر از ۰٫۰۰۵ کیلو برای همه‌ی اجناس = یکی)
    $dist = [];
    foreach ($solve['cands'] as $c) {
        $dup = false;
        foreach ($dist as $d) {
            $same = true;
            foreach ($c['p'] as $i => $v) if (abs($v - $d['p'][$i]) * $W >= 0.005) { $same = false; break; }
            if ($same) { $dup = true; break; }
        }
        if (!$dup) $dist[] = $c;
    }
    if ($obj !== null) {
        foreach ($dist as $c) if ($c['obj'] === $obj) return as_blend_screen_result($st, $lots, $eff, $solve, $c, count($dist) > 1);
        foreach ($solve['cands'] as $c) if ($c['obj'] === $obj) return as_blend_screen_result($st, $lots, $eff, $solve, $c, count($dist) > 1);
    }
    if (count($dist) === 1) return as_blend_screen_result($st, $lots, $eff, $solve, $dist[0], false);
    return as_blend_screen_choose($st, $lots, $eff, $solve, $dist);
}

// ====================================================================== هندلر

function as_h_blend(array $ctx): array {
    $pdo = $ctx['pdo'];
    $cid = (int)$ctx['cid'];
    $step = (string)($ctx['step'] ?? 'start');
    $params = (array)($ctx['params'] ?? []);
    $data = as_blend_lots($pdo, $cid);
    $lots = $data['lots'];
    $cfg = as_blend_cfg();

    if ($step === 'start') {
        return as_blend_screen_pick($lots, $data['truncated'], ['sel' => [], 'w' => null, 'g' => null, 'o' => null, 'man' => []]);
    }
    if ($step !== 'answer') as_bad('درخواست نامعتبر است', 400);

    $do = $params['do'] ?? null;
    if (!is_string($do) || !in_array($do, ['pick', 'pickman', 'targets', 'manual', 'run', 'choose'], true)) as_bad('درخواست نامعتبر است', 400);
    $st = as_blend_state($params, $lots);
    $warnDropped = $st['dropped'] > 0 ? 'برخی اجناس انتخاب‌شده دیگر موجودی ندارند و از فهرست حذف شدند.' : null;

    if ($do === 'pick') {
        return as_blend_screen_pick($lots, $data['truncated'], $st, $warnDropped);
    }
    if ($do === 'pickman') { // مقدار دستی گرم/انس همان لحظه‌ی انتخاب (اختیاری؛ حداقل یکی)
        $lot = $params['lot'] ?? null;
        if (!is_string($lot) || !in_array($lot, $st['sel'], true)) as_bad('درخواست نامعتبر است', 400);
        $vals = as_blend_form_values($params) ?? [];
        $l = $lots[$lot];
        $cur = $st['man'][$lot] ?? [null, null];
        $any = false;
        if ($l['g'] === null && !quota_is_empty($vals['gram'] ?? null)) {
            $v = quota_norm_number($vals['gram']);
            if ($v === null || $v <= 0 || $v > 100) as_bad('«گرم» باید عددی بیشتر از صفر و حداکثر ۱۰۰ باشد.', 400);
            $cur[0] = $v; $any = true;
        }
        if ($l['o'] === null && !quota_is_empty($vals['ounce'] ?? null)) {
            $v = quota_norm_number($vals['ounce']);
            if ($v === null || $v <= 0 || $v > QUOTA_MAX_OUNCE) as_bad('«انس» باید عددی بیشتر از صفر و حداکثر ۱٬۰۰۰ باشد.', 400);
            $cur[1] = $v; $any = true;
        }
        if (!$any) as_bad('دست‌کم یکی از مقدارها را وارد کنید.', 400);
        $st['man'][$lot] = $cur;
        return as_blend_screen_pick($lots, $data['truncated'], $st, $warnDropped);
    }
    // بقیه‌ی مرحله‌ها دست‌کم ۲ جنس لازم دارند
    if (count($st['sel']) < 2) {
        return as_blend_screen_pick($lots, $data['truncated'], $st, $warnDropped ?? 'دست‌کم دو جنس انتخاب کنید.');
    }

    if ($do === 'targets') {
        $vals = as_blend_form_values($params);
        if ($vals === null) return as_blend_screen_targets($st);
        $w = quota_norm_number($vals['weight'] ?? null);
        if (quota_is_empty($vals['weight'] ?? null) || $w === null) as_bad('وزن نهایی را (به کیلو) وارد کنید.', 400);
        if ($w <= 0 || $w > QUOTA_MAX_WEIGHT) as_bad('«وزن نهایی» باید بیشتر از صفر باشد.', 400);
        $g = null; $o = null;
        if (!quota_is_empty($vals['gram'] ?? null)) {
            $g = quota_norm_number($vals['gram']);
            if ($g === null) as_bad('«گرم» باید یک عدد باشد.', 400);
            if ($g <= 0 || $g > 100) as_bad('«گرم» باید بیشتر از صفر و حداکثر ۱۰۰ باشد.', 400);
        }
        if (!quota_is_empty($vals['ounce'] ?? null)) {
            $o = quota_norm_number($vals['ounce']);
            if ($o === null) as_bad('«انس» باید یک عدد باشد.', 400);
            if ($o <= 0 || $o > QUOTA_MAX_OUNCE) as_bad('«انس» باید بیشتر از صفر و حداکثر ۱٬۰۰۰ باشد.', 400);
        }
        if ($g === null && $o === null) as_bad('حداقل یکی از «گرم» یا «انس» را وارد کنید.', 400);
        $tot = 0.0;
        foreach ($st['sel'] as $k) $tot += $lots[$k]['kg'];
        if ($w - $tot > 1e-6) as_bad('موجودی اجناس انتخاب‌شده ' . as_num($tot, 2) . ' کیلو است و برای ' . as_num($w, 2) . ' کیلو کافی نیست.', 400);
        $st['w'] = $w; $st['g'] = $g; $st['o'] = $o;
        return as_blend_run($st, $lots, null);
    }

    // run / choose / manual به هدف معتبر نیاز دارند
    if ($st['w'] === null || $st['w'] <= 0 || ($st['g'] === null && $st['o'] === null)) {
        return as_blend_screen_targets($st);
    }

    if ($do === 'manual') {
        $lot = $params['lot'] ?? null;
        if (!is_string($lot) || !in_array($lot, $st['sel'], true)) as_bad('درخواست نامعتبر است', 400);
        $vals = as_blend_form_values($params) ?? [];
        $l = $lots[$lot];
        $cur = $st['man'][$lot] ?? [null, null];
        if ($st['g'] !== null && $l['g'] === null) {
            if (quota_is_empty($vals['gram'] ?? null)) as_bad('«گرم» این جنس را وارد کنید (یا آن را کنار بگذارید).', 400);
            $v = quota_norm_number($vals['gram']);
            if ($v === null || $v <= 0 || $v > 100) as_bad('«گرم» باید عددی بیشتر از صفر و حداکثر ۱۰۰ باشد.', 400);
            $cur[0] = $v;
        }
        if ($st['o'] !== null && $l['o'] === null) {
            if (quota_is_empty($vals['ounce'] ?? null)) as_bad('«انس» این جنس را وارد کنید (یا آن را کنار بگذارید).', 400);
            $v = quota_norm_number($vals['ounce']);
            if ($v === null || $v <= 0 || $v > QUOTA_MAX_OUNCE) as_bad('«انس» باید عددی بیشتر از صفر و حداکثر ۱٬۰۰۰ باشد.', 400);
            $cur[1] = $v;
        }
        $st['man'][$lot] = $cur;
        return as_blend_run($st, $lots, null);
    }

    $obj = null;
    if ($do === 'choose') {
        $obj = $params['obj'] ?? null;
        if (!is_string($obj) || !in_array($obj, ['cost', 'few', 'old', 'mid', 'near', 'prio_g', 'prio_o'], true)) as_bad('درخواست نامعتبر است', 400);
    }
    return as_blend_run($st, $lots, $obj);
}

as_register('blend', 'as_h_blend');
