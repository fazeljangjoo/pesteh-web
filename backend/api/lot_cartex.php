<?php
declare(strict_types=1);
/**
 * مرحلهٔ ۱۰ — «کارتکس لات» (زیرتب انبار). غیر از «کارتکس مالک» (workshop_cartex.php / reports.cartex).
 * فقط‌خواندنی، بدون هیچ قیمت/مبلغ. مجوز مشاهده: cartex.view (نقش «کارتکس»؛ مهاجرت v75 آن را به هر نقش دارای stock.view هم می‌دهد)؛ ذخیرهٔ تنظیم بازه: stock.update یا مالک.
 *
 * ساختار: تابع خالص pb_lot_cartex_build (بدون PDO؛ تست: lot_cartex_selftest.php و WINDOWS/test/lot_cartex_cases.json)
 *         + pb_lot_cartex_report (کوئری) + pb_lot_cartex_settings_get/save.
 * وزن‌ها داخل محاسبه روی گرم صحیح؛ خروجی JSON به کیلو (۳ رقم).
 *
 * تصمیم‌ها (بازنگری ساختار تب‌ها):
 *  • لات‌های is_mix=0،1،2 نمایش داده می‌شوند؛ is_mix=3 (امانی) نه (مالک ما نیست).
 *  • «مانده» = ستون purchases.remaining (مرجع فیزیکی موجودی)؛ خروج‌ها از mix_sources / proc_inputs+proc_orders / crack_run_lots / sale_items.
 *  • تب «دید کلی» (overview): همهٔ لات‌های بازه (عادی، لات کوت، لات‌های ساخته‌شده از فراوری، فروش‌رفته) با ستون «وضعیت».
 *  • تب «موجود در انبار» (normal): هر لاتی که مانده>0 دارد، به‌جز «خود لات کوت» (is_mix=1) و «لات‌های ساخته‌شده از فراوری/شکست».
 *    پستهٔ مبدأی که بخشی از آن کوت/فراوری شده و بخشی هنوز در انبار است همان مانده‌اش در اینجا می‌ماند. نوع و تاریخ خروج ندارد. با انس/گرم.
 *  • تب «کوت‌ریزی‌شده» (kot): به‌ازای هر کوت یک گروه (یک رنگ) با کد، انس، گرم و وزن کوت؛ لات‌های وارد‌شده زیرش با انس/گرم/وزن سهم.
 *  • تب «فراوری‌شده» (processed): به‌ازای هر سفارش فراوری/اجرای شکست یک گروه (یک رنگ): ورودی‌ها (پسته‌های مبدأ) + لات‌های ساخته‌شده (outputs).
 *  • لاتی که مانده‌اش صفر شده و وارد کوت یا فراوری/شکست شده فقط در گروه خودش می‌آید؛ اگر مانده دارد، مانده‌اش در «موجود در انبار» هم می‌ماند.
 *  • تب «پسته‌های خارج‌شده» (exited): لاتی که مانده=0 و فروش>0؛ تاریخ خروج = آخرین فروش، نوع «sold». فقط این تب تاریخ/نوع خروج دارد.
 *  • خروج از طریق جداسازی/امانی/برگشت خرید در این گزارش نیست (خارج از مشخصات).
 */

require_once __DIR__ . '/jdate.php';
require_once __DIR__ . '/mix_group.php';

const PB_LC_PRESET_TYPES = ['اکبری', 'احمدآقایی', 'فندقی', 'کله‌قوچی'];
const PB_LC_EPS_G = 1; // کمتر از ۱ گرم = صفر

/** کلید مرتب‌سازی فارسی: نرمال‌سازی ي/ی ك/ک ة/ه، حذف نیم‌فاصله/اعراب/کشیده؛ آ=ا؛ ترتیب الفبای فارسی. */
function pb_lc_sort_key(string $s): string {
    $s = strtr($s, ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'ئ' => 'ی', 'ؤ' => 'و', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ی']);
    $s = preg_replace('/[\x{200C}\x{200D}\x{200F}\x{200E}\x{064B}-\x{0652}\x{0670}\x{0640}\s]+/u', '', $s) ?? $s;
    $s = mb_strtolower($s, 'UTF-8');
    static $order = null;
    if ($order === null) {
        $order = [];
        $i = 0;
        foreach (preg_split('//u', 'ابپتثجچحخدذرزژسشصضطظعغفقکگلمنوهی', -1, PREG_SPLIT_NO_EMPTY) as $ch) $order[$ch] = ++$i;
    }
    $out = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (isset($order[$ch])) { $out .= chr(0xA0 + $order[$ch]); continue; }
        $cp = mb_ord($ch, 'UTF-8');
        // ارقام فارسی/عربی ⇒ لاتین؛ کاراکترهای غیرفارسی قبل از حروف فارسی (۰x20..۰x9F)
        if ($cp >= 0x06F0 && $cp <= 0x06F9) $cp = 0x30 + ($cp - 0x06F0);
        elseif ($cp >= 0x0660 && $cp <= 0x0669) $cp = 0x30 + ($cp - 0x0660);
        $out .= $cp < 0x80 ? chr(max(0x20, $cp)) : '~';
    }
    return $out;
}

/** مقایسهٔ نام فارسی؛ نام خالی آخر. */
function pb_lc_cmp_name(string $a, string $b): int {
    $a = trim($a); $b = trim($b);
    if ($a === '' && $b === '') return 0;
    if ($a === '') return 1;
    if ($b === '') return -1;
    return strcmp(pb_lc_sort_key($a), pb_lc_sort_key($b));
}

function pb_lc_g($kg): int { return (int)round(((float)$kg) * 1000); }
function pb_lc_kg(int $g): float { return round($g / 1000, 3); }

/**
 * @param array $lots       [{id,code,type_name,seller,net_weight,remaining,date,is_mix,ounce?,gram?}] (همهٔ لات‌ها؛ فیلتر بازه داخل تابع)
 * @param array $mixSources [{mix_purchase_id,source_purchase_id,weight}]
 * @param array $procInputs [{lot_id,kg,order_id,order_date,op_type}] (op_type='crack' ⇒ شکسته شده؛ order_id=شناسهٔ اجرا + kind='crack_run' برای خط شکست)
 * @param array $saleItems  [{purchase_id,weight,date}]
 * @param array $opts       {from,to,label?}
 * @param array $procOutputs [{order_id,lot_id,source_lot_id?,kind?}] لات‌های ساخته‌شده از فراوری/شکست (kind='crack_run' برای خط شکست)
 *
 * خروجی هر نوع پسته (types[]): overview (دید کلی) · normal (موجود در انبار) · kot (گروه‌های کوت) · processed (گروه‌های فراوری/شکست) · exited (خارج‌شده: فروش).
 * normal: مانده>0 و نه خود لات کوت و نه لات ساخته‌شده از فراوری؛ exited: مانده=0، فروش>0 و نه کوت/فراوری شده.
 */
function pb_lot_cartex_build(array $lots, array $mixSources, array $procInputs, array $saleItems, array $opts, array $procOutputs = []): array {
    $from = (string)($opts['from'] ?? '0000-00-00');
    $to = (string)($opts['to'] ?? '9999-12-31');
    $label = (string)($opts['label'] ?? '');
    $num = fn($v) => ($v === null || $v === '') ? null : round((float)$v, 3);

    $byId = [];
    foreach ($lots as $l) $byId[(int)$l['id']] = $l;
    $keys = pb_mix_group_keys(array_map(fn($l) => ['id' => $l['id'], 'is_mix' => $l['is_mix'] ?? 0], $lots), $mixSources);

    // کوت‌ها: mixId ⇒ [{src, g}]
    $kots = [];
    $kotOfLot = []; // lotId ⇒ [mixId ⇒ g]
    foreach ($mixSources as $m) {
        $mid = (int)($m['mix_purchase_id'] ?? 0); $sid = (int)($m['source_purchase_id'] ?? 0);
        $g = pb_lc_g($m['weight'] ?? 0);
        if ($mid <= 0 || $sid <= 0 || $g <= 0 || !isset($byId[$mid])) continue;
        $kots[$mid][$sid] = ($kots[$mid][$sid] ?? 0) + $g;
        $kotOfLot[$sid][$mid] = ($kotOfLot[$sid][$mid] ?? 0) + $g;
    }
    // فراوری/شکست: lotId ⇒ [groupKey ⇒ {g,date,crack,order_id,run}]؛ groupKey = o<orderId> | r<runId>
    $procOf = [];
    $procGroups = []; // groupKey ⇒ {date,crack,order_id,run}
    foreach ($procInputs as $p) {
        $lid = (int)($p['lot_id'] ?? 0); $g = pb_lc_g($p['kg'] ?? 0);
        if ($lid <= 0 || $g <= 0) continue;
        $crack = (($p['op_type'] ?? '') === 'crack') || (($p['kind'] ?? '') === 'crack_run');
        $oid = (int)($p['order_id'] ?? 0);
        $run = ($p['kind'] ?? '') === 'crack_run';
        $rk = ($crack && $run ? 'r' : 'o') . $oid;
        if (!isset($procOf[$lid][$rk])) $procOf[$lid][$rk] = ['g' => 0, 'date' => (string)($p['order_date'] ?? ''), 'crack' => $crack, 'order_id' => $oid, 'run' => $run];
        $procOf[$lid][$rk]['g'] += $g;
        if (!isset($procGroups[$rk])) $procGroups[$rk] = ['date' => (string)($p['order_date'] ?? ''), 'crack' => $crack, 'order_id' => $oid, 'run' => $run];
    }
    // لات‌های ساخته‌شده: groupKey ⇒ [lotId,...] (بدون تکرار)
    $outOf = [];
    foreach ($procOutputs as $o) {
        $lid = (int)($o['lot_id'] ?? 0); $oid = (int)($o['order_id'] ?? 0);
        if ($lid <= 0 || $oid <= 0) continue;
        $rk = (($o['kind'] ?? '') === 'crack_run' ? 'r' : 'o') . $oid;
        $outOf[$rk][$lid] = $lid;
    }
    $outLots = []; // لات‌هایی که خروجی فراوری/شکست‌اند
    foreach ($outOf as $ids) foreach ($ids as $olid) $outLots[$olid] = true;
    $soldOf = []; // lotId ⇒ {g, last}
    foreach ($saleItems as $s) {
        $lid = (int)($s['purchase_id'] ?? 0); $g = pb_lc_g($s['weight'] ?? 0);
        if ($lid <= 0 || $g <= 0) continue;
        $d = (string)($s['date'] ?? '');
        if (!isset($soldOf[$lid])) $soldOf[$lid] = ['g' => 0, 'last' => ''];
        $soldOf[$lid]['g'] += $g;
        if ($d > $soldOf[$lid]['last']) $soldOf[$lid]['last'] = $d;
    }

    $types = []; // typeName ⇒ section
    $sec = function (string $t) use (&$types) {
        if (!isset($types[$t])) $types[$t] = ['type_name' => $t, 'total_g' => 0, 'overview' => [], 'normal' => [], 'exited' => [], 'kot' => [], 'processed' => []];
        return $t;
    };

    foreach ($lots as $l) {
        $id = (int)$l['id'];
        $kind = (int)($l['is_mix'] ?? 0);
        if ($kind === 3) continue;
        $date = (string)($l['date'] ?? '');
        if ($date < $from || $date > $to) continue;
        $type = trim((string)($l['type_name'] ?? ''));
        $t = $sec($type);
        $netG = pb_lc_g($l['net_weight'] ?? 0);
        $remG = max(0, pb_lc_g($l['remaining'] ?? 0));
        if ($remG < PB_LC_EPS_G) $remG = 0;
        $types[$t]['total_g'] += $netG;
        $party = trim((string)($l['seller'] ?? ''));
        $code = (string)($l['code'] ?? '');
        $base = ['lot_id' => $id, 'code' => $code, 'party' => $party, 'is_mix' => $kind, 'entry_date' => $date, 'net_kg' => pb_lc_kg($netG),
                 'ounce' => $num($l['ounce'] ?? null), 'gram' => $num($l['gram'] ?? null)];

        $hasKot = !empty($kotOfLot[$id]);
        $hasProc = !empty($procOf[$id]);
        $sold = $soldOf[$id]['g'] ?? 0;

        $isOutLot = isset($outLots[$id]);
        $derived = $kind === 1 || $isOutLot; // خود لات کوت یا لات ساخته‌شده از فراوری/شکست
        // «دید کلی»: همهٔ لات‌ها با وضعیت
        $parts = [];
        if ($kind === 1) $parts[] = 'لات کوت';
        if ($isOutLot) $parts[] = 'ساخته‌شده از فراوری/شکست';
        if ($hasKot) $parts[] = 'وارد کوت شده';
        if ($hasProc) $parts[] = 'وارد فراوری/شکست شده';
        $parts[] = $remG > 0 ? 'موجود در انبار' : ($sold > 0 ? 'فروش رفته' : 'مانده ندارد');
        $types[$t]['overview'][] = $base + ['weight_kg' => pb_lc_kg($netG), 'remaining_kg' => pb_lc_kg($remG), 'sold_kg' => pb_lc_kg($sold),
            'exit_date' => null, 'exit_type' => 'overview', 'exit_label' => implode(' · ', $parts), 'partial' => $remG < $netG - PB_LC_EPS_G];
        // «موجود در انبار»: هر لات دارای مانده، به‌جز لات کوت و لات‌های ساخته‌شده از فراوری (مانده‌ی پستهٔ مبدأِ نیمه‌کوت/نیمه‌فراوری همین‌جا می‌ماند)
        if ($remG > 0 && !$derived) {
            $types[$t]['normal'][] = $base + ['weight_kg' => pb_lc_kg($netG), 'remaining_kg' => pb_lc_kg($remG), 'sold_kg' => pb_lc_kg($sold),
                'exit_date' => null, 'exit_type' => 'in_stock', 'exit_label' => '', 'partial' => $remG < $netG - PB_LC_EPS_G];
        }
        // «خارج‌شده»: کاملاً فروش رفته و نه کوت شده نه فراوری/شکست
        if ($remG === 0 && $sold > 0 && !$hasKot && !$hasProc) {
            $types[$t]['exited'][] = $base + ['weight_kg' => pb_lc_kg($sold), 'remaining_kg' => 0.0, 'sold_kg' => pb_lc_kg($sold),
                'exit_date' => $soldOf[$id]['last'], 'exit_type' => 'sold', 'exit_label' => 'فروش رفته', 'partial' => false];
        }
        // «کوت‌ریزی‌شده» (ردیف‌ها زیر کوت جمع می‌شوند)
        foreach ($kotOfLot[$id] ?? [] as $mid => $g) {
            $types[$t]['_kotrows'][$mid][] = $base + ['weight_kg' => pb_lc_kg($g), 'remaining_kg' => pb_lc_kg($remG), 'kot_id' => $mid,
                'exit_date' => (string)($byId[$mid]['date'] ?? ''), 'exit_type' => 'kot', 'exit_label' => ''];
        }
        // «فراوری‌شده» (ردیف‌ها زیر سفارش/اجرا جمع می‌شوند)
        foreach ($procOf[$id] ?? [] as $rk => $pr) {
            $types[$t]['_procrows'][$rk][] = $base + ['weight_kg' => pb_lc_kg($pr['g']), 'remaining_kg' => pb_lc_kg($remG), 'order_id' => $pr['order_id'],
                'exit_date' => $pr['date'], 'exit_type' => $pr['crack'] ? 'crack' : 'proc', 'exit_label' => ''];
        }
    }

    $cmp = function ($a, $b) {
        $c = pb_lc_cmp_name((string)$a['party'], (string)$b['party']);
        if ($c !== 0) return $c;
        $c = strcmp((string)$a['entry_date'], (string)$b['entry_date']);
        return $c !== 0 ? $c : ((int)$a['lot_id'] <=> (int)$b['lot_id']);
    };

    // جزئیات کامل هر کوت (همهٔ لات‌های درون آن، فارغ از بازه)
    $kotDetail = function (int $mid) use ($kots, $byId, $cmp, $num) {
        $items = [];
        $tot = 0;
        foreach ($kots[$mid] ?? [] as $sid => $g) {
            $s = $byId[$sid] ?? null;
            if (!$s) continue;
            $tot += $g;
            $items[] = ['lot_id' => $sid, 'code' => (string)($s['code'] ?? ''), 'party' => trim((string)($s['seller'] ?? '')),
                        'type_name' => trim((string)($s['type_name'] ?? '')), 'weight_kg' => pb_lc_kg($g), 'entry_date' => (string)($s['date'] ?? ''),
                        'is_mix' => (int)($s['is_mix'] ?? 0), 'ounce' => $num($s['ounce'] ?? null), 'gram' => $num($s['gram'] ?? null)];
        }
        usort($items, $cmp);
        return [$items, $tot];
    };

    $outTypes = [];
    foreach ($types as $name => $s) {
        usort($s['normal'], $cmp);
        usort($s['overview'], $cmp);
        usort($s['exited'], function ($a, $b) use ($cmp) { // جدیدترین خروج اول
            $c = strcmp((string)$b['exit_date'], (string)$a['exit_date']);
            return $c !== 0 ? $c : $cmp($a, $b);
        });
        $kotList = [];
        foreach ($s['_kotrows'] ?? [] as $mid => $rows) {
            usort($rows, $cmp);
            [$all, $totG] = $kotDetail((int)$mid);
            $m = $byId[$mid];
            $kotList[] = ['kot_id' => (int)$mid, 'code' => (string)($m['code'] ?? ''), 'date' => (string)($m['date'] ?? ''),
                          'total_kg' => pb_lc_kg($totG), 'weight_kg' => pb_lc_kg(pb_lc_g($m['net_weight'] ?? 0)),
                          'ounce' => $num($m['ounce'] ?? null), 'gram' => $num($m['gram'] ?? null),
                          'color_key' => $keys[(int)$mid] ?? (int)$mid, 'color_idx' => ((int)$mid) % 12,
                          'items' => $rows, 'all_items' => $all];
        }
        usort($kotList, fn($a, $b) => strcmp($b['date'], $a['date']) ?: ($b['kot_id'] <=> $a['kot_id'])); // جدیدترین کوت اول

        $procList = [];
        foreach ($s['_procrows'] ?? [] as $rk => $rows) {
            usort($rows, $cmp);
            $pg = $procGroups[$rk] ?? ['date' => '', 'crack' => false, 'order_id' => 0, 'run' => false];
            $inG = 0;
            foreach ($rows as $r) $inG += pb_lc_g($r['weight_kg']);
            $outs = [];
            $outG = 0;
            foreach ($outOf[$rk] ?? [] as $olid) {
                $o = $byId[$olid] ?? null;
                $og = pb_lc_g($o['net_weight'] ?? 0);
                $outG += $og;
                $outs[] = ['lot_id' => (int)$olid, 'code' => (string)($o['code'] ?? ''), 'party' => trim((string)($o['seller'] ?? '')),
                           'type_name' => trim((string)($o['type_name'] ?? '')), 'weight_kg' => pb_lc_kg($og), 'entry_date' => (string)($o['date'] ?? ''),
                           'ounce' => $num($o['ounce'] ?? null), 'gram' => $num($o['gram'] ?? null)];
            }
            usort($outs, $cmp);
            $procList[] = ['group_id' => $rk, 'kind' => $pg['crack'] ? 'crack' : 'proc', 'order_id' => (int)$pg['order_id'], 'date' => (string)$pg['date'],
                           'label' => $pg['crack'] ? 'شکسته شده' : 'فراوری سفارش ' . $pg['order_id'],
                           'color_key' => ($pg['run'] ? 2000000 : 1000000) + (int)$pg['order_id'],
                           'in_kg' => pb_lc_kg($inG), 'out_kg' => pb_lc_kg($outG), 'items' => $rows, 'outputs' => $outs];
        }
        usort($procList, fn($a, $b) => strcmp($b['date'], $a['date']) ?: ($b['order_id'] <=> $a['order_id']) ?: strcmp($a['group_id'], $b['group_id']));

        $outTypes[] = ['type_name' => $name, 'total_kg' => pb_lc_kg($s['total_g']), 'overview' => $s['overview'], 'normal' => $s['normal'], 'kot' => $kotList,
                       'processed' => $procList, 'exited' => $s['exited']];
    }
    // ترتیب بخش‌ها: ۴ نوع پیش‌فرض اول، بقیه الفبایی
    $preset = array_flip(PB_LC_PRESET_TYPES);
    usort($outTypes, function ($a, $b) use ($preset) {
        $pa = $preset[$a['type_name']] ?? null; $pb = $preset[$b['type_name']] ?? null;
        if ($pa !== null && $pb !== null) return $pa <=> $pb;
        if ($pa !== null) return -1;
        if ($pb !== null) return 1;
        return pb_lc_cmp_name($a['type_name'], $b['type_name']);
    });

    return ['range' => ['from' => $from, 'to' => $to, 'label' => $label], 'types' => $outTypes];
}

/* ---------------- بازه و تنظیمات ---------------- */

function pb_lc_days_in_jmonth(int $m): int { return $m <= 6 ? 31 : ($m <= 11 ? 30 : 29); }

/** رقم فارسی برای برچسب. */
function pb_lc_fa_digits(string $s): string { return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }

/** @return array{from:string,to:string,label:string} بازهٔ سالِ پسته‌ایِ شروع‌شده در سال شمسی $jy. */
function pb_lot_cartex_range(int $jy, int $month, int $day): array {
    $month = max(1, min(12, $month));
    $day = max(1, min(pb_lc_days_in_jmonth($month), $day));
    $from = pb_j2g_str($jy, $month, $day);
    $to = date('Y-m-d', strtotime(pb_j2g_str($jy + 1, $month, $day) . ' -1 day'));
    return ['from' => $from, 'to' => $to, 'label' => pb_lc_fa_digits('سال پسته‌ای ' . $jy . '–' . ($jy + 1))];
}

/** سال شمسیِ شروع بازه‌ای که تاریخ $ymd در آن می‌افتد. */
function pb_lc_start_year_of(string $ymd, int $month, int $day): int {
    [$jy, $jm, $jd] = pb_g2j((int)substr($ymd, 0, 4), (int)substr($ymd, 5, 2), (int)substr($ymd, 8, 2));
    return ($jm < $month || ($jm === $month && $jd < $day)) ? $jy - 1 : $jy;
}

/** @return array{month:int,day:int} */
function pb_lot_cartex_settings_get(PDO $pdo, int $cid): array {
    $m = (int)pb_app_setting_get($pdo, $cid, 'lot_cartex_start_month', '7');
    $d = (int)pb_app_setting_get($pdo, $cid, 'lot_cartex_start_day', '1');
    if ($m < 1 || $m > 12) $m = 7;
    if ($d < 1 || $d > pb_lc_days_in_jmonth($m)) $d = 1;
    return ['month' => $m, 'day' => $d];
}

/** @throws InvalidArgumentException */
function pb_lot_cartex_settings_save(PDO $pdo, int $cid, array $payload): array {
    $m = (int)($payload['month'] ?? 0); $d = (int)($payload['day'] ?? 0);
    if ($m < 1 || $m > 12) throw new InvalidArgumentException('ماه شروع باید بین ۱ تا ۱۲ باشد');
    if ($d < 1 || $d > pb_lc_days_in_jmonth($m)) throw new InvalidArgumentException('روز شروع برای این ماه معتبر نیست');
    pb_app_setting_set($pdo, $cid, 'lot_cartex_start_month', (string)$m);
    pb_app_setting_set($pdo, $cid, 'lot_cartex_start_day', (string)$d);
    return ['month' => $m, 'day' => $d];
}

/**
 * payload: year_start (سال شمسی شروع) | from+to (میلادی). بدون هیچ‌کدام ⇒ سال پسته‌ایِ جاری.
 * خروجی علاوه بر build: years (سال‌های شروعِ دارای داده + سال جاری، نزولی)، settings.
 */
function pb_lot_cartex_report(PDO $pdo, int $cid, array $payload): array {
    $set = pb_lot_cartex_settings_get($pdo, $cid);
    $dateRe = '/^\d{4}-\d{2}-\d{2}$/';
    $today = date('Y-m-d');
    if (!empty($payload['from']) && !empty($payload['to']) && preg_match($dateRe, (string)$payload['from']) && preg_match($dateRe, (string)$payload['to'])) {
        $from = (string)$payload['from']; $to = (string)$payload['to'];
        if ($from > $to) { $x = $from; $from = $to; $to = $x; }
        $r = ['from' => $from, 'to' => $to, 'label' => 'از ' . $from . ' تا ' . $to];
        $yr = pb_lc_start_year_of($from, $set['month'], $set['day']);
        $std = pb_lot_cartex_range($yr, $set['month'], $set['day']);
        if ($std['from'] === $from && $std['to'] === $to) $r = $std;
    } else {
        $yr = !empty($payload['year_start']) ? (int)$payload['year_start'] : pb_lc_start_year_of($today, $set['month'], $set['day']);
        if ($yr < 1300 || $yr > 1700) $yr = pb_lc_start_year_of($today, $set['month'], $set['day']);
        $r = pb_lot_cartex_range($yr, $set['month'], $set['day']);
    }

    $q = $pdo->prepare('SELECT id, code, type_name, seller, net_weight, remaining, date, is_mix, ounce, gram FROM purchases WHERE customer_id=? AND is_mix IN (0,1,2)');
    $q->execute([$cid]);
    $allLots = $q->fetchAll(PDO::FETCH_ASSOC);
    // کوت‌ها (لات مخلوط) باید حتی خارج از بازه برای تاریخ/کد در دسترس باشند: همهٔ لات‌ها لازم‌اند (is_mix=3 هم برای کوت ندارد)
    $q = $pdo->prepare('SELECT mix_purchase_id, source_purchase_id, weight FROM mix_sources WHERE customer_id=?');
    $q->execute([$cid]);
    $mixSources = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $pdo->prepare('SELECT i.lot_id, i.kg, i.order_id, o.order_date, o.op_type FROM proc_inputs i JOIN proc_orders o ON o.id=i.order_id AND o.customer_id=i.customer_id WHERE i.customer_id=?');
    $q->execute([$cid]);
    $procInputs = $q->fetchAll(PDO::FETCH_ASSOC);
    try { // خط شکست لات‌شده: لات مبدأ ← تاریخ اجرای خط
        $q = $pdo->prepare('SELECT l.source_lot_id AS lot_id, MAX(l.kg) AS kg, l.run_id AS order_id, r.run_date AS order_date, \'crack\' AS op_type, \'crack_run\' AS kind
            FROM crack_run_lots l JOIN crack_runs r ON r.id=l.run_id AND r.customer_id=l.customer_id WHERE l.customer_id=? GROUP BY l.source_lot_id, l.run_id, r.run_date');
        $q->execute([$cid]);
        // مقدار مصرف‌شده از مبدأ = جمع خروجی‌های لات‌شدهٔ همان اجرا (lots_only)؛ استخر full: input_g
        $runs = $q->fetchAll(PDO::FETCH_ASSOC);
        $sum = $pdo->prepare('SELECT run_id, source_lot_id, SUM(kg) AS kg FROM crack_run_lots WHERE customer_id=? GROUP BY run_id, source_lot_id');
        $sum->execute([$cid]);
        $kgBy = [];
        foreach ($sum->fetchAll(PDO::FETCH_ASSOC) as $x) $kgBy[$x['run_id'] . '|' . $x['source_lot_id']] = (float)$x['kg'];
        try {
            $pl = $pdo->prepare('SELECT run_id, source_lot_id, input_g FROM crack_pools WHERE customer_id=?');
            $pl->execute([$cid]);
            foreach ($pl->fetchAll(PDO::FETCH_ASSOC) as $x) if ((int)$x['input_g'] > 0) $kgBy[$x['run_id'] . '|' . $x['source_lot_id']] = (int)$x['input_g'] / 1000;
        } catch (Throwable $e) { /* crack_pools هنوز ساخته نشده */ }
        foreach ($runs as $x) {
            $x['kg'] = $kgBy[$x['order_id'] . '|' . $x['lot_id']] ?? (float)$x['kg'];
            $procInputs[] = $x;
        }
    } catch (Throwable $e) { /* جدول‌های شکست هنوز ساخته نشده */ }
    $q = $pdo->prepare('SELECT i.purchase_id, i.weight, s.date FROM sale_items i JOIN sales s ON s.id=i.sale_id AND s.customer_id=i.customer_id WHERE i.customer_id=?');
    $q->execute([$cid]);
    $saleItems = $q->fetchAll(PDO::FETCH_ASSOC);

    // لات‌های ساخته‌شده از فراوری (proc_outputs) و از خط شکست (crack_run_lots) — برای نمایش زیر پستهٔ مبدأ
    $procOutputs = [];
    try {
        $q = $pdo->prepare('SELECT order_id, lot_id, source_lot_id FROM proc_outputs WHERE customer_id=?');
        $q->execute([$cid]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) $procOutputs[] = $x;
    } catch (Throwable $e) { /* جدول هنوز ساخته نشده */ }
    try {
        $q = $pdo->prepare('SELECT run_id AS order_id, lot_id, source_lot_id, \'crack_run\' AS kind FROM crack_run_lots WHERE customer_id=?');
        $q->execute([$cid]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) $procOutputs[] = $x;
    } catch (Throwable $e) { /* جدول‌های شکست هنوز ساخته نشده */ }

    $res = pb_lot_cartex_build($allLots, $mixSources, $procInputs, $saleItems, $r, $procOutputs);

    // سال‌های دارای داده + سال جاری
    $years = [pb_lc_start_year_of($today, $set['month'], $set['day']) => true];
    foreach ($allLots as $l) if (!empty($l['date'])) $years[pb_lc_start_year_of((string)$l['date'], $set['month'], $set['day'])] = true;
    $ys = array_keys($years);
    rsort($ys);
    $res['years'] = array_map(fn($y) => ['year_start' => (int)$y, 'label' => pb_lot_cartex_range((int)$y, $set['month'], $set['day'])['label']], $ys);
    $res['settings'] = $set;
    return $res;
}
