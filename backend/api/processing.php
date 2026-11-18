<?php
declare(strict_types=1);
require_once __DIR__ . '/code_kind.php'; // T1: حرف کد پسته
require_once __DIR__ . '/ddl_guard.php';
require_once __DIR__ . '/workshop.php'; // مرحلهٔ ۱ کارگاه: جهت internal
/**
 * S09.T09.1 — processing orders (فراوری). Both tiers.
 *
 * direction 'paid'     (برون‌سپاری): our lots go to a processor. Input cost + fee (capitalized) is distributed to the output lots,
 *                      so Σ output cost = Σ input cost + fee EXACTLY (remainder to the largest output). Outputs are new lots
 *                      (is_mix=2 like S07 separation outputs, so every sale/stock path works and they are excluded from own purchases).
 * direction 'received' (فراوری امانی): the customer's goods are processed. Inputs and outputs are consigned-in lots (is_mix=3):
 *                      never valued in our inventory. Fee + waste charge (waste_kg × waste_charge_per_kg) is the customer's
 *                      charge (charge_amount). Outputs are returned with deliver (status closed).
 *
 * Tables: proc_orders (header), proc_inputs (lot consumed / brought in), proc_outputs (lots produced).
 * Lifecycle: create → in_progress · receive → done · deliver (received only) → closed.
 * direction 'internal' (کارگاه داخلی، workshop.php): ورودی از موجودی کم، خروجی‌ها با grade/owner/source_lot_id به موجودی برمی‌گردند؛ ثبت یک‌جا (status done)، تراز فقط هشدار.
 * direction 'paid' + قبض (کارگاه بیرونی، مرحلهٔ ۴، workshop_outside.php): فیلدهای شمارهٔ قبض/مالک/عدل/گرم‌ها روی همین ردیف؛ processing.receipt_save، workshop.outside_raw.
 * Mass balance: Σ input kg = Σ output kg + waste_kg (± PB_PROC_TOL), enforced at receive.
 * Lineage: output lot → proc_outputs.order_id → proc_inputs.lot_id (no separate lot_lineage rows).
 * Stock moves (stock_moves doc_type 'proc', desired state in pb_proc_desired):
 *   paid:     proc_out (inputs, at create) · proc_in (outputs, at receive)
 *   received: consign_in (inputs, at create) · proc_out (inputs, at receive) · consign_in (outputs, at receive) · consign_out (outputs, at deliver)
 * Deviation from the stage note: paid inputs leave our stock at create (no virtual 'at processor' warehouse yet; the order row is the record).
 * Delete: paid orders while in_progress only (stock reversed, inputs restored). Received orders create lots at create, so they are not deletable.
 */
const PB_PROC_TOL = 0.0015;
const PB_PROC_DIRS = ['paid', 'received', 'internal'];
const PB_PROC_FEE_BASIS = ['per_kg', 'fixed', 'per_job'];
const PB_PROC_SERVICES = ['hulling', 'drying', 'sorting', 'splitting', 'other'];

function pb_proc_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS proc_orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        direction VARCHAR(10) NOT NULL,
        party VARCHAR(150) NOT NULL,
        order_date DATE NOT NULL,
        service VARCHAR(20) NOT NULL DEFAULT 'other',
        fee_basis VARCHAR(10) NOT NULL DEFAULT 'per_kg',
        fee_value DECIMAL(18,4) NOT NULL DEFAULT 0,
        fee_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        standard_yield_pct DECIMAL(6,2) NOT NULL DEFAULT 100,
        waste_charge_per_kg DECIMAL(18,4) NOT NULL DEFAULT 0,
        charge_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'in_progress',
        warehouse_uid CHAR(36) NULL,
        input_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        input_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        output_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        waste_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        yield_pct DECIMAL(8,3) NULL,
        receive_date DATE NULL,
        deliver_date DATE NULL,
        note VARCHAR(500) NULL,
        op_type VARCHAR(20) NULL,
        owner VARCHAR(150) NULL,
        pistachio_type VARCHAR(100) NULL,
        defect_note VARCHAR(255) NULL,
        initial_kg DECIMAL(14,3) NULL,
        bale_count DECIMAL(10,2) NULL,
        loss_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        balance_diff_kg DECIMAL(14,3) NULL,
        balance_ok TINYINT NULL,
        undelivered_kg DECIMAL(14,3) NULL,
        price_per_kg DECIMAL(18,4) NULL,
        wage_per_kg DECIMAL(18,4) NULL,
        other_cost DECIMAL(18,2) NULL,
        price_deformed DECIMAL(18,4) NULL,
        price_discolored DECIMAL(18,4) NULL,
        price_hull DECIMAL(18,4) NULL,
        price_soil DECIMAL(18,4) NULL,
        price_kernel DECIMAL(18,4) NULL,
        value_in DECIMAL(18,2) NULL,
        cost_total DECIMAL(18,2) NULL,
        recovery_value DECIMAL(18,2) NULL,
        cost_sound DECIMAL(18,2) NULL,
        cost_per_kg_sound DECIMAL(18,4) NULL,
        sent_gram_pct DECIMAL(7,3) NULL,
        recv_kernel_kg DECIMAL(14,3) NULL,
        recv_gram_pct DECIMAL(7,3) NULL,
        receipt_no VARCHAR(60) NULL,
        cash_note VARCHAR(150) NULL,
        non_company_note VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_proc_uid (customer_id, uid),
        KEY idx_proc_cust (customer_id, order_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS proc_inputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        unit_cost DECIMAL(18,4) NULL,
        cost DECIMAL(18,2) NULL,
        KEY idx_pri_order (customer_id, order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS proc_outputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        type_name VARCHAR(100) NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        grade VARCHAR(20) NULL,
        owner VARCHAR(150) NULL,
        source_lot_id INT UNSIGNED NULL,
        KEY idx_pro_order (customer_id, order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_proc_fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

function pb_proc_row(array $r): array {
    return ['id' => (int)$r['id'], 'uid' => $r['uid'], 'direction' => $r['direction'], 'party' => $r['party'], 'date' => $r['order_date'],
        'service' => $r['service'], 'fee_amount' => (float)$r['fee_amount'], 'input_kg' => (float)$r['input_kg'], 'output_kg' => (float)$r['output_kg'],
        'waste_kg' => (float)$r['waste_kg'], 'yield_pct' => $r['yield_pct'] === null ? null : (float)$r['yield_pct'],
        'charge_amount' => (float)$r['charge_amount'], 'status' => $r['status']];
}

/** Stock moves of a processing order (called from pb_stock_desired). Each item: [lot, kind, kg, small, large, unit_cost, date, wh]. */
function pb_proc_desired(PDO $pdo, int $cid, int $id): array {
    $st = $pdo->prepare('SELECT * FROM proc_orders WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $o = $st->fetch();
    if (!$o) return [];
    $rec = $o['direction'] === 'received';
    $received = $o['receive_date'] !== null;
    $out = [];
    $ins = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks, warehouse_uid, unit_cost FROM proc_inputs WHERE order_id=? AND customer_id=? ORDER BY id');
    $ins->execute([$id, $cid]);
    foreach ($ins->fetchAll() as $r) {
        $kg = (float)$r['kg']; $sm = (float)$r['small_sacks']; $lg = (float)$r['large_sacks'];
        if ($rec) {
            $out[] = [(int)$r['lot_id'], 'consign_in', $kg, $sm, $lg, null, (string)$o['order_date'], null];
            if ($received) $out[] = [(int)$r['lot_id'], 'proc_out', -$kg, -$sm, -$lg, null, (string)$o['receive_date'], null];
        } else {
            $out[] = [(int)$r['lot_id'], 'proc_out', -$kg, -$sm, -$lg, (float)$r['unit_cost'], (string)$o['order_date'], ($r['warehouse_uid'] ?? null) ?: null];
        }
    }
    if ($received) {
        $os = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks, cost, warehouse_uid FROM proc_outputs WHERE order_id=? AND customer_id=? ORDER BY id');
        $os->execute([$id, $cid]);
        foreach ($os->fetchAll() as $r) {
            $kg = (float)$r['kg']; $sm = (float)$r['small_sacks']; $lg = (float)$r['large_sacks'];
            if ($rec) {
                $out[] = [(int)$r['lot_id'], 'consign_in', $kg, $sm, $lg, null, (string)$o['receive_date'], null];
                if ($o['deliver_date'] !== null) $out[] = [(int)$r['lot_id'], 'consign_out', -$kg, -$sm, -$lg, null, (string)$o['deliver_date'], null];
            } else {
                $uc = $kg > 0 ? (float)$r['cost'] / $kg : null;
                if ($uc !== null && $uc < 0 && $o['direction'] === 'internal') $uc = 0.0; // کارگاه داخلی: AG منفی ⇒ هشدار؛ ارزش موجودی منفی نمی‌شود
                $out[] = [(int)$r['lot_id'], 'proc_in', $kg, $sm, $lg, $uc, (string)$o['receive_date'], ($r['warehouse_uid'] ?? null) ?: null];
            }
        }
    }
    return $out;
}

/** Lot row for an output or a consigned input: purchases with is_mix 2 (paid) or 3 (received). */
function pb_proc_insert_lot(PDO $pdo, int $cid, int $isMix, int $cat, string $name, string $date, float $price, float $kg, float $sm, float $lg, ?string $note, ?string $wh, string $party): int {
    $isMix = $isMix === 3 ? 3 : 2;
    $ins = $pdo->prepare('INSERT INTO purchases (customer_id, code, category_id, type_name, date, price, total_weight, small_sack_count, large_sack_count, small_sacks_remaining, large_sacks_remaining, net_weight, remaining, seller, note, is_mix) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,' . $isMix . ')');
    $total = round($kg + $sm * 0.1 + $lg * 0.2, 3);
    $ins->execute([$cid, null, $cat, $name, $date, $price, $total, $sm, $lg, $sm, $lg, $kg, $kg, $isMix === 3 ? $party : null, $note]);
    $lotId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE purchases SET code=? WHERE id=? AND customer_id=?')->execute([pb_code_with_id($isMix === 3 ? 'R' : 'B', (int)$lotId), $lotId, $cid]);
    pb_wh_apply_lot($pdo, $cid, $lotId, ['warehouse_uid' => $wh], true);
    return $lotId;
}

/** Fee of an order: per_kg = rate × input kg; fixed / per_job = the value itself. Rounded to 2 decimals. */
function pb_proc_fee(string $basis, float $value, float $inKg): float {
    return $basis === 'per_kg' ? round($value * $inKg, 2) : round($value, 2);
}

function pb_proc_get(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT * FROM proc_orders WHERE id=? AND customer_id=?');
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    $o = $st->fetch();
    if (!$o) pb_proc_fail('سفارش فراوری یافت نشد', 404);
    $id = (int)$o['id'];
    $in = $pdo->prepare('SELECT i.lot_id, p.code, p.type_name, i.kg, i.small_sacks, i.large_sacks, i.unit_cost, i.cost
        FROM proc_inputs i LEFT JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.customer_id=? AND i.order_id=? ORDER BY i.id');
    $in->execute([$cid, $id]);
    $inputs = [];
    foreach ($in->fetchAll() as $r) $inputs[] = ['lot_id' => (int)$r['lot_id'], 'code' => $r['code'], 'type_name' => $r['type_name'], 'kg' => (float)$r['kg'],
        'small_sacks' => (float)$r['small_sacks'], 'large_sacks' => (float)$r['large_sacks'], 'unit_cost' => $r['unit_cost'] === null ? null : (float)$r['unit_cost'],
        'cost' => $r['cost'] === null ? null : (float)$r['cost']];
    $ou = $pdo->prepare('SELECT o.id, o.lot_id, p.code, o.type_name, o.kg, o.small_sacks, o.large_sacks, o.cost, o.warehouse_uid, o.grade, o.owner, o.source_lot_id, o.out_price, sp.code AS source_code
        FROM proc_outputs o LEFT JOIN purchases p ON p.id=o.lot_id AND p.customer_id=o.customer_id
        LEFT JOIN purchases sp ON sp.id=o.source_lot_id AND sp.customer_id=o.customer_id WHERE o.customer_id=? AND o.order_id=? ORDER BY o.id');
    $ou->execute([$cid, $id]);
    $outputs = [];
    foreach ($ou->fetchAll() as $r) $outputs[] = ['id' => (int)$r['id'], 'lot_id' => (int)$r['lot_id'], 'code' => $r['code'], 'type_name' => $r['type_name'], 'kg' => (float)$r['kg'],
        'small_sacks' => (float)$r['small_sacks'], 'large_sacks' => (float)$r['large_sacks'], 'cost' => (float)$r['cost'], 'warehouse_uid' => $r['warehouse_uid'],
        'grade' => $r['grade'], 'grade_label' => PB_WS_GRADE_LABELS[(string)$r['grade']] ?? null, 'owner' => $r['owner'],
        'source_lot_id' => $r['source_lot_id'] === null ? null : (int)$r['source_lot_id'], 'source_code' => $r['source_code'],
        'out_price' => $r['out_price'] === null ? null : (float)$r['out_price']]; // مرحلهٔ ۴؛ برای غیرمجاز با pb_ws_public حذف می‌شود
    $row = pb_proc_row($o);
    $row['fee_basis'] = $o['fee_basis'];
    $row['fee_value'] = (float)$o['fee_value'];
    $row['standard_yield_pct'] = (float)$o['standard_yield_pct'];
    $row['yield_variance_pct'] = $o['yield_pct'] === null ? null : round((float)$o['yield_pct'] - (float)$o['standard_yield_pct'], 3);
    $row['waste_charge_per_kg'] = (float)$o['waste_charge_per_kg'];
    $row['input_cost'] = (float)$o['input_cost'];
    $row['receive_date'] = $o['receive_date'];
    $row['deliver_date'] = $o['deliver_date'];
    $row['warehouse_uid'] = $o['warehouse_uid'];
    $row['note'] = $o['note'];
    $row['inputs'] = $inputs;
    $row['outputs'] = $outputs;
    // mass balance (only meaningful once received): Σin − Σout − waste must be ~0
    $row['check'] = $o['status'] === 'in_progress' ? null : ['kg' => round((float)$o['input_kg'] - (float)$o['output_kg'] - (float)$o['waste_kg'], 3)];
    $row['deletable'] = $o['direction'] === 'paid' && $o['status'] === 'in_progress';
    if ($o['direction'] === 'paid') $row = array_merge($row, pb_ow_fields($o)); // مرحلهٔ ۴: قبض کارگاه بیرونی (بدون مبلغ)
    if ($o['direction'] === 'internal') { // کارگاه داخلی: هشدار تراز (R = H − (I+…+O))، حذف تا وقتی خروجی‌ها مصرف نشده‌اند
        $row = array_merge($row, pb_ws_fields($o), pb_ws_cost_fields($o));
        $row['check'] = ['kg' => $row['balance_diff_kg'], 'ok' => $row['balance_ok'], 'label' => $row['balance_label']];
        $row['deletable'] = pb_ws_outputs_used($pdo, $cid, $id) === null;
    }
    return $row;
}

/** processing.list {from?, to?, direction?, status?, party?} */
function pb_proc_list(PDO $pdo, int $cid, array $p): array {
    $sql = 'SELECT * FROM proc_orders WHERE customer_id=?';
    $args = [$cid];
    if (($p['from'] ?? '') !== '') { $sql .= ' AND order_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND order_date <= ?'; $args[] = vdate($p['to']); }
    if (($p['direction'] ?? '') !== '') { $sql .= ' AND direction = ?'; $args[] = (string)$p['direction']; }
    if (($p['status'] ?? '') !== '') { $sql .= ' AND status = ?'; $args[] = (string)$p['status']; }
    if (($p['party'] ?? '') !== '') { $sql .= ' AND party = ?'; $args[] = (string)$p['party']; }
    $st = $pdo->prepare($sql . ' ORDER BY order_date DESC, id DESC LIMIT 500');
    $st->execute($args);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = pb_proc_row($r) + ($r['direction'] === 'internal' ? pb_ws_fields($r) + pb_ws_cost_fields($r) : ($r['direction'] === 'paid' ? pb_ow_fields($r) : []));
    return ['items' => $items];
}

/** processing.create {uid?, direction, party, date?, service?, fee_basis?, fee_value?, standard_yield_pct?, waste_charge_per_kg?, warehouse_uid?, note?,
 *   paid + قبض: receipt_no?, cash_note?, non_company_note?, owner?, bale_count?, sent_gram_pct?, (recv_kernel_kg?, recv_gram_pct? معمولاً با receive)
 *   items:[paid: {purchase_id, kg, small_sacks?, large_sacks?, warehouse_uid?} | received: {type_name, kg, small_sacks?, large_sacks?, category_id?}]} */
function pb_proc_create(PDO $pdo, int $cid, array $p): array {
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT id FROM proc_orders WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $eid = $ex->fetchColumn();
        if ($eid) return pb_proc_get($pdo, $cid, ['id' => (int)$eid]); // idempotent replay (offline queue)
    }
    $dir = (string)($p['direction'] ?? '');
    if (!in_array($dir, PB_PROC_DIRS, true)) pb_proc_fail('جهت فراوری نامعتبر است');
    if ($dir === 'internal') return pb_ws_create($pdo, $cid, $p); // کارگاه داخلی: ثبت یک‌جا، تراز هشدار
    $party = str_or_null($p['party'] ?? null, 150);
    if ($party === null) pb_proc_fail('نام طرف حساب (فرآوری‌کننده / صاحب کالا) را وارد کنید');
    $service = (string)($p['service'] ?? 'other');
    if (!in_array($service, PB_PROC_SERVICES, true)) pb_proc_fail('نوع خدمت فراوری نامعتبر است');
    $basis = (string)($p['fee_basis'] ?? 'per_kg');
    if (!in_array($basis, PB_PROC_FEE_BASIS, true)) pb_proc_fail('روش محاسبهٔ کرایه نامعتبر است');
    $fv = num($p['fee_value'] ?? 0);
    if ($fv < 0 || $fv > 1e13) pb_proc_fail('مقدار کرایه نامعتبر است');
    $std = num($p['standard_yield_pct'] ?? 100);
    if ($std <= 0 || $std > 100) pb_proc_fail('بازدهٔ استاندارد باید بین ۰ و ۱۰۰ باشد');
    $wcharge = num($p['waste_charge_per_kg'] ?? 0);
    if ($wcharge < 0 || $wcharge > 1e13) pb_proc_fail('نرخ ضایعات نامعتبر است');
    $date = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $note = str_or_null($p['note'] ?? null, 500);
    $ow = $dir === 'paid' ? pb_ow_normalize($p) : []; // مرحلهٔ ۴: قبض کارگاه بیرونی (اختیاری)
    $items = $p['items'] ?? [];
    if (!is_array($items) || count($items) < 1) pb_proc_fail('حداقل یک ورودی ثبت کنید');
    if (count($items) > 100) pb_proc_fail('تعداد اقلام بیش از حد مجاز است');
    $wh = null;
    if (!empty($p['warehouse_uid']) && is_string($p['warehouse_uid'])) $wh = pb_wh_require($pdo, $cid, $p['warehouse_uid']);
    $fc = $pdo->prepare('SELECT id FROM categories WHERE customer_id=? ORDER BY id LIMIT 1');
    $fc->execute([$cid]);
    $firstCat = (int)$fc->fetchColumn();

    $norm = []; $inKg = 0.0;
    foreach ($items as $o) {
        if (!is_array($o)) pb_proc_fail('ورودی نامعتبر است');
        $kg = num($o['kg'] ?? $o['weight'] ?? 0);
        if ($kg <= 0 || $kg > 1e9) pb_proc_fail('وزن هر ورودی باید بیشتر از صفر باشد');
        $sm = num($o['small_sacks'] ?? 0); $lg = num($o['large_sacks'] ?? 0);
        if ($sm < 0 || $lg < 0 || $sm > 1e7 || $lg > 1e7) pb_proc_fail('تعداد گونی نامعتبر است');
        $n = ['kg' => $kg, 'sm' => $sm, 'lg' => $lg, 'wh' => (!empty($o['warehouse_uid']) && is_string($o['warehouse_uid'])) ? $o['warehouse_uid'] : null];
        if ($dir === 'paid') {
            $pid = (int)($o['purchase_id'] ?? 0);
            if ($pid <= 0) pb_proc_fail('لات ورودی را انتخاب کنید');
            $n['pid'] = $pid;
        } else {
            $name = str_or_null($o['type_name'] ?? null, 100);
            if ($name === null) pb_proc_fail('نوع کالای هر ورودی را وارد کنید');
            $cat = (int)($o['category_id'] ?? 0);
            if ($cat <= 0) $cat = $firstCat;
            if ($cat <= 0 || !ownsCategory($pdo, $cid, $cat)) pb_proc_fail('دستهٔ کالا یافت نشد', 404);
            $n['name'] = $name; $n['cat'] = $cat;
        }
        $inKg += $kg;
        $norm[] = $n;
    }
    $fee = pb_proc_fee($basis, $fv, $inKg);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO proc_orders (customer_id, uid, direction, party, order_date, service, fee_basis, fee_value, fee_amount, standard_yield_pct, waste_charge_per_kg, status, warehouse_uid, input_kg, note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $uid, $dir, $party, $date, $service, $basis, $fv, $fee, $std, $wcharge, 'in_progress', $wh, round($inKg, 3), $note]);
        $id = (int)$pdo->lastInsertId();
        pb_ow_apply($pdo, $cid, $id, $ow);
        $inCost = 0.0;
        $insIn = $pdo->prepare('INSERT INTO proc_inputs (customer_id, order_id, lot_id, kg, small_sacks, large_sacks, warehouse_uid, unit_cost, cost) VALUES (?,?,?,?,?,?,?,?,?)');
        if ($dir === 'paid') {
            $lock = $pdo->prepare('SELECT p.is_mix, p.remaining, p.type_name, p.small_sacks_remaining, p.large_sacks_remaining, ' . PB_BROKER_COST_SQL . ' AS cost
                FROM purchases p WHERE p.id=? AND p.customer_id=? FOR UPDATE');
            $rem = $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?');
            foreach ($norm as $n) {
                $lock->execute([$n['pid'], $cid]);
                $row = $lock->fetch();
                if (!$row) pb_proc_fail('یکی از ورودی‌ها یافت نشد', 404);
                if ((int)$row['is_mix'] === 3) pb_proc_fail('کالای امانی قابل فراوری برون‌سپاری نیست');
                if ($n['kg'] - (float)$row['remaining'] > 1e-6) pb_proc_fail('موجودی «' . $row['type_name'] . '» کافی نیست');
                checkSacksAvailable($pdo, $row, $n['sm'], $n['lg']);
                $whLot = pb_stock_pick_wh_or_bad($pdo, $cid, $n['pid'], $n['kg'], $n['wh'] ?? $wh);
                $unit = (float)$row['cost'];
                $cost = round($n['kg'] * $unit, 2);
                $inCost += $cost;
                $insIn->execute([$cid, $id, $n['pid'], $n['kg'], $n['sm'], $n['lg'], $whLot, round($unit, 4), $cost]);
                $rem->execute([$n['kg'], $n['sm'], $n['lg'], $n['pid'], $cid]);
            }
        } else {
            foreach ($norm as $n) {
                $lotId = pb_proc_insert_lot($pdo, $cid, 3, $n['cat'], $n['name'], $date, 0.0, $n['kg'], $n['sm'], $n['lg'], $note, $wh, $party);
                $insIn->execute([$cid, $id, $lotId, $n['kg'], $n['sm'], $n['lg'], $wh, null, null]);
            }
        }
        $pdo->prepare('UPDATE proc_orders SET input_cost=? WHERE id=? AND customer_id=?')->execute([round($inCost, 2), $id, $cid]);
        pb_stock_post_doc($pdo, $cid, 'proc', $id);
        if ($dir === 'paid' && function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, 'proc_send', $id); // S16.T16.5a (gold)
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_proc_get($pdo, $cid, ['id' => $id]);
}

/** processing.receive {id, outputs:[{type_name, kg, small_sacks?, large_sacks?, category_id?}], waste_kg?, date?, warehouse_uid?}
 *  Mass balance: Σin = Σout + waste (± tolerance). Paid: outputs carry input cost + fee. Received: outputs stay as consigned lots. */
function pb_proc_receive(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $outs = $p['outputs'] ?? [];
    if (!is_array($outs) || count($outs) < 1) pb_proc_fail('حداقل یک خروجی ثبت کنید');
    if (count($outs) > 100) pb_proc_fail('تعداد خروجی‌ها بیش از حد مجاز است');
    $waste = num($p['waste_kg'] ?? 0);
    if ($waste < 0 || $waste > 1e9) pb_proc_fail('وزن ضایعات نامعتبر است');
    $rdate = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $owRecv = pb_ow_normalize($p, ['recv_kernel_kg', 'recv_gram_pct', 'receipt_no']); // مرحلهٔ ۴: مغز/گرم دریافتی و شمارهٔ قبض (فقط paid ذخیره می‌شود)
    $wh0 = !empty($p['warehouse_uid']) && is_string($p['warehouse_uid']) ? pb_wh_require($pdo, $cid, $p['warehouse_uid']) : null;

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM proc_orders WHERE id=? AND customer_id=? FOR UPDATE');
        $st->execute([$id, $cid]);
        $o = $st->fetch();
        if (!$o) pb_proc_fail('سفارش فراوری یافت نشد', 404);
        if ($o['status'] !== 'in_progress') pb_proc_fail('این سفارش قبلاً دریافت شده است');
        $rec = $o['direction'] === 'received';
        $wh = $wh0 ?? ($o['warehouse_uid'] ?: null);
        $fc = $pdo->prepare('SELECT id FROM categories WHERE customer_id=? ORDER BY id LIMIT 1');
        $fc->execute([$cid]);
        $firstCat = (int)$fc->fetchColumn();

        $norm = []; $outKg = 0.0;
        foreach ($outs as $x) {
            if (!is_array($x)) pb_proc_fail('خروجی نامعتبر است');
            $kg = num($x['kg'] ?? 0);
            if ($kg <= 0 || $kg > 1e9) pb_proc_fail('وزن هر خروجی باید بیشتر از صفر باشد');
            $name = str_or_null($x['type_name'] ?? null, 100);
            if ($name === null) pb_proc_fail('نام/نوع هر خروجی را وارد کنید');
            $sm = num($x['small_sacks'] ?? 0); $lg = num($x['large_sacks'] ?? 0);
            if ($sm < 0 || $lg < 0 || $sm > 1e7 || $lg > 1e7) pb_proc_fail('تعداد گونی نامعتبر است');
            $cat = (int)($x['category_id'] ?? 0);
            if ($cat <= 0) $cat = $firstCat;
            if ($cat <= 0 || !ownsCategory($pdo, $cid, $cat)) pb_proc_fail('دستهٔ خروجی یافت نشد', 404);
            $outKg += $kg;
            $norm[] = ['kg' => $kg, 'name' => $name, 'sm' => $sm, 'lg' => $lg, 'cat' => $cat];
        }
        $inKg = (float)$o['input_kg'];
        $diff = $inKg - $outKg - $waste;
        if (abs($diff) > PB_PROC_TOL + 1e-9) {
            $f = function ($v) { return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.'); };
            pb_proc_fail($diff > 0
                ? 'وزن ورودی ' . $f($inKg) . ' کیلو است ولی خروجی + ضایعات فقط ' . $f($outKg + $waste) . ' کیلو می‌شود؛ ' . $f($diff) . ' کیلو اختلاف ثبت‌نشده دارید'
                : 'خروجی + ضایعات (' . $f($outKg + $waste) . ' کیلو) از وزن ورودی (' . $f($inKg) . ' کیلو) بیشتر است؛ ' . $f(-$diff) . ' کیلو اضافه است');
        }

        // paid: Σ output cost = input cost + fee (2-decimal lines, remainder to the largest output)
        $distributable = $rec ? 0.0 : round((float)$o['input_cost'] + (float)$o['fee_amount'], 2);
        $costs = [];
        $acc = 0.0; $big = 0;
        foreach ($norm as $i => $x) {
            $costs[$i] = $rec ? 0.0 : round($distributable * $x['kg'] / $outKg, 2);
            $acc += $costs[$i];
            if ($x['kg'] > $norm[$big]['kg']) $big = $i;
        }
        if (!$rec) $costs[$big] = round($costs[$big] + ($distributable - $acc), 2);

        // received: inputs are consumed from the customer's consigned lots
        if ($rec) {
            $ins = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks FROM proc_inputs WHERE order_id=? AND customer_id=?');
            $ins->execute([$id, $cid]);
            $rem = $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?');
            foreach ($ins->fetchAll() as $r) {
                $rem->execute([(float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks'], (int)$r['lot_id'], $cid]);
            }
        }

        $insOut = $pdo->prepare('INSERT INTO proc_outputs (customer_id, order_id, lot_id, type_name, kg, small_sacks, large_sacks, cost, warehouse_uid) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($norm as $i => $x) {
            $price = $rec ? 0.0 : round($costs[$i] / $x['kg'], 2);
            $lotId = pb_proc_insert_lot($pdo, $cid, $rec ? 3 : 2, $x['cat'], $x['name'], $rdate, $price, $x['kg'], $x['sm'], $x['lg'], null, $wh, (string)$o['party']);
            $insOut->execute([$cid, $id, $lotId, $x['name'], $x['kg'], $x['sm'], $x['lg'], $costs[$i], $wh]);
        }

        $charge = $rec ? round((float)$o['fee_amount'] + round($waste * (float)$o['waste_charge_per_kg'], 2), 2) : 0.0;
        $yield = $inKg > 0 ? round($outKg / $inKg * 100, 3) : null;
        $pdo->prepare('UPDATE proc_orders SET status=?, receive_date=?, output_kg=?, waste_kg=?, yield_pct=?, charge_amount=? WHERE id=? AND customer_id=?')
            ->execute(['done', $rdate, round($outKg, 3), round($waste, 3), $yield, $charge, $id, $cid]);
        if (!$rec) pb_ow_apply($pdo, $cid, $id, $owRecv);
        pb_stock_post_doc($pdo, $cid, 'proc', $id);
        if (function_exists('pb_plc_post')) pb_plc_post($pdo, $cid, $rec ? 'proc_toll' : 'proc_recv', $id); // S16.T16.5a (gold)
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_proc_get($pdo, $cid, ['id' => $id]);
}

/** processing.deliver {id, date?} — received orders only: outputs go back to the customer (consign_out, lots leave our stock). */
function pb_proc_deliver(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $rdate = ($p['date'] ?? '') !== '' ? vdate($p['date']) : today();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM proc_orders WHERE id=? AND customer_id=? FOR UPDATE');
        $st->execute([$id, $cid]);
        $o = $st->fetch();
        if (!$o) pb_proc_fail('سفارش فراوری یافت نشد', 404);
        if ($o['direction'] !== 'received') pb_proc_fail('تحویل به مشتری فقط برای فراوری امانی است');
        if ($o['status'] !== 'done') pb_proc_fail('ابتدا خروجی‌ها را دریافت کنید');
        $ou = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks FROM proc_outputs WHERE order_id=? AND customer_id=?');
        $ou->execute([$id, $cid]);
        $rows = $ou->fetchAll();
        $lock = $pdo->prepare('SELECT type_name, remaining FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $rem = $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?');
        foreach ($rows as $r) {
            $lock->execute([(int)$r['lot_id'], $cid]);
            $lot = $lock->fetch();
            if (!$lot) pb_proc_fail('یکی از خروجی‌ها یافت نشد', 404);
            if ((float)$lot['remaining'] + 1e-6 < (float)$r['kg']) pb_proc_fail('بخشی از «' . $lot['type_name'] . '» قبلاً از انبار خارج شده است');
            $rem->execute([(float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks'], (int)$r['lot_id'], $cid]);
        }
        $pdo->prepare('UPDATE proc_orders SET status=?, deliver_date=? WHERE id=? AND customer_id=?')->execute(['closed', $rdate, $id, $cid]);
        pb_stock_post_doc($pdo, $cid, 'proc', $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return pb_proc_get($pdo, $cid, ['id' => $id]);
}

/** processing.delete {id} — paid orders while in_progress: stock moves reversed, inputs restored, order removed. */
function pb_proc_delete(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM proc_orders WHERE id=? AND customer_id=? FOR UPDATE');
        $st->execute([$id, $cid]);
        $o = $st->fetch();
        if (!$o) pb_proc_fail('سفارش فراوری یافت نشد', 404);
        if ($o['direction'] === 'internal') {
            pb_ws_revert($pdo, $cid, $id);
            $pdo->commit();
            return ['ok' => true];
        }
        if ($o['direction'] !== 'paid') pb_proc_fail('سفارش فراوری امانی پس از ثبت قابل حذف نیست');
        if ($o['status'] !== 'in_progress') pb_proc_fail('فقط سفارشِ در جریان قابل حذف است');
        pb_stock_reverse_doc($pdo, $cid, 'proc', $id);
        if (function_exists('pb_plc_unpost')) pb_plc_unpost($pdo, $cid, 'proc_send', $id); // S16.T16.5a
        $ins = $pdo->prepare('SELECT lot_id, kg, small_sacks, large_sacks FROM proc_inputs WHERE order_id=? AND customer_id=?');
        $ins->execute([$id, $cid]);
        $back = $pdo->prepare('UPDATE purchases SET remaining = remaining + ?, small_sacks_remaining = small_sacks_remaining + ?, large_sacks_remaining = large_sacks_remaining + ? WHERE id=? AND customer_id=?');
        foreach ($ins->fetchAll() as $r) $back->execute([(float)$r['kg'], (float)$r['small_sacks'], (float)$r['large_sacks'], (int)$r['lot_id'], $cid]);
        $pdo->prepare('DELETE FROM proc_inputs WHERE order_id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM proc_orders WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}

// ---------------------------------------------------------------------------
// S09.T09.2 — reports: summary, outstanding, party statement (+ party statement hook)
// ---------------------------------------------------------------------------

/** Statement events of processing fees (hook of pb_party_event_hooks). Convention: + = party owes us, − = we owe party.
 *  received (فراوری امانی): charge_amount is a debit on the customer, once the order is received.
 *  paid (برون‌سپاری): fee_amount is a credit to the processor (payments to processors are not tracked yet). */
function pb_proc_party_events(PDO $pdo, int $cid): array {
    try {
        $st = $pdo->prepare("SELECT id, party, receive_date, direction, fee_amount, charge_amount, status, service FROM proc_orders
            WHERE customer_id=? AND status IN ('done','closed') AND receive_date IS NOT NULL");
        $st->execute([$cid]);
    } catch (Throwable $e) {
        return []; // table not migrated yet
    }
    $ev = [];
    foreach ($st->fetchAll() as $r) {
        $k = partyKey($r['party']);
        if ($r['direction'] === 'internal') continue; // کارگاه داخلی طرف‌حساب مالی ندارد
        if ($r['direction'] === 'received') {
            $amt = (float)$r['charge_amount'];
            if ($amt <= 0) continue;
            $ev[] = ['key' => $k, 'date' => (string)$r['receive_date'], 'type' => 'proc_charge', 'ref' => 'PO' . (int)$r['id'],
                'label' => 'فراوری امانی (' . $r['service'] . ')', 'effect' => $amt, 'doc_id' => (int)$r['id'], 'ord' => 1];
        } else {
            $amt = (float)$r['fee_amount'];
            if ($amt <= 0) continue;
            $ev[] = ['key' => $k, 'date' => (string)$r['receive_date'], 'type' => 'proc_fee', 'ref' => 'PO' . (int)$r['id'],
                'label' => 'کرایه فراوری برون‌سپاری (' . $r['service'] . ')', 'effect' => -$amt, 'doc_id' => (int)$r['id'], 'ord' => 1];
        }
    }
    return $ev;
}

/** reports.processing_summary {from?, to?, direction?, group?: party|service|month}
 *  Yield and waste are computed on received orders only (done/closed); open orders count in `orders` and `open`. */
function pb_proc_summary(PDO $pdo, int $cid, array $p): array {
    $group = (string)($p['group'] ?? 'party');
    if (!in_array($group, ['party', 'service', 'month'], true)) bad('نوع گروه‌بندی نامعتبر است');
    $sql = 'SELECT * FROM proc_orders WHERE customer_id=?';
    $args = [$cid];
    if (($p['from'] ?? '') !== '') { $sql .= ' AND order_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND order_date <= ?'; $args[] = vdate($p['to']); }
    if (in_array((string)($p['direction'] ?? ''), PB_PROC_DIRS, true)) { $sql .= ' AND direction = ?'; $args[] = (string)$p['direction']; }
    $st = $pdo->prepare($sql . ' ORDER BY order_date, id LIMIT 5000');
    $st->execute($args);
    $blank = static function (string $key, string $label): array {
        return ['key' => $key, 'label' => $label, 'orders' => 0, 'open' => 0, 'input_kg' => 0.0, 'done_input_kg' => 0.0, 'output_kg' => 0.0,
            'waste_kg' => 0.0, 'yield_pct' => null, 'fee' => 0.0, 'charge' => 0.0, 'input_cost' => 0.0];
    };
    $groups = [];
    $tot = $blank('total', 'جمع کل');
    foreach ($st->fetchAll() as $r) {
        $key = $group === 'party' ? (string)$r['party'] : ($group === 'service' ? (string)$r['service'] : substr((string)$r['order_date'], 0, 7));
        if (!isset($groups[$key])) $groups[$key] = $blank($key, $key);
        $done = in_array($r['status'], ['done', 'closed'], true);
        foreach ([&$groups[$key], &$tot] as &$g) {
            $g['orders']++;
            if ($r['status'] === 'in_progress') $g['open']++;
            $g['input_kg'] += (float)$r['input_kg'];
            $g['fee'] += (float)$r['fee_amount'];
            $g['charge'] += (float)$r['charge_amount'];
            if ($r['direction'] === 'paid') $g['input_cost'] += (float)$r['input_cost'];
            if ($done && $r['direction'] === 'received') {
                $g['done_input_kg'] += (float)$r['input_kg'];
                $g['output_kg'] += (float)$r['output_kg'];
                $g['waste_kg'] += (float)$r['waste_kg'];
            }
        }
        unset($g);
    }
    $finish = static function (array $g): array {
        foreach (['input_kg', 'done_input_kg', 'output_kg', 'waste_kg', 'fee', 'charge', 'input_cost'] as $k) $g[$k] = round($g[$k], $k === 'fee' || $k === 'charge' || $k === 'input_cost' ? 2 : 3);
        $g['yield_pct'] = $g['done_input_kg'] > 0 ? round($g['output_kg'] / $g['done_input_kg'] * 100, 3) : null;
        $g['balance_kg'] = round($g['done_input_kg'] - $g['output_kg'] - $g['waste_kg'], 3); // must be 0.000
        return $g;
    };
    ksort($groups);
    $outGroups = [];
    foreach ($groups as $g) $outGroups[] = $finish($g);
    return ['group' => $group, 'groups' => $outGroups, 'total' => $finish($tot)];
}

/** reports.processing_outstanding — goods still at processors / with us for customers, and fees receivable / payable.
 *  Payments of fees are not tracked yet, so payable/receivable are the amounts charged, not net balances. */
function pb_proc_outstanding(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare("SELECT * FROM proc_orders WHERE customer_id=? AND (status='in_progress' OR (direction='received' AND status='done'))
        ORDER BY order_date, id LIMIT 5000");
    $st->execute([$cid]);
    $items = []; $byParty = []; $recv = 0.0; $pay = 0.0;
    foreach ($st->fetchAll() as $r) {
        $isRec = $r['direction'] === 'received';
        if ($r['status'] === 'in_progress') {
            $kind = $isRec ? 'awaiting_processing' : 'at_processor';
            $kg = (float)$r['input_kg'];
            $val = $isRec ? 0.0 : (float)$r['input_cost'];
        } else { // received, done, not yet delivered
            $kind = 'awaiting_delivery';
            $kg = (float)$r['output_kg'];
            $val = 0.0;
        }
        $items[] = ['id' => (int)$r['id'], 'party' => $r['party'], 'direction' => $r['direction'], 'date' => $r['order_date'], 'kind' => $kind,
            'kg' => round($kg, 3), 'value' => round($val, 2), 'status' => $r['status']];
        $k = (string)$r['party'];
        if (!isset($byParty[$k])) $byParty[$k] = ['party' => $k, 'kg' => 0.0, 'orders' => 0];
        $byParty[$k]['kg'] += $kg; $byParty[$k]['orders']++;
    }
    // fees: receivable from customers (received orders, charged on receive) / payable to processors (paid orders, fee on receive)
    $fs = $pdo->prepare("SELECT direction, COALESCE(SUM(charge_amount),0) AS charge, COALESCE(SUM(fee_amount),0) AS fee FROM proc_orders
        WHERE customer_id=? AND status IN ('done','closed') GROUP BY direction");
    $fs->execute([$cid]);
    foreach ($fs->fetchAll() as $r) {
        if ($r['direction'] === 'received') $recv += (float)$r['charge'];
        else $pay += (float)$r['fee'];
    }
    foreach ($byParty as &$b) $b['kg'] = round($b['kg'], 3);
    unset($b);
    return ['items' => $items, 'by_party' => array_values($byParty), 'fee_receivable' => round($recv, 2), 'fee_payable' => round($pay, 2),
        'payments_tracked' => false];
}

/** processing.statement {party, direction?} — orders of one party with fee/charge totals; same rows as the party statement hook. */
function pb_proc_statement(PDO $pdo, int $cid, array $p): array {
    $party = str_or_null($p['party'] ?? null, 150);
    if ($party === null) bad('نام طرف حساب را وارد کنید');
    $sql = 'SELECT * FROM proc_orders WHERE customer_id=? AND party=?';
    $args = [$cid, $party];
    if (in_array((string)($p['direction'] ?? ''), PB_PROC_DIRS, true)) { $sql .= ' AND direction=?'; $args[] = (string)$p['direction']; }
    $st = $pdo->prepare($sql . ' ORDER BY order_date, id LIMIT 5000');
    $st->execute($args);
    $rows = []; $t = ['fee' => 0.0, 'charge' => 0.0, 'input_kg' => 0.0, 'output_kg' => 0.0, 'waste_kg' => 0.0, 'open' => 0];
    foreach ($st->fetchAll() as $r) {
        $done = in_array($r['status'], ['done', 'closed'], true);
        $fee = $done && $r['direction'] === 'paid' ? (float)$r['fee_amount'] : 0.0;
        $charge = $done && $r['direction'] === 'received' ? (float)$r['charge_amount'] : 0.0;
        $rows[] = ['id' => (int)$r['id'], 'date' => $r['order_date'], 'direction' => $r['direction'], 'service' => $r['service'], 'status' => $r['status'],
            'input_kg' => (float)$r['input_kg'], 'output_kg' => (float)$r['output_kg'], 'waste_kg' => (float)$r['waste_kg'],
            'fee' => round($fee, 2), 'charge' => round($charge, 2)];
        $t['fee'] += $fee; $t['charge'] += $charge; $t['input_kg'] += (float)$r['input_kg'];
        $t['output_kg'] += $done ? (float)$r['output_kg'] : 0.0; $t['waste_kg'] += $done ? (float)$r['waste_kg'] : 0.0;
        if ($r['status'] === 'in_progress') $t['open']++;
    }
    $t['fee'] = round($t['fee'], 2); $t['charge'] = round($t['charge'], 2);
    // ties to the party statement: same source rows, effect = charge (debit) − fee (credit)
    $t['net_effect'] = round($t['charge'] - $t['fee'], 2);
    return ['party' => $party, 'rows' => $rows, 'totals' => $t];
}
