<?php
declare(strict_types=1);
/**
 * پسته‌کاو — ابزارهای فقط‌خواندنی که مدل اجازهٔ صدا زدنشان را دارد (فهرست ثابت، ۶ ابزار).
 * قواعد: فقط خواندن؛ customer_id فقط از ctx (توکن احراز‌شده)؛ آرگومان‌های مدل هرگز SQL نمی‌شوند؛
 * خروجی برای مدل فشرده است و فیلدهای متنی آزاد (note/custom_specs/photo) و شناسه‌های داخلی در آن نیست.
 * هر ابزار سه چیز برمی‌گرداند: data (برای مدل)، blocks (برای نمایش به کاربر؛ اعداد را سرور نشان می‌دهد)،
 * facts (همهٔ اعداد داخل data؛ برای «نگهبان عدد» مرحلهٔ A4).
 */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }
require_once __DIR__ . '/assistant_h_common.php';

const AI_TOOL_MAX_ROWS = 30;       // سقف ردیف در خروجی ابزار
const AI_TOOL_MAX_CANDIDATES = 8;  // سقف کاندیداها در حالت مبهم
const AI_TOOL_NAME_MAX = 60;       // سقف طول نام‌ها

/** نام ابزارهای مجاز (تنها فهرست قابل‌اجرا) */
function ai_tools_names(): array {
    return ['get_stock_total', 'get_stock_item', 'get_party_debt', 'get_party_receivable', 'get_dues', 'find_lot_by_code'];
}

/** اسکیمای OpenAI برای ابزارها */
function ai_tools_schema(): array {
    $fn = static function (string $name, string $desc, array $props, array $required): array {
        return ['type' => 'function', 'function' => [
            'name' => $name, 'description' => $desc,
            'parameters' => ['type' => 'object', 'properties' => $props ?: new stdClass(), 'required' => $required],
        ]];
    };
    return [
        $fn('get_stock_total', 'موجودی کل انبار (کیلوگرم) و موجودی هر جنس و تعداد گونی‌های باقی‌مانده را برمی‌گرداند.', [], []),
        $fn('get_stock_item', 'موجودی یک جنس مشخص (کیلوگرم، گونی، و لات‌ها با کد) را برمی‌گرداند. بخشی از نام جنس یا دسته کافی است.',
            ['type_query' => ['type' => 'string', 'maxLength' => AI_TOOL_NAME_MAX, 'description' => 'نام یا بخشی از نام جنس یا دسته، مثلاً «اکبری»']], ['type_query']),
        $fn('get_party_debt', 'میزان بدهی ما به یک طرف‌حساب (بابت خرید و قرض) را برمی‌گرداند. بخشی از نام کافی است.',
            ['party_query' => ['type' => 'string', 'maxLength' => AI_TOOL_NAME_MAX, 'description' => 'نام یا بخشی از نام طرف‌حساب']], ['party_query']),
        $fn('get_party_receivable', 'میزان طلب ما از یک طرف‌حساب یا خریدار (مانده‌ی وصول‌نشده‌ی فروش‌ها و قرضِ داده‌شده) را برمی‌گرداند. برای پرسش‌هایی مثل «چقدر از فلانی طلب دارم / باید وصول کنم» استفاده شود. بخشی از نام کافی است.',
            ['party_query' => ['type' => 'string', 'maxLength' => AI_TOOL_NAME_MAX, 'description' => 'نام یا بخشی از نام طرف‌حساب یا خریدار']], ['party_query']),
        $fn('get_dues', 'سررسیدهای دریافتی (باید دریافت کنیم) و پرداختی (باید بپردازیم) را برای یک بازه برمی‌گرداند.',
            ['range' => ['type' => 'string', 'enum' => ['today', 'tomorrow', 'week'], 'description' => 'today=امروز، tomorrow=فردا، week=هفت روز آینده']], ['range']),
        $fn('find_lot_by_code', 'مشخصات یک پسته (خرید یا مخلوط) را با کد آن (مثل KH00012 یا MX00003) برمی‌گرداند.',
            ['code' => ['type' => 'string', 'maxLength' => 20, 'description' => 'کد پسته مثل KH00012']], ['code']),
    ];
}

// ------------------------------------------------------------------ کمکی‌ها

/** ارقام فارسی/عربی → لاتین؛ جداکنندهٔ هزارگان حذف و ممیز فارسی → نقطه */
function ai_digits_en(string $s): string {
    return strtr($s, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٫' => '.', '٬' => ',',
    ]);
}

/** پاک‌سازی متن آزادِ ثبت‌شده توسط کاربر پیش از رفتن به مدل: بدون نویسه‌های کنترلی/جهت‌دهی و نشانه‌گذاری قالب، با سقف طول */
function ai_clean($s, int $max = AI_TOOL_NAME_MAX): string {
    $s = (string)$s;
    $s = (string)preg_replace('/[\p{Cc}\x{200B}\x{200D}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', ' ', $s);
    $s = str_replace(['ي', 'ك', '`', '<', '>', '{', '}'], ['ی', 'ک', '', '', '', '', ''], $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, $max);
}

/** شکل مقایسه‌ایِ متن برای جستجوی «شامل»: بدون فاصله/نیم‌فاصله/کشیده، ارقام لاتین، حروف کوچک */
function ai_fold($s): string {
    $s = ai_clean($s, 200);
    $s = str_replace(["\u{200C}", "\u{0640}", ' '], '', $s);
    return mb_strtolower(ai_digits_en($s));
}

/** همهٔ اعداد داخل ساختار data (عددها و ارقام داخل رشته‌ها، مثل تاریخ شمسی و کد) برای نگهبان عدد */
function ai_collect_facts($v, array &$acc = []): array {
    if (is_int($v) || is_float($v)) {
        $acc[(string)round((float)$v, 2)] = round((float)$v, 2);
    } elseif (is_string($v)) {
        $t = ai_digits_en($v);
        $t = (string)preg_replace('/(?<=\d),(?=\d{3}(?:\D|$))/', '', $t);
        if (preg_match_all('/\d+(?:\.\d+)?/', $t, $m)) {
            foreach ($m[0] as $x) { $f = round((float)$x, 2); $acc[(string)$f] = $f; }
        }
    } elseif (is_array($v)) {
        foreach ($v as $x) ai_collect_facts($x, $acc);
    }
    return array_values($acc);
}

function ai_kg_text(float $n): string { return as_num($n, 2) . ' کیلوگرم'; }
function ai_money_text(float $n): string { return as_money($n); }
function ai_jd(?string $d): string { return as_jdate($d === null ? null : substr($d, 0, 10)); }

/** کدِ قابل‌قبول (KH/MX + رقم) یا null */
function ai_norm_code($raw): ?string {
    $c = strtoupper(ai_digits_en((string)$raw));
    $flat = (string)preg_replace('/[^A-Z0-9]/', '', $c);
    if (preg_match('/^(KH|MX|PR|OR|[A-Z])\d{3,}$/', $flat)) return $flat; // T1: KH00012 · M00003 · D405… · قدیمی MX/PR/OR
    $chain = (string)preg_replace('/[^A-Z0-9-]/', '', $c);
    return preg_match('/^[SWBK]-[A-Z0-9]+(-[A-Z0-9]+)*$/', $chain) ? $chain : null; // زنجیره: S-KH00012-1
}

// ------------------------------------------------------------------ خواندن یک لات با کد (فقط SELECT)

/** مشخصات یک خرید/مخلوط با کد؛ فقط برای همین مشتری. شامل فروخته‌شده‌ها هم هست. بدون قیمت و بدون متن آزاد. */
function as_rd_lot_by_code(PDO $pdo, int $cid, string $code): ?array {
    $code = ai_norm_code($code);
    if ($code === null) return null;
    $from = 'FROM purchases p JOIN categories c ON c.id = p.category_id AND c.customer_id = p.customer_id WHERE p.customer_id = ? AND p.code = ? LIMIT 1';
    try {
        $st = $pdo->prepare('SELECT p.code, p.type_name, p.date, p.net_weight, p.remaining, p.small_sacks_remaining, p.large_sacks_remaining, p.seller, p.is_mix, c.name AS category_name ' . $from);
        $st->execute([$cid, $code]);
    } catch (PDOException $e) { // دیتابیس قدیمی بدون ستون‌های گونی
        $st = $pdo->prepare('SELECT p.code, p.type_name, p.date, p.net_weight, p.remaining, NULL AS small_sacks_remaining, NULL AS large_sacks_remaining, p.seller, p.is_mix, c.name AS category_name ' . $from);
        $st->execute([$cid, $code]);
    }
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return [
        'code' => (string)$r['code'], 'type_name' => (string)$r['type_name'], 'category_name' => (string)$r['category_name'],
        'date' => substr((string)$r['date'], 0, 10), 'net_weight' => (float)$r['net_weight'], 'remaining' => (float)$r['remaining'],
        'small_sacks_remaining' => (float)($r['small_sacks_remaining'] ?? 0), 'large_sacks_remaining' => (float)($r['large_sacks_remaining'] ?? 0),
        'seller' => ai_clean($r['seller'] ?? ''), 'is_mix' => (int)$r['is_mix'] === 1,
    ];
}

// ------------------------------------------------------------------ اجرای ابزار

/**
 * @param array $ctx باید شامل pdo و cid باشد (از توکن احراز‌شده). هر customer_id داخل $args نادیده گرفته می‌شود.
 * @return array{data:array,blocks:array,facts:array}
 */
function ai_tool_run(string $name, array $args, array $ctx): array {
    $none = ['blocks' => [], 'facts' => []];
    if (!in_array($name, ai_tools_names(), true)) return ['data' => ['error' => 'unknown_tool']] + $none;
    $pdo = $ctx['pdo'] ?? null;
    $cid = (int)($ctx['cid'] ?? 0);
    if (!($pdo instanceof PDO) || $cid <= 0) return ['data' => ['error' => 'no_context']] + $none;
    try {
        switch ($name) {
            case 'get_stock_total':  $res = ai_tool_stock_total($pdo, $cid); break;
            case 'get_stock_item':   $res = ai_tool_stock_item($pdo, $cid, $args['type_query'] ?? null); break;
            case 'get_party_debt':   $res = ai_tool_party_debt($pdo, $cid, $args['party_query'] ?? null); break;
            case 'get_party_receivable': $res = ai_tool_party_receivable($pdo, $cid, $args['party_query'] ?? null); break;
            case 'get_dues':         $res = ai_tool_dues($pdo, $cid, $args['range'] ?? null); break;
            default:                 $res = ai_tool_find_lot($pdo, $cid, $args['code'] ?? null); break;
        }
    } catch (Throwable $e) {
        error_log('[ai-tool] ' . $name . ' ' . get_class($e)); // بدون پیام استثنا (ممکن است داده داشته باشد)
        return ['data' => ['error' => 'tool_failed']] + $none;
    }
    $res['facts'] = ai_collect_facts($res['data']);
    return $res;
}

/** ورودی متنیِ نامعتبر/خالی */
function ai_tool_bad_arg(string $field): array {
    return ['data' => ['error' => 'invalid_argument', 'field' => $field], 'blocks' => []];
}

function ai_tool_stock_total(PDO $pdo, int $cid): array {
    $groups = as_rd_stock_groups($pdo, $cid);
    $total = 0.0; $items = []; $rows = [];
    foreach ($groups as $g) {
        $total += $g['kg'];
        if (count($items) < AI_TOOL_MAX_ROWS) {
            $items[] = ['type' => ai_clean($g['type_name']), 'category' => ai_clean($g['category_name']),
                'kg' => round($g['kg'], 2), 'kg_text' => ai_kg_text($g['kg']),
                'small_sacks_left' => round($g['small']), 'large_sacks_left' => round($g['large'])];
            $rows[] = [$g['type_name'], $g['category_name'], as_num($g['kg'], 2)];
        }
    }
    $data = ['total_kg' => round($total, 2), 'total_kg_text' => ai_kg_text($total), 'item_count' => count($groups),
             'items' => $items, 'truncated' => count($groups) > count($items)];
    if (!$groups) {
        $blocks = [blk_card('موجودی کل انبار', [['label' => 'موجودی کل (کیلوگرم)', 'value' => as_num(0.0), 'raw' => 0.0]], '۰ کیلوگرم'),
                   blk_notice('در حال حاضر موجودی‌ای در انبار ثبت نشده است.', 'info')];
    } else {
        $blocks = [
            blk_card('موجودی کل انبار', [
                ['label' => 'موجودی کل (کیلوگرم)', 'value' => as_num($total, 2), 'raw' => round($total, 3)],
                ['label' => 'تعداد اجناس', 'value' => as_num((float)count($groups)), 'raw' => count($groups)],
            ], as_num($total, 2) . ' کیلوگرم'),
            blk_table('به تفکیک جنس', [['key' => 'type', 'label' => 'جنس'], ['key' => 'category', 'label' => 'دسته'], ['key' => 'kg', 'label' => 'کیلوگرم', 'align' => 'end']],
                $rows, count($groups) > count($rows) ? null : ['جمع', '', as_num($total, 2)]),
        ];
    }
    return ['data' => $data, 'blocks' => $blocks];
}

function ai_tool_stock_item(PDO $pdo, int $cid, $q): array {
    if (!is_string($q) || ($f = ai_fold($q)) === '') return ai_tool_bad_arg('type_query');
    $groups = as_rd_stock_groups($pdo, $cid);
    $hits = [];
    foreach ($groups as $g) {
        if (str_contains(ai_fold($g['category_name'] . $g['type_name']), $f)) $hits[] = $g;
    }
    if (!$hits) {
        $names = [];
        foreach (array_slice($groups, 0, 15) as $g) $names[] = ai_clean($g['type_name']);
        return ['data' => ['found' => false, 'available_types' => $names],
                'blocks' => [blk_notice('جنسی با این نام در موجودی انبار پیدا نشد.', 'info')]];
    }
    $total = 0.0; foreach ($hits as $g) $total += $g['kg'];
    $shown = array_slice($hits, 0, AI_TOOL_MAX_CANDIDATES);
    $single = count($hits) === 1;
    $matches = [];
    foreach ($shown as $g) {
        $m = ['type' => ai_clean($g['type_name']), 'category' => ai_clean($g['category_name']), 'kg' => round($g['kg'], 2), 'kg_text' => ai_kg_text($g['kg']),
              'small_sacks_left' => round($g['small']), 'large_sacks_left' => round($g['large']), 'lot_count' => count($g['lots'])];
        if ($single) {
            $m['lots'] = [];
            foreach (array_slice($g['lots'], 0, 15) as $r) {
                $m['lots'][] = ['code' => (string)($r['code'] ?? ''), 'date' => ai_jd($r['date'] ?? null), 'kg' => round((float)$r['remaining'], 2),
                                'small_sacks_left' => round((float)($r['small_sacks_remaining'] ?? 0)), 'large_sacks_left' => round((float)($r['large_sacks_remaining'] ?? 0))];
            }
        }
        $matches[] = $m;
    }
    $data = ['found' => true, 'match_count' => count($hits), 'total_kg_of_matches' => round($total, 2), 'total_kg_text' => ai_kg_text($total),
             'matches' => $matches, 'truncated' => count($hits) > count($shown)];
    if ($single) {
        $g = $hits[0]; $rows = [];
        foreach (array_slice($g['lots'], 0, 15) as $r) {
            $rows[] = [(string)($r['code'] ?? ''), ai_jd($r['date'] ?? null), as_num((float)$r['remaining'], 2),
                       as_num((float)($r['small_sacks_remaining'] ?? 0)), as_num((float)($r['large_sacks_remaining'] ?? 0))];
        }
        $blocks = [
            blk_card($g['type_name'], [
                ['label' => 'دسته', 'value' => $g['category_name']],
                ['label' => 'موجودی (کیلوگرم)', 'value' => as_num($g['kg'], 2), 'raw' => round($g['kg'], 3)],
                ['label' => 'گونی کوچک باقی‌مانده', 'value' => as_num($g['small']), 'raw' => round($g['small'])],
                ['label' => 'گونی بزرگ باقی‌مانده', 'value' => as_num($g['large']), 'raw' => round($g['large'])],
            ], as_num($g['kg'], 2) . ' کیلوگرم'),
            blk_table(count($rows) > 1 ? 'لات‌ها (' . as_num((float)count($rows)) . ' مورد)' : 'لات', [
                ['key' => 'code', 'label' => 'کد'], ['key' => 'date', 'label' => 'تاریخ'], ['key' => 'kg', 'label' => 'کیلوگرم', 'align' => 'end'],
                ['key' => 'small', 'label' => 'گونی کوچک', 'align' => 'end'], ['key' => 'large', 'label' => 'گونی بزرگ', 'align' => 'end'],
            ], $rows),
        ];
    } else {
        $rows = [];
        foreach ($shown as $g) $rows[] = [$g['type_name'], $g['category_name'], as_num($g['kg'], 2)];
        $blocks = [blk_table('اجناس مشابه (' . as_num((float)count($hits)) . ' مورد)', [['key' => 'type', 'label' => 'جنس'], ['key' => 'category', 'label' => 'دسته'], ['key' => 'kg', 'label' => 'کیلوگرم', 'align' => 'end']], $rows)];
    }
    return ['data' => $data, 'blocks' => $blocks];
}

function ai_tool_party_debt(PDO $pdo, int $cid, $q): array {
    if (!is_string($q) || ($f = ai_fold($q)) === '') return ai_tool_bad_arg('party_query');
    $eps = 0.5;
    $parties = as_rd_party_debts($pdo, $cid);
    $exact = []; $hits = [];
    foreach ($parties as $p) {
        $n = ai_fold($p['name']);
        if ($n === '') continue;
        if ($n === $f) $exact[] = $p;
        if (str_contains($n, $f)) $hits[] = $p;
    }
    $similar = [];
    if ($exact) { // تطابق کامل نام بر مبهم بودن اولویت دارد؛ ولی نام‌های مشابه را هم می‌گوییم تا مدل بتواند اشاره کند
        foreach ($hits as $h) { if ($h['name'] !== $exact[0]['name'] && count($similar) < 5) $similar[] = ai_clean($h['name']); }
        $hits = [$exact[0]];
    }
    if (!$hits) return ['data' => ['found' => false], 'blocks' => [blk_notice('طرف‌حسابی با این نام پیدا نشد.', 'info')]];

    if (count($hits) > 1) {
        $cands = []; $rows = [];
        foreach (array_slice($hits, 0, AI_TOOL_MAX_CANDIDATES) as $p) {
            $d = max(0.0, $p['debt']);
            $cands[] = ['name' => ai_clean($p['name']), 'debt' => round($d, 2), 'debt_text' => ai_money_text($d)];
            $rows[] = [$p['name'], as_money($d)];
        }
        return ['data' => ['found' => true, 'ambiguous' => true, 'candidate_count' => count($hits), 'candidates' => $cands, 'truncated' => count($hits) > count($cands)],
                'blocks' => [blk_table('طرف‌حساب‌های مشابه', [['key' => 'name', 'label' => 'طرف‌حساب'], ['key' => 'debt', 'label' => 'بدهی ما', 'align' => 'end']], $rows)]];
    }

    $p = $hits[0];
    $name = $p['name'] === '' ? 'بدون نام' : $p['name'];
    $buy = max(0.0, $p['weOwe']); $loan = max(0.0, $p['loanPayable']);
    $cur = as_money_unit();
    if ($p['debt'] > $eps) {
        $data = ['found' => true, 'ambiguous' => false, 'name' => ai_clean($name), 'status' => 'debtor', 'total_debt' => round($p['debt'], 2), 'total_debt_text' => ai_money_text($p['debt']),
                 'for_purchases' => round($buy, 2), 'for_purchases_text' => ai_money_text($buy), 'for_loans' => round($loan, 2), 'for_loans_text' => ai_money_text($loan),
                 'for_opening_balance' => round($p['openingDebt'], 2), 'for_opening_balance_text' => ai_money_text($p['openingDebt']),
                 'partly_creditor' => ($p['weOwe'] < -$eps || $p['loanPayable'] < -$eps)];
        if ($similar) $data['similar_names'] = $similar;
        $rows = [
            ['label' => 'بابت خرید', 'value' => as_money($buy), 'raw' => round($buy, 2)],
            ['label' => 'بابت قرض', 'value' => as_money($loan), 'raw' => round($loan, 2)],
        ];
        if ($p['openingDebt'] > $eps) $rows[] = ['label' => 'بابت مانده اول دوره', 'value' => as_money($p['openingDebt']), 'raw' => round($p['openingDebt'], 2)];
        $blocks = [blk_card('بدهی شما به «' . $name . '»', $rows, as_money($p['debt']))];
        if ($data['partly_creditor']) $blocks[] = blk_notice('بخشی از حساب شما با این طرف‌حساب بستانکار است و از مبلغ کل کم شده است.', 'info');
    } else {
        $credit = $p['debt'] < -$eps;
        $data = ['found' => true, 'ambiguous' => false, 'name' => ai_clean($name), 'status' => $credit ? 'creditor' : 'no_debt', 'total_debt' => 0, 'total_debt_text' => ai_money_text(0.0)];
        if ($similar) $data['similar_names'] = $similar;
        $blocks = [blk_card('بدهی شما به «' . $name . '»', [['label' => 'میزان بدهی', 'value' => as_money(0.0), 'raw' => 0.0]],
            $credit ? 'بدهی صفر — بستانکار هستید' : 'بدهی‌ای وجود ندارد')];
    }
    return ['data' => $data, 'blocks' => $blocks];
}

/** طلب ما از یک طرف‌حساب (وصول‌نشده‌ی فروش‌ها + قرضِ داده‌شده) — قرینه‌ی ai_tool_party_debt */
function ai_tool_party_receivable(PDO $pdo, int $cid, $q): array {
    if (!is_string($q) || ($f = ai_fold($q)) === '') return ai_tool_bad_arg('party_query');
    $eps = 0.5;
    $parties = as_rd_party_debts($pdo, $cid);
    $exact = []; $hits = [];
    foreach ($parties as $p) {
        $n = ai_fold($p['name']);
        if ($n === '') continue;
        if ($n === $f) $exact[] = $p;
        if (str_contains($n, $f)) $hits[] = $p;
    }
    $similar = [];
    if ($exact) { // تطابق کامل نام بر مبهم بودن اولویت دارد
        foreach ($hits as $h) { if ($h['name'] !== $exact[0]['name'] && count($similar) < 5) $similar[] = ai_clean($h['name']); }
        $hits = [$exact[0]];
    }
    if (!$hits) return ['data' => ['found' => false], 'blocks' => [blk_notice('طرف‌حسابی با این نام پیدا نشد.', 'info')]];

    if (count($hits) > 1) {
        $cands = []; $rows = [];
        foreach (array_slice($hits, 0, AI_TOOL_MAX_CANDIDATES) as $p) {
            $d = max(0.0, $p['receivable']);
            $cands[] = ['name' => ai_clean($p['name']), 'receivable' => round($d, 2), 'receivable_text' => ai_money_text($d)];
            $rows[] = [$p['name'], as_money($d)];
        }
        return ['data' => ['found' => true, 'ambiguous' => true, 'candidate_count' => count($hits), 'candidates' => $cands, 'truncated' => count($hits) > count($cands)],
                'blocks' => [blk_table('طرف‌حساب‌های مشابه', [['key' => 'name', 'label' => 'طرف‌حساب'], ['key' => 'receivable', 'label' => 'طلب ما', 'align' => 'end']], $rows)]];
    }

    $p = $hits[0];
    $name = $p['name'] === '' ? 'بدون نام' : $p['name'];
    $sales = max(0.0, $p['theyOwe']); $loan = max(0.0, $p['loanReceivable']);
    $cur = as_money_unit();
    if ($p['receivable'] > $eps) {
        $data = ['found' => true, 'ambiguous' => false, 'name' => ai_clean($name), 'status' => 'debtor_to_us', 'total_receivable' => round($p['receivable'], 2), 'total_receivable_text' => ai_money_text($p['receivable']),
                 'for_sales' => round($sales, 2), 'for_sales_text' => ai_money_text($sales), 'for_loans' => round($loan, 2), 'for_loans_text' => ai_money_text($loan),
                 'for_opening_balance' => round($p['openingReceivable'], 2), 'for_opening_balance_text' => ai_money_text($p['openingReceivable']),
                 'partly_creditor' => ($p['theyOwe'] < -$eps || $p['loanReceivable'] < -$eps)];
        if ($similar) $data['similar_names'] = $similar;
        $rows = [
            ['label' => 'بابت فروش', 'value' => as_money($sales), 'raw' => round($sales, 2)],
            ['label' => 'بابت قرض', 'value' => as_money($loan), 'raw' => round($loan, 2)],
        ];
        if ($p['openingReceivable'] > $eps) $rows[] = ['label' => 'بابت مانده اول دوره', 'value' => as_money($p['openingReceivable']), 'raw' => round($p['openingReceivable'], 2)];
        $blocks = [blk_card('طلب شما از «' . $name . '»', $rows, as_money($p['receivable']))];
        if ($data['partly_creditor']) $blocks[] = blk_notice('بخشی از حساب شما با این طرف‌حساب بدهکار است و از مبلغ کل کم شده است.', 'info');
    } else {
        $over = $p['receivable'] < -$eps; // او بیش از حساب‌مان پرداخته است
        $data = ['found' => true, 'ambiguous' => false, 'name' => ai_clean($name), 'status' => $over ? 'overpaid' : 'no_receivable', 'total_receivable' => 0, 'total_receivable_text' => ai_money_text(0.0)];
        if ($similar) $data['similar_names'] = $similar;
        $blocks = [blk_card('طلب شما از «' . $name . '»', [['label' => 'میزان طلب', 'value' => as_money(0.0), 'raw' => 0.0]],
            $over ? 'طلب صفر — بیش از حساب پرداخته است' : 'طلبی وجود ندارد')];
    }
    return ['data' => $data, 'blocks' => $blocks];
}

function ai_tool_dues(PDO $pdo, int $cid, $range): array {
    if (!is_string($range) || !in_array($range, ['today', 'tomorrow', 'week'], true)) return ai_tool_bad_arg('range');
    $c = (array)(as_config()['dues'] ?? []);
    $d0 = as_rd_date_offset((int)($c['today'] ?? 0));
    $tm = as_rd_date_offset((int)($c['tomorrow'] ?? 1));
    $wk = (int)($c['week'] ?? 7);
    $map = [
        'today'    => ['امروز', $d0, $d0],
        'tomorrow' => ['فردا', $tm, $tm],
        'week'     => [as_fa_digits((string)$wk) . ' روز آینده', $d0, as_rd_date_offset($wk)],
    ];
    [$label, $from, $to] = $map[$range];
    $all = as_rd_dues_all($pdo, $cid);
    $cur = as_money_unit();
    $data = ['range' => $range, 'label' => $label, 'from' => ai_jd($from), 'to' => ai_jd($to)];
    $blocks = [blk_text($label . ($from === $to ? ' — ' . ai_jd($from) : ''))];
    foreach ([[true, 'receivable', 'سررسید وصول'], [false, 'payable', 'سررسید بدهی']] as [$isRec, $key, $title]) {
        $band = as_rd_dues_band($all, $isRec, $from, $to);
        $sum = 0.0; foreach ($band as $it) $sum += (float)$it['amount'];
        $rowsData = []; $rows = [];
        foreach (array_slice($band, 0, AI_TOOL_MAX_ROWS) as $it) {
            $rowsData[] = ['party' => ai_clean($it['party'] === '' ? 'بدون نام' : $it['party']), 'kind' => $it['kind'], 'amount' => round((float)$it['amount'], 2),
                           'amount_text' => ai_money_text((float)$it['amount']), 'date' => ai_jd($it['date']),
                           'ref' => ai_clean($it['ref'], 30), 'bank' => ai_clean($it['bank'], 30)];
            $extra = trim($it['ref'] . ($it['ref'] !== '' && $it['bank'] !== '' ? ' / ' : '') . $it['bank']);
            $rows[] = [$it['party'] === '' ? 'بدون نام' : $it['party'], $it['kind'], as_money((float)$it['amount']), ai_jd($it['date']), $extra === '' ? '—' : $extra];
        }
        $data[$key] = ['count' => count($band), 'total' => round($sum, 2), 'total_text' => ai_money_text($sum), 'rows' => $rowsData, 'truncated' => count($band) > count($rowsData)];
        if (!$band) { $blocks[] = blk_card($title, [['label' => $label, 'value' => 'موردی نیست']]); continue; }
        $blocks[] = blk_table($title . ' (' . as_num((float)count($band)) . ' مورد)', [
            ['key' => 'party', 'label' => 'طرف‌حساب'], ['key' => 'kind', 'label' => 'نوع'], ['key' => 'amount', 'label' => 'مبلغ', 'align' => 'end'],
            ['key' => 'date', 'label' => 'تاریخ'], ['key' => 'cheque', 'label' => 'شماره چک / بانک'],
        ], $rows, ['جمع', '', as_money($sum), '', '']);
        if (count($band) > count($rows)) $blocks[] = blk_notice('فقط ' . as_num((float)count($rows)) . ' ردیف اول نمایش داده شد؛ جمع بالا مربوط به همهٔ موارد است.', 'info');
    }
    return ['data' => $data, 'blocks' => $blocks];
}

function ai_tool_find_lot(PDO $pdo, int $cid, $code): array {
    $c = is_string($code) ? ai_norm_code($code) : null;
    if ($c === null) {
        return ['data' => ['error' => 'invalid_code', 'hint' => 'کد باید مثل KH00012، M00003 یا S-KH00012-1 باشد'],
                'blocks' => [blk_notice('کد واردشده معتبر نیست؛ کد پسته مثل KH00012، M00003 یا S-KH00012-1 است.', 'info')]];
    }
    $r = as_rd_lot_by_code($pdo, $cid, $c);
    if ($r === null) return ['data' => ['found' => false, 'code' => $c], 'blocks' => [blk_notice('پسته‌ای با کد «' . $c . '» در حساب شما پیدا نشد.', 'info')]];
    $used = max(0.0, $r['net_weight'] - $r['remaining']);
    $avail = $r['remaining'] > 0.0005;
    $data = ['found' => true, 'code' => $r['code'], 'kind' => $r['is_mix'] ? 'مخلوط/جداسازی' : 'خرید', 'type' => ai_clean($r['type_name']), 'category' => ai_clean($r['category_name']),
             'date' => ai_jd($r['date']), 'net_kg' => round($r['net_weight'], 2), 'net_kg_text' => ai_kg_text($r['net_weight']),
             'remaining_kg' => round($r['remaining'], 2), 'remaining_kg_text' => ai_kg_text($r['remaining']),
             'sold_or_used_kg' => round($used, 2), 'sold_or_used_kg_text' => ai_kg_text($used), 'status' => $avail ? 'available' : 'sold_out',
             'small_sacks_left' => round($r['small_sacks_remaining']), 'large_sacks_left' => round($r['large_sacks_remaining']), 'seller' => $r['seller']];
    $lines = [
        ['label' => 'کد', 'value' => $r['code']],
        ['label' => 'نوع', 'value' => $data['kind']],
        ['label' => 'جنس', 'value' => $r['type_name']],
        ['label' => 'دسته', 'value' => $r['category_name']],
        ['label' => 'تاریخ', 'value' => $data['date']],
        ['label' => 'وزن خالص (کیلوگرم)', 'value' => as_num($r['net_weight'], 2), 'raw' => round($r['net_weight'], 3)],
        ['label' => 'مانده (کیلوگرم)', 'value' => as_num($r['remaining'], 2), 'raw' => round($r['remaining'], 3)],
        ['label' => 'فروخته/مصرف‌شده (کیلوگرم)', 'value' => as_num($used, 2), 'raw' => round($used, 3)],
        ['label' => 'گونی کوچک باقی‌مانده', 'value' => as_num($r['small_sacks_remaining'])],
        ['label' => 'گونی بزرگ باقی‌مانده', 'value' => as_num($r['large_sacks_remaining'])],
    ];
    if ($r['seller'] !== '') $lines[] = ['label' => 'فروشنده', 'value' => $r['seller']];
    return ['data' => $data, 'blocks' => [blk_card('پسته ' . $r['code'], $lines, $avail ? as_num($r['remaining'], 2) . ' کیلوگرم مانده' : 'کامل فروخته/مصرف شده')]];
}
