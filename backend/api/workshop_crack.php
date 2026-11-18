<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
require_once __DIR__ . '/workshop_calc.php';
require_once __DIR__ . '/crack_pool.php'; // CP1: استخر هزینه
/**
 * کارگاه — مرحلهٔ ۳-الف: «گزارش خط شکست» (فرم نوبت کار دستگاه شکست؛ برگهٔ اکسل A..AP).
 * جداول: crack_runs (یک نوبت کار) + crack_stops (فهرست توقف‌ها: علت + دقیقه). مستقل از موجودی انبار اصلی (تصمیم کاربر):
 * هیچ حرکت موجودی/لات نمی‌سازد؛ موجودی جدا برای شکست/برون‌سپاری در مرحلهٔ ۵. هیچ فیلد قیمتی وجود ندارد.
 * محاسبات (N, X, AJ, AL, AP, افت٪, تعداد/مجموع توقف) فقط هنگام خروجی با pb_cr_calc (workshop_calc.php) حساب می‌شوند و ذخیره نمی‌شوند.
 * دسترسی: workshop.crack = ثبت/دیدن (اپراتور فقط ردیف‌های خودش؛ ویرایش فقط ردیف خودش) · workshop.manage = همهٔ ردیف‌ها + حذف.
 */
const PB_CR_STR = ['shift' => 30, 'operator_name' => 100, 'customer_name' => 150, 'line_code' => 40, 'pistachio_type' => 100, 'customer_code' => 40,
    'shipment_code' => 40, 'ounce_by' => 100, 'machine_setting' => 100, 'analyst' => 100, 'note' => 500];
/** ستون‌های عددی نامنفی: [نام، سقف] */
const PB_CR_NUM = ['bale_count' => 1e7, 'input_kg' => 1e9, 'gram_pct' => 100, 'moisture_pct' => 100, 'fines_pct' => 100, 'run_minutes' => 1e5,
    'kernel_kg' => 1e9, 'hull_kg' => 1e9, 'soil_kg' => 1e9, 'hull_left_kg' => 1e9, 'sound_kg' => 1e9, 'wheat_kg' => 1e9, 'super_fine_kg' => 1e9,
    'flower_kg' => 1e9, 'split_kg' => 1e9, 'unbreakable_kg' => 1e9, 'pink_kg' => 1e9, 'waste_kernel_kg' => 1e9, 'initial_kernel_kg' => 1e9, 'power_kwh' => 1e9];

function pb_cr_ensure(PDO $pdo): void {
    $num = '';
    foreach (array_keys(PB_CR_NUM) as $c) {
        if ($c === 'input_kg') continue; // ستون اجباری جدا در DDL
        $num .= "        $c " . (in_array($c, ['gram_pct', 'moisture_pct', 'fines_pct'], true) ? 'DECIMAL(7,3)' : 'DECIMAL(14,3)') . " NULL,\n";
    }
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS crack_runs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        run_date DATE NOT NULL,
        end_date DATE NULL,
        shift VARCHAR(30) NULL,
        operator_name VARCHAR(100) NULL,
        customer_name VARCHAR(150) NULL,
        line_code VARCHAR(40) NULL,
        pistachio_type VARCHAR(100) NULL,
        customer_code VARCHAR(40) NULL,
        shipment_code VARCHAR(40) NULL,
        input_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        ounce_by VARCHAR(100) NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        machine_setting VARCHAR(100) NULL,
        analyst VARCHAR(100) NULL,
$num        note VARCHAR(500) NULL,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_by_name VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_crack_uid (customer_id, uid),
        KEY idx_crack_cust (customer_id, run_date),
        KEY idx_crack_by (customer_id, created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS crack_stops (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        run_id INT UNSIGNED NOT NULL,
        reason VARCHAR(150) NULL,
        minutes DECIMAL(10,2) NOT NULL DEFAULT 0,
        KEY idx_cstop_run (customer_id, run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS crack_run_lots (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        run_id INT UNSIGNED NOT NULL,
        field_key VARCHAR(30) NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        source_lot_id INT UNSIGNED NOT NULL,
        source_warehouse_uid VARCHAR(36) NULL,
        kg DECIMAL(14,3) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_crl_field (customer_id, run_id, field_key),
        KEY idx_crl_lot (customer_id, lot_id),
        KEY idx_crl_run (customer_id, run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_cp_ensure($pdo); // CP1
}

function pb_cr_is_manager(array $customer): bool { return pb_staff_can($customer, 'workshop.manage'); }

// ---------------------------------------------------------------------------
// S5 — ثبت خروجی‌های خط شکست به‌عنوان لات جدید (فقط workshop.manage؛ پیش‌فرض خاموش = بدون کلید `lots` در درخواست).
// payload.lots = {enabled, source_lot_id (الزامی), fields:[kernel_kg|hull_kg|soil_kg|sound_kg|…|waste_kernel_kg],
//                 category_id? (پیش‌فرض = دستهٔ لات مبدأ), warehouse_uid? (لات‌های جدید؛ پیش‌فرض انبار اصلی), source_warehouse_uid?}
// قاعده: مجموع وزن لات‌های جدید از remaining لات مبدأ کم می‌شود (نه کل L) ⇒ جمع موجودی ثابت می‌ماند. گونیِ لات‌ها ۰ است و از لات مبدأ کم نمی‌شود.
// دفتر موجودی: doc_type 'crack' (stock.php) با kindهای crack_out (لات مبدأ) و crack_in (لات‌ها). بهای لات‌ها ۰.
// ---------------------------------------------------------------------------
/** kill-switch: false ⇒ ساخت لات جدید رد می‌شود (حذف/برگرداندن لات‌های موجود همچنان کار می‌کند). */
const PB_CR_LOTS_ENABLED = true;
/** خروجی‌های قابل لات‌شدن: کلید ستون ⇒ برچسب (نام لات). Z (پوست باقی‌مانده) عمداً نیست. */
const PB_CR_LOT_FIELDS = ['kernel_kg' => 'مغز', 'hull_kg' => 'پوست', 'soil_kg' => 'خاک', 'sound_kg' => 'مغز سالم', 'wheat_kg' => 'گندمی و موشو',
    'super_fine_kg' => 'سوپر خیلی ریز', 'flower_kg' => 'گلدار', 'split_kg' => 'لپه و شکسته', 'unbreakable_kg' => 'ناشکن', 'pink_kg' => 'کال سوپر و صورتی', 'waste_kernel_kg' => 'مغز ضایعاتی'];
/** تفکیک مغز (AA..AH): همراه با U لات نمی‌شود (مغز دو بار شمرده می‌شود). */
const PB_CR_LOT_SUB = ['sound_kg', 'wheat_kg', 'super_fine_kg', 'flower_kg', 'split_kg', 'unbreakable_kg', 'pink_kg', 'waste_kernel_kg'];

/** تابع خالص (بدون DB): فهرست خروجی‌های انتخابی ⇒ اقلام لات با وزن روی گرم صحیح. @return array{items:array,total_g:int}|array{error:string} */
function pb_cr_lots_plan(array $cols, array $fields): array {
    $fields = array_values(array_unique(array_map('strval', $fields)));
    if (!$fields) return ['error' => 'حداقل یک خروجی را برای ساخت لات انتخاب کنید'];
    foreach ($fields as $f) if (!isset(PB_CR_LOT_FIELDS[$f])) return ['error' => 'خروجی نامعتبر برای لات: ' . $f];
    if (in_array('kernel_kg', $fields, true) && array_intersect($fields, PB_CR_LOT_SUB)) {
        return ['error' => '«مغز» و تفکیک آن (مغز سالم، گندمی، …) را هم‌زمان لات نکنید؛ مغز دو بار شمرده می‌شود'];
    }
    $items = []; $tot = 0;
    foreach (array_keys(PB_CR_LOT_FIELDS) as $f) { // ترتیب ثابت ⇒ کدها قابل پیش‌بینی
        if (!in_array($f, $fields, true)) continue;
        $g = (int)round(((float)($cols[$f] ?? 0)) * 1000);
        if ($g <= 0) return ['error' => 'برای «' . PB_CR_LOT_FIELDS[$f] . '» وزنی وارد نشده است'];
        $items[] = ['field' => $f, 'label' => PB_CR_LOT_FIELDS[$f], 'g' => $g, 'kg' => $g / 1000];
        $tot += $g;
    }
    return ['items' => $items, 'total_g' => $tot];
}

/** پدر کد لات‌ها: کد محموله ← کد مشتری ← CR<id>؛ فقط حروف/ارقام/_/-. */
function pb_cr_lot_parent(array $cols, int $runId): string {
    foreach (['shipment_code', 'customer_code'] as $k) {
        $v = preg_replace('/[^\p{L}\p{N}_\-]/u', '', (string)($cols[$k] ?? ''));
        if ($v !== null && $v !== '') return mb_substr($v, 0, 30);
    }
    return 'CR' . $runId;
}

function pb_cr_fail(PDO $pdo, string $msg, int $code = 400): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pb_proc_fail($msg, $code);
}

/** ردیف‌های پیوند لات یک گزارش. */
function pb_cr_links(PDO $pdo, int $cid, int $runId): array {
    if ($runId <= 0) return [];
    $st = $pdo->prepare('SELECT * FROM crack_run_lots WHERE customer_id=? AND run_id=? ORDER BY id');
    $st->execute([$cid, $runId]);
    return $st->fetchAll();
}

/** لات‌های ساخته‌شدهٔ چند گزارش ⇒ [run_id => [{lot_id, code, field_key, kg, source_lot_id}]] (بدون قیمت). */
function pb_cr_lots_of(PDO $pdo, int $cid, array $runIds): array {
    $by = [];
    if (!$runIds) return $by;
    $in = implode(',', array_map('intval', $runIds));
    $st = $pdo->query('SELECT l.run_id, l.field_key, l.lot_id, l.kg, l.source_lot_id, p.code FROM crack_run_lots l LEFT JOIN purchases p ON p.id=l.lot_id AND p.customer_id=l.customer_id
        WHERE l.customer_id=' . (int)$cid . " AND l.run_id IN ($in) ORDER BY l.id");
    foreach ($st->fetchAll() as $r) {
        $by[(int)$r['run_id']][] = ['lot_id' => (int)$r['lot_id'], 'code' => $r['code'], 'field_key' => $r['field_key'], 'kg' => (float)$r['kg'], 'source_lot_id' => (int)$r['source_lot_id']];
    }
    return $by;
}

/** کدام لات (کد) از لات‌های این گزارش دست‌خورده است (فروش/انتقال/مصرف/تغییر مانده)؛ هیچ‌کدام ⇒ null. آینهٔ pb_ws_outputs_used. */
function pb_cr_lots_used(PDO $pdo, int $cid, array $links): ?string {
    $q0 = $pdo->prepare('SELECT code, net_weight, remaining, small_sack_count, small_sacks_remaining, large_sack_count, large_sacks_remaining FROM purchases WHERE id=? AND customer_id=?');
    foreach ($links as $l) {
        $q0->execute([(int)$l['lot_id'], $cid]);
        $r = $q0->fetch();
        if (!$r) continue; // لات قبلاً حذف شده
        $code = (string)($r['code'] ?? $l['lot_id']);
        if (abs((float)$r['net_weight'] - (float)$r['remaining']) > 1e-6
            || abs((float)$r['small_sack_count'] - (float)$r['small_sacks_remaining']) > 1e-6
            || abs((float)$r['large_sack_count'] - (float)$r['large_sacks_remaining']) > 1e-6) return $code;
        foreach ([['sale_items', 'purchase_id'], ['mix_sources', 'source_purchase_id'], ['separation_inputs', 'lot_id'], ['purchase_return_items', 'lot_id'],
                  ['proc_inputs', 'lot_id'], ['consignment_items', 'lot_id']] as [$t, $col]) {
            try {
                $q = $pdo->prepare("SELECT 1 FROM $t WHERE customer_id=? AND $col=? LIMIT 1");
                $q->execute([$cid, (int)$l['lot_id']]);
                if ($q->fetchColumn()) return $code;
            } catch (Throwable $e) { /* optional table absent */ }
        }
    }
    return null;
}

/**
 * برگرداندن لات‌های یک گزارش: اگر دست‌نخورده‌اند، حرکت‌ها برگردانده، لات‌ها حذف و وزن به لات مبدأ برمی‌گردد؛ وگرنه ردِ کامل با پیام فارسی
 * (rollback + خطا؛ هیچ تغییری نمی‌ماند). Caller owns the transaction.
 */
function pb_cr_lots_revert(PDO $pdo, int $cid, int $runId): void {
    $links = pb_cr_links($pdo, $cid, $runId);
    if (!$links) return;
    $pool = pb_cp_load($pdo, $cid, $runId); // CP1
    if ($pool && (string)$pool['status'] === 'closed') pb_cr_fail($pdo, 'استخر هزینهٔ این گزارش بسته است؛ ابتدا آن را باز کنید'); // CP1
    $used = pb_cr_lots_used($pdo, $cid, $links);
    if ($used !== null) pb_cr_fail($pdo, 'لات «' . $used . '» ساخته‌شده از این گزارش قبلاً فروخته، منتقل یا مصرف شده است؛ ابتدا آن را برگردانید');
    $bySrc = [];
    foreach ($links as $l) $bySrc[(int)$l['source_lot_id']] = ($bySrc[(int)$l['source_lot_id']] ?? 0) + (int)round(((float)$l['kg']) * 1000);
    if ($pool && isset($bySrc[(int)$pool['source_lot_id']])) $bySrc = [(int)$pool['source_lot_id'] => (int)$pool['input_g']]; // CP1/D8: مقدار برگشتی = مصرف واقعی (در full شامل افت)
    $sel = $pdo->prepare('SELECT net_weight, remaining FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
    foreach ($bySrc as $sid => $g) {
        $sel->execute([(int)$sid, $cid]);
        $s = $sel->fetch();
        if (!$s) pb_cr_fail($pdo, 'لات مبدأ یافت نشد؛ برگرداندن وزن ممکن نیست');
        if ((float)$s['remaining'] + $g / 1000 - (float)$s['net_weight'] > 1e-6) pb_cr_fail($pdo, 'لات مبدأ در این فاصله تغییر کرده و برگرداندن وزن ممکن نیست');
    }
    foreach ($links as $l) pb_stock_drop_lot_transfers($pdo, $cid, (int)$l['lot_id']);
    pb_stock_reverse_doc($pdo, $cid, 'crack', $runId);
    $dl = $pdo->prepare('DELETE FROM purchases WHERE id=? AND customer_id=? AND is_mix=2');
    foreach ($links as $l) $dl->execute([(int)$l['lot_id'], $cid]);
    $up = $pdo->prepare('UPDATE purchases SET remaining = remaining + ? WHERE id=? AND customer_id=?');
    foreach ($bySrc as $sid => $g) $up->execute([$g / 1000, (int)$sid, $cid]);
    $pdo->prepare('DELETE FROM crack_run_lots WHERE customer_id=? AND run_id=?')->execute([$cid, $runId]);
    pb_cp_delete($pdo, $cid, $runId); // CP1
}

/**
 * پیش از تراکنش: اعتبارسنجی درخواست `lots` و تعیین کار لازم.
 * @return array{action:string, spec?:array, plan?:array}  action: none | remove | apply
 * - بدون کلید lots و بدون لات قبلی ⇒ none (رفتار قبلی دست‌نخورده).
 * - بدون کلید lots ولی لات دارد ⇒ همان انتخاب با وزن‌های به‌روز (اگر عوض شد باید مجاز باشد: فقط workshop.manage).
 * - lots.enabled=false ⇒ حذف لات‌ها (فقط اگر دست‌نخورده).
 * - کلیدِ یکسان با لات‌های فعلی ⇒ none (ارسال دوباره، لات تکراری نمی‌سازد).
 */
function pb_cr_lots_prepare(PDO $pdo, int $cid, array $customer, int $runId, array $cols, $opt): array {
    $exist = pb_cr_links($pdo, $cid, $runId);
    $given = is_array($opt);
    if (!$given && !$exist) return ['action' => 'none'];
    $can = pb_cr_is_manager($customer);
    if ($given && empty($opt['enabled'])) { // خاموش صریح
        if (!$exist) return ['action' => 'none'];
        if (!$can) pb_proc_fail('حذف لات‌های خط شکست فقط با مجوز مدیریت کارگاه ممکن است', 403);
        return ['action' => 'remove'];
    }
    if ($given) {
        if (!$can) pb_proc_fail('ساخت لات از خروجی‌های خط شکست فقط با مجوز مدیریت کارگاه ممکن است', 403);
        if (!PB_CR_LOTS_ENABLED) pb_proc_fail('ساخت لات از خروجی‌های خط شکست در این نسخه غیرفعال است');
        $fields = is_array($opt['fields'] ?? null) ? $opt['fields'] : [];
        $sid = (int)($opt['source_lot_id'] ?? 0);
        if ($sid <= 0) pb_proc_fail('«لات مبدأ» را انتخاب کنید');
        $catReq = (int)($opt['category_id'] ?? 0);
        $whReq = (!empty($opt['warehouse_uid']) && is_string($opt['warehouse_uid'])) ? $opt['warehouse_uid'] : null;
        $srcWhReq = (!empty($opt['source_warehouse_uid']) && is_string($opt['source_warehouse_uid'])) ? $opt['source_warehouse_uid'] : null;
        $curPool = pb_cp_load($pdo, $cid, $runId); // CP1: کلید consume نیامده ⇒ حالت فعلی استخر (وگرنه lots_only)
        $mode = pb_cp_mode_of($opt['consume'] ?? null, $curPool ? (string)$curPool['consume_mode'] : 'lots_only');
        if ($mode === null) pb_proc_fail('حالت مصرف نامعتبر است (full | lots_only)');
    } else { // انتخاب قبلی را با وزن‌های جدید بازسازی کن
        $f0 = $exist[0];
        $fields = array_map(fn($l) => (string)$l['field_key'], $exist);
        $sid = (int)$f0['source_lot_id'];
        $catReq = 0; $whReq = null; $srcWhReq = ($f0['source_warehouse_uid'] ?? null) ?: null;
        $curPool = pb_cp_load($pdo, $cid, $runId); // CP1
        $mode = $curPool ? (string)$curPool['consume_mode'] : 'lots_only';
    }
    $plan = pb_cr_lots_plan($cols, $fields);
    if (isset($plan['error'])) {
        pb_proc_fail($given ? $plan['error'] : ($plan['error'] . '؛ این گزارش لات دارد، ابتدا لات را حذف کنید'));
    }
    $sq = $pdo->prepare('SELECT category_id FROM purchases WHERE id=? AND customer_id=?');
    $sq->execute([$sid, $cid]);
    $srcCat = $sq->fetchColumn();
    if ($srcCat === false) pb_proc_fail('لات مبدأ یافت نشد', 404);
    $rq = $pdo->prepare('SELECT remaining FROM purchases WHERE id=? AND customer_id=?'); // CP1: بررسی زودهنگام؛ apply بعد از revert دوباره دقیق چک می‌کند
    $rq->execute([$sid, $cid]);
    $remG = (int)round(((float)$rq->fetchColumn()) * 1000);
    if ($curPool && (int)$curPool['source_lot_id'] === $sid) $remG += (int)$curPool['input_g']; // مصرف فعلیِ همین گزارش پس از revert برمی‌گردد
    $cons = pb_cp_consume_plan($mode, (float)($cols['input_kg'] ?? 0), (int)$plan['total_g'], $remG);
    if (isset($cons['error'])) pb_proc_fail($cons['error']);
    $cat = $catReq > 0 ? $catReq : (int)$srcCat;
    if (!$given) { // دستهٔ فعلی لات‌ها را نگه دار
        $cq = $pdo->prepare('SELECT category_id FROM purchases WHERE id=? AND customer_id=?');
        $cq->execute([(int)$exist[0]['lot_id'], $cid]);
        $cc = $cq->fetchColumn();
        if ($cc !== false) $cat = (int)$cc;
    }
    if ($cat <= 0 || !ownsCategory($pdo, $cid, $cat)) pb_proc_fail('دستهٔ کالا یافت نشد', 404);
    if ($whReq !== null) $whReq = pb_wh_require($pdo, $cid, $whReq);
    if ($given && $srcWhReq !== null) $srcWhReq = pb_wh_require($pdo, $cid, $srcWhReq); // انبار قبلیِ بازسازی‌شده را pick خودش اعتبارسنجی می‌کند
    $spec = ['source_lot_id' => $sid, 'category_id' => $cat, 'warehouse_uid' => $whReq, 'source_warehouse_uid' => $srcWhReq, 'consume' => $cons['mode'], 'consumed_g' => $cons['consumed_g']];
    if ($exist) { // یکسان با لات‌های فعلی؟ ⇒ بدون تغییر
        $same = count($exist) === count($plan['items']) && (int)$exist[0]['source_lot_id'] === $sid;
        if ($same) {
            $cur = [];
            foreach ($exist as $l) $cur[(string)$l['field_key']] = (int)round(((float)$l['kg']) * 1000);
            foreach ($plan['items'] as $it) if (($cur[$it['field']] ?? -1) !== $it['g']) { $same = false; break; }
        }
        if ($same) {
            $lq = $pdo->prepare('SELECT category_id, warehouse_uid FROM purchases WHERE id=? AND customer_id=?');
            $lq->execute([(int)$exist[0]['lot_id'], $cid]);
            $lr = $lq->fetch();
            if (!$lr || (int)$lr['category_id'] !== $cat) $same = false;
            if ($same && $whReq !== null && (string)($lr['warehouse_uid'] ?? '') !== $whReq) $same = false;
            if ($same && $srcWhReq !== null && (string)($exist[0]['source_warehouse_uid'] ?? '') !== $srcWhReq) $same = false;
        }
        if ($same && $curPool && ((string)$curPool['consume_mode'] !== $cons['mode'] || (int)$curPool['input_g'] !== $cons['consumed_g'])) $same = false; // CP1
        if ($same) return ['action' => 'none'];
        if (!$can) pb_proc_fail('این گزارش لات دارد؛ ویرایش مقدار خروجی‌های لات‌شده فقط با مجوز مدیریت کارگاه ممکن است', 403);
    }
    return ['action' => 'apply', 'spec' => $spec, 'plan' => $plan];
}

/** داخل تراکنش: لات‌های قبلی (اگر هست) برمی‌گردند و لات‌های جدید ساخته می‌شوند. */
function pb_cr_lots_apply(PDO $pdo, int $cid, int $runId, array $cols, array $lp): void {
    if ($lp['action'] === 'none') return;
    $oldPool = pb_cp_load($pdo, $cid, $runId); // CP1: هزینهٔ اضافهٔ دستی مدیر هنگام بازسازی حفظ می‌شود
    pb_cr_lots_revert($pdo, $cid, $runId);
    if ($lp['action'] === 'remove') return;
    $spec = $lp['spec']; $plan = $lp['plan'];
    $sid = (int)$spec['source_lot_id'];
    $lock = $pdo->prepare('SELECT code, category_id, is_mix, remaining, type_name FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
    $lock->execute([$sid, $cid]);
    $src = $lock->fetch();
    if (!$src) pb_cr_fail($pdo, 'لات مبدأ یافت نشد', 404);
    if ((int)$src['is_mix'] === 3) pb_cr_fail($pdo, 'کالای امانی نمی‌تواند لات مبدأ باشد');
    $cons = pb_cp_consume_plan((string)$spec['consume'], (float)($cols['input_kg'] ?? 0), (int)$plan['total_g'], (int)round(((float)$src['remaining']) * 1000)); // CP1/D3
    if (isset($cons['error'])) pb_cr_fail($pdo, $cons['error']);
    $totalKg = $cons['consumed_g'] / 1000; // lots_only: جمع لات‌ها ؛ full: کل ورودی L
    $srcWh = pb_stock_pick_wh_or_bad($pdo, $cid, $sid, $totalKg, $spec['source_warehouse_uid'], ['crack', $runId]);
    $pdo->prepare('UPDATE purchases SET remaining = remaining - ? WHERE id=? AND customer_id=?')->execute([$totalKg, $sid, $cid]); // گونی کم نمی‌شود (۰ در لات‌های جدید)
    pb_cp_save($pdo, $cid, $runId, $sid, $cons['mode'], $cons['consumed_g'], pb_cp_source_cost($pdo, $cid, $sid, $cons['consumed_g']), $oldPool ? (int)$oldPool['extra_cost_rial'] : 0); // CP1: پیش از pb_stock_post_doc (crack_out از input_g می‌خواند)
    $parent = pb_cr_lot_parent($cols, $runId);
    $cn = trim((string)($cols['customer_name'] ?? ''));
    $note = mb_substr('خروجی خط شکست' . ($cn !== '' ? ' — ' . $cn : ''), 0, 200);
    $insL = $pdo->prepare('INSERT INTO crack_run_lots (customer_id, run_id, field_key, lot_id, source_lot_id, source_warehouse_uid, kg) VALUES (?,?,?,?,?,?,?)');
    $taken = [];
    foreach ($plan['items'] as $it) {
        $lotId = pb_proc_insert_lot($pdo, $cid, 2, (int)$spec['category_id'], $it['label'], (string)$cols['run_date'], 0.0, (float)$it['kg'], 0.0, 0.0, $note, $spec['warehouse_uid'], $cn);
        $code = pb_sep_next_code($pdo, $cid, $parent, $runId, $taken, 'W'); // W-<پدر>-n؛ یکتا در purchases.code
        $pdo->prepare('UPDATE purchases SET code=? WHERE id=? AND customer_id=?')->execute([$code, $lotId, $cid]);
        $insL->execute([$cid, $runId, $it['field'], $lotId, $sid, $srcWh, $it['kg']]);
    }
    pb_stock_post_doc($pdo, $cid, 'crack', $runId);
}

/**
 * S1 — پارس ورودی عددی خط شکست. آینهٔ Dart `crParseNum`؛ تست مشترک: test/crack_parse_cases.json.
 * خالی/`-`/`–`/`—`/`_` ⇒ null · `عدد(عدد)` ⇒ فقط عدد اول (عدد داخل پرانتز دور ریخته می‌شود) · ارقام فارسی/عربی، ٫ و جداکنندهٔ هزارگان پشتیبانی می‌شود.
 * رشتهٔ غیرعددی ⇒ خطای «مقدار عددی نامعتبر» (قبلاً بی‌صدا ۰ می‌شد).
 */
function pb_cr_num($v, string $k): ?float {
    if ($v === null) return null;
    if (is_int($v) || is_float($v)) {
        if (!is_finite((float)$v)) pb_proc_fail('مقدار عددی نامعتبر: ' . $k);
        return (float)$v;
    }
    if (!is_string($v)) pb_proc_fail('مقدار عددی نامعتبر: ' . $k);
    $s = strtr(trim($v), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٫' => '.', '٬' => '', ',' => '', '،' => '', '（' => '(', '）' => ')', "\u{2212}" => '-',
    ]);
    $s = trim($s);
    if ($s === '' || preg_match('/^[\-\x{2013}\x{2014}\x{2012}_]+$/u', $s)) return null;
    if (preg_match('/^(-?\d+(?:\.\d+)?)\s*\([^()]*\)$/u', $s, $m)) $s = $m[1];
    if (!is_numeric($s)) pb_proc_fail('مقدار عددی نامعتبر: ' . $k);
    $f = (float)$s;
    if (!is_finite($f)) pb_proc_fail('مقدار عددی نامعتبر: ' . $k);
    return $f;
}

/** ورودی را اعتبارسنجی و به ستون‌های جدول تبدیل می‌کند. @return array{cols:array,stops:array} */
function pb_cr_normalize(array $p): array {
    $c = [];
    $c['run_date'] = ($p['run_date'] ?? $p['date'] ?? '') !== '' ? vdate($p['run_date'] ?? $p['date']) : today();
    $c['end_date'] = ($p['end_date'] ?? '') !== '' ? vdate($p['end_date']) : null;
    foreach (PB_CR_STR as $k => $len) $c[$k] = str_or_null($p[$k] ?? null, $len);
    foreach (PB_CR_NUM as $k => $max) {
        $v = pb_cr_num($p[$k] ?? null, $k);
        if ($v !== null && ($v < 0 || $v > $max)) pb_proc_fail('مقدار نامعتبر: ' . $k);
        $c[$k] = $v;
    }
    if ($c['input_kg'] === null) $c['input_kg'] = 0.0;
    foreach (['start_time', 'end_time'] as $k) {
        $t = trim((string)($p[$k] ?? ''));
        if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) pb_proc_fail('ساعت نامعتبر است (HH:MM): ' . $k);
        $c[$k] = $t === '' ? null : $t . ':00';
    }
    $stops = [];
    $raw = $p['stops'] ?? [];
    if (!is_array($raw) || count($raw) > 200) pb_proc_fail('فهرست توقف‌ها نامعتبر است');
    foreach ($raw as $s) {
        if (!is_array($s)) pb_proc_fail('توقف نامعتبر است');
        $mn = num($s['minutes'] ?? 0);
        if ($mn < 0 || $mn > 1e5) pb_proc_fail('دقیقهٔ توقف نامعتبر است');
        $stops[] = ['reason' => str_or_null($s['reason'] ?? null, 150), 'minutes' => round($mn, 2)];
    }
    return ['cols' => $c, 'stops' => $stops];
}

function pb_cr_stops_of(PDO $pdo, int $cid, array $runIds): array {
    $by = [];
    if (!$runIds) return $by;
    $in = implode(',', array_map('intval', $runIds));
    $st = $pdo->query("SELECT run_id, reason, minutes FROM crack_stops WHERE customer_id=" . (int)$cid . " AND run_id IN ($in) ORDER BY id");
    foreach ($st->fetchAll() as $r) $by[(int)$r['run_id']][] = ['reason' => $r['reason'], 'minutes' => (float)$r['minutes']];
    return $by;
}

/** یک ردیف دیتابیس + توقف‌ها ⇒ خروجی API (فیلدهای ذخیره‌شده + بخش calc). بدون قیمت. */
function pb_cr_row(array $r, array $stops, array $lots = [], ?array $poolInfo = null): array {
    $o = ['id' => (int)$r['id'], 'uid' => $r['uid'], 'run_date' => $r['run_date'], 'end_date' => $r['end_date'], 'created_by' => (int)$r['created_by'], 'created_at' => $r['created_at']];
    foreach (array_keys(PB_CR_STR) as $k) $o[$k] = $r[$k];
    foreach (array_keys(PB_CR_NUM) as $k) $o[$k] = $r[$k] === null ? null : (float)$r[$k];
    foreach (['start_time', 'end_time'] as $k) $o[$k] = $r[$k] === null ? null : substr((string)$r[$k], 0, 5);
    $o['stops'] = $stops;
    $o['lots'] = $lots; // S5: [{lot_id, code, field_key, kg, source_lot_id}] — بدون قیمت
    if ($poolInfo !== null) $o['pool_info'] = $poolInfo; // CP1: فقط مدیر؛ بدون مبلغ
    $o['calc'] = pb_cr_calc($o);
    return $o;
}

function pb_cr_load(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM crack_runs WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    return $st->fetch() ?: null;
}

function pb_cr_check_access(array $customer, array $row): void {
    if (pb_cr_is_manager($customer)) return;
    $sid = (int)($customer['_staff_id'] ?? 0);
    if ($sid > 0 && (int)$row['created_by'] !== $sid) pb_proc_fail('این گزارش مربوط به کاربر دیگری است', 403);
}

function pb_cr_write_stops(PDO $pdo, int $cid, int $runId, array $stops): void {
    $pdo->prepare('DELETE FROM crack_stops WHERE run_id=? AND customer_id=?')->execute([$runId, $cid]);
    $ins = $pdo->prepare('INSERT INTO crack_stops (customer_id, run_id, reason, minutes) VALUES (?,?,?,?)');
    foreach ($stops as $s) $ins->execute([$cid, $runId, $s['reason'], $s['minutes']]);
}

/** workshop.crack_create {uid?, run_date, ... (ستون‌های PB_CR_STR/PB_CR_NUM), start_time, end_time, stops:[{reason, minutes}]} — idempotent با uid. */
function pb_cr_create(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_cr_ensure($pdo);
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM crack_runs WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $eid = $ex->fetchColumn();
        if ($eid) return pb_cr_get($pdo, $cid, $customer, ['id' => (int)$eid]);
    }
    $n = pb_cr_normalize($p);
    $lp = pb_cr_lots_prepare($pdo, $cid, $customer, 0, $n['cols'], $p['lots'] ?? null); // S5: پیش از تراکنش؛ بدون کلید lots ⇒ none
    $cols = $n['cols'] + ['uid' => $uid, 'created_by' => (int)($customer['_staff_id'] ?? 0), 'created_by_name' => str_or_null($customer['_staff_name'] ?? null, 100)];
    $pdo->beginTransaction();
    try {
        $names = array_keys($cols);
        $sql = 'INSERT INTO crack_runs (customer_id, `' . implode('`,`', $names) . '`) VALUES (?' . str_repeat(',?', count($names)) . ')';
        $pdo->prepare($sql)->execute(array_merge([$cid], array_values($cols)));
        $id = (int)$pdo->lastInsertId();
        pb_cr_write_stops($pdo, $cid, $id, $n['stops']);
        pb_cr_lots_apply($pdo, $cid, $id, $n['cols'], $lp); // S5
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cr_get($pdo, $cid, $customer, ['id' => $id]);
}

/** workshop.crack_update {id, ...همان فیلدها} — جایگزینی کامل ستون‌ها و فهرست توقف‌ها؛ فقط ردیف خودِ اپراتور (یا workshop.manage). */
function pb_cr_update(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_cr_ensure($pdo);
    $id = (int)($p['id'] ?? 0);
    $row = pb_cr_load($pdo, $cid, $id);
    if (!$row) pb_proc_fail('گزارش خط شکست یافت نشد', 404);
    pb_cr_check_access($customer, $row);
    $n = pb_cr_normalize($p);
    $lp = pb_cr_lots_prepare($pdo, $cid, $customer, $id, $n['cols'], $p['lots'] ?? null); // S5
    $pdo->beginTransaction();
    try {
        $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($n['cols'])));
        $pdo->prepare("UPDATE crack_runs SET $set WHERE id=? AND customer_id=?")->execute(array_merge(array_values($n['cols']), [$id, $cid]));
        pb_cr_write_stops($pdo, $cid, $id, $n['stops']);
        pb_cr_lots_apply($pdo, $cid, $id, $n['cols'], $lp); // S5: لات‌های دست‌نخورده به‌روز می‌شوند؛ وگرنه ردِ کامل (rollback)
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_cr_get($pdo, $cid, $customer, ['id' => $id]);
}

/** workshop.crack_get {id} */
function pb_cr_get(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_cr_ensure($pdo);
    $row = pb_cr_load($pdo, $cid, (int)($p['id'] ?? 0));
    if (!$row) pb_proc_fail('گزارش خط شکست یافت نشد', 404);
    pb_cr_check_access($customer, $row);
    $stops = pb_cr_stops_of($pdo, $cid, [(int)$row['id']]);
    $lots = pb_cr_lots_of($pdo, $cid, [(int)$row['id']]);
    $pi = pb_cr_is_manager($customer) ? pb_cp_info_of($pdo, $cid, [(int)$row['id']]) : []; // CP1
    return pb_cr_row($row, $stops[(int)$row['id']] ?? [], $lots[(int)$row['id']] ?? [], $pi[(int)$row['id']] ?? null);
}

/** workshop.crack_list {from?, to?, shift?, line_code?} — کارمند بدون workshop.manage فقط ردیف‌های خودش. */
function pb_cr_list(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_cr_ensure($pdo);
    $sql = 'SELECT * FROM crack_runs WHERE customer_id=?';
    $args = [$cid];
    if (($p['from'] ?? '') !== '') { $sql .= ' AND run_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND run_date <= ?'; $args[] = vdate($p['to']); }
    foreach (['shift', 'line_code'] as $k) if (($p[$k] ?? '') !== '') { $sql .= " AND $k = ?"; $args[] = (string)$p[$k]; }
    $sid = (int)($customer['_staff_id'] ?? 0);
    if ($sid > 0 && !pb_cr_is_manager($customer)) { $sql .= ' AND created_by = ?'; $args[] = $sid; }
    $st = $pdo->prepare($sql . ' ORDER BY run_date DESC, id DESC LIMIT 500');
    $st->execute($args);
    $rows = $st->fetchAll();
    $stops = pb_cr_stops_of($pdo, $cid, array_map(fn($r) => (int)$r['id'], $rows));
    $lots = pb_cr_lots_of($pdo, $cid, array_map(fn($r) => (int)$r['id'], $rows));
    $pi = pb_cr_is_manager($customer) ? pb_cp_info_of($pdo, $cid, array_map(fn($r) => (int)$r['id'], $rows)) : []; // CP1
    return ['items' => array_map(fn($r) => pb_cr_row($r, $stops[(int)$r['id']] ?? [], $lots[(int)$r['id']] ?? [], $pi[(int)$r['id']] ?? null), $rows)];
}

/** workshop.crack_delete {id} — فقط workshop.manage (نگاشت اکشن). */
function pb_cr_delete(PDO $pdo, int $cid, array $p): array {
    pb_cr_ensure($pdo);
    $id = (int)($p['id'] ?? 0);
    if (!pb_cr_load($pdo, $cid, $id)) pb_proc_fail('گزارش خط شکست یافت نشد', 404);
    $pdo->beginTransaction();
    try {
        pb_cr_lots_revert($pdo, $cid, $id); // S5: لات‌های دست‌نخورده حذف و وزن به لات مبدأ برمی‌گردد؛ وگرنه ردِ کامل
        $pdo->prepare('DELETE FROM crack_stops WHERE run_id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM crack_runs WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}
