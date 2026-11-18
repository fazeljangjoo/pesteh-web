<?php
declare(strict_types=1);
require_once __DIR__ . '/workshop_calc.php';
require_once __DIR__ . '/workshop_crack.php'; // مرحلهٔ ۳-الف: گزارش خط شکست
require_once __DIR__ . '/workshop_outside.php'; // مرحلهٔ ۴: کارگاه بیرونی با قبض (paid) + فایل خام
require_once __DIR__ . '/workshop_feed.php'; // مرحلهٔ ۵-الف: موجودی پستهٔ خرجی (feed.*)
require_once __DIR__ . '/workshop_cartex.php'; // مرحلهٔ ۶-۱: کارتکس مالک (reports.cartex)
require_once __DIR__ . '/workshop_report.php'; // مرحلهٔ ۶-۲: خلاصهٔ مدیریتی + مقایسه (reports.workshop_*)
/**
 * کارگاه داخلی — مرحلهٔ ۱ (جهت جدید `internal` روی proc_orders / proc_inputs / proc_outputs).
 *
 * یک عملیات داخلی (پوست‌گیری، خاک‌گیری، جداسازی پوست و مغز، ریزبرداری/بدشکل، شکست) یک‌جا ثبت می‌شود و فوراً status='done' است:
 *   ورودی‌ها از موجودی لات‌ها کم می‌شوند (proc_out)، هر خروجی یک لات تازه می‌شود (proc_in، is_mix=2) با کد `K-<کد لات اصلی>-n`.
 *   «افت» به موجودی برنمی‌گردد. تراز (pb_ws_balance، آستانهٔ ۰٫۰۱ کیلو) فقط هشدار است و ثبت را رد نمی‌کند.
 * روی هر خروجی: grade (sound|deformed_fine|hull|kernel|soil|discolored)، owner و source_lot_id (لات اصلی = بزرگ‌ترین لات ورودی).
 * ردیابی: proc_outputs.source_lot_id + proc_inputs (مثل سایر فراوری‌ها؛ lot_lineage عمداً پر نمی‌شود چون جدولش به separation_id وابسته است
 *   و lot_trace.php فراوری را ردیابی نمی‌کند).
 * بها (مرحلهٔ ۲): اگر فراخواننده workshop.cost + price.view دارد (`_can_cost` که app.php مقدارش را خودش می‌نویسد) فرمول اکسل W/Z/AF/AG/AH محاسبه و روی لات‌ها ثبت می‌شود
 *   (pb_ws_cost / pb_ws_alloc)؛ وگرنه بها خالی می‌ماند و بعداً با workshop.cost_save ثبت می‌شود. خروجی workshop.* برای بقیه هیچ فیلد قیمتی ندارد (pb_ws_public / pb_ws_gate).
 */

/** مرحلهٔ ۲: ستون‌های بها (idempotent؛ v65). */
function pb_ws_ensure_cost_columns(PDO $pdo): void {
    $cols = [
        ['price_per_kg', 'DECIMAL(18,4) NULL'],
        ['wage_per_kg', 'DECIMAL(18,4) NULL'],
        ['other_cost', 'DECIMAL(18,2) NULL'],
        ['price_deformed', 'DECIMAL(18,4) NULL'],
        ['price_discolored', 'DECIMAL(18,4) NULL'],
        ['price_hull', 'DECIMAL(18,4) NULL'],
        ['price_soil', 'DECIMAL(18,4) NULL'],
        ['price_kernel', 'DECIMAL(18,4) NULL'],
        ['value_in', 'DECIMAL(18,2) NULL'],
        ['cost_total', 'DECIMAL(18,2) NULL'],
        ['recovery_value', 'DECIMAL(18,2) NULL'],
        ['cost_sound', 'DECIMAL(18,2) NULL'],
        ['cost_per_kg_sound', 'DECIMAL(18,4) NULL'],
    ];
    foreach ($cols as [$c, $def]) {
        $st = $pdo->prepare('SHOW COLUMNS FROM `proc_orders` LIKE ?');
        $st->execute([$c]);
        if (!$st->fetch()) $pdo->exec("ALTER TABLE `proc_orders` ADD COLUMN `$c` $def");
    }
    pb_ws_ensure_out_price($pdo);
}

/** مرحلهٔ ۴ (v72): قیمت هر کیلوی ردیف خروجی (idempotent). NULL = ثبت‌نشده / ردیف سالم. */
function pb_ws_ensure_out_price(PDO $pdo): void {
    $t = $pdo->query("SHOW TABLES LIKE 'proc_outputs'");
    if (!($t && $t->fetch())) return; // جدول هنوز نیست (pb_proc_ensure می‌سازد؛ v72 دوباره صدا می‌زند)
    $st = $pdo->prepare('SHOW COLUMNS FROM `proc_outputs` LIKE ?');
    $st->execute(['out_price']);
    if (!$st->fetch()) $pdo->exec('ALTER TABLE `proc_outputs` ADD COLUMN `out_price` DECIMAL(18,4) NULL');
}

/** فقط دارندهٔ price.view + workshop.cost بها را می‌بیند/ثبت می‌کند (مالک همیشه). */
function pb_ws_can_cost(array $customer): bool {
    return pb_staff_can($customer, 'price.view') && pb_staff_can($customer, 'workshop.cost');
}

/** فیلدهای بهای یک سفارش internal (null = هنوز محاسبه نشده). فقط برای دارندهٔ مجوز؛ بقیه pb_ws_public/ماسک. */
function pb_ws_cost_fields(array $o): array {
    $f = fn($k) => ($o[$k] ?? null) === null ? null : (float)$o[$k];
    return ['price_per_kg' => $f('price_per_kg'), 'wage_per_kg' => $f('wage_per_kg'), 'other_cost' => $f('other_cost'),
        'price_deformed' => $f('price_deformed'), 'price_discolored' => $f('price_discolored'), 'price_hull' => $f('price_hull'),
        'price_soil' => $f('price_soil'), 'price_kernel' => $f('price_kernel'),
        'value_in' => $f('value_in'), 'cost_total' => $f('cost_total'), 'recovery_value' => $f('recovery_value'),
        'cost_sound' => $f('cost_sound'), 'cost_per_kg_sound' => $f('cost_per_kg_sound'),
        'cost_warning' => ($o['cost_sound'] ?? null) !== null && (float)$o['cost_sound'] < 0 ? 'negative_cost' : null];
}

/** خروجی processing.* / workshop.* را برای کسی که workshop.cost ندارد از هر فیلد بهای کارگاه خالی می‌کند (فقط ردیف‌های internal). */
function pb_ws_gate($data, array $customer) {
    if (!is_array($data) || pb_ws_can_cost($customer)) return $data;
    if (isset($data['items']) && is_array($data['items'])) {
        foreach ($data['items'] as $i => $r) if (is_array($r) && ($r['direction'] ?? 'internal') === 'internal') $data['items'][$i] = pb_ws_public($r);
        return $data;
    }
    return (($data['direction'] ?? 'internal') === 'internal') ? pb_ws_public($data) : $data;
}

/**
 * قیمت‌های ورودی (V,X,Y,AA..AE). V پیش‌فرض = میانگین موزون بهای لات‌های ورودی ($inCost/$inKg)؛ قابل‌ویرایش.
 * @return array{buy_per_kg:float,wage_per_kg:float,other_cost:float,price_deformed:float,price_discolored:float,price_hull:float,price_soil:float,price_kernel:float}
 */
function pb_ws_prices(array $p, float $inKg, float $inCost): array {
    $v = [];
    foreach (['buy_per_kg' => 'price_per_kg', 'wage_per_kg' => 'wage_per_kg', 'other_cost' => 'other_cost', 'price_deformed' => 'price_deformed',
              'price_discolored' => 'price_discolored', 'price_hull' => 'price_hull', 'price_soil' => 'price_soil', 'price_kernel' => 'price_kernel'] as $calc => $in) {
        $x = nullableNum($p[$in] ?? ($p[$calc] ?? null));
        if ($x !== null && ($x < 0 || $x > 1e12)) pb_proc_fail('قیمت یا هزینهٔ واردشده نامعتبر است');
        $v[$calc] = $x;
    }
    if ($v['buy_per_kg'] === null) $v['buy_per_kg'] = $inKg > 0 ? round($inCost / $inKg, 4) : 0.0;
    foreach ($v as $k => $x) if ($x === null) $v[$k] = 0.0;
    return $v;
}

/**
 * محاسبهٔ بها + تخصیص به لات‌های خروجی. $nOuts: [['grade'=>,'kg'=>,'price'=>?],...] به ترتیب ثبت.
 * مرحلهٔ ۴: 'price' = قیمت هر کیلوی همان ردیف (null ⇒ قیمت دستهٔ استاندارد از payload قدیمی price_*؛ برای custom صفر). خروجی `row_prices` هم‌اندیس $nOuts
 * (null برای ردیف سالم) برای ذخیره در proc_outputs.out_price؛ ستون‌های قدیمی price_* برای ۵ دستهٔ استاندارد = میانگین موزون قیمت ردیف‌های همان دسته.
 */
function pb_ws_cost_plan(array $p, float $inKg, float $inCost, array $nOuts, array $byGrade): array {
    $pr = pb_ws_prices($p, $inKg, $inCost);
    $rows = []; $rowPrices = [];
    foreach ($nOuts as $i => $o) {
        $g = (string)$o['grade'];
        if ($g === 'sound') { $rowPrices[$i] = null; $rows[] = $o; continue; }
        $price = ($o['price'] ?? null) !== null ? (float)$o['price'] : (float)(isset(PB_WS_PRICE_KEYS[$g]) ? $pr[PB_WS_PRICE_KEYS[$g]] : 0.0);
        $rowPrices[$i] = $price;
        $rows[] = ['grade' => $g, 'kg' => $o['kg'], 'price' => $price];
    }
    foreach (PB_WS_PRICE_KEYS as $g => $key) { // همگام‌سازی ستون‌های قدیمی
        $sumKg = 0.0; $sumVal = 0.0;
        foreach ($rows as $r) if ($r['grade'] === $g) { $sumKg += (float)$r['kg']; $sumVal += (float)$r['kg'] * (float)$r['price']; }
        if ($sumKg > 0) $pr[$key] = round($sumVal / $sumKg, 4);
    }
    $prCalc = $pr;
    $prCalc['outs'] = $rows;
    $c = pb_ws_cost($inKg, $byGrade, $prCalc);
    $lots = pb_ws_alloc($rows, $pr, $c);
    $af = 0.0; $ag = 0.0;
    foreach ($nOuts as $i => $o) { if ($o['grade'] === 'sound') $ag += $lots[$i]; else $af += $lots[$i]; }
    if ($ag !== 0.0 || $af !== 0.0) { // AF/AG واقعی = Σ لات‌ها (AG = W+Z−AF دقیقاً حفظ می‌شود)
        $c['recovery_value'] = pb_ws_r2($af);
        if ($c['sound_kg'] > 0) $c['cost_sound'] = pb_ws_r2($ag);
        $c['warning'] = $c['cost_sound'] < 0 ? 'negative_cost' : null;
    }
    return ['pr' => $pr, 'cost' => $c, 'lots' => $lots, 'row_prices' => $rowPrices];
}

/** ستون‌های بهای proc_orders از نتیجهٔ pb_ws_cost_plan. */
function pb_ws_cost_cols(array $plan): array {
    $pr = $plan['pr']; $c = $plan['cost'];
    return ['price_per_kg' => $pr['buy_per_kg'], 'wage_per_kg' => $pr['wage_per_kg'], 'other_cost' => $pr['other_cost'],
        'price_deformed' => $pr['price_deformed'], 'price_discolored' => $pr['price_discolored'], 'price_hull' => $pr['price_hull'],
        'price_soil' => $pr['price_soil'], 'price_kernel' => $pr['price_kernel'],
        'value_in' => $c['value_in'], 'cost_total' => $c['cost_total'], 'recovery_value' => $c['recovery_value'],
        'cost_sound' => $c['cost_sound'], 'cost_per_kg_sound' => $c['cost_per_kg_sound']];
}

/** ستون‌های جدید (idempotent). v64 صدا می‌زند. */
function pb_ws_ensure_columns(PDO $pdo): void {
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?");
        $st->execute([$c]);
        return (bool)$st->fetch();
    };
    $add = [
        'proc_orders' => [
            ['op_type', 'VARCHAR(20) NULL'], ['owner', 'VARCHAR(150) NULL'], ['pistachio_type', 'VARCHAR(100) NULL'], ['defect_note', 'VARCHAR(255) NULL'],
            ['initial_kg', 'DECIMAL(14,3) NULL'], ['bale_count', 'DECIMAL(10,2) NULL'], ['loss_kg', 'DECIMAL(14,3) NOT NULL DEFAULT 0'],
            ['balance_diff_kg', 'DECIMAL(14,3) NULL'], ['balance_ok', 'TINYINT NULL'], ['undelivered_kg', 'DECIMAL(14,3) NULL'],
        ],
        'proc_outputs' => [['grade', 'VARCHAR(20) NULL'], ['owner', 'VARCHAR(150) NULL'], ['source_lot_id', 'INT UNSIGNED NULL']],
    ];
    foreach ($add as $t => $cols) {
        foreach ($cols as [$c, $def]) {
            if (!$has($t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        }
    }
}

function pb_ws_fail(PDO $pdo, string $msg, int $code = 400): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pb_proc_fail($msg, $code);
}

/** کلیدهای مالی که هرگز به اپراتور کارگاه نمی‌رسند (بازگشتی). */
function pb_ws_public($v) {
    static $drop = ['unit_cost', 'cost', 'input_cost', 'fee_basis', 'fee_value', 'fee_amount', 'charge_amount', 'waste_charge_per_kg', 'standard_yield_pct', 'price',
        'price_per_kg', 'wage_per_kg', 'other_cost', 'price_deformed', 'price_discolored', 'price_hull', 'price_soil', 'price_kernel', 'unit_price',
        'value_in', 'cost_total', 'recovery_value', 'cost_sound', 'cost_per_kg_sound', 'cost_warning', 'out_price']; // مرحلهٔ ۲: هر ستون بها/قیمت؛ مرحلهٔ ۴: out_price ردیف خروجی
    if (!is_array($v)) return $v;
    $o = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && in_array($k, $drop, true)) continue;
        $o[$k] = pb_ws_public($x);
    }
    return $o;
}

/** فیلدهای کارگاهی یک سفارش internal برای pb_proc_get / workshop.list. */
function pb_ws_fields(array $o): array {
    $ok = $o['balance_ok'] === null ? null : ((int)$o['balance_ok'] === 1);
    return [
        'op_type' => $o['op_type'], 'op_label' => PB_WS_OP_LABELS[(string)$o['op_type']] ?? null,
        'owner' => $o['owner'], 'pistachio_type' => $o['pistachio_type'], 'defect_note' => $o['defect_note'],
        'initial_kg' => $o['initial_kg'] === null ? null : (float)$o['initial_kg'],
        'bale_count' => $o['bale_count'] === null ? null : (float)$o['bale_count'],
        'loss_kg' => (float)$o['loss_kg'],
        'balance_diff_kg' => $o['balance_diff_kg'] === null ? null : (float)$o['balance_diff_kg'],
        'balance_ok' => $ok, 'balance_label' => $ok === null ? null : ($ok ? 'تراز صحیح' : 'نیاز به بررسی'),
        'sound_pct' => $o['yield_pct'] === null ? null : (float)$o['yield_pct'],
        'undelivered_kg' => $o['undelivered_kg'] === null ? null : (float)$o['undelivered_kg'],
    ];
}

/** True when any output lot of an internal order was sold / consumed / re-separated / mixed (returns its code). */
function pb_ws_outputs_used(PDO $pdo, int $cid, int $orderId): ?string {
    $o = $pdo->prepare('SELECT o.lot_id, p.code, p.net_weight, p.remaining, p.small_sack_count, p.small_sacks_remaining, p.large_sack_count, p.large_sacks_remaining
        FROM proc_outputs o LEFT JOIN purchases p ON p.id=o.lot_id AND p.customer_id=o.customer_id WHERE o.customer_id=? AND o.order_id=?');
    $o->execute([$cid, $orderId]);
    foreach ($o->fetchAll() as $r) {
        $code = (string)($r['code'] ?? $r['lot_id']);
        if ($r['net_weight'] === null) continue;
        if (abs((float)$r['net_weight'] - (float)$r['remaining']) > 1e-6
            || abs((float)$r['small_sack_count'] - (float)$r['small_sacks_remaining']) > 1e-6
            || abs((float)$r['large_sack_count'] - (float)$r['large_sacks_remaining']) > 1e-6) return $code;
        foreach ([['sale_items', 'purchase_id', ''], ['mix_sources', 'source_purchase_id', ''], ['separation_inputs', 'lot_id', ''],
                  ['purchase_return_items', 'lot_id', ''], ['proc_inputs', 'lot_id', ' AND order_id<>' . $orderId], ['consignment_items', 'lot_id', '']] as [$t, $col, $extra]) {
            try {
                $q = $pdo->prepare("SELECT 1 FROM $t WHERE customer_id=? AND $col=?$extra LIMIT 1");
                $q->execute([$cid, (int)$r['lot_id']]);
                if ($q->fetchColumn()) return $code;
            } catch (Throwable $e) { /* optional table absent */ }
        }
    }
    return null;
}

/**
 * processing.create / workshop.create با direction='internal':
 * {uid?, date?, op_type, owner?, pistachio_type?, defect_note?, initial_kg?, bale_count?, loss_kg?, party?, warehouse_uid?, note?,
 *  items:[{purchase_id, kg, small_sacks?, large_sacks?, warehouse_uid?}],
 *  outputs:[{grade, kg, type_name?(اجباری برای grade=custom), price_per_kg?(فقط با workshop.cost؛ مرحلهٔ ۴), owner?, small_sacks?, large_sacks?, warehouse_uid?}]}
 */
function pb_ws_create(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM proc_orders WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $eid = $ex->fetchColumn();
        if ($eid) return pb_proc_get($pdo, $cid, ['id' => (int)$eid]); // idempotent replay (offline queue)
    }
    $op = (string)($p['op_type'] ?? '');
    if (!in_array($op, PB_WS_OP_TYPES, true)) pb_proc_fail('نوع عملیات کارگاه نامعتبر است');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $owner = str_or_null($p['owner'] ?? null, 150);
    $ptype = str_or_null($p['pistachio_type'] ?? null, 100);
    $defect = str_or_null($p['defect_note'] ?? null, 255);
    $party = str_or_null($p['party'] ?? null, 150) ?? 'کارگاه داخلی';
    $note = str_or_null($p['note'] ?? null, 500);
    $initial = nullableNum($p['initial_kg'] ?? null);
    $bales = nullableNum($p['bale_count'] ?? null);
    if (($initial !== null && ($initial < 0 || $initial > 1e9)) || ($bales !== null && ($bales < 0 || $bales > 1e7))) pb_proc_fail('وزن اولیه یا تعداد عدل نامعتبر است');
    $loss = num($p['loss_kg'] ?? 0);
    if ($loss < 0 || $loss > 1e9) pb_proc_fail('افت وزن نامعتبر است');
    $items = $p['items'] ?? [];
    if (!is_array($items) || count($items) < 1) pb_proc_fail('حداقل یک ورودی ثبت کنید');
    if (count($items) > 100) pb_proc_fail('تعداد اقلام بیش از حد مجاز است');
    $outs = $p['outputs'] ?? [];
    if (!is_array($outs)) pb_proc_fail('خروجی‌ها نامعتبر است');
    if (count($outs) > 100) pb_proc_fail('تعداد خروجی‌ها بیش از حد مجاز است');
    $wh = null;
    if (!empty($p['warehouse_uid']) && is_string($p['warehouse_uid'])) $wh = pb_wh_require($pdo, $cid, $p['warehouse_uid']);

    $norm = []; $inKg = 0.0;
    foreach ($items as $o) {
        if (!is_array($o)) pb_proc_fail('ورودی نامعتبر است');
        $kg = num($o['kg'] ?? $o['weight'] ?? 0);
        if ($kg <= 0 || $kg > 1e9) pb_proc_fail('وزن هر ورودی باید بیشتر از صفر باشد');
        $sm = num($o['small_sacks'] ?? 0); $lg = num($o['large_sacks'] ?? 0);
        if ($sm < 0 || $lg < 0 || $sm > 1e7 || $lg > 1e7) pb_proc_fail('تعداد گونی نامعتبر است');
        $pid = (int)($o['purchase_id'] ?? 0);
        if ($pid <= 0) pb_proc_fail('لات ورودی را انتخاب کنید');
        $norm[] = ['pid' => $pid, 'kg' => $kg, 'sm' => $sm, 'lg' => $lg, 'wh' => (!empty($o['warehouse_uid']) && is_string($o['warehouse_uid'])) ? $o['warehouse_uid'] : null];
        $inKg += $kg;
    }
    $nOuts = []; $byGrade = []; $outKg = 0.0;
    foreach ($outs as $x) {
        if (!is_array($x)) pb_proc_fail('خروجی نامعتبر است');
        $grade = (string)($x['grade'] ?? '');
        if (!in_array($grade, PB_WS_GRADES, true)) pb_proc_fail('دستهٔ خروجی نامعتبر است');
        $kg = num($x['kg'] ?? 0);
        if ($kg <= 0 || $kg > 1e9) pb_proc_fail('وزن هر خروجی باید بیشتر از صفر باشد');
        $sm = num($x['small_sacks'] ?? 0); $lg = num($x['large_sacks'] ?? 0);
        if ($sm < 0 || $lg < 0 || $sm > 1e7 || $lg > 1e7) pb_proc_fail('تعداد گونی نامعتبر است');
        $oname = str_or_null($x['type_name'] ?? null, 100);
        if ($grade === 'custom' && $oname === null) pb_proc_fail('نام خروجی جدید را وارد کنید');
        $oprice = nullableNum($x['price_per_kg'] ?? null);
        if ($oprice !== null && ($oprice < 0 || $oprice > 1e12)) pb_proc_fail('قیمت هر کیلوی خروجی نامعتبر است');
        $nOuts[] = ['grade' => $grade, 'kg' => $kg, 'sm' => $sm, 'lg' => $lg, 'name' => $oname, 'price' => $oprice,
            'owner' => str_or_null($x['owner'] ?? null, 150) ?? $owner,
            'wh' => (!empty($x['warehouse_uid']) && is_string($x['warehouse_uid'])) ? pb_wh_require($pdo, $cid, $x['warehouse_uid']) : null];
        $byGrade[$grade] = ($byGrade[$grade] ?? 0.0) + $kg;
        $outKg += $kg;
    }
    $bal = pb_ws_balance($inKg, $byGrade, $loss, $initial);
    if (!empty($p['_can_cost'])) pb_ws_prices($p, $inKg, 0.0); // اعتبارسنجی قیمت‌ها قبل از تراکنش

    $pdo->beginTransaction();
    try {
        $fc = $pdo->prepare('SELECT id FROM categories WHERE customer_id=? ORDER BY id LIMIT 1');
        $fc->execute([$cid]);
        $firstCat = (int)$fc->fetchColumn();
        $lock = $pdo->prepare('SELECT p.code, p.category_id, p.is_mix, p.remaining, p.type_name, p.small_sacks_remaining, p.large_sacks_remaining, ' . PB_BROKER_COST_SQL . ' AS cost
            FROM purchases p WHERE p.id=? AND p.customer_id=? FOR UPDATE');
        $rem = $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?');
        $pdo->prepare('INSERT INTO proc_orders (customer_id, uid, direction, party, order_date, service, fee_basis, fee_value, fee_amount, standard_yield_pct, waste_charge_per_kg, status, warehouse_uid, input_kg, note,
                op_type, owner, pistachio_type, defect_note, initial_kg, bale_count, loss_kg)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, 'internal', $party, $date, 'other', 'fixed', 0, 0, 100, 0, 'in_progress', $wh, round($inKg, 3), $note,
                $op, $owner, $ptype, $defect, $initial, $bales, round($loss, 3)]);
        $id = (int)$pdo->lastInsertId();
        $insIn = $pdo->prepare('INSERT INTO proc_inputs (customer_id, order_id, lot_id, kg, small_sacks, large_sacks, warehouse_uid, unit_cost, cost) VALUES (?,?,?,?,?,?,?,?,?)');
        $inCost = 0.0; $big = null; $bigKg = -1.0; $bigRow = null;
        foreach ($norm as $n) {
            $lock->execute([$n['pid'], $cid]);
            $row = $lock->fetch();
            if (!$row) pb_ws_fail($pdo, 'یکی از ورودی‌ها یافت نشد', 404);
            if ((int)$row['is_mix'] === 3) pb_ws_fail($pdo, 'کالای امانی قابل فراوری در کارگاه داخلی نیست');
            if ($n['kg'] - (float)$row['remaining'] > 1e-6) pb_ws_fail($pdo, 'موجودی «' . $row['type_name'] . '» کافی نیست');
            checkSacksAvailable($pdo, $row, $n['sm'], $n['lg']);
            $whLot = pb_stock_pick_wh_or_bad($pdo, $cid, $n['pid'], $n['kg'], $n['wh'] ?? $wh);
            $unit = (float)$row['cost']; // فقط برای حرکت موجودی (همان رفتار paid)؛ بهای کارگاه = مرحلهٔ ۲
            $cost = round($n['kg'] * $unit, 2);
            $inCost += $cost;
            $insIn->execute([$cid, $id, $n['pid'], $n['kg'], $n['sm'], $n['lg'], $whLot, round($unit, 4), $cost]);
            $rem->execute([$n['kg'], $n['sm'], $n['lg'], $n['pid'], $cid]);
            if ($n['kg'] > $bigKg) { $bigKg = $n['kg']; $big = $n['pid']; $bigRow = $row; }
        }
        $plan = null;
        if (!empty($p['_can_cost'])) $plan = pb_ws_cost_plan($p, $inKg, $inCost, array_map(fn($x) => ['grade' => $x['grade'], 'kg' => $x['kg'], 'price' => $x['price']], $nOuts), $byGrade);
        if ($ptype === null) $ptype = (string)$bigRow['type_name'];
        $cat = (int)$bigRow['category_id'];
        if ($cat <= 0) $cat = $firstCat;
        if ($cat <= 0 || !ownsCategory($pdo, $cid, $cat)) pb_ws_fail($pdo, 'دستهٔ کالا یافت نشد', 404);

        $insOut = $pdo->prepare('INSERT INTO proc_outputs (customer_id, order_id, lot_id, type_name, kg, small_sacks, large_sacks, cost, warehouse_uid, grade, owner, source_lot_id, out_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $taken = [];
        foreach ($nOuts as $oi => $x) {
            $lotCost = $plan ? $plan['lots'][$oi] : 0.0;
            $name = $x['name'] ?? ($x['grade'] === 'sound' ? $ptype : PB_WS_GRADE_LABELS[$x['grade']]);
            $lotId = pb_proc_insert_lot($pdo, $cid, 2, $cat, $name, $date, ($plan && $x['kg'] > 0) ? max(0.0, round($lotCost / $x['kg'], 2)) : 0.0, $x['kg'], $x['sm'], $x['lg'], null, $x['wh'] ?? $wh, $party);
            $code = pb_sep_next_code($pdo, $cid, ((string)$bigRow['code']) !== '' ? (string)$bigRow['code'] : 'L' . $big, $id, $taken, 'W'); // T1: W-<کد لات اصلی>-n
            $pdo->prepare('UPDATE purchases SET code=? WHERE id=? AND customer_id=?')->execute([$code, $lotId, $cid]);
            $insOut->execute([$cid, $id, $lotId, $name, $x['kg'], $x['sm'], $x['lg'], $lotCost, $x['wh'] ?? $wh, $x['grade'], $x['owner'], $big, $plan ? $plan['row_prices'][$oi] : null]);
        }
        $pdo->prepare('UPDATE proc_orders SET status=?, receive_date=?, input_cost=?, output_kg=?, waste_kg=?, yield_pct=?, pistachio_type=?, balance_diff_kg=?, balance_ok=?, undelivered_kg=? WHERE id=? AND customer_id=?')
            ->execute(['done', $date, round($inCost, 2), round($outKg, 3), round($loss, 3), $bal['sound_pct'], $ptype, $bal['diff_kg'], $bal['ok'] ? 1 : 0, $bal['undelivered_kg'], $id, $cid]);
        if ($plan) {
            $cols = pb_ws_cost_cols($plan);
            $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($cols)));
            $pdo->prepare("UPDATE proc_orders SET $set WHERE id=? AND customer_id=?")->execute(array_merge(array_values($cols), [$id, $cid]));
        }
        pb_stock_post_doc($pdo, $cid, 'proc', $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_proc_get($pdo, $cid, ['id' => $id]);
}

/** processing.delete / workshop.delete برای internal: حرکت‌ها برگردانده، ورودی‌ها به موجودی برمی‌گردند، لات‌های خروجی حذف می‌شوند (فقط اگر مصرف نشده باشند). Caller owns the transaction. */
function pb_ws_revert(PDO $pdo, int $cid, int $id): void {
    $used = pb_ws_outputs_used($pdo, $cid, $id);
    if ($used !== null) pb_ws_fail($pdo, 'خروجی «' . $used . '» قبلاً فروخته یا مصرف شده است؛ ابتدا آن را برگردانید');
    $lots = $pdo->prepare('SELECT lot_id FROM proc_outputs WHERE customer_id=? AND order_id=?');
    $lots->execute([$cid, $id]);
    $lots = $lots->fetchAll(PDO::FETCH_COLUMN);
    foreach ($lots as $lid) pb_stock_drop_lot_transfers($pdo, $cid, (int)$lid);
    pb_stock_reverse_doc($pdo, $cid, 'proc', $id);
    foreach ($lots as $lid) $pdo->prepare('DELETE FROM purchases WHERE id=? AND customer_id=? AND is_mix=2')->execute([(int)$lid, $cid]);
    $ins = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks FROM proc_inputs WHERE order_id=? AND customer_id=?');
    $ins->execute([$id, $cid]);
    foreach ($ins->fetchAll() as $r) restoreStock($pdo, $cid, (int)$r['lot_id'], (float)$r['kg'], $r['small_sacks'], $r['large_sacks']);
    foreach (['proc_outputs', 'proc_inputs'] as $t) $pdo->prepare("DELETE FROM $t WHERE order_id=? AND customer_id=?")->execute([$id, $cid]);
    $pdo->prepare('DELETE FROM proc_orders WHERE id=? AND customer_id=?')->execute([$id, $cid]);
}

/** workshop.list {from?, to?, op_type?, owner?} — فقط internal، بدون هیچ فیلد قیمتی. */
function pb_ws_list(PDO $pdo, int $cid, array $p): array {
    $sql = "SELECT * FROM proc_orders WHERE customer_id=? AND direction='internal'";
    $args = [$cid];
    if (($p['from'] ?? '') !== '') { $sql .= ' AND order_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND order_date <= ?'; $args[] = vdate($p['to']); }
    if (($p['op_type'] ?? '') !== '') { $sql .= ' AND op_type = ?'; $args[] = (string)$p['op_type']; }
    if (($p['owner'] ?? '') !== '') { $sql .= ' AND owner = ?'; $args[] = (string)$p['owner']; }
    $st = $pdo->prepare($sql . ' ORDER BY order_date DESC, id DESC LIMIT 500');
    $st->execute($args);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = ['id' => (int)$r['id'], 'uid' => $r['uid'], 'date' => $r['order_date'], 'party' => $r['party'], 'status' => $r['status'],
            'input_kg' => (float)$r['input_kg'], 'output_kg' => (float)$r['output_kg'], 'note' => $r['note']] + pb_ws_fields($r);
    }
    return ['items' => $items];
}

/** workshop.get {id} — internal فقط؛ بدون قیمت. */
function pb_ws_get(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare("SELECT direction FROM proc_orders WHERE id=? AND customer_id=?");
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $d = $st->fetchColumn();
    if ($d !== 'internal') pb_proc_fail('عملیات کارگاه یافت نشد', 404);
    return pb_proc_get($pdo, $cid, $p); // بها در app.php با pb_ws_gate فقط برای workshop.cost + price.view می‌ماند
}

/** workshop.lots — لات‌های قابل‌برداشت برای ورودی (بدون قیمت). */
function pb_ws_lots(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT id, code, type_name, remaining, small_sacks_remaining, large_sacks_remaining, date FROM purchases
        WHERE customer_id=? AND remaining > 0 AND is_mix <> 3 ORDER BY date DESC, id DESC LIMIT 1000');
    $st->execute([$cid]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = ['id' => (int)$r['id'], 'code' => $r['code'], 'type_name' => $r['type_name'], 'remaining' => (float)$r['remaining'],
            'small_sacks_remaining' => (float)$r['small_sacks_remaining'], 'large_sacks_remaining' => (float)$r['large_sacks_remaining'], 'date' => $r['date']];
    }
    return ['items' => $items];
}

/** workshop.delete {id} — فقط internal (کلید workshop.manage نباید سفارش‌های paid/received را حذف کند). */
function pb_ws_delete(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT direction FROM proc_orders WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    if ($st->fetchColumn() !== 'internal') pb_proc_fail('عملیات کارگاه یافت نشد', 404);
    return pb_proc_delete($pdo, $cid, $p);
}

/**
 * workshop.cost_save {id, price_per_kg?, wage_per_kg?, other_cost?, price_deformed?, price_discolored?, price_hull?, price_soil?, price_kernel?,
 *   outputs?:[{id, price_per_kg}]}  — مرحلهٔ ۴: `outputs` (id = proc_outputs.id) قیمت هر ردیف خروجی را می‌گذارد؛ ردیف‌های غایب در آن، out_price ذخیره‌شده‌شان را نگه می‌دارند.
 *   payload قدیمی (بدون outputs): ردیف‌های استاندارد از price_* دسته، ردیف‌های custom از out_price ذخیره‌شده. بعد از ذخیره ستون‌های قدیمی price_* همگام می‌شوند.
 * ثبت/ویرایش قیمت‌ها و محاسبهٔ دوبارهٔ بها (مجوز workshop.cost + price.view). فقط تا وقتی خروجی‌ها فروخته/مصرف نشده‌اند.
 * V خالی = میانگین موزون بهای لات‌های ورودی. مقدار قبلی ورودی‌های غایب حفظ نمی‌شود؛ فرم همهٔ فیلدها را می‌فرستد.
 */
function pb_ws_cost_save(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM proc_orders WHERE id=? AND customer_id=? AND direction='internal'");
    $st->execute([$id, $cid]);
    $o = $st->fetch();
    if (!$o) pb_proc_fail('عملیات کارگاه یافت نشد', 404);
    $used = pb_ws_outputs_used($pdo, $cid, $id);
    if ($used !== null) pb_proc_fail('خروجی «' . $used . '» قبلاً فروخته یا مصرف شده است؛ بها دیگر قابل تغییر نیست');
    $ins = $pdo->prepare('SELECT SUM(kg) AS kg, SUM(cost) AS cost FROM proc_inputs WHERE order_id=? AND customer_id=?');
    $ins->execute([$id, $cid]);
    $ir = $ins->fetch();
    $inKg = (float)$o['input_kg']; $inCost = (float)($ir['cost'] ?? 0);
    $os = $pdo->prepare('SELECT id, lot_id, kg, grade, out_price FROM proc_outputs WHERE order_id=? AND customer_id=? ORDER BY id');
    $os->execute([$id, $cid]);
    $outs = $os->fetchAll();
    $nOuts = []; $by = [];
    $newMap = []; $hasNew = isset($p['outputs']) && is_array($p['outputs']);
    if ($hasNew) foreach ($p['outputs'] as $x) {
        if (!is_array($x)) pb_proc_fail('خروجی نامعتبر است');
        $px = nullableNum($x['price_per_kg'] ?? null);
        if ($px !== null && ($px < 0 || $px > 1e12)) pb_proc_fail('قیمت هر کیلوی خروجی نامعتبر است');
        $newMap[(int)($x['id'] ?? 0)] = $px;
    }
    foreach ($outs as $r) {
        $g = (string)$r['grade'];
        $stored = $r['out_price'] === null ? null : (float)$r['out_price'];
        if ($g === 'sound') $price = null;
        elseif ($hasNew) $price = array_key_exists((int)$r['id'], $newMap) ? $newMap[(int)$r['id']] : $stored;
        else $price = $g === 'custom' ? $stored : null; // payload قدیمی: دستهٔ استاندارد از price_*
        $nOuts[] = ['grade' => $g, 'kg' => (float)$r['kg'], 'price' => $price];
        $by[$g] = ($by[$g] ?? 0.0) + (float)$r['kg'];
    }
    $plan = pb_ws_cost_plan($p, $inKg, $inCost, $nOuts, $by);
    $pdo->beginTransaction();
    try {
        $cols = pb_ws_cost_cols($plan);
        $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($cols)));
        $pdo->prepare("UPDATE proc_orders SET $set WHERE id=? AND customer_id=?")->execute(array_merge(array_values($cols), [$id, $cid]));
        $uc = $pdo->prepare('UPDATE proc_outputs SET cost=?, out_price=? WHERE id=? AND customer_id=?');
        $up = $pdo->prepare('UPDATE purchases SET price=? WHERE id=? AND customer_id=? AND is_mix=2');
        foreach ($outs as $i => $r) {
            $kg = (float)$r['kg'];
            $uc->execute([$plan['lots'][$i], $plan['row_prices'][$i], (int)$r['id'], $cid]);
            $up->execute([$kg > 0 ? max(0.0, round($plan['lots'][$i] / $kg, 2)) : 0.0, (int)$r['lot_id'], $cid]);
        }
        pb_stock_reverse_doc($pdo, $cid, 'proc', $id); // unit_cost حرکت‌ها در امضای pb_stock_post_doc نیست ⇒ صریح برگردان و دوباره ثبت کن
        pb_stock_post_doc($pdo, $cid, 'proc', $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_proc_get($pdo, $cid, ['id' => $id]);
}
