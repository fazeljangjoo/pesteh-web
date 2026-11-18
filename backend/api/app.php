<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * API یکپارچهٔ داده‌های «بازار پسته» (نسخه وب).
 * همه‌ی درخواست‌ها POST با بدنهٔ JSON هستند: { token, phone, action, payload, idem? }
 * خروجی همیشه JSON است.
 */
require dirname(__DIR__, 3) . '/private/config.php';
require __DIR__ . '/auth_token.php'; // STAGE14
require_once __DIR__ . '/auth_limits.php'; // LIMITS
require_once __DIR__ . '/notify_sms.php'; // SMS
require_once __DIR__ . '/migrations.php'; // S00: ordered migrations
require_once __DIR__ . '/features.php'; // S00: editions (tier x plan)
require_once __DIR__ . '/entities.php'; // S00: generic entity framework
require_once __DIR__ . '/warehouses.php'; // S01: warehouses + ounce grades
require_once __DIR__ . '/company.php'; // S12.T12.1: company profile + doc sequences hooks
require_once __DIR__ . '/stock.php'; // S01: stock moves ledger
require_once __DIR__ . '/parties.php'; // S03.T03.1: party master
require_once __DIR__ . '/fx_tx.php'; // S28.T28.2: fx receipts/payments/cheques
require_once __DIR__ . '/party_statement.php'; // S03.T03.2: statement + aging
require_once __DIR__ . '/crm.php'; // S03.T03.3: CRM dashboard
require_once __DIR__ . '/returns.php'; // S04.T04.1: purchase returns
require_once __DIR__ . '/sale_returns.php'; // S04.T04.2: sales returns
require_once __DIR__ . '/dues_credit.php'; // return credit on dues
require_once __DIR__ . '/proformas.php'; // S04.T04.3: pro-forma invoices
require_once __DIR__ . '/brokers.php'; // S05.T05.1: brokers + commissions
require_once __DIR__ . '/import.php'; // S13.T13.2b: bulk import engine
require_once __DIR__ . '/fiscal.php'; // S15.T15.1: fiscal years (gold)
require_once __DIR__ . '/treasury.php'; // S15.T15.3: cost centers, cash, banks, expenses (gold)
require_once __DIR__ . '/accounts.php'; // S15.T15.2: chart of accounts (gold)
require_once __DIR__ . '/vouchers.php'; // S16.T16.1: vouchers (gold)
require_once __DIR__ . '/posting.php'; // S16.T16.2: posting engine (gold)
require_once __DIR__ . '/tax_apply.php'; // S17.T17.1c: document tax persistence (gold, before the posting hook)
require_once __DIR__ . '/vat_report.php'; // S17.T17.2: reports.vat / vat.settle (gold)
require_once __DIR__ . '/statements.php'; // S18.T18.3 (gold)
require_once __DIR__ . '/fx_reports.php'; // S30.T30.4: reports.fx (gold+fx)
require_once __DIR__ . '/trial_balance.php'; // S18.T18.2: reports.trial (gold)
require_once __DIR__ . '/treasury_balances.php'; // S28.T28.1: treasury.balances (gold)
require_once __DIR__ . '/ledger_reports.php'; // S18.T18.1: reports.journal / reports.ledger / reports.tafsili (gold)
require_once __DIR__ . '/accounting_tools.php'; // S16.T16.6: accounting.rebuild / accounting.integrity (gold)
require_once __DIR__ . '/tier_upgrade.php'; // S20.T20.1: tier.upgrade_* (green -> gold)
require_once __DIR__ . '/cheques.php'; // S06.T06.1: unified cheque register
require_once __DIR__ . '/audit_run.php'; // S23.T23.2: audit.run data-health report
require_once __DIR__ . '/separation.php'; // S07.T07.1: multi-output separations
require_once __DIR__ . '/sale_core.php'; // S08.T08.2: sales.create core (reusable)
require_once __DIR__ . '/consignment.php'; // S08.T08.1: consignment (امانی)
require_once __DIR__ . '/processing.php'; // S09.T09.1: processing orders (فراوری)
require_once __DIR__ . '/orchards.php'; // S10.T10.1: orchards, costs, harvests
require_once __DIR__ . '/code_kind.php'; // T1: حرف کد پسته
require_once __DIR__ . '/mix_group.php'; // T3a: کلید گروه مخلوط (رنگ انبار)
require_once __DIR__ . '/payroll.php'; // S11.T11.1: employees, pay items, payroll runs, advances
require_once __DIR__ . '/audit.php'; // S02.T02.3: audit log
require_once __DIR__ . '/sync_version.php'; // S4: ver++ per write + sync.version/changes
require_once __DIR__ . '/sync_export.php'; // S10: sync.export (read-only snapshot for non-owner staff)
require_once __DIR__ . '/permissions.php'; // S02: staff accounts, roles, action->perm map
require_once __DIR__ . '/money_unit.php'; // S24.T24.2: money unit gate (toman -> rial)
require_once __DIR__ . '/fx_currencies.php'; // S25.T25.2: currencies
require_once __DIR__ . '/form_settings.php'; // S35.T35.1: form feature switches
require_once __DIR__ . '/weigh_block.php'; // S35.T35.2: weighing block
require_once __DIR__ . '/grade_lines.php'; // S35.T35.3: grade lines
require_once __DIR__ . '/grade_prices.php'; // S35.T35.4: daily price table
require_once __DIR__ . '/fin_block.php'; // S35.T35.5: financial block
require_once __DIR__ . '/pay_terms.php'; // S35.T35.7a: payment terms (pure math)
require_once __DIR__ . '/payments_mixed.php'; // S35.T35.7h: mixed payment lines
require_once __DIR__ . '/fx_rates.php'; // S25.T25.3: daily rates
require_once __DIR__ . '/fx_documents.php'; // S27.T27.1/2: foreign-currency sales & purchases
require_once __DIR__ . '/fx_adjust.php'; // S27b.T27b.8: adjustments + rate mismatch
require_once __DIR__ . '/approval.php'; // S35.T35.9e: second approval above a limit
require_once __DIR__ . '/bank_recon.php'; // S31.T31.1: bank reconciliation (gold)
require_once __DIR__ . '/backup_drill.php'; // S31.T31.15: monthly restore drill (non-destructive)
require_once __DIR__ . '/two_factor.php'; // S31.T31.19: TOTP 2FA + session management (owner)
require_once __DIR__ . '/error_monitor.php'; // S31.T31.18: server/app error monitoring
pb_err_install(); // PHP fatals -> error_events
require_once __DIR__ . '/reminders.php'; // S31.T31.14: unified due reminders feed
require_once __DIR__ . '/kpi.php'; // S31.T31.13: KPI dashboard (read-only)
require_once __DIR__ . '/lc.php'; // S31.T31.12: letters of credit with deadlines (fx plan)
require_once __DIR__ . '/sale_export.php'; // S31.T31.10: export fields on sales invoice (fx plan)
require_once __DIR__ . '/lot_cartex.php'; // مرحلهٔ ۱۰: کارتکس لات (زیرتب انبار؛ غیر از کارتکس مالک)
require_once __DIR__ . '/lot_trace.php'; // S31.T31.7: lot trace orchard->sale + orchard flow report
require_once __DIR__ . '/lot_chain.php'; // T4: مسیر پسته (lot_chain.get)
require_once __DIR__ . '/daily_recon.php'; // S31.T31.4: daily reconciliation dashboard (gold)
require_once __DIR__ . '/pay_approval.php'; // S31.T31.3: second approval for payments above a limit
require_once __DIR__ . '/correction.php'; // S35.T35.9c: correction document
require_once __DIR__ . '/posting_preview.php'; // S35.T35.9d: voucher dry-run preview (gold, display-only)
// تاریخ «امروز» باید به وقت ایران باشد، نه منطقهٔ زمانی سرور
date_default_timezone_set('Asia/Tehran');
// STAGE11: سپر همگام‌سازی؛ فقط با PB_SYNC_GUARD روشن (private/config.php) و وجود فایل. وگرنه رفتار قدیمی.
if (defined('PB_SYNC_GUARD') && PB_SYNC_GUARD && is_file(__DIR__ . '/sync_guard.php')) require_once __DIR__ . '/sync_guard.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// CORS: پیش‌فرض فقط هم‌مبدأ (همان هاست). اگر وب‌اپ روی دامنهٔ دیگری است، در config.php بنویسید:
// define('ALLOWED_ORIGINS', 'https://app.example.com');   (چند مورد با ویرگول)
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
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/** اکشن‌هایی که با کلید یکتا (idem) از ثبت تکراری محافظت می‌شوند */
const WRITE_ACTIONS = ['parties.save', 'categories.create', 'types.create', 'purchases.create', 'purchases.delete', 'mixes.create',
    'mixes.update', 'mixes.delete', 'payments.save', 'receipts.save', 'sales.create', 'sales.update', 'sales.delete',
    'dues.mark_paid', 'backup.import', 'parties.create', 'loans.create', 'loans.delete', 'loans.due_paid', 'entity.save', 'entity.delete', 'intake.submit', 'intake.pct_defs.seed', 'intake.confirm', 'intake.reject', 'intake.mark_sample', 'stock.transfer', 'stock.transfer_cancel',
    'purchase_returns.create', 'purchase_returns.delete', 'sale_returns.create', 'sale_returns.delete',
    'proformas.save', 'proformas.set_status', 'proformas.delete', 'proformas.convert', 'proformas.link_sales',
    'broker_rules.save', 'broker_rules.delete', 'broker_payments.save', 'broker_payments.delete', 'broker.attach', 'cheques.transition',
    'separations.create', 'separations.update', 'separations.delete', 'separations.settings_save',
    'consignments.create', 'consignments.delete', 'consignments.return', 'consignments.return_delete', 'consignments.pay', 'consignments.pay_delete', 'consignments.sold',
    'processing.create', 'processing.receive', 'processing.deliver', 'processing.delete', 'workshop.create', 'workshop.delete', 'workshop.cost_save', 'workshop.crack_create', 'workshop.crack_update', 'workshop.crack_delete', 'workshop.crack_pool_extra', 'workshop.crack_pool_close', 'workshop.crack_pool_reopen', 'processing.receipt_save', 'feed.adjust_create', 'feed.adjust_delete',
    'code_prefix.set', 'orchards.save', 'orchards.delete', 'orchard_costs.add', 'orchard_costs.delete', 'harvests.create', 'orchards.allocate',
    'payroll.employee_save', 'payroll.item_save', 'payroll.advance_add', 'payroll.run_create', 'payroll.line_update', 'payroll.approve', 'payroll.run_delete', 'payroll.pay_line_add', 'payroll.pay_line_delete', 'payroll.params_save'];

/** انواع تراکنش دفتر قرض و وام: قرض اعطایی / قرض دریافتی / بازپرداخت دریافتی / بازپرداخت پرداختی */
const LOAN_KINDS = ['given', 'taken', 'repay_in', 'repay_out'];

$idemCtx = null; // ['cid'=>, 'key'=>] وقتی درخواست نوشتنیِ دارای کلید در حال اجراست

function out($data, int $code = 200): void {
    global $pdo, $idemCtx, $customer, $action, $payload;
    if ($code >= 400 && !empty($GLOBALS['pb_out_throw']) && class_exists('PbOutError')) throw new PbOutError($data, $code); // S20.T20.1: upgrade steps isolate a failing document
    if ($code < 400 && isset($customer) && is_array($customer) && function_exists('pb_strip_price_fields')) $data = pb_strip_price_fields($data, $customer); // S1: کارکنِ بدون price.view هیچ فیلد قیمتی نمی‌گیرد (قبل از ذخیرهٔ idem)
    if ($code >= 400 && isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if ($idemCtx && isset($pdo)) {
        try {
            if ($code < 400) {
                $pdo->prepare('UPDATE idempotency_keys SET status=1, http_code=?, response=? WHERE customer_id=? AND idem_key=?')
                    ->execute([$code, json_encode($data, JSON_UNESCAPED_UNICODE), $idemCtx['cid'], $idemCtx['key']]);
            } else {
                // شکست: کلید آزاد می‌شود تا تلاش دوباره ممکن باشد
                $pdo->prepare('DELETE FROM idempotency_keys WHERE customer_id=? AND idem_key=? AND status=0')
                    ->execute([$idemCtx['cid'], $idemCtx['key']]);
            }
        } catch (Throwable $e) { error_log('idem: ' . $e->getMessage()); }
        $idemCtx = null;
    }
    if ($code < 400 && isset($pdo, $customer, $action) && $pdo instanceof PDO && is_array($customer) && isset($customer['id']) && function_exists('pb_sync_is_write') && pb_sync_is_write((string)$action)) pb_sync_bump($pdo, (int)$customer['id'], (string)$action, is_array($payload ?? null) ? $payload : []); // S4: ver++ (هرگز استثنا نمی‌اندازد)
    if ($code < 400 && isset($pdo, $customer, $action) && $pdo instanceof PDO && is_array($customer) && isset($customer['id']) && function_exists('pb_audit_auto')) pb_audit_auto($pdo, $customer, (string)$action, is_array($payload ?? null) ? $payload : [], $data); // S02.T02.3
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function bad(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

function iniBytes(string $v): int {
    $v = trim($v); if ($v === '') return 0;
    $n = (int)$v; $u = strtolower(substr($v, -1));
    if ($u === 'g') $n *= 1024 ** 3; elseif ($u === 'm') $n *= 1024 ** 2; elseif ($u === 'k') $n *= 1024;
    return $n;
}

/** شروع درخواست نوشتنیِ دارای کلید یکتا؛ اگر قبلاً انجام شده باشد همان پاسخ قبلی را برمی‌گرداند */
function idemBegin(PDO $pdo, int $cid, string $key): void {
    global $idemCtx;
    pb_ddl_exec($pdo, 'CREATE TABLE IF NOT EXISTS idempotency_keys (
        customer_id INT UNSIGNED NOT NULL, idem_key VARCHAR(64) NOT NULL, status TINYINT NOT NULL DEFAULT 0,
        http_code SMALLINT NOT NULL DEFAULT 0, response MEDIUMTEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (customer_id, idem_key), KEY idx_idem_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (mt_rand(1, 50) === 1) $pdo->exec('DELETE FROM idempotency_keys WHERE created_at < (NOW() - INTERVAL 7 DAY)');
    try {
        $pdo->prepare('INSERT INTO idempotency_keys (customer_id, idem_key, status) VALUES (?,?,0)')->execute([$cid, $key]);
        $idemCtx = ['cid' => $cid, 'key' => $key];
        return;
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') throw $e;
    }
    $st = $pdo->prepare('SELECT status, http_code, response, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM idempotency_keys WHERE customer_id=? AND idem_key=?');
    $st->execute([$cid, $key]);
    $row = $st->fetch();
    if ($row && (int)$row['status'] === 1) {
        header('X-Idempotent-Replay: 1');
        http_response_code((int)$row['http_code'] ?: 200);
        echo (string)$row['response'];
        exit;
    }
    if ($row && (int)$row['age'] > 120) { // درخواست قبلی احتمالاً وسط کار قطع شده؛ کلید را دوباره تصاحب می‌کنیم
        $pdo->prepare('UPDATE idempotency_keys SET created_at=NOW() WHERE customer_id=? AND idem_key=? AND status=0')->execute([$cid, $key]);
        $idemCtx = ['cid' => $cid, 'key' => $key];
        return;
    }
    bad('درخواست قبلی هنوز در حال پردازش است، چند لحظه بعد دوباره تلاش می‌شود', 409);
}

$rawBody = (string)file_get_contents('php://input');
$contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMax = iniBytes((string)ini_get('post_max_size'));
// اگر حجم درخواست از post_max_size بگذرد PHP بدنه را خالی می‌بیند؛ این را با ۴۰۱ اشتباه نگیریم (کلاینت logout می‌کرد)
if (($rawBody === '' && $contentLen > 0) || ($postMax > 0 && $contentLen > $postMax)) {
    out(['error' => 'حجم داده از حد مجاز سرور بیشتر است (post_max_size). حجم عکس یا فایل را کم کنید'], 413);
}
$raw = json_decode($rawBody, true);
if (!is_array($raw)) bad('درخواست نامعتبر است', 400);

$phoneRaw = (string)($raw['phone'] ?? '');
$token = (string)($raw['token'] ?? '');
$action = (string)($raw['action'] ?? '');
$payload = is_array($raw['payload'] ?? null) ? $raw['payload'] : [];
$idemKey = (string)($raw['idem'] ?? '');

if ($phoneRaw === '' || $token === '') bad('نیاز به ورود مجدد است', 401);
$phone = normalize_phone($phoneRaw);

$pdo = db();
ensure_plan_column($pdo);
pb_migrate($pdo); // S00: adds customers.tier before the customer row is loaded
$customer = tok_auth($pdo, $phone, $token, true); // STAGE14; S02: کارکن مجاز است (مجوزش پایین‌تر بررسی می‌شود)
if (!$customer) bad('نشست شما نامعتبر است، دوباره وارد شوید', 401);
// کاربر غیرفعال/منقضی فقط به account.status دسترسی دارد (برای دیدن وضعیت و راه تماس جهت تمدید)
$blocked = null;
if (!$customer['active']) $blocked = 'اشتراک شما فعال نیست';
elseif (strtotime((string)$customer['expiry_date']) < strtotime('today')) $blocked = 'اشتراک شما منقضی شده است';
if ($blocked !== null && $action !== 'account.status') bad($blocked, 403);

$cid = (int)$customer['id'];
$plan = customer_plan($customer);
$isFx = $plan === PLAN_FX; // پلن ارزی: قیمت دلار + سود دلاری
$edition = edition_of($customer); // S00: {tier, plan, code, features[], limits}

// S02.T02.1: کارکن فقط اکشن‌های مجاز نقشش را اجرا می‌کند؛ اکشن ناشناخته یا مخصوص مالک = رد. مالک بدون این بررسی.
if ((int)($customer['_staff_id'] ?? 0) > 0) {
    pb_staff_attach($pdo, $customer);
    $needPerm = pb_action_perm($action);
    // اتاق انس (ounce.intake) هم می‌تواند دسته/نوع جدید بسازد، بدون اینکه مجوز مدیریت کاتالوگ داشته باشد
    $catalogViaIntake = in_array($action, ['categories.create', 'types.create'], true) && pb_staff_can($customer, 'ounce.intake');
    if (!$catalogViaIntake && ($needPerm === null || ($needPerm !== '' && !pb_staff_can($customer, $needPerm)))) {
        out(['error' => 'forbidden', 'message' => 'دسترسی لازم را ندارید', 'need' => $needPerm ?? $action], 403);
    }
}

if ($idemKey !== '' && in_array($action, WRITE_ACTIONS, true)) {
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $idemKey)) bad('کلید درخواست نامعتبر است');
    idemBegin($pdo, $cid, $idemKey);
}

// ---------------------------------------------------------------- کمک‌کننده‌ها

function num($v): float { return $v === null || $v === '' ? 0.0 : (float)$v; }
function nullableNum($v) { return ($v === null || $v === '') ? null : (float)$v; }
/** هزینهٔ جانبی (کارگر / حمل‌ونقل-باسکول): خالی = null، منفی یا بیش‌ازحد = خطا */
function costNum($v): ?float {
    if ($v === null || $v === '') return null;
    $f = (float)$v;
    if ($f < 0 || $f > 1e13) bad('مبلغ هزینه نامعتبر است');
    return $f == 0.0 ? null : round($f, 2);
}
function tare($small, $large): float { $k = $GLOBALS['PB_SACK_KG'] ?? [0.1, 0.2]; return num($small) * (float)$k[0] + num($large) * (float)$k[1]; } // S35.T35.2: sack kg configurable (set by pb_weigh_boot only when form.weigh is on)
function today(): string { return date('Y-m-d'); }

/** آیا این لات در اقلام یک «امانی ارسالی» آمده؟ کالای نزد امانت‌گیرنده در purchases.remaining می‌ماند؛ بررسی «فروخته‌نشده» (net=remaining) آن را نمی‌بیند. */
function pb_lot_on_consignment(PDO $pdo, int $cid, int $lotId): bool {
    try {
        $q = $pdo->prepare('SELECT 1 FROM consignment_items WHERE customer_id=? AND lot_id=? LIMIT 1');
        $q->execute([$cid, $lotId]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) { return false; } // جدول اختیاری
}


/**
 * قیمت دلار (تومان به‌ازای هر دلار). فقط در پلن ارزی ذخیره می‌شود؛ در پلن ریالی همیشه null.
 * اگر ارسال نشده باشد null می‌ماند (مثلاً عملیاتِ صف‌شدهٔ قبل از ارتقای پلن) و کلاینت‌ها وارد کردنش را اجباری می‌کنند.
 */
function readUsdRate(array $p, bool $isFx): ?float {
    if (!$isFx) return null;
    $r = nullableNum($p['usd_rate'] ?? null);
    if ($r === null) return null;
    if ($r <= 0 || $r > 1e9) bad('قیمت دلار نامعتبر است');
    return round($r, 2);
}
/** فی به دلار (هر کیلو) = فی تومانی ÷ قیمت دلار */
function usdPrice(float $price, ?float $rate): ?float {
    return ($rate !== null && $rate > 0) ? round($price / $rate, 4) : null;
}
/** در پلن ریالی هیچ فیلد دلاری‌ای به کلاینت نمی‌رسد */
function stripFx(array $rows, bool $isFx, array $keys = ['usd_rate', 'price_usd', 'cost_price_usd']): array {
    if ($isFx) return $rows;
    foreach ($rows as &$r) { foreach ($keys as $k) unset($r[$k]); }
    unset($r);
    return $rows;
}
/** ستون‌های دلاری (usd_rate/price_usd روی خرید، usd_rate روی فروش) در صورت نبودن ساخته می‌شوند */
function ensureFxColumns(PDO $pdo): void {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'usd_rate'");
        if (!($st && $st->fetch())) {
            $pdo->exec("ALTER TABLE purchases
                ADD COLUMN usd_rate DECIMAL(14,2) NULL,
                ADD COLUMN price_usd DECIMAL(14,4) NULL");
        }
        $st = $pdo->query("SHOW COLUMNS FROM sales LIKE 'usd_rate'");
        if (!($st && $st->fetch())) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN usd_rate DECIMAL(14,2) NULL");
        }
    } catch (Throwable $e) {
        error_log('[app.php] ensureFxColumns: ' . $e->getMessage());
    }
}

/** ستون ضایعات/خاک (waste) روی purchases برای بارهای مخلوط/جداسازی؛ در صورت نبودن خودکار ساخته می‌شود */
function ensureWasteColumn(PDO $pdo): void {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'waste'");
        if (!($st && $st->fetch())) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN waste DECIMAL(12,3) NULL DEFAULT NULL");
        }
        // هزینه کارگر و حمل‌ونقل/باسکول (خرید و فروش) — از سود کم می‌شود
        foreach (['purchases', 'sales'] as $tbl) {
            foreach (['labor_cost', 'transport_cost'] as $col) {
                $stc = $pdo->query("SHOW COLUMNS FROM $tbl LIKE '$col'");
                if (!($stc && $stc->fetch())) $pdo->exec("ALTER TABLE $tbl ADD COLUMN $col DECIMAL(14,2) NULL DEFAULT NULL");
            }
        }
        // نوع زیردستهٔ مخلوط/جداسازی (برای دقیق‌تر شدن نمودارها)
        $st2 = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'base_type'");
        if (!($st2 && $st2->fetch())) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN base_type VARCHAR(100) NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('[app.php] ensureWasteColumn: ' . $e->getMessage());
    }
}

/**
 * ضایعات/خاکِ مخلوط یا جداسازی. قاعده: وزنِ برداشته‌شده از انبار = وزن خالصِ بارِ تازه + ضایعات.
 * اگر کلید waste اصلاً نیامده باشد (عملیات آفلاینِ نسخه‌های قدیمی) بررسی انجام نمی‌شود و null برمی‌گردد.
 */
function readMixWaste(PDO $pdo, array $payload, float $taken, float $net): ?float {
    if (!array_key_exists('waste', $payload)) return null;
    $w = nullableNum($payload['waste']);
    if ($w === null) $w = 0.0;
    if ($w < 0 || $w > 1e9) { $pdo->rollBack(); bad('وزن ضایعات نامعتبر است'); }
    $diff = $taken - $net - $w;
    if (abs($diff) > 0.0015) {
        $pdo->rollBack();
        $f = function ($v) { return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.'); };
        if ($diff > 0) bad('وزن برداشته‌شده ' . $f($taken) . ' کیلو است ولی وزن خالص + ضایعات فقط ' . $f($net + $w) . ' کیلو می‌شود؛ ' . $f($diff) . ' کیلو اختلاف ثبت‌نشده دارید. اگر خاک یا ضایعات بوده، در «ضایعات» وارد کنید');
        bad('وزن خالص + ضایعات (' . $f($net + $w) . ' کیلو) از وزن برداشته‌شده (' . $f($taken) . ' کیلو) بیشتر است؛ ' . $f(-$diff) . ' کیلو اضافه است');
    }
    return round($w, 3);
}

/** رشتهٔ کوتاه‌شده و تمیز؛ خالی => null */
/** جدول‌های طرف‌حساب‌های دستی و دفتر قرض/وام (مبالغ قرض هرگز در سود و زیان حساب نمی‌شوند) */
function ensureLoanTables(PDO $pdo): void {
    try {
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS parties (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            UNIQUE KEY uq_party (customer_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS loan_entries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            uid VARCHAR(40) NOT NULL,
            party VARCHAR(150) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            date DATE NOT NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME NULL,
            UNIQUE KEY uq_loan_uid (customer_id, uid),
            KEY idx_loan_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // سررسید/اقساط هر قرض (حداکثر ۲۰ مورد)؛ برای یادآوری در صفحهٔ سررسیدها
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS loan_dues (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            loan_uid VARCHAR(40) NOT NULL,
            seq TINYINT UNSIGNED NOT NULL,
            due_date DATE NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            note VARCHAR(500) NULL,
            paid TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_loan_due (customer_id, loan_uid, seq),
            KEY idx_due_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[app.php] ensureLoanTables: ' . $e->getMessage());
    }
}

/** پیام‌ها/اطلاعیه‌هایی که مدیر از پنل ادمین برای یک مشتری یا همه می‌فرستد (تب خانه) */
function ensureMessageTables(PDO $pdo): void {
    try {
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS announcements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NULL,
            title VARCHAR(120) NOT NULL,
            body TEXT NOT NULL,
            link_url VARCHAR(500) NULL,
            link_label VARCHAR(60) NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'info',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            KEY idx_ann_target (customer_id, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS announcement_reads (
            customer_id INT UNSIGNED NOT NULL,
            announcement_id INT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL,
            PRIMARY KEY (customer_id, announcement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[app.php] ensureMessageTables: ' . $e->getMessage());
    }
}

function str_or_null($v, int $max): ?string {
    if ($v === null || is_array($v)) return null;
    $v = trim((string)$v);
    if ($v === '') return null;
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
}
/** تاریخ YYYY-MM-DD معتبر؛ خالی => امروز (به وقت ایران) */
function vdate($v): string {
    if ($v === null || $v === '') return today();
    $v = (string)$v;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) bad('تاریخ نامعتبر است');
    return $v;
}
function vdateReq($v): string {
    if ($v === null || $v === '') bad('تاریخ سررسید را وارد کنید');
    return vdate($v);
}
function vamount($v): float {
    $a = num($v);
    if ($a < 0 || $a > 1e13) bad('مبلغ نامعتبر است');
    return $a;
}
/** عکس رسید فقط data-URL تصویر با base64 خالص (جلوگیری از تزریق HTML/اسکریپت) */
function vphoto($p): ?string {
    if ($p === null || $p === '') return null;
    if (!is_string($p) || strlen($p) > 8 * 1024 * 1024) bad('عکس نامعتبر یا بیش از حد بزرگ است');
    if (!preg_match('#^data:image/(jpeg|png|webp|gif);base64,#', $p, $m)) bad('فرمت عکس نامعتبر است');
    $b64 = substr($p, strlen($m[0]));
    if ($b64 === '' || strspn($b64, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') !== strlen($b64)) bad('فرمت عکس نامعتبر است');
    return $p;
}
function ownsCategory(PDO $pdo, int $cid, int $catId): bool {
    $st = $pdo->prepare('SELECT 1 FROM categories WHERE id=? AND customer_id=?');
    $st->execute([$catId, $cid]);
    return (bool)$st->fetchColumn();
}
/** وزن کل/گونی‌ها را می‌خواند و وزن خالص را برمی‌گرداند؛ مقدار منفی یا صفر مجاز نیست */
function readWeights(array $p): array {
    $total = num($p['total_weight'] ?? 0);
    $small = num($p['small_sack_count'] ?? 0);
    $large = num($p['large_sack_count'] ?? 0);
    if ($total <= 0 || $total > 1e9) bad('وزن باید بزرگ‌تر از صفر باشد');
    if ($small < 0 || $large < 0 || $small > 1e7 || $large > 1e7) bad('تعداد گونی نامعتبر است');
    $net = $total - tare($small, $large);
    if ($net <= 0) bad('وزن خالص باید بزرگ‌تر از صفر باشد');
    return [$total, $small, $large, $net];
}
/**
 * S3: منطق مشترک «ثبت/ویرایش خرید» (بدون تغییر رفتار؛ از case 'purchases.create' استخراج شد) تا intake.confirm هم همان را صدا بزند.
 * $opts['code'] (اختیاری): کد پسته به‌جای KH+id (فقط در ایجاد). $opts['in_tx']=true: تراکنش بیرونی است؛ تابع نه begin می‌کند نه commit.
 * فراخوان اجازه‌ها را خودش چک می‌کند. در خطا bad() (=rollback). خروجی: شناسهٔ خرید.
 */
function pb_purchase_create_core(PDO $pdo, int $cid, array $customer, array $payload, bool $isFx, array $opts = []): int {
    $editId = null; // (رفتار قبلی: قبل از خط ویرایش تعریف‌نشده بود)
    pb_weigh_boot($pdo, $cid); // S35.T35.2
    [$total, $small, $large, $net] = readWeights($payload);
    $weigh = pb_weigh_read($pdo, $cid, $payload, $small, $large); // S35.T35.2: net = gross - tare - deduction (server-recomputed)
    if ($weigh) { $total = $weigh['gross']; $net = $weigh['net']; }
    $grades = pb_grades_read($pdo, $cid, $payload, (float)$net); // S35.T35.3: weighted price from grade lines
    $catId = (int)($payload['category_id'] ?? 0);
    if ($catId <= 0) bad('دسته را انتخاب کنید');
    if (!ownsCategory($pdo, $cid, $catId)) bad('دستهٔ انتخابی یافت نشد', 404);
    $price = num($payload['price'] ?? 0);
    if ($price < 0 || $price > 1e13) bad('قیمت نامعتبر است');
    if ($grades) $price = (float)$grades['price']; // S35.T35.3 (a foreign-currency doc overrides it below as before)
    foreach (['ounce', 'gram', 'pct_dombest', 'pct_tuchin', 'pct_crooked', 'pct_khandan'] as $k) {
        $v = nullableNum($payload[$k] ?? null);
        if ($v !== null && ($v < 0 || $v > 1e9)) bad('مقدار مشخصات کیفی نامعتبر است');
        if ($v !== null && strpos($k, 'pct_') === 0 && $v > 100) bad('درصدها نباید از ۱۰۰ بیشتر باشند');
    }
    $pDocDate = vdate($payload['date'] ?? null);
    if ($editId > 0) pb_fx_guard_no_edit($pdo, $cid, 'purchases', $editId, $payload); // S27c.T27b.6: ویرایش خرید ارزی رد می‌شود
    $fxDoc = ($editId > 0) ? null : pb_fx_doc_currency($pdo, $cid, $payload, $pDocDate, $isFx); // S27.T27.2
    if ($fxDoc) $price = $fxDoc['price_stored'];
    $usdRate = readUsdRate($payload, $isFx);
    $priceUsd = usdPrice($price, $usdRate);
    if ($fxDoc) { $usdRate = null; $priceUsd = null; } // T30.3b: foreign-currency docs carry fx_rate/ccy; legacy usd_rate is read-only
    $typeName = str_or_null($payload['type_name'] ?? '', 100) ?? '';
    $seller = str_or_null($payload['seller'] ?? null, 150);
    $note = str_or_null($payload['note'] ?? null, 500);
    $pDate = vdate($payload['date'] ?? null);
    pb_fy_guard($pdo, $cid, $customer, $pDate); // S15.T15.1 (gold only)
    $editId = (int)($payload['id'] ?? 0);
    $laborCost = costNum($payload['labor_cost'] ?? null);
    $transportCost = costNum($payload['transport_cost'] ?? null);
    $customSpecs = null;
    if (!empty($payload['custom_specs'])) {
        if (!is_array($payload['custom_specs'])) bad('مشخصات دلخواه نامعتبر است');
        $customSpecs = json_encode($payload['custom_specs'], JSON_UNESCAPED_UNICODE);
        if ($customSpecs === false || strlen($customSpecs) > 4000) bad('مشخصات دلخواه بیش از حد بزرگ است');
    }
    // عکس رسید: یک data-URL (base64) که سمت مرورگر قبلاً فشرده شده؛ اگر کلید photo اصلاً
    // در payload نباشد یعنی کاربر تغییری در عکس نداده (در ویرایش عکس قبلی دست‌نخورده می‌ماند).
    $photoProvided = array_key_exists('photo', $payload);
    $photo = $photoProvided ? vphoto($payload['photo'] ?: null) : null;

    $ownTx = empty($opts['in_tx']); // S3: intake.confirm تراکنش بیرونی دارد (purchases + قیمت + وضعیت اتمیک)
    if ($ownTx) $pdo->beginTransaction();
    $txLost = false; $txStep = function (string $s) use ($pdo, &$txLost): void { if (!$txLost && !$pdo->inTransaction()) { $txLost = true; try { pb_err_server(new RuntimeException('TX ENDED BEFORE/AT: ' . $s)); } catch (Throwable $e) {} } }; // hotfix diagnostic
    if ($editId > 0) {
        $chk = $pdo->prepare('SELECT net_weight, remaining, small_sack_count, large_sack_count, small_sacks_remaining, large_sacks_remaining, usd_rate, ccy, seller FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $chk->execute([$editId, $cid]);
        $old = $chk->fetch();
        // v71: رد تغییر نام فروشنده روی خریدِ دارای برگشت (اعتبار برگشت به نام فروشنده است)
        if ($old && partyKey($old['seller']) !== partyKey($seller) && pb_preturn_lot_has_returns($pdo, $cid, $editId)) { $pdo->rollBack(); bad('این خرید برگشت از خرید دارد؛ نام فروشنده قابل تغییر نیست'); }
        // پلن ریالی: دادهٔ دلاریِ قدیمی دست‌نخورده می‌ماند (فقط فی دلاری با فیِ جدید و نرخ قبلی هماهنگ می‌شود)
        if ($old && (!$isFx || !empty($old['ccy']))) { // T30.3b: also keep legacy usd_rate on foreign-currency docs
            $usdRate = $old['usd_rate'] !== null ? (float)$old['usd_rate'] : null;
            $priceUsd = usdPrice($price, $usdRate);
        }
        if (!$old) { $pdo->rollBack(); bad('این خرید یافت نشد', 404); }
        $sold = (float)$old['net_weight'] - (float)$old['remaining'];
        if ($sold > 1e-6 && abs($net - (float)$old['net_weight']) > 1e-6) {
            $pdo->rollBack(); bad('چون بخشی از این خرید فروخته شده، وزن آن قابل تغییر نیست');
        }
        $newRemaining = $net - $sold;
        // گونیِ باقی‌مانده = تعداد جدید منهای گونیِ تاکنون مصرف‌شده (تعداد قبلی - باقی‌مانده قبلی)
        $usedSmall = (float)$old['small_sack_count'] - (float)$old['small_sacks_remaining'];
        $usedLarge = (float)$old['large_sack_count'] - (float)$old['large_sacks_remaining'];
        $newSmallRem = $small - $usedSmall; $newLargeRem = $large - $usedLarge;
        if ($newSmallRem < -1e-6 || $newLargeRem < -1e-6) {
            $pdo->rollBack(); bad('تعداد گونی نمی‌تواند از گونیِ تاکنون مصرف‌شدهٔ این خرید کمتر باشد');
        }
        $newSmallRem = max(0.0, $newSmallRem); $newLargeRem = max(0.0, $newLargeRem);
        $sql = 'UPDATE purchases SET category_id=?, type_name=?, date=?, ounce=?, price=?, total_weight=?,
            small_sack_count=?, large_sack_count=?, small_sacks_remaining=?, large_sacks_remaining=?, net_weight=?, remaining=?, gram=?, pct_dombest=?, pct_tuchin=?,
            pct_crooked=?, pct_khandan=?, custom_specs=?, seller=?, note=?, usd_rate=?, price_usd=?, labor_cost=?, transport_cost=?' . ($photoProvided ? ', photo=?' : '') . '
            WHERE id=? AND customer_id=?';
        $params = [
            $catId, $typeName, $pDate, nullableNum($payload['ounce'] ?? null),
            $price, $total, $small, $large, $newSmallRem, $newLargeRem, $net, $newRemaining,
            nullableNum($payload['gram'] ?? null), nullableNum($payload['pct_dombest'] ?? null),
            nullableNum($payload['pct_tuchin'] ?? null), nullableNum($payload['pct_crooked'] ?? null),
            nullableNum($payload['pct_khandan'] ?? null), $customSpecs, $seller,
            $note, $usdRate, $priceUsd, $laborCost, $transportCost,
        ];
        if ($photoProvided) $params[] = $photo;
        $params[] = $editId; $params[] = $cid;
        $pdo->prepare($sql)->execute($params);
        $purchaseId = $editId;
    } else {
        $sql = 'INSERT INTO purchases (customer_id, code, category_id, type_name, date, ounce, price, total_weight,
            small_sack_count, large_sack_count, small_sacks_remaining, large_sacks_remaining, net_weight, remaining, gram, pct_dombest, pct_tuchin, pct_crooked,
            pct_khandan, custom_specs, photo, seller, note, usd_rate, price_usd, labor_cost, transport_cost, is_mix) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)';
        $pdo->prepare($sql)->execute([
            $cid, null, $catId, $typeName, $pDate,
            nullableNum($payload['ounce'] ?? null), $price, $total, $small, $large, $small, $large,
            $net, $net, nullableNum($payload['gram'] ?? null), nullableNum($payload['pct_dombest'] ?? null),
            nullableNum($payload['pct_tuchin'] ?? null), nullableNum($payload['pct_crooked'] ?? null),
            nullableNum($payload['pct_khandan'] ?? null), $customSpecs, $photo, $seller, $note, $usdRate, $priceUsd, $laborCost, $transportCost,
        ]);
        $purchaseId = (int)$pdo->lastInsertId();
        if ($fxDoc) pb_fx_doc_stamp($pdo, $cid, 'purchases', $purchaseId, $fxDoc, 0.0); // S27.T27.2
    $txStep('after pb_fx_doc_stamp');
        $code = isset($opts['code']) && $opts['code'] !== '' ? (string)$opts['code'] : pb_code_with_id(pb_code_letter_for_category($pdo, $cid, (int)$catId), (int)$purchaseId); // S3: intake code override · T1: حرف از دستهٔ خرید (KH/D/G)
        $pdo->prepare('UPDATE purchases SET code=? WHERE id=?')->execute([$code, $purchaseId]);
    }
    pb_weigh_store($pdo, $cid, 'purchases', (int)$purchaseId, $weigh); // S35.T35.2
    $txStep('after pb_weigh_store');
    pb_grades_store($pdo, $cid, 'purchase', (int)$purchaseId, $grades, (float)$net, pb_form_on($pdo, $cid, 'grades')); // S35.T35.3
    $txStep('after pb_grades_store');
    pb_wh_apply_lot($pdo, $cid, $purchaseId, $payload, $editId <= 0); // S01: warehouse/ounce grade (additive)
    $txStep('after pb_wh_apply_lot');
    pb_broker_apply($pdo, $cid, 'purchase', $purchaseId, $payload); // S05.T05.1: commission (capitalized into lot cost when payer=us)
    $txStep('after pb_broker_apply');
    pb_stock_post_doc($pdo, $cid, 'purchase', $purchaseId); // S01.T01.2 ledger (reverse + repost when changed)
    $txStep('after pb_stock_post_doc');
    if (isset($payload['payment'])) savePayment($pdo, $cid, $purchaseId, is_array($payload['payment']) ? $payload['payment'] : []);
    $txStep('after savePayment');
    pb_fin_apply($pdo, $cid, 'purchases', (int)$purchaseId, $payload); // S35.T35.5
    $txStep('after pb_fin_apply');
    pb_tax_apply_doc($pdo, $customer, 'purchase', (int)$purchaseId, $payload); // S17.T17.1c
    $txStep('after pb_tax_apply_doc');
    pb_post_hook($pdo, $customer, 'purchase', $purchaseId); // S16.T16.3 (gold only)
    $txStep('after pb_post_hook');
    if ($editId <= 0) pb_appr_mark($pdo, $cid, $customer, 'purchase', (int)$purchaseId); // S35.T35.9e
    $txStep('after pb_appr_mark');
    if ($ownTx) { if ($pdo->inTransaction()) $pdo->commit(); else error_log('[purchases.create] transaction ended early (implicit commit by DDL inside the transaction?)'); } // hotfix
    return (int)$purchaseId;

}

/** اقلام فروش: وزن باید مثبت باشد و ردیف‌های تکراری یک خرید ادغام می‌شوند (وزن و گونی جمع، فی میانگین وزنی) */
function normalizeSaleItems($items, float $fallbackPrice): array {
    if (!is_array($items) || count($items) === 0) bad('حداقل یک قلم از انبار انتخاب کنید');
    if (count($items) > 300) bad('تعداد اقلام بیش از حد مجاز است');
    $m = [];
    foreach ($items as $it) {
        if (!is_array($it)) bad('قلم فروش نامعتبر است');
        $pid = (int)($it['purchase_id'] ?? 0);
        $w = num($it['weight'] ?? 0);
        if ($pid <= 0 || $w <= 0 || $w > 1e9) bad('وزن هر قلم فروش باید بزرگ‌تر از صفر باشد');
        $hasPrice = isset($it['item_price']) && $it['item_price'] !== '' && $it['item_price'] !== null;
        $price = $hasPrice ? num($it['item_price']) : $fallbackPrice;
        if ($price < 0 || $price > 1e13) bad('فی فروش نامعتبر است');
        $ss = nullableNum($it['small_sacks'] ?? null);
        $ls = nullableNum($it['large_sacks'] ?? null);
        if (($ss !== null && $ss < 0) || ($ls !== null && $ls < 0)) bad('تعداد گونی نامعتبر است');
        if (!isset($m[$pid])) $m[$pid] = ['purchase_id' => $pid, 'weight' => 0.0, 'val' => 0.0, 'hasPrice' => false, 'small_sacks' => null, 'large_sacks' => null];
        $m[$pid]['weight'] += $w;
        $m[$pid]['val'] += $w * $price;
        if ($hasPrice) $m[$pid]['hasPrice'] = true;
        if ($ss !== null) $m[$pid]['small_sacks'] = ($m[$pid]['small_sacks'] ?? 0) + $ss;
        if ($ls !== null) $m[$pid]['large_sacks'] = ($m[$pid]['large_sacks'] ?? 0) + $ls;
    }
    $res = [];
    foreach ($m as $r) {
        $res[] = ['purchase_id' => $r['purchase_id'], 'weight' => $r['weight'],
            'item_price' => $r['hasPrice'] ? $r['val'] / $r['weight'] : null,
            'small_sacks' => $r['small_sacks'], 'large_sacks' => $r['large_sacks']];
    }
    return $res;
}

/** پرداخت/دریافت را برای یک خرید یا فروش ثبت می‌کند (جایگزین رکورد قبلی در ویرایش) */
function payRows($v): array {
    if (!is_array($v)) return [];
    if (count($v) > 200) bad('تعداد ردیف‌های پرداخت/دریافت بیش از حد مجاز است');
    return array_values(array_filter($v, 'is_array'));
}

function savePayment(PDO $pdo, int $cid, int $purchaseId, array $p): void {
    $pdo->prepare('DELETE FROM lump_tx WHERE payment_id IN (SELECT id FROM payments WHERE purchase_id=? AND customer_id=?)')
        ->execute([$purchaseId, $cid]);
    $pdo->prepare('DELETE FROM credit_inst WHERE payment_id IN (SELECT id FROM payments WHERE purchase_id=? AND customer_id=?)')
        ->execute([$purchaseId, $cid]);
    $pdo->prepare('DELETE FROM cheque_inst WHERE payment_id IN (SELECT id FROM payments WHERE purchase_id=? AND customer_id=?)')
        ->execute([$purchaseId, $cid]);
    $pdo->prepare('DELETE FROM payments WHERE purchase_id=? AND customer_id=?')->execute([$purchaseId, $cid]);

    $payType = $p['pay_type'] ?? null;
    if (!$payType) return;
    $stagedType = $p['staged_type'] ?? null;
    if (!in_array($payType, ['lump', 'staged', 'mixed'], true) || !in_array($stagedType, [null, 'credit', 'cheque'], true)) bad('نوع پرداخت نامعتبر است');
    $ins = $pdo->prepare('INSERT INTO payments (customer_id, purchase_id, pay_type, staged_type) VALUES (?,?,?,?)');
    $ins->execute([$cid, $purchaseId, $payType, $stagedType]);
    $paymentId = (int)$pdo->lastInsertId();

    if ($payType === 'lump') {
        $st = $pdo->prepare('INSERT INTO lump_tx (customer_id, payment_id, from_account, payer_name, to_account, seller_name, amount, date, paid) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach (payRows($p['lump'] ?? []) as $t) {
            $st->execute([$cid, $paymentId, str_or_null($t['from_account'] ?? null, 150), str_or_null($t['payer_name'] ?? null, 150),
                str_or_null($t['to_account'] ?? null, 150), str_or_null($t['seller_name'] ?? null, 150), vamount($t['amount'] ?? 0),
                vdate($t['date'] ?? null), !empty($t['paid']) ? 1 : 0]);
            pb_fx_tx_stamp($pdo, $cid, 'lump_tx', (int)$pdo->lastInsertId(), $t, (string)(vdate($t['date'] ?? null) ?: date('Y-m-d'))); // S28.T28.2
        }
    } elseif ($payType === 'staged' && $stagedType === 'credit') {
        $st = $pdo->prepare('INSERT INTO credit_inst (customer_id, payment_id, due_date, amount, from_account, payer_name, to_account, seller_name, paid) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach (payRows($p['installments'] ?? []) as $t) {
            $st->execute([$cid, $paymentId, vdateReq($t['due_date'] ?? null), vamount($t['amount'] ?? 0), str_or_null($t['from_account'] ?? null, 150),
                str_or_null($t['payer_name'] ?? null, 150), str_or_null($t['to_account'] ?? null, 150), str_or_null($t['seller_name'] ?? null, 150), !empty($t['paid']) ? 1 : 0]);
            pb_fx_tx_stamp($pdo, $cid, 'credit_inst', (int)$pdo->lastInsertId(), $t, (string)vdateReq($t['due_date'] ?? null)); // S28.T28.2
        }
    } elseif ($payType === 'staged' && $stagedType === 'cheque') {
        $st = $pdo->prepare('INSERT INTO cheque_inst (customer_id, payment_id, due_date, amount, cheque_no, bank_name, from_account, payer_name, to_account, seller_name, paid) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach (payRows($p['installments'] ?? []) as $t) {
            $sayad = pb_pay_sayad_check($pdo, $t); // S35.T35.7c
            $st->execute([$cid, $paymentId, vdateReq($t['due_date'] ?? null), vamount($t['amount'] ?? 0), str_or_null($t['cheque_no'] ?? null, 60),
                str_or_null($t['bank_name'] ?? null, 100), str_or_null($t['from_account'] ?? null, 150), str_or_null($t['payer_name'] ?? null, 150),
                str_or_null($t['to_account'] ?? null, 150), str_or_null($t['seller_name'] ?? null, 150), !empty($t['paid']) ? 1 : 0]);
            pb_pay_sayad_set($pdo, $cid, 'cheque_inst', (int)$pdo->lastInsertId(), $sayad); // S35.T35.7c
            pb_fx_tx_stamp($pdo, $cid, 'cheque_inst', (int)$pdo->lastInsertId(), $t, (string)vdateReq($t['due_date'] ?? null)); // S28.T28.2
        }
    } elseif ($payType === 'mixed') {
        pb_mixed_insert_lines($pdo, $cid, $paymentId, $p); // S35.T35.7h
    }
    pb_treasury_map_legacy($pdo, $cid, true); // S15.T15.3b (gold only, best effort)
}

function saveReceipt(PDO $pdo, int $cid, int $saleId, array $p): void {
    $pdo->prepare('DELETE FROM receipt_lump_tx WHERE receipt_id IN (SELECT id FROM receipts WHERE sale_id=? AND customer_id=?)')
        ->execute([$saleId, $cid]);
    $pdo->prepare('DELETE FROM receipt_credit_inst WHERE receipt_id IN (SELECT id FROM receipts WHERE sale_id=? AND customer_id=?)')
        ->execute([$saleId, $cid]);
    $pdo->prepare('DELETE FROM receipt_cheque_inst WHERE receipt_id IN (SELECT id FROM receipts WHERE sale_id=? AND customer_id=?)')
        ->execute([$saleId, $cid]);
    $pdo->prepare('DELETE FROM receipts WHERE sale_id=? AND customer_id=?')->execute([$saleId, $cid]);

    $recvType = $p['recv_type'] ?? null;
    if (!$recvType) return;
    $stagedType = $p['staged_type'] ?? null;
    if (!in_array($recvType, ['lump', 'staged'], true) || !in_array($stagedType, [null, 'credit', 'cheque'], true)) bad('نوع دریافت نامعتبر است');
    $ins = $pdo->prepare('INSERT INTO receipts (customer_id, sale_id, recv_type, staged_type) VALUES (?,?,?,?)');
    $ins->execute([$cid, $saleId, $recvType, $stagedType]);
    $receiptId = (int)$pdo->lastInsertId();

    if ($recvType === 'lump') {
        $st = $pdo->prepare('INSERT INTO receipt_lump_tx (customer_id, receipt_id, from_account, payer_name, to_account, receiver_name, amount, date, paid) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach (payRows($p['lump'] ?? []) as $t) {
            $st->execute([$cid, $receiptId, str_or_null($t['from_account'] ?? null, 150), str_or_null($t['payer_name'] ?? null, 150),
                str_or_null($t['to_account'] ?? null, 150), str_or_null($t['receiver_name'] ?? null, 150), vamount($t['amount'] ?? 0),
                vdate($t['date'] ?? null), !empty($t['paid']) ? 1 : 0]);
            pb_fx_tx_stamp($pdo, $cid, 'receipt_lump_tx', (int)$pdo->lastInsertId(), $t, (string)(vdate($t['date'] ?? null) ?: date('Y-m-d'))); // S28.T28.2
        }
    } elseif ($recvType === 'staged' && $stagedType === 'credit') {
        $st = $pdo->prepare('INSERT INTO receipt_credit_inst (customer_id, receipt_id, due_date, amount, from_account, payer_name, to_account, receiver_name, paid) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach (payRows($p['installments'] ?? []) as $t) {
            $st->execute([$cid, $receiptId, vdateReq($t['due_date'] ?? null), vamount($t['amount'] ?? 0), str_or_null($t['from_account'] ?? null, 150),
                str_or_null($t['payer_name'] ?? null, 150), str_or_null($t['to_account'] ?? null, 150), str_or_null($t['receiver_name'] ?? null, 150), !empty($t['paid']) ? 1 : 0]);
            pb_fx_tx_stamp($pdo, $cid, 'receipt_credit_inst', (int)$pdo->lastInsertId(), $t, (string)vdateReq($t['due_date'] ?? null)); // S28.T28.2
        }
    } elseif ($recvType === 'staged' && $stagedType === 'cheque') {
        $st = $pdo->prepare('INSERT INTO receipt_cheque_inst (customer_id, receipt_id, due_date, amount, cheque_no, bank_name, from_account, payer_name, to_account, receiver_name, paid) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach (payRows($p['installments'] ?? []) as $t) {
            $sayad = pb_pay_sayad_check($pdo, $t); // S35.T35.7c
            $st->execute([$cid, $receiptId, vdateReq($t['due_date'] ?? null), vamount($t['amount'] ?? 0), str_or_null($t['cheque_no'] ?? null, 60),
                str_or_null($t['bank_name'] ?? null, 100), str_or_null($t['from_account'] ?? null, 150), str_or_null($t['payer_name'] ?? null, 150),
                str_or_null($t['to_account'] ?? null, 150), str_or_null($t['receiver_name'] ?? null, 150), !empty($t['paid']) ? 1 : 0]);
            pb_pay_sayad_set($pdo, $cid, 'receipt_cheque_inst', (int)$pdo->lastInsertId(), $sayad); // S35.T35.7c
            pb_fx_tx_stamp($pdo, $cid, 'receipt_cheque_inst', (int)$pdo->lastInsertId(), $t, (string)vdateReq($t['due_date'] ?? null)); // S28.T28.2
        }
    }
    pb_treasury_map_legacy($pdo, $cid, true); // S15.T15.3b
}

function partyKey($n): string {
    $n = (string)($n ?? '');
    $n = str_replace(['ي', 'ك'], ['ی', 'ک'], $n);
    $n = trim(preg_replace('/\s+/u', ' ', $n));
    return $n;
}

function seedDefaults(PDO $pdo, int $cid): void {
    $c = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE customer_id=?');
    $c->execute([$cid]);
    if ((int)$c->fetchColumn() > 0) return;
    $normal = ['اکبری', 'احمدآقایی', 'فندقی', 'کله‌قوچی'];
    $kernel = ['مغز کال', 'مغز درجه یک', 'مغز درجه دو'];
    $insCat = $pdo->prepare('INSERT INTO categories (customer_id, name, preset_key) VALUES (?,?,?)');
    $insTyp = $pdo->prepare('INSERT INTO types (customer_id, category_id, name) VALUES (?,?,?)');
    foreach ([['پسته خندان', 'khandan', $normal], ['پسته دنبست', 'dombest', $normal], ['مغز', 'kernel', $kernel]] as [$name, $key, $types]) {
        $insCat->execute([$cid, $name, $key]);
        $catId = (int)$pdo->lastInsertId();
        foreach ($types as $t) $insTyp->execute([$cid, $catId, $t]);
    }
}

/**
 * ستون‌های گونیِ باقی‌مانده (small_sacks_remaining / large_sacks_remaining) روی purchases.
 * اگر وجود نداشته باشند خودکار ساخته و برای داده‌های قدیمی محاسبه می‌شوند (اجرای دستی migration_v4 لازم نیست).
 */
function ensureSackColumns(PDO $pdo): void {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'small_sacks_remaining'");
        if ($st && $st->fetch()) return;
        $pdo->exec("ALTER TABLE purchases
            ADD COLUMN small_sacks_remaining DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER large_sack_count,
            ADD COLUMN large_sacks_remaining DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER small_sacks_remaining");
        backfillSacksRemaining($pdo, null);
    } catch (Throwable $e) {
        // درخواست همزمان دیگری همین ستون‌ها را ساخته باشد: بی‌خطر است
        error_log('[app.php] ensureSackColumns: ' . $e->getMessage());
    }
}

/**
 * گونی باقی‌مانده را برای داده‌های قدیمی از روی فروش‌ها/مخلوط‌های ثبت‌شده محاسبه می‌کند
 * (همان منطق تخمینیِ قبلی: اگر فروش/مخلوطی گونی ثبت نکرده، سهم گونی آن به نسبت وزن برآورد می‌شود).
 * $cid = null یعنی برای همهٔ کاربران.
 */
function backfillSacksRemaining(PDO $pdo, ?int $cid): void {
    $where = $cid === null ? '' : ' WHERE p.customer_id = ' . (int)$cid;
    $pdo->exec("UPDATE purchases p
        LEFT JOIN (
            SELECT purchase_id AS pid,
                SUM(CASE WHEN small_sacks IS NULL AND large_sacks IS NULL THEN weight ELSE 0 END) AS unk_w,
                SUM(COALESCE(small_sacks, 0)) AS ks,
                SUM(COALESCE(large_sacks, 0)) AS kl
            FROM (
                SELECT purchase_id, weight, small_sacks, large_sacks FROM sale_items
                UNION ALL
                SELECT source_purchase_id AS purchase_id, weight, small_sacks, large_sacks FROM mix_sources
            ) u GROUP BY purchase_id
        ) s ON s.pid = p.id
        SET p.small_sacks_remaining = GREATEST(0, ROUND(p.small_sack_count - COALESCE(s.ks, 0)
                - p.small_sack_count * IF(p.net_weight > 0, COALESCE(s.unk_w, 0) / p.net_weight, 0))),
            p.large_sacks_remaining = GREATEST(0, ROUND(p.large_sack_count - COALESCE(s.kl, 0)
                - p.large_sack_count * IF(p.net_weight > 0, COALESCE(s.unk_w, 0) / p.net_weight, 0)))" . $where);
}

/**
 * برای هر خرید: گونی باقی‌ماندهٔ ذخیره‌شده (small_left/large_left) + اینکه آیا مقداری از آن هنوز
 * داخل مخلوطی است که کامل فروخته نشده (in_mix_stock / in_mix_codes، به‌صورت بازگشتی برای مخلوطِ مخلوط).
 */
function attachSacksLeft(PDO $pdo, int $cid, array $rows): array {
    if (!$rows) return $rows;
    $mixSt = $pdo->prepare('SELECT ms.source_purchase_id AS src, ms.mix_purchase_id AS mix_id FROM mix_sources ms WHERE ms.customer_id=?');
    $mixSt->execute([$cid]);
    $childrenOf = [];
    $mixLinks = $mixSt->fetchAll();
    foreach ($mixLinks as $r) $childrenOf[(int)$r['src']][] = (int)$r['mix_id'];
    $groupKeys = pb_mix_group_keys($rows, array_map(static fn($l) => ['mix_id' => $l['mix_id'], 'source_id' => $l['src']], $mixLinks)); // T3a: lotId ⇒ کلید گروه مخلوط یا null
    $alive = [];
    if ($childrenOf) {
        $aliveSt = $pdo->prepare('SELECT id, code FROM purchases WHERE customer_id=? AND is_mix=1 AND remaining > 0.000001');
        $aliveSt->execute([$cid]);
        foreach ($aliveSt->fetchAll() as $r) $alive[(int)$r['id']] = (string)$r['code'];
    }
    foreach ($rows as &$r) {
        $r['small_left'] = round((float)($r['small_sacks_remaining'] ?? 0));
        $r['large_left'] = round((float)($r['large_sacks_remaining'] ?? 0));
        $r['sacks_estimated'] = false;
        $codes = []; $seen = []; $stack = $childrenOf[(int)$r['id']] ?? [];
        while ($stack) {
            $m = array_pop($stack);
            if (isset($seen[$m])) continue;
            $seen[$m] = true;
            if (isset($alive[$m])) $codes[] = $alive[$m];
            foreach ($childrenOf[$m] ?? [] as $c2) $stack[] = $c2;
        }
        $r['in_mix_codes'] = $codes;
        $r['in_mix_stock'] = count($codes) > 0;
        $r['mix_group'] = $groupKeys[(int)$r['id']] ?? null; // T3a: فیلد افزایشی؛ کلاینت قدیمی نادیده می‌گیرد
    }
    unset($r);
    return $rows;
}

/** یک قلم فروش (هر فروش فقط یک purchase_id). فرمت قدیمی items با دقیقاً یک ردیف هم پذیرفته می‌شود (صف آفلاین نسخهٔ قبل). */
function readSingleSaleItem(array $payload, float $fallbackPrice): array {
    $src = $payload;
    if (!isset($payload['purchase_id'])) {
        $items = $payload['items'] ?? null;
        if (!is_array($items) || count($items) !== 1 || !is_array($items[0])) {
            bad('هر فروش فقط می‌تواند یک قلم از انبار داشته باشد؛ برای چند جنس، چند فروش جداگانه ثبت کنید');
        }
        $src = $items[0];
    }
    $pid = (int)($src['purchase_id'] ?? 0);
    $w = num($src['weight'] ?? 0);
    if ($pid <= 0) bad('جنس فروخته‌شده را از انبار انتخاب کنید');
    if ($w <= 0 || $w > 1e9) bad('وزن فروش باید بزرگ‌تر از صفر باشد');
    $hasPrice = isset($src['item_price']) && $src['item_price'] !== '' && $src['item_price'] !== null;
    $price = $hasPrice ? num($src['item_price']) : $fallbackPrice;
    if ($price <= 0 || $price > 1e13) bad('فی فروش نامعتبر است');
    $ss = nullableNum($src['small_sacks'] ?? null);
    $ls = nullableNum($src['large_sacks'] ?? null);
    if (($ss !== null && ($ss < 0 || $ss > 1e7)) || ($ls !== null && ($ls < 0 || $ls > 1e7))) bad('تعداد گونی نامعتبر است');
    return ['purchase_id' => $pid, 'weight' => $w, 'price' => $price, 'small_sacks' => $ss, 'large_sacks' => $ls];
}

/** گونی و وزن مصرف‌شدهٔ قبلی را به خرید برمی‌گرداند (گونی هرگز از تعداد خریداری‌شده بیشتر نمی‌شود) */
function restoreStock(PDO $pdo, int $cid, int $pid, float $weight, $small, $large): void {
    $pdo->prepare('UPDATE purchases SET remaining = remaining + ?,
            small_sacks_remaining = LEAST(small_sack_count, small_sacks_remaining + ?),
            large_sacks_remaining = LEAST(large_sack_count, large_sacks_remaining + ?)
        WHERE id=? AND customer_id=?')
        ->execute([$weight, $small === null ? 0 : (float)$small, $large === null ? 0 : (float)$large, $pid, $cid]);
}

/** اعتبارسنجی گونیِ درخواستی در برابر گونی باقی‌ماندهٔ همان خرید؛ کوچک و بزرگ جدا از هم */
function checkSacksAvailable(PDO $pdo, array $row, $small, $large): void {
    if ($small !== null && $small - (float)$row['small_sacks_remaining'] > 1e-6) {
        $pdo->rollBack();
        bad('گونی کوچک درخواستی (' . rtrim(rtrim(number_format($small, 2, '.', ''), '0'), '.') . ') از گونی کوچک باقی‌ماندهٔ این خرید (' . rtrim(rtrim(number_format((float)$row['small_sacks_remaining'], 2, '.', ''), '0'), '.') . ') بیشتر است');
    }
    if ($large !== null && $large - (float)$row['large_sacks_remaining'] > 1e-6) {
        $pdo->rollBack();
        bad('گونی بزرگ درخواستی (' . rtrim(rtrim(number_format($large, 2, '.', ''), '0'), '.') . ') از گونی بزرگ باقی‌ماندهٔ این خرید (' . rtrim(rtrim(number_format((float)$row['large_sacks_remaining'], 2, '.', ''), '0'), '.') . ') بیشتر است');
    }
}

/** مبدأهای مخلوط: وزن منفی مجاز نیست و ردیف‌های تکراری یک خرید ادغام می‌شوند */
function normalizeMixSources($sources): array {
    if (!is_array($sources) || count($sources) < 1) bad('حداقل یک جنس مبدأ را انتخاب کنید');
    if (count($sources) > 300) bad('تعداد مبدأها بیش از حد مجاز است');
    $m = [];
    foreach ($sources as $s) {
        if (!is_array($s)) bad('مبدأ نامعتبر است');
        $pid = (int)($s['purchase_id'] ?? 0);
        $w = num($s['weight'] ?? 0);
        $ss = nullableNum($s['small_sacks'] ?? null);
        $ls = nullableNum($s['large_sacks'] ?? null);
        if ($w < 0 || $w > 1e9 || ($ss !== null && $ss < 0) || ($ls !== null && $ls < 0)) bad('وزن یا گونی مبدأ نامعتبر است');
        if (!isset($m[$pid])) $m[$pid] = ['purchase_id' => $pid, 'weight' => 0.0, 'small_sacks' => null, 'large_sacks' => null, 'warehouse_uid' => null];
        if ($m[$pid]['warehouse_uid'] === null && !empty($s['warehouse_uid']) && is_string($s['warehouse_uid'])) $m[$pid]['warehouse_uid'] = $s['warehouse_uid']; // S01.T01.3 (first one wins when a lot is listed twice)
        $m[$pid]['weight'] += $w;
        if ($ss !== null) $m[$pid]['small_sacks'] = ($m[$pid]['small_sacks'] ?? 0) + $ss;
        if ($ls !== null) $m[$pid]['large_sacks'] = ($m[$pid]['large_sacks'] ?? 0) + $ls;
    }
    return array_values($m);
}

/** مشخصات کامل جدول‌های دادهٔ کاربر برای پشتیبان‌گیری/بازگردانی؛ ترتیب = والد→فرزند */
const BACKUP_TABLES = [
    'categories' => ['id', 'customer_id', 'name', 'preset_key'],
    'types' => ['id', 'customer_id', 'category_id', 'name'],
    'purchases' => ['id', 'customer_id', 'code', 'category_id', 'type_name', 'date', 'ounce', 'price', 'total_weight',
        'small_sack_count', 'large_sack_count', 'small_sacks_remaining', 'large_sacks_remaining', 'net_weight', 'remaining', 'gram', 'pct_dombest', 'pct_tuchin',
        'pct_crooked', 'pct_khandan', 'custom_specs', 'photo', 'seller', 'note', 'is_mix', 'created_at', 'usd_rate', 'price_usd', 'waste', 'base_type', 'labor_cost', 'transport_cost', 'warehouse_uid', 'ounce_uid', 'ccy', 'fx_rate', 'price_ccy', 'fx_rate_source', 'fx_rate_entered_at', 'gross_kg', 'tare_kg', 'quality_deduct_kg', 'weigh_ticket_no', 'vehicle_plate', 'driver_name', 'discount_amount', 'tax_amount', 'doc_status', 'side_cost_payer', 'taxable', 'weighbridge_cost'], // T27b.3b: fx cols; S35.T35.1: form cols (absent in old backups -> NULL) (absent in old backups -> NULL = rial doc)
    'mix_sources' => ['id', 'customer_id', 'mix_purchase_id', 'source_purchase_id', 'weight', 'small_sacks', 'large_sacks', 'warehouse_uid'],
    'payments' => ['id', 'customer_id', 'purchase_id', 'pay_type', 'staged_type'],
    'lump_tx' => ['id', 'customer_id', 'payment_id', 'from_account', 'payer_name', 'to_account', 'seller_name', 'amount', 'date', 'paid', 'ccy', 'ccy_amount', 'fx_rate', 'credit_used'],
    'credit_inst' => ['id', 'customer_id', 'payment_id', 'due_date', 'amount', 'from_account', 'payer_name', 'to_account', 'seller_name', 'paid', 'ccy', 'ccy_amount', 'fx_rate', 'credit_used'],
    'cheque_inst' => ['id', 'customer_id', 'payment_id', 'due_date', 'amount', 'cheque_no', 'sayad_id', 'bank_name', 'from_account', 'payer_name', 'to_account', 'seller_name', 'paid', 'ccy', 'ccy_amount', 'fx_rate', 'credit_used'],
    'sales' => ['id', 'customer_id', 'date', 'price', 'total_weight', 'small_sack_count', 'large_sack_count', 'net_weight', 'buyer', 'note', 'created_at', 'usd_rate', 'labor_cost', 'transport_cost', 'ccy', 'fx_rate', 'total_ccy', 'fx_rate_source', 'fx_rate_entered_at', 'gross_kg', 'tare_kg', 'quality_deduct_kg', 'weigh_ticket_no', 'vehicle_plate', 'driver_name', 'discount_amount', 'tax_amount', 'doc_status', 'side_cost_payer', 'weighbridge_cost'], // T27b.3b; S35.T35.1
    'sale_items' => ['id', 'customer_id', 'sale_id', 'purchase_id', 'weight', 'item_price', 'small_sacks', 'large_sacks', 'warehouse_uid', 'taxable'], // S35.T35.1: taxable
    'receipts' => ['id', 'customer_id', 'sale_id', 'recv_type', 'staged_type'],
    'receipt_lump_tx' => ['id', 'customer_id', 'receipt_id', 'from_account', 'payer_name', 'to_account', 'receiver_name', 'amount', 'date', 'paid', 'ccy', 'ccy_amount', 'fx_rate', 'credit_used'],
    'receipt_credit_inst' => ['id', 'customer_id', 'receipt_id', 'due_date', 'amount', 'from_account', 'payer_name', 'to_account', 'receiver_name', 'paid', 'ccy', 'ccy_amount', 'fx_rate', 'credit_used'],
    'receipt_cheque_inst' => ['id', 'customer_id', 'receipt_id', 'due_date', 'amount', 'cheque_no', 'sayad_id', 'bank_name', 'from_account', 'payer_name', 'to_account', 'receiver_name', 'paid', 'ccy', 'ccy_amount', 'fx_rate', 'credit_used'],
    'parties' => ['id', 'customer_id', 'name', 'types', 'phone', 'address', 'national_id', 'economic_code', 'postal_code', 'credit_limit', 'opening_balance', 'opening_side', 'active', 'note', 'tags', 'stage'], // T03.1/T03.3: extra cols optional in old backups
    'loan_entries' => ['id', 'customer_id', 'uid', 'party', 'kind', 'amount', 'date', 'note', 'created_at'],
    'loan_dues' => ['id', 'customer_id', 'loan_uid', 'seq', 'due_date', 'amount', 'note', 'paid'],
    'stock_transfers' => ['id', 'customer_id', 'uid', 'lot_id', 'from_warehouse_uid', 'to_warehouse_uid', 'qty_kg', 'small_sacks', 'large_sacks', 'move_date', 'note', 'cancelled', 'created_at'],
    'purchase_returns' => ['id', 'customer_id', 'uid', 'number', 'party', 'date', 'refund_mode', 'refund_amount', 'cheque_no', 'cheque_due', 'note', 'tax_amount', 'created_at'], // S04.T04.1
    'purchase_return_items' => ['id', 'customer_id', 'return_id', 'lot_id', 'warehouse_uid', 'kg', 'small_sacks', 'large_sacks', 'unit_price'],
    'sale_returns' => ['id', 'customer_id', 'uid', 'number', 'party', 'date', 'refund_mode', 'refund_amount', 'cheque_no', 'cheque_due', 'note', 'tax_amount', 'created_at'], // S04.T04.2
    'sale_return_items' => ['id', 'customer_id', 'return_id', 'sale_id', 'lot_id', 'warehouse_uid', 'kg', 'small_sacks', 'large_sacks', 'unit_price'],
    'separations' => ['id', 'customer_id', 'uid', 'sep_date', 'note', 'labor_cost', 'transport_cost', 'waste_kg', 'cost_alloc', 'warehouse_uid', 'input_kg', 'input_cost', 'abnormal_kg', 'abnormal_cost', 'created_at'], // S07.T07.1
    'separation_inputs' => ['id', 'customer_id', 'separation_id', 'lot_id', 'kg', 'small_sacks', 'large_sacks', 'warehouse_uid', 'unit_cost', 'cost'],
    'separation_outputs' => ['id', 'customer_id', 'separation_id', 'lot_id', 'type_name', 'ounce_uid', 'kg', 'small_sacks', 'large_sacks', 'alloc_price', 'cost'],
    'lot_lineage' => ['id', 'customer_id', 'separation_id', 'parent_lot_id', 'child_lot_id', 'parent_kg'],
    'letters_of_credit' => ['id', 'customer_id', 'sale_id', 'lc_no', 'issuing_bank', 'advising_bank', 'ccy_code', 'amount', 'open_date', 'expiry_date', 'latest_ship_date', 'presentation_days', 'status', 'ship_date', 'presented_date', 'note', 'created_at', 'updated_at'], // S31.T31.12
    'sale_export' => ['id', 'customer_id', 'sale_id', 'incoterm', 'ship_method', 'port_loading', 'port_destination', 'ccy_code', 'remit_no', 'lc_bank', 'lc_no', 'pkg_count', 'pkg_type', 'gross_kg', 'marks', 'origin_country', 'hs_code', 'cert_no', 'updated_at'],
    'proc_orders' => ['id', 'customer_id', 'uid', 'direction', 'party', 'order_date', 'service', 'fee_basis', 'fee_value', 'fee_amount', 'standard_yield_pct', 'waste_charge_per_kg', 'charge_amount', 'status', 'warehouse_uid', 'input_kg', 'input_cost', 'output_kg', 'waste_kg', 'yield_pct', 'receive_date', 'deliver_date', 'note', 'op_type', 'owner', 'pistachio_type', 'defect_note', 'initial_kg', 'bale_count', 'loss_kg', 'balance_diff_kg', 'balance_ok', 'undelivered_kg', 'price_per_kg', 'wage_per_kg', 'other_cost', 'price_deformed', 'price_discolored', 'price_hull', 'price_soil', 'price_kernel', 'value_in', 'cost_total', 'recovery_value', 'cost_sound', 'cost_per_kg_sound', 'sent_gram_pct', 'recv_kernel_kg', 'recv_gram_pct', 'receipt_no', 'cash_note', 'non_company_note', 'created_at'], // S09.T09.1 + کارگاه v64/v65/v67
    'proc_inputs' => ['id', 'customer_id', 'order_id', 'lot_id', 'kg', 'small_sacks', 'large_sacks', 'warehouse_uid', 'unit_cost', 'cost'],
    'proc_outputs' => ['id', 'customer_id', 'order_id', 'lot_id', 'type_name', 'kg', 'small_sacks', 'large_sacks', 'cost', 'warehouse_uid', 'grade', 'owner', 'source_lot_id'],
    'crack_runs' => ['id', 'customer_id', 'uid', 'run_date', 'end_date', 'shift', 'operator_name', 'customer_name', 'line_code', 'pistachio_type', 'customer_code', 'shipment_code', 'input_kg', 'ounce_by', 'start_time', 'end_time', 'machine_setting', 'analyst', 'bale_count', 'gram_pct', 'moisture_pct', 'fines_pct', 'run_minutes', 'kernel_kg', 'hull_kg', 'soil_kg', 'hull_left_kg', 'sound_kg', 'wheat_kg', 'super_fine_kg', 'flower_kg', 'split_kg', 'unbreakable_kg', 'pink_kg', 'waste_kernel_kg', 'initial_kernel_kg', 'power_kwh', 'note', 'created_by', 'created_by_name', 'created_at'], // v66 گزارش خط شکست
    'crack_stops' => ['id', 'customer_id', 'run_id', 'reason', 'minutes'],
    'crack_run_lots' => ['id', 'customer_id', 'run_id', 'field_key', 'lot_id', 'source_lot_id', 'source_warehouse_uid', 'kg', 'created_at'], // S5: لات‌های ساخته‌شده از خروجی خط شکست
    'crack_pools' => ['id', 'customer_id', 'run_id', 'source_lot_id', 'consume_mode', 'input_g', 'source_cost_rial', 'extra_cost_rial', 'status', 'closed_at', 'closed_by', 'backfilled', 'created_at'], // CP1
    'feed_adjust' => ['id', 'customer_id', 'uid', 'adj_date', 'kind', 'owner', 'workshop', 'receipt_no', 'kg', 'reason', 'created_by', 'created_by_name', 'created_at'], // v68 موجودی پستهٔ خرجی
    'employees' => ['id', 'customer_id', 'uid', 'name', 'position', 'hire_date', 'base_salary', 'ccy', 'pay_schedule', 'bank_account', 'insurance_no', 'active', 'note', 'created_at'], // S11.T11.1
    'pay_items' => ['id', 'customer_id', 'uid', 'name', 'kind', 'taxable', 'insurable', 'formula', 'default_value', 'created_at'],
    'payroll_runs' => ['id', 'customer_id', 'uid', 'year', 'month', 'ccy', 'status', 'approved_at', 'created_at'],
    'payroll_lines' => ['id', 'customer_id', 'run_id', 'employee_id', 'base', 'overtime', 'allowances', 'earnings', 'deductions', 'other_deductions', 'advances_deducted', 'net', 'ccy'],
    'payroll_line_items' => ['id', 'customer_id', 'line_id', 'pay_item_id', 'name', 'kind', 'amount'],
    'employee_advances' => ['id', 'customer_id', 'uid', 'employee_id', 'adv_date', 'amount', 'deducted', 'ccy', 'note', 'created_at'],
    'payroll_params' => ['id', 'customer_id', 'fy', 'employee_ins_rate', 'employer_ins_rate', 'unemployment_share_rate', 'overtime_multiplier', 'min_wage', 'exemption', 'tax_brackets', 'source', 'source_date', 'updated_at'], // S11.T11.3
    'accounts' => ['id', 'customer_id', 'code', 'name', 'level', 'parent_id', 'nature', 'type', 'is_system', 'system_role', 'active', 'created_at'], // S15.T15.2
    'account_roles' => ['id', 'customer_id', 'role', 'account_id'],
    'tafsili' => ['id', 'customer_id', 'kind', 'ref_key', 'code', 'name', 'active', 'created_at'],
    'vouchers' => ['id', 'customer_id', 'fiscal_year_id', 'number', 'voucher_date', 'description', 'status', 'source_type', 'source_id', 'total', 'created_by', 'created_at', 'updated_at'], // S16.T16.1
    'voucher_lines' => ['id', 'customer_id', 'voucher_id', 'line_no', 'account_id', 'tafsili_id', 'cost_center_id', 'debit', 'credit', 'description', 'ccy', 'ccy_amount', 'rate'],
    'fiscal_years' => ['id', 'customer_id', 'name', 'start_date', 'end_date', 'status', 'vat_enabled', 'vat_rate', 'vat_levy_rate', 'vat_note', 'vat_source', 'is_current', 'created_at'], // S15.T15.1
    'import_batches' => ['id', 'customer_id', 'batch_id', 'kind', 'all_or_nothing', 'total_rows', 'created_n', 'updated_n', 'skipped_n', 'failed_n', 'result', 'created_at'], // S13.T13.2b
    'payroll_line_stat' => ['customer_id', 'line_id', 'fy', 'insurable_base', 'taxable_base', 'ins_employee', 'ins_employer', 'exemption', 'taxable', 'tax'],
    'payroll_pay_lines' => ['id', 'customer_id', 'uid', 'line_id', 'ccy', 'amount', 'net_part', 'due_date', 'method', 'cheque_ref', 'paid', 'paid_date', 'created_at'], // S11.T11.2
    'orchards' => ['id', 'customer_id', 'uid', 'name', 'location', 'area_ha', 'tree_count', 'variety', 'ownership', 'note', 'cost_center_id', 'created_at'], // S10.T10.1
    'orchard_costs' => ['id', 'customer_id', 'uid', 'orchard_id', 'season_year', 'category', 'amount', 'cost_date', 'party', 'note', 'created_at'],
    'harvests' => ['id', 'customer_id', 'uid', 'orchard_id', 'season_year', 'harvest_date', 'wet_kg', 'dry_kg', 'lot_id', 'cost', 'note', 'created_at'],
    'orchard_allocations' => ['id', 'customer_id', 'orchard_id', 'season_year', 'basis', 'market_per_kg', 'pool_cost', 'allocated_cost', 'residual', 'dry_kg', 'created_at'],
    'consignments' => ['id', 'customer_id', 'uid', 'direction', 'party', 'cons_date', 'warehouse_uid', 'settlement_mode', 'commission_type', 'commission_value', 'agreed_price', 'status', 'note', 'created_at'], // S08.T08.1
    'consignment_items' => ['id', 'customer_id', 'consignment_id', 'lot_id', 'kg', 'small_sacks', 'large_sacks', 'warehouse_uid'], // warehouse_uid: S08.T08.2 (absent in older backups)
    'consignment_returns' => ['id', 'customer_id', 'uid', 'consignment_id', 'lot_id', 'kg', 'small_sacks', 'large_sacks', 'warehouse_uid', 'ret_date', 'note', 'created_at'],
    'consignment_payments' => ['id', 'customer_id', 'uid', 'consignment_id', 'pay_date', 'amount', 'note', 'created_at'],
    'consignment_sales' => ['id', 'customer_id', 'uid', 'consignment_id', 'sale_id', 'lot_id', 'kg', 'sold_date', 'created_at'], // S08.T08.2
    'grade_prices' => ['id', 'customer_id', 'type_name', 'ounce_uid', 'grade_key', 'price_per_kg', 'price_date'], // S35.T35.4
    'doc_grade_lines' => ['id', 'customer_id', 'doc_type', 'doc_id', 'grade_key', 'kg', 'unit_price'], // S35.T35.3b: polymorphic doc_id (purchase|sale), remapped on import
];
/** جدول‌هایی که در پشتیبان‌های قدیمی نیستند و نبودنشان خطا نیست (خالی در نظر گرفته می‌شوند) */
const BACKUP_OPTIONAL = ['grade_prices', 'doc_grade_lines', 'parties', 'loan_entries', 'loan_dues', 'stock_transfers', 'purchase_returns', 'purchase_return_items', 'sale_returns', 'sale_return_items', 'separations', 'separation_inputs', 'separation_outputs', 'lot_lineage', 'consignments', 'consignment_items', 'consignment_returns', 'consignment_payments', 'consignment_sales', 'proc_orders', 'proc_inputs', 'proc_outputs', 'crack_runs', 'crack_stops', 'crack_run_lots', 'crack_pools', 'feed_adjust', 'orchards', 'orchard_costs', 'harvests', 'orchard_allocations', 'employees', 'pay_items', 'payroll_runs', 'payroll_lines', 'payroll_line_items', 'employee_advances', 'payroll_pay_lines', 'payroll_params', 'payroll_line_stat', 'import_batches', 'fiscal_years', 'accounts', 'account_roles', 'tafsili', 'vouchers', 'voucher_lines', 'sale_export', 'letters_of_credit'];

/** ارجاع‌های بین جدول‌ها: جدول => [ستون => جدول والد] */
const BACKUP_REFS = [
    'types' => ['category_id' => 'categories'],
    'purchases' => ['category_id' => 'categories'],
    'mix_sources' => ['mix_purchase_id' => 'purchases', 'source_purchase_id' => 'purchases'],
    'payments' => ['purchase_id' => 'purchases'],
    'lump_tx' => ['payment_id' => 'payments'],
    'credit_inst' => ['payment_id' => 'payments'],
    'cheque_inst' => ['payment_id' => 'payments'],
    'sale_items' => ['sale_id' => 'sales', 'purchase_id' => 'purchases'],
    'receipts' => ['sale_id' => 'sales'],
    'receipt_lump_tx' => ['receipt_id' => 'receipts'],
    'receipt_credit_inst' => ['receipt_id' => 'receipts'],
    'receipt_cheque_inst' => ['receipt_id' => 'receipts'],
    'stock_transfers' => ['lot_id' => 'purchases'],
    'purchase_return_items' => ['return_id' => 'purchase_returns', 'lot_id' => 'purchases'],
    'sale_return_items' => ['return_id' => 'sale_returns', 'sale_id' => 'sales', 'lot_id' => 'purchases'],
    'separation_inputs' => ['separation_id' => 'separations', 'lot_id' => 'purchases'],
    'separation_outputs' => ['separation_id' => 'separations', 'lot_id' => 'purchases'],
    'lot_lineage' => ['separation_id' => 'separations', 'parent_lot_id' => 'purchases', 'child_lot_id' => 'purchases'],
    'sale_export' => ['sale_id' => 'sales'],
    'letters_of_credit' => ['sale_id' => 'sales'], // S31.T31.12
    'consignment_items' => ['consignment_id' => 'consignments', 'lot_id' => 'purchases'],
    'consignment_returns' => ['consignment_id' => 'consignments', 'lot_id' => 'purchases'],
    'consignment_payments' => ['consignment_id' => 'consignments'],
    'consignment_sales' => ['consignment_id' => 'consignments', 'sale_id' => 'sales', 'lot_id' => 'purchases'],
    'proc_inputs' => ['order_id' => 'proc_orders', 'lot_id' => 'purchases'], // S09.T09.1
    'proc_outputs' => ['order_id' => 'proc_orders', 'lot_id' => 'purchases', 'source_lot_id' => 'purchases'],
    'crack_stops' => ['run_id' => 'crack_runs'], // v66
    'crack_run_lots' => ['run_id' => 'crack_runs', 'lot_id' => 'purchases', 'source_lot_id' => 'purchases'], // S5
    'crack_pools' => ['run_id' => 'crack_runs', 'source_lot_id' => 'purchases'], // CP1
    'orchard_costs' => ['orchard_id' => 'orchards'], 'harvests' => ['orchard_id' => 'orchards', 'lot_id' => 'purchases'], 'orchard_allocations' => ['orchard_id' => 'orchards'], // S10.T10.1
    'payroll_lines' => ['run_id' => 'payroll_runs', 'employee_id' => 'employees'], 'payroll_line_items' => ['line_id' => 'payroll_lines', 'pay_item_id' => 'pay_items'], 'employee_advances' => ['employee_id' => 'employees'], 'payroll_pay_lines' => ['line_id' => 'payroll_lines'], 'payroll_line_stat' => ['line_id' => 'payroll_lines'], // S11.T11.1-3
];
/** ستون‌های اجباری (NOT NULL بدون پیش‌فرض) */
const BACKUP_REQUIRED = [
    'categories' => ['name'], 'types' => ['category_id', 'name'],
    'purchases' => ['category_id', 'type_name', 'date', 'price', 'total_weight', 'net_weight', 'remaining'],
    'mix_sources' => ['mix_purchase_id', 'source_purchase_id', 'weight'],
    'payments' => ['purchase_id', 'pay_type'],
    'lump_tx' => ['payment_id', 'amount', 'date'], 'credit_inst' => ['payment_id', 'due_date', 'amount'],
    'cheque_inst' => ['payment_id', 'due_date', 'amount'],
    'sales' => ['date', 'price', 'total_weight', 'net_weight'], 'sale_items' => ['sale_id', 'purchase_id', 'weight'],
    'receipts' => ['sale_id', 'recv_type'],
    'receipt_lump_tx' => ['receipt_id', 'amount', 'date'], 'receipt_credit_inst' => ['receipt_id', 'due_date', 'amount'],
    'receipt_cheque_inst' => ['receipt_id', 'due_date', 'amount'],
    'parties' => ['name'], 'loan_entries' => ['uid', 'party', 'kind', 'amount', 'date'],
    'loan_dues' => ['loan_uid', 'seq', 'due_date', 'amount'],
    'stock_transfers' => ['uid', 'lot_id', 'from_warehouse_uid', 'to_warehouse_uid', 'qty_kg', 'move_date'],
    'purchase_returns' => ['uid', 'number', 'party', 'date'], 'purchase_return_items' => ['return_id', 'lot_id', 'kg', 'unit_price'],
    'sale_returns' => ['uid', 'number', 'party', 'date'], 'sale_return_items' => ['return_id', 'sale_id', 'lot_id', 'kg', 'unit_price'],
    'separations' => ['sep_date'], 'separation_inputs' => ['separation_id', 'lot_id', 'kg'], 'separation_outputs' => ['separation_id', 'lot_id', 'type_name', 'kg'],
    'lot_lineage' => ['separation_id', 'parent_lot_id', 'child_lot_id'],
    'sale_export' => ['sale_id'],
    'orchards' => ['name'], 'employees' => ['name'], 'pay_items' => ['name'], 'payroll_runs' => ['year', 'month', 'ccy'], 'orchard_costs' => ['orchard_id', 'season_year', 'category', 'amount'], 'harvests' => ['orchard_id', 'season_year', 'dry_kg'], 'orchard_allocations' => ['orchard_id', 'season_year'],
    'proc_orders' => ['uid'], 'proc_inputs' => ['order_id', 'lot_id', 'kg'], 'proc_outputs' => ['order_id', 'lot_id', 'type_name', 'kg'], // S09.T09.1
    'crack_runs' => ['run_date'], 'crack_stops' => ['run_id'], // v66
    'crack_run_lots' => ['run_id', 'field_key', 'lot_id', 'source_lot_id', 'kg'], // S5
    'crack_pools' => ['run_id', 'source_lot_id', 'consume_mode', 'input_g'], // CP1
    'feed_adjust' => ['adj_date', 'kind', 'kg'], // v68
];
const BACKUP_MAXLEN = ['name' => 100, 'preset_key' => 50, 'code' => 40, 'type_name' => 100, 'seller' => 150, 'buyer' => 150,
    'note' => 500, 'types' => 200, 'phone' => 30, 'address' => 300, 'national_id' => 20, 'economic_code' => 20, 'postal_code' => 20, 'opening_side' => 10, 'tags' => 300, 'stage' => 20, 'from_account' => 150, 'payer_name' => 150, 'to_account' => 150, 'seller_name' => 150, 'receiver_name' => 150,
    'cheque_no' => 60, 'bank_name' => 100, 'custom_specs' => 4000, 'pay_type' => 10, 'recv_type' => 10, 'staged_type' => 10,
    'uid' => 40, 'warehouse_uid' => 36, 'from_warehouse_uid' => 36, 'to_warehouse_uid' => 36, 'ounce_uid' => 36, 'party' => 150, 'kind' => 12, 'base_type' => 100, 'loan_uid' => 40, 'ccy' => 8, 'fx_rate_source' => 10, 'fx_rate_entered_at' => 30,
    'owner' => 150, 'field_key' => 30, 'source_warehouse_uid' => 36, 'shift' => 30, 'operator_name' => 100, 'customer_name' => 150, 'line_code' => 40, 'customer_code' => 40, 'shipment_code' => 40, 'ounce_by' => 100, 'machine_setting' => 100, 'analyst' => 100, 'reason' => 150, 'created_by_name' => 100, 'start_time' => 8, 'end_time' => 8, 'pistachio_type' => 100, 'defect_note' => 255, 'op_type' => 20, 'grade' => 20,
    'receipt_no' => 60, 'cash_note' => 150, 'non_company_note' => 150, 'weigh_ticket_no' => 40, 'vehicle_plate' => 20, 'driver_name' => 80, 'doc_status' => 10, 'side_cost_payer' => 10]; // S35.T35.1 // T27b.3b: match VARCHAR(8/10/30) in migrations v47/v48
const BACKUP_NUMERIC = ['doc_id', 'kg', 'unit_price', 'ccy_amount', 'credit_used', 'fx_rate', 'ounce', 'price', 'total_weight', 'small_sack_count', 'large_sack_count', 'small_sacks_remaining', 'large_sacks_remaining', 'net_weight', 'remaining', 'gram',
    'pct_dombest', 'pct_tuchin', 'pct_crooked', 'pct_khandan', 'weight', 'qty_kg', 'small_sacks', 'large_sacks', 'item_price', 'amount', 'usd_rate', 'price_usd', 'waste', 'labor_cost', 'transport_cost', 'credit_limit', 'opening_balance', 'number', 'kg', 'unit_price', 'refund_amount', 'tax_amount',
    'waste_kg', 'input_kg', 'input_cost', 'abnormal_kg', 'abnormal_cost', 'cost', 'alloc_price', 'unit_cost', 'parent_kg', 'fx_rate', 'price_ccy', 'total_ccy', 'gross_kg', 'tare_kg', 'quality_deduct_kg', 'discount_amount', 'price_per_kg', 'weighbridge_cost', 'initial_kg', 'bale_count', 'loss_kg',
    'gram_pct', 'moisture_pct', 'fines_pct', 'run_minutes', 'kernel_kg', 'hull_kg', 'soil_kg', 'hull_left_kg', 'sound_kg', 'wheat_kg', 'super_fine_kg', 'flower_kg', 'split_kg', 'unbreakable_kg', 'pink_kg', 'waste_kernel_kg', 'initial_kernel_kg', 'power_kwh', 'minutes', 'created_by', // v66 خط شکست
    'sent_gram_pct', 'recv_kernel_kg', 'recv_gram_pct', 'wage_per_kg', 'other_cost', 'price_deformed', 'price_discolored', 'price_hull', 'price_soil', 'price_kernel', 'value_in', 'cost_total', 'recovery_value']; // v65 (cost_sound/cost_per_kg_sound منفی‌پذیرند ⇒ عمداً نیامده)؛ T27b.3b; کارگاه v64 (balance_diff_kg/undelivered_kg منفی‌پذیرند ⇒ عمداً عددی-نامنفی نیستند); S35.T35.1 (tax_amount already listed)

/** ستون‌های «فقط خروجی»: نسخهٔ محلی اپ به آن‌ها نیاز دارد (انتخاب مالیاتی سند طلایی، فاکتور رسمی) ولی واردکردن پشتیبان نمی‌خواهد؛ فقط اگر ستون وجود داشته باشد */
function pb_backup_export_cols(PDO $pdo, string $table, array $cols): array {
    if ($table !== 'purchases' && $table !== 'sales') return $cols;
    foreach (['tax_status', 'tax_rate_milli'] as $xc) {
        if (in_array($xc, $cols, true)) continue;
        $q = $pdo->query('SHOW COLUMNS FROM `' . $table . "` LIKE '" . $xc . "'");
        if ($q && $q->fetch()) $cols[] = $xc;
    }
    return $cols;
}

/**
 * ساختار فایل پشتیبان را کامل بررسی می‌کند و ردیف‌های تمیزشده را برمی‌گرداند.
 * هرچه اشتباه باشد bad() صدا زده می‌شود و هیچ داده‌ای حذف نمی‌شود.
 */
function validateBackup(array $tables): array {
    $known = array_keys(BACKUP_TABLES);
    foreach (array_keys($tables) as $k) {
        if (!in_array($k, $known, true)) bad('فایل پشتیبان معتبر نیست: جدول ناشناخته «' . mb_substr((string)$k, 0, 40) . '»');
    }
    foreach ($known as $t) {
        if (in_array($t, BACKUP_OPTIONAL, true) && !array_key_exists($t, $tables)) { $tables[$t] = []; continue; }
        if (!array_key_exists($t, $tables) || !is_array($tables[$t])) bad('فایل پشتیبان ناقص است: جدول «' . $t . '» وجود ندارد');
    }
    $plan = []; $ids = [];
    $total = 0;
    foreach (BACKUP_TABLES as $table => $cols) {
        $plan[$table] = []; $ids[$table] = [];
        foreach ($tables[$table] as $row) {
            if (++$total > 300000) bad('فایل پشتیبان بیش از حد بزرگ است');
            if (!is_array($row)) bad('فایل پشتیبان معتبر نیست: ردیف نامعتبر در «' . $table . '»');
            $id = $row['id'] ?? null;
            if (!is_numeric($id) || (int)$id <= 0 || isset($ids[$table][(int)$id])) bad('فایل پشتیبان معتبر نیست: شناسهٔ نامعتبر یا تکراری در «' . $table . '»');
            $ids[$table][(int)$id] = true;
            $clean = ['id' => (int)$id];
            foreach ($cols as $c) {
                if ($c === 'id' || $c === 'customer_id') continue;
                $v = $row[$c] ?? null;
                if (is_array($v)) bad('فایل پشتیبان معتبر نیست: مقدار نامعتبر در «' . $table . '.' . $c . '»');
                if (($v === null || $v === '') && in_array($c, BACKUP_REQUIRED[$table] ?? [], true)) bad('فایل پشتیبان معتبر نیست: «' . $table . '.' . $c . '» خالی است');
                if ($v === '') $v = null;
                if ($v !== null) {
                    if (isset(BACKUP_REFS[$table][$c])) {
                        if (!is_numeric($v) || !isset($ids[BACKUP_REFS[$table][$c]][(int)$v])) bad('فایل پشتیبان معتبر نیست: ارجاع نامعتبر در «' . $table . '.' . $c . '»');
                        $v = (int)$v;
                    } elseif ($c === 'date' || $c === 'due_date' || $c === 'move_date' || $c === 'cheque_due' || $c === 'sep_date') {
                        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) bad('فایل پشتیبان معتبر نیست: تاریخ نامعتبر در «' . $table . '»');
                    } elseif ($c === 'created_at') {
                        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $v)) $v = null;
                        else $v = substr(str_replace('T', ' ', $v), 0, 19);
                    } elseif ($c === 'is_mix') { // 0 purchase | 1 mix | 2 separation output lot (S07) | 3 consigned-in lot (S08)
                        $v = in_array((int)$v, [2, PB_CONS_LOT_KIND], true) ? (int)$v : (((int)$v === 1 || $v === true) ? 1 : 0); // 3 = consigned-in lot (S08): must survive a restore
                    } elseif ($c === 'paid' || $c === 'cancelled' || ($table === 'parties' && $c === 'active')) {
                        $v = ($v === true || $v === 1 || $v === '1') ? 1 : 0;
                    } elseif ($c === 'photo') {
                        $v = vphoto($v);
                    } elseif (in_array($c, BACKUP_NUMERIC, true)) {
                        if (!is_numeric($v) || abs((float)$v) > 1e13) bad('فایل پشتیبان معتبر نیست: عدد نامعتبر در «' . $table . '.' . $c . '»');
                        if ((float)$v < 0 && !($table === 'proc_outputs' && $c === 'cost')) bad('فایل پشتیبان معتبر نیست: عدد منفی در «' . $table . '.' . $c . '»'); // proc_outputs.cost (بهای سالم کارگاه) می‌تواند منفی باشد
                        $v = (float)$v;
                    } else {
                        if (!is_scalar($v)) bad('فایل پشتیبان معتبر نیست');
                        $v = (string)$v;
                        if (mb_strlen($v) > (BACKUP_MAXLEN[$c] ?? 500)) bad('فایل پشتیبان معتبر نیست: متن بیش از حد طولانی در «' . $table . '.' . $c . '»');
                        if ($c === 'custom_specs' && json_decode($v, true) === null) $v = null;
                    }
                }
                $clean[$c] = $v;
            }
            if (isset($clean['pay_type']) && !in_array($clean['pay_type'], ['lump', 'staged', 'mixed'], true)) bad('فایل پشتیبان معتبر نیست: نوع پرداخت');
            if (isset($clean['recv_type']) && !in_array($clean['recv_type'], ['lump', 'staged'], true)) bad('فایل پشتیبان معتبر نیست: نوع دریافت');
            if (isset($clean['staged_type']) && !in_array($clean['staged_type'], ['credit', 'cheque'], true)) bad('فایل پشتیبان معتبر نیست: نوع قسط');
            if ($table === 'loan_entries') {
                if (!in_array($clean['kind'], LOAN_KINDS, true)) bad('فایل پشتیبان معتبر نیست: نوع قرض');
                if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', (string)$clean['uid'])) bad('فایل پشتیبان معتبر نیست: شناسهٔ قرض');
                $clean['created_at'] = $clean['created_at'] ?? date('Y-m-d H:i:s');
            }
            if ($table === 'doc_grade_lines') { // S35.T35.3b: doc_id must point to a purchase/sale present in the file
                $pt = ($clean['doc_type'] ?? '') === 'sale' ? 'sales' : ((($clean['doc_type'] ?? '') === 'purchase') ? 'purchases' : '');
                if ($pt === '' || !isset($ids[$pt][(int)($clean['doc_id'] ?? 0)])) bad('فایل پشتیبان معتبر نیست: ردیف درجه');
            }
            if ($table === 'loan_dues') {
                if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', (string)$clean['loan_uid'])) bad('فایل پشتیبان معتبر نیست: شناسهٔ سررسید قرض');
                $clean['seq'] = max(1, min(255, (int)$clean['seq']));
            }
            if ($table === 'purchases') {
                if ($clean['net_weight'] <= 0 || $clean['remaining'] > $clean['net_weight'] + 1e-6) bad('فایل پشتیبان معتبر نیست: وزن یا موجودی خرید');
                // پشتیبان قدیمی ستون گونیِ باقی‌مانده ندارد: مقدار موقت = کل گونی؛ بعد از ورود، backfill دقیق‌ترش می‌کند
                foreach (['small', 'large'] as $k) {
                    $rc = $k . '_sacks_remaining'; $tc = $k . '_sack_count';
                    if (!isset($clean[$rc])) $clean[$rc] = (float)($clean[$tc] ?? 0);
                    if ($clean[$rc] > (float)($clean[$tc] ?? 0) + 1e-6) bad('فایل پشتیبان معتبر نیست: گونی باقی‌مانده بیشتر از گونی خرید است');
                }
                $clean['is_mix'] = (int)($clean['is_mix'] ?? 0);
                $clean['created_at'] = $clean['created_at'] ?? date('Y-m-d H:i:s');
            }
            if ($table === 'sales') { if ($clean['net_weight'] <= 0) bad('فایل پشتیبان معتبر نیست: وزن فروش'); $clean['created_at'] = $clean['created_at'] ?? date('Y-m-d H:i:s'); }
            if ($table === 'stock_transfers') {
                $clean['cancelled'] = (int)($clean['cancelled'] ?? 0);
                $clean['created_at'] = $clean['created_at'] ?? date('Y-m-d H:i:s');
                if ($clean['qty_kg'] <= 0) bad('فایل پشتیبان معتبر نیست: وزن انتقال');
            }
            if ($table === 'parties') { // T03.1: old backups carry only the name
                $clean['opening_balance'] = (int)round((float)($clean['opening_balance'] ?? 0));
                $clean['opening_side'] = ($clean['opening_side'] ?? '') === 'creditor' ? 'creditor' : 'debtor';
                $clean['active'] = array_key_exists('active', $row) && $row['active'] !== null ? (int)$clean['active'] : 1;
                if (!isset($clean['credit_limit'])) $clean['credit_limit'] = null; else $clean['credit_limit'] = (int)round((float)$clean['credit_limit']);
            }
            if ($table === 'payments' || $table === 'receipts') { /* یک رکورد به‌ازای هر خرید/فروش (UNIQUE) */ }
            $plan[$table][] = $clean;
        }
    }
    // هر خرید/فروش حداکثر یک پرداخت/دریافت (کلید UNIQUE)
    foreach (['payments' => 'purchase_id', 'receipts' => 'sale_id'] as $t => $col) {
        $seen = [];
        foreach ($plan[$t] as $r) { if (isset($seen[$r[$col]])) bad('فایل پشتیبان معتبر نیست: پرداخت/دریافت تکراری'); $seen[$r[$col]] = 1; }
    }
    return $plan;
}

// ---------------------------------------------------------------- اکشن‌ها

ensureSackColumns($pdo);
ensureFxColumns($pdo);
ensureWasteColumn($pdo);
// S01.T01.2: ledger of this customer is built lazily on the first stock-touching request (outside any transaction)
if (preg_match('/^(purchases\.(create|delete)|purchase_returns\.(create|delete)|sale_returns\.(create|delete)|mixes\.|sales\.(create|update|delete)|consignments\.|processing\.|workshop\.|harvests\.|orchards\.allocate|stock\.)/', $action)) pb_stock_ensure($pdo, $cid);
if (strpos($action, 'loans.') === 0 || strpos($action, 'parties.') === 0 || strpos($action, 'backup.') === 0 || strpos($action, 'dues.') === 0) ensureLoanTables($pdo);
if (strpos($action, 'messages.') === 0) ensureMessageTables($pdo);
// STAGE11: ساخت جدول‌ها پیش از هر تراکنش (DDL = commit ضمنی) + پاکسازی گاه‌به‌گاه لاگ
if (function_exists('sg_ensure_tables') && in_array($action, ['payments.save', 'receipts.save', 'loans.create'], true)) { sg_ensure_tables($pdo); if (mt_rand(1, 50) === 1) sg_purge($pdo); }

pb_fy_guard_action($pdo, $cid, $customer, $action, is_array($payload) ? $payload : []); // S15.T15.1b (gold only)
pb_unit_mult_bind($pdo, $cid); // S29.T29.2
pb_money_unit_guard($pdo, $cid, $action, $raw); // S24.T24.2: 426 upgrade_required for old clients once tenant is rial

if (function_exists('pb_audit_before_capture')) pb_audit_before_capture($pdo, $cid, (string)$action, is_array($payload) ? $payload : []); // S31.T31.5: previous row for audit before_json
try {
switch ($action) {

    case 'sync.version': case 'sync.changes': pb_sync_action($action, $payload, $pdo, $customer); break; // S4
    case 'sync.export': pb_sync_export_action($pdo, $customer, $edition); break; // S10: read-only full snapshot for non-owner staff

    case 'bootstrap': {
        seedDefaults($pdo, $cid);
        pb_wh_default_uid($pdo, $cid); // S01: «انبار اصلی» + adopt existing lots
        $cats = $pdo->prepare('SELECT * FROM categories WHERE customer_id=? ORDER BY id');
        $cats->execute([$cid]);
        $typs = $pdo->prepare('SELECT * FROM types WHERE customer_id=? ORDER BY id');
        $typs->execute([$cid]);
        out(['categories' => $cats->fetchAll(), 'types' => $typs->fetchAll(),
             'staff' => pb_staff_public($customer), // S21.T21.1: null = مالک؛ برای کارکن نقش و مجوزها (وب)
             'customer' => ['name' => $customer['name'], 'phone' => $customer['phone'], 'expiry_date' => $customer['expiry_date'], 'plan' => $plan, 'edition' => $edition],
             'entity_rev' => pb_entity_revs($pdo, $cid, $edition),
             'sync' => ['ver' => pb_sync_version($pdo, $cid), 'poll_interval' => pb_sync_poll_interval($pdo, $cid)], // S4: additive (کلاینت قدیمی نادیده می‌گیرد)
             'money_unit' => pb_money_unit($pdo, $cid),
             'display_unit' => function_exists('pb_fmt_display_unit') ? pb_fmt_display_unit($pdo, $cid) : pb_money_unit($pdo, $cid), // S35.T35.12: additive; web PBFmt
             'digits' => 'fa']); // S29.T29.3: client handshake (toman|rial)
    }

    case 'categories.create': {
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) bad('نام دسته را (حداکثر ۱۰۰ حرف) وارد کنید');
        $pdo->prepare('INSERT INTO categories (customer_id, name, preset_key) VALUES (?,?,NULL)')->execute([$cid, $name]);
        out(['id' => (int)$pdo->lastInsertId()]);
    }

    case 'types.create': {
        $catId = (int)($payload['category_id'] ?? 0);
        $name = trim((string)($payload['name'] ?? ''));
        if ($catId <= 0 || $name === '' || mb_strlen($name) > 100) bad('اطلاعات نوع ناقص است');
        if (!ownsCategory($pdo, $cid, $catId)) bad('دستهٔ انتخابی یافت نشد', 404);
        $pdo->prepare('INSERT INTO types (customer_id, category_id, name) VALUES (?,?,?)')->execute([$cid, $catId, $name]);
        out(['id' => (int)$pdo->lastInsertId()]);
    }

    // ---------------- خرید ----------------
    case 'purchases.list': {
        // توجه: ستون photo عمداً در لیست انتخاب نمی‌شود تا حجم پاسخ کم بماند؛
        // برای دیدن عکس باید purchases.get صدا زده شود (صفحهٔ جزئیات).
        $st = $pdo->prepare('SELECT p.id, p.customer_id, p.code, p.category_id, p.type_name, p.date, p.ounce,
            p.price, p.total_weight, p.small_sack_count, p.large_sack_count, p.small_sacks_remaining, p.large_sacks_remaining, p.net_weight, p.remaining,
            p.gram, p.pct_dombest, p.pct_tuchin, p.pct_crooked, p.pct_khandan, p.usd_rate, p.price_usd, p.waste, p.base_type, p.labor_cost, p.transport_cost, p.warehouse_uid, p.ounce_uid, p.broker_id, p.broker_basis, p.broker_value, p.broker_amount, p.broker_payer,
            (p.photo IS NOT NULL) AS has_photo, p.seller, p.note, p.is_mix, p.created_at,
            c.name AS category_name
            FROM purchases p JOIN categories c ON c.id = p.category_id AND c.customer_id = p.customer_id
            WHERE p.customer_id=? ORDER BY p.date DESC, p.id DESC');
        $st->execute([$cid]);
        out(['items' => stripFx(attachSacksLeft($pdo, $cid, $st->fetchAll()), $isFx)]);
    }

    case 'purchases.create': {
        out(['id' => pb_purchase_create_core($pdo, $cid, $customer, $payload, $isFx)]);
    }

    case 'purchases.get': {
        $id = (int)($payload['id'] ?? 0);
        $st = $pdo->prepare('SELECT p.*, c.name AS category_name FROM purchases p JOIN categories c ON c.id=p.category_id AND c.customer_id=p.customer_id WHERE p.id=? AND p.customer_id=?');
        $st->execute([$id, $cid]);
        $row = $st->fetch();
        if (!$row) bad('یافت نشد', 404);
        $pay = $pdo->prepare('SELECT * FROM payments WHERE purchase_id=? AND customer_id=?');
        $pay->execute([$id, $cid]);
        $payment = $pay->fetch();
        $row['payment'] = null;
        if ($payment) {
            $tbl = $payment['pay_type'] === 'lump' ? 'lump_tx' : ($payment['staged_type'] === 'credit' ? 'credit_inst' : 'cheque_inst');
            $tx = $pdo->prepare("SELECT * FROM $tbl WHERE payment_id=? AND customer_id=? ORDER BY id");
            $tx->execute([$payment['id'], $cid]);
            $row['payment'] = ['pay_type' => $payment['pay_type'], 'staged_type' => $payment['staged_type'],
                ($payment['pay_type'] === 'lump' ? 'lump' : 'installments') => $tx->fetchAll()];
        }
        if ((int)$row['is_mix'] === 1) {
            $src = $pdo->prepare('SELECT ms.*, p.type_name, p.code FROM mix_sources ms JOIN purchases p ON p.id = ms.source_purchase_id AND p.customer_id = ms.customer_id WHERE ms.mix_purchase_id=? AND ms.customer_id=?');
            $src->execute([$id, $cid]);
            $row['sources'] = $src->fetchAll();
        }
        $rowsWithSacks = stripFx(attachSacksLeft($pdo, $cid, [$row]), $isFx);
        out(['item' => $rowsWithSacks[0]]);
    }

    case 'mixes.create': {
        $sources = $payload['sources'] ?? [];
        if (!is_array($sources) || count($sources) < 1) bad('حداقل یک جنس مبدأ را انتخاب کنید');
        $catId = (int)($payload['category_id'] ?? 0);
        if ($catId <= 0) bad('دسته را انتخاب کنید');
        if (!ownsCategory($pdo, $cid, $catId)) bad('دستهٔ انتخابی یافت نشد', 404);
        $netWeight = num($payload['net_weight'] ?? 0);
        if ($netWeight <= 0 || $netWeight > 1e9) bad('وزن نهایی مخلوط (طبق ترازو) را وارد کنید');
        $small = num($payload['small_sack_count'] ?? 0);
        $large = num($payload['large_sack_count'] ?? 0);
        if ($small < 0 || $large < 0) bad('تعداد گونی نامعتبر است');
        $mixName = str_or_null($payload['type_name'] ?? null, 100) ?? 'مخلوط';
        $mixNote = str_or_null($payload['note'] ?? null, 500);
        $baseType = str_or_null($payload['base_type'] ?? null, 100);
        // انس و گرم مخلوط (اختیاری)
        $mixOunce = nullableNum($payload['ounce'] ?? null);
        $mixGram = nullableNum($payload['gram'] ?? null);
        foreach ([$mixOunce, $mixGram] as $mv) {
            if ($mv !== null && ($mv < 0 || $mv > 1e9)) bad('مقدار انس یا گرم نامعتبر است');
        }
        // اگر یک خرید مبدأ چند بار در لیست باشد، قبل از بررسیِ موجودی وزن‌ها را جمع می‌زنیم (دقیقاً مثل اپ اندروید)
        $sources = normalizeMixSources($sources);

        $pdo->beginTransaction();
        $lockSt = $pdo->prepare('SELECT remaining, price, price_usd, type_name, small_sacks_remaining, large_sacks_remaining, is_mix FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $sumW = 0.0; $sumVal = 0.0; $sumUsd = 0.0; $usdMissing = false;
        foreach ($sources as $s) {
            $pid = $s['purchase_id']; $w = (float)$s['weight'];
            if ($w <= 0) continue;
            $lockSt->execute([$pid, $cid]);
            $row = $lockSt->fetch();
            if (!$row) { $pdo->rollBack(); bad('یکی از مبدأها یافت نشد'); }
            if ((int)$row['is_mix'] === PB_CONS_LOT_KIND) { $pdo->rollBack(); bad('کالای امانی قابل مخلوط‌کردن نیست'); }
            if ($w - (float)$row['remaining'] > 1e-6) { $pdo->rollBack(); bad('موجودی «' . $row['type_name'] . '» کافی نیست'); }
            checkSacksAvailable($pdo, $row, $s['small_sacks'], $s['large_sacks']);
            $sumW += $w; $sumVal += $w * (float)$row['price'];
            if ($row['price_usd'] === null) $usdMissing = true; else $sumUsd += $w * (float)$row['price_usd'];
        }
        if ($sumW <= 0) { $pdo->rollBack(); bad('حداقل از یک جنس مبدأ وزن بردارید'); }
        $mixWaste = readMixWaste($pdo, $payload, $sumW, $netWeight);
        // فی = هزینهٔ کل مبدأها ÷ وزن خالصِ بار تازه؛ پس هزینهٔ ضایعات هم در فی لحاظ می‌شود (بدون ضایعات = همان میانگین وزنی)
        $feeDiv = ($mixWaste === null) ? $sumW : $netWeight;
        $avgPrice = $sumVal / $feeDiv;
        $mixPriceUsd = !$usdMissing ? round($sumUsd / $feeDiv, 4) : null;
        $mixUsdRate = ($mixPriceUsd !== null && $mixPriceUsd > 0) ? round($avgPrice / $mixPriceUsd, 2) : null;

        $pdo->prepare('INSERT INTO purchases (customer_id, code, category_id, type_name, date, price, total_weight, small_sack_count, large_sack_count, small_sacks_remaining, large_sacks_remaining, net_weight, remaining, seller, note, ounce, gram, usd_rate, price_usd, waste, base_type, is_mix) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)')
            ->execute([$cid, null, $catId, $mixName, today(), $avgPrice,
                $netWeight, $small, $large, $small, $large, $netWeight, $netWeight, null, $mixNote, $mixOunce, $mixGram, $mixUsdRate, $mixPriceUsd, $mixWaste, $baseType]);
        $mixId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE purchases SET code=? WHERE id=? AND customer_id=?')->execute([pb_code_with_id('M', (int)$mixId), $mixId, $cid]);

        $insSrc = $pdo->prepare('INSERT INTO mix_sources (customer_id, mix_purchase_id, source_purchase_id, weight, small_sacks, large_sacks) VALUES (?,?,?,?,?,?)');
        $updRem = $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?');
        foreach ($sources as $s) {
            $w = (float)$s['weight'];
            if ($w <= 0) continue;
            $insSrc->execute([$cid, $mixId, $s['purchase_id'], $w, $s['small_sacks'], $s['large_sacks']]);
            $updRem->execute([$w, (float)($s['small_sacks'] ?? 0), (float)($s['large_sacks'] ?? 0), $s['purchase_id'], $cid]);
            // S01.T01.3: the warehouse each source is taken from
            $srcWh = pb_stock_pick_wh_or_bad($pdo, $cid, (int)$s['purchase_id'], $w, $s['warehouse_uid'] ?? null);
            $pdo->prepare('UPDATE mix_sources SET warehouse_uid=? WHERE mix_purchase_id=? AND source_purchase_id=? AND customer_id=?')->execute([$srcWh, $mixId, $s['purchase_id'], $cid]);
        }
        pb_wh_apply_lot($pdo, $cid, $mixId, $payload, true); // S01
        pb_stock_post_doc($pdo, $cid, 'mix', $mixId); // S01.T01.2
        $pdo->commit();
        out(['id' => $mixId]);
    }

    case 'mixes.update': {
        $id = (int)($payload['id'] ?? 0);
        $sources = $payload['sources'] ?? [];
        if (!is_array($sources) || count($sources) < 1) bad('حداقل یک جنس مبدأ را انتخاب کنید');
        $catId = (int)($payload['category_id'] ?? 0);
        if ($catId <= 0) bad('دسته را انتخاب کنید');
        if (!ownsCategory($pdo, $cid, $catId)) bad('دستهٔ انتخابی یافت نشد', 404);
        $netWeight = num($payload['net_weight'] ?? 0);
        if ($netWeight <= 0 || $netWeight > 1e9) bad('وزن نهایی مخلوط (طبق ترازو) را وارد کنید');
        $small = num($payload['small_sack_count'] ?? 0);
        $large = num($payload['large_sack_count'] ?? 0);
        if ($small < 0 || $large < 0) bad('تعداد گونی نامعتبر است');
        $mixName = str_or_null($payload['type_name'] ?? null, 100) ?? 'مخلوط';
        $mixNote = str_or_null($payload['note'] ?? null, 500);
        $baseType = str_or_null($payload['base_type'] ?? null, 100);
        // انس و گرم مخلوط (اختیاری)
        $mixOunce = nullableNum($payload['ounce'] ?? null);
        $mixGram = nullableNum($payload['gram'] ?? null);
        foreach ([$mixOunce, $mixGram] as $mv) {
            if ($mv !== null && ($mv < 0 || $mv > 1e9)) bad('مقدار انس یا گرم نامعتبر است');
        }
        $sources = normalizeMixSources($sources);

        $pdo->beginTransaction();
        $chk = $pdo->prepare('SELECT net_weight, remaining FROM purchases WHERE id=? AND customer_id=? AND is_mix=1 FOR UPDATE');
        $chk->execute([$id, $cid]);
        $old = $chk->fetch();
        if (!$old) { $pdo->rollBack(); bad('یافت نشد', 404); }
        $sold = (float)$old['net_weight'] - (float)$old['remaining'];
        if ($sold > 0.0005) { $pdo->rollBack(); bad('چون بخشی از این مخلوط فروخته شده، ویرایش آن ممکن نیست'); }

        // برگرداندن وزنِ مبدأهای قبلی به موجودی، قبل از بررسیِ مبدأهای تازه
        $oldSrc = $pdo->prepare('SELECT source_purchase_id, weight, small_sacks, large_sacks FROM mix_sources WHERE mix_purchase_id=? AND customer_id=?');
        $oldSrc->execute([$id, $cid]);
        foreach ($oldSrc->fetchAll() as $s) restoreStock($pdo, $cid, (int)$s['source_purchase_id'], (float)$s['weight'], $s['small_sacks'], $s['large_sacks']);

        $lockSt = $pdo->prepare('SELECT remaining, price, price_usd, type_name, small_sacks_remaining, large_sacks_remaining, is_mix FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $sumW = 0.0; $sumVal = 0.0; $sumUsd = 0.0; $usdMissing = false;
        foreach ($sources as $s) {
            $pid = $s['purchase_id']; $w = (float)$s['weight'];
            if ($w <= 0) continue;
            $lockSt->execute([$pid, $cid]);
            $row = $lockSt->fetch();
            if (!$row) { $pdo->rollBack(); bad('یکی از مبدأها یافت نشد'); }
            if ((int)$row['is_mix'] === PB_CONS_LOT_KIND) { $pdo->rollBack(); bad('کالای امانی قابل مخلوط‌کردن نیست'); }
            if ($w - (float)$row['remaining'] > 1e-6) { $pdo->rollBack(); bad('موجودی «' . $row['type_name'] . '» کافی نیست'); }
            checkSacksAvailable($pdo, $row, $s['small_sacks'], $s['large_sacks']);
            $sumW += $w; $sumVal += $w * (float)$row['price'];
            if ($row['price_usd'] === null) $usdMissing = true; else $sumUsd += $w * (float)$row['price_usd'];
        }
        if ($sumW <= 0) { $pdo->rollBack(); bad('حداقل از یک جنس مبدأ وزن بردارید'); }
        $mixWaste = readMixWaste($pdo, $payload, $sumW, $netWeight);
        // فی = هزینهٔ کل مبدأها ÷ وزن خالصِ بار تازه؛ پس هزینهٔ ضایعات هم در فی لحاظ می‌شود (بدون ضایعات = همان میانگین وزنی)
        $feeDiv = ($mixWaste === null) ? $sumW : $netWeight;
        $avgPrice = $sumVal / $feeDiv;
        $mixPriceUsd = !$usdMissing ? round($sumUsd / $feeDiv, 4) : null;
        $mixUsdRate = ($mixPriceUsd !== null && $mixPriceUsd > 0) ? round($avgPrice / $mixPriceUsd, 2) : null;

        $pdo->prepare('UPDATE purchases SET category_id=?, type_name=?, price=?, total_weight=?, small_sack_count=?, large_sack_count=?, small_sacks_remaining=?, large_sacks_remaining=?, net_weight=?, remaining=?, note=?, ounce=?, gram=?, usd_rate=?, price_usd=?, waste=?, base_type=? WHERE id=? AND customer_id=?')
            ->execute([$catId, $mixName, $avgPrice, $netWeight, $small, $large, $small, $large, $netWeight, $netWeight, $mixNote, $mixOunce, $mixGram, $mixUsdRate, $mixPriceUsd, $mixWaste, $baseType, $id, $cid]);
        $pdo->prepare('DELETE FROM mix_sources WHERE mix_purchase_id=? AND customer_id=?')->execute([$id, $cid]);
        $insSrc = $pdo->prepare('INSERT INTO mix_sources (customer_id, mix_purchase_id, source_purchase_id, weight, small_sacks, large_sacks) VALUES (?,?,?,?,?,?)');
        $updRem2 = $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?');
        foreach ($sources as $s) {
            $w = (float)$s['weight'];
            if ($w <= 0) continue;
            $insSrc->execute([$cid, $id, $s['purchase_id'], $w, $s['small_sacks'], $s['large_sacks']]);
            $updRem2->execute([$w, (float)($s['small_sacks'] ?? 0), (float)($s['large_sacks'] ?? 0), $s['purchase_id'], $cid]);
            $srcWh = pb_stock_pick_wh_or_bad($pdo, $cid, (int)$s['purchase_id'], $w, $s['warehouse_uid'] ?? null, ['mix', $id]); // S01.T01.3
            $pdo->prepare('UPDATE mix_sources SET warehouse_uid=? WHERE mix_purchase_id=? AND source_purchase_id=? AND customer_id=?')->execute([$srcWh, $id, $s['purchase_id'], $cid]);
        }
        pb_wh_apply_lot($pdo, $cid, $id, $payload, false); // S01
        pb_stock_post_doc($pdo, $cid, 'mix', $id); // S01.T01.2
        $pdo->commit();
        out(['ok' => true]);
    }

    case 'mixes.delete': {
        $id = (int)($payload['id'] ?? 0);
        $chk = $pdo->prepare('SELECT net_weight, remaining FROM purchases WHERE id=? AND customer_id=? AND is_mix=1');
        $chk->execute([$id, $cid]);
        $row = $chk->fetch();
        if (!$row) bad('یافت نشد', 404);
        if (abs((float)$row['net_weight'] - (float)$row['remaining']) > 1e-6) bad('این مخلوط فروخته شده و قابل حذف نیست');
        if (pb_lot_on_consignment($pdo, $cid, $id)) bad('این مخلوط در یک «امانی ارسالی» ثبت شده است؛ ابتدا آن امانی را حذف کنید', 409);
        $pdo->beginTransaction();
        $src = $pdo->prepare('SELECT source_purchase_id, weight, small_sacks, large_sacks FROM mix_sources WHERE mix_purchase_id=? AND customer_id=?');
        $src->execute([$id, $cid]);
        foreach ($src->fetchAll() as $s) restoreStock($pdo, $cid, (int)$s['source_purchase_id'], (float)$s['weight'], $s['small_sacks'], $s['large_sacks']);
        pb_stock_drop_lot_transfers($pdo, $cid, $id); // S01.T01.3
        pb_stock_reverse_doc($pdo, $cid, 'mix', $id); // S01.T01.2
        $pdo->prepare('DELETE FROM mix_sources WHERE mix_purchase_id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM purchases WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->commit();
        out(['ok' => true]);
    }

    case 'payments.save': {
        $pid = (int)($payload['purchase_id'] ?? 0);
        $chk = $pdo->prepare('SELECT id FROM purchases WHERE id=? AND customer_id=?');
        $chk->execute([$pid, $cid]);
        if (!$chk->fetch()) bad('خرید یافت نشد', 404);
        $payIn = $payload['payment'] ?? [];
        if (is_array($payIn) && ($pendAppr = pb_payappr_intercept($pdo, $cid, $customer, $pid, $payIn, $payload['snap_at'] ?? null)) !== null) out($pendAppr); // S31.T31.3
        $pdo->beginTransaction();
        // STAGE11: حفظ تسویهٔ دستیار در برابر ارسال کل وضعیت قدیمی اپ
        if (function_exists('sg_merge_incoming') && is_array($payIn)) $payIn = sg_merge_incoming($pdo, $cid, 'purchase', $pid, $payIn, $payload['snap_at'] ?? null);
        savePayment($pdo, $cid, $pid, $payIn);
        pb_post_hook($pdo, $customer, 'purchase', $pid); // S16.T16.4: replaces the doc voucher (payments saved later are part of it, never a 2nd voucher)
        $pdo->commit();
        out(['ok' => true]);
    }

    case 'receipts.save': {
        $sid = (int)($payload['sale_id'] ?? 0);
        $chk = $pdo->prepare('SELECT id FROM sales WHERE id=? AND customer_id=?');
        $chk->execute([$sid, $cid]);
        if (!$chk->fetch()) bad('فروش یافت نشد', 404);
        $pdo->beginTransaction();
        $recIn = $payload['receipt'] ?? [];
        // STAGE11: مثل payments.save
        if (function_exists('sg_merge_incoming') && is_array($recIn)) $recIn = sg_merge_incoming($pdo, $cid, 'sale', $sid, $recIn, $payload['snap_at'] ?? null);
        saveReceipt($pdo, $cid, $sid, $recIn);
        pb_post_hook($pdo, $customer, 'sale', $sid); // S16.T16.4 (see payments.save)
        $pdo->commit();
        out(['ok' => true]);
    }

    case 'purchases.delete': {
        $id = (int)($payload['id'] ?? 0);
        pb_delete_reason_check($payload); // S35.T35.9a: reason (5-300) is recorded in audit_log.before_json via the payload
        pb_pl_guard_doc($pdo, $cid, $customer, 'purchases', $id); // S31.T31.2b
        $chk = $pdo->prepare('SELECT net_weight, remaining, is_mix FROM purchases WHERE id=? AND customer_id=?');
        $chk->execute([$id, $cid]);
        $row = $chk->fetch();
        if (!$row) bad('یافت نشد', 404);
        if ((int)$row['is_mix'] === PB_CONS_LOT_KIND) bad('این کالا امانی است؛ از بخش «امانی» حذف/برگشت دهید');
        if (abs((float)$row['net_weight'] - (float)$row['remaining']) > 1e-6) bad('این خرید فروخته شده و قابل حذف نیست');
        if (pb_sreturn_lot_has_returns($pdo, $cid, $id)) bad('این خرید در فروش و برگشت از فروش استفاده شده و قابل حذف نیست'); // S04.T04.2
        if (pb_preturn_lot_has_returns($pdo, $cid, $id)) bad('این خرید برگشت از خرید دارد و قابل حذف نیست'); // v71
        if (pb_lot_on_consignment($pdo, $cid, $id)) bad('این کالا در یک «امانی ارسالی» ثبت شده است؛ ابتدا آن امانی را حذف کنید', 409);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM lump_tx WHERE payment_id IN (SELECT id FROM payments WHERE purchase_id=? AND customer_id=?)')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM credit_inst WHERE payment_id IN (SELECT id FROM payments WHERE purchase_id=? AND customer_id=?)')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM cheque_inst WHERE payment_id IN (SELECT id FROM payments WHERE purchase_id=? AND customer_id=?)')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM payments WHERE purchase_id=? AND customer_id=?')->execute([$id, $cid]);
        pb_stock_drop_lot_transfers($pdo, $cid, $id); // S01.T01.3
        pb_stock_reverse_doc($pdo, $cid, 'purchase', $id); // S01.T01.2
        pb_unpost_hook($pdo, $customer, 'purchase', $id); // S16.T16.3
        pb_grades_store($pdo, $cid, 'purchase', $id, null, 1.0); // S35.T35.3b: drop grade lines
        $pdo->prepare('DELETE FROM purchases WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->commit();
        out(['ok' => true]);
    }

    // ---------------- فروش ----------------
    case 'sales.list': {
        $st = $pdo->prepare('SELECT * FROM sales WHERE customer_id=? ORDER BY date DESC, id DESC');
        $st->execute([$cid]);
        $sales = $st->fetchAll();
        $itemsSt = $pdo->prepare('SELECT si.*, p.type_name, p.base_type, p.labor_cost AS p_labor_cost, p.transport_cost AS p_transport_cost, p.net_weight AS p_net_weight, p.code, (CASE WHEN p.is_mix=' . PB_CONS_LOT_KIND . ' THEN NULL ELSE p.date END) AS purchase_date, ' . pb_cons_cost_sql() . ' AS cost_price, p.price_usd AS cost_price_usd, c.name AS category_name FROM sale_items si JOIN purchases p ON p.id = si.purchase_id AND p.customer_id = si.customer_id JOIN categories c ON c.id = p.category_id AND c.customer_id = p.customer_id WHERE si.sale_id=? AND si.customer_id=?');
        foreach ($sales as &$s) {
            $itemsSt->execute([$s['id'], $cid]);
            $s['items'] = stripFx($itemsSt->fetchAll(), $isFx);
        }
        unset($s);
        out(['items' => stripFx($sales, $isFx)]);
    }

    case 'sales.create': {
        pb_consout_guard_payload($pdo, $cid, $payload); // S08.T08.2: stock at consignees is sold only through consignments.sold
        out(pb_sale_create_core($pdo, $cid, $payload, $customer)); // S08.T08.2: core moved to sale_core.php (verbatim)
    }

    case 'sales.get': {
        $id = (int)($payload['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM sales WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $row = $st->fetch();
        if (!$row) bad('یافت نشد', 404);
        $itemsSt = $pdo->prepare('SELECT si.*, p.type_name, p.base_type, p.labor_cost AS p_labor_cost, p.transport_cost AS p_transport_cost, p.net_weight AS p_net_weight, p.code, (CASE WHEN p.is_mix=' . PB_CONS_LOT_KIND . ' THEN NULL ELSE p.date END) AS purchase_date, ' . pb_cons_cost_sql() . ' AS cost_price, p.price_usd AS cost_price_usd FROM sale_items si JOIN purchases p ON p.id=si.purchase_id AND p.customer_id=si.customer_id WHERE si.sale_id=? AND si.customer_id=?');
        $itemsSt->execute([$id, $cid]);
        $row['items'] = stripFx($itemsSt->fetchAll(), $isFx);
        if (!$isFx) unset($row['usd_rate']);
        $rec = $pdo->prepare('SELECT * FROM receipts WHERE sale_id=? AND customer_id=?');
        $rec->execute([$id, $cid]);
        $receipt = $rec->fetch();
        $row['receipt'] = null;
        if ($receipt) {
            $tbl = $receipt['recv_type'] === 'lump' ? 'receipt_lump_tx' : ($receipt['staged_type'] === 'credit' ? 'receipt_credit_inst' : 'receipt_cheque_inst');
            $tx = $pdo->prepare("SELECT * FROM $tbl WHERE receipt_id=? AND customer_id=? ORDER BY id");
            $tx->execute([$receipt['id'], $cid]);
            $row['receipt'] = ['recv_type' => $receipt['recv_type'], 'staged_type' => $receipt['staged_type'],
                ($receipt['recv_type'] === 'lump' ? 'lump' : 'installments') => $tx->fetchAll()];
        }
        out(['item' => $row]);
    }

    case 'sales.update': {
        $id = (int)($payload['id'] ?? 0);
        pb_pl_guard_doc($pdo, $cid, $customer, 'sales', $id); // S31.T31.2b (the NEW date is guarded in sale_core)
        if (pb_sreturn_sale_has_returns($pdo, $cid, $id)) bad('این فروش برگشت دارد؛ ابتدا برگشت‌ها را حذف کنید'); // S04.T04.2
        pb_consout_guard_sale_edit($pdo, $cid, $id); // S08.T08.2
        pb_fx_guard_no_edit($pdo, $cid, 'sales', $id, $payload); // S27c.T27b.7: ویرایش فروش ارزی رد می‌شود
        pb_consout_guard_payload($pdo, $cid, $payload);
        $fallback = num($payload['price'] ?? 0);
        if ($fallback < 0 || $fallback > 1e13) bad('فی فروش نامعتبر است');
        pb_weigh_boot($pdo, $cid); // S35.T35.2b
        $it = readSingleSaleItem($payload, $fallback);
        $gradesU = pb_grades_read($pdo, $cid, $payload, (float)$it['weight']); // S35.T35.3
        if ($gradesU) $it['price'] = (float)$gradesU['price'];
        pb_wh_check_sale($pdo, $cid, $payload, (int)$it['purchase_id']); // S01: optional warehouse_uid
        $saleUsdRate = readUsdRate($payload, $isFx);
        $laborCost = costNum($payload['labor_cost'] ?? null);
        $transportCost = costNum($payload['transport_cost'] ?? null);
        $sDate = vdate($payload['date'] ?? null);
        pb_fy_guard($pdo, $cid, $customer, $sDate); // S15.T15.1 (gold only)
        $buyer = str_or_null($payload['buyer'] ?? null, 150);
        $note = str_or_null($payload['note'] ?? null, 500);

        $pdo->beginTransaction();
        $own = $pdo->prepare('SELECT id, usd_rate, ccy FROM sales WHERE id=? AND customer_id=? FOR UPDATE');
        $own->execute([$id, $cid]);
        $ownRow = $own->fetch();
        if (!$ownRow) { $pdo->rollBack(); bad('یافت نشد', 404); }
        if (!$isFx || !empty($ownRow['ccy'])) $saleUsdRate = $ownRow['usd_rate'] !== null ? (float)$ownRow['usd_rate'] : null; // دادهٔ قدیمی می‌ماند
        $oldItemsSt = $pdo->prepare('SELECT purchase_id, weight, small_sacks, large_sacks FROM sale_items WHERE sale_id=? AND customer_id=?');
        $oldItemsSt->execute([$id, $cid]);
        $oldItems = $oldItemsSt->fetchAll();
        if (count($oldItems) > 1) { $pdo->rollBack(); bad('این فروش قدیمیِ چندقلمی است و قابل ویرایش نیست؛ آن را حذف کنید و برای هر جنس یک فروش جدا ثبت کنید'); }
        // ابتدا وزن و گونیِ قبلی را به همان خرید برمی‌گردانیم
        foreach ($oldItems as $o) restoreStock($pdo, $cid, (int)$o['purchase_id'], (float)$o['weight'], $o['small_sacks'], $o['large_sacks']);
        $pdo->prepare('DELETE FROM sale_items WHERE sale_id=? AND customer_id=?')->execute([$id, $cid]);

        // سپس با مقادیر تازه دوباره اعتبارسنجی و کم می‌کنیم
        $lockSt = $pdo->prepare('SELECT remaining, small_sacks_remaining, large_sacks_remaining FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $lockSt->execute([$it['purchase_id'], $cid]);
        $row = $lockSt->fetch();
        if (!$row) { $pdo->rollBack(); bad('قلم انتخاب‌شده در انبار یافت نشد'); }
        if ($it['weight'] - (float)$row['remaining'] > 1e-6) { $pdo->rollBack(); bad('وزن فروش از موجودی این قلم بیشتر است'); }
        checkSacksAvailable($pdo, $row, $it['small_sacks'], $it['large_sacks']);
        $saleWh = pb_stock_pick_wh_or_bad($pdo, $cid, (int)$it['purchase_id'], (float)$it['weight'], $payload['warehouse_uid'] ?? null, ['sale', $id]); // S01.T01.3

        $net = $it['weight'];
        $total = $net + tare($it['small_sacks'], $it['large_sacks']);
        $pdo->prepare('UPDATE sales SET date=?, price=?, total_weight=?, small_sack_count=?, large_sack_count=?, net_weight=?, buyer=?, note=?, usd_rate=?, labor_cost=?, transport_cost=? WHERE id=? AND customer_id=?')
            ->execute([$sDate, $it['price'], $total, (float)($it['small_sacks'] ?? 0), (float)($it['large_sacks'] ?? 0), $net, $buyer, $note, $saleUsdRate, $laborCost, $transportCost, $id, $cid]);
        // S35.T35.2b/T35.3: weighing block + grade lines on edit (off => untouched)
        $weighU = pb_weigh_read($pdo, $cid, $payload + ['net_weight' => $net], (float)($it['small_sacks'] ?? 0), (float)($it['large_sacks'] ?? 0));
        if ($weighU && abs($weighU['net'] - $net) > PB_WEIGH_TOL) { $pdo->rollBack(); bad('وزن خالص با وزن کل منهای تارا و کسر کیفیت نمی‌خواند'); }
        if ($weighU) { $pdo->prepare('UPDATE sales SET total_weight=? WHERE id=? AND customer_id=?')->execute([$weighU['gross'], $id, $cid]); }
        pb_weigh_store($pdo, $cid, 'sales', (int)$id, $weighU);
        pb_grades_store($pdo, $cid, 'sale', (int)$id, $gradesU, (float)$net, pb_form_on($pdo, $cid, 'grades'));
        $pdo->prepare('INSERT INTO sale_items (customer_id, sale_id, purchase_id, weight, item_price, small_sacks, large_sacks) VALUES (?,?,?,?,?,?,?)')
            ->execute([$cid, $id, $it['purchase_id'], $net, $it['price'], $it['small_sacks'], $it['large_sacks']]);
        $pdo->prepare('UPDATE sale_items SET warehouse_uid=? WHERE sale_id=? AND customer_id=?')->execute([$saleWh, $id, $cid]); // S01.T01.3
        $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?')
            ->execute([$net, (float)($it['small_sacks'] ?? 0), (float)($it['large_sacks'] ?? 0), $it['purchase_id'], $cid]);
        pb_broker_apply($pdo, $cid, 'sale', $id, $payload); // S05.T05.1
        if (isset($payload['receipt'])) saveReceipt($pdo, $cid, $id, is_array($payload['receipt']) ? $payload['receipt'] : []);
        pb_stock_post_doc($pdo, $cid, 'sale', $id); // S01.T01.2
        pb_fin_apply($pdo, $cid, 'sales', (int)$id, $payload); // S35.T35.5
        pb_tax_apply_doc($pdo, $customer, 'sale', (int)$id, $payload); // S17.T17.1c
        pb_post_hook($pdo, $customer, 'sale', $id); // S16.T16.3
        $pdo->commit();
        out(['ok' => true]);
    }

    case 'sales.delete': {
        $id = (int)($payload['id'] ?? 0);
        pb_delete_reason_check($payload); // S35.T35.9a: reason (5-300) is recorded in audit_log.before_json via the payload
        pb_pl_guard_doc($pdo, $cid, $customer, 'sales', $id); // S31.T31.2b
        if (pb_sreturn_sale_has_returns($pdo, $cid, $id)) bad('این فروش برگشت دارد؛ ابتدا برگشت‌ها را حذف کنید'); // S04.T04.2
        $itemsSt = $pdo->prepare('SELECT purchase_id, weight, small_sacks, large_sacks FROM sale_items WHERE sale_id=? AND customer_id=?');
        $itemsSt->execute([$id, $cid]);
        $items = $itemsSt->fetchAll();
        if (!$items) bad('یافت نشد', 404);
        $pdo->beginTransaction();
        foreach ($items as $o) restoreStock($pdo, $cid, (int)$o['purchase_id'], (float)$o['weight'], $o['small_sacks'], $o['large_sacks']);
        pb_grades_store($pdo, $cid, 'sale', $id, null, 1.0); // S35.T35.3b: drop grade lines
        $pdo->prepare('DELETE FROM receipt_lump_tx WHERE receipt_id IN (SELECT id FROM receipts WHERE sale_id=? AND customer_id=?)')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM receipt_credit_inst WHERE receipt_id IN (SELECT id FROM receipts WHERE sale_id=? AND customer_id=?)')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM receipt_cheque_inst WHERE receipt_id IN (SELECT id FROM receipts WHERE sale_id=? AND customer_id=?)')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM receipts WHERE sale_id=? AND customer_id=?')->execute([$id, $cid]);
        pb_stock_reverse_doc($pdo, $cid, 'sale', $id); // S01.T01.2
        pb_unpost_hook($pdo, $customer, 'sale', $id); // S16.T16.3
        pb_proforma_on_sale_delete($pdo, $cid, $id); // S04.T04.3
        pb_consout_on_sale_delete($pdo, $cid, $id); // S08.T08.2: unlink from its consignment (goods go back to the consignee via the ledger reversal)
        $pdo->prepare('DELETE FROM sale_items WHERE sale_id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM sales WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->commit();
        out(['ok' => true]);
    }

    // ---------------- سررسیدها ----------------
    case 'dues.list': {
        $out = [];
        $includePaid = !empty($payload['include_paid']);
        $add = function (string $sql, string $table, bool $receivable, string $kind) use ($pdo, $cid, &$out, $includePaid) {
            $st = $pdo->prepare($sql);
            $st->execute([$cid]);
            foreach ($st->fetchAll() as $r) {
                // مثل اپ اندروید (loadDues): مواردِ تسویه‌شده در سررسیدها نمی‌آیند
                if (!$includePaid && (int)$r['paid'] === 1) continue;
                $out[] = [
                    'table' => $table, 'id' => (int)$r['id'], 'receivable' => $receivable, 'kind' => $kind,
                    'party' => partyKey($r['party']), 'label' => $r['label'], 'ref' => $r['ref'], 'bank' => $r['bank'],
                    'amount' => (float)$r['amount'], 'date' => $r['date'], 'paid' => (bool)$r['paid'],
                ];
            }
        };
        $payBase = "FROM %T% x JOIN payments pay ON pay.id = x.payment_id AND pay.customer_id = x.customer_id JOIN purchases p ON p.id = pay.purchase_id AND p.customer_id = x.customer_id WHERE x.customer_id = ? AND ";
        $add(str_replace('%T%', 'lump_tx', "SELECT x.id,x.amount,x.date,x.paid,p.seller AS party,p.type_name AS label,NULL AS ref,NULL AS bank $payBase pay.pay_type IN ('lump','mixed')"), 'lump_tx', false, 'یکجا');
        $add(str_replace('%T%', 'credit_inst', "SELECT x.id,x.amount,x.due_date AS date,x.paid,p.seller AS party,p.type_name AS label,NULL AS ref,NULL AS bank $payBase (pay.pay_type='mixed' OR (pay.pay_type='staged' AND pay.staged_type='credit'))"), 'credit_inst', false, 'قسط');
        $add(str_replace('%T%', 'cheque_inst', "SELECT x.id,x.amount,x.due_date AS date,x.paid,p.seller AS party,p.type_name AS label,x.cheque_no AS ref,x.bank_name AS bank $payBase (pay.pay_type='mixed' OR (pay.pay_type='staged' AND pay.staged_type='cheque'))"), 'cheque_inst', false, 'چک');
        $recBase = "FROM %T% x JOIN receipts rec ON rec.id = x.receipt_id AND rec.customer_id = x.customer_id JOIN sales s ON s.id = rec.sale_id AND s.customer_id = x.customer_id WHERE x.customer_id = ? AND ";
        $add(str_replace('%T%', 'receipt_lump_tx', "SELECT x.id,x.amount,x.date,x.paid,s.buyer AS party,'فروش' AS label,NULL AS ref,NULL AS bank $recBase rec.recv_type='lump'"), 'receipt_lump_tx', true, 'یکجا');
        $add(str_replace('%T%', 'receipt_credit_inst', "SELECT x.id,x.amount,x.due_date AS date,x.paid,s.buyer AS party,'فروش' AS label,NULL AS ref,NULL AS bank $recBase rec.recv_type='staged' AND rec.staged_type='credit'"), 'receipt_credit_inst', true, 'قسط');
        $add(str_replace('%T%', 'receipt_cheque_inst', "SELECT x.id,x.amount,x.due_date AS date,x.paid,s.buyer AS party,'فروش' AS label,x.cheque_no AS ref,x.bank_name AS bank $recBase rec.recv_type='staged' AND rec.staged_type='cheque'"), 'receipt_cheque_inst', true, 'چک');
        // برگشت از فروش/خرید: اعتبار برگشتِ هر طرف‌حساب از سررسیدهای پرداخت‌نشدهٔ همان طرف کم می‌شود (مثل loadDues در اپ)
        $out = pb_dues_apply_return_credit($out, pb_sreturn_party_sums($pdo, $cid), pb_preturn_party_sums($pdo, $cid), pb_dues_credit_used_sums($pdo, $cid)); // v71: minus credit already consumed by paid rows
        // ---- سررسیدهای قرض و وام (اعطای قرض = دریافتیِ ما، دریافت قرض = پرداختیِ ما) ----
        $sumSt = $pdo->prepare('SELECT party, kind, SUM(amount) AS s FROM loan_entries WHERE customer_id=? GROUP BY party, kind');
        $sumSt->execute([$cid]);
        $sums = [];
        foreach ($sumSt->fetchAll() as $r) $sums[$r['party'] . '|' . $r['kind']] = (float)$r['s'];
        $outstanding = function (string $party, string $kind) use ($sums): float {
            $rep = $kind === 'given' ? 'repay_in' : 'repay_out';
            return ($sums[$party . '|' . $kind] ?? 0.0) - ($sums[$party . '|' . $rep] ?? 0.0);
        };
        $ld = $pdo->prepare('SELECT d.id, d.amount, d.due_date, d.paid, d.note, l.party, l.kind FROM loan_dues d JOIN loan_entries l ON l.uid = d.loan_uid AND l.customer_id = d.customer_id WHERE d.customer_id = ?');
        $ld->execute([$cid]);
        foreach ($ld->fetchAll() as $r) {
            $isPaid = (int)$r['paid'] === 1;
            if (!$isPaid && $outstanding($r['party'], $r['kind']) <= 0.5) continue; // قرض کاملاً تسویه شده
            if (!$includePaid && $isPaid) continue;
            $given = $r['kind'] === 'given';
            $out[] = ['table' => 'loan_dues', 'id' => (int)$r['id'], 'receivable' => $given, 'kind' => 'قرض',
                'party' => partyKey($r['party']), 'label' => $given ? 'قرض اعطایی' : 'قرض دریافتی', 'ref' => null, 'bank' => null,
                'amount' => (float)$r['amount'], 'date' => $r['due_date'], 'paid' => $isPaid];
        }
        // ---- سررسیدهای پرداخت حقوق (S11.T11.2): پرداختیِ ما ----
        $pl = $pdo->prepare("SELECT x.id, x.amount, x.ccy, x.due_date, x.paid, x.cheque_ref, e.name AS party FROM payroll_pay_lines x JOIN payroll_lines l ON l.id = x.line_id AND l.customer_id = x.customer_id JOIN employees e ON e.id = l.employee_id AND e.customer_id = l.customer_id WHERE x.customer_id = ?");
        $pl->execute([$cid]);
        foreach ($pl->fetchAll() as $r) {
            $isPaid = (int)$r['paid'] === 1;
            if (!$includePaid && $isPaid) continue;
            $out[] = ['table' => 'payroll_pay_lines', 'id' => (int)$r['id'], 'receivable' => false, 'kind' => 'حقوق',
                'party' => partyKey($r['party']), 'label' => 'حقوق ' . $r['ccy'], 'ref' => $r['cheque_ref'], 'bank' => null,
                'amount' => (float)$r['amount'], 'date' => $r['due_date'], 'paid' => $isPaid];
        }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
        out(['items' => $out]);
    }

    case 'dues.mark_paid': {
        if ((string)($payload['table'] ?? '') === 'loan_dues') {
            // سررسید قرض: بسته می‌شود و بازپرداخت متناظر (حداکثر تا مانده‌ی قرض) خودکار در دفتر ثبت می‌شود
            $id = (int)($payload['id'] ?? 0);
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT d.id, d.loan_uid, d.seq, d.amount, d.paid, l.kind, l.party FROM loan_dues d JOIN loan_entries l ON l.uid = d.loan_uid AND l.customer_id = d.customer_id WHERE d.id=? AND d.customer_id=? FOR UPDATE');
            $q->execute([$id, $cid]);
            $d = $q->fetch();
            if (!$d) { $pdo->rollBack(); bad('این سررسید یافت نشد', 404); }
            if ((int)$d['paid'] !== 1) {
                $rep = $d['kind'] === 'given' ? 'repay_in' : 'repay_out';
                $o = $pdo->prepare('SELECT COALESCE(SUM(CASE WHEN kind=? THEN amount WHEN kind=? THEN -amount ELSE 0 END),0) FROM loan_entries WHERE customer_id=? AND party=?');
                $o->execute([$d['kind'], $rep, $cid, $d['party']]);
                $owed = (float)$o->fetchColumn();
                $pay = min((float)$d['amount'], $owed);
                $pdo->prepare('UPDATE loan_dues SET paid=1 WHERE id=? AND customer_id=?')->execute([$id, $cid]);
                if ($pay > 0.5) {
                    $ruid = 'R' . substr(hash('sha256', $d['loan_uid'] . '|' . $d['seq']), 0, 24);
                    $pdo->prepare('INSERT IGNORE INTO loan_entries (customer_id, uid, party, kind, amount, date, note, created_at) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$cid, $ruid, $d['party'], $rep, round($pay, 2), date('Y-m-d'), 'تسویهٔ سررسید ' . (int)$d['seq'], date('Y-m-d H:i:s')]); // تاریخ از ساعت تهرانِ PHP، نه ساعت MySQL
                    pb_plb_loan_post($pdo, $customer, $ruid); // S16.T16.4
                }
            }
            $pdo->commit();
            out(['ok' => true]);
        }
        if ((string)($payload['table'] ?? '') === 'payroll_pay_lines') { // S11.T11.2
            $paidP = !empty($payload['paid']) ? 1 : 0;
            $pdo->prepare('UPDATE payroll_pay_lines SET paid=?, paid_date=IF(?=1, ?, NULL) WHERE id=? AND customer_id=?')
                ->execute([$paidP, $paidP, date('Y-m-d'), (int)($payload['id'] ?? 0), $cid]);
            if (function_exists('pb_plc_post')) { // S16.T16.5b2 (gold): paid => voucher, unpaid => removed
                if ($paidP === 1) pb_plc_post($pdo, $cid, 'payroll_pay', (int)($payload['id'] ?? 0));
                else pb_plc_unpost($pdo, $cid, 'payroll_pay', (int)($payload['id'] ?? 0));
            }
            out(['ok' => true]);
        }
        $allowed = ['lump_tx', 'credit_inst', 'cheque_inst', 'receipt_lump_tx', 'receipt_credit_inst', 'receipt_cheque_inst'];
        $table = (string)($payload['table'] ?? '');
        if (!in_array($table, $allowed, true)) bad('جدول نامعتبر');
        $id = (int)($payload['id'] ?? 0);
        $paid = !empty($payload['paid']) ? 1 : 0;
        // v71: paying a row reduced by return credit makes the reduction real (amount -= credit_used); un-pay restores the original amount
        $pdo->beginTransaction();
        try { pb_dues_set_paid($pdo, $cid, $table, $id, $paid === 1); $pdo->commit(); } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        pb_plb_mark_paid_hook($pdo, $customer, $table, $id); // S16.T16.4 (gold): installments / lump parent doc / cheque lifecycle
        out(['ok' => true]);
    }

    // ---------------- حساب طرف‌ها ----------------
    case 'parties.list': {
        $map = pb_party_balance_sums($pdo, $cid); // S35.T35.7e: extracted aggregation (same sums)
        $acc = function ($name) use (&$map) {
            $k = partyKey($name);
            if (!isset($map[$k])) $map[$k] = ['key' => $k, 'bought' => 0, 'paid' => 0, 'sold' => 0, 'received' => 0, 'entries' => [],
                'loans' => [], 'dues' => [], 'loanGiven' => 0, 'loanTaken' => 0, 'repaidToUs' => 0, 'repaidByUs' => 0];
            return $k;
        };
        // طرف‌حساب‌های تعریف‌شدهٔ دستی (حتی بدون خرید/فروش)
        pb_party_sync($pdo, $cid); // T03.1: lazily create master rows for names used in purchases/sales/loans
        $ps = $pdo->prepare('SELECT * FROM parties WHERE customer_id=?');
        $ps->execute([$cid]);
        $master = [];
        foreach ($ps->fetchAll() as $r) { $acc($r['name']); $master[partyKey($r['name'])] = $r; }
        // دفتر قرض و وام (در سود و زیان حساب نمی‌شود)
        $ls = $pdo->prepare('SELECT id, uid, party, kind, amount, date, note FROM loan_entries WHERE customer_id=? ORDER BY date DESC, id DESC');
        $ls->execute([$cid]);
        foreach ($ls->fetchAll() as $r) {
            $k = $acc($r['party']);
            $amt = (float)$r['amount'];
            if ($r['kind'] === 'given') $map[$k]['loanGiven'] += $amt;
            elseif ($r['kind'] === 'taken') $map[$k]['loanTaken'] += $amt;
            elseif ($r['kind'] === 'repay_in') $map[$k]['repaidToUs'] += $amt;
            elseif ($r['kind'] === 'repay_out') $map[$k]['repaidByUs'] += $amt;
            else continue;
            $map[$k]['loans'][] = ['id' => (int)$r['id'], 'uid' => $r['uid'], 'kind' => $r['kind'], 'amount' => $amt, 'date' => $r['date'], 'note' => $r['note']];
        }
        $dd = $pdo->prepare('SELECT d.id, d.loan_uid, d.seq, d.due_date, d.amount, d.note, d.paid, l.party, l.kind FROM loan_dues d JOIN loan_entries l ON l.uid = d.loan_uid AND l.customer_id = d.customer_id WHERE d.customer_id=? ORDER BY d.due_date, d.id');
        $dd->execute([$cid]);
        foreach ($dd->fetchAll() as $r) {
            $k = $acc($r['party']);
            $map[$k]['dues'][] = ['id' => (int)$r['id'], 'loan_uid' => $r['loan_uid'], 'seq' => (int)$r['seq'], 'due_date' => $r['due_date'],
                'amount' => (float)$r['amount'], 'note' => $r['note'], 'paid' => (int)$r['paid'] === 1, 'kind' => $r['kind']];
        }
        $list = array_values($map);
        foreach ($list as &$a) {
            $a['weOwe'] = $a['bought'] - $a['paid'];
            $a['theyOwe'] = $a['sold'] - $a['received'];
            $a['net'] = $a['theyOwe'] - $a['weOwe'];
            $a['loanReceivable'] = $a['loanGiven'] - $a['repaidToUs'];
            $a['loanPayable'] = $a['loanTaken'] - $a['repaidByUs'];
            $a['loanNet'] = $a['loanReceivable'] - $a['loanPayable'];
            $a['totalNet'] = $a['net'] + $a['loanNet'];
            $a += pb_party_public($master[$a['key']] ?? null); // T03.1 (additive fields)
            $a['balanceWithOpening'] = $a['totalNet'] + $a['opening']; // totalNet itself is unchanged
            usort($a['entries'], fn($x, $y) => strcmp($y['date'], $x['date']));
        }
        unset($a);
        usort($list, fn($a, $b) => abs($b['totalNet']) <=> abs($a['totalNet']));
        out(['items' => $list]);
    }

    // ---------------- گزارش‌ها ----------------
    case 'reports.tx_all': {
        // گزارش کلی همهٔ تراکنش‌های پرداخت (kind=payments) یا دریافت (kind=receipts)، شامل یکجا/قسط/چک و تسویه‌شده یا باقی‌مانده
        $from = (string)($payload['from'] ?? '');
        $to = (string)($payload['to'] ?? '');
        $isPay = ($payload['kind'] ?? 'payments') === 'payments';
        $defs = $isPay ? [
            ['یکجا', 'lump_tx', 'x.date', 'payment_id', 'payments', 'purchase_id', 'purchases p ON p.id = pay.purchase_id AND p.customer_id = pay.customer_id JOIN categories c ON c.id = p.category_id AND c.customer_id = p.customer_id', "CONCAT(p.type_name, ' (', c.name, ')')"],
            ['قسط', 'credit_inst', 'x.due_date', 'payment_id', 'payments', 'purchase_id', 'purchases p ON p.id = pay.purchase_id AND p.customer_id = pay.customer_id JOIN categories c ON c.id = p.category_id AND c.customer_id = p.customer_id', "CONCAT(p.type_name, ' (', c.name, ')')"],
            ['چک', 'cheque_inst', 'x.due_date', 'payment_id', 'payments', 'purchase_id', 'purchases p ON p.id = pay.purchase_id AND p.customer_id = pay.customer_id JOIN categories c ON c.id = p.category_id AND c.customer_id = p.customer_id', "CONCAT(p.type_name, ' (', c.name, ')')"],
        ] : [
            ['یکجا', 'receipt_lump_tx', 'x.date', 'receipt_id', 'receipts', 'sale_id', 'sales s ON s.id = pay.sale_id AND s.customer_id = pay.customer_id', "COALESCE(s.buyer, 'بدون نام')"],
            ['قسط', 'receipt_credit_inst', 'x.due_date', 'receipt_id', 'receipts', 'sale_id', 'sales s ON s.id = pay.sale_id AND s.customer_id = pay.customer_id', "COALESCE(s.buyer, 'بدون نام')"],
            ['چک', 'receipt_cheque_inst', 'x.due_date', 'receipt_id', 'receipts', 'sale_id', 'sales s ON s.id = pay.sale_id AND s.customer_id = pay.customer_id', "COALESCE(s.buyer, 'بدون نام')"],
        ];
        $rows = [];
        // نام (فروشنده/خریدار) و کد پسته؛ در نبود نام، خط تیره
        $nameExpr = $isPay ? "COALESCE(NULLIF(TRIM(p.seller), ''), '-')" : "COALESCE(NULLIF(TRIM(s.buyer), ''), '-')";
        $codeExpr = $isPay ? "COALESCE(p.code, '-')"
            : "COALESCE((SELECT GROUP_CONCAT(pp.code SEPARATOR '، ') FROM sale_items si JOIN purchases pp ON pp.id = si.purchase_id AND pp.customer_id = si.customer_id WHERE si.sale_id = s.id AND si.customer_id = s.customer_id), '-')";
        foreach ($defs as [$kind, $tbl, $dateCol, $fk, $parent, $pk, $joinRest, $labelExpr]) {
            $sql = "SELECT $dateCol AS date, x.amount AS amount, x.paid AS paid, $labelExpr AS label, $nameExpr AS name, $codeExpr AS code
                    FROM $tbl x JOIN $parent pay ON pay.id = x.$fk AND pay.customer_id = x.customer_id JOIN $joinRest WHERE x.customer_id=?";
            $st = $pdo->prepare($sql);
            $st->execute([$cid]);
            foreach ($st->fetchAll() as $r) {
                if ($from !== '' && $r['date'] < $from) continue;
                if ($to !== '' && $r['date'] > $to) continue;
                $rows[] = ['kind' => $kind, 'date' => $r['date'], 'amount' => (float)$r['amount'], 'paid' => (int)$r['paid'], 'label' => $r['label'], 'name' => $r['name'], 'code' => $r['code']];
            }
        }
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
        out(['items' => $rows]);
    }

    // ---------------- حساب کاربری / پشتیبان‌گیری ----------------
    case 'account.set_name': {
        $name = clean_person_name((string)($payload['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) bad('نام باید بین ۲ تا ۶۰ حرف باشد');
        $pdo->prepare('UPDATE customers SET name = ? WHERE id = ?')->execute([$name, $cid]);
        out(['ok' => true, 'name' => $name]);
    }

    case 'account.set_password': {
        // تعیین/تغییر رمز توسط خود کاربر. با هر تغییر، توکن ورود عوض می‌شود: همهٔ دستگاه‌های دیگر
        // خارج می‌شوند و همین دستگاه با توکن جدید (new_token) وارد می‌ماند.
        ensure_password_column($pdo);
        // LIMITS: سقف تغییر رمز در روز
        if (!pb_pwchange_allowed($pdo, (int)$cid)) bad(PB_MSG_PWCHANGE_BLOCKED, 400);
        $new = (string)($payload['new_password'] ?? '');
        $cur = (string)($payload['current_password'] ?? '');
        $err = password_policy_error($new);
        if ($err !== null) bad($err);

        $st = $pdo->prepare('SELECT has_password, password_hash FROM customers WHERE id = ?');
        $st->execute([$cid]);
        $row = $st->fetch();
        if (!$row) bad('نشست شما نامعتبر است، دوباره وارد شوید', 401);
        if ((int)$row['has_password'] === 1) {
            $tk = 'pw_change:' . $cid;
            throttle_check($tk, 8, 900);
            if ($cur === '' || !password_verify($cur, (string)$row['password_hash'])) {
                throttle_fail($tk);
                bad('رمز فعلی اشتباه است'); // عمداً 400 (نه 401/403) تا اپ کاربر را خارج نکند
            }
            throttle_reset($tk);
        }
        // STAGE14: رمز + باطل‌سازی نشست‌ها + نشست جدید در یک تراکنش
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE customers SET password_hash = ?, has_password = 1 WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $cid]);
            $newToken = tok_password_token($pdo, (int)$cid);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        pb_pwchange_done($pdo, (int)$cid); // LIMITS
        pb_notify_password_changed_by_id($pdo, (int)$cid, 'شما'); // SMS
        out(['ok' => true, 'new_token' => $newToken]);
    }

    case 'account.reset_password': {
        // «فراموشی رمز»: کاربرِ واردشده (توکن معتبر) با کد پیامکیِ ارسال‌شده به همین شماره، رمز جدید می‌گذارد؛
        // رمز فعلی لازم نیست. مثل set_password توکن عوض می‌شود تا دستگاه‌های دیگر خارج شوند.
        // خطاهای کد عمداً 400/429 هستند (نه 401) تا اپ فکر نکند نشست نامعتبر است.
        ensure_password_column($pdo);
        ensure_otp_table($pdo);
        // LIMITS: سقف تغییر رمز در روز
        if (!pb_pwchange_allowed($pdo, (int)$cid)) bad(PB_MSG_PWCHANGE_BLOCKED, 400);
        $new = (string)($payload['new_password'] ?? '');
        $err = password_policy_error($new);
        if ($err !== null) bad($err);
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹']; $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩']; $en = ['0','1','2','3','4','5','6','7','8','9'];
        $code = preg_replace('/[^0-9]/', '', str_replace($ar, $en, str_replace($fa, $en, (string)($payload['code'] ?? '')))) ?? '';
        if ($code === '' || strlen($code) > 12) bad('کد پیامکی را درست وارد کنید');

        $tk = 'pw_reset:' . $cid;
        throttle_check($tk, 8, 900);
        $nowStr = date('Y-m-d H:i:s');
        $st = $pdo->prepare('SELECT * FROM otp_codes WHERE phone = ? AND used = 0 AND expires_at >= ? ORDER BY id DESC LIMIT 1');
        $st->execute([$customer['phone'], $nowStr]);
        $otp = $st->fetch();
        if (!$otp) { throttle_fail($tk); bad('کد منقضی شده است. دوباره کد بگیرید.'); }
        if ((int)$otp['attempts'] >= OTP_MAX_ATTEMPTS) {
            $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?')->execute([$otp['id']]);
            bad('تعداد تلاش اشتباه زیاد بود. دوباره کد بگیرید.', 429);
        }
        $given = otp_hash((string)$customer['phone'], $code);
        $okCode = hash_equals((string)$otp['code_hash'], $given)
            || ($otp['code_hash2'] !== null && hash_equals((string)$otp['code_hash2'], $given));
        if (!$okCode) {
            $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
            throttle_fail($tk);
            bad('کد اشتباه است.');
        }
        $use = $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = ? AND used = 0');
        $use->execute([$otp['id']]);
        if ($use->rowCount() !== 1) bad('این کد قبلاً استفاده شده است. دوباره کد بگیرید.');
        throttle_reset($tk);

        // STAGE14: رمز + باطل‌سازی نشست‌ها + نشست جدید در یک تراکنش
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE customers SET password_hash = ?, has_password = 1 WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $cid]);
            $newToken = tok_password_token($pdo, (int)$cid);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        pb_pwchange_done($pdo, (int)$cid); // LIMITS
        pb_notify_password_changed_by_id($pdo, (int)$cid, 'شما'); // SMS
        out(['ok' => true, 'new_token' => $newToken]);
    }

    case 'account.status': {
        out(['item' => [
            'phone' => $customer['phone'],
            'name' => $customer['name'],
            'has_password' => (int)($customer['has_password'] ?? 0) === 1,
            'staff' => pb_staff_public($customer), // S02: null = مالک؛ برای کارکن نقش و مجوزها
            'active' => (bool)$customer['active'],
            'plan' => $plan,
            'edition' => $edition,
            'expiry_date' => $customer['expiry_date'],
            'days_left' => (int)floor((strtotime((string)$customer['expiry_date']) - strtotime('today')) / 86400),
        ]]);
    }

    // ---------------- پیام‌های مدیر (تب خانه) ----------------
    case 'messages.list': {
        $st = $pdo->prepare("SELECT a.id, a.title, a.body, a.link_url, a.link_label, a.kind, a.created_at, (r.announcement_id IS NOT NULL) AS is_read
            FROM announcements a LEFT JOIN announcement_reads r ON r.announcement_id = a.id AND r.customer_id = ?
            WHERE a.active = 1 AND (a.customer_id IS NULL OR a.customer_id = ?)
              AND (a.expires_at IS NULL OR a.expires_at > NOW()) AND a.created_at >= (NOW() - INTERVAL 60 DAY)
            ORDER BY a.id DESC LIMIT 30");
        $st->execute([$cid, $cid]);
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $items[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body'], 'link_url' => $r['link_url'],
                'link_label' => $r['link_label'], 'kind' => $r['kind'], 'created_at' => $r['created_at'], 'read' => (int)$r['is_read'] === 1];
        }
        out(['items' => $items]);
    }

    case 'messages.read': {
        $mid = (int)($payload['id'] ?? 0);
        if ($mid > 0) {
            $pdo->prepare('INSERT IGNORE INTO announcement_reads (customer_id, announcement_id, read_at) VALUES (?,?,NOW())')->execute([$cid, $mid]);
        }
        out(['ok' => true]);
    }

    // ---------------- طرف‌حساب‌ها و دفتر قرض/وام (جدا از سود و زیان) ----------------
    case 'parties.create': {
        $name = partyKey($payload['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) bad('نام طرف‌حساب را (حداکثر ۱۰۰ حرف) وارد کنید');
        $st = $pdo->prepare('SELECT id FROM parties WHERE customer_id=? AND name=?');
        $st->execute([$cid, $name]);
        $ex = $st->fetchColumn();
        if ($ex) out(['id' => (int)$ex]); // تکراری (ارسال مجدد): همان قبلی
        $pdo->prepare('INSERT INTO parties (customer_id, name) VALUES (?,?)')->execute([$cid, $name]);
        pb_taf_hook($pdo, $cid, 'party', $name, $name);
        out(['id' => (int)$pdo->lastInsertId()]);
    }

    case 'parties.save': {
        pb_party_save($pdo, $cid, $payload);
    }
    case 'parties.statement': pb_party_statement($pdo, $cid, $payload); break; // T03.2 (out() exits)
    case 'parties.aging': pb_party_aging($pdo, $cid, $payload); break; // T03.2
    case 'purchase_returns.create': out(pb_preturn_create($pdo, $cid, $payload, $customer)); // S04.T04.1
    case 'purchase_returns.delete': out(pb_preturn_delete($pdo, $cid, $payload, $customer));
    case 'purchase_returns.list': out(pb_preturn_list($pdo, $cid, $payload));
    case 'purchase_returns.get': out(pb_preturn_get($pdo, $cid, $payload));
    case 'sale_returns.create': out(pb_sreturn_create($pdo, $cid, $payload, $customer)); // S04.T04.2
    case 'sale_returns.delete': out(pb_sreturn_delete($pdo, $cid, $payload, $customer));
    case 'sale_returns.list': out(pb_sreturn_list($pdo, $cid, $payload));
    case 'sale_returns.get': out(pb_sreturn_get($pdo, $cid, $payload));
    case 'sale_returns.summary': out(pb_sreturn_summary($pdo, $cid, $payload));
    case 'sale_returns.profit_lines': out(pb_sreturn_profit_lines($pdo, $cid, $payload)); // برگشت از فروش در سود و زیان
    case 'proformas.save': out(pb_proforma_save($pdo, $cid, $payload)); // S04.T04.3
    case 'proformas.set_status': out(pb_proforma_set_status($pdo, $cid, $payload));
    case 'proformas.delete': out(pb_proforma_delete($pdo, $cid, $payload));
    case 'proformas.list': out(pb_proforma_list($pdo, $cid, $payload));
    case 'proformas.get': out(pb_proforma_get($pdo, $cid, $payload));
    case 'proformas.convert': out(pb_proforma_convert($pdo, $cid, $payload, $isFx));
    case 'proformas.link_sales': out(pb_proforma_link_sales($pdo, $cid, $payload)); // S04.T04.4c
    case 'accounts.list': case 'accounts.save': case 'accounts.delete': case 'accounts.roles': case 'accounts.roles_save':
    case 'tafsili.list': case 'tafsili.save': case 'tafsili.delete': case 'tafsili.sync': pb_acc_action($action, $payload, $pdo, $customer); break; // S15.T15.2
    case 'vouchers.list': case 'vouchers.get': case 'vouchers.save': case 'vouchers.set_status': case 'vouchers.delete': case 'vouchers.copy': pb_voucher_action($action, $payload, $pdo, $customer); break; // S16.T16.1
    case 'posting.preview': pb_preview_action($payload, $pdo, $customer); break; // S35.T35.9d (read-only dry-run)
    case 'posting.post': case 'posting.unpost': pb_posting_action($action, $payload, $pdo, $customer); break; // S16.T16.2
    case 'reports.vat': case 'vat.settle': pb_vat_action($action, $payload, $pdo, $customer); break; // S17.T17.2
    case 'reports.journal': case 'reports.ledger': case 'reports.tafsili': pb_lr_action($action, $payload, $pdo, $customer); break; // S18.T18.1
    case 'reports.ebook': require_once __DIR__ . '/ebook.php'; pb_eb_action($action, $payload, $pdo, $customer); break; // S18.T18.4
    case 'reports.fx': pb_fxrep_action($action, $payload, $pdo, $customer); break; // S30.T30.4
    case 'reports.trial': pb_tb_action($action, $payload, $pdo, $customer); break; // S18.T18.2
    case 'bank_recon.list': case 'bank_recon.report': case 'bank_recon.import': case 'bank_recon.auto': case 'bank_recon.match': case 'bank_recon.unmatch': case 'bank_recon.ignore': pb_bank_recon_action($action, $payload, $pdo, $customer); break; // S31.T31.1
    case '2fa.status': case '2fa.setup': case '2fa.enable': case '2fa.disable': case '2fa.recovery_regen': case 'sessions.list': case 'sessions.revoke': case 'sessions.revoke_others': pb_2fa_action($action, $payload, $pdo, $customer, $token); break; // S31.T31.19 (owner only, checked in handler)
    case 'error.report': case 'error.list': case 'error.summary': case 'error.resolve': pb_err_action($action, $payload, $pdo, $customer); break; // S31.T31.18
    case 'backup_drill.run': case 'backup_drill.list': case 'backup_drill.status': pb_drill_action($action, $payload, $pdo, $customer); break; // S31.T31.15
    case 'reminders.due': pb_rem_action($action, $payload, $pdo, $customer); break; // S31.T31.14
    case 'kpi.dashboard': pb_kpi_action($action, $payload, $pdo, $customer); break; // S31.T31.13
    case 'lc.list': case 'lc.save': case 'lc.set_status': case 'lc.delete': case 'lc.deadlines': pb_lc_action($action, $payload, $pdo, $customer); break; // S31.T31.12
    case 'sale_export.get': case 'sale_export.save': case 'sale_export.docs': pb_sx_action($action, $payload, $pdo, $customer); break; // S31.T31.10
    case 'lot_chain.get': pb_chain_action($action, $payload, $pdo, $customer); break; // T4c
    case 'lot_trace.get': case 'reports.orchard_flow': pb_lt_action($action, $payload, $pdo, $customer); break; // S31.T31.7
    case 'daily_recon.get': pb_dr_action($action, $payload, $pdo, $customer); break; // S31.T31.4
    case 'pay_approval.list': case 'pay_approval.set_limit': case 'pay_approval.approve': case 'pay_approval.reject': pb_payappr_action($action, $payload, $pdo, $customer); break; // S31.T31.3 (owner only)
    case 'period_lock.get': case 'period_lock.close': case 'period_lock.reopen': pb_period_lock_action($action, $payload, $pdo, $customer); break; // S31.T31.2
    case 'treasury.balances': pb_tr_action($action, $payload, $pdo, $customer); break; // S28.T28.1
    case 'reports.income': case 'reports.balance': case 'reports.cc_pnl': case 'reports.instant': case 'reports.margin': case 'statements.map': case 'statements.map_save': pb_st_action($action, $payload, $pdo, $customer); break; // S18.T18.3
    case 'reports.crack_profit': pb_cp_action($action, $payload, $pdo, $customer); break; // CP2: سود و زیان خط شکست (gold + profit.view)
    case 'tier.upgrade_preview': case 'tier.upgrade_apply': case 'tier.upgrade_rollback': case 'tier.upgrade_status': pb_ug_action($action, $payload, $pdo, $customer); break; // S20.T20.1
    case 'accounting.fx_reval.preview': case 'accounting.fx_reval.apply': case 'accounting.fx_reval.reverse': case 'accounting.fx_reval.list': case 'accounting.fx_reval.detail': case 'accounting.fx_reval.check': case 'accounting.fx_reval.export': case 'accounting.fx_reval.docs': pb_fxr_action($action, $payload, $pdo, $customer); break; // S30.T30.2
    case 'accounting.rebuild': case 'accounting.integrity': pb_acct_action($action, $payload, $pdo, $customer); break; // S16.T16.6
    case 'fy.close_preview': case 'fy.close': case 'fy.lock': case 'fy.reopen': case 'fy.open_next': case 'fy.open_undo': require_once __DIR__ . '/fy_close.php'; pb_fc_action($action, $payload, $pdo, $customer); break; // S19.T19.2
    case 'fiscal_years.list': case 'fiscal_years.save': case 'fiscal_years.create': case 'fiscal_years.set_current': pb_fy_action($action, $payload, $pdo, $customer); break; // S15.T15.1
    case 'import.commit': pb_import_commit($pdo, $cid, $customer, $payload); break; // S13.T13.2b (own batch idempotency; perm per kind inside)
    case 'broker_rules.list': out(pb_broker_rules_list($pdo, $cid, $payload)); // S05.T05.1
    case 'broker_rules.save': out(pb_broker_rules_save($pdo, $cid, $payload));
    case 'broker_rules.delete': out(pb_broker_rules_delete($pdo, $cid, $payload));
    case 'broker_payments.list': out(pb_broker_pay_list($pdo, $cid, $payload));
    case 'broker_payments.save': out(pb_broker_pay_save($pdo, $cid, $payload));
    case 'broker_payments.delete': out(pb_broker_pay_delete($pdo, $cid, $payload));
    case 'reports.broker_commission': out(pb_broker_report($pdo, $cid, $payload));
    case 'cheques.list': out(pb_cheques_list($pdo, $cid, $payload)); // S06.T06.1
    case 'cheques.get': out(pb_cheques_get($pdo, $cid, $payload));
    case 'cheques.report': out(pb_cheques_report($pdo, $cid, $payload)); // S06.T06.3
    case 'cheques.transition': if (!empty($payload['date'])) pb_fy_guard($pdo, $cid, $customer, vdate($payload['date'])); out(pb_cheques_transition($pdo, $cid, $payload));
    case 'consignments.list': out(pb_cons_list($pdo, $cid, $payload)); // S08.T08.1
    case 'consignments.get': out(pb_cons_get($pdo, $cid, $payload));
    case 'consignments.create': out(pb_cons_create($pdo, $cid, $payload));
    case 'consignments.delete': out(pb_cons_delete($pdo, $cid, $payload));
    case 'consignments.return': out(pb_cons_return($pdo, $cid, $payload));
    case 'consignments.return_delete': out(pb_cons_return_delete($pdo, $cid, $payload));
    case 'consignments.pay': out(pb_cons_pay($pdo, $cid, $payload));
    case 'consignments.pay_delete': out(pb_cons_pay_delete($pdo, $cid, $payload));
    case 'consignments.sold': out(pb_consout_sold($pdo, $cid, $payload, $customer)); // S08.T08.2: consignee sold report -> sale via pb_sale_create_core
    case 'consignments.statement': out(pb_cons_statement($pdo, $cid, $payload));
    case 'processing.list': out(pb_ws_gate(pb_proc_list($pdo, $cid, $payload), $customer)); // S09.T09.1؛ بهای کارگاه فقط با workshop.cost
    case 'processing.get': out(pb_ws_gate(pb_proc_get($pdo, $cid, $payload), $customer));
    case 'processing.create': unset($payload['_can_cost']); $payload['_can_cost'] = pb_ws_can_cost($customer); out(pb_ws_gate(pb_proc_create($pdo, $cid, $payload), $customer));
    case 'processing.receive': out(pb_proc_receive($pdo, $cid, $payload));
    case 'processing.deliver': out(pb_proc_deliver($pdo, $cid, $payload));
    case 'processing.delete': out(pb_proc_delete($pdo, $cid, $payload));
    case 'workshop.list': out(pb_ws_gate(pb_ws_list($pdo, $cid, $payload), $customer)); // کارگاه داخلی؛ بها فقط با workshop.cost + price.view
    case 'workshop.get': out(pb_ws_gate(pb_ws_get($pdo, $cid, $payload), $customer));
    case 'workshop.lots': out(pb_ws_lots($pdo, $cid, $payload));
    case 'workshop.create': unset($payload['_can_cost']); $payload['_can_cost'] = pb_ws_can_cost($customer); out(pb_ws_gate(pb_proc_create($pdo, $cid, ['direction' => 'internal'] + $payload), $customer));
    case 'workshop.cost_save': out(pb_ws_cost_save($pdo, $cid, $payload));
    case 'workshop.delete': out(pb_ws_delete($pdo, $cid, $payload));
    case 'workshop.crack_list': out(pb_cr_list($pdo, $cid, $customer, $payload)); // مرحلهٔ ۳: گزارش خط شکست؛ بدون قیمت، مستقل از موجودی
    case 'workshop.crack_get': out(pb_cr_get($pdo, $cid, $customer, $payload));
    case 'workshop.crack_create': out(pb_cr_create($pdo, $cid, $customer, $payload));
    case 'workshop.crack_update': out(pb_cr_update($pdo, $cid, $customer, $payload));
    case 'workshop.crack_delete': out(pb_cr_delete($pdo, $cid, $payload));
    case 'workshop.crack_pool_get': case 'workshop.crack_pool_extra': case 'workshop.crack_pool_close': case 'workshop.crack_pool_reopen': pb_cp_action($action, $payload, $pdo, $customer); break; // CP2: استخر هزینه (gold + profit.view)
    case 'processing.receipt_save': out(pb_ow_receipt_save($pdo, $cid, $payload)); // مرحلهٔ ۴: قبض کارگاه بیرونی (paid)
    case 'workshop.outside_raw': out(pb_ow_raw($pdo, $cid, $payload)); // مرحلهٔ ۴: «فایل خام شکست کارگاه‌ها» (فقط‌خواندنی، بدون قیمت)
    case 'reports.workshop_summary': out(pb_wreport_summary($pdo, $cid, $payload)); // مرحلهٔ ۶-۲: خلاصهٔ مدیریتی (فقط‌خواندنی، بدون قیمت؛ stock.view)
    case 'reports.workshop_compare': out(pb_wreport_compare($pdo, $cid, $payload)); // مرحلهٔ ۶-۲: مقایسهٔ کارگاه‌ها/اپراتورها (workshop.feed)
    case 'reports.cartex': out(pb_cartex_report($pdo, $cid, $payload)); // مرحلهٔ ۶: کارتکس مالک (فقط‌خواندنی، بدون قیمت؛ workshop.feed)
    case 'feed.stock': out(pb_feed_stock($pdo, $cid, $payload)); // مرحلهٔ ۵: موجودی پستهٔ خرجی (نمای جدا؛ بی‌قیمت)
    case 'feed.adjust_list': out(pb_feed_adjust_list($pdo, $cid, $payload));
    case 'feed.adjust_create': out(pb_feed_adjust_create($pdo, $cid, $customer, $payload));
    case 'feed.adjust_delete': out(pb_feed_adjust_delete($pdo, $cid, $payload));
    case 'processing.statement': out(pb_proc_statement($pdo, $cid, $payload)); // S09.T09.2
    case 'reports.processing_summary': out(pb_proc_summary($pdo, $cid, $payload));
    case 'reports.processing_outstanding': out(pb_proc_outstanding($pdo, $cid, $payload));
    case 'orchards.list': out(pb_orch_list($pdo, $cid, $payload)); // S10.T10.1
    case 'orchards.get': out(pb_orch_get($pdo, $cid, $payload));
    case 'orchards.save': out(pb_orch_save($pdo, $cid, $payload));
    case 'orchards.delete': out(pb_orch_delete($pdo, $cid, $payload));
    case 'orchard_costs.add': out(pb_orch_cost_add($pdo, $cid, $payload));
    case 'orchard_costs.delete': out(pb_orch_cost_delete($pdo, $cid, $payload));
    case 'harvests.create': out(pb_orch_harvest_create($pdo, $cid, $payload));
    case 'orchards.allocate': out(pb_orch_allocate($pdo, $cid, $payload));
    case 'reports.orchard_performance': out(pb_orch_performance($pdo, $cid, $payload, $customer)); // S10.T10.2
    case 'payroll.employees': out(pb_pay_employees($pdo, $cid)); // S11.T11.1
    case 'payroll.employee_save': out(pb_pay_employee_save($pdo, $cid, $payload));
    case 'payroll.items': out(pb_pay_items($pdo, $cid));
    case 'payroll.item_save': out(pb_pay_item_save($pdo, $cid, $payload));
    case 'payroll.advance_add': out(pb_pay_advance_add($pdo, $cid, $payload));
    case 'payroll.runs': out(pb_pay_runs($pdo, $cid));
    case 'payroll.run_create': out(pb_pay_run_create($pdo, $cid, $payload, edition_has(edition_of($customer), 'gold.payroll_law'))); // S11.T11.3
    case 'payroll.run_get': out(pb_pay_run_get($pdo, $cid, $payload));
    case 'payroll.line_update': out(pb_pay_line_update($pdo, $cid, $payload, edition_has(edition_of($customer), 'gold.payroll_law')));
    case 'payroll.approve': out(pb_pay_approve($pdo, $cid, $payload, edition_has(edition_of($customer), 'gold.payroll_law')));
    case 'payroll.run_delete': out(pb_pay_run_delete($pdo, $cid, $payload));
    case 'payroll.pay_line_add': out(pb_pay_line_add($pdo, $cid, $payload)); // S11.T11.2
    case 'payroll.pay_line_delete': out(pb_pay_line_delete($pdo, $cid, $payload));
    case 'payroll.pay_lines': out(pb_pay_lines_for_run($pdo, $cid, $payload));
    case 'payroll.pay_unpaid': out(pb_pay_unpaid($pdo, $cid));
    case 'payroll.statement': out(pb_pay_statement($pdo, $cid, $payload));
    case 'payroll.params_get': out(pb_pay_params_view($pdo, $cid, $payload)); // S11.T11.3
    case 'payroll.params_save': require_feature(edition_of($customer), 'gold.payroll_law'); out(pb_pay_params_save($pdo, $cid, $payload));
    case 'payroll.payslip': out(pb_pay_payslip($pdo, $cid, $payload));
    case 'reports.consigned_stock': out(pb_cons_stock_report($pdo, $cid, $payload));
    case 'separations.list': out(pb_sep_list($pdo, $cid, $payload)); // S07.T07.1
    case 'separations.get': out(pb_sep_get($pdo, $cid, $payload));
    case 'separations.create': out(pb_sep_create($pdo, $cid, $payload));
    case 'separations.update': out(pb_sep_update($pdo, $cid, $payload));
    case 'separations.delete': out(pb_sep_delete($pdo, $cid, $payload));
    case 'separations.lineage': out(pb_sep_lineage($pdo, $cid, $payload));
    case 'form_settings.get': out(pb_form_settings_get($pdo, $cid)); break; // S35.T35.1 (both editions)
    case 'form_settings.save': out(pb_form_settings_save($pdo, $cid, $payload)); break;
    case 'code_prefix.get': out(pb_code_prefix_get($pdo, $cid)); break; // T1.3: حرف کد هر دسته (خواندن)
    case 'code_prefix.set': out(pb_code_prefix_set($pdo, $cid, $payload)); break; // T1.3 (settings.manage)
    case 'docs.correct': out(pb_corr_apply($pdo, $cid, $customer, $payload)); break; // S35.T35.9c
    case 'approval.get': out(pb_appr_get($pdo, $cid)); break; // S35.T35.9e
    case 'approval.set_limit': out(pb_appr_set_limit($pdo, $cid, $customer, $payload)); break;
    case 'approval.approve': out(pb_appr_approve($pdo, $cid, $customer, $payload)); break;
    case 'fin.block': out(pb_fin_block_of($pdo, $cid, (string)($payload['doc_type'] ?? ''), (int)($payload['id'] ?? 0))); break; // S35.T35.5
    case 'pay.terms': out(pb_pay_terms_action($pdo, $cid, $payload)); break; // S35.T35.7a
    case 'pay.rate.save': out(pb_pay_rate_save($pdo, $cid, $payload)); break; // S35.T35.7b
    case 'pay.credit_check': out(pb_pay_credit_check($pdo, $cid, $payload)); break; // S35.T35.7e (warn only)
    case 'grade_prices.list': out(pb_gp_list($pdo, $cid, $payload)); break; // S35.T35.4
    case 'grade_prices.save': out(pb_gp_save($pdo, $cid, $payload)); break;
    case 'grade_prices.bulk': out(pb_gp_bulk($pdo, $cid, $payload)); break;
    case 'grade_prices.delete': out(pb_gp_delete($pdo, $cid, $payload)); break;
    case 'grade_prices.suggest': out(pb_gp_suggest($pdo, $cid, $payload)); break;
    case 'fx.currencies.list': require_feature(edition_of($customer), 'fx.rates'); out(pb_fx_currencies_list($pdo, $cid)); break;
    case 'fx.currencies.save': require_feature(edition_of($customer), 'fx.rates'); out(pb_fx_currency_save($pdo, $cid, $payload)); break;
    case 'fx.currencies.deactivate': require_feature(edition_of($customer), 'fx.rates'); out(pb_fx_currency_deactivate($pdo, $cid, $payload)); break;
    case 'fx.rates.list': require_feature(edition_of($customer), 'fx.rates'); out(pb_fx_rates_list($pdo, $cid, $payload)); break;
    case 'fx.rates.save': require_feature(edition_of($customer), 'fx.rates'); out(pb_fx_rate_save($pdo, $cid, $payload)); break;
    case 'fx.rates.delete': require_feature(edition_of($customer), 'fx.rates'); out(pb_fx_rate_delete($pdo, $cid, $payload)); break;
    case 'fx.rates.get_at': require_feature(edition_of($customer), 'fx.rates'); out(pb_fx_rate_get_at($pdo, $cid, $payload)); break;
    case 'fx.adjust.list': require_feature(edition_of($customer), 'fx.documents'); out(pb_fx_adjust_list($pdo, $cid, $payload)); break;
    case 'fx.adjust.preview': require_feature(edition_of($customer), 'fx.documents'); out(pb_fx_adjust_preview($pdo, $cid, $payload)); break;
    case 'fx.adjust.apply': require_feature(edition_of($customer), 'fx.documents'); $adjRes = pb_fx_adjust_apply($pdo, $cid, $customer, $payload); pb_post_document($pdo, $customer, 'fx_adjust', (int)$adjRes['id']); out($adjRes); break; // T30.3c: gold posts the adjustment voucher
    case 'fx.rate_mismatch.list': require_feature(edition_of($customer), 'fx.documents'); out(pb_fx_rate_mismatch_list($pdo, $cid)); break;
    case 'separations.settings': out(pb_sep_settings($pdo, $cid));
    case 'reports.separation_yield': out(pb_sep_yield_report($pdo, $cid, $payload)); // S07.T07.2
    case 'lots.lineage': out(pb_sep_lineage($pdo, $cid, $payload));
    case 'lots.find_code': out(pb_sep_lot_by_code($pdo, $cid, (string)($payload['code'] ?? '')));
    case 'separations.settings_save': out(pb_sep_settings_save($pdo, $cid, $payload));
    case 'broker.attach': out(pb_broker_attach($pdo, $cid, $payload, $customer)); // S05.T05.2

    case 'crm.dashboard': pb_crm_dashboard($pdo, $cid, $customer, $payload); break; // T03.3 (out() exits)

    case 'loans.create': {
        $uid = (string)($payload['uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uid)) bad('شناسهٔ قرض نامعتبر است');
        $party = partyKey($payload['party'] ?? '');
        if ($party === '' || mb_strlen($party) > 150) bad('نام طرف‌حساب را وارد کنید');
        $kind = (string)($payload['kind'] ?? '');
        if (!in_array($kind, LOAN_KINDS, true)) bad('نوع تراکنش قرض نامعتبر است');
        $amount = vamount($payload['amount'] ?? 0);
        if ($amount <= 0) bad('مبلغ را وارد کنید');
        $date = vdate($payload['date'] ?? null);
        $note = str_or_null($payload['note'] ?? null, 500);
        // STAGE11: قفل مشترک با دستیار (pb_as_w_<cid>)؛ شکست قفل = ادامه بدون قفل؛ آزادسازی تضمینی در shutdown
        // درخواست loopback دستیار (idem = 'as'+40hex) خودش قفل را نگه داشته؛ دوباره گرفتن = ۳ ثانیه معطلی بی‌دلیل
        if (function_exists('sg_lock') && !preg_match('/^as[0-9a-f]{40}$/', (string)$idemKey)) sg_lock($pdo, $cid);
        $st = $pdo->prepare('SELECT id FROM loan_entries WHERE customer_id=? AND uid=?');
        $st->execute([$cid, $uid]);
        $ex = $st->fetchColumn();
        if ($ex) out(['id' => (int)$ex]); // ارسال تکراری: دوباره ثبت نمی‌شود
        $dues = $payload['dues'] ?? [];
        if (!is_array($dues) || count($dues) > 20) bad('حداکثر ۲۰ سررسید برای هر قرض مجاز است');
        if ($dues && !in_array($kind, ['given', 'taken'], true)) bad('سررسید فقط برای اعطا یا دریافت قرض ثبت می‌شود');
        $pdo->beginTransaction();
        // STAGE11: بازپرداخت سررسیدی که سرور/وب قبلاً ثبت کرده ⇒ دوباره درج نشود (due_ref یا ابتکار اپ قدیمی)
        if (function_exists('sg_due_dup')) {
            $dupId = sg_due_dup($pdo, $cid, $party, $kind, $amount, $note, $uid, $payload['due_ref'] ?? null);
            if ($dupId !== null) { $pdo->commit(); out(['id' => $dupId]); }
        }
        $pdo->prepare('INSERT INTO loan_entries (customer_id, uid, party, kind, amount, date, note, created_at) VALUES (?,?,?,?,?,?,?,NOW())')
            ->execute([$cid, $uid, $party, $kind, $amount, $date, $note]);
        $newId = (int)$pdo->lastInsertId();
        $insDue = $pdo->prepare('INSERT INTO loan_dues (customer_id, loan_uid, seq, due_date, amount, note, paid) VALUES (?,?,?,?,?,?,0)');
        $seq = 0;
        foreach ($dues as $du) {
            if (!is_array($du)) { $pdo->rollBack(); bad('سررسید نامعتبر است'); }
            $seq++;
            $da = vamount($du['amount'] ?? 0);
            if ($da <= 0) { $pdo->rollBack(); bad('مبلغ هر سررسید را وارد کنید'); }
            $dd = vdate($du['due_date'] ?? null);
            $insDue->execute([$cid, $uid, $seq, $dd, $da, str_or_null($du['note'] ?? null, 500)]);
        }
        pb_plb_loan_post($pdo, $customer, $uid); // S16.T16.4 (gold only; inside the transaction)
        $pdo->commit();
        out(['id' => $newId]);
    }

    case 'loans.delete': {
        $uid = (string)($payload['uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uid)) bad('شناسهٔ قرض نامعتبر است');
        pb_plb_loan_unpost($pdo, $customer, $uid); // S16.T16.4
        $pdo->prepare('DELETE FROM loan_dues WHERE customer_id=? AND loan_uid=?')->execute([$cid, $uid]);
        $pdo->prepare('DELETE FROM loan_entries WHERE customer_id=? AND uid=?')->execute([$cid, $uid]);
        out(['ok' => true]); // اگر وجود نداشت هم موفق است (idempotent)
    }

    case 'loans.due_paid': {
        $uid = (string)($payload['loan_uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uid)) bad('شناسهٔ قرض نامعتبر است');
        $seq = (int)($payload['seq'] ?? 0);
        if ($seq < 1 || $seq > 255) bad('شمارهٔ سررسید نامعتبر است');
        $pdo->prepare('UPDATE loan_dues SET paid=1 WHERE customer_id=? AND loan_uid=? AND seq=?')->execute([$cid, $uid, $seq]);
        out(['ok' => true]);
    }

    case 'backup.export': {
        pb_wh_default_uid($pdo, $cid); // S01: lots without warehouse belong to the default one
        $out = ['exported_at' => date('c'), 'phone' => $customer['phone'], 'money_unit' => pb_money_unit($pdo, $cid), 'tables' => []]; // S29.T29.3
        foreach (BACKUP_TABLES as $table => $cols) {
            $cols = pb_backup_export_cols($pdo, $table, $cols);
            $st = $pdo->prepare("SELECT " . implode(',', $cols) . " FROM `$table` WHERE customer_id=?");
            $st->execute([$cid]);
            $out['tables'][$table] = $st->fetchAll(); // فیلدهای دلاری هم می‌آیند تا بازیابی پشتیبان دادهٔ قدیمیِ دلاری را پاک نکند
        }
        $ents = pb_entity_export($pdo, $cid, $edition); // S00 generic entities (additive key; old clients ignore it)
        if ($ents) $out['entities'] = $ents;
        // S04.T04.4b: proformas are mirrored offline but are not part of BACKUP_TABLES (nullable refs) -> additive `docs` key
        try {
            pb_proforma_ensure($pdo);
            $docs = [];
            foreach ([
                'proformas' => 'id,uid,number,party,date,valid_until,status,reserve,note,terms,converted_at,tax_amount,created_at',
                'proforma_items' => 'id,proforma_id,lot_id,description,kg,unit_price,discount,sale_id',
            ] as $dt => $dc) {
                $dq = $pdo->prepare("SELECT $dc FROM `$dt` WHERE customer_id=?");
                $dq->execute([$cid]);
                $docs[$dt] = $dq->fetchAll();
            }
            try { $docs['cons_terms'] = pb_cons_terms_export($pdo, $cid); } catch (Throwable $e) { /* consignment tables absent ⇒ app falls back to p.price */ } // S36: settlement terms per consigned lot (cost basis offline)
            $out['docs'] = $docs;
        } catch (Throwable $e) { /* never break the export because of an optional mirror */ }
        out($out);
    }

    case 'backup.import': {
        $tables = is_array($payload['tables'] ?? null) ? $payload['tables'] : null;
        if (!$tables) bad('فایل پشتیبان معتبر نیست');
        $plan = validateBackup($tables); // هر خطای ساختاری همین‌جا و «قبل از حذف هر داده» گزارش می‌شود
        // S00: generic entities are restored only when the file carries them (old backups leave them untouched)
        $entPlan = array_key_exists('entities', $payload) ? pb_entity_import_validate($pdo, $cid, $payload['entities'], $edition) : [];

        $pdo->beginTransaction();
        // حذف کامل داده‌های فعلی این کاربر، از فرزند به والد
        foreach (array_reverse(array_keys(BACKUP_TABLES)) as $table) {
            $pdo->prepare("DELETE FROM `$table` WHERE customer_id=?")->execute([$cid]);
        }
        // درج از والد به فرزند با «شناسهٔ جدید»: idهای فایل نگه داشته نمی‌شوند (AUTO_INCREMENT بین همهٔ مشتری‌ها مشترک است)
        // و ارجاع‌ها با نگاشت قدیم→جدید بازنویسی می‌شوند.
        $idMap = [];
        foreach (BACKUP_TABLES as $table => $cols) {
            $idMap[$table] = [];
            $ins = [];
            $useCols = array_values(array_filter($cols, fn($c) => $c !== 'id'));
            $ins = $pdo->prepare("INSERT INTO `$table` (" . implode(',', $useCols) . ") VALUES (" . implode(',', array_fill(0, count($useCols), '?')) . ")");
            foreach ($plan[$table] as $row) {
                $vals = [];
                foreach ($useCols as $c) {
                    if ($c === 'customer_id') { $vals[] = $cid; continue; }
                    if (isset(BACKUP_REFS[$table][$c])) { $vals[] = $idMap[BACKUP_REFS[$table][$c]][(int)$row[$c]]; continue; }
                    if ($table === 'doc_grade_lines' && $c === 'doc_id') { $vals[] = $idMap[$row['doc_type'] === 'sale' ? 'sales' : 'purchases'][(int)$row['doc_id']]; continue; }
                    $vals[] = $row[$c];
                }
                $ins->execute($vals);
                $newId = (int)$pdo->lastInsertId();
                $idMap[$table][(int)$row['id']] = $newId;
                if ($table === 'purchases') {
                    $code = pb_code_with_id(pb_code_letter_for_restore($pdo, $cid, $row, (int)($idMap['categories'][(int)($row['category_id'] ?? 0)] ?? 0)), (int)$newId); // T1
                    $pdo->prepare('UPDATE purchases SET code=? WHERE id=? AND customer_id=?')->execute([$code, $newId, $cid]);
                }
            }
        }
        if (!empty($tables['purchases']) && is_array($tables['purchases'][array_key_first($tables['purchases'])] ?? null)
            && !array_key_exists('small_sacks_remaining', $tables['purchases'][array_key_first($tables['purchases'])])) {
            backfillSacksRemaining($pdo, $cid);
        }
        if ($entPlan) pb_entity_import_apply($pdo, $cid, $entPlan);
        pb_stock_rebuild($pdo, $cid); // S01.T01.2: restored lots/sales -> rebuild ledger
        $pdo->commit();
        out(['ok' => true]);
    }

    case 'stock.reconcile': {
        if (!pb_perm_check($customer, 'stock.view')) bad('دسترسی لازم را ندارید', 403);
        out(pb_stock_reconcile($pdo, $cid));
    }

    case 'stock_count.sheet': case 'stock_count.list': case 'stock_count.get': case 'stock_count.save': case 'stock_count.delete': case 'stock_count.approve': case 'stock_count.submit': case 'stock_count.reopen':
        require_once __DIR__ . '/stock_count.php'; pb_sc_action($action, $payload, $pdo, $customer); break; // S19.T19.1

    case 'stock.transfer': {
        if (!pb_perm_check($customer, 'stock.transfer')) bad('دسترسی لازم را ندارید', 403);
        out(pb_stock_transfer_create($pdo, $cid, $payload));
    }

    case 'stock.transfer_cancel': {
        if (!pb_perm_check($customer, 'stock.transfer')) bad('دسترسی لازم را ندارید', 403);
        pb_stock_transfer_cancel($pdo, $cid, $payload);
        out(['ok' => true]);
    }

    case 'stock.transfers': {
        if (!pb_perm_check($customer, 'stock.view')) bad('دسترسی لازم را ندارید', 403);
        out(['items' => pb_stock_transfers_list($pdo, $cid, $payload)]);
    }

    case 'stock.lot_cartex': {
        if (!pb_perm_check($customer, 'cartex.view')) bad('دسترسی لازم را ندارید', 403);
        out(pb_lot_cartex_report($pdo, $cid, $payload));
    }

    case 'stock.lot_cartex_settings': {
        if (!pb_perm_check($customer, 'cartex.view')) bad('دسترسی لازم را ندارید', 403);
        if (!empty($payload['save'])) {
            if (!pb_perm_check($customer, 'stock.update') && (int)($customer['_staff_id'] ?? 0) > 0) bad('دسترسی لازم را ندارید', 403);
            try { out(['settings' => pb_lot_cartex_settings_save($pdo, $cid, $payload)]); }
            catch (InvalidArgumentException $e) { bad($e->getMessage(), 422); }
        }
        out(['settings' => pb_lot_cartex_settings_get($pdo, $cid)]);
    }

    case 'stock.by_warehouse': {
        if (!pb_perm_check($customer, 'stock.view')) bad('دسترسی لازم را ندارید', 403);
        out(['items' => pb_stock_by_warehouse($pdo, $cid, !empty($payload['lot_id']) ? (int)$payload['lot_id'] : null)]);
    }

    // T01.5 stock reports (read-only; both tiers; perm stock.view)
    case 'reports.stock_ledger': {
        if (!pb_perm_check($customer, 'stock.view')) bad('دسترسی لازم را ندارید', 403);
        pb_stock_ensure($pdo, $cid);
        out(pb_stock_ledger($pdo, $cid, $payload));
    }

    case 'reports.stock_balance': {
        if (!pb_perm_check($customer, 'stock.view')) bad('دسترسی لازم را ندارید', 403);
        pb_stock_ensure($pdo, $cid);
        out(pb_stock_balance($pdo, $cid, $payload));
    }

    case 'reports.stock_mix': {
        if (!pb_perm_check($customer, 'stock.view')) bad('دسترسی لازم را ندارید', 403);
        pb_stock_ensure($pdo, $cid);
        out(pb_stock_mix($pdo, $cid, $payload));
    }

    // S02.T02.1: کارکنان و نقش‌ها (مجوز users.manage در pb_action_perm؛ مالک همیشه مجاز)
    case 'staff.list': case 'staff.save': case 'staff.delete': case 'roles.list': case 'roles.save': {
        pb_staff_action($action, $payload, $pdo, $customer, $edition);
        break;
    }

    case 'audit.run': { // S23.T23.2: read-only Persian health report (perm audit.view via pb_action_perm)
        out(pb_audit_run($pdo, $customer));
    }

    case 'audit.verify': out(pb_audit_verify($pdo, $customer)); break; // S31.T31.5
    case 'audit.list': {
        pb_audit_action($action, $payload, $pdo, $customer);
        break;
    }

    case 'intake.submit': case 'intake.pct_defs.seed': case 'intake.preview_price': case 'intake.confirm': case 'intake.reject': case 'intake.mark_sample': case 'intake.price_settings':
        pb_intake_action($action, $payload, $pdo, $customer); break; // S2/S3 (intake.php)

    case 'entity.list': case 'entity.save': case 'entity.delete': case 'entity.changes': {
        if (($payload['entity'] ?? '') === 'warehouses') pb_wh_default_uid($pdo, $cid); // S01: default warehouse always exists
        if (($payload['entity'] ?? '') === 'pct_defs') pb_intake_seed_defs($pdo, $cid); // S2: four default percent defs always exist
        pb_entity_action($action, $payload, $pdo, $customer, $edition);
        break;
    }

    default:
        bad('اکشن نامعتبر است', 404);
}
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[app.php] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    pb_err_server($e); // S31.T31.18 (never throws)
    out(['error' => 'خطای داخلی سرور. لطفاً دوباره تلاش کنید'], 500);
}
