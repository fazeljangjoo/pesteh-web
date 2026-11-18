<?php
declare(strict_types=1);
/**
 * S02.T02.1 — کارکنان (staff)، نقش‌ها و کاتالوگ مجوز.
 *
 * مدل:
 * - مالک (توکن با staff_id = NULL) همه‌کار می‌کند؛ مسیر مالک بدون تغییر است.
 * - کارکن با شمارهٔ مالک + نام کاربری + رمز وارد می‌شود (staff_login.php). توکنش در customer_tokens.staff_id ثبت می‌شود،
 *   پس کلاینت همان فرم قبلی (phone + token) را می‌فرستد.
 * - هر اکشن از pb_action_perm() مجوزش را می‌گیرد. اکشن ناشناخته یا مخصوص مالک = رد (deny by default).
 * - کارکن نمی‌تواند نقشی با مجوز بیشتر از خودش بدهد یا کارکنی با نقش بالاتر را ویرایش کند.
 */
require_once __DIR__ . '/auth_token.php';

/** کاتالوگ کلیدهای مجوز (module.action). کلیدهای ثبت‌شده در entities.php (warehouses.*, ounces.*) هم اینجا آمده‌اند. */
const PB_PERM_CATALOG = [
    'sales.view', 'sales.create', 'sales.update', 'sales.delete', 'pos.use',
    'purchases.view', 'purchases.create', 'purchases.delete', 'purchases.return', 'sales.return',
    'mixes.create', 'mixes.update', 'mixes.delete', 'separations.create', 'separations.update', 'separations.delete',
    'consignments.create', 'consignments.manage',
    'processing.create', 'processing.manage', // S09.T09.1
    'workshop.view', 'workshop.operate', 'workshop.manage', 'workshop.cost', 'workshop.crack', 'workshop.feed', // کارگاه داخلی: مشاهده / ثبت عملیات / حذف / دیدن و ثبت بها (مرحلهٔ ۲؛ نیازمند price.view)
    'orchards.view', 'orchards.manage', // S10.T10.1
    'payroll.view', 'payroll.manage', // S11.T11.1
    'payments.save', 'receipts.save',
    'dues.view', 'dues.mark_paid',
    'parties.view', 'parties.create',
    'crm.view', 'crm.edit',
    'loans.create', 'loans.delete', 'loans.due_paid',
    'catalog.manage',
    'stock.view', 'stock.transfer', 'stock.count', 'cartex.view', // cartex.view: فقط مشاهدهٔ «کارتکس لات» (نقش «کارتکس»)
    'warehouses.view', 'warehouses.manage',
    'ounces.view', 'ounces.manage',
    'reports.view', 'profit.view',
    'accounting.view', 'accounting.post', 'accounting.edit', 'accounting.close', 'fy.manage', 'tax.view', 'settings.manage',
    'accounting.fx_reval.apply', 'accounting.fx_reval.reverse', // تسعیر ارز: اعمال / ابطال (preview/list/detail/check = accounting.post)
    'users.manage', 'audit.view',
    // S1 (اتاق انس): پذیرش پسته توسط پرسنل اتاق انس؛ قطعی‌سازی و قیمت فقط مدیر/حسابدار
    'ounce.intake', 'ounce.view_own', 'ounce.view_all', 'ounce.edit_own', 'intake.confirm', 'intake.price', 'price.view',
];

/** نام نقش سیستمی اتاق انس (بدون هیچ دسترسی مالی). */
const PB_ROLE_OUNCE_ROOM = 'اتاق انس';

/** نام نقش سیستمی اپراتور کارگاه (بدون هیچ دسترسی مالی؛ مجوز price.view ندارد). رابط اپراتور = مرحلهٔ ۳. */
const PB_ROLE_WORKSHOP_OPERATOR = 'اپراتور کارگاه';

/** نام نقش سیستمی «کارتکس»: فقط «کارتکس لات» را می‌بیند (بدون هیچ مجوز مالی/خرید/فروش/موجودی). */
const PB_ROLE_CARTEX = 'کارتکس';

/**
 * کلیدهایی که در پاسخ سرور برای کارکنِ بدون «price.view» حذف می‌شوند (دفاع در عمق؛ لایهٔ اول deny-by-default در pb_action_perm است).
 * فقط نام فیلدهای مالی؛ ساختار پاسخ تغییر نمی‌کند (کلید حذف می‌شود).
 */
const PB_PRICE_KEYS = [
    'price', 'item_price', 'unit_price', 'total_price', 'sale_price', 'buy_price', 'purchase_price', 'base_price',
    'cost', 'unit_cost', 'total_cost', 'input_cost', 'labor_cost', 'transport_cost', 'profit', 'profit_amount', 'margin',
    'usd_price', 'usd_rate', 'avg_price', 'final_price', 'amount_due', 'total_amount',
    // کارگاه داخلی (مرحلهٔ ۲): ورودی‌ها و نتایج بها (ماسک sync_export هم از همین فهرست + regex می‌آید)
    'price_per_kg', 'wage_per_kg', 'other_cost', 'price_deformed', 'price_discolored', 'price_hull', 'price_soil', 'price_kernel',
    'value_in', 'cost_total', 'recovery_value', 'cost_sound', 'cost_per_kg_sound',
];

/** نقش‌های پیش‌فرض (هنگام اولین استفاده برای هر مشتری ساخته می‌شوند). «مالک» همهٔ کاتالوگ را دارد. */
function pb_default_roles(): array {
    return [
        'مالک' => PB_PERM_CATALOG,
        'حسابدار' => ['sales.view', 'purchases.view', 'dues.view', 'parties.view', 'crm.view', 'reports.view', 'profit.view',
            'payments.save', 'receipts.save', 'accounting.view', 'accounting.post', 'accounting.fx_reval.apply', 'accounting.fx_reval.reverse', 'accounting.edit', 'accounting.close', 'tax.view', 'stock.view', 'warehouses.view', 'ounces.view', 'audit.view', 'payroll.view', 'payroll.manage',
            'price.view', 'intake.confirm', 'intake.price', 'ounce.view_all', 'workshop.view', 'workshop.cost', 'workshop.feed'],
        'انباردار' => ['purchases.view', 'purchases.create', 'purchases.return', 'mixes.create', 'mixes.update', 'mixes.delete', 'separations.create', 'separations.update', 'separations.delete', 'consignments.create', 'consignments.manage',
            'stock.view', 'stock.transfer', 'stock.count', 'warehouses.view', 'ounces.view', 'catalog.manage', 'price.view', 'workshop.feed'],
        'فروشنده / صندوقدار' => ['sales.view', 'sales.create', 'pos.use', 'sales.return', 'parties.view', 'parties.create', 'dues.view',
            'receipts.save', 'stock.view', 'warehouses.view', 'crm.view', 'crm.edit', 'price.view', 'workshop.feed'],
        'فقط مشاهده' => ['sales.view', 'purchases.view', 'dues.view', 'parties.view', 'crm.view', 'reports.view', 'stock.view',
            'warehouses.view', 'ounces.view', 'price.view', 'workshop.feed'],
        // S1: پرسنل اتاق انس — فقط پذیرش؛ هیچ مجوز مالی/خرید/فروش/موجودی ندارد (و price.view ندارد)
        PB_ROLE_OUNCE_ROOM => ['ounce.intake', 'ounce.view_own', 'ounce.edit_own', 'ounces.view'],
        // کارگاه (مرحلهٔ ۱): اپراتور فقط ثبت/مشاهدهٔ عملیات کارگاه؛ بدون price.view و بدون هیچ مجوز مالی/خرید/فروش/موجودی
        PB_ROLE_WORKSHOP_OPERATOR => ['workshop.operate', 'workshop.view', 'workshop.crack', 'workshop.feed'],
        // «کارتکس»: فقط مشاهدهٔ کارتکس لات (فقط‌خواندنی، بدون قیمت)
        PB_ROLE_CARTEX => ['cartex.view'],
    ];
}

/**
 * نگاشت اکشن به کلید مجوز.
 * - رشتهٔ '' : هر کارکنِ فعال مجاز است (احراز کافی است؛ مثلاً bootstrap).
 * - کلید مجوز : همان کلید باید در نقش باشد.
 * - null : برای کارکن رد می‌شود (مخصوص مالک یا ناشناخته).
 * entity.* : مجوز هر موجودیت در registry (entities.php) با pb_perm_check بررسی می‌شود.
 */
function pb_action_perm(string $action): ?string {
    static $map = [
        'bootstrap' => '', 'account.status' => '', 'messages.list' => '', 'messages.read' => '',
        'categories.create' => 'catalog.manage', 'types.create' => 'catalog.manage',
        'purchases.list' => 'purchases.view', 'purchases.get' => 'purchases.view',
        'purchases.create' => 'purchases.create', 'purchases.delete' => 'purchases.delete',
        'purchase_returns.list' => 'purchases.view', 'purchase_returns.get' => 'purchases.view',
        'purchase_returns.create' => 'purchases.return', 'purchase_returns.delete' => 'purchases.return',
        'sale_returns.list' => 'sales.view', 'sale_returns.get' => 'sales.view', 'sale_returns.summary' => 'sales.view', 'sale_returns.profit_lines' => 'profit.view',
        'sale_returns.create' => 'sales.return', 'sale_returns.delete' => 'sales.return',
        'proformas.list' => 'sales.view', 'proformas.get' => 'sales.view',
        'proformas.save' => 'sales.create', 'proformas.set_status' => 'sales.create', 'proformas.delete' => 'sales.create', 'proformas.convert' => 'sales.create', 'proformas.link_sales' => 'sales.create',
        'broker.attach' => 'purchases.view', 'broker_rules.list' => 'purchases.view', 'broker_payments.list' => 'purchases.view', 'reports.broker_commission' => 'purchases.view',
        'broker_rules.save' => 'payments.save', 'broker_rules.delete' => 'payments.save', 'broker_payments.save' => 'payments.save', 'broker_payments.delete' => 'payments.save',
        'cheques.list' => 'dues.view', 'cheques.get' => 'dues.view', 'cheques.report' => 'dues.view', 'cheques.transition' => 'dues.mark_paid',
        'form_settings.get' => 'sales.view', 'form_settings.save' => 'settings.manage', // S35.T35.1
        'code_prefix.get' => 'sales.view', 'code_prefix.set' => 'settings.manage', // T1.3: حرف کد دسته‌ها
        'fin.block' => 'sales.view', 'pay.terms' => 'sales.view', 'pay.rate.save' => 'settings.manage', 'pay.credit_check' => 'sales.view', 'grade_prices.list' => 'sales.view', 'grade_prices.suggest' => 'sales.view', 'grade_prices.save' => 'settings.manage', 'grade_prices.bulk' => 'settings.manage', 'grade_prices.delete' => 'settings.manage', // S35.T35.4
        'fx.currencies.list' => 'sales.view', 'fx.currencies.save' => 'settings.manage', 'fx.currencies.deactivate' => 'settings.manage', 'fx.rates.list' => 'sales.view', 'fx.rates.get_at' => 'sales.view', 'fx.rates.save' => 'settings.manage', 'fx.rates.delete' => 'settings.manage', 'fx.adjust.list' => 'sales.view', 'fx.adjust.preview' => 'sales.view', 'fx.adjust.apply' => 'accounting.post', 'fx.rate_mismatch.list' => 'sales.view',
        'separations.list' => 'purchases.view', 'separations.get' => 'purchases.view', 'separations.lineage' => 'purchases.view', 'separations.settings' => 'purchases.view', 'reports.separation_yield' => 'purchases.view', 'lots.lineage' => 'purchases.view', 'lots.find_code' => 'purchases.view',
        'separations.create' => 'separations.create', 'separations.update' => 'separations.update', 'separations.delete' => 'separations.delete', 'separations.settings_save' => 'separations.update',
        'consignments.list' => 'stock.view', 'consignments.get' => 'stock.view', 'consignments.statement' => 'stock.view', 'reports.consigned_stock' => 'stock.view',
        'consignments.create' => 'consignments.create', 'consignments.delete' => 'consignments.manage', 'consignments.return' => 'consignments.manage', 'consignments.return_delete' => 'consignments.manage',
        'consignments.pay' => 'payments.save', 'consignments.pay_delete' => 'payments.save', 'consignments.sold' => 'sales.create',
        'processing.list' => 'stock.view', 'processing.get' => 'stock.view', 'processing.create' => 'processing.create',
        'processing.receipt_save' => 'processing.manage', 'workshop.outside_raw' => 'stock.view', 'processing.receive' => 'processing.manage', 'processing.deliver' => 'processing.manage', 'processing.delete' => 'processing.manage', 'processing.statement' => 'stock.view', 'reports.processing_summary' => 'stock.view', 'reports.processing_outstanding' => 'stock.view',
        'workshop.list' => 'workshop.view', 'workshop.get' => 'workshop.view', 'workshop.lots' => 'workshop.operate', 'workshop.create' => 'workshop.operate', 'workshop.delete' => 'workshop.manage', 'workshop.cost_save' => 'workshop.cost',
        'workshop.crack_list' => 'workshop.crack', 'workshop.crack_get' => 'workshop.crack', 'workshop.crack_create' => 'workshop.crack', 'workshop.crack_update' => 'workshop.crack', 'workshop.crack_delete' => 'workshop.manage', 'workshop.crack_pool_get' => 'profit.view', 'workshop.crack_pool_extra' => 'workshop.manage', 'workshop.crack_pool_close' => 'workshop.manage', 'workshop.crack_pool_reopen' => 'workshop.manage', 'reports.crack_profit' => 'profit.view', // CP2: استخر هزینه (داخل pb_cp_action: gold + profit.view؛ نوشتنی‌ها workshop.manage هم) — مرحلهٔ ۳: گزارش خط شکست (بدون قیمت)
        'reports.workshop_summary' => 'stock.view', 'reports.workshop_compare' => 'workshop.feed', 'reports.cartex' => 'workshop.feed', 'feed.stock' => 'workshop.feed', 'feed.adjust_list' => 'workshop.feed', 'feed.adjust_create' => 'workshop.manage', 'feed.adjust_delete' => 'workshop.manage', // مرحلهٔ ۵: موجودی پستهٔ خرجی (بی‌قیمت؛ workshop.feed = مشاهده)
        'orchards.list' => 'orchards.view', 'orchards.get' => 'orchards.view', 'reports.orchard_performance' => 'orchards.view', 'orchards.save' => 'orchards.manage', 'orchards.delete' => 'orchards.manage',
        'orchard_costs.add' => 'orchards.manage', 'orchard_costs.delete' => 'orchards.manage', 'harvests.create' => 'orchards.manage', 'orchards.allocate' => 'orchards.manage',
        'payroll.employees' => 'payroll.view', 'payroll.items' => 'payroll.view', 'payroll.runs' => 'payroll.view', 'payroll.run_get' => 'payroll.view',
        'payroll.employee_save' => 'payroll.manage', 'payroll.item_save' => 'payroll.manage', 'payroll.advance_add' => 'payroll.manage',
        'payroll.run_create' => 'payroll.manage', 'payroll.line_update' => 'payroll.manage', 'payroll.approve' => 'payroll.manage', 'payroll.run_delete' => 'payroll.manage',
        'payroll.pay_lines' => 'payroll.view', 'payroll.pay_unpaid' => 'payroll.view', 'payroll.statement' => 'payroll.view',
        'payroll.pay_line_add' => 'payroll.manage', 'payroll.pay_line_delete' => 'payroll.manage',
        'payroll.params_get' => 'payroll.view', 'payroll.payslip' => 'payroll.view', 'payroll.params_save' => 'payroll.manage',
        'mixes.create' => 'mixes.create', 'mixes.update' => 'mixes.update', 'mixes.delete' => 'mixes.delete',
        'payments.save' => 'payments.save', 'receipts.save' => 'receipts.save',
        'sales.list' => 'sales.view', 'sales.get' => 'sales.view', 'sales.create' => 'sales.create',
        'sales.update' => 'sales.update', 'sales.delete' => 'sales.delete',
        'dues.list' => 'dues.view', 'dues.mark_paid' => 'dues.mark_paid',
        'parties.list' => 'parties.view', 'parties.create' => 'parties.create', 'parties.save' => 'parties.create',
        'parties.statement' => 'parties.view', 'parties.aging' => 'parties.view', 'crm.dashboard' => 'crm.view',
        'loans.create' => 'loans.create', 'loans.delete' => 'loans.delete', 'loans.due_paid' => 'loans.due_paid',
        'reports.tx_all' => 'reports.view',
        'reports.stock_ledger' => 'stock.view', 'reports.stock_balance' => 'stock.view', 'reports.stock_mix' => 'stock.view',
        'stock_count.sheet' => 'stock.view', 'stock_count.list' => 'stock.view', 'stock_count.get' => 'stock.view', 'stock_count.save' => 'stock.count', 'stock_count.delete' => 'stock.count', 'stock_count.approve' => 'stock.count', 'stock_count.submit' => 'stock.count', 'stock_count.reopen' => 'stock.count', 'stock.reconcile' => 'stock.view', 'stock.transfer' => 'stock.transfer', 'stock.transfer_cancel' => 'stock.transfer',
        'stock.transfers' => 'stock.view', 'stock.by_warehouse' => 'stock.view', 'stock.lot_cartex' => 'cartex.view', 'stock.lot_cartex_settings' => 'cartex.view',
        'accounts.list' => 'accounting.view', 'accounts.roles' => 'accounting.view', 'accounts.save' => 'settings.manage', 'accounts.delete' => 'settings.manage', 'accounts.roles_save' => 'settings.manage',
        'tafsili.list' => 'accounting.view', 'tafsili.save' => 'settings.manage', 'tafsili.delete' => 'settings.manage', 'tafsili.sync' => 'settings.manage',
        'vouchers.list' => 'accounting.view', 'vouchers.get' => 'accounting.view', 'vouchers.save' => 'accounting.edit', 'vouchers.delete' => 'accounting.edit', 'vouchers.copy' => 'accounting.edit', 'vouchers.set_status' => 'accounting.post',
        'posting.preview' => 'accounting.view', 'posting.post' => 'accounting.post', 'posting.unpost' => 'accounting.post', 'tier.upgrade_preview' => 'settings.manage', 'tier.upgrade_apply' => 'settings.manage', 'tier.upgrade_rollback' => 'settings.manage', 'tier.upgrade_status' => 'settings.manage', // S20.T20.1 (handler is owner-only)
        'accounting.fx_reval.preview' => 'accounting.post', 'accounting.fx_reval.list' => 'accounting.post', 'accounting.fx_reval.detail' => 'accounting.post', 'accounting.fx_reval.check' => 'accounting.post', 'accounting.fx_reval.export' => 'accounting.post', 'accounting.fx_reval.docs' => 'accounting.post', // S30.T30.2 + fx-reval step 6
        'accounting.fx_reval.apply' => 'accounting.fx_reval.apply', 'accounting.fx_reval.reverse' => 'accounting.fx_reval.reverse', // کلیدهای جدا؛ نقش‌های دارای accounting.post با v74 هر دو را گرفتند
        'bank_recon.list' => 'accounting.view', 'bank_recon.report' => 'accounting.view', 'bank_recon.import' => 'accounting.post', 'bank_recon.auto' => 'accounting.post', 'bank_recon.match' => 'accounting.post', 'bank_recon.unmatch' => 'accounting.post', 'bank_recon.ignore' => 'accounting.post', // S31.T31.1
        'sale_export.get' => 'sales.view', 'sale_export.save' => 'sales.update', 'sale_export.docs' => 'sales.view',
        'backup_drill.run' => 'settings.manage', 'backup_drill.list' => 'settings.manage', 'backup_drill.status' => 'settings.manage', // S31.T31.15
        '2fa.status' => '', '2fa.setup' => '', '2fa.enable' => '', '2fa.disable' => '', '2fa.recovery_regen' => '', 'sessions.list' => '', 'sessions.revoke' => '', 'sessions.revoke_others' => '', // S31.T31.19 (owner check inside the handler)
        'error.report' => '', 'error.list' => 'settings.manage', 'error.summary' => 'settings.manage', 'error.resolve' => 'settings.manage', // S31.T31.18 (report = any logged-in user)
        'reminders.due' => 'dues.view', // S31.T31.14
        'kpi.dashboard' => 'reports.view', // S31.T31.13 (profit part additionally needs profit.view)
        'lc.list' => 'sales.view', 'lc.deadlines' => 'sales.view', 'lc.save' => 'sales.update', 'lc.set_status' => 'sales.update', 'lc.delete' => 'sales.update', // S31.T31.12
        // S31.T31.10
        'lot_trace.get' => 'purchases.view', 'lot_chain.get' => 'purchases.view', 'reports.orchard_flow' => 'orchards.view', // S31.T31.7
        'daily_recon.get' => 'accounting.view', // S31.T31.4
        'period_lock.get' => 'accounting.view', 'period_lock.close' => 'accounting.post', // S31.T31.2 (period_lock.reopen = owner only, absent on purpose)
        'accounting.rebuild' => 'accounting.post', 'accounting.integrity' => 'accounting.view', // S16.T16.6
        'reports.vat' => 'tax.view', 'vat.settle' => 'accounting.post', // S17.T17.2
        'reports.journal' => 'accounting.view', 'reports.ledger' => 'accounting.view', 'reports.tafsili' => 'accounting.view', // S18.T18.1
        'reports.trial' => 'accounting.view', 'reports.fx' => 'accounting.view', // S30.T30.4
         'reports.ebook' => 'accounting.view', 'treasury.balances' => 'accounting.view', // S18.T18.2, S28.T28.1
        'reports.income' => 'accounting.view', 'reports.balance' => 'accounting.view', 'reports.cc_pnl' => 'accounting.view', 'reports.instant' => 'accounting.view', 'reports.margin' => 'accounting.view', 'statements.map' => 'accounting.view', 'statements.map_save' => 'settings.manage', // S18.T18.3
        'fy.close_preview' => 'accounting.close', 'fy.close' => 'accounting.close', 'fy.lock' => 'accounting.close', 'fy.reopen' => 'accounting.close', 'fy.open_next' => 'accounting.close', 'fy.open_undo' => 'accounting.close', 'fiscal_years.list' => 'accounting.view', 'fiscal_years.save' => 'fy.manage', 'fiscal_years.create' => 'fy.manage', 'fiscal_years.set_current' => 'accounting.view',
        'import.commit' => '', // per-kind permission is checked inside pb_import_commit (S13.T13.2b)
        'entity.list' => '', 'entity.changes' => '', 'entity.save' => '', 'entity.delete' => '',
        'sync.version' => '', 'sync.changes' => '', // S4: فقط عدد/نام اکشن؛ داده‌ای برنمی‌گردانند
        'sync.export' => '', // S10: خروجی فقط‌خواندنیِ فیلترشده برای کارکن (مجوز هر جدول/entity و price.view داخل هندلر اعمال می‌شود)
        // S1 (اتاق انس) — اکشن‌ها در S2/S3 پیاده می‌شوند؛ نگاشت مجوز از همین‌جا ثبت شده تا deny-by-default مصون بماند
        // S2: CRUD پذیرش/درصدها از entity.* (لیست/ذخیره/حذف/تغییرات) انجام می‌شود؛ فقط این دو اکشن اختصاصی‌اند
        'intake.submit' => 'ounce.intake', 'intake.pct_defs.seed' => 'ounce.intake',
        'intake.preview_price' => 'intake.price', 'intake.confirm' => 'intake.confirm', 'intake.reject' => 'intake.confirm', 'intake.mark_sample' => 'intake.confirm', 'intake.price_settings' => 'intake.price',
        'staff.list' => 'users.manage', 'staff.save' => 'users.manage', 'staff.delete' => 'users.manage',
        'roles.list' => 'users.manage', 'roles.save' => 'users.manage', 'audit.list' => 'audit.view', 'audit.run' => 'audit.view', 'audit.verify' => 'audit.view',
        // مخصوص مالک (عمداً در نگاشت نیستند): account.set_name, account.set_password, account.reset_password, backup.*
    ];
    return array_key_exists($action, $map) ? $map[$action] : null;
}

/** مجوزها را تمیز می‌کند: فقط کلیدهای کاتالوگ، بدون تکرار. ورودی می‌تواند آرایه یا JSON باشد. */
function pb_perms_clean($raw): array {
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $k) {
        if (is_string($k) && in_array($k, PB_PERM_CATALOG, true)) $out[$k] = $k;
    }
    return array_values($out);
}

/** در زمان احراز: برای توکن کارکن، نقش و مجوزها را روی $customer می‌نشاند. مالک: _staff_id = 0 و _perms = همه. */
function pb_staff_attach(PDO $pdo, array &$customer): void {
    $sid = (int)($customer['_staff_id'] ?? 0);
    if ($sid <= 0) {
        $customer['_staff_id'] = 0;
        $customer['_perms'] = PB_PERM_CATALOG;
        return;
    }
    $st = $pdo->prepare('SELECT su.name, su.username, su.staff_code, su.allowed_fy_ids, r.perms, r.name AS role_name FROM staff_users su
        LEFT JOIN roles r ON r.id = su.role_id AND r.customer_id = su.customer_id
        WHERE su.id = ? AND su.customer_id = ? LIMIT 1');
    $st->execute([$sid, (int)$customer['id']]);
    $row = $st->fetch();
    $customer['_staff_name'] = is_array($row) ? (string)$row['name'] : '';
    $customer['_staff_username'] = is_array($row) ? (string)$row['username'] : '';
    $customer['_staff_code'] = is_array($row) ? (string)($row['staff_code'] ?? '') : ''; // S1: کد ۲ رقمی پرسنل برای کد پسته
    $customer['_staff_role'] = is_array($row) ? (string)($row['role_name'] ?? '') : '';
    $customer['_perms'] = is_array($row) ? pb_perms_clean($row['perms'] ?? '[]') : [];
    $customer['_allowed_fy'] = is_array($row) ? pb_fy_ids_clean($row['allowed_fy_ids'] ?? null) : []; // null = all years (S15.T15.4)
}

/** allowed_fy_ids column/payload -> null (no restriction) | int[] (restricted; [] = none). */
function pb_fy_ids_clean($raw): ?array {
    if ($raw === null || $raw === '') return null;
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) return null;
    $o = [];
    foreach ($raw as $v) if (is_numeric($v) && (int)$v > 0) $o[(int)$v] = (int)$v;
    return array_values($o);
}

/** آیا این مشتری (مالک یا کارکن) کلید مجوز را دارد؟ مالک همیشه بله. */
function pb_staff_can(array $customer, string $key): bool {
    if ((int)($customer['_staff_id'] ?? 0) <= 0) return true;
    return in_array($key, (array)($customer['_perms'] ?? []), true);
}

/** اطلاعات کارکن برای کلاینت (account.status). مالک = null. */
function pb_staff_public(array $customer): ?array {
    $sid = (int)($customer['_staff_id'] ?? 0);
    if ($sid <= 0) return null;
    return [
        'id' => $sid,
        'name' => (string)($customer['_staff_name'] ?? ''),
        'username' => (string)($customer['_staff_username'] ?? ''),
        'role' => (string)($customer['_staff_role'] ?? ''),
        'staff_code' => (string)($customer['_staff_code'] ?? ''),
        'perms' => array_values((array)($customer['_perms'] ?? [])),
    ];
}

/** توکن تازه برای کارکن (فقط هش ذخیره می‌شود). */
function pb_staff_issue(PDO $pdo, int $cid, int $staffId, ?string $device = null): string {
    $t = tok_new();
    $now = date('Y-m-d H:i:s');
    $dev = ($device !== null && $device !== '') ? substr($device, 0, 64) : null;
    $pdo->prepare('INSERT INTO customer_tokens (customer_id, token_hash, device, created_at, last_used_at, staff_id) VALUES (?,?,?,?,?,?)')
        ->execute([$cid, tok_hash($t), $dev, $now, $now, $staffId]);
    tok_cap($pdo, $cid, $staffId);
    return $t;
}

/** نقش‌های پیش‌فرض را یک‌بار برای مشتری می‌سازد (اگر هنوز نقشی ندارد). */
function pb_roles_seed(PDO $pdo, int $cid): void {
    $st = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE customer_id = ?');
    $st->execute([$cid]);
    if ((int)$st->fetchColumn() > 0) return;
    $ins = $pdo->prepare('INSERT INTO roles (customer_id, name, perms, is_system) VALUES (?,?,?,1)');
    foreach (pb_default_roles() as $name => $perms) {
        $ins->execute([$cid, (string)$name, json_encode(array_values($perms), JSON_UNESCAPED_UNICODE)]);
    }
}

/**
 * S1: نقش‌های سیستمی جدید را برای مشتری‌هایی که قبلاً نقش داشته‌اند هم می‌سازد («اتاق انس»). idempotent و سبک.
 * نقش‌های قبلی را تغییر نمی‌دهد (مهاجرت v62 یک‌بار price.view/intake را به آن‌ها می‌دهد).
 */
function pb_roles_ensure_system(PDO $pdo, int $cid): void {
    static $done = [];
    if (isset($done[$cid])) return;
    $done[$cid] = true;
    $def = pb_default_roles();
    $chk = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE customer_id = ? AND name = ?');
    $ins = $pdo->prepare('INSERT INTO roles (customer_id, name, perms, is_system) VALUES (?,?,?,1)');
    foreach ([PB_ROLE_OUNCE_ROOM, PB_ROLE_WORKSHOP_OPERATOR, PB_ROLE_CARTEX] as $name) { // مرحلهٔ ۱ کارگاه: «اپراتور کارگاه» هم همین‌طور
        $chk->execute([$cid, $name]);
        if ((int)$chk->fetchColumn() > 0) continue;
        $ins->execute([$cid, $name, json_encode(array_values($def[$name]), JSON_UNESCAPED_UNICODE)]);
    }
}

/**
 * S1 (امنیت سطح فیلد): از پاسخ، کلیدهای مالی (PB_PRICE_KEYS) را برای کارکنِ بدون «price.view» حذف می‌کند.
 * مالک و کارکنِ دارای price.view دست‌نخورده‌اند. بازگشتی روی آرایه‌های تودرتو.
 */
function pb_strip_price_fields($data, array $customer) {
    if ((int)($customer['_staff_id'] ?? 0) <= 0) return $data;     // مالک
    if (pb_staff_can($customer, 'price.view')) return $data;
    return pb_strip_price_walk($data);
}

function pb_strip_price_walk($v) {
    if (!is_array($v)) return $v;
    $out = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && in_array($k, PB_PRICE_KEYS, true)) continue;
        $out[$k] = is_array($x) ? pb_strip_price_walk($x) : $x;
    }
    return $out;
}

function pb_role_row(PDO $pdo, int $cid, int $roleId): ?array {
    $st = $pdo->prepare('SELECT id, name, perms, is_system FROM roles WHERE id = ? AND customer_id = ? LIMIT 1');
    $st->execute([$roleId, $cid]);
    $r = $st->fetch();
    return is_array($r) ? $r : null;
}

/** Actions: staff.list|save|delete, roles.list|save. مجوز users.manage روی همین اکشن‌ها را pb_action_perm کنترل کرده است. */
function pb_staff_action(string $action, array $payload, PDO $pdo, array $customer, array $edition): void {
    $cid = (int)$customer['id'];
    pb_roles_seed($pdo, $cid);
    pb_roles_ensure_system($pdo, $cid); // S1
    $owner = (int)($customer['_staff_id'] ?? 0) <= 0;
    $actor = $owner ? PB_PERM_CATALOG : (array)($customer['_perms'] ?? []);

    switch ($action) {
        case 'roles.list': {
            $st = $pdo->prepare('SELECT id, name, perms, is_system FROM roles WHERE customer_id = ? ORDER BY id');
            $st->execute([$cid]);
            $items = [];
            foreach ($st->fetchAll() as $r) {
                $items[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'perms' => pb_perms_clean($r['perms']),
                            'is_system' => (int)$r['is_system'] === 1];
            }
            out(['items' => $items, 'catalog' => PB_PERM_CATALOG]);
        }

        case 'roles.save': {
            $id = (int)($payload['id'] ?? 0);
            $name = trim((string)($payload['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) bad('نام نقش را وارد کنید');
            $raw = is_array($payload['perms'] ?? null) ? $payload['perms'] : [];
            $unknown = array_diff(array_filter($raw, 'is_string'), PB_PERM_CATALOG);
            if ($unknown) bad('کلید مجوز نامعتبر: ' . implode(', ', $unknown));
            $perms = pb_perms_clean($raw);
            if (!$owner && array_diff($perms, $actor)) out(['error' => 'forbidden', 'message' => 'نقشی با مجوزهای بیشتر از خودتان نمی‌توانید بسازید', 'need' => 'users.manage'], 403);
            $json = json_encode($perms, JSON_UNESCAPED_UNICODE);
            if ($id > 0) {
                $cur = pb_role_row($pdo, $cid, $id);
                if (!$cur) bad('نقش یافت نشد', 404);
                if (!$owner && array_diff(pb_perms_clean($cur['perms']), $actor)) out(['error' => 'forbidden', 'message' => 'دسترسی لازم را ندارید'], 403);
                $pdo->prepare('UPDATE roles SET name = ?, perms = ? WHERE id = ? AND customer_id = ?')->execute([$name, $json, $id, $cid]);
            } else {
                $pdo->prepare('INSERT INTO roles (customer_id, name, perms, is_system) VALUES (?,?,?,0)')->execute([$cid, $name, $json]);
                $id = (int)$pdo->lastInsertId();
            }
            out(['ok' => true, 'id' => $id]);
        }

        case 'staff.list': {
            $st = $pdo->prepare('SELECT su.id, su.username, su.name, su.staff_code, su.role_id, su.active, su.last_login, su.allowed_fy_ids, r.name AS role_name
                FROM staff_users su LEFT JOIN roles r ON r.id = su.role_id AND r.customer_id = su.customer_id
                WHERE su.customer_id = ? ORDER BY su.id');
            $st->execute([$cid]);
            $items = [];
            foreach ($st->fetchAll() as $r) {
                $items[] = ['id' => (int)$r['id'], 'username' => (string)$r['username'], 'name' => (string)$r['name'],
                            'staff_code' => (string)($r['staff_code'] ?? ''), 'role_id' => (int)$r['role_id'], 'role_name' => (string)($r['role_name'] ?? ''),
                            'active' => (int)$r['active'] === 1, 'last_login' => $r['last_login'],
                            'allowed_fy_ids' => pb_fy_ids_clean($r['allowed_fy_ids'] ?? null)];
            }
            $cap = $edition['limits']['staff_users'] ?? null;
            out(['items' => $items, 'limit' => $cap]);
        }

        case 'staff.save': {
            $id = (int)($payload['id'] ?? 0);
            $username = trim((string)($payload['username'] ?? ''));
            $name = trim((string)($payload['name'] ?? ''));
            $roleId = (int)($payload['role_id'] ?? 0);
            $active = array_key_exists('active', $payload) ? ((bool)$payload['active'] ? 1 : 0) : 1;
            $password = (string)($payload['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) bad('نام کاربری فقط حروف انگلیسی، عدد و . _ - است (۳ تا ۶۰ نویسه)');
            if ($name === '' || mb_strlen($name) > 120) bad('نام کارکن را وارد کنید');
            if ($password !== '' && (strlen($password) < 6 || strlen($password) > 200)) bad('رمز کارکن باید بین ۶ تا ۲۰۰ نویسه باشد');
            $role = pb_role_row($pdo, $cid, $roleId);
            if (!$role) bad('نقش نامعتبر است');
            if (!$owner && array_diff(pb_perms_clean($role['perms']), $actor)) out(['error' => 'forbidden', 'message' => 'نقشی با مجوزهای بیشتر از خودتان نمی‌توانید بدهید', 'need' => 'users.manage'], 403);

            if ($id > 0) {
                $st = $pdo->prepare('SELECT su.*, r.perms AS role_perms FROM staff_users su LEFT JOIN roles r ON r.id = su.role_id AND r.customer_id = su.customer_id WHERE su.id = ? AND su.customer_id = ?');
                $st->execute([$id, $cid]);
                $cur = $st->fetch();
                if (!is_array($cur)) bad('کارکن یافت نشد', 404);
                if (!$owner && array_diff(pb_perms_clean($cur['role_perms'] ?? '[]'), $actor)) out(['error' => 'forbidden', 'message' => 'دسترسی لازم را ندارید'], 403);
                try {
                    $pdo->prepare('UPDATE staff_users SET username = ?, name = ?, role_id = ?, active = ? WHERE id = ? AND customer_id = ?')
                        ->execute([$username, $name, $roleId, $active, $id, $cid]);
                } catch (PDOException $e) {
                    if ((string)$e->getCode() === '23000') bad('این نام کاربری قبلاً ثبت شده است');
                    throw $e;
                }
                if ($password !== '') {
                    $pdo->prepare('UPDATE staff_users SET pass_hash = ? WHERE id = ? AND customer_id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $id, $cid]);
                }
                // رمز عوض شد یا کارکن غیرفعال شد: نشست‌های همین کارکن باطل می‌شوند
                if ($password !== '' || $active === 0) {
                    $pdo->prepare('DELETE FROM customer_tokens WHERE staff_id = ? AND customer_id = ?')->execute([$id, $cid]);
                }
            } else {
                if ($password === '') bad('رمز اولیهٔ کارکن را وارد کنید');
                $cap = $edition['limits']['staff_users'] ?? null;
                if ($cap !== null) {
                    $cnt = $pdo->prepare('SELECT COUNT(*) FROM staff_users WHERE customer_id = ? AND active = 1');
                    $cnt->execute([$cid]);
                    if ((int)$cnt->fetchColumn() >= (int)$cap) {
                        out(['error' => 'staff_limit_reached', 'message' => 'سقف تعداد کارکنان این حساب پر شده است', 'limit' => (int)$cap], 403);
                    }
                }
                try {
                    $pdo->prepare('INSERT INTO staff_users (customer_id, username, name, pass_hash, role_id, active) VALUES (?,?,?,?,?,?)')
                        ->execute([$cid, $username, $name, password_hash($password, PASSWORD_DEFAULT), $roleId, $active]);
                    $id = (int)$pdo->lastInsertId();
                } catch (PDOException $e) {
                    if ((string)$e->getCode() === '23000') bad('این نام کاربری قبلاً ثبت شده است');
                    throw $e;
                }
                require_once __DIR__ . '/lot_code.php'; // S1: کد ۲ رقمی پرسنل (برای کد پسته)
                try { pb_staff_code_assign($pdo, $cid, $id); } catch (Throwable $e) { error_log('staff_code: ' . $e->getMessage()); }
            }
            if (array_key_exists('allowed_fy_ids', $payload)) { // S15.T15.4: null = all years; list = only those years
                $ids = pb_fy_ids_clean($payload['allowed_fy_ids']);
                if ($ids !== null && $ids) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM fiscal_years WHERE customer_id=? AND id IN ($ph)");
                    $chk->execute(array_merge([$cid], $ids));
                    if ((int)$chk->fetchColumn() !== count($ids)) bad('سال مالی نامعتبر است');
                }
                $pdo->prepare('UPDATE staff_users SET allowed_fy_ids=? WHERE id=? AND customer_id=?')->execute([$ids === null ? null : json_encode($ids), $id, $cid]);
            }
            out(['ok' => true, 'id' => $id]);
        }

        case 'staff.delete': {
            $id = (int)($payload['id'] ?? 0);
            $st = $pdo->prepare('SELECT su.id, r.perms AS role_perms FROM staff_users su LEFT JOIN roles r ON r.id = su.role_id AND r.customer_id = su.customer_id WHERE su.id = ? AND su.customer_id = ?');
            $st->execute([$id, $cid]);
            $cur = $st->fetch();
            if (!is_array($cur)) bad('کارکن یافت نشد', 404);
            if (!$owner && array_diff(pb_perms_clean($cur['role_perms'] ?? '[]'), $actor)) out(['error' => 'forbidden', 'message' => 'دسترسی لازم را ندارید'], 403);
            $pdo->prepare('DELETE FROM customer_tokens WHERE staff_id = ? AND customer_id = ?')->execute([$id, $cid]);
            $pdo->prepare('DELETE FROM staff_users WHERE id = ? AND customer_id = ?')->execute([$id, $cid]);
            out(['ok' => true]);
        }
    }
    bad('اکشن نامعتبر است', 404);
}
