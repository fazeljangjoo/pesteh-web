<?php
declare(strict_types=1);
require_once __DIR__ . '/plan_upgrade.php'; // S20.T20.2
require_once __DIR__ . '/accounts.php';
/**
 * موتور واحد محاسبهٔ سفارش اشتراک (مرحلهٔ ۲).
 * توابع pay_* خالص‌اند (بدون دیتابیس) تا تست شوند. فقط pay_load_cfg و pay_activate_order به فایل/دیتابیس می‌رسند.
 * قیمت‌ها: همان منطق assets/pb-calc.js. ماه‌ها: ۳=۹۰، ۶=۱۸۰، ۱۲=۳۶۵ روز (مثل دکمه‌های پنل ادمین).
 * تاریخ انقضا = آخرین روز معتبر (مثل app.php: منقضی یعنی expiry < امروز).
 */

const PAY_MONTHS = [3, 6, 12];

function pay_period_days(int $months): int {
    return [3 => 90, 6 => 180, 12 => 365][$months] ?? $months * 30;
}

function pay_parse_amount($v): ?int {
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) return $v > 0 ? (int)round($v) : null;
    $s = preg_replace('/\D+/', '', strtr((string)$v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
    return ($s !== '' && (int)$s > 0) ? (int)$s : null;
}

/** S29.T29.2: unit of subscription prices/orders: 'toman' (default) or 'rial' (config prices x10 + define('PB_PRICE_UNIT','rial')). */
function pay_price_unit(): string {
    return (defined('PB_PRICE_UNIT') && PB_PRICE_UNIT === 'rial') ? 'rial' : 'toman';
}

/** گرد به نزدیک‌ترین ۱۰۰۰ تومان (۱۰٬۰۰۰ ریال) */
function pay_round_k(float $x): int {
    $step = pay_price_unit() === 'rial' ? 10000 : 1000;
    return (int)(floor($x / $step + 0.5) * $step);
}

function pay_today(int $now): string {
    $d = new DateTimeImmutable('@' . $now);
    return $d->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
}

function pay_days_between(string $from, string $to): int {
    $a = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('Asia/Tehran'));
    $b = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('Asia/Tehran'));
    return (int)$a->diff($b)->format('%r%a');
}

function pay_add_days(string $date, int $days): string {
    return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Asia/Tehran')))
        ->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/** سطح اشتراک: green (پیش‌فرض و قدیمی) | gold */
function pay_tier_norm($t): string {
    return $t === 'gold' ? 'gold' : 'green';
}

/**
 * S22.T22.1 — ساختار قیمت (به‌صورت افزایشی):
 *   plans.{rial|fx}            = قیمت سبز (شکل قدیمی؛ بدون تغییر)
 *   tiers.green.{rial|fx}      = اختیاری؛ اگر بود بر plans.* اولویت دارد
 *   tiers.gold.{rial|fx}       = قیمت طلایی: {base, special:{3,6,12}}
 * base=0/null یعنی آن SKU فعال نیست (خرید با price_missing رد می‌شود).
 */
function pay_plan_cfg(array $cfg, string $tier, string $plan): ?array {
    $tier = pay_tier_norm($tier);
    if ($tier === 'gold') {
        $p = $cfg['tiers']['gold'][$plan] ?? null;
    } else {
        $p = $cfg['tiers']['green'][$plan] ?? ($cfg['plans'][$plan] ?? null);
    }
    return is_array($p) ? $p : null;
}

/**
 * پیش‌فرض‌های زمان اجرا برای کلیدهای جاافتاده (فقط در حافظه؛ هرگز فایل config را نمی‌نویسد).
 * SKUهای طلایی بدون قیمت، base=null دارند و خرید آن‌ها رد می‌شود.
 */
function pay_cfg_normalize(array $cfg): array {
    if (!is_array($cfg['plans'] ?? null)) $cfg['plans'] = [];
    if (!is_array($cfg['campaigns'] ?? null)) $cfg['campaigns'] = [];
    if (!is_array($cfg['tiers'] ?? null)) $cfg['tiers'] = [];
    foreach (['gold'] as $t) {
        if (!is_array($cfg['tiers'][$t] ?? null)) $cfg['tiers'][$t] = [];
        foreach (['rial', 'fx'] as $pl) {
            if (!is_array($cfg['tiers'][$t][$pl] ?? null)) $cfg['tiers'][$t][$pl] = ['base' => null, 'special' => []];
            if (!is_array($cfg['tiers'][$t][$pl]['special'] ?? null)) $cfg['tiers'][$t][$pl]['special'] = [];
        }
    }
    return $cfg;
}

function pay_campaign_for(array $cfg, string $plan, int $months, int $now, string $tier = 'green'): ?array {
    $best = null;
    $tier = pay_tier_norm($tier);
    foreach (($cfg['campaigns'] ?? []) as $c) {
        if (!is_array($c) || empty($c['active']) || !((float)($c['percent'] ?? 0) > 0)) continue;
        if (!empty($c['end']) && (int)$c['end'] <= $now) continue;
        if (!empty($c['start']) && (int)$c['start'] > $now) continue;
        // کمپین قدیمی بدون کلید tiers فقط برای سبز بود (طلایی آن موقع وجود نداشت)
        if (!in_array($tier, array_key_exists('tiers', $c) ? (array)$c['tiers'] : ['green'], true)) continue;
        if (!in_array($plan, (array)($c['plans'] ?? []), true)) continue;
        if (!in_array($months, array_map('intval', (array)($c['durations'] ?? [])), true)) continue;
        if ($best === null || $c['percent'] > $best['percent']) $best = $c;
    }
    return $best;
}

/** قیمت یک SKU (سطح × پلن) و مدت: normal → special → کمپین → گرد هزار */
function pay_site_price(array $cfg, string $plan, int $months, int $now, string $tier = 'green'): ?array {
    $p = pay_plan_cfg($cfg, $tier, $plan);
    if (!is_array($p)) return null;
    $base = pay_parse_amount($p['base'] ?? null);
    if (!$base) return null;
    $normal = $base * $months;
    $sp = pay_parse_amount(($p['special'] ?? [])[(string)$months] ?? null);
    $special = ($sp !== null && $sp < $normal) ? $sp : null;
    $after = $special ?? $normal;
    $camp = pay_campaign_for($cfg, $plan, $months, $now, $tier);
    $fin = $camp ? pay_round_k($after * (1 - $camp['percent'] / 100)) : $after;
    if ($fin < (pay_price_unit() === 'rial' ? 10000 : 1000)) $fin = $after;
    return [
        'tier' => pay_tier_norm($tier), 'months' => $months, 'normal' => $normal, 'after_special' => $after, 'final' => $fin,
        'special_discount' => $normal - $after, 'campaign_discount' => $after - $fin,
        'campaign_title' => $camp['title'] ?? null,
    ];
}

/**
 * ارتقا ریالی←ارزی: روزهای باقی‌مانده × (پایهٔ ارزی − پایهٔ ریالی) ÷ ۳۰، فقط یک گرد نهایی به ۱۰۰۰ تومان.
 * مستقل از تخفیف‌ها و مدت؛ تاریخ پایان عوض نمی‌شود.
 */
function pay_upgrade_price(array $cfg, int $remainingDays, string $tier = 'green'): int {
    $r = pay_parse_amount(pay_plan_cfg($cfg, $tier, 'rial')['base'] ?? null) ?? 0;
    $f = pay_parse_amount(pay_plan_cfg($cfg, $tier, 'fx')['base'] ?? null) ?? 0;
    $diff = max(0, $f - $r);
    if ($remainingDays < 1 || $diff < 1) return 0;
    return pay_round_k($remainingDays * $diff / 30);
}

/**
 * S22.T22.3 — قیمت ارتقای سبز → طلایی (فرمول ثابت، قابل تنظیم با قیمت‌های SKU):
 *   روزهای باقی‌مانده × (پایهٔ طلایی − پایهٔ سبز) ÷ ۳۰، یک گرد نهایی به ۱۰۰۰ تومان.
 * اگر یکی از دو پایه تعریف نشده یا اختلاف ≤ ۰ باشد null (خرید رد می‌شود، نه ارتقای رایگان).
 */
function pay_tier_upgrade_price(array $cfg, string $plan, int $remainingDays): ?int {
    $g = pay_parse_amount(pay_plan_cfg($cfg, 'gold', $plan)['base'] ?? null);
    $s = pay_parse_amount(pay_plan_cfg($cfg, 'green', $plan)['base'] ?? null);
    if ($g === null || $s === null || $g <= $s || $remainingDays < 1) return null;
    return pay_round_k($remainingDays * ($g - $s) / 30);
}

/**
 * اثر خرید روی مشتری: پلن جدید، تاریخ پایان جدید و بازه‌ها.
 * $cust: ['plan'=>'rial|fx', 'expiry_date'=>'Y-m-d'|null]
 * خروجی خطا: ['error'=>کد]
 */
function pay_apply(array $cust, string $wantPlan, int $months, string $today, string $tier = 'green'): array {
    if (!in_array($wantPlan, ['rial', 'fx'], true)) return ['error' => 'bad_plan'];
    $tier = pay_tier_norm($tier); // تمدید سطح را عوض نمی‌کند؛ سطح را فقط سفارش/مشتری تعیین می‌کند
    if ($months !== 0 && !in_array($months, PAY_MONTHS, true)) return ['error' => 'bad_months'];
    $exp = !empty($cust['expiry_date']) ? (string)$cust['expiry_date'] : null;
    $curPlan = ($cust['plan'] ?? 'rial') === 'fx' ? 'fx' : 'rial';
    $active = $exp !== null && $exp >= $today;
    $remaining = $active ? pay_days_between($today, $exp) : 0;

    if ($active && $curPlan === 'fx' && $wantPlan === 'rial') return ['error' => 'fx_to_rial_blocked'];
    $upgrade = $active && $curPlan === 'rial' && $wantPlan === 'fx';
    if (!$upgrade && $months < 1) return ['error' => 'months_required'];
    if ($upgrade && $months < 1 && $remaining < 1) return ['error' => 'nothing_to_buy'];

    $periods = [];
    if ($upgrade && $remaining >= 1) {
        $periods[] = ['kind' => 'upgrade', 'plan' => 'fx', 'start' => $today, 'end' => $exp, 'days' => $remaining];
    }
    $newExp = $exp;
    if ($months > 0) {
        $base = $active ? $exp : $today;
        $days = pay_period_days($months);
        $newExp = pay_add_days($base, $days);
        $periods[] = ['kind' => 'renew', 'plan' => $wantPlan, 'start' => $base, 'end' => $newExp, 'days' => $days];
    }
    return [
        'order_type' => $upgrade ? ($months > 0 ? 'upgrade_renew' : 'upgrade') : 'renew',
        'upgrade' => $upgrade, 'remaining_days' => $remaining,
        'tier' => $tier, 'new_plan' => $wantPlan, 'new_expiry' => $newExp, 'periods' => $periods,
    ];
}

function pay_csv(?string $s): array {
    return $s === null || trim($s) === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $s)), 'strlen'));
}

function pay_fail(string $code, string $msg): array { return ['ok' => false, 'error' => $code, 'message' => $msg]; }

/**
 * پیش‌فاکتور کامل.
 * $in: cfg, now, customer{plan,expiry_date}, want_plan, months, vat_percent, discount_enabled,
 *      code (ردیف discount_codes + used_total + used_by_user) یا null
 */
function pay_quote(array $in): array {
    $now = (int)$in['now'];
    $today = pay_today($now);
    $months = (int)($in['months'] ?? 0);
    // سطح از مشتری گرفته می‌شود؛ تمدید سطح را عوض نمی‌کند (تغییر سطح فقط با ارتقا، S22.T22.3)
    $tier = pay_tier_norm($in['customer']['tier'] ?? 'green');
    if (isset($in['tier']) && pay_tier_norm((string)$in['tier']) !== $tier) {
        return pay_fail('tier_change_blocked', 'تغییر سطح فقط از طریق «ارتقا» انجام می‌شود.');
    }
    $tierUp = !empty($in['tier_upgrade']); // S22.T22.3b: خرید حق ارتقا به طلایی (شاخهٔ جدا، بدون مدت)
    $ap = $tierUp ? [] : pay_apply((array)$in['customer'], (string)$in['want_plan'], $months, $today, $tier);
    if (!$tierUp && isset($ap['error'])) {
        $msgs = [
            'bad_plan' => 'پلن نامعتبر است.',
            'bad_months' => 'مدت نامعتبر است.',
            'fx_to_rial_blocked' => 'تا پایان اشتراک ارزی نمی‌توانید پلن ریالی بخرید.',
            'months_required' => 'مدت اشتراک را انتخاب کنید.',
            'nothing_to_buy' => 'امروز آخرین روز اشتراک است؛ برای ارتقا یک مدت ارزی هم انتخاب کنید.',
        ];
        return pay_fail($ap['error'], $msgs[$ap['error']] ?? 'درخواست نامعتبر است.');
    }
    $tierPrice = 0;
    if ($tierUp) {
        // S22.T22.3b — ارتقای سبز → طلایی: فقط برای اشتراک فعال سبز، بدون مدت؛ پلن و تاریخ پایان عوض نمی‌شود
        $cust = (array)$in['customer'];
        $exp = !empty($cust['expiry_date']) ? (string)$cust['expiry_date'] : null;
        if ($tier === 'gold') return pay_fail('already_gold', 'حساب شما هم‌اکنون طلایی است.');
        if ($exp === null || $exp < $today) return pay_fail('not_active', 'ارتقا به طلایی فقط برای اشتراک فعال ممکن است؛ ابتدا اشتراک را تمدید کنید.');
        if ($months !== 0) return pay_fail('months_not_allowed', 'ارتقا به طلایی مدت جداگانه ندارد.');
        $plan = ($cust['plan'] ?? 'rial') === 'fx' ? 'fx' : 'rial';
        $remaining = pay_days_between($today, $exp);
        $tierPrice = pay_tier_upgrade_price($in['cfg'], $plan, $remaining);
        if ($tierPrice === null) return pay_fail('price_missing', 'قیمت ارتقای طلایی برای این پلن تعریف نشده است.');
        $ap = ['order_type' => 'tier_upgrade', 'upgrade' => false, 'remaining_days' => $remaining, 'new_plan' => $plan, 'new_expiry' => $exp, 'periods' => []];
        $up = 0; $renew = null;
    } else {
        $plan = $ap['new_plan'];
        $up = $ap['upgrade'] ? pay_upgrade_price($in['cfg'], $ap['remaining_days'], $tier) : 0;
        // قیمت ارتقای طلایی تعریف نشده = ارتقای رایگان ناخواسته؛ رد می‌شود
        if ($ap['upgrade'] && $ap['remaining_days'] >= 1 && $up < 1 && $tier === 'gold') return pay_fail('price_missing', 'قیمت این پلن تعریف نشده است.');
        $renew = null;
        if ($months > 0) {
            $renew = pay_site_price($in['cfg'], $plan, $months, $now, $tier);
            if ($renew === null) return pay_fail('price_missing', 'قیمت این پلن تعریف نشده است.');
        }
    }
    $subtotal = $tierUp ? $tierPrice : ($up + ($renew['final'] ?? 0));

    $codeDiscount = 0; $codeText = null; $codeId = null; $upgradeFree = false;
    $code = $in['code'] ?? null;
    if ($code !== null) {
        if (empty($in['discount_enabled'])) return pay_fail('codes_off', 'کد تخفیف فعلاً فعال نیست.');
        $codeText = (string)$code['code']; $codeId = (int)($code['id'] ?? 0);
        if (empty($code['active'])) return pay_fail('code_inactive', 'این کد تخفیف فعال نیست.');
        if (!empty($code['expires_at']) && strtotime((string)$code['expires_at']) <= $now) return pay_fail('code_expired', 'مهلت این کد تخفیف تمام شده است.');
        if (($code['max_total'] ?? null) !== null && (int)($code['used_total'] ?? 0) >= (int)$code['max_total']) return pay_fail('code_exhausted', 'ظرفیت این کد تخفیف تمام شده است.');
        if (($code['max_per_user'] ?? null) !== null && (int)($code['used_by_user'] ?? 0) >= (int)$code['max_per_user']) return pay_fail('code_user_limit', 'شما قبلاً از این کد استفاده کرده‌اید.');
        $pl = pay_csv($code['plans'] ?? null); $mo = pay_csv($code['months'] ?? null); $ot = pay_csv($code['order_types'] ?? null);
        $ti = pay_csv($code['tiers'] ?? null); // null = همه‌ی سطوح (رفتار قدیمی)
        if ($ti && !in_array($tier, $ti, true)) return pay_fail('code_not_applicable', 'این کد برای این سطح معتبر نیست.');
        if ($pl && !in_array($plan, $pl, true)) return pay_fail('code_not_applicable', 'این کد برای این پلن معتبر نیست.');
        if ($mo && $months > 0 && !in_array((string)$months, $mo, true)) return pay_fail('code_not_applicable', 'این کد برای این مدت معتبر نیست.');
        if ($ot && !in_array($ap['order_type'], $ot, true)) return pay_fail('code_not_applicable', 'این کد برای این نوع سفارش معتبر نیست.');
        $type = (string)$code['type']; $val = (int)$code['value'];
        if ($type === 'percent') {
            if ($val < 1 || $val > 100) return pay_fail('code_bad', 'کد تخفیف نامعتبر است.');
            $codeDiscount = pay_round_k($subtotal * $val / 100);
        } elseif ($type === 'fixed') {
            if ($val < 1) return pay_fail('code_bad', 'کد تخفیف نامعتبر است.');
            $codeDiscount = $val;
        } elseif ($type === 'free_upgrade') {
            if (!$ap['upgrade'] || $up < 1) return pay_fail('code_not_applicable', 'این کد فقط برای ارتقا به پلن ارزی است.');
            $codeDiscount = $up; $upgradeFree = true;
        } else return pay_fail('code_bad', 'کد تخفیف نامعتبر است.');
        $codeDiscount = max(0, min($codeDiscount, $subtotal));
    }
    $beforeVat = $subtotal - $codeDiscount;
    $vatPct = max(0.0, (float)($in['vat_percent'] ?? 0));
    $vat = (int)round($beforeVat * $vatPct / 100);
    $total = $beforeVat + $vat;

    return [
        'ok' => true, 'today' => $today, 'order_type' => $ap['order_type'], 'tier' => $tierUp ? 'gold' : $tier, 'from_tier' => $tier,
        'tier_upgrade' => $tierUp, 'plan' => $plan, 'months' => $months,
        'upgrade' => $ap['upgrade'], 'remaining_days' => $ap['remaining_days'],
        'upgrade_amount' => $up, 'upgrade_free' => $upgradeFree,
        'renew_normal' => $renew['normal'] ?? 0, 'renew_final' => $renew['final'] ?? 0,
        'special_discount' => $renew['special_discount'] ?? 0, 'campaign_discount' => $renew['campaign_discount'] ?? 0,
        'campaign_title' => $renew['campaign_title'] ?? null,
        'subtotal' => $subtotal, 'code_id' => $codeId, 'code_text' => $codeText, 'code_discount' => $codeDiscount,
        'vat_percent' => $vatPct, 'vat_amount' => $vat, 'total' => $total,
        'new_plan' => $plan, 'new_expiry' => $ap['new_expiry'], 'periods' => $ap['periods'],
    ];
}

/** تنظیمات قیمت سایت (همان data/config.php؛ خط اول فقط محافظ است) */
function pay_load_cfg(): array {
    $f = dirname(__DIR__, 2) . '/data/config.php';
    $t = is_file($f) ? (string)file_get_contents($f) : '';
    $i = strpos($t, '{');
    $j = $i === false ? null : json_decode(substr($t, $i), true);
    return pay_cfg_normalize(is_array($j) ? $j : []);
}

/**
 * فعال‌سازی سفارش پرداخت‌شده — فقط یک‌بار (idempotent) و داخل تراکنش با قفل ردیف.
 * بعد از verify زرین‌پال یا تأیید ادمین صدا زده می‌شود. تاریخ‌ها از وضعیت «همین الان» مشتری حساب می‌شوند،
 * قیمت همان است که کاربر پرداخته. خروجی: ['ok'=>true,'already'=>bool,'new_expiry'=>..] یا ['ok'=>false,'error'=>..]
 */
function pay_activate_order(PDO $pdo, int $orderId, string $method = 'gateway'): array {
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM pay_orders WHERE id = ? FOR UPDATE');
        $s->execute([$orderId]);
        $o = $s->fetch();
        if (!$o) { $pdo->rollBack(); return ['ok' => false, 'error' => 'order_not_found']; }
        if ($o['status'] === 'paid') { $pdo->commit(); return ['ok' => true, 'already' => true]; }
        if (in_array($o['status'], ['rejected', 'canceled'], true)) { $pdo->rollBack(); return ['ok' => false, 'error' => 'order_closed']; }

        $c = $pdo->prepare('SELECT id, plan, expiry_date, tier FROM customers WHERE id = ? FOR UPDATE');
        $c->execute([$o['customer_id']]);
        $cust = $c->fetch();
        if (!$cust) { $pdo->rollBack(); return ['ok' => false, 'error' => 'customer_not_found']; }

        // S22.T22.3b: خرید «حق ارتقا به طلایی» فقط حق را ثبت می‌کند و سطح را عوض نمی‌کند؛
        // تغییر سطح و انتقال داده‌ها در ویزارد T20 (tier.upgrade_apply) انجام می‌شود و آن هم به همین حق نیاز دارد.
        if (($o['order_type'] ?? '') === 'tier_upgrade') {
            $pdo->prepare("UPDATE pay_orders SET status = 'paid', method = ?, activated_at = NOW() WHERE id = ?")
                ->execute([$method, $o['id']]);
            $pdo->commit();
            return ['ok' => true, 'already' => false, 'new_tier' => pay_tier_norm($cust['tier'] ?? 'green'), 'entitles' => 'gold'];
        }

        // سطح: سفارش همان سطحی را فعال می‌کند که با آن فروخته شده؛ هرگز پایین‌آوردن سطح (طلایی → سبز) نیست (Q3)
        $orderTier = pay_tier_norm($o['tier'] ?? 'green');
        $newTier = (pay_tier_norm($cust['tier'] ?? 'green') === 'gold' || $orderTier === 'gold') ? 'gold' : 'green';

        $ap = pay_apply($cust, (string)$o['plan'], (int)$o['months'], pay_today(time()), $orderTier);
        if (isset($ap['error'])) { $pdo->rollBack(); return ['ok' => false, 'error' => $ap['error']]; }

        // STAGE11.2c: ظرفیت کد تخفیف هنگام فعال‌سازی (پشتوانهٔ رقابت هم‌زمان؛ قفل ردیف کد، فعال‌سازی‌های هم‌زمان را پشت‌سرهم می‌کند).
        // فقط استفاده‌های قطعی شمرده می‌شود و خود این سفارش مستثناست. شکست ⇒ سفارش بسته نمی‌شود؛ درگاه: awaiting_review، ادمین: پیام.
        if (!empty($o['code_id'])) {
            $cs = $pdo->prepare('SELECT max_total FROM discount_codes WHERE id = ? FOR UPDATE');
            $cs->execute([$o['code_id']]);
            $mt = $cs->fetchColumn();
            if ($mt !== false && $mt !== null) {
                $cu = $pdo->prepare('SELECT COUNT(*) FROM discount_redemptions WHERE code_id = ? AND order_id <> ?');
                $cu->execute([$o['code_id'], $o['id']]);
                if ((int)$cu->fetchColumn() >= (int)$mt) { $pdo->rollBack(); return ['ok' => false, 'error' => 'code_exhausted']; }
            }
        }

        foreach ($ap['periods'] as $p) {
            $pdo->prepare('INSERT IGNORE INTO subscription_periods (customer_id, order_id, plan, kind, start_date, end_date, days) VALUES (?,?,?,?,?,?,?)')
                ->execute([$o['customer_id'], $o['id'], $p['plan'], $p['kind'], $p['start'], $p['end'], $p['days']]);
        }
        $pdo->prepare('UPDATE customers SET plan = ?, expiry_date = ?, active = 1, tier = ? WHERE id = ?')
            ->execute([$ap['new_plan'], $ap['new_expiry'], $newTier, $o['customer_id']]);
        if (!empty($o['code_id'])) {
            $pdo->prepare('INSERT IGNORE INTO discount_redemptions (code_id, customer_id, order_id) VALUES (?,?,?)')
                ->execute([$o['code_id'], $o['customer_id'], $o['id']]);
        }
        $pdo->prepare("UPDATE pay_orders SET status = 'paid', method = ?, activated_at = NOW() WHERE id = ?")
            ->execute([$method, $o['id']]);
        $pdo->commit();
        if ($ap['new_plan'] === 'fx' && function_exists('pb_plan_upgrade_fx')) pb_plan_upgrade_fx($pdo, (int)$o['customer_id']); // S20.T20.2 (DDL: after commit)
        return ['ok' => true, 'already' => false, 'new_expiry' => $ap['new_expiry'], 'new_plan' => $ap['new_plan'], 'new_tier' => $newTier];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('pay_activate_order: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'server_error'];
    }
}
