<?php
declare(strict_types=1);
/**
 * هستهٔ پرداخت سمت API (مرحلهٔ ۴): ساخت سفارش، شروع درگاه، نهایی‌سازی پرداخت درگاه، کارت‌به‌کارت، قرارداد، فاکتور.
 * بین pay.php (API اپ‌ها) و pay_callback.php مشترک است. پیش‌نیاز: pay_engine.php و pay_zarinpal.php.
 * همهٔ مبلغ‌ها تومان؛ قیمت فقط از سرور محاسبه می‌شود.
 */

if (!function_exists('pay_fa')) {
    function pay_fa(string $s): string {
        return strtr($s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }
}
function payc_en(string $s): string {
    return strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
}
function payc_money($n): string { return pay_fa(number_format((int)$n)); }

/** میلادی ← شمسی (همان الگوریتم پنل ادمین) */
if (!function_exists('pay_g2j')) {
    function pay_g2j(int $gy, int $gm, int $gd): array {
        $gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
        else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
        return [$jy, $jm, $jd];
    }
}
if (!function_exists('pay_jdate')) {
    function pay_jdate(?string $dt, bool $time = false): string {
        if (!$dt || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $dt, $m)) return '—';
        [$jy, $jm, $jd] = pay_g2j((int)$m[1], (int)$m[2], (int)$m[3]);
        $s = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        if ($time && isset($m[4])) $s .= ' ' . $m[4] . ':' . $m[5];
        return pay_fa($s);
    }
}
/** شمسی ← میلادی */
function payc_j2g(int $jy, int $jm, int $jd): array {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $days--;
        $gy += 100 * intdiv($days, 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) { $gy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    $gd = $days + 1;
    $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0));
    $sal = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 1; $gm < 13 && $gd > $sal[$gm]; $gm++) $gd -= $sal[$gm];
    return [$gy, $gm, $gd];
}
/** زمان پرداخت کاربر (میلادی یا شمسی، ارقام فارسی هم قبول) ← 'Y-m-d H:i:00' به وقت ایران، یا null */
function payc_parse_paid_at(string $s): ?string {
    $s = trim(payc_en($s));
    if (!preg_match('~^(\d{4})[-/](\d{1,2})[-/](\d{1,2})[ T]+(\d{1,2}):(\d{2})~', $s, $m)) return null;
    [$y, $mo, $d, $h, $mi] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5]];
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $h > 23 || $mi > 59) return null;
    if ($y < 1700) {
        if ($y < 1300 || $d > ($mo <= 6 ? 31 : 30)) return null;
        [$y, $mo, $d] = payc_j2g($y, $mo, $d);
    }
    if (!checkdate($mo, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $mo, $d, $h, $mi);
}

// ---------------------------------------------------------------- تنظیمات و مشتری

function payc_settings(PDO $pdo): array {
    $d = ['vat_percent' => '0', 'discount_enabled' => '1', 'gateway_enabled' => '1', 'card_enabled' => '1', 'sheba_enabled' => '1'];
    foreach ($pdo->query('SELECT k, v FROM pay_settings')->fetchAll() as $r) $d[(string)$r['k']] = (string)$r['v'];
    return $d;
}

function payc_banks(PDO $pdo): array {
    $rows = $pdo->query('SELECT id, bank_name, card_number, sheba, owner_name, note FROM bank_accounts WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'bank_name' => (string)$r['bank_name'], 'card_number' => (string)($r['card_number'] ?? ''),
            'sheba' => (string)($r['sheba'] ?? ''), 'owner_name' => (string)$r['owner_name'], 'note' => (string)($r['note'] ?? '')];
    }
    return $out;
}

/** وضعیت مشتری برای نمایش در UI */
function payc_account(array $c): array {
    $exp = !empty($c['expiry_date']) ? (string)$c['expiry_date'] : null;
    $today = pay_today(time());
    $active = $exp !== null && $exp >= $today && !empty($c['active']);
    return [
        'plan' => ($c['plan'] ?? 'rial') === 'fx' ? 'fx' : 'rial',
        'tier' => pay_tier_norm($c['tier'] ?? 'green'),
        'expiry_date' => $exp, 'expiry_jalali' => $exp ? pay_jdate($exp) : '—',
        'active' => $active,
        'days_left' => $exp !== null ? max(0, pay_days_between($today, $exp)) : 0,
        'expired' => $exp === null || $exp < $today,
    ];
}

// ---------------------------------------------------------------- کد تخفیف و پیش‌فاکتور

function payc_find_code(PDO $pdo, string $text, int $cid): ?array {
    $text = trim(payc_en($text));
    if ($text === '' || mb_strlen($text) > 40) return null;
    $st = $pdo->prepare('SELECT * FROM discount_codes WHERE UPPER(code) = UPPER(?) LIMIT 1');
    $st->execute([$text]);
    $c = $st->fetch();
    if (!$c) return null;
    // مصرف = استفاده‌های قطعی + سفارش‌های در انتظار بررسی + سفارش‌های درگاهیِ pending «دیگر مشتری‌ها» در یک ساعت اخیر (تا با چند سفارش همزمان از سقف رد نشود).
    // STAGE11.2c: pending مشتری خودش شمرده نمی‌شود چون با ساخت سفارش جدید لغو می‌شود (وگرنه پس از رها کردن درگاه، با max_per_user=1 دیگر نمی‌توانست دوباره تلاش کند)؛
    // pending قدیمی‌تر از یک ساعت رهاشده فرض می‌شود تا سبد رهاشده سقف را برای همیشه اشغال نکند.
    $q = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM discount_redemptions WHERE code_id = :c1)
          + (SELECT COUNT(*) FROM pay_orders WHERE code_id = :c2 AND (status = 'awaiting_review' OR (status = 'pending' AND customer_id <> :cx AND created_at > (NOW() - INTERVAL 1 HOUR)))) AS t,
        (SELECT COUNT(*) FROM discount_redemptions WHERE code_id = :c3 AND customer_id = :u1) + (SELECT COUNT(*) FROM pay_orders WHERE code_id = :c4 AND customer_id = :u2 AND status = 'awaiting_review') AS u");
    $q->execute([':c1' => $c['id'], ':c2' => $c['id'], ':cx' => $cid, ':c3' => $c['id'], ':u1' => $cid, ':c4' => $c['id'], ':u2' => $cid]);
    $n = $q->fetch();
    $c['used_total'] = (int)$n['t'];
    $c['used_by_user'] = (int)$n['u'];
    return $c;
}

/** پیش‌فاکتور با کد اختیاری. خروجی مثل pay_quote (+ 'summary' برای نمایش) */
function payc_quote(PDO $pdo, array $cust, string $plan, int $months, string $codeText = '', bool $tierUpgrade = false): array {
    $s = payc_settings($pdo);
    $code = null;
    $codeText = trim($codeText);
    if ($codeText !== '') {
        $cid = (int)$cust['id'];
        throttle_check('paycode:' . $cid, 12, 900);
        $code = payc_find_code($pdo, $codeText, $cid);
        if ($code === null) {
            throttle_fail('paycode:' . $cid, 900);
            return pay_fail('code_not_found', 'کد تخفیف پیدا نشد.');
        }
    }
    $q = pay_quote([
        'cfg' => pay_load_cfg(), 'now' => time(),
        'customer' => ['plan' => ($cust['plan'] ?? 'rial'), 'expiry_date' => $cust['expiry_date'] ?? null, 'tier' => pay_tier_norm($cust['tier'] ?? 'green')],
        'want_plan' => $plan, 'months' => $months,
        'vat_percent' => (float)$s['vat_percent'], 'discount_enabled' => $s['discount_enabled'] === '1',
        'code' => $code, 'tier_upgrade' => $tierUpgrade,
    ]);
    if (!empty($q['ok'])) $q['summary'] = payc_summary($q, $cust);
    return $q;
}

/** خلاصهٔ آمادهٔ نمایش (یکسان در وب، اندروید و ویندوز) */
function payc_summary(array $q, array $cust): array {
    $planName = ($q['tier'] ?? 'green') === 'gold' ? 'طلایی ' : ''; // سبز بدون برچسب (مثل قبل)
    $planName .= $q['plan'] === 'fx' ? 'ارزی' : 'ریالی';
    $lines = [];
    if (!empty($q['tier_upgrade'])) { // S22.T22.3b
        $lines[] = ['kind' => 'item', 'label' => 'ارتقا به طلایی (' . pay_fa((string)$q['remaining_days']) . ' روز باقی‌مانده)', 'amount' => $q['subtotal']];
    }
    if ($q['upgrade_amount'] > 0) {
        $lines[] = ['kind' => 'item', 'label' => 'ارتقا به ارزی (' . pay_fa((string)$q['remaining_days']) . ' روز باقی‌مانده)', 'amount' => $q['upgrade_amount']];
    }
    if ($q['months'] > 0) {
        $lines[] = ['kind' => 'item', 'label' => 'اشتراک ' . $planName . ' ' . pay_fa((string)$q['months']) . ' ماهه', 'amount' => $q['renew_normal']];
        if ($q['special_discount'] > 0) $lines[] = ['kind' => 'discount', 'label' => 'تخفیف ویژهٔ پلن', 'amount' => -$q['special_discount']];
        if ($q['campaign_discount'] > 0) $lines[] = ['kind' => 'discount', 'label' => 'تخفیف مناسبتی' . ($q['campaign_title'] ? ' «' . $q['campaign_title'] . '»' : ''), 'amount' => -$q['campaign_discount']];
    }
    if ($q['code_discount'] > 0) $lines[] = ['kind' => 'discount', 'label' => 'کد تخفیف ' . $q['code_text'], 'amount' => -$q['code_discount']];
    if ($q['vat_amount'] > 0) $lines[] = ['kind' => 'vat', 'label' => 'مالیات بر ارزش افزوده (' . pay_fa((string)$q['vat_percent']) . '٪)', 'amount' => $q['vat_amount']];
    $lines[] = ['kind' => 'total', 'label' => 'مبلغ قابل پرداخت', 'amount' => $q['total']];

    $periods = [];
    foreach ($q['periods'] as $p) {
        $periods[] = ['kind' => $p['kind'], 'plan' => $p['plan'], 'start' => $p['start'], 'end' => $p['end'], 'days' => $p['days'],
            'label' => ($p['kind'] === 'upgrade' ? 'ارتقا به ارزی' : 'اشتراک ' . (($q['tier'] ?? 'green') === 'gold' ? 'طلایی ' : '') . ($p['plan'] === 'fx' ? 'ارزی' : 'ریالی'))
                . ': از ' . pay_jdate($p['start']) . ' تا ' . pay_jdate($p['end']) . ' (' . pay_fa((string)$p['days']) . ' روز)'];
    }
    $notes = [];
    $acc = payc_account($cust);
    if (!empty($q['tier_upgrade'])) {
        $notes[] = 'سطح حساب شما از سبز به طلایی ارتقا می‌یابد؛ پلن و تاریخ پایان اشتراک فعلی تغییری نمی‌کند. پس از پرداخت، حق ارتقا برای حساب شما ثبت می‌شود و انتقال داده‌ها از «ویزارد ارتقا» در برنامه انجام می‌شود.';
    }
    if ($q['upgrade']) {
        $notes[] = 'پلن فعلی شما تا ' . pay_jdate((string)$cust['expiry_date']) . ' ادامه دارد و ' . pay_fa((string)$q['remaining_days'])
            . ' روز باقی‌ماندهٔ آن به ارزی ارتقا می‌یابد؛ تاریخ پایان اشتراک فعلی تغییر نمی‌کند.';
    }
    if ($q['months'] > 0 && !$acc['expired'] && $q['plan'] === $acc['plan']) {
        $notes[] = 'مدت جدید بعد از پایان اشتراک فعلی شما (' . pay_jdate((string)$cust['expiry_date']) . ') اضافه می‌شود.';
    }
    $notes[] = 'تاریخ پایان جدید اشتراک: ' . pay_jdate((string)$q['new_expiry']) . '.';
    $notes[] = 'استرداد وجه به هیچ عنوان وجود ندارد؛ لطفاً قبل از پرداخت خلاصهٔ سفارش را با دقت بخوانید.';
    return ['lines' => $lines, 'periods' => $periods, 'notes' => $notes, 'new_expiry_jalali' => pay_jdate((string)$q['new_expiry'])];
}

// ---------------------------------------------------------------- سفارش

function payc_order_by_public(PDO $pdo, int $cid, string $pid): ?array {
    if (!preg_match('/^[A-Za-z0-9]{6,20}$/', $pid)) return null;
    $st = $pdo->prepare('SELECT * FROM pay_orders WHERE public_id = ? AND customer_id = ? LIMIT 1');
    $st->execute([$pid, $cid]);
    $o = $st->fetch();
    return $o ?: null;
}

/** شکل عمومی سفارش برای اپ‌ها (بدون دادهٔ داخلی) */
function payc_order_view(array $o): array {
    $snap = json_decode((string)($o['snapshot'] ?? ''), true);
    $snap = is_array($snap) ? $snap : [];
    return [
        'id' => (string)$o['public_id'], 'status' => (string)$o['status'], 'method' => $o['method'] !== null ? (string)$o['method'] : null,
        'order_type' => (string)$o['order_type'], 'plan' => (string)$o['plan'], 'tier' => pay_tier_norm($o['tier'] ?? 'green'), 'months' => (int)$o['months'],
        'upgrade_amount' => (int)$o['upgrade_amount'], 'renew_normal' => (int)$o['renew_normal'], 'site_discount' => (int)$o['site_discount'],
        'code_text' => $o['code_text'], 'code_discount' => (int)$o['code_discount'],
        'vat_percent' => (float)$o['vat_percent'], 'vat_amount' => (int)$o['vat_amount'], 'total' => (int)$o['total'],
        'reject_reason' => $o['reject_reason'], 'created_at' => (string)$o['created_at'], 'created_jalali' => pay_jdate((string)$o['created_at'], true),
        'activated_at' => $o['activated_at'], 'activated_jalali' => $o['activated_at'] ? pay_jdate((string)$o['activated_at'], true) : null,
        'new_expiry' => $snap['new_expiry'] ?? null, 'new_expiry_jalali' => !empty($snap['new_expiry']) ? pay_jdate((string)$snap['new_expiry']) : null,
        'summary' => $snap['summary'] ?? null,
    ];
}

function payc_new_public_id(): string {
    return 'PB' . date('ymd') . strtoupper(bin2hex(random_bytes(6)));
}

/**
 * ساخت سفارش (idempotent با idem). قیمت از سرور؛ سفارش‌های pending قبلیِ همین کاربر لغو می‌شوند.
 * سفارش با مبلغ صفر (مثلاً ارتقای رایگان با کد) بلافاصله فعال می‌شود.
 * خروجی: ['ok'=>true,'order'=>row,'replay'=>bool] | pay_fail(...)
 */
function payc_create_order(PDO $pdo, array $cust, string $plan, int $months, string $codeText, string $method, string $idem, bool $tierUpgrade = false): array {
    $cid = (int)$cust['id'];
    $ex = $pdo->prepare('SELECT * FROM pay_orders WHERE customer_id = ? AND idem = ? LIMIT 1');
    $ex->execute([$cid, $idem]);
    if ($row = $ex->fetch()) return ['ok' => true, 'order' => $row, 'replay' => true];

    throttle_check('payorder:' . $cid, 30, 900);
    $q = payc_quote($pdo, $cust, $plan, $months, $codeText, $tierUpgrade);
    if (empty($q['ok'])) return $q;

    $s = payc_settings($pdo);
    $total = (int)$q['total'];
    if ($total === 0) {
        $method = 'free';
    } else {
        if (!in_array($method, ['gateway', 'card', 'sheba'], true)) return pay_fail('bad_method', 'روش پرداخت نامعتبر است.');
        if ($method === 'gateway' && ($s['gateway_enabled'] !== '1' || !zp_configured())) return pay_fail('method_off', 'درگاه پرداخت فعلاً در دسترس نیست. از کارت‌به‌کارت یا شبا استفاده کنید.');
        if ($method === 'card' || $method === 'sheba') {
            if ($s[$method . '_enabled'] !== '1') return pay_fail('method_off', 'این روش پرداخت فعلاً فعال نیست.');
            $has = false;
            foreach (payc_banks($pdo) as $b) if (($method === 'card' ? $b['card_number'] : $b['sheba']) !== '') { $has = true; break; }
            if (!$has) return pay_fail('method_off', 'اطلاعات حساب برای این روش هنوز ثبت نشده است.');
        }
    }
    throttle_fail('payorder:' . $cid, 900); // هر ساخت سفارش یک امتیاز؛ سقف ۳۰ در ۱۵ دقیقه

    $snapshot = $q;
    $pid = payc_new_public_id();
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE pay_orders SET status = 'canceled' WHERE customer_id = ? AND status = 'pending'")->execute([$cid]);
        // tier: ستون جدید (migration v43). سطح سفارش همان سطح مشتری در لحظهٔ خرید است.
        $pdo->prepare('INSERT INTO pay_orders (public_id, customer_id, idem, order_type, plan, tier, months, status, method, upgrade_amount, renew_normal, site_discount,
                code_id, code_text, code_discount, vat_percent, vat_amount, total, snapshot)
            VALUES (?,?,?,?,?,?,?,\'pending\',?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$pid, $cid, $idem, $q['order_type'], $q['plan'], $q['tier'], $q['months'], $method, $q['upgrade_amount'], $q['renew_normal'],
                $q['special_discount'] + $q['campaign_discount'], $q['code_id'] ?: null, $q['code_text'], $q['code_discount'],
                $q['vat_percent'], $q['vat_amount'], $total, json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string)$e->getCode() === '23000') { // دوبار زدن همزمان: همان سفارش اول
            $ex->execute([$cid, $idem]);
            if ($row = $ex->fetch()) return ['ok' => true, 'order' => $row, 'replay' => true];
        }
        error_log('payc_create_order: ' . $e->getMessage());
        return pay_fail('server_error', 'خطای سرور؛ دوباره تلاش کنید.');
    }

    if ($total === 0) {
        $r = pay_activate_order($pdo, $id, 'free');
        if (empty($r['ok'])) {
            error_log('payc_create_order free activate: ' . ($r['error'] ?? '?'));
            $pdo->prepare("UPDATE pay_orders SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$id]);
            return pay_fail((string)($r['error'] ?? 'server_error'), 'فعال‌سازی انجام نشد؛ دوباره تلاش کنید.');
        }
    }
    $st = $pdo->prepare('SELECT * FROM pay_orders WHERE id = ?');
    $st->execute([$id]);
    return ['ok' => true, 'order' => $st->fetch(), 'replay' => false];
}

// ---------------------------------------------------------------- درگاه

/** شروع پرداخت درگاه برای سفارش pending. خروجی: ['ok'=>true,'url'=>..] یا pay_fail */
function payc_gateway_start(PDO $pdo, array $cust, array $o): array {
    $s = payc_settings($pdo);
    if ($s['gateway_enabled'] !== '1' || !zp_configured()) return pay_fail('method_off', 'درگاه پرداخت فعلاً در دسترس نیست.');
    if ($o['status'] !== 'pending') return pay_fail('order_not_pending', 'این سفارش دیگر در انتظار پرداخت نیست.');
    $total = (int)$o['total'];
    if ($total < 1000) return pay_fail('bad_amount', 'مبلغ سفارش برای پرداخت اینترنتی معتبر نیست.');
    $age = $pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) FROM pay_orders WHERE id = ?');
    $age->execute([$o['id']]);
    if ((int)$age->fetchColumn() > 360) return pay_fail('order_stale', 'این سفارش قدیمی شده است؛ سفارش جدید بسازید.');

    // دوبار زدن دکمه: اگر تا ۱۰ دقیقهٔ اخیر درخواست پرداختِ بازی دارد همان را برمی‌گردانیم
    $re = $pdo->prepare("SELECT authority FROM pay_transactions WHERE order_id = ? AND status = 'started' AND created_at > (NOW() - INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1");
    $re->execute([$o['id']]);
    if ($auth = $re->fetchColumn()) return ['ok' => true, 'url' => zp_go_url((string)$auth), 'reused' => true];

    $plan = $o['plan'] === 'fx' ? 'ارزی' : 'ریالی';
    $desc = 'اشتراک پسته بازار — ' . ($o['order_type'] === 'upgrade' ? 'ارتقا به ارزی' : $plan . ' ' . (int)$o['months'] . ' ماهه') . ' — سفارش ' . $o['public_id'];
    $mobile = preg_match('/^98(9\d{9})$/', (string)$cust['phone'], $m) ? '0' . $m[1] : '';
    $r = zp_request($total, $desc, $mobile, (string)$o['public_id']);
    if (empty($r['ok'])) {
        if (empty($r['net'])) return pay_fail('gateway_unreachable', 'ارتباط با درگاه برقرار نشد؛ چند لحظه بعد دوباره تلاش کنید.');
        return pay_fail('gateway_error', 'درگاه درخواست را نپذیرفت' . (isset($r['code']) ? ' (کد ' . $r['code'] . ')' : '') . '. می‌توانید کارت‌به‌کارت را امتحان کنید.');
    }
    try {
        $pdo->prepare("INSERT INTO pay_transactions (order_id, gateway, authority, amount_gateway, status) VALUES (?, 'zarinpal', ?, ?, 'started')")
            ->execute([$o['id'], $r['authority'], $r['amount_gateway']]);
        $pdo->prepare("UPDATE pay_orders SET method = 'gateway' WHERE id = ? AND status = 'pending'")->execute([$o['id']]);
    } catch (Throwable $e) {
        error_log('payc_gateway_start insert: ' . $e->getMessage());
        return pay_fail('server_error', 'خطای سرور؛ دوباره تلاش کنید.');
    }
    return ['ok' => true, 'url' => $r['url'], 'reused' => false];
}

/**
 * نهایی‌سازی پرداخت درگاه: verify الزامی + فعال‌سازی یک‌باره. برای callback و «بررسی پرداخت» مشترک است.
 * $tx: ردیف pay_transactions. $claimedOk=false یعنی زرین‌پال خودش «ناموفق/انصراف» گفته (بدون verify).
 * خروجی result: paid | review | failed | pending | error
 *   paid   = فعال شد (یا قبلاً فعال شده بود)
 *   review = پول گرفته شد ولی فعال‌سازی خودکار نشد؛ سفارش به بررسی ادمین رفت
 *   failed = پرداخت انجام نشده/انصراف
 *   pending= verify نامعلوم (خطای شبکه) — بعداً با «بررسی پرداخت» دوباره
 */
function payc_gateway_finalize(PDO $pdo, array $tx, bool $claimedOk): array {
    $oid = (int)$tx['order_id'];
    if (!$claimedOk) {
        $pdo->prepare("UPDATE pay_transactions SET status = 'failed' WHERE id = ? AND status = 'started'")->execute([$tx['id']]);
        return ['result' => 'failed', 'order_id' => $oid];
    }
    if ($tx['status'] !== 'paid') {
        $v = zp_verify((string)$tx['authority'], (int)$tx['amount_gateway']);
        if ($v['state'] === 'unknown') return ['result' => 'pending', 'order_id' => $oid];
        if ($v['state'] === 'failed') {
            $pdo->prepare("UPDATE pay_transactions SET status = 'failed' WHERE id = ? AND status <> 'paid'")->execute([$tx['id']]);
            return ['result' => 'failed', 'order_id' => $oid];
        }
        $pdo->prepare("UPDATE pay_transactions SET status = 'paid', ref_id = ?, card_pan = ?, verified_at = COALESCE(verified_at, NOW()) WHERE id = ?")
            ->execute([$v['ref_id'], $v['card_pan'] !== '' ? $v['card_pan'] : null, $tx['id']]);
        $tx['ref_id'] = $v['ref_id'];
    }
    // سفارشی که با ساخت سفارش جدید لغو شده ولی پولش رسیده، دوباره باز می‌شود
    $pdo->prepare("UPDATE pay_orders SET status = 'pending' WHERE id = ? AND status = 'canceled'")->execute([$oid]);
    $r = pay_activate_order($pdo, $oid, 'gateway');
    if (empty($r['ok']) && ($r['error'] ?? '') === 'server_error') { usleep(300000); $r = pay_activate_order($pdo, $oid, 'gateway'); }
    if (!empty($r['ok'])) return ['result' => 'paid', 'order_id' => $oid, 'already' => !empty($r['already'])];

    $note = mb_substr('پرداخت درگاه موفق (ref ' . ($tx['ref_id'] ?? '?') . ') ولی فعال‌سازی خودکار نشد: ' . ($r['error'] ?? '?') . ' — بررسی دستی/بازگشت وجه', 0, 255);
    $pdo->prepare("UPDATE pay_orders SET status = 'awaiting_review', method = 'gateway', admin_note = ? WHERE id = ? AND status IN ('pending','canceled')")->execute([$note, $oid]);
    error_log('payc_gateway_finalize: paid but not activated order=' . $oid . ' err=' . ($r['error'] ?? '?'));
    return ['result' => 'review', 'order_id' => $oid];
}

/** «بررسی پرداخت»: برای سفارش pending، تراکنش‌های اخیر را verify می‌کند (اگر کاربر پرداخت کرده ولی callback نرسیده) */
function payc_reconcile(PDO $pdo, array $o): array {
    if (!in_array($o['status'], ['pending', 'canceled'], true) || (string)$o['method'] !== 'gateway') return ['result' => 'none'];
    $st = $pdo->prepare("SELECT * FROM pay_transactions WHERE order_id = ? AND status IN ('started','failed') AND created_at > (NOW() - INTERVAL 3 DAY) ORDER BY id DESC LIMIT 3");
    $st->execute([$o['id']]);
    $last = ['result' => 'none'];
    foreach ($st->fetchAll() as $tx) {
        $r = payc_gateway_finalize($pdo, $tx, true);
        if (in_array($r['result'], ['paid', 'review'], true)) return $r;
        if ($r['result'] === 'pending') $last = $r;
        elseif ($last['result'] === 'none') $last = $r;
    }
    return $last;
}

// ---------------------------------------------------------------- کارت‌به‌کارت / شبا

/**
 * ثبت اطلاعات پرداخت دستی. سفارش از pending به awaiting_review می‌رود (فقط یک‌بار).
 * خروجی: ['ok'=>true] یا pay_fail
 */
function payc_submit_manual(PDO $pdo, array $o, string $method, array $in): array {
    if (!in_array($method, ['card', 'sheba'], true)) return pay_fail('bad_method', 'روش پرداخت نامعتبر است.');
    $s = payc_settings($pdo);
    if ($s[$method . '_enabled'] !== '1') return pay_fail('method_off', 'این روش پرداخت فعلاً فعال نیست.');
    if ($o['status'] === 'awaiting_review') return pay_fail('already_submitted', 'اطلاعات پرداخت شما قبلاً ثبت شده و در انتظار بررسی است.');
    if ($o['status'] !== 'pending') return pay_fail('order_not_pending', 'این سفارش دیگر در انتظار پرداخت نیست.');
    if ((int)$o['total'] < 1) return pay_fail('bad_amount', 'این سفارش پرداخت ندارد.');

    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['payer_name'] ?? '')) ?? '');
    if (mb_strlen($name) < 3 || mb_strlen($name) > 100) return pay_fail('bad_payer', 'نام پرداخت‌کننده را کامل وارد کنید.');
    $last4 = preg_replace('/\D+/', '', payc_en((string)($in['card_last4'] ?? ''))) ?? '';
    if (strlen($last4) !== 4) return pay_fail('bad_card', '۴ رقم آخر کارت پرداخت‌کننده را وارد کنید.');
    $paidAt = payc_parse_paid_at((string)($in['paid_at'] ?? ''));
    if ($paidAt === null) return pay_fail('bad_paid_at', 'تاریخ و ساعت پرداخت را درست وارد کنید.');
    $ts = strtotime($paidAt);
    if ($ts > time() + 600) return pay_fail('bad_paid_at', 'زمان پرداخت نمی‌تواند در آینده باشد.');
    if ($ts < time() - 30 * 86400) return pay_fail('bad_paid_at', 'زمان پرداخت خیلی قدیمی است؛ با پشتیبانی تماس بگیرید.');
    $amount = pay_parse_amount($in['amount'] ?? null);
    if ($amount === null) return pay_fail('bad_amount', 'مبلغ پرداخت‌شده را وارد کنید.');
    $track = trim(payc_en((string)($in['tracking_no'] ?? '')));
    if (mb_strlen($track) > 40) return pay_fail('bad_tracking', 'شمارهٔ پیگیری خیلی طولانی است.');

    try {
        $pdo->beginTransaction();
        $u = $pdo->prepare("UPDATE pay_orders SET status = 'awaiting_review', method = ? WHERE id = ? AND status = 'pending'");
        $u->execute([$method, $o['id']]);
        if ($u->rowCount() < 1) { $pdo->rollBack(); return pay_fail('already_submitted', 'اطلاعات پرداخت شما قبلاً ثبت شده است.'); }
        $pdo->prepare('INSERT INTO pay_manual (order_id, payer_name, card_last4, paid_at, amount, tracking_no) VALUES (?,?,?,?,?,?)')
            ->execute([$o['id'], $name, $last4, $paidAt, $amount, $track !== '' ? $track : null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('payc_submit_manual: ' . $e->getMessage());
        return pay_fail('server_error', 'خطای سرور؛ دوباره تلاش کنید.');
    }
    return ['ok' => true, 'amount_mismatch' => $amount !== (int)$o['total']];
}

// ---------------------------------------------------------------- قرارداد

function payc_contract_latest(PDO $pdo): ?array {
    $r = $pdo->query('SELECT version, title, body, created_at FROM contract_versions WHERE published = 1 ORDER BY version DESC LIMIT 1')->fetch();
    return $r ? ['version' => (int)$r['version'], 'title' => (string)$r['title'], 'body' => (string)$r['body'], 'created_at' => (string)$r['created_at']] : null;
}
function payc_contract_accepted(PDO $pdo, int $cid, int $version): bool {
    $st = $pdo->prepare('SELECT 1 FROM contract_acceptances WHERE customer_id = ? AND version = ? LIMIT 1');
    $st->execute([$cid, $version]);
    return (bool)$st->fetchColumn();
}

// ---------------------------------------------------------------- فاکتور

/** دادهٔ فاکتور برای سفارش پرداخت‌شده (ساخت PDF سمت کلاینت، مرحلهٔ ۸) */
function payc_invoice(PDO $pdo, array $cust, array $o): array {
    $s = $pdo->query("SELECT k, v FROM pay_settings WHERE k IN ('seller_name','seller_phone','seller_address','seller_economic_code')")->fetchAll();
    $sel = ['seller_name' => 'پسته بازار', 'seller_phone' => '', 'seller_address' => '', 'seller_economic_code' => ''];
    foreach ($s as $r) if (trim((string)$r['v']) !== '') $sel[(string)$r['k']] = (string)$r['v'];
    $v = payc_order_view($o);
    $snap = json_decode((string)$o['snapshot'], true);
    $tx = $pdo->prepare("SELECT ref_id, card_pan FROM pay_transactions WHERE order_id = ? AND status = 'paid' ORDER BY id DESC LIMIT 1");
    $tx->execute([$o['id']]);
    $t = $tx->fetch() ?: [];
    return [
        'number' => (string)$o['public_id'],
        'date_jalali' => pay_jdate((string)($o['activated_at'] ?? $o['created_at']), true),
        'seller' => ['name' => $sel['seller_name'], 'phone' => $sel['seller_phone'], 'address' => $sel['seller_address'], 'economic_code' => $sel['seller_economic_code']],
        'buyer' => ['name' => (string)($cust['name'] ?? ''), 'phone' => (string)$cust['phone']],
        'method' => (string)$o['method'], 'ref_id' => $t['ref_id'] ?? null,
        'lines' => is_array($snap) ? ($snap['summary']['lines'] ?? []) : [],
        'periods' => is_array($snap) ? ($snap['summary']['periods'] ?? []) : [],
        'total' => $v['total'], 'vat_amount' => $v['vat_amount'], 'vat_percent' => $v['vat_percent'],
        'new_expiry_jalali' => $v['new_expiry_jalali'],
        'price_unit' => function_exists('pay_price_unit') ? pay_price_unit() : 'toman', // STAGE10
        'footer' => (function_exists('pay_price_unit') && pay_price_unit() === 'rial' ? 'مبالغ به ریال است.' : 'مبالغ به تومان است.') . ' استرداد وجه وجود ندارد.',
    ];
}
