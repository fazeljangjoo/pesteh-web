<?php
declare(strict_types=1);
/**
 * S15.T15.3 — GOLD entities on the S00 framework: cost_centers, cash_boxes, bank_accounts, expenses.
 * - Each cost center / cash box / bank account gets a tafsili row (kind cost_center|cash|bank, ref_key = entity uid) via after_save.
 * - Merged into pb_entity_registry() (additive). Server gate = gold tier; perms: accounting.view / settings.manage.
 */
function pb_gold_entities(): array {
    $gate = ['tier' => 'gold', 'plan' => 'any'];
    $manage = ['view' => 'accounting.view', 'create' => 'settings.manage', 'edit' => 'settings.manage', 'delete' => 'settings.manage'];
    return [
        'cost_centers' => [
            'label' => 'مرکز هزینه',
            'columns' => [
                'code'   => ['type' => 'string', 'label' => 'کد', 'required' => true, 'max' => 20],
                'name'   => ['type' => 'string', 'label' => 'نام', 'required' => true, 'max' => 120],
                'parent' => ['type' => 'ref', 'ref' => 'cost_centers', 'label' => 'والد'],
                'active' => ['type' => 'bool', 'label' => 'فعال', 'default' => true],
            ],
            'perms' => $manage, 'gate' => $gate, 'search' => ['code', 'name'], 'order' => ['code', 'ASC'],
            'before_save' => 'pb_cc_before_save', 'after_save' => 'pb_cc_after_save', 'before_delete' => 'pb_cc_before_delete',
        ],
        'cash_boxes' => [
            'label' => 'صندوق',
            'columns' => [
                'name'   => ['type' => 'string', 'label' => 'نام صندوق', 'required' => true, 'max' => 100],
                'ccy'    => ['type' => 'string', 'label' => 'ارز', 'max' => 8, 'default' => 'IRR'], // S28.T28.1
                'opening_ccy_amount' => ['type' => 'decimal', 'label' => 'مانده اول دورهٔ ارزی', 'scale' => 4],
                'active' => ['type' => 'bool', 'label' => 'فعال', 'default' => true],
                'note'   => ['type' => 'string', 'label' => 'توضیح', 'max' => 250],
            ],
            'perms' => $manage, 'gate' => $gate, 'search' => ['name'], 'order' => ['id', 'ASC'],
            'before_save' => 'pb_treasury_ccy_before_save', 'after_save' => 'pb_cash_after_save', 'before_delete' => 'pb_treasury_before_delete',
        ],
        'bank_accounts' => [
            'label' => 'حساب بانکی',
            'columns' => [
                'name'            => ['type' => 'string', 'label' => 'عنوان حساب', 'required' => true, 'max' => 100],
                'bank_name'       => ['type' => 'string', 'label' => 'بانک', 'max' => 100],
                'branch'          => ['type' => 'string', 'label' => 'شعبه', 'max' => 100],
                'account_no'      => ['type' => 'string', 'label' => 'شمارهٔ حساب', 'max' => 40],
                'iban'            => ['type' => 'string', 'label' => 'شبا', 'max' => 34],
                'ccy'             => ['type' => 'string', 'label' => 'ارز', 'max' => 8, 'default' => 'IRR'],
                'opening_balance' => ['type' => 'bigint', 'label' => 'مانده اول دوره (ریال)', 'default' => 0],
                'opening_ccy_amount' => ['type' => 'decimal', 'label' => 'مانده اول دورهٔ ارزی', 'scale' => 4], // S28.T28.1
                'active'          => ['type' => 'bool', 'label' => 'فعال', 'default' => true],
            ],
            'perms' => $manage, 'gate' => $gate, 'search' => ['name', 'bank_name', 'account_no', 'iban'], 'order' => ['id', 'ASC'],
            'before_save' => 'pb_treasury_ccy_before_save', 'after_save' => 'pb_bank_after_save', 'before_delete' => 'pb_treasury_before_delete',
        ],
        'expenses' => [
            'label' => 'هزینهٔ عمومی',
            'columns' => [
                'date'         => ['type' => 'date', 'label' => 'تاریخ', 'required' => true],
                'amount'       => ['type' => 'bigint', 'label' => 'مبلغ', 'required' => true, 'vmin' => 1],
                'category'     => ['type' => 'string', 'label' => 'دسته', 'max' => 100],
                'account_code' => ['type' => 'string', 'label' => 'کد حساب هزینه', 'max' => 20],
                'cost_center'  => ['type' => 'ref', 'ref' => 'cost_centers', 'label' => 'مرکز هزینه'],
                'payer'        => ['type' => 'enum', 'label' => 'پرداخت از', 'values' => ['cash', 'bank', 'credit'], 'default' => 'cash'],
                'cash_box'     => ['type' => 'ref', 'ref' => 'cash_boxes', 'label' => 'صندوق'],
                'bank_account' => ['type' => 'ref', 'ref' => 'bank_accounts', 'label' => 'حساب بانکی'],
                'party'        => ['type' => 'string', 'label' => 'طرف‌حساب', 'max' => 150],
                'note'         => ['type' => 'string', 'label' => 'توضیح', 'max' => 500],
            ],
            'perms' => ['view' => 'accounting.view', 'create' => 'accounting.post', 'edit' => 'accounting.post', 'delete' => 'accounting.post'],
            'gate' => $gate, 'search' => ['category', 'party', 'note'], 'order' => ['date', 'DESC'],
            'before_save' => 'pb_expense_before_save', 'after_save' => 'pb_expense_after_save', 'before_delete' => 'pb_expense_before_delete',
        ],
        'owner_draws' => [ // S16.T16.4: owner draws (برداشت) and contributions (آورده); posts draws/capital vs cash/bank
            'label' => 'برداشت/آورده صاحب سرمایه',
            'columns' => [
                'date'         => ['type' => 'date', 'label' => 'تاریخ', 'required' => true],
                'amount'       => ['type' => 'bigint', 'label' => 'مبلغ', 'required' => true, 'vmin' => 1],
                'kind'         => ['type' => 'enum', 'label' => 'نوع', 'values' => ['draw', 'contribution'], 'default' => 'draw'],
                'payer'        => ['type' => 'enum', 'label' => 'از/به', 'values' => ['cash', 'bank'], 'default' => 'cash'],
                'cash_box'     => ['type' => 'ref', 'ref' => 'cash_boxes', 'label' => 'صندوق'],
                'bank_account' => ['type' => 'ref', 'ref' => 'bank_accounts', 'label' => 'حساب بانکی'],
                'note'         => ['type' => 'string', 'label' => 'توضیح', 'max' => 500],
            ],
            'perms' => ['view' => 'accounting.view', 'create' => 'accounting.post', 'edit' => 'accounting.post', 'delete' => 'accounting.post'],
            'gate' => $gate, 'search' => ['note'], 'order' => ['date', 'DESC'],
            'before_save' => 'pb_draw_before_save', 'after_save' => 'pb_draw_after_save', 'before_delete' => 'pb_draw_before_delete',
        ],
    ];
}

/**
 * S28.T28.1 — currency of a cash box / bank account. IRR (or empty) = rial account; any other code must be an active,
 * non-base currency of this tenant (FX plan). The currency cannot change once a voucher line uses the account.
 */
function pb_treasury_ccy_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $g = function (string $k) use ($vals, $existing) { return array_key_exists($k, $vals) ? $vals[$k] : ($existing[$k] ?? null); };
    $norm = function ($v): string { $c = strtoupper(trim((string)$v)); return ($c === '' || $c === 'IRT') ? 'IRR' : $c; };
    $ccy = $norm($g('ccy'));
    if ($isNew || array_key_exists('ccy', $vals)) $vals['ccy'] = $ccy;
    if ($ccy === 'IRR') {
        if (array_key_exists('opening_ccy_amount', $vals)) $vals['opening_ccy_amount'] = null;
    } else {
        require_once __DIR__ . '/fx_currencies.php';
        $cur = pb_fx_currency_by_code($pdo, $cid, $ccy);
        if (!$cur || (int)$cur['active'] !== 1 || (int)$cur['is_base'] === 1) bad('ارز حساب نامعتبر یا غیرفعال است');
    }
    if ($existing && $norm($existing['ccy'] ?? '') !== $ccy && !empty($existing['uid'])) {
        try {
            $st = $pdo->prepare("SELECT 1 FROM voucher_lines vl JOIN tafsili t ON t.id=vl.tafsili_id AND t.customer_id=vl.customer_id WHERE vl.customer_id=? AND t.kind IN ('cash','bank') AND t.ref_key=? LIMIT 1");
            $st->execute([$cid, (string)$existing['uid']]);
            if ($st->fetchColumn()) bad('ارز حساب بعد از ثبت سند قابل تغییر نیست');
        } catch (PDOException $e) { /* vouchers not created yet: nothing uses the account */ }
    }
}

function pb_cc_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $code = array_key_exists('code', $vals) ? (string)$vals['code'] : (string)($existing['code'] ?? '');
    $st = $pdo->prepare('SELECT 1 FROM ent_cost_centers WHERE customer_id=? AND code=? AND deleted_at IS NULL AND uid<>? LIMIT 1');
    $st->execute([$cid, $code, (string)($existing['uid'] ?? '')]);
    if ($st->fetchColumn()) bad('این کد مرکز هزینه قبلاً استفاده شده است');
    if ($existing && !empty($vals['parent']) && (string)$vals['parent'] === (string)$existing['uid']) bad('مرکز هزینه نمی‌تواند والد خودش باشد');
}

function pb_cc_after_save(PDO $pdo, int $cid, string $uid, bool $isNew, array $vals): void {
    $st = $pdo->prepare('SELECT code, name FROM ent_cost_centers WHERE customer_id=? AND uid=?');
    $st->execute([$cid, $uid]);
    $r = $st->fetch();
    if ($r) pb_taf_hook($pdo, $cid, 'cost_center', $uid, $r['code'] . ' - ' . $r['name']);
}

function pb_cash_after_save(PDO $pdo, int $cid, string $uid, bool $isNew, array $vals): void {
    $st = $pdo->prepare('SELECT name FROM ent_cash_boxes WHERE customer_id=? AND uid=?');
    $st->execute([$cid, $uid]);
    $n = $st->fetchColumn();
    if ($n !== false) pb_taf_hook($pdo, $cid, 'cash', $uid, 'صندوق ' . $n);
}

function pb_bank_after_save(PDO $pdo, int $cid, string $uid, bool $isNew, array $vals): void {
    $st = $pdo->prepare('SELECT name FROM ent_bank_accounts WHERE customer_id=? AND uid=?');
    $st->execute([$cid, $uid]);
    $n = $st->fetchColumn();
    if ($n !== false) pb_taf_hook($pdo, $cid, 'bank', $uid, 'بانک ' . $n);
}

/** Entities that expenses (and later vouchers) point to cannot be deleted while referenced. */
function pb_treasury_before_delete(PDO $pdo, int $cid, array $row): void {
    foreach (['cash_box' => 'ent_cash_boxes', 'bank_account' => 'ent_bank_accounts'] as $col => $tbl) {
        if (!isset($row['uid']) || $tbl === '') continue;
        $st = $pdo->prepare("SELECT 1 FROM ent_expenses WHERE customer_id=? AND `$col`=? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$cid, $row['uid']]);
        if ($st->fetchColumn()) bad('این مورد در هزینه‌ها استفاده شده است؛ به‌جای حذف، غیرفعالش کنید');
    }
}

function pb_cc_before_delete(PDO $pdo, int $cid, array $row): void {
    $st = $pdo->prepare('SELECT 1 FROM ent_cost_centers WHERE customer_id=? AND parent=? AND deleted_at IS NULL LIMIT 1');
    $st->execute([$cid, $row['uid']]);
    if ($st->fetchColumn()) bad('این مرکز هزینه زیرمجموعه دارد');
    $st = $pdo->prepare('SELECT 1 FROM ent_expenses WHERE customer_id=? AND cost_center=? AND deleted_at IS NULL LIMIT 1');
    $st->execute([$cid, $row['uid']]);
    if ($st->fetchColumn()) bad('این مرکز هزینه در هزینه‌ها استفاده شده است؛ به‌جای حذف، غیرفعالش کنید');
}

function pb_expense_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $g = function (string $k) use ($vals, $existing) { return array_key_exists($k, $vals) ? $vals[$k] : ($existing[$k] ?? null); };
    $payer = (string)($g('payer') ?? 'cash');
    if ($payer === 'cash' && empty($g('cash_box'))) bad('صندوق پرداخت‌کننده را انتخاب کنید');
    if ($payer === 'bank' && empty($g('bank_account'))) bad('حساب بانکی پرداخت‌کننده را انتخاب کنید');
    if ($payer === 'credit' && trim((string)($g('party') ?? '')) === '') bad('برای هزینهٔ نسیه، طرف‌حساب را وارد کنید');
}

function pb_draw_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $g = function (string $k) use ($vals, $existing) { return array_key_exists($k, $vals) ? $vals[$k] : ($existing[$k] ?? null); };
    $payer = (string)($g('payer') ?? 'cash');
    if ($payer === 'cash' && empty($g('cash_box'))) bad('صندوق را انتخاب کنید');
    if ($payer === 'bank' && empty($g('bank_account'))) bad('حساب بانکی را انتخاب کنید');
}

/* ---------------- S15.T15.3b: legacy treasury mapping + cost-center columns ---------------- */

/** [table => our-side text column]: payments pay FROM our account, receipts arrive TO our account. */
function pb_treasury_legacy_tables(): array {
    return ['lump_tx' => 'from_account', 'credit_inst' => 'from_account', 'cheque_inst' => 'from_account',
        'receipt_lump_tx' => 'to_account', 'receipt_credit_inst' => 'to_account', 'receipt_cheque_inst' => 'to_account'];
}

function pb_treasury_has_table(PDO $pdo, string $t): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
}

function pb_treasury_has_col(PDO $pdo, string $t, string $c): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $st->execute([$t, $c]);
    return (int)$st->fetchColumn() > 0;
}

/** Idempotent: nullable treasury_tafsili_id on the 6 legacy tables (original text kept) + cost_center_uid on expense-like tables. */
function pb_treasury_ensure_cols(PDO $pdo): void {
    foreach (array_keys(pb_treasury_legacy_tables()) as $t) {
        if (pb_treasury_has_table($pdo, $t) && !pb_treasury_has_col($pdo, $t, 'treasury_tafsili_id')) {
            $pdo->exec("ALTER TABLE `$t` ADD COLUMN treasury_tafsili_id INT UNSIGNED NULL");
        }
    }
    foreach (['orchard_costs', 'broker_payments', 'payroll_lines', 'employee_advances'] as $t) {
        if (pb_treasury_has_table($pdo, $t) && !pb_treasury_has_col($pdo, $t, 'cost_center_uid')) {
            $pdo->exec("ALTER TABLE `$t` ADD COLUMN cost_center_uid CHAR(36) NULL");
        }
    }
}

/** Finds/creates the cash box or bank account for a legacy text and returns its tafsili id. Empty text => default «صندوق اصلی». */
function pb_treasury_resolve(PDO $pdo, int $cid, string $text): int {
    $name = trim(preg_replace('/\s+/u', ' ', $text));
    if ($name === '') $name = 'صندوق اصلی';
    $name = mb_substr($name, 0, 100);
    $isCash = (bool)preg_match('/صندوق|نقد|تنخواه|cash/iu', $name);
    $tbl = $isCash ? 'ent_cash_boxes' : 'ent_bank_accounts';
    $st = $pdo->prepare("SELECT uid FROM `$tbl` WHERE customer_id=? AND name=? AND deleted_at IS NULL ORDER BY id LIMIT 1");
    $st->execute([$cid, $name]);
    $uid = $st->fetchColumn();
    if ($uid === false) {
        $uid = pb_uuid4();
        $rev = pb_entity_next_rev($pdo, $cid, $isCash ? 'cash_boxes' : 'bank_accounts');
        if ($isCash) {
            $pdo->prepare('INSERT INTO ent_cash_boxes (uid, customer_id, rev, created_by, name, active, note) VALUES (?,?,?,?,?,1,?)')
                ->execute([$uid, $cid, $rev, null, $name, 'ایجاد خودکار از پرداخت/دریافت‌های قبلی']);
        } else {
            $pdo->prepare('INSERT INTO ent_bank_accounts (uid, customer_id, rev, created_by, name, ccy, opening_balance, active) VALUES (?,?,?,?,?,?,0,1)')
                ->execute([$uid, $cid, $rev, null, $name, 'IRR']);
        }
    }
    return pb_taf_upsert($pdo, $cid, $isCash ? 'cash' : 'bank', (string)$uid, ($isCash ? 'صندوق ' : 'بانک ') . $name);
}

/**
 * Resolves every payment/receipt row of the customer to a treasury tafsili (idempotent; only rows still NULL).
 * $bestEffort=true (used from save paths): gold customers only and never throws.
 */
function pb_treasury_map_legacy(PDO $pdo, int $cid, bool $bestEffort = false): int {
    $n = 0;
    try {
        if ($bestEffort) {
            $st = $pdo->prepare('SELECT tier FROM customers WHERE id=?');
            $st->execute([$cid]);
            if ($st->fetchColumn() !== 'gold') return 0;
        }
        pb_acc_ensure($pdo);
        pb_ensure_entity_tables($pdo);
        pb_treasury_ensure_cols($pdo);
        foreach (pb_treasury_legacy_tables() as $t => $col) {
            if (!pb_treasury_has_table($pdo, $t)) continue;
            $q = $pdo->prepare("SELECT DISTINCT TRIM(COALESCE(`$col`, '')) FROM `$t` WHERE customer_id=? AND treasury_tafsili_id IS NULL");
            $q->execute([$cid]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $text) {
                $tid = pb_treasury_resolve($pdo, $cid, (string)$text);
                $u = $pdo->prepare("UPDATE `$t` SET treasury_tafsili_id=? WHERE customer_id=? AND treasury_tafsili_id IS NULL AND TRIM(COALESCE(`$col`, ''))=?");
                $u->execute([$tid, $cid, (string)$text]);
                $n += $u->rowCount();
            }
        }
    } catch (Throwable $e) {
        if (!$bestEffort) throw $e;
        error_log('[treasury_map] ' . $e->getMessage());
    }
    return $n;
}

/** Optional cost_center_uid on documents: null/'' => null; non-gold => ignored; otherwise must be a live cost center of this customer. */
function pb_cc_check(PDO $pdo, int $cid, $v): ?string {
    if ($v === null || $v === '') return null;
    $st = $pdo->prepare('SELECT tier FROM customers WHERE id=?');
    $st->execute([$cid]);
    if ($st->fetchColumn() !== 'gold') return null;
    if (!is_string($v) || !pb_is_uid($v)) bad('مرکز هزینه نامعتبر است');
    pb_ensure_entity_tables($pdo);
    $q = $pdo->prepare('SELECT 1 FROM ent_cost_centers WHERE customer_id=? AND uid=? AND deleted_at IS NULL');
    $q->execute([$cid, $v]);
    if (!$q->fetchColumn()) bad('مرکز هزینه پیدا نشد');
    return $v;
}
