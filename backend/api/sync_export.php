<?php
declare(strict_types=1);
/**
 * S10 — sync.export: دریافت کاملِ «فقط‌خواندنیِ امن» برای کارکنِ غیرمالک (جایگزین backup.export که مخصوص مالک است).
 *
 * اصول:
 *  1) فقط‌خواندنی؛ هیچ ver++ و نوشتنی ندارد (در pb_sync_is_write هم «sync.*» نوشتنی حساب نمی‌شود). تنها اثر جانبی: pb_wh_default_uid
 *     (ایدمپوتنت، همان که backup.export/bootstrap هم صدا می‌زنند).
 *  2) deny-by-default: هر جدولِ BACKUP_TABLES که در PB_SYNC_EXPORT_TABLES نیامده، برای کارکن «خالی» می‌رود
 *     (کلیدش هست تا کلاینت داده‌ی محلیِ قدیمی را درست پاک کند)؛ import_batches و … عمداً در نگاشت نیستند.
 *  3) هر جدول فقط اگر کارکن حداقل یکی از مجوزهای فهرستش را داشته باشد می‌آید.
 *  4) جدول‌های مالی ('price' => true) بدون «price.view» خالی می‌روند؛ در جدول‌های دیگر ستون‌های قیمت/هزینه با 0 (یا null) پوشانده می‌شوند
 *     (کلید حذف نمی‌شود چون SQLite محلی ستون‌هایی مثل price را NOT NULL دارد).
 *  5) entityها همان قواعد entity.list: مجوز view، row_filter (مثلاً اتاق انس فقط پذیرش‌های خودش)، و حذف ستون‌های قیمت بدون price.view.
 *  6) پذیرش‌های اتاق انس (lot_intakes) فقط در «entities» می‌آیند، نه در purchases؛ موجودی فقط از purchases ساخته می‌شود و
 *     purchases فقط با intake.confirm (حسابدار/مالک) ردیف می‌گیرد ⇒ تا قبل از تأیید هیچ اثری روی موجودی ندارند.
 */

/** جدول => ['any' => [مجوزها (حداقل یکی)], 'price' => true اگر مالی است و بدون price.view کاملاً خالی می‌رود] */
const PB_SYNC_EXPORT_TABLES = [
    'categories' => ['any' => [], 'price' => false],
    'types' => ['any' => [], 'price' => false],
    'purchases' => ['any' => ['purchases.view', 'purchases.create', 'stock.view', 'stock.count', 'stock.transfer'], 'price' => false],
    'mix_sources' => ['any' => ['purchases.view', 'purchases.create', 'stock.view', 'mixes.create', 'mixes.update'], 'price' => false],
    'stock_transfers' => ['any' => ['stock.view', 'stock.transfer'], 'price' => false],
    'purchase_returns' => ['any' => ['purchases.view', 'purchases.return'], 'price' => false],
    'purchase_return_items' => ['any' => ['purchases.view', 'purchases.return'], 'price' => false],
    'separations' => ['any' => ['stock.view', 'separations.create', 'separations.update'], 'price' => false],
    'separation_inputs' => ['any' => ['stock.view', 'separations.create', 'separations.update'], 'price' => false],
    'separation_outputs' => ['any' => ['stock.view', 'separations.create', 'separations.update'], 'price' => false],
    'lot_lineage' => ['any' => ['stock.view', 'separations.create', 'separations.update'], 'price' => false],
    'proc_orders' => ['any' => ['processing.create', 'processing.manage', 'workshop.view', 'workshop.operate'], 'price' => false],
    'proc_inputs' => ['any' => ['processing.create', 'processing.manage', 'workshop.view', 'workshop.operate'], 'price' => false],
    'proc_outputs' => ['any' => ['processing.create', 'processing.manage', 'workshop.view', 'workshop.operate'], 'price' => false],
    'consignments' => ['any' => ['consignments.create', 'consignments.manage', 'stock.view'], 'price' => false],
    'consignment_items' => ['any' => ['consignments.create', 'consignments.manage', 'stock.view'], 'price' => false],
    'consignment_returns' => ['any' => ['consignments.create', 'consignments.manage', 'stock.view'], 'price' => false],
    'consignment_payments' => ['any' => ['consignments.manage'], 'price' => true],
    'consignment_sales' => ['any' => ['consignments.manage'], 'price' => true],
    'sales' => ['any' => ['sales.view', 'sales.create', 'sales.return', 'pos.use'], 'price' => false],
    'sale_items' => ['any' => ['sales.view', 'sales.create', 'sales.return', 'pos.use'], 'price' => false],
    'sale_returns' => ['any' => ['sales.view', 'sales.return'], 'price' => false],
    'sale_return_items' => ['any' => ['sales.view', 'sales.return'], 'price' => false],
    'letters_of_credit' => ['any' => ['sales.view'], 'price' => true],
    'sale_export' => ['any' => ['sales.view'], 'price' => true],
    'payments' => ['any' => ['payments.save', 'dues.view', 'purchases.view'], 'price' => true],
    'lump_tx' => ['any' => ['payments.save', 'dues.view', 'purchases.view'], 'price' => true],
    'credit_inst' => ['any' => ['payments.save', 'dues.view', 'purchases.view'], 'price' => true],
    'cheque_inst' => ['any' => ['payments.save', 'dues.view', 'purchases.view'], 'price' => true],
    'receipts' => ['any' => ['receipts.save', 'dues.view', 'sales.view'], 'price' => true],
    'receipt_lump_tx' => ['any' => ['receipts.save', 'dues.view', 'sales.view'], 'price' => true],
    'receipt_credit_inst' => ['any' => ['receipts.save', 'dues.view', 'sales.view'], 'price' => true],
    'receipt_cheque_inst' => ['any' => ['receipts.save', 'dues.view', 'sales.view'], 'price' => true],
    'parties' => ['any' => ['parties.view', 'parties.create', 'sales.view', 'purchases.view'], 'price' => false],
    'loan_entries' => ['any' => ['dues.view', 'loans.create'], 'price' => true],
    'loan_dues' => ['any' => ['dues.view', 'loans.create'], 'price' => true],
    'employees' => ['any' => ['payroll.view', 'payroll.manage'], 'price' => true],
    'pay_items' => ['any' => ['payroll.view', 'payroll.manage'], 'price' => true],
    'payroll_runs' => ['any' => ['payroll.view', 'payroll.manage'], 'price' => true],
    'payroll_lines' => ['any' => ['payroll.view', 'payroll.manage'], 'price' => true],
    'payroll_line_items' => ['any' => ['payroll.view', 'payroll.manage'], 'price' => true],
    'employee_advances' => ['any' => ['payroll.view', 'payroll.manage'], 'price' => true],
    'payroll_params' => ['any' => ['payroll.view', 'payroll.manage'], 'price' => true],
    'payroll_line_stat' => ['any' => ['payroll.view', 'payroll.manage'], 'price' => true],
    'payroll_pay_lines' => ['any' => ['payroll.view', 'payroll.manage'], 'price' => true],
    'accounts' => ['any' => ['accounting.view'], 'price' => true],
    'account_roles' => ['any' => ['accounting.view'], 'price' => true],
    'tafsili' => ['any' => ['accounting.view'], 'price' => true],
    'vouchers' => ['any' => ['accounting.view'], 'price' => true],
    'voucher_lines' => ['any' => ['accounting.view'], 'price' => true],
    'fiscal_years' => ['any' => ['accounting.view', 'fy.manage'], 'price' => false],
    'orchards' => ['any' => ['orchards.view'], 'price' => false],
    'orchard_costs' => ['any' => ['orchards.view'], 'price' => true],
    'harvests' => ['any' => ['orchards.view'], 'price' => false],
    'orchard_allocations' => ['any' => ['orchards.view'], 'price' => true],
    'grade_prices' => ['any' => ['sales.view', 'purchases.view', 'intake.price'], 'price' => true],
    'doc_grade_lines' => ['any' => ['sales.view', 'purchases.view'], 'price' => true],
    // import_batches: عمداً نیست ⇒ برای کارکن خالی (مخصوص مالک)
];

/** ستونی که مقدار مالی دارد (توکن‌محور؛ مثلاً «ounce» و «total_weight» را نمی‌گیرد). */
function pb_sync_export_is_price_col(string $col): bool {
    if ($col === 'side_cost_payer') return false; // enum متنی (چه کسی هزینه را می‌دهد)، مبلغ نیست
    if (in_array($col, PB_PRICE_KEYS, true)) return true;
    return preg_match('/(^|_)(price|prices|cost|costs|usd|fx|amount|profit|margin|rate|discount|tax|labor|transport|credit)(_|$)/', $col) === 1;
}

/** مقدار پوشانده‌شده: عددی ⇒ 0 (سازگار با NOT NULL محلی)، غیر عددی ⇒ null. */
function pb_sync_export_mask($v) {
    if ($v === null) return null;
    return is_numeric($v) ? 0 : null;
}

function pb_sync_export_may_read(array $customer, array $rule): bool {
    if ((int)($customer['_staff_id'] ?? 0) <= 0) return true; // مالک
    if ($rule['price'] && !pb_staff_can($customer, 'price.view')) return false;
    if (!$rule['any']) return true;
    foreach ($rule['any'] as $k) if (pb_staff_can($customer, (string)$k)) return true;
    return false;
}

/** ردیف‌های یک جدول را با پوشاندن ستون‌های قیمت (در صورت نبودِ price.view) برمی‌گرداند. */
function pb_sync_export_rows(PDO $pdo, int $cid, string $table, array $cols, bool $maskPrice): array {
    $st = $pdo->prepare('SELECT ' . implode(',', $cols) . " FROM `$table` WHERE customer_id=?");
    $st->execute([$cid]);
    $rows = $st->fetchAll();
    if (!$maskPrice) return $rows;
    $priceCols = [];
    foreach ($cols as $c) if (pb_sync_export_is_price_col((string)$c)) $priceCols[] = (string)$c;
    if (!$priceCols) return $rows;
    foreach ($rows as &$r) foreach ($priceCols as $c) if (array_key_exists($c, $r)) $r[$c] = pb_sync_export_mask($r[$c]);
    unset($r);
    return $rows;
}

/** entityهای قابل‌دیدن برای این کارکن؛ همان قواعد entity.list (view + row_filter) و حذف ستون‌های قیمت بدون price.view. */
function pb_sync_export_entities(PDO $pdo, int $cid, array $customer, array $edition): array {
    $out = [];
    $canPrice = pb_staff_can($customer, 'price.view');
    foreach (pb_entity_registry() as $name => $def) {
        if (!empty($def['dev']) && !(defined('PB_ENTITY_DEV') && PB_ENTITY_DEV)) continue;
        if (!pb_entity_visible($def, $edition)) continue;
        $perms = $def['perms'] ?? [];
        if (isset($perms['view']) && !pb_perm_check($customer, $perms['view'])) continue;
        $t = pb_entity_table((string)$name);
        $cols = array_merge(['uid', 'rev', 'deleted_at', 'created_by', 'created_at', 'updated_at'], array_map('strval', array_keys($def['columns'])));
        if (!$canPrice) $cols = array_values(array_filter($cols, fn($c) => !pb_sync_export_is_price_col((string)$c)));
        $where = 'customer_id=?';
        $args = [$cid];
        if (!empty($def['row_filter']) && function_exists($def['row_filter'])) {
            $rf = ($def['row_filter'])($pdo, $customer);
            if (is_array($rf) && !empty($rf[0])) {
                $where .= ' AND (' . (string)$rf[0] . ')';
                foreach ((array)($rf[1] ?? []) as $ra) $args[] = $ra;
            }
        }
        $st = $pdo->prepare('SELECT `' . implode('`,`', $cols) . "` FROM `$t` WHERE $where");
        $st->execute($args);
        $out[(string)$name] = $st->fetchAll();
    }
    return $out;
}

/**
 * sync.export (بدون ورودی). خروجی هم‌شکل backup.export: {exported_at, phone, money_unit, tables, entities[, docs]}.
 * با out() تمام می‌شود. فقط خواندن.
 */
function pb_sync_export_action(PDO $pdo, array $customer, array $edition): void {
    $cid = (int)$customer['id'];
    pb_wh_default_uid($pdo, $cid); // مثل backup.export: لات‌های بدون انبار به انبار پیش‌فرض تعلق دارند
    $canPrice = pb_staff_can($customer, 'price.view');
    $out = ['exported_at' => date('c'), 'phone' => $customer['phone'], 'money_unit' => pb_money_unit($pdo, $cid), 'tables' => []];
    foreach (BACKUP_TABLES as $table => $cols) {
        $rule = PB_SYNC_EXPORT_TABLES[$table] ?? null;
        if ($rule === null || !pb_sync_export_may_read($customer, $rule)) { $out['tables'][$table] = []; continue; }
        $out['tables'][$table] = pb_sync_export_rows($pdo, $cid, (string)$table, pb_backup_export_cols($pdo, (string)$table, $cols), !$canPrice && (int)($customer['_staff_id'] ?? 0) > 0);
        if ((int)($customer['_staff_id'] ?? 0) > 0 && !(function_exists('pb_ws_can_cost') ? pb_ws_can_cost($customer) : (pb_staff_can($customer, 'price.view') && pb_staff_can($customer, 'workshop.cost'))) && in_array($table, ['proc_orders', 'proc_outputs'], true)) { // بهای کارگاه (v65) فقط با workshop.cost
            foreach ($out['tables'][$table] as &$wr) {
                if (!is_array($wr)) continue;
                if ($table === 'proc_orders') foreach (['price_per_kg', 'wage_per_kg', 'other_cost', 'price_deformed', 'price_discolored', 'price_hull', 'price_soil', 'price_kernel', 'value_in', 'cost_total', 'recovery_value', 'cost_sound', 'cost_per_kg_sound'] as $wc) { if (array_key_exists($wc, $wr)) $wr[$wc] = pb_sync_export_mask($wr[$wc]); }
                elseif (array_key_exists('grade', $wr) && $wr['grade'] !== null && array_key_exists('cost', $wr)) $wr['cost'] = pb_sync_export_mask($wr['cost']);
            }
            unset($wr);
        }
    }
    $ents = pb_sync_export_entities($pdo, $cid, $customer, $edition);
    if ($ents) $out['entities'] = $ents;
    // پیش‌فاکتورها: مالی ⇒ فقط با sales.view + price.view (یا مالک)
    $owner = (int)($customer['_staff_id'] ?? 0) <= 0;
    if ($owner || (pb_staff_can($customer, 'sales.view') && $canPrice)) {
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
            try { $docs['cons_terms'] = pb_cons_terms_export($pdo, $cid); } catch (Throwable $e) { /* بدون جدول امانی ⇒ اپ به p.price برمی‌گردد */ } // S36
            $out['docs'] = $docs;
        } catch (Throwable $e) { /* بخش اختیاری */ }
    }
    out($out);
}
