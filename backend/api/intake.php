<?php
declare(strict_types=1);
require_once __DIR__ . '/code_kind.php'; // T1: حرف کد پسته
/**
 * S2 (ماژول اتاق انس) — API پذیرش.
 *
 * سه entity روی چارچوب entities.php (آفلاین‑اول، uid، rev، حذف نرم، دلتا با entity.changes):
 *   lot_intakes     پذیرش هر پسته در اتاق انس (تا قطعی‌شدن هیچ اثری روی purchases/موجودی ندارد)
 *   lot_intake_pcts درصدهای هر پذیرش (توچین، دهن‌بست، …)
 *   pct_defs        تعریف درصدها (قابل‌گسترش)
 * CRUD کامل از طریق entity.list|save|delete|changes؛ فقط دو اکشن اختصاصی:
 *   intake.submit          تخصیص کد پسته (سمت سرور، اتمیک) + تغییر وضعیت draft → submitted | sample_only
 *   intake.pct_defs.seed   ساخت چهار تعریف پیش‌فرض (idempotent؛ در entity.list/changes ی pct_defs هم خودکار اجرا می‌شود)
 *
 * قواعد:
 *  - ستون‌های «مال سرور» (status, code, purchase_id, created_by_*, confirmed_by_*, confirmed_at, submitted_at, reject_reason)
 *    را کلاینت از طریق entity.save نمی‌تواند بنویسد؛ hook آن‌ها را دور می‌ریزد.
 *  - پرسنل بدون ounce.view_all فقط پذیرش‌های خودش را می‌بیند/ویرایش می‌کند (row_filter + hook).
 *  - بعد از confirmed / rejected / sample_only هیچ ویرایش یا حذفی ممکن نیست (برای همه، حتی مالک).
 *  - حذف فقط در وضعیت draft (بعد از ثبت، راه کنار گذاشتن = «رد» در S3).
 *  - ممیزی: entity='intake' (پذیرش و درصدهایش با entity_id = uid پذیرش) و entity='pct_def'.
 *  - این مرحله هیچ نوشتنی در purchases/stock ندارد.
 */
require_once __DIR__ . '/lot_code.php';
require_once __DIR__ . '/pricing_engine.php'; // S3

/** وضعیت‌هایی که دیگر قابل ویرایش نیستند. */
const PB_INTAKE_LOCKED = ['confirmed', 'rejected', 'sample_only'];
/** ستون‌هایی که فقط سرور می‌نویسد. */
const PB_INTAKE_SERVER_COLS = ['status', 'code', 'purchase_id', 'created_by_staff_id', 'created_by_name',
    'confirmed_by_staff_id', 'confirmed_by_name', 'confirmed_at', 'submitted_at', 'reject_reason'];
/** چهار درصد اصلی (هم‌نام ستون‌های purchases؛ قابل حذف نیستند). key => [label, sort] */
const PB_INTAKE_CORE_DEFS = ['tuchin' => ['توچین', 10], 'dombest' => ['دهن‌بست', 20], 'crooked' => ['کج', 30], 'khandan' => ['خندان', 40]];

/** رجیستری entityها (در entities.php با pb_entity_registry ادغام می‌شود). */
function pb_intake_entities(): array {
    return [
        'lot_intakes' => [
            'label' => 'پذیرش اتاق انس',
            'columns' => [
                'category_id'  => ['type' => 'int', 'label' => 'دسته', 'vmin' => 1],
                'type_name'    => ['type' => 'string', 'label' => 'نوع', 'max' => 120],
                'gross_kg'     => ['type' => 'decimal', 'label' => 'وزن کل (کیلو)', 'scale' => 3, 'vmin' => 0, 'vmax' => 100000000],
                'small_sacks'  => ['type' => 'int', 'label' => 'گونی کوچک', 'vmin' => 0, 'vmax' => 1000000],
                'large_sacks'  => ['type' => 'int', 'label' => 'گونی بزرگ', 'vmin' => 0, 'vmax' => 1000000],
                'ounce'        => ['type' => 'decimal', 'label' => 'انس', 'scale' => 3, 'vmin' => 0, 'vmax' => 100000],
                'gram'         => ['type' => 'decimal', 'label' => 'گرم', 'scale' => 3, 'vmin' => 0, 'vmax' => 100],
                'owner_name'   => ['type' => 'string', 'label' => 'نام صاحب پسته', 'max' => 150],
                'is_sample'    => ['type' => 'bool', 'label' => 'نمونه', 'default' => false],
                'status'       => ['type' => 'enum', 'label' => 'وضعیت', 'values' => ['draft', 'submitted', 'returned', 'confirmed', 'rejected', 'sample_only'], 'default' => 'draft'],
                'code'         => ['type' => 'string', 'label' => 'کد پسته', 'max' => 40],
                'note'         => ['type' => 'string', 'label' => 'یادداشت', 'max' => 500],
                'purchase_id'  => ['type' => 'bigint', 'label' => 'شناسهٔ خرید', 'vmin' => 1],
                'created_by_staff_id'   => ['type' => 'int', 'label' => 'ثبت‌کننده', 'vmin' => 0],
                'created_by_name'       => ['type' => 'string', 'label' => 'نام ثبت‌کننده', 'max' => 120],
                'confirmed_by_staff_id' => ['type' => 'int', 'label' => 'قطعی‌کننده', 'vmin' => 0],
                'confirmed_by_name'     => ['type' => 'string', 'label' => 'نام قطعی‌کننده', 'max' => 120],
                'confirmed_at' => ['type' => 'datetime', 'label' => 'زمان قطعی‌سازی'],
                'submitted_at' => ['type' => 'datetime', 'label' => 'زمان ثبت'],
                'reject_reason' => ['type' => 'string', 'label' => 'دلیل رد', 'max' => 250],
            ],
            'perms' => ['view' => ['ounce.view_own', 'ounce.view_all'], 'create' => 'ounce.intake', 'edit' => 'ounce.edit_own', 'delete' => 'ounce.edit_own'],
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['code', 'owner_name', 'type_name', 'note'],
            'order' => ['id', 'DESC'],
            'before_save' => 'pb_intake_before_save',
            'after_save' => 'pb_intake_after_save',
            'before_delete' => 'pb_intake_before_delete',
            'row_filter' => 'pb_intake_row_filter',
            'audit_custom' => true,
        ],
        'lot_intake_pcts' => [
            'label' => 'درصد پذیرش اتاق انس',
            'columns' => [
                'intake_id' => ['type' => 'ref', 'label' => 'پذیرش', 'ref' => 'lot_intakes', 'required' => true],
                'def_key'   => ['type' => 'string', 'label' => 'نوع درصد', 'required' => true, 'max' => 32],
                'pct'       => ['type' => 'decimal', 'label' => 'درصد', 'scale' => 3, 'required' => true, 'vmin' => 0, 'vmax' => 100],
                'kernel_g'  => ['type' => 'decimal', 'label' => 'گرم مغز در ۱۰۰ گرم', 'scale' => 3, 'vmin' => 0, 'vmax' => 100],
            ],
            'perms' => ['view' => ['ounce.view_own', 'ounce.view_all'], 'create' => 'ounce.intake', 'edit' => 'ounce.edit_own', 'delete' => 'ounce.edit_own'],
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['def_key'],
            'order' => ['id', 'ASC'],
            'before_save' => 'pb_intake_pct_before_save',
            'after_save' => 'pb_intake_pct_after_save',
            'before_delete' => 'pb_intake_pct_before_delete',
            'row_filter' => 'pb_intake_pct_row_filter',
            'audit_custom' => true,
        ],
        'pct_defs' => [
            'label' => 'تعریف درصدهای پذیرش',
            'columns' => [
                'key'             => ['type' => 'string', 'label' => 'کلید', 'max' => 32],
                'label'           => ['type' => 'string', 'label' => 'عنوان', 'required' => true, 'max' => 80],
                'active'          => ['type' => 'bool', 'label' => 'فعال', 'default' => true],
                'sort'            => ['type' => 'int', 'label' => 'ترتیب', 'vmin' => 0, 'vmax' => 100000],
                'pricing_allowed' => ['type' => 'bool', 'label' => 'قابل قیمت‌گذاری', 'default' => false],
            ],
            'perms' => ['view' => ['ounce.view_own', 'ounce.view_all', 'intake.price'], 'create' => 'ounce.intake', 'edit' => 'intake.confirm', 'delete' => 'intake.confirm'],
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['label', 'key'],
            'order' => ['sort', 'ASC'],
            'before_save' => 'pb_pctdef_before_save',
            'after_save' => 'pb_pctdef_after_save',
            'before_delete' => 'pb_pctdef_before_delete',
            'audit_custom' => true,
        ],
    ];
}

/* ---------------- کمک‌کننده‌ها ---------------- */

/** مشتری/کارکنِ درخواست جاری (در pb_entity_action ست می‌شود). بدون آن، هیچ hookی کار نمی‌کند. */
function pb_intake_cust(): array {
    $c = $GLOBALS['PB_ENTITY_CUSTOMER'] ?? null;
    if (!is_array($c) || !isset($c['id'])) bad('خطای داخلی: هویت درخواست مشخص نیست', 500);
    return $c;
}

/** [staffId, staffName, seesAll]. مالک: [0, 'مالک', true]. */
function pb_intake_actor(array $c): array {
    $sid = (int)($c['_staff_id'] ?? 0);
    if ($sid <= 0) return [0, 'مالک', true];
    return [$sid, mb_substr((string)($c['_staff_name'] ?? ''), 0, 120), pb_staff_can($c, 'ounce.view_all')];
}

/** آیا این پذیرش (ردیف DB) در دسترس این فرد است؟ */
function pb_intake_owns(array $row, array $c): bool {
    [$sid, , $all] = pb_intake_actor($c);
    if ($all) return true;
    return (int)($row['created_by_staff_id'] ?? -1) === $sid;
}

function pb_intake_require_unlocked(array $row): void {
    if (in_array((string)($row['status'] ?? ''), PB_INTAKE_LOCKED, true)) {
        bad('این پذیرش قطعی، رد یا نمونه شده و دیگر قابل تغییر نیست', 409);
    }
}

/** فیلد حساس حذف‌شده از before/after ممیزی. */
function pb_intake_audit_row(?array $r): ?array {
    if ($r === null) return null;
    $keep = ['uid', 'category_id', 'type_name', 'gross_kg', 'small_sacks', 'large_sacks', 'ounce', 'gram', 'owner_name', 'is_sample',
             'status', 'code', 'note', 'purchase_id', 'created_by_staff_id', 'created_by_name', 'def_key', 'pct', 'kernel_g', 'intake_id'];
    $o = [];
    foreach ($keep as $k) { if (array_key_exists($k, $r) && $r[$k] !== null) $o[$k] = $r[$k]; }
    return $o;
}

/** ایندکس یکتای (customer_id, code) روی جدول پذیرش؛ یک‌بار، خارج از تراکنش (DDL). */
function pb_intake_ensure_indexes(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $st = $pdo->query("SHOW INDEX FROM `ent_lot_intakes` WHERE Key_name = 'uq_cust_code'");
        if (!$st->fetch()) $pdo->exec('ALTER TABLE `ent_lot_intakes` ADD UNIQUE KEY `uq_cust_code` (`customer_id`, `code`)');
        $st = $pdo->query("SHOW INDEX FROM `ent_lot_intakes` WHERE Key_name = 'idx_cust_creator'");
        if (!$st->fetch()) $pdo->exec('ALTER TABLE `ent_lot_intakes` ADD KEY `idx_cust_creator` (`customer_id`, `created_by_staff_id`, `status`)');
        $st = $pdo->query("SHOW INDEX FROM `ent_lot_intake_pcts` WHERE Key_name = 'idx_cust_intake'");
        if (!$st->fetch()) $pdo->exec('ALTER TABLE `ent_lot_intake_pcts` ADD KEY `idx_cust_intake` (`customer_id`, `intake_id`)');
    } catch (Throwable $e) {
        error_log('[intake] ensure_indexes: ' . $e->getMessage());
    }
    $done = true;
}

/* ---------------- row filters ---------------- */

function pb_intake_row_filter(PDO $pdo, array $customer): ?array {
    [$sid, , $all] = pb_intake_actor($customer);
    if ($all) return null;
    return ['created_by_staff_id = ?', [$sid]];
}

function pb_intake_pct_row_filter(PDO $pdo, array $customer): ?array {
    [$sid, , $all] = pb_intake_actor($customer);
    if ($all) return null;
    return ['intake_id IN (SELECT uid FROM ent_lot_intakes WHERE customer_id = ? AND created_by_staff_id = ?)', [(int)$customer['id'], $sid]];
}

/* ---------------- hooks: lot_intakes ---------------- */

function pb_intake_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $c = pb_intake_cust();
    [$sid, $sname] = pb_intake_actor($c);
    foreach (PB_INTAKE_SERVER_COLS as $k) unset($vals[$k]); // کلاینت نمی‌تواند وضعیت/کد/قطعی‌سازی را بنویسد
    if ($isNew) {
        $vals['status'] = 'draft';
        $vals['created_by_staff_id'] = $sid;
        $vals['created_by_name'] = $sname;
    } else {
        if (!pb_intake_owns($existing, $c)) bad('این پذیرش متعلق به شما نیست', 403);
        pb_intake_require_unlocked($existing);
        if ((string)$existing['status'] !== 'draft' && array_key_exists('is_sample', $vals) && (int)$vals['is_sample'] !== (int)$existing['is_sample']) {
            bad('نمونه‌بودن پس از ثبت نهایی قابل تغییر نیست');
        }
    }
    if (array_key_exists('gram', $vals) && $vals['gram'] !== null && ((float)$vals['gram'] <= 0 || (float)$vals['gram'] > 100)) {
        bad('«گرم» باید بیشتر از ۰ و حداکثر ۱۰۰ باشد');
    }
    $GLOBALS['PB_INTAKE_BEFORE'] = $existing === null ? null : pb_intake_audit_row($existing);
}

function pb_intake_after_save(PDO $pdo, int $cid, string $uid, bool $isNew, array $vals): void {
    $c = pb_intake_cust();
    pb_audit($pdo, $c, $isNew ? 'create' : 'update', 'intake', $uid,
        $isNew ? 'ایجاد پیش‌نویس پذیرش اتاق انس' : 'ویرایش پذیرش اتاق انس',
        $GLOBALS['PB_INTAKE_BEFORE'] ?? null, pb_intake_audit_row($vals + ['uid' => $uid]));
    $GLOBALS['PB_INTAKE_BEFORE'] = null;
}

function pb_intake_before_delete(PDO $pdo, int $cid, array $row): void {
    $c = pb_intake_cust();
    if (!pb_intake_owns($row, $c)) bad('این پذیرش متعلق به شما نیست', 403);
    if ((string)$row['status'] !== 'draft') bad('پذیرشِ ثبت‌شده قابل حذف نیست؛ مدیر می‌تواند آن را رد کند', 409);
    // درصدهای پیش‌نویس هم حذف نرم می‌شوند تا کلاینت‌های دیگر یتیم نبینند
    $st = $pdo->prepare('SELECT uid FROM ent_lot_intake_pcts WHERE customer_id = ? AND intake_id = ? AND deleted_at IS NULL');
    $st->execute([$cid, $row['uid']]);
    foreach ($st->fetchAll() as $ch) {
        $rev = pb_entity_next_rev($pdo, $cid, 'lot_intake_pcts');
        $pdo->prepare('UPDATE ent_lot_intake_pcts SET deleted_at = NOW(), rev = ? WHERE customer_id = ? AND uid = ?')->execute([$rev, $cid, $ch['uid']]);
    }
    pb_audit($pdo, $c, 'delete', 'intake', (string)$row['uid'], 'حذف پیش‌نویس پذیرش اتاق انس', pb_intake_audit_row($row), null);
}

/* ---------------- hooks: lot_intake_pcts ---------------- */

/** والد را قفل می‌کند و مالکیت/قفل‌بودن را می‌سنجد. */
function pb_intake_pct_parent(PDO $pdo, int $cid, string $intakeUid, array $c): array {
    $st = $pdo->prepare('SELECT * FROM ent_lot_intakes WHERE customer_id = ? AND uid = ? AND deleted_at IS NULL FOR UPDATE');
    $st->execute([$cid, $intakeUid]);
    $p = $st->fetch();
    if (!$p) bad('پذیرش مربوطه پیدا نشد', 404);
    if (!pb_intake_owns($p, $c)) bad('این پذیرش متعلق به شما نیست', 403);
    pb_intake_require_unlocked($p);
    return $p;
}

function pb_intake_pct_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $c = pb_intake_cust();
    $intakeUid = (string)($vals['intake_id'] ?? ($existing['intake_id'] ?? ''));
    if (!$isNew && array_key_exists('intake_id', $vals) && (string)$vals['intake_id'] !== (string)$existing['intake_id']) bad('پذیرشِ یک درصد قابل تغییر نیست');
    $parent = pb_intake_pct_parent($pdo, $cid, $intakeUid, $c);
    $key = array_key_exists('def_key', $vals) ? (string)$vals['def_key'] : (string)($existing['def_key'] ?? '');
    if (!$isNew && array_key_exists('def_key', $vals) && $key !== (string)$existing['def_key']) bad('نوع درصد قابل تغییر نیست؛ ردیف را حذف و دوباره ثبت کنید');
    $d = $pdo->prepare('SELECT active FROM ent_pct_defs WHERE customer_id = ? AND `key` = ? AND deleted_at IS NULL LIMIT 1');
    $d->execute([$cid, $key]);
    $def = $d->fetch();
    if (!$def) bad('نوع درصد تعریف نشده است');
    if ($isNew && (int)$def['active'] !== 1) bad('این نوع درصد غیرفعال است');
    $dup = $pdo->prepare('SELECT 1 FROM ent_lot_intake_pcts WHERE customer_id = ? AND intake_id = ? AND def_key = ? AND deleted_at IS NULL AND uid <> ? LIMIT 1');
    $dup->execute([$cid, $intakeUid, $key, (string)($existing['uid'] ?? '')]);
    if ($dup->fetchColumn()) bad('این درصد قبلاً برای همین پذیرش ثبت شده است');
    if (array_key_exists('kernel_g', $vals) && $vals['kernel_g'] !== null && ((float)$vals['kernel_g'] <= 0 || (float)$vals['kernel_g'] > 100)) {
        bad('«گرم مغز در ۱۰۰ گرم» باید بیشتر از ۰ و حداکثر ۱۰۰ باشد');
    }
    $GLOBALS['PB_INTAKE_CTX'] = ['parent' => $intakeUid, 'key' => $key, 'before' => $existing === null ? null : pb_intake_audit_row($existing)];
}

function pb_intake_pct_after_save(PDO $pdo, int $cid, string $uid, bool $isNew, array $vals): void {
    $x = $GLOBALS['PB_INTAKE_CTX'] ?? null;
    if (!is_array($x)) return;
    $pct = array_key_exists('pct', $vals) ? $vals['pct'] : ($x['before']['pct'] ?? '');
    pb_audit($pdo, pb_intake_cust(), $isNew ? 'create' : 'update', 'intake', (string)$x['parent'],
        'درصد «' . $x['key'] . '» ' . ($isNew ? 'ثبت شد' : 'ویرایش شد') . ': ' . $pct,
        $x['before'], pb_intake_audit_row($vals + ['uid' => $uid]));
    $GLOBALS['PB_INTAKE_CTX'] = null;
}

function pb_intake_pct_before_delete(PDO $pdo, int $cid, array $row): void {
    $c = pb_intake_cust();
    pb_intake_pct_parent($pdo, $cid, (string)$row['intake_id'], $c);
    pb_audit($pdo, $c, 'delete', 'intake', (string)$row['intake_id'], 'درصد «' . $row['def_key'] . '» حذف شد', pb_intake_audit_row($row), null);
}

/* ---------------- hooks: pct_defs ---------------- */

function pb_pctdef_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $c = pb_intake_cust();
    $canPrice = pb_staff_can($c, 'intake.price');
    if ($isNew) {
        $key = isset($vals['key']) ? strtolower(trim((string)$vals['key'])) : '';
        if ($key === '') {
            do { // کلید خودکار برای تعریف‌های جدید (برچسب فارسی را نمی‌شود slug کرد)
                $key = 'x' . substr(bin2hex(random_bytes(4)), 0, 8);
                $q = $pdo->prepare('SELECT 1 FROM ent_pct_defs WHERE customer_id = ? AND `key` = ? LIMIT 1');
                $q->execute([$cid, $key]);
            } while ($q->fetchColumn());
        } else {
            if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key)) bad('«کلید» فقط حروف کوچک انگلیسی، عدد و _ باشد');
            $q = $pdo->prepare('SELECT 1 FROM ent_pct_defs WHERE customer_id = ? AND `key` = ? LIMIT 1');
            $q->execute([$cid, $key]);
            if ($q->fetchColumn()) bad('این کلید قبلاً تعریف شده است');
        }
        $vals['key'] = $key;
        if (!$canPrice) $vals['pricing_allowed'] = 0; // فقط دارندهٔ intake.price تعیین می‌کند
        if (!array_key_exists('sort', $vals)) {
            $m = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) FROM ent_pct_defs WHERE customer_id = ?');
            $m->execute([$cid]);
            $vals['sort'] = (int)$m->fetchColumn() + 10;
        }
    } else {
        if (array_key_exists('key', $vals) && (string)$vals['key'] !== (string)$existing['key']) bad('«کلید» پس از ساخت قابل تغییر نیست');
        unset($vals['key']);
        if (!$canPrice && array_key_exists('pricing_allowed', $vals) && (int)$vals['pricing_allowed'] !== (int)$existing['pricing_allowed']) {
            bad('تغییر «قابل قیمت‌گذاری» نیاز به دسترسی قیمت‌گذاری دارد', 403);
        }
    }
    if (array_key_exists('label', $vals)) {
        $q = $pdo->prepare('SELECT 1 FROM ent_pct_defs WHERE customer_id = ? AND label = ? AND deleted_at IS NULL AND uid <> ? LIMIT 1');
        $q->execute([$cid, $vals['label'], (string)($existing['uid'] ?? '')]);
        if ($q->fetchColumn()) bad('عنوانی با همین نام قبلاً تعریف شده است');
    }
    $GLOBALS['PB_INTAKE_BEFORE'] = $existing === null ? null : ['key' => $existing['key'], 'label' => $existing['label'], 'active' => $existing['active'], 'sort' => $existing['sort'], 'pricing_allowed' => $existing['pricing_allowed']];
}

function pb_pctdef_after_save(PDO $pdo, int $cid, string $uid, bool $isNew, array $vals): void {
    pb_audit($pdo, pb_intake_cust(), $isNew ? 'create' : 'update', 'pct_def', $uid,
        $isNew ? 'تعریف درصد جدید در پذیرش' : 'ویرایش تعریف درصد', $GLOBALS['PB_INTAKE_BEFORE'] ?? null, $vals);
    $GLOBALS['PB_INTAKE_BEFORE'] = null;
}

function pb_pctdef_before_delete(PDO $pdo, int $cid, array $row): void {
    if (isset(PB_INTAKE_CORE_DEFS[(string)$row['key']])) bad('چهار درصد اصلی قابل حذف نیستند؛ می‌توانید غیرفعال‌شان کنید');
    $q = $pdo->prepare('SELECT 1 FROM ent_lot_intake_pcts WHERE customer_id = ? AND def_key = ? AND deleted_at IS NULL LIMIT 1');
    $q->execute([$cid, $row['key']]);
    if ($q->fetchColumn()) bad('این درصد در پذیرش‌ها استفاده شده؛ به‌جای حذف، غیرفعالش کنید');
    pb_audit($pdo, pb_intake_cust(), 'delete', 'pct_def', (string)$row['uid'], 'حذف تعریف درصد', ['key' => $row['key'], 'label' => $row['label']], null);
}

/** چهار تعریف پیش‌فرض را (فقط اگر هرگز وجود نداشته‌اند) می‌سازد. idempotent و ایمن در برابر درخواست هم‌زمان. بازگشت: تعداد ساخته‌شده. */
function pb_intake_seed_defs(PDO $pdo, int $cid): int {
    static $done = [];
    if (isset($done[$cid])) return 0;
    $n = 0;
    $lock = 'pb_pctseed_' . $cid;
    $got = false;
    try {
        $got = (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 5)')->fetchColumn() === 1;
        $st = $pdo->prepare('SELECT `key` FROM ent_pct_defs WHERE customer_id = ?');
        $st->execute([$cid]);
        $have = array_flip(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
        foreach (PB_INTAKE_CORE_DEFS as $key => $d) {
            if (isset($have[$key])) continue; // موجود (یا قبلاً حذف نرم شده) → دوباره نساز
            $own = !$pdo->inTransaction();
            if ($own) $pdo->beginTransaction();
            try {
                $rev = pb_entity_next_rev($pdo, $cid, 'pct_defs');
                $pdo->prepare('INSERT INTO ent_pct_defs (uid, customer_id, rev, created_by, `key`, label, active, sort, pricing_allowed) VALUES (?,?,?,?,?,?,1,?,1)')
                    ->execute([pb_uuid4(), $cid, $rev, null, $key, $d[0], $d[1]]);
                if ($own) $pdo->commit();
                $n++;
            } catch (Throwable $e) {
                if ($own && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        $done[$cid] = true;
    } catch (Throwable $e) {
        error_log('[intake] seed_defs: ' . $e->getMessage());
    } finally {
        if ($got) { try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')'); } catch (Throwable $e) { /* ignore */ } }
    }
    return $n;
}


/* ---------------- S3: قیمت‌گذاری و قطعی‌سازی ---------------- */

const PB_PRICE_REF_GRAM_KEY = 'price.ref_gram';
const PB_PRICE_REF_GRAM_DEFAULT = 50.0;
const PB_INTAKE_ENGINE_ERRORS = [
    'bad_base_price' => 'قیمت پایه نامعتبر است', 'bad_weight_kg' => 'وزن نامعتبر است', 'bad_ref_gram' => 'گرم مرجع نامعتبر است',
    'bad_items' => 'اقلام قیمت‌گذاری نامعتبر است', 'bad_pct' => 'درصد نامعتبر است', 'bad_q' => 'قیمت درصد نامعتبر است',
    'bad_base_gram' => '«گرم مغز» پسته اصلی باید بیشتر از ۰ و حداکثر ۱۰۰ باشد', 'bad_gram' => '«گرم مغز» باید بیشتر از ۰ و حداکثر ۱۰۰ باشد', 'sum_over_100' => 'مجموع درصدها نمی‌تواند بیشتر از ۱۰۰ باشد',
];

/** S3.T5: گرم مرجع R (تنظیم شرکت، app_settings کلید price.ref_gram؛ پیش‌فرض ۵۰). */
function pb_price_ref_gram(PDO $pdo, int $cid): float {
    $v = pb_app_setting_get($pdo, $cid, PB_PRICE_REF_GRAM_KEY, '');
    return ($v !== '' && is_numeric($v) && (float)$v > 0) ? (float)$v : PB_PRICE_REF_GRAM_DEFAULT;
}

/** جدول قیمت قطعی هر پذیرش (یک ردیف به‌ازای هر پذیرش؛ DDL → قبل از تراکنش صدا بزنید). */
function pb_intake_prices_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS lot_intake_prices (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        intake_uid CHAR(36) NOT NULL,
        purchase_id BIGINT UNSIGNED NOT NULL,
        base_price DECIMAL(18,2) NOT NULL,
        weight_kg DECIMAL(16,3) NOT NULL,
        ref_gram DECIMAL(10,3) NOT NULL,
        avg_per_kg BIGINT NOT NULL,
        total BIGINT NOT NULL,
        inputs_json MEDIUMTEXT NOT NULL,
        result_json MEDIUMTEXT NOT NULL,
        confirmed_by_staff_id INT UNSIGNED NOT NULL DEFAULT 0,
        confirmed_by_name VARCHAR(120) NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_cust_intake (customer_id, intake_uid),
        KEY idx_cust_purchase (customer_id, purchase_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** وزن خالص مبنا (کیلو) = وزن کل − تارِ گونی‌ها (همان محاسبهٔ purchases.create). */
function pb_intake_net_kg(PDO $pdo, int $cid, array $intake): float {
    pb_weigh_boot($pdo, $cid); // وزن گونی قابل‌تنظیم (فقط اگر فرم وزن‌کشی روشن باشد)
    $net = (float)$intake['gross_kg'] - tare($intake['small_sacks'] ?? 0, $intake['large_sacks'] ?? 0);
    if ($net <= 0) bad('وزن خالص این پذیرش باید بزرگ‌تر از صفر باشد');
    return $net;
}

/**
 * محاسبهٔ قیمت بدون ذخیره (مشترک preview و confirm). درصدها و kernel_g فقط از DB می‌آیند؛ کلاینت فقط q / gram_mode / g می‌دهد.
 * ورودی $p: {base_price, items:[{def_key, q|null, gram_mode?, g?}]} ؛ بازگشت: ['res','in','P','W','R','rows'] یا bad().
 */
function pb_intake_price_compute(PDO $pdo, int $cid, array $intake, array $p): array {
    $P = $p['base_price'] ?? null;
    if ($P === null || $P === '' || !is_numeric($P) || (float)$P <= 0 || (float)$P > 1e13) bad('قیمت پایه را درست وارد کنید');
    $W = pb_intake_net_kg($pdo, $cid, $intake);
    $R = pb_price_ref_gram($pdo, $cid);

    $st = $pdo->prepare('SELECT def_key, pct, kernel_g FROM ent_lot_intake_pcts WHERE customer_id = ? AND intake_id = ? AND deleted_at IS NULL ORDER BY id ASC');
    $st->execute([$cid, $intake['uid']]);
    $rows = $st->fetchAll();
    $d = $pdo->prepare('SELECT `key`, label, pricing_allowed FROM ent_pct_defs WHERE customer_id = ? AND deleted_at IS NULL');
    $d->execute([$cid]);
    $defs = [];
    foreach ($d->fetchAll() as $r) $defs[(string)$r['key']] = $r;

    $req = [];
    $reqItems = $p['items'] ?? [];
    if (!is_array($reqItems)) bad('اقلام قیمت‌گذاری نامعتبر است');
    foreach ($reqItems as $it) {
        if (!is_array($it) || !isset($it['def_key']) || !is_string($it['def_key'])) bad('اقلام قیمت‌گذاری نامعتبر است');
        if (isset($req[$it['def_key']])) bad('یک درصد بیش از یک‌بار قیمت‌گذاری شده است');
        $req[$it['def_key']] = $it;
    }
    $have = [];
    foreach ($rows as $r) $have[(string)$r['def_key']] = true;
    foreach ($req as $k => $_) if (!isset($have[$k])) bad('درصد «' . $k . '» در این پذیرش ثبت نشده است');

    $items = [];
    foreach ($rows as $r) {
        $k = (string)$r['def_key'];
        $label = (string)($defs[$k]['label'] ?? $k);
        $it = ['pct' => (float)$r['pct'], 'q' => null];
        $ri = $req[$k] ?? null;
        if ($ri !== null && isset($ri['q']) && $ri['q'] !== '' && $ri['q'] !== null) {
            // قیمت جدا برای هر درصدِ ثبت‌شده در پذیرش آزاد است (دیگر به «قابل قیمت‌گذاری» در تنظیمات وابسته نیست)
            $it['q'] = $ri['q'];
            $gm = !empty($ri['gram_mode']);
            $it['gram_mode'] = $gm;
            if ($gm) {
                $g = (isset($ri['g']) && $ri['g'] !== '' && $ri['g'] !== null) ? $ri['g'] : $r['kernel_g']; // اگر حسابدار نداد، گرم ثبت‌شدهٔ اتاق انس
                if ($g === null || $g === '') bad('«گرم مغز در ۱۰۰ گرم» برای «' . $label . '» وارد نشده است');
                $it['g'] = $g;
            }
        }
        $items[] = $it;
    }
    $in = ['base_price' => $P, 'weight_kg' => $W, 'ref_gram' => $R, 'items' => $items];
    // خرید گرمیِ پسته اصلی؛ اگر حسابدار گرم نداد، «گرم» ثبت‌شدهٔ اتاق انس استفاده می‌شود
    if (!empty($p['base_gram_mode'])) {
        $bg = (isset($p['base_g']) && $p['base_g'] !== '' && $p['base_g'] !== null) ? $p['base_g'] : ($intake['gram'] ?? null);
        if ($bg === null || $bg === '') bad('«گرم» پسته اصلی وارد نشده است');
        $in['base_gram_mode'] = true;
        $in['base_g'] = $bg;
    }
    $res = pb_price_blend($in);
    if (!$res['ok']) bad(PB_INTAKE_ENGINE_ERRORS[$res['error']] ?? 'قیمت‌گذاری نامعتبر است');
    // نام درصد برای نمایش/ممیزی کنار هر خط (هم‌ترتیب items)
    foreach ($res['lines'] as $i => $ln) { $res['lines'][$i]['def_key'] = (string)$rows[$i]['def_key']; $res['lines'][$i]['label'] = (string)($defs[(string)$rows[$i]['def_key']]['label'] ?? $rows[$i]['def_key']); }
    return ['res' => $res, 'in' => $in, 'P' => (float)$P, 'W' => $W, 'R' => $R, 'rows' => $rows, 'defs' => $defs];
}

/** پذیرش را با قفل می‌خواند (FOR UPDATE داخل تراکنش) و وجود/حذف‌نشدن را می‌سنجد. */
function pb_intake_load(PDO $pdo, int $cid, string $uid, bool $forUpdate): array {
    $st = $pdo->prepare('SELECT * FROM ent_lot_intakes WHERE customer_id = ? AND uid = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$cid, $uid]);
    $row = $st->fetch();
    if (!$row || $row['deleted_at'] !== null) bad('پذیرش پیدا نشد', 404);
    return $row;
}

/** انبارهای فعال برای انتخاب «انبار مقصد» موقع قطعی‌سازی (بدون نیاز به مجوز مشاهدهٔ انبار). پیش‌فرض اول. */
function pb_intake_wh_options(PDO $pdo, int $cid): array {
    pb_wh_default_uid($pdo, $cid);
    $st = $pdo->prepare('SELECT uid, name, is_default FROM ent_warehouses WHERE customer_id = ? AND deleted_at IS NULL AND active = 1 ORDER BY is_default DESC, id ASC');
    $st->execute([$cid]);
    return array_map(static fn($r) => ['uid' => (string)$r['uid'], 'name' => (string)$r['name'], 'is_default' => (int)$r['is_default'] === 1], $st->fetchAll());
}

/** payload مربوط به purchases.create از روی پذیرش + قیمت نهایی (چهار درصد اصلی در ستون‌های همنام، بقیه custom_specs). */
function pb_intake_purchase_payload(array $intake, array $calc): array {
    $pl = [
        'category_id' => (int)$intake['category_id'], 'type_name' => (string)$intake['type_name'],
        'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d'),
        'total_weight' => (float)$intake['gross_kg'],
        'small_sack_count' => (int)$intake['small_sacks'], 'large_sack_count' => (int)$intake['large_sacks'],
        'price' => $calc['res']['avg_per_kg'],
        'ounce' => $intake['ounce'], 'gram' => $intake['gram'],
        'seller' => (string)($intake['owner_name'] ?? ''),
        'note' => trim('پذیرش اتاق انس ' . (string)$intake['code'] . ((string)($intake['note'] ?? '') !== '' ? ' — ' . $intake['note'] : '')),
    ];
    $specs = [];
    foreach ($calc['rows'] as $r) {
        $k = (string)$r['def_key'];
        if (isset(PB_INTAKE_CORE_DEFS[$k])) { $pl['pct_' . $k] = (float)$r['pct']; continue; }
        $specs[] = ['label' => (string)($calc['defs'][$k]['label'] ?? $k), 'value' => rtrim(rtrim(number_format((float)$r['pct'], 3, '.', ''), '0'), '.') . '%'];
    }
    if ($specs) $pl['custom_specs'] = $specs;
    return $pl;
}

/** تغییر وضعیت سمت سرور با UPDATE مستقیم + rev (قفل entity.save را دور می‌زند). */
function pb_intake_set_status(PDO $pdo, int $cid, string $uid, string $status, array $customer, array $extra = []): void {
    [$sid, $sname] = pb_intake_actor($customer);
    $rev = pb_entity_next_rev($pdo, $cid, 'lot_intakes');
    $sets = ['status = ?', 'confirmed_by_staff_id = ?', 'confirmed_by_name = ?', 'confirmed_at = NOW()', 'rev = ?'];
    $args = [$status, $sid, $sname, $rev];
    foreach ($extra as $col => $val) { $sets[] = "`$col` = ?"; $args[] = $val; }
    $args[] = $cid; $args[] = $uid;
    $pdo->prepare('UPDATE ent_lot_intakes SET ' . implode(', ', $sets) . ' WHERE customer_id = ? AND uid = ?')->execute($args);
}

function pb_intake_action_s3(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    $GLOBALS['PB_ENTITY_CUSTOMER'] = $customer;
    $def = pb_entity_def('lot_intakes');
    $uid = (string)($p['uid'] ?? '');

    if ($action === 'intake.price_settings') { // خواندن/ذخیرهٔ R
        if (array_key_exists('ref_gram', $p)) {
            if (!pb_staff_can($customer, 'intake.price')) bad('دسترسی لازم را ندارید', 403);
            $r = $p['ref_gram'];
            if (!is_numeric($r) || (float)$r <= 0 || (float)$r > 1000) bad('گرم مرجع باید بیشتر از ۰ و حداکثر ۱۰۰۰ باشد');
            $before = pb_price_ref_gram($pdo, $cid);
            pb_app_setting_set($pdo, $cid, PB_PRICE_REF_GRAM_KEY, (string)(float)$r);
            pb_audit($pdo, $customer, 'update', 'intake_price_settings', 'ref_gram', 'تغییر گرم مرجع قیمت‌گذاری', ['ref_gram' => $before], ['ref_gram' => (float)$r]);
        }
        out(['ref_gram' => pb_price_ref_gram($pdo, $cid)]);
    }

    if (!pb_is_uid($uid)) bad('شناسهٔ پذیرش نامعتبر است');

    if ($action === 'intake.preview_price') { // فقط محاسبه؛ هیچ نوشتنی ندارد
        $row = pb_intake_load($pdo, $cid, $uid, false);
        if ((string)$row['status'] !== 'submitted') bad('فقط پذیرشِ «ثبت‌شده» قابل قیمت‌گذاری است', 409);
        $c = pb_intake_price_compute($pdo, $cid, $row, $p);
        out(['uid' => $uid, 'code' => (string)$row['code'], 'weight_kg' => $c['W'], 'ref_gram' => $c['R'], 'base_price' => $c['P'], 'base_gram' => $row['gram'], 'warehouses' => pb_intake_wh_options($pdo, $cid),
             'avg_per_kg' => $c['res']['avg_per_kg'], 'total' => $c['res']['total'], 'lines' => $c['res']['lines']]);
    }

    if ($action === 'intake.confirm') {
        if (!pb_staff_can($customer, 'intake.price')) bad('برای قطعی‌سازی دسترسی «قیمت‌گذاری» هم لازم است', 403);
        pb_intake_ensure_indexes($pdo); pb_intake_prices_ensure($pdo); pb_lotcode_ensure($pdo); // DDL → قبل از تراکنش
        pb_weigh_boot($pdo, $cid);
        $pdo->beginTransaction();
        $row = pb_intake_load($pdo, $cid, $uid, true);
        $status = (string)$row['status'];
        if ($status === 'confirmed') { // تکرار (صف آفلاین/دوبار کلیک): همان نتیجه، خرید دوم ساخته نمی‌شود
            $pdo->commit();
            out(['item' => pb_entity_row_out($def, $row), 'purchase_id' => (int)$row['purchase_id'], 'already' => true]);
        }
        if ($status === 'draft') bad('پذیرش هنوز ثبت نهایی نشده است', 409);
        if ($status === 'rejected') bad('این پذیرش رد شده است', 409);
        if ($status === 'sample_only' || (int)$row['is_sample'] === 1) bad('پذیرشِ «نمونه» قطعی نمی‌شود', 409);
        if ($status !== 'submitted') bad('وضعیت پذیرش اجازهٔ قطعی‌سازی نمی‌دهد', 409);
        if ((string)$row['code'] === '') bad('پذیرش کد ندارد', 409);

        $calc = pb_intake_price_compute($pdo, $cid, $row, $p);
        $payload = pb_intake_purchase_payload($row, $calc);
        if (isset($p['warehouse_uid']) && is_string($p['warehouse_uid']) && $p['warehouse_uid'] !== '') $payload['warehouse_uid'] = $p['warehouse_uid']; // انبار مقصد (اعتبارسنجی در pb_wh_apply_lot)
        $isFx = ($GLOBALS['isFx'] ?? false) === true;
        $purchaseId = pb_purchase_create_core($pdo, $cid, $customer, $payload, $isFx, ['code' => (string)$row['code'], 'in_tx' => true]);

        [$sid, $sname] = pb_intake_actor($customer);
        $pdo->prepare('INSERT INTO lot_intake_prices (customer_id, intake_uid, purchase_id, base_price, weight_kg, ref_gram, avg_per_kg, total, inputs_json, result_json, confirmed_by_staff_id, confirmed_by_name, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())')->execute([
            $cid, $uid, $purchaseId, $calc['P'], $calc['W'], $calc['R'], $calc['res']['avg_per_kg'], $calc['res']['total'],
            json_encode($calc['in'], JSON_UNESCAPED_UNICODE), json_encode($calc['res'], JSON_UNESCAPED_UNICODE), $sid, $sname]);
        pb_intake_set_status($pdo, $cid, $uid, 'confirmed', $customer, ['purchase_id' => $purchaseId]);
        pb_audit($pdo, $customer, 'update', 'intake', $uid, 'قطعی‌سازی پذیرش اتاق انس (' . $row['code'] . ') ← خرید #' . $purchaseId,
            ['status' => 'submitted'], ['status' => 'confirmed', 'purchase_id' => $purchaseId, 'avg_per_kg' => $calc['res']['avg_per_kg'], 'total' => $calc['res']['total'], 'staff' => $sname]);
        pb_audit($pdo, $customer, 'create', 'purchase', (string)$purchaseId, 'ثبت خرید از پذیرش اتاق انس (' . $row['code'] . ')', null,
            ['code' => $row['code'], 'price' => $calc['res']['avg_per_kg'], 'intake' => $uid]);
        $fresh = pb_intake_load($pdo, $cid, $uid, false);
        $pdo->commit();
        out(['item' => pb_entity_row_out($def, $fresh), 'purchase_id' => $purchaseId, 'avg_per_kg' => $calc['res']['avg_per_kg'], 'total' => $calc['res']['total'], 'already' => false]);
    }

    if ($action === 'intake.reject' || $action === 'intake.mark_sample') {
        $isReject = $action === 'intake.reject';
        $reason = trim((string)($p['reason'] ?? ''));
        if ($isReject && ($reason === '' || mb_strlen($reason) > 250)) bad('دلیل رد را (حداکثر ۲۵۰ نویسه) وارد کنید');
        // رد قطعی (final، پیش‌فرض؛ سازگار با کلاینت قدیمی) = قفل می‌شود؛ final=false = «برگشت برای اصلاح»: اتاق انس ویرایش و دوباره ثبت می‌کند
        $finalReject = $isReject && (!array_key_exists('final', $p) || !empty($p['final']));
        $target = $isReject ? ($finalReject ? 'rejected' : 'returned') : 'sample_only';
        $sampleNote = trim((string)($p['note'] ?? ''));
        if (!$isReject && ($sampleNote === '' || mb_strlen($sampleNote) > 400)) bad('برای «نمونه» توضیح لازم است (حداکثر ۴۰۰ نویسه): این نمونه برای چیست/کیست؟');
        $pdo->beginTransaction();
        $row = pb_intake_load($pdo, $cid, $uid, true);
        $status = (string)$row['status'];
        if ($status === $target) { // تکراری → همان نتیجه
            $pdo->commit();
            out(['item' => pb_entity_row_out($def, $row), 'already' => true]);
        }
        if ($status === 'draft') bad('پذیرش هنوز ثبت نهایی نشده است', 409);
        if ($status !== 'submitted') bad('این پذیرش قطعی، رد یا نمونه شده و دیگر قابل تغییر نیست', 409);
        if (!$isReject && trim((string)($row['owner_name'] ?? '')) === '') bad('نام صاحب پسته برای «نمونه» الزامی است', 409);
        $noteNew = trim((string)($row['note'] ?? ''));
        $noteNew = mb_substr(($noteNew === '' ? '' : $noteNew . ' | ') . 'نمونه: ' . $sampleNote, 0, 500);
        $extra = $isReject ? ['reject_reason' => mb_substr($reason, 0, 250)] : ['is_sample' => 1, 'note' => $noteNew];
        pb_intake_set_status($pdo, $cid, $uid, $target, $customer, $extra);
        [, $sname] = pb_intake_actor($customer);
        pb_audit($pdo, $customer, 'update', 'intake', $uid, ($isReject ? ($finalReject ? 'رد قطعی پذیرش اتاق انس (' : 'برگشت پذیرش به اتاق انس برای اصلاح (') : 'تبدیل پذیرش به نمونه (') . $row['code'] . ')' . ($isReject ? ': ' . mb_substr($reason, 0, 120) : ''),
            ['status' => 'submitted'], ['status' => $target, 'staff' => $sname] + ($isReject ? ['reject_reason' => $reason] : []));
        $fresh = pb_intake_load($pdo, $cid, $uid, false);
        $pdo->commit();
        out(['item' => pb_entity_row_out($def, $fresh), 'already' => false]);
    }

    bad('اکشن نامعتبر است', 404);
}

/* ---------------- اکشن‌های اختصاصی ---------------- */

/** intake.submit {uid} | intake.pct_defs.seed — همیشه با out()/bad() تمام می‌شود. */
function pb_intake_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    $GLOBALS['PB_ENTITY_CUSTOMER'] = $customer;

    if ($action === 'intake.pct_defs.seed') {
        out(['seeded' => pb_intake_seed_defs($pdo, $cid)]);
    }

    if ($action === 'intake.submit') {
        $uid = (string)($p['uid'] ?? '');
        if (!pb_is_uid($uid)) bad('شناسهٔ پذیرش نامعتبر است');
        pb_intake_seed_defs($pdo, $cid);
        pb_intake_ensure_indexes($pdo); // DDL → قبل از تراکنش
        pb_lotcode_ensure($pdo);        // DDL → قبل از تراکنش
        [$sid, $sname] = pb_intake_actor($customer);
        $def = pb_entity_def('lot_intakes');
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM ent_lot_intakes WHERE customer_id = ? AND uid = ? FOR UPDATE');
        $st->execute([$cid, $uid]);
        $row = $st->fetch();
        if (!$row || $row['deleted_at'] !== null) bad('پذیرش پیدا نشد', 404);
        if (!pb_intake_owns($row, $customer)) bad('این پذیرش متعلق به شما نیست', 403);
        $status = (string)$row['status'];
        if ($status === 'submitted' || $status === 'sample_only') { // تلاش دوباره از صف آفلاین: همان نتیجه، کد جدید نمی‌گیرد
            $pdo->commit();
            out(['item' => pb_entity_row_out($def, $row), 'code' => (string)$row['code'], 'already' => true]);
        }
        if ($status !== 'draft') pb_intake_require_unlocked($row);

        // اعتبارسنجی کامل قبل از گرفتن کد
        if ((int)($row['category_id'] ?? 0) <= 0) bad('دسته را انتخاب کنید');
        if (trim((string)($row['type_name'] ?? '')) === '') bad('نوع پسته را وارد کنید');
        if ((float)($row['gross_kg'] ?? 0) <= 0) bad('وزن کل را وارد کنید');
        $isSample = (int)$row['is_sample'] === 1;
        if (trim((string)($row['owner_name'] ?? '')) === '') bad($isSample ? 'نام صاحب نمونه الزامی است' : 'نام صاحب پسته را وارد کنید');
        if ($isSample && trim((string)($row['note'] ?? '')) === '') bad('برای «نمونه» توضیح بنویسید (این نمونه برای چیست/کیست؟)');
        if ($row['gram'] !== null && ((float)$row['gram'] <= 0 || (float)$row['gram'] > 100)) bad('«گرم» نامعتبر است');
        $ps = $pdo->prepare('SELECT def_key, pct FROM ent_lot_intake_pcts WHERE customer_id = ? AND intake_id = ? AND deleted_at IS NULL');
        $ps->execute([$cid, $uid]);
        $sum = 0.0;
        foreach ($ps->fetchAll() as $pr) $sum += (float)$pr['pct'];
        if ($sum > 100.0001) bad('مجموع درصدها نمی‌تواند بیشتر از ۱۰۰ باشد');

        if ($status === 'returned' && (string)($row['code'] ?? '') !== '') {
            $code = (string)$row['code']; // پذیرشِ برگشتی همان کد قبلی را نگه می‌دارد
        } else {
            $code = pb_alloc_lot_code($pdo, $cid, $sid, null, pb_code_letter_for_category($pdo, $cid, (int)$row['category_id'])); // T1: حرف از دستهٔ پسته؛ سمت سرور، اتمیک، تاریخ شمسی سرور
            $dup = $pdo->prepare('SELECT 1 FROM ent_lot_intakes WHERE customer_id = ? AND code = ? LIMIT 1');
            $dup->execute([$cid, $code]);
            if ($dup->fetchColumn()) throw new RuntimeException('کد پسته تکراری تولید شد');
        }
        $newStatus = $isSample ? 'sample_only' : 'submitted';
        $rev = pb_entity_next_rev($pdo, $cid, 'lot_intakes');
        $pdo->prepare('UPDATE ent_lot_intakes SET code = ?, status = ?, submitted_at = NOW(), reject_reason = NULL, rev = ? WHERE customer_id = ? AND uid = ?')
            ->execute([$code, $newStatus, $rev, $cid, $uid]);
        pb_audit($pdo, $customer, 'update', 'intake', $uid, 'ثبت نهایی پذیرش اتاق انس (' . $code . ')',
            ['status' => $status, 'code' => null], ['status' => $newStatus, 'code' => $code, 'staff' => $sname]);
        $st = $pdo->prepare('SELECT * FROM ent_lot_intakes WHERE customer_id = ? AND uid = ?');
        $st->execute([$cid, $uid]);
        $fresh = $st->fetch();
        $pdo->commit();
        out(['item' => pb_entity_row_out($def, $fresh), 'code' => $code, 'already' => false]);
    }

    if (strpos($action, 'intake.') === 0) pb_intake_action_s3($action, $p, $pdo, $customer); // S3
    bad('اکشن نامعتبر است', 404);
}
