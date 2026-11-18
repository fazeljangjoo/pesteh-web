<?php
declare(strict_types=1);
/**
 * API پرداخت و تمدید اشتراک (مرحلهٔ ۴) — برای وب، اندروید و ویندوز.
 * POST با بدنهٔ JSON: { phone, token, action, payload, idem? } — مثل app.php.
 * برخلاف app.php، کاربر «منقضی» هم مجاز است (صفحهٔ تمدید باید روی برنامهٔ قفل‌شده کار کند).
 * اکشن‌ها: pay.info, pay.quote, pay.create_order, pay.start_gateway, pay.order_status, pay.check_payment,
 *          pay.submit_manual, pay.history, pay.invoice, contract.public (بدون ورود), contract.get, contract.accept
 */
require dirname(__DIR__, 3) . '/private/config.php';
require __DIR__ . '/pay_engine.php';
require __DIR__ . '/pay_zarinpal.php';
require __DIR__ . '/pay_core.php';
require __DIR__ . '/auth_token.php'; // STAGE14
date_default_timezone_set('Asia/Tehran');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $corsOk = false;
    if (defined('ALLOWED_ORIGINS')) {
        $list = is_array(ALLOWED_ORIGINS) ? ALLOWED_ORIGINS : array_map('trim', explode(',', (string)ALLOWED_ORIGINS));
        $corsOk = in_array($origin, $list, true);
    } else {
        $oh = (string)parse_url($origin, PHP_URL_HOST);
        $op = parse_url($origin, PHP_URL_PORT);
        $corsOk = $oh !== '' && strcasecmp($oh . ($op ? ':' . $op : ''), (string)($_SERVER['HTTP_HOST'] ?? '')) === 0;
    }
    if ($corsOk) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['error' => 'درخواست نامعتبر است']); exit; }

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
/** خطای pay_fail ← پاسخ HTTP */
function out_fail(array $f, int $http = 400): void {
    out(['error' => (string)($f['message'] ?? 'خطا'), 'code' => (string)($f['error'] ?? 'error')], $http);
}

/** وضعیت تازهٔ حساب مشتری بعد از تغییر (فعال‌سازی) */
function fresh_account(PDO $pdo, array $cust): array {
    $x = $pdo->prepare('SELECT plan, expiry_date, active FROM customers WHERE id = ?');
    $x->execute([$cust['id']]);
    return payc_account(array_merge($cust, $x->fetch() ?: []));
}

$raw = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($raw)) out(['error' => 'درخواست نامعتبر است'], 400);
$action = (string)($raw['action'] ?? '');
$payload = is_array($raw['payload'] ?? null) ? $raw['payload'] : [];
$idem = (string)($raw['idem'] ?? '');

try {
    $pdo = db();

    // ---- بدون ورود: متن آخرین قرارداد منتشرشده (برای نمایش قبل از ثبت‌نام)
    if ($action === 'contract.public') {
        $c = payc_contract_latest($pdo);
        if (!$c) out(['item' => null]);
        out(['item' => $c]);
    }

    $phoneRaw = (string)($raw['phone'] ?? '');
    $token = (string)($raw['token'] ?? '');
    if ($phoneRaw === '' || $token === '') out(['error' => 'نیاز به ورود مجدد است'], 401);
    $phone = normalize_phone($phoneRaw);
    ensure_plan_column($pdo);
    $cust = tok_auth($pdo, $phone, $token, true); // STAGE14؛ توکن کارکن هم شناخته می‌شود (قبلاً ۴۰۱ می‌داد و کارکن بلافاصله بعد از ورود خارج می‌شد)
    if (!$cust) out(['error' => 'نشست شما نامعتبر است، دوباره وارد شوید'], 401);
    if ((int)($cust['_staff_id'] ?? 0) > 0) {
        // قرارداد را مالک می‌پذیرد؛ کارکن نباید جلوی ورودش گرفته شود. تمدید/پرداخت فقط برای مالک است (۴۰۳، نه ۴۰۱ تا خروج خودکار نشود)
        if ($action === 'contract.get') out(['item' => null, 'accepted' => true]);
        out(['error' => 'این بخش فقط برای مالک حساب است', 'code' => 'owner_only'], 403);
    }
    $cid = (int)$cust['id'];

    $planIn = (string)($payload['plan'] ?? '');
    $monthsIn = (int)($payload['months'] ?? 0);
    $tierUpIn = (string)($payload['kind'] ?? '') === 'tier_upgrade'; // S22.T22.3b: خرید حق ارتقا به طلایی

    switch ($action) {

        // وضعیت حساب + قیمت‌ها + روش‌های پرداخت + حساب‌های بانکی + وضعیت قرارداد
        case 'pay.info': {
            $s = payc_settings($pdo);
            $cfg = pay_load_cfg();
            $now = time();
            // prices = سبز (شکل قدیمی، بدون تغییر)؛ tiers = همهٔ SKUها به تفکیک سطح (S22.T22.1)
            $prices = []; $tiers = [];
            foreach (['green', 'gold'] as $t) {
                $tiers[$t] = [];
                foreach (['rial', 'fx'] as $p) {
                    $tiers[$t][$p] = [];
                    foreach (PAY_MONTHS as $m) {
                        $r = pay_site_price($cfg, $p, $m, $now, $t);
                        if ($r !== null) $tiers[$t][$p][(string)$m] = $r;
                    }
                }
            }
            $prices = $tiers['green'];
            $acc = payc_account($cust);
            $banks = payc_banks($pdo);
            $hasCard = false; $hasSheba = false;
            foreach ($banks as $b) { if ($b['card_number'] !== '') $hasCard = true; if ($b['sheba'] !== '') $hasSheba = true; }
            $upgrade = null;
            if (!$acc['expired'] && $acc['plan'] === 'rial') {
                $upgrade = ['remaining_days' => $acc['days_left'], 'amount' => pay_upgrade_price($cfg, $acc['days_left'], $acc['tier'])];
            }
            // S22.T22.3b: قیمت خرید حق ارتقا به طلایی (فقط سبز و اشتراک فعال)
            $tierUpgrade = null;
            if (!$acc['expired'] && $acc['tier'] !== 'gold') {
                $tp = pay_tier_upgrade_price($cfg, $acc['plan'] === 'fx' ? 'fx' : 'rial', (int)$acc['days_left']);
                if ($tp !== null) $tierUpgrade = ['remaining_days' => (int)$acc['days_left'], 'amount' => $tp, 'plan' => $acc['plan'] === 'fx' ? 'fx' : 'rial'];
            }
            $contract = payc_contract_latest($pdo);
            out(['item' => [
                'price_unit' => pay_price_unit(), // STAGE10: 'rial'|'toman' (PB_PRICE_UNIT)؛ اپ برچسب‌ها را از این می‌گیرد
                'account' => $acc + ['name' => (string)($cust['name'] ?? ''), 'phone' => (string)$cust['phone']],
                'prices' => $prices, 'tiers' => $tiers, 'months' => PAY_MONTHS,
                'can_buy_rial' => !($acc['active'] && $acc['plan'] === 'fx'),
                'upgrade' => $upgrade,
                'tier_upgrade' => $tierUpgrade,
                'vat_percent' => (float)$s['vat_percent'],
                'methods' => [
                    'gateway' => $s['gateway_enabled'] === '1' && zp_configured(),
                    'card' => $s['card_enabled'] === '1' && $hasCard,
                    'sheba' => $s['sheba_enabled'] === '1' && $hasSheba,
                ],
                'discount_enabled' => $s['discount_enabled'] === '1',
                'banks' => $banks,
                'contract' => $contract ? ['version' => $contract['version'], 'title' => $contract['title'], 'accepted' => payc_contract_accepted($pdo, $cid, $contract['version'])] : null,
                'notes' => ['درگاه پرداخت سریع‌تر است (فعال‌سازی فوری).', 'استرداد وجه به هیچ عنوان وجود ندارد.'],
            ]]);
        }

        // پیش‌فاکتور (و اعتبارسنجی کد تخفیف). code اختیاری
        case 'pay.quote':
        case 'pay.code_check': {
            $q = payc_quote($pdo, $cust, $planIn, $monthsIn, (string)($payload['code'] ?? ''), $tierUpIn);
            if (empty($q['ok'])) out_fail($q);
            out(['item' => $q]);
        }

        // ساخت سفارش (idem اجباری). برای method=gateway پیوند پرداخت هم برمی‌گردد
        case 'pay.create_order': {
            if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $idem)) out(['error' => 'کلید درخواست نامعتبر است', 'code' => 'bad_idem'], 400);
            $method = (string)($payload['method'] ?? '');
            $r = payc_create_order($pdo, $cust, $planIn, $monthsIn, (string)($payload['code'] ?? ''), $method, $idem, $tierUpIn);
            if (empty($r['ok'])) out_fail($r);
            $o = $r['order'];
            $res = ['order' => payc_order_view($o), 'replay' => !empty($r['replay'])];
            if ($o['status'] === 'pending' && $o['method'] === 'gateway') {
                $g = payc_gateway_start($pdo, $cust, $o);
                if (!empty($g['ok'])) $res['pay_url'] = $g['url'];
                else $res['gateway_error'] = ['code' => $g['error'] ?? 'error', 'message' => $g['message'] ?? ''];
            }
            if ($o['status'] === 'paid') $res['account'] = fresh_account($pdo, $cust);
            out($res);
        }

        // شروع (یا ادامهٔ) پرداخت درگاه برای سفارش pending
        case 'pay.start_gateway': {
            $o = payc_order_by_public($pdo, $cid, (string)($payload['order_id'] ?? ''));
            if (!$o) out(['error' => 'سفارش پیدا نشد', 'code' => 'order_not_found'], 404);
            $g = payc_gateway_start($pdo, $cust, $o);
            if (empty($g['ok'])) out_fail($g, in_array($g['error'] ?? '', ['gateway_unreachable', 'server_error'], true) ? 503 : 400);
            out(['pay_url' => $g['url']]);
        }

        // وضعیت سفارش (فقط خواندن از دیتابیس؛ مناسب polling)
        case 'pay.order_status': {
            $o = payc_order_by_public($pdo, $cid, (string)($payload['order_id'] ?? ''));
            if (!$o) out(['error' => 'سفارش پیدا نشد', 'code' => 'order_not_found'], 404);
            out(['order' => payc_order_view($o), 'account' => fresh_account($pdo, $cust)]);
        }

        // «پرداخت کردم، بررسی کن»: verify تراکنش‌های اخیر از سمت سرور
        case 'pay.check_payment': {
            $o = payc_order_by_public($pdo, $cid, (string)($payload['order_id'] ?? ''));
            if (!$o) out(['error' => 'سفارش پیدا نشد', 'code' => 'order_not_found'], 404);
            throttle_check('paycheck:' . $cid, 40, 900);
            throttle_fail('paycheck:' . $cid, 900);
            $r = payc_reconcile($pdo, $o);
            $st2 = $pdo->prepare('SELECT * FROM pay_orders WHERE id = ?');
            $st2->execute([$o['id']]);
            $o2 = $st2->fetch();
            out(['result' => $r['result'], 'order' => payc_order_view($o2), 'account' => fresh_account($pdo, $cust)]);
        }

        // ثبت کارت‌به‌کارت یا شبا
        case 'pay.submit_manual': {
            $o = payc_order_by_public($pdo, $cid, (string)($payload['order_id'] ?? ''));
            if (!$o) out(['error' => 'سفارش پیدا نشد', 'code' => 'order_not_found'], 404);
            $r = payc_submit_manual($pdo, $o, (string)($payload['method'] ?? ''), $payload);
            if (empty($r['ok'])) out_fail($r, ($r['error'] ?? '') === 'server_error' ? 500 : (in_array($r['error'] ?? '', ['already_submitted', 'order_not_pending'], true) ? 409 : 400));
            $st2 = $pdo->prepare('SELECT * FROM pay_orders WHERE id = ?');
            $st2->execute([$o['id']]);
            out(['order' => payc_order_view($st2->fetch()), 'amount_mismatch' => !empty($r['amount_mismatch']),
                'message' => 'اطلاعات پرداخت شما ثبت شد و پس از بررسی، نتیجه با پیام داخل برنامه اعلام می‌شود.']);
        }

        // فهرست سفارش‌های من
        case 'pay.history': {
            $st2 = $pdo->prepare("SELECT * FROM pay_orders WHERE customer_id = ? AND status <> 'canceled' ORDER BY id DESC LIMIT 30");
            $st2->execute([$cid]);
            $items = [];
            foreach ($st2->fetchAll() as $o) { $v = payc_order_view($o); unset($v['summary']); $items[] = $v; }
            out(['items' => $items]);
        }

        // دادهٔ فاکتور (فقط سفارش پرداخت‌شده)
        case 'pay.invoice': {
            $o = payc_order_by_public($pdo, $cid, (string)($payload['order_id'] ?? ''));
            if (!$o) out(['error' => 'سفارش پیدا نشد', 'code' => 'order_not_found'], 404);
            if ($o['status'] !== 'paid') out(['error' => 'فاکتور فقط برای سفارش پرداخت‌شده صادر می‌شود', 'code' => 'not_paid'], 400);
            out(['invoice' => payc_invoice($pdo, $cust, $o)]);
        }

        // قرارداد (با ورود): متن آخرین نسخه + اینکه این کاربر تأیید کرده یا نه
        case 'contract.get': {
            $c = payc_contract_latest($pdo);
            if (!$c) out(['item' => null, 'accepted' => true]);
            out(['item' => $c, 'accepted' => payc_contract_accepted($pdo, $cid, $c['version'])]);
        }

        case 'contract.accept': {
            $c = payc_contract_latest($pdo);
            if (!$c) out(['ok' => true, 'version' => 0]);
            $ver = (int)($payload['version'] ?? 0);
            if ($ver !== $c['version']) out(['error' => 'نسخهٔ قرارداد به‌روز شده است؛ متن جدید را بخوانید.', 'code' => 'contract_outdated', 'latest' => $c['version']], 409);
            $plat = (string)($payload['platform'] ?? '');
            if (!in_array($plat, ['android', 'windows', 'web'], true)) $plat = '';
            $dev = mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', (string)($payload['device'] ?? '')) ?? ''), 0, 80);
            $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $pdo->prepare('INSERT IGNORE INTO contract_acceptances (customer_id, version, platform, device, ip) VALUES (?,?,?,?,?)')
                ->execute([$cid, $ver, $plat !== '' ? $plat : null, $dev !== '' ? $dev : null, $ip !== '' ? $ip : null]);
            out(['ok' => true, 'version' => $ver]);
        }

        default:
            out(['error' => 'اکشن نامعتبر است', 'code' => 'bad_action'], 400);
    }
} catch (Throwable $e) {
    error_log('pay.php ' . $action . ': ' . $e->getMessage());
    out(['error' => 'خطای سرور؛ دوباره تلاش کنید.', 'code' => 'server_error'], 500);
}
