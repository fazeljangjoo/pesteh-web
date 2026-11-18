<?php
declare(strict_types=1);
/**
 * محاسبه‌گر کوت‌ریزی (quota) — فقط یک ماشین‌حساب: بدون دیتابیس، بدون ذخیره‌سازی.
 *
 * تعریف‌ها: وزن = کیلوگرم؛ گرم = درصد مغزدهی (از هر ۱۰۰ گرم پسته چند گرم مغز)؛
 *           انس = عدد انس (تعداد دانه در ۱۴۲٫۵ گرم ÷ ۵)؛ قیمت = قیمت هر کیلو (اختیاری).
 * فرمول: وزن نهایی = Σw؛ میانگین گرم = Σ(g·w) ÷ وزن نهایی؛ میانگین انس = Σ(o·w) ÷ وزن نهایی؛
 *        میانگین قیمت هر کیلو = Σ(p·w) ÷ وزن نهایی.
 * قیمت اختیاری است، ولی اگر در یک ردیف وارد شد باید در همه‌ی ردیف‌های پرشده وارد شود (وگرنه میانگین غلط می‌شود).
 * «مغز کل» (وزن نهایی × گرم میانگین) هرگز محاسبه یا برگردانده نمی‌شود.
 *
 * quota_calc() و توابع quota_* خالص‌اند (بدون وابستگی به DB یا assistant_lib)؛ فقط as_h_quota به lib نیاز دارد.
 */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }

const QUOTA_MAX_WEIGHT = 1e9;
const QUOTA_MAX_OUNCE = 1000.0;
const QUOTA_MAX_PRICE = 1e12; // سقف قیمت هر کیلو

/** ارقام لاتین → فارسی (همراه جداکننده‌ها) برای نمایش */
function quota_to_fa(string $s): string {
    return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ',' => '٬', '.' => '٫']);
}

/** نمایش با دقت ثابت $dec رقم اعشار (گرد فقط اینجا، در نمایش) */
function quota_fmt(float $n, int $dec = 2): string {
    $n = round($n, $dec);
    if ($n == 0.0) $n = 0.0;
    return quota_to_fa(number_format($n, $dec, '.', ','));
}

/** نمایش ورودی‌های کاربر: تا ۴ رقم اعشار، صفرهای انتهایی حذف */
function quota_fmt_trim(float $n): string {
    $s = number_format(round($n, 4), 4, '.', ',');
    $s = rtrim(rtrim($s, '0'), '.');
    return quota_to_fa($s);
}

/** رشته‌ی «خالی» (null یا فقط فاصله/نویسه‌ی نامرئی) */
function quota_is_empty($v): bool {
    if ($v === null) return true;
    if (!is_string($v)) return false;
    return preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}\x{202A}-\x{202F}\x{2009}\x{FEFF}]+/u', '', $v) === '';
}

/**
 * عدد را از ورودی کاربر نرمال می‌کند؛ نامعتبر = null.
 * ارقام فارسی/عربی، «٫» اعشار، «٬» و «،» و «,» جداکننده‌ها:
 *  - «٫» همیشه اعشار؛ «٬» همیشه هزارگان؛
 *  - «,» و «،»: اگر الگوی هزارگان باشد (۱٬۰۰۰ یا 1,000,000) هزارگان، وگرنه اگر یکی باشد اعشار (45,5)، وگرنه نامعتبر.
 */
function quota_norm_number($v): ?float {
    if (is_int($v)) return (float)$v;
    if (is_float($v)) return is_finite($v) ? $v : null;
    if (!is_string($v) || strlen($v) > 60) return null;
    $s = strtr($v, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    $s = (string)preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}\x{202A}-\x{202F}\x{2009}\x{FEFF}]+/u', '', $s);
    $s = str_replace(["\u{2212}", "\u{2013}", "\u{FF0D}"], '-', $s);
    $s = str_replace("\u{060C}", ',', $s);       // «،» مثل «,»
    $s = str_replace("\u{066C}", '', $s);        // «٬» هزارگان
    $s = str_replace("\u{066B}", '.', $s);       // «٫» اعشار
    if (strpos($s, ',') !== false) {
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) $s = str_replace(',', '', $s);
        elseif (substr_count($s, ',') === 1 && strpos($s, '.') === false) $s = str_replace(',', '.', $s);
        else return null;
    }
    if (!preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $s)) return null;
    $f = (float)$s;
    return is_finite($f) ? $f : null;
}

/**
 * قیمت: مثل quota_norm_number، ولی نقطه‌ی هزارگان هم پذیرفته می‌شود (۳.۱۰۰.۰۰۰ یا ۳.۱۰۰ ⇒ هزارگان).
 * قیمت هر کیلو در عمل عدد صحیح است، پس الگوی «۱ تا ۳ رقم + گروه‌های ۳رقمی با نقطه» هزارگان حساب می‌شود.
 */
function quota_norm_price($v): ?float {
    if (is_string($v)) {
        $t = strtr($v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $t = (string)preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}\x{202A}-\x{202F}\x{2009}\x{FEFF}]+/u', '', $t);
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $t)) $v = str_replace('.', '', $t);
    }
    return quota_norm_number($v);
}

/**
 * محاسبه‌ی خالص. $rows: آرایه‌ای از ردیف‌ها با کلیدهای weight, gram, ounce و price (اختیاری).
 * ردیف کاملاً خالی نادیده گرفته می‌شود؛ ردیف نیمه‌پر خطا می‌دهد (با شماره‌ی ردیفِ ارسالی، از ۱).
 * خروجی: ['final_weight','avg_gram','avg_ounce','avg_price'|null,'rows'=>[{index,weight,gram,ounce,price|null}]] — با دقت کامل (بدون گرد).
 * @throws InvalidArgumentException پیام فارسی روشن
 */
function quota_calc(array $rows, int $maxRows = 50): array {
    $rows = array_values($rows);
    if (count($rows) > $maxRows) {
        throw new InvalidArgumentException('حداکثر ' . quota_to_fa((string)$maxRows) . ' ردیف مجاز است.');
    }
    $use = [];
    foreach ($rows as $i => $r) {
        $n = quota_to_fa((string)($i + 1));
        if (!is_array($r)) throw new InvalidArgumentException("ردیف $n نامعتبر است.");
        $w = $r['weight'] ?? null; $g = $r['gram'] ?? null; $o = $r['ounce'] ?? null; $p = $r['price'] ?? null;
        if (quota_is_empty($w) && quota_is_empty($g) && quota_is_empty($o) && quota_is_empty($p)) continue; // ردیف خالی
        if (quota_is_empty($w)) throw new InvalidArgumentException("ردیف $n: وزن را وارد کنید.");
        if (quota_is_empty($g)) throw new InvalidArgumentException("ردیف $n: گرم را وارد کنید.");
        if (quota_is_empty($o)) throw new InvalidArgumentException("ردیف $n: انس را وارد کنید.");

        $wv = quota_norm_number($w);
        if ($wv === null) throw new InvalidArgumentException("ردیف $n: «وزن» باید یک عدد باشد.");
        if ($wv <= 0 || $wv > QUOTA_MAX_WEIGHT) throw new InvalidArgumentException("ردیف $n: «وزن» باید بیشتر از صفر و حداکثر ۱٬۰۰۰٬۰۰۰٬۰۰۰ کیلو باشد.");

        $gv = quota_norm_number($g);
        if ($gv === null) throw new InvalidArgumentException("ردیف $n: «گرم» باید یک عدد باشد.");
        if ($gv < 0 || $gv > 100) throw new InvalidArgumentException("ردیف $n: «گرم» باید بین ۰ تا ۱۰۰ باشد.");

        $ov = quota_norm_number($o);
        if ($ov === null) throw new InvalidArgumentException("ردیف $n: «انس» باید یک عدد باشد.");
        if ($ov <= 0 || $ov > QUOTA_MAX_OUNCE) throw new InvalidArgumentException("ردیف $n: «انس» باید بیشتر از صفر و حداکثر ۱٬۰۰۰ باشد.");

        $pv = null;
        if (!quota_is_empty($p)) {
            $pv = quota_norm_price($p);
            if ($pv === null) throw new InvalidArgumentException("ردیف $n: «قیمت هر کیلو» باید یک عدد باشد.");
            if ($pv <= 0 || $pv > QUOTA_MAX_PRICE) throw new InvalidArgumentException("ردیف $n: «قیمت هر کیلو» باید بیشتر از صفر باشد.");
        }
        $use[] = ['index' => $i + 1, 'weight' => $wv, 'gram' => $gv, 'ounce' => $ov, 'price' => $pv];
    }
    if (!$use) throw new InvalidArgumentException('حداقل یک ردیف کامل (وزن، گرم و انس) وارد کنید.');

    // قیمت: یا در همه‌ی ردیف‌های پرشده یا در هیچ‌کدام (وگرنه میانگین وزنی بی‌معنی می‌شود)
    $priced = 0;
    foreach ($use as $u) if ($u['price'] !== null) $priced++;
    if ($priced > 0 && $priced < count($use)) {
        foreach ($use as $u) {
            if ($u['price'] === null) {
                throw new InvalidArgumentException('ردیف ' . quota_to_fa((string)$u['index']) . ': «قیمت هر کیلو» را وارد کنید (یا قیمت را از همه‌ی ردیف‌ها خالی بگذارید).');
            }
        }
    }

    $sumW = 0.0; $sumGW = 0.0; $sumOW = 0.0; $sumPW = 0.0;
    foreach ($use as $u) {
        $sumW += $u['weight'];
        $sumGW += $u['gram'] * $u['weight'];
        $sumOW += $u['ounce'] * $u['weight'];
        if ($priced) $sumPW += $u['price'] * $u['weight'];
    }
    return [
        'final_weight' => $sumW, 'avg_gram' => $sumGW / $sumW, 'avg_ounce' => $sumOW / $sumW,
        'avg_price' => $priced ? $sumPW / $sumW : null,
        'rows' => $use,
    ];
}

/** فرم کوت‌ریزی (کاملاً از سرور؛ کلاینت فقط رندر می‌کند). مقادیر فرم در params.rows می‌روند. */
function as_quota_form(): array {
    $q = (array)(as_config()['quota'] ?? []);
    $max = max(1, min(200, (int)($q['max_rows'] ?? 50)));
    $init = max(1, min($max, (int)($q['initial_rows'] ?? 2)));
    return blk_form('محاسبه کوت‌ریزی', [
        ['name' => 'weight', 'label' => 'وزن (کیلو)', 'kind' => 'number'],
        ['name' => 'gram', 'label' => 'گرم', 'kind' => 'number', 'hint' => 'درصد مغزدهی؛ از هر ۱۰۰ گرم پسته چند گرم مغز (مثلاً ۴۵)'],
        ['name' => 'ounce', 'label' => 'انس', 'kind' => 'number', 'hint' => 'اگر تعداد دانه در ۱۴۲٫۵ گرم را می‌دانید، بر ۵ تقسیم کنید'],
        ['name' => 'price', 'label' => 'قیمت هر کیلو (اختیاری)', 'kind' => 'number', 'format' => 'thousands', 'hint' => 'مثلاً ۳٬۱۰۰٬۰۰۰؛ اگر وارد کنید باید برای همه‌ی ردیف‌ها باشد تا قیمت میانگین محاسبه شود'],
    ], ['label' => 'محاسبه', 'action' => as_action('quota', 'answer')],
    ['repeat' => ['min' => $init, 'max' => $max, 'add_label' => 'افزودن ردیف', 'remove_label' => 'حذف ردیف']]);
}

function as_h_quota(array $ctx): array {
    $step = (string)($ctx['step'] ?? 'start');
    $q = (array)(as_config()['quota'] ?? []);
    $max = max(1, min(200, (int)($q['max_rows'] ?? 50)));

    if ($step === 'start') {
        return as_response([
            blk_text('وزن، گرم (درصد مغزدهی)، انس و در صورت تمایل قیمت هر کیلو را برای هر ردیف وارد کنید؛ ردیف خالی نادیده گرفته می‌شود. ارقام فارسی هم پذیرفته می‌شود.'),
            as_quota_form(),
        ], ['data_note' => null]);
    }
    if ($step !== 'answer') as_bad('درخواست نامعتبر است', 400);

    $rows = $ctx['params']['rows'] ?? null;
    if (!is_array($rows)) as_bad('ردیف‌ها نامعتبر است', 400);
    try {
        $res = quota_calc($rows, $max);
    } catch (InvalidArgumentException $e) {
        as_bad($e->getMessage(), 400); // پیام فارسی برای کاربر؛ کلاینت فرم را روی صفحه نگه می‌دارد
    }

    $hasPrice = $res['avg_price'] !== null;
    $table = [];
    foreach ($res['rows'] as $u) {
        $line = [quota_to_fa((string)$u['index']), quota_fmt_trim($u['weight']), quota_fmt_trim($u['gram']), quota_fmt_trim($u['ounce'])];
        if ($hasPrice) $line[] = quota_fmt_trim($u['price']);
        $table[] = $line;
    }
    $lines = [
        ['label' => 'وزن نهایی (کیلو)', 'value' => quota_fmt($res['final_weight']), 'raw' => $res['final_weight']],
        ['label' => 'میانگین گرم', 'value' => quota_fmt($res['avg_gram']), 'raw' => $res['avg_gram']],
        ['label' => 'میانگین انس', 'value' => quota_fmt($res['avg_ounce']), 'raw' => $res['avg_ounce']],
    ];
    if ($hasPrice) {
        $lines[] = ['label' => 'قیمت میانگین هر کیلو', 'value' => quota_fmt($res['avg_price'], 0), 'raw' => $res['avg_price']];
    }
    $cols = [
        ['key' => 'row', 'label' => 'ردیف'],
        ['key' => 'weight', 'label' => 'وزن (کیلو)', 'align' => 'end'],
        ['key' => 'gram', 'label' => 'گرم', 'align' => 'end'],
        ['key' => 'ounce', 'label' => 'انس', 'align' => 'end'],
    ];
    if ($hasPrice) $cols[] = ['key' => 'price', 'label' => 'قیمت هر کیلو', 'align' => 'end'];
    return as_response([
        blk_card('نتیجه محاسبه', $lines),
        blk_table('ردیف‌های ورودی', $cols, $table),
        blk_buttons([
            ['id' => 'quota', 'label' => 'محاسبه جدید', 'icon' => '🧮', 'action' => as_action('quota', 'start')],
            ['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')],
        ]),
    ], ['data_note' => null]);
}

if (function_exists('as_register')) as_register('quota', 'as_h_quota');
