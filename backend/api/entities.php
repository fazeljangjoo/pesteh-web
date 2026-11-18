<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * Generic entity framework (Z-Limit S00.T00.3).
 *
 * One registry entry = one tenant-scoped table `ent_<name>` + generic actions:
 *   entity.list | entity.save | entity.delete | entity.changes   (called from app.php)
 *
 * Rules:
 *  - customer_id is ALWAYS taken from the authenticated customer; any client-sent cid/customer_id/id/rev is ignored.
 *  - Rows are identified across devices by `uid` (client UUID v4). `id` is server-local only.
 *  - Every write bumps a per-(customer,entity) revision counter (`entity_revs`) -> rows.rev. Clients pull with since_rev.
 *  - Deletes are soft (deleted_at) so that sync clients learn about them.
 *  - Tables/columns are created/extended automatically from the registry (pb_ensure_entity_tables, run by pb_migrate()
 *    when the registry hash changes). Columns are only ever ADDED, never dropped/retyped.
 *  - References between entities use the other row's `uid` (type 'ref'), so backup/restore needs no id remapping.
 *
 * Column spec: ['type'=>string|text|int|bigint|decimal|bool|date|datetime|enum|ref|json,
 *               'label'=>Persian label (for errors), 'required'=>bool, 'max'=>int (string/text/json length),
 *               'min'/'max' for int|bigint|decimal values use 'vmin'/'vmax', 'scale'=>int (decimal),
 *               'values'=>[..] (enum), 'ref'=>entity name (ref), 'default'=>mixed]
 * Entity spec: ['label'=>Persian, 'columns'=>[...], 'perms'=>['view','create','edit','delete' => permission key],
 *               'gate'=>['tier'=>'green|gold','plan'=>'any|fx'], 'search'=>[column,...], 'order'=>['col','ASC|DESC'],
 *               'dev'=>bool (only when PB_ENTITY_DEV is defined true)]
 * money columns: use 'bigint' in the unit stored by the app (toman) -- never floats (see DECISIONS B1/Q1).
 */

require_once __DIR__ . '/features.php';
require_once __DIR__ . '/treasury.php'; // S15.T15.3 gold entities (merged into the registry)
require_once __DIR__ . '/intake.php'; // S2 (ounce room): lot_intakes / lot_intake_pcts / pct_defs entities + intake.submit

const PB_ENTITY_RESERVED = ['id', 'uid', 'customer_id', 'rev', 'deleted_at', 'created_by', 'created_at', 'updated_at'];

/** The registry. Later stages append entries here (never rename/remove a released one). */
function pb_entity_registry(): array {
    $gold = function_exists('pb_gold_entities') ? pb_gold_entities() : []; // S15.T15.3 (treasury.php)
    $gold = $gold + (function_exists('pb_intake_entities') ? pb_intake_entities() : []); // S2 (intake.php)
    return $gold + [
        // S01.T01.1 warehouses (unlimited per customer; lots point to them via purchases.warehouse_uid)
        'warehouses' => [
            'label' => 'انبار',
            'columns' => [
                'name'       => ['type' => 'string', 'label' => 'نام انبار', 'required' => true, 'max' => 100],
                'type'       => ['type' => 'enum', 'label' => 'نوع', 'values' => ['main', 'consignment', 'processing', 'virtual'], 'default' => 'main'],
                'active'     => ['type' => 'bool', 'label' => 'فعال', 'default' => true],
                'address'    => ['type' => 'string', 'label' => 'نشانی', 'max' => 250],
                'is_default' => ['type' => 'bool', 'label' => 'پیش‌فرض', 'default' => false],
            ],
            'perms' => ['view' => 'warehouses.view', 'create' => 'warehouses.manage', 'edit' => 'warehouses.manage', 'delete' => 'warehouses.manage'],
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['name', 'address'],
            'order' => ['id', 'ASC'],
            'before_save' => 'pb_wh_before_save',
            'before_delete' => 'pb_wh_before_delete',
        ],
        // S01.T01.1 ounce grades (e.g. code '28-30', from 28 to 30); numeric purchases.ounce is kept separately
        'ounces' => [
            'label' => 'درجه‌بندی اونس',
            'columns' => [
                'code'     => ['type' => 'string', 'label' => 'کد', 'required' => true, 'max' => 40],
                'from_val' => ['type' => 'decimal', 'label' => 'از', 'scale' => 3, 'vmin' => 0],
                'to_val'   => ['type' => 'decimal', 'label' => 'تا', 'scale' => 3, 'vmin' => 0],
                'note'     => ['type' => 'string', 'label' => 'توضیح', 'max' => 250],
            ],
            'perms' => ['view' => 'ounces.view', 'create' => 'ounces.manage', 'edit' => 'ounces.manage', 'delete' => 'ounces.manage'],
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['code', 'note'],
            'order' => ['from_val', 'ASC'],
            'before_save' => 'pb_ounce_before_save',
            'before_delete' => 'pb_ounce_before_delete',
        ],
        // S03.T03.3 CRM interactions (calls/visits/messages) — `party` = partyKey() name of the party
        'crm_notes' => [
            'label' => 'یادداشت ارتباط با طرف‌حساب',
            'columns' => [
                'party' => ['type' => 'string', 'label' => 'طرف‌حساب', 'required' => true, 'max' => 150],
                'kind'  => ['type' => 'enum', 'label' => 'نوع', 'values' => ['call', 'visit', 'message', 'other'], 'default' => 'other'],
                'text'  => ['type' => 'text', 'label' => 'متن', 'required' => true, 'max' => 5000],
                'date'  => ['type' => 'date', 'label' => 'تاریخ'],
            ],
            'perms' => ['view' => 'crm.view', 'create' => 'crm.edit', 'edit' => 'crm.edit', 'delete' => 'crm.edit'],
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['party', 'text'],
            'order' => ['id', 'DESC'],
        ],
        // S03.T03.3 CRM follow-up tasks
        'crm_tasks' => [
            'label' => 'پیگیری طرف‌حساب',
            'columns' => [
                'party'          => ['type' => 'string', 'label' => 'طرف‌حساب', 'required' => true, 'max' => 150],
                'due_at'         => ['type' => 'datetime', 'label' => 'موعد پیگیری'],
                'text'           => ['type' => 'string', 'label' => 'شرح', 'required' => true, 'max' => 500],
                'done'           => ['type' => 'bool', 'label' => 'انجام شد', 'default' => false],
                'assignee_staff' => ['type' => 'string', 'label' => 'مسئول', 'max' => 100],
            ],
            'perms' => ['view' => 'crm.view', 'create' => 'crm.edit', 'edit' => 'crm.edit', 'delete' => 'crm.edit'],
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['party', 'text'],
            'order' => ['due_at', 'ASC'],
        ],
        // S12.T12.1 company profile (one row per customer; used on invoice/PDF headers)
        'company_profile' => [
            'label' => 'مشخصات شرکت',
            'columns' => [
                'name'            => ['type' => 'string', 'label' => 'نام شرکت', 'required' => true, 'max' => 150],
                'logo'            => ['type' => 'text', 'label' => 'لوگو', 'max' => 120000],
                'address'         => ['type' => 'string', 'label' => 'نشانی', 'max' => 300],
                'phones'          => ['type' => 'string', 'label' => 'تلفن‌ها', 'max' => 150],
                'economic_code'   => ['type' => 'string', 'label' => 'کد اقتصادی', 'max' => 30],
                'national_id'     => ['type' => 'string', 'label' => 'شناسه ملی', 'max' => 30],
                'postal_code'     => ['type' => 'string', 'label' => 'کد پستی', 'max' => 20],
                'registration_no' => ['type' => 'string', 'label' => 'شمارهٔ ثبت', 'max' => 30],
                'footer_text'     => ['type' => 'string', 'label' => 'متن پای فاکتور', 'max' => 300],
            ],
            'perms' => ['create' => 'settings.manage', 'edit' => 'settings.manage', 'delete' => 'settings.manage'],
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['name'],
            'order' => ['id', 'ASC'],
            'before_save' => 'pb_company_before_save',
        ],
        // S12.T12.1 document number sequences (fy = Jalali year, 0 = not per year / green tier)
        'doc_sequences' => [
            'label' => 'شمارندهٔ اسناد',
            'columns' => [
                'doc_type' => ['type' => 'string', 'label' => 'نوع سند', 'required' => true, 'max' => 20],
                'fy'       => ['type' => 'int', 'label' => 'سال مالی', 'vmin' => 0, 'vmax' => 9999, 'default' => 0],
                'prefix'   => ['type' => 'string', 'label' => 'پیشوند', 'max' => 12],
                'next_no'   => ['type' => 'int', 'label' => 'شمارهٔ بعدی', 'vmin' => 1, 'default' => 1],
                'padding'  => ['type' => 'int', 'label' => 'تعداد ارقام', 'vmin' => 0, 'vmax' => 12, 'default' => 5],
            ],
            'perms' => ['create' => 'settings.manage', 'edit' => 'settings.manage', 'delete' => 'settings.manage'], // S9: create/edit قبلاً بدون مجوز بود (هر کارکن، از جمله «اتاق انس»، می‌توانست شمارندهٔ اسناد را جلو ببرد)
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['doc_type', 'prefix'],
            'order' => ['doc_type', 'ASC'],
            'before_save' => 'pb_docseq_before_save',
        ],
        // Sample/dev entity for testing the framework; hidden unless define('PB_ENTITY_DEV', true) in private/config.php.
        'dev_notes' => [
            'label' => 'یادداشت آزمایشی',
            'dev' => true,
            'columns' => [
                'title' => ['type' => 'string', 'label' => 'عنوان', 'required' => true, 'max' => 120],
                'body'  => ['type' => 'text',   'label' => 'متن', 'max' => 5000],
                'kind'  => ['type' => 'enum',   'label' => 'نوع', 'values' => ['note', 'todo'], 'default' => 'note'],
                'done'  => ['type' => 'bool',   'label' => 'انجام شد', 'default' => false],
                'due'   => ['type' => 'date',   'label' => 'سررسید'],
                'amount'=> ['type' => 'bigint', 'label' => 'مبلغ', 'vmin' => 0],
            ],
            'perms' => ['view' => 'dev_notes.view', 'create' => 'dev_notes.create', 'edit' => 'dev_notes.edit', 'delete' => 'dev_notes.delete'],
            'gate' => ['tier' => 'green', 'plan' => 'any'],
            'search' => ['title', 'body'],
            'order' => ['id', 'DESC'],
        ],
    ];
}

function pb_entities_hash(): string {
    return md5((string)json_encode(pb_entity_registry()));
}

function pb_entity_def(string $name): ?array {
    if (!preg_match('/^[a-z][a-z0-9_]{1,40}$/', $name)) return null;
    $r = pb_entity_registry();
    return isset($r[$name]) ? $r[$name] : null;
}

function pb_entity_table(string $name): string { return 'ent_' . $name; }

/** Entity visible to this edition? (dev entities only when enabled) */
function pb_entity_visible(array $def, array $edition): bool {
    if (!empty($def['dev']) && !(defined('PB_ENTITY_DEV') && PB_ENTITY_DEV)) return false;
    $g = $def['gate'] ?? ['tier' => 'green', 'plan' => 'any'];
    return pb_feature_allowed((string)($edition['tier'] ?? 'green'), (string)($edition['plan'] ?? 'rial'), $g);
}

/** Permission hook. S02 defines pb_staff_can(array $customer, string $key): bool; until then the owner can do everything. */
function pb_perm_check(array $customer, $key): bool {
    if (is_array($key)) { // S2: any-of list of permission keys
        foreach ($key as $k) { if (pb_perm_check($customer, (string)$k)) return true; }
        return false;
    }
    if (function_exists('pb_staff_can')) return (bool)pb_staff_can($customer, (string)$key);
    return true;
}

function pb_uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

function pb_is_uid($v): bool {
    return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) === 1;
}

/* ---------------- schema ---------------- */

function pb_entity_sql_type(array $col): string {
    switch ($col['type'] ?? 'string') {
        case 'string':   return 'VARCHAR(' . max(1, min(1000, (int)($col['max'] ?? 255))) . ') NULL';
        case 'text':     return 'MEDIUMTEXT NULL';
        case 'int':      return 'INT NULL';
        case 'bigint':   return 'BIGINT NULL';
        case 'decimal':  return 'DECIMAL(24,' . max(0, min(8, (int)($col['scale'] ?? 4))) . ') NULL';
        case 'bool':     return 'TINYINT(1) NULL';
        case 'date':     return 'DATE NULL';
        case 'datetime': return 'DATETIME NULL';
        case 'enum':     return 'VARCHAR(40) NULL';
        case 'ref':      return 'CHAR(36) NULL';
        case 'json':     return 'MEDIUMTEXT NULL';
    }
    throw new RuntimeException('unknown column type');
}

/** Create missing tables / add missing columns for every registry entity. Idempotent. Throws on failure. */
function pb_ensure_entity_tables(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS entity_revs (
        customer_id INT UNSIGNED NOT NULL,
        entity VARCHAR(41) NOT NULL,
        rev BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (customer_id, entity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (pb_entity_registry() as $name => $def) {
        if (!preg_match('/^[a-z][a-z0-9_]{1,40}$/', (string)$name)) throw new RuntimeException('bad entity name');
        $t = pb_entity_table((string)$name);
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS `$t` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid CHAR(36) NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            rev BIGINT UNSIGNED NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cust_uid (customer_id, uid),
            KEY idx_cust_rev (customer_id, rev)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (($def['columns'] ?? []) as $cn => $col) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', (string)$cn) || in_array($cn, PB_ENTITY_RESERVED, true)) {
                throw new RuntimeException("bad column name $name.$cn");
            }
            $st = $pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?");
            $st->execute([(string)$cn]);
            if (!$st->fetch()) {
                $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$cn` " . pb_entity_sql_type($col));
            }
        }
    }
}

/* ---------------- validation ---------------- */

function pb_en_digits(string $s): string {
    return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                      '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => '', ',' => '']);
}

/**
 * Coerce one value. Returns the DB-ready value (null = empty). Calls bad() with a Persian message on invalid input.
 * $cid is needed only for 'ref' existence checks.
 */
function pb_entity_coerce(PDO $pdo, int $cid, string $cn, array $col, $v) {
    $label = (string)($col['label'] ?? $cn);
    $type = (string)($col['type'] ?? 'string');
    if ($v === null || $v === '') return null;
    switch ($type) {
        case 'string':
        case 'text':
            if (!is_scalar($v)) bad("«$label» نامعتبر است");
            $s = trim((string)$v);
            if ($s === '') return null;
            $max = (int)($col['max'] ?? ($type === 'string' ? 255 : 20000));
            if (mb_strlen($s) > $max) bad("«$label» حداکثر $max حرف می‌تواند باشد");
            return $s;
        case 'int':
        case 'bigint':
            if (is_bool($v) || !is_scalar($v)) bad("«$label» باید عدد باشد");
            $s = pb_en_digits(trim((string)$v));
            if (!preg_match('/^-?\d{1,18}$/', $s)) bad("«$label» باید عدد صحیح باشد");
            $n = (int)$s;
            $lim = $type === 'int' ? 2147483647 : 9007199254740991;
            if ($n > $lim || $n < -$lim) bad("«$label» خارج از حد مجاز است");
            if (isset($col['vmin']) && $n < (int)$col['vmin']) bad("«$label» نمی‌تواند کمتر از " . (int)$col['vmin'] . ' باشد');
            if (isset($col['vmax']) && $n > (int)$col['vmax']) bad("«$label» نمی‌تواند بیشتر از " . (int)$col['vmax'] . ' باشد');
            return $n;
        case 'decimal':
            if (is_bool($v) || !is_scalar($v)) bad("«$label» باید عدد باشد");
            $s = pb_en_digits(trim((string)$v));
            if (!preg_match('/^-?\d{1,18}(\.\d{1,8})?$/', $s)) bad("«$label» باید عدد باشد");
            $f = round((float)$s, max(0, min(8, (int)($col['scale'] ?? 4))));
            if (isset($col['vmin']) && $f < (float)$col['vmin']) bad("«$label» نمی‌تواند کمتر از " . $col['vmin'] . ' باشد');
            if (isset($col['vmax']) && $f > (float)$col['vmax']) bad("«$label» نمی‌تواند بیشتر از " . $col['vmax'] . ' باشد');
            return number_format($f, max(0, min(8, (int)($col['scale'] ?? 4))), '.', '');
        case 'bool':
            if ($v === true || $v === 1 || $v === '1' || $v === 'true') return 1;
            if ($v === false || $v === 0 || $v === '0' || $v === 'false') return 0;
            bad("«$label» نامعتبر است");
            return null;
        case 'date':
            $s = is_string($v) ? trim($v) : '';
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) bad("«$label» تاریخ معتبر نیست");
            return $s;
        case 'datetime':
            $s = is_string($v) ? trim($v) : '';
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])
                || (int)$m[4] > 23 || (int)$m[5] > 59) bad("«$label» تاریخ و ساعت معتبر نیست");
            return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
        case 'enum':
            if (!is_string($v) || !in_array($v, (array)($col['values'] ?? []), true)) bad("«$label» نامعتبر است");
            return $v;
        case 'ref':
            if (!pb_is_uid($v)) bad("«$label» نامعتبر است");
            $rt = pb_entity_table((string)($col['ref'] ?? ''));
            if (pb_entity_def((string)($col['ref'] ?? '')) === null) bad("«$label» نامعتبر است");
            $st = $pdo->prepare("SELECT 1 FROM `$rt` WHERE customer_id=? AND uid=? AND deleted_at IS NULL");
            $st->execute([$cid, $v]);
            if (!$st->fetchColumn()) bad("«$label» پیدا نشد");
            return $v;
        case 'json':
            $max = (int)($col['max'] ?? 65535);
            $s = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            if (!is_string($s) || strlen($s) > $max || json_decode($s) === null && trim($s) !== 'null') bad("«$label» نامعتبر است");
            return $s;
    }
    bad("«$label» نامعتبر است");
    return null;
}

/**
 * Build DB values for save. Only keys present in $data are returned (plus defaults on create).
 * Unknown keys (cid, id, rev, ...) are ignored. Required check is applied to the merged result (existing + new).
 */
function pb_entity_validate(PDO $pdo, int $cid, array $def, array $data, ?array $existing): array {
    $out = [];
    foreach ($def['columns'] as $cn => $col) {
        $cn = (string)$cn;
        $label = (string)($col['label'] ?? $cn);
        if (array_key_exists($cn, $data)) {
            $out[$cn] = pb_entity_coerce($pdo, $cid, $cn, $col, $data[$cn]);
        } elseif ($existing === null && array_key_exists('default', $col)) {
            $out[$cn] = pb_entity_coerce($pdo, $cid, $cn, $col, $col['default']);
        }
        if (!empty($col['required'])) {
            $final = array_key_exists($cn, $out) ? $out[$cn] : ($existing[$cn] ?? null);
            if ($final === null || $final === '') bad("«$label» الزامی است");
        }
    }
    return $out;
}

/* ---------------- revisions ---------------- */

/** Atomically increments and returns the next revision of (customer, entity). */
function pb_entity_next_rev(PDO $pdo, int $cid, string $name): int {
    $pdo->prepare('INSERT INTO entity_revs (customer_id, entity, rev) VALUES (?, ?, LAST_INSERT_ID(1))
                   ON DUPLICATE KEY UPDATE rev = LAST_INSERT_ID(rev + 1)')->execute([$cid, $name]);
    return (int)$pdo->lastInsertId();
}

/** {entity: currentRev} for every entity visible to this edition (sent in bootstrap). */
function pb_entity_revs(PDO $pdo, int $cid, array $edition): array {
    $vis = [];
    foreach (pb_entity_registry() as $name => $def) {
        if (pb_entity_visible($def, $edition)) $vis[(string)$name] = 0;
    }
    if (!$vis) return [];
    try {
        $st = $pdo->prepare('SELECT entity, rev FROM entity_revs WHERE customer_id=?');
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) {
            if (isset($vis[$r['entity']])) $vis[$r['entity']] = (int)$r['rev'];
        }
    } catch (Throwable $e) {
        error_log('[entities.php] revs: ' . $e->getMessage());
    }
    return $vis;
}

/* ---------------- row formatting ---------------- */

function pb_entity_row_out(array $def, array $r): array {
    $o = [
        'uid' => (string)$r['uid'],
        'rev' => (int)$r['rev'],
        'deleted' => $r['deleted_at'] !== null,
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at'],
    ];
    foreach ($def['columns'] as $cn => $col) {
        $v = $r[$cn] ?? null;
        if ($v !== null) {
            switch ($col['type']) {
                case 'int':
                case 'bigint': $v = (int)$v; break;
                case 'bool':   $v = ((int)$v === 1); break;
                case 'decimal': $v = (float)$v; break;
                case 'json':   $v = json_decode((string)$v, true); break;
            }
        }
        $o[(string)$cn] = $v;
    }
    return $o;
}

/* ---------------- actions ---------------- */

function pb_entity_like(string $s): string {
    return '%' . strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}

/** Dispatcher called by app.php for entity.* actions. Always ends the request through out()/bad(). */
function pb_entity_action(string $action, array $p, PDO $pdo, array $customer, array $edition): void {
    $cid = (int)$customer['id']; // ONLY source of tenant id
    $name = (string)($p['entity'] ?? '');
    $def = pb_entity_def($name);
    if ($def === null || !pb_entity_visible($def, $edition)) {
        // unknown or dev-hidden entity -> 404; gated entity -> edition_required
        if ($def !== null && empty($def['dev'])) {
            $g = $def['gate'] ?? [];
            out(['error' => 'edition_required', 'need' => (($g['tier'] ?? 'green') === 'gold' && ($edition['tier'] ?? '') !== 'gold') ? 'gold' : 'fx',
                 'feature' => 'entity.' . $name, 'message' => 'این بخش در نسخهٔ فعلی شما فعال نیست'], 403);
        }
        bad('بخش درخواستی نامعتبر است', 404);
    }
    $perms = $def['perms'] ?? [];
    $t = pb_entity_table($name);
    $GLOBALS['PB_ENTITY_CUSTOMER'] = $customer; // S2: hooks (before_save/before_delete) need the acting staff; only set from the authenticated request

    if ($action === 'entity.list' || $action === 'entity.changes') {
        if (isset($perms['view']) && !pb_perm_check($customer, $perms['view'])) bad('دسترسی لازم را ندارید', 403);
        $limit = max(1, min(500, (int)($p['limit'] ?? 100)));
        $where = ['customer_id = ?'];
        $args = [$cid];
        if (!empty($def['row_filter']) && function_exists($def['row_filter'])) { // S2: row-level visibility (e.g. staff sees only own intakes)
            $rf = ($def['row_filter'])($pdo, $customer);
            if (is_array($rf) && !empty($rf[0])) {
                $where[] = '(' . (string)$rf[0] . ')';
                foreach ((array)($rf[1] ?? []) as $ra) $args[] = $ra;
            }
        }
        if ($action === 'entity.changes') {
            $since = max(0, (int)($p['since_rev'] ?? 0));
            $where[] = 'rev > ?';
            $args[] = $since;
            $order = 'rev ASC';
            $offset = 0;
        } else {
            if (empty($p['include_deleted'])) $where[] = 'deleted_at IS NULL';
            if (isset($p['since_rev'])) { $where[] = 'rev > ?'; $args[] = max(0, (int)$p['since_rev']); }
            $filters = is_array($p['filters'] ?? null) ? $p['filters'] : [];
            foreach ($filters as $fc => $fv) {
                $fc = (string)$fc;
                if (!isset($def['columns'][$fc])) bad('فیلتر نامعتبر است');
                $ft = (string)$def['columns'][$fc]['type'];
                if (in_array($ft, ['text', 'json'], true)) bad('فیلتر نامعتبر است');
                if (is_array($fv)) {
                    foreach (['gte' => '>=', 'lte' => '<=', 'gt' => '>', 'lt' => '<'] as $k => $op) {
                        if (array_key_exists($k, $fv)) {
                            $where[] = "`$fc` $op ?";
                            $args[] = pb_entity_coerce($pdo, $cid, $fc, $def['columns'][$fc], $fv[$k]);
                        }
                    }
                } else {
                    $cv = pb_entity_coerce($pdo, $cid, $fc, $def['columns'][$fc], $fv);
                    if ($cv === null) { $where[] = "`$fc` IS NULL"; } else { $where[] = "`$fc` = ?"; $args[] = $cv; }
                }
            }
            $q = trim((string)($p['q'] ?? ''));
            if ($q !== '' && !empty($def['search'])) {
                if (mb_strlen($q) > 100) bad('عبارت جست‌وجو بیش از حد طولانی است');
                $ors = [];
                foreach ($def['search'] as $sc) {
                    if (!isset($def['columns'][$sc])) continue;
                    $ors[] = "`$sc` LIKE ?";
                    $args[] = pb_entity_like($q);
                }
                if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
            }
            $ocol = (string)($p['order']['col'] ?? ($def['order'][0] ?? 'id'));
            $odir = strtoupper((string)($p['order']['dir'] ?? ($def['order'][1] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
            if (!isset($def['columns'][$ocol]) && !in_array($ocol, ['id', 'updated_at', 'created_at', 'rev'], true)) bad('ترتیب نامعتبر است');
            $order = "`$ocol` $odir, id $odir";
            $offset = max(0, (int)($p['offset'] ?? 0));
        }
        $sqlWhere = implode(' AND ', $where);
        $st = $pdo->prepare("SELECT * FROM `$t` WHERE $sqlWhere ORDER BY $order LIMIT $limit OFFSET $offset");
        $st->execute($args);
        $items = [];
        $maxRev = 0;
        foreach ($st->fetchAll() as $r) {
            $items[] = pb_entity_row_out($def, $r);
            if ((int)$r['rev'] > $maxRev) $maxRev = (int)$r['rev'];
        }
        $res = ['items' => $items];
        if ($action === 'entity.changes') {
            $res['max_rev'] = $maxRev;
            $res['has_more'] = count($items) === $limit;
        } else {
            $ct = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE $sqlWhere");
            $ct->execute($args);
            $res['total'] = (int)$ct->fetchColumn();
            $res['next_offset'] = $offset + count($items);
        }
        out($res);
    }

    if ($action === 'entity.save') {
        $data = is_array($p['data'] ?? null) ? $p['data'] : null;
        if ($data === null) bad('داده‌ای برای ذخیره ارسال نشده است');
        $uid = (string)($p['uid'] ?? '');
        if ($uid !== '' && !pb_is_uid($uid)) bad('شناسهٔ رکورد نامعتبر است');
        $pdo->beginTransaction();
        $existing = null;
        if ($uid !== '') {
            $st = $pdo->prepare("SELECT * FROM `$t` WHERE customer_id=? AND uid=? FOR UPDATE");
            $st->execute([$cid, $uid]);
            $existing = $st->fetch() ?: null;
        }
        $isNew = $existing === null;
        $need = $isNew ? 'create' : 'edit';
        if (isset($perms[$need]) && !pb_perm_check($customer, $perms[$need])) bad('دسترسی لازم را ندارید', 403);
        if (!$isNew && $existing['deleted_at'] !== null) bad('این رکورد حذف شده است', 409);
        if (!$isNew && isset($p['expected_rev']) && (int)$p['expected_rev'] !== (int)$existing['rev']) {
            bad('این رکورد در دستگاه دیگری تغییر کرده است؛ دوباره بارگذاری کنید', 409);
        }
        $vals = pb_entity_validate($pdo, $cid, $def, $data, $existing);
        if (!empty($def['before_save']) && function_exists($def['before_save'])) { $hook = $def['before_save']; $hook($pdo, $cid, $isNew, $existing, $vals); }
        $rev = pb_entity_next_rev($pdo, $cid, $name);
        if ($isNew) {
            if ($uid === '') $uid = pb_uuid4();
            $cols = array_merge(['uid', 'customer_id', 'rev', 'created_by'], array_keys($vals));
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $args = array_merge([$uid, $cid, $rev, null], array_values($vals));
            $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES ($ph)")->execute($args);
        } else {
            $sets = ['rev = ?'];
            $args = [$rev];
            foreach ($vals as $cn => $cv) { $sets[] = "`$cn` = ?"; $args[] = $cv; }
            $args[] = $cid; $args[] = $uid;
            $pdo->prepare("UPDATE `$t` SET " . implode(', ', $sets) . ' WHERE customer_id=? AND uid=?')->execute($args);
        }
        if (!empty($def['after_save']) && function_exists($def['after_save'])) { $hook = $def['after_save']; $hook($pdo, $cid, $uid, $isNew, $vals); }
        if (empty($def['audit_custom']) && function_exists('pb_audit')) pb_audit($pdo, $customer, $isNew ? 'create' : 'update', 'entity.' . $name, $uid);
        $st = $pdo->prepare("SELECT * FROM `$t` WHERE customer_id=? AND uid=?");
        $st->execute([$cid, $uid]);
        $row = $st->fetch();
        $pdo->commit();
        out(['item' => pb_entity_row_out($def, $row), 'created' => $isNew]);
    }

    if ($action === 'entity.delete') {
        $uid = (string)($p['uid'] ?? '');
        if (!pb_is_uid($uid)) bad('شناسهٔ رکورد نامعتبر است');
        if (isset($perms['delete']) && !pb_perm_check($customer, $perms['delete'])) bad('دسترسی لازم را ندارید', 403);
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM `$t` WHERE customer_id=? AND uid=? FOR UPDATE");
        $st->execute([$cid, $uid]);
        $row = $st->fetch();
        if (!$row) bad('رکورد پیدا نشد', 404);
        if ($row['deleted_at'] === null) {
            if (!empty($def['before_delete']) && function_exists($def['before_delete'])) { $hook = $def['before_delete']; $hook($pdo, $cid, $row); }
            $rev = pb_entity_next_rev($pdo, $cid, $name);
            $pdo->prepare("UPDATE `$t` SET deleted_at = NOW(), rev = ? WHERE customer_id=? AND uid=?")->execute([$rev, $cid, $uid]);
            if (empty($def['audit_custom']) && function_exists('pb_audit')) pb_audit($pdo, $customer, 'delete', 'entity.' . $name, $uid);
        }
        $pdo->commit();
        out(['ok' => true]);
    }

    bad('اکشن نامعتبر است', 404);
}

/* ---------------- backup (generic) ---------------- */

/** Export all entity rows of this customer for visible, non-dev entities: {entity: [row,...]} (raw DB rows without customer_id/id). */
function pb_entity_export(PDO $pdo, int $cid, array $edition): array {
    $out = [];
    foreach (pb_entity_registry() as $name => $def) {
        if (!empty($def['dev']) && !(defined('PB_ENTITY_DEV') && PB_ENTITY_DEV)) continue;
        if (!pb_entity_visible($def, $edition)) continue;
        $t = pb_entity_table((string)$name);
        $cols = array_merge(['uid', 'rev', 'deleted_at', 'created_by', 'created_at', 'updated_at'], array_map('strval', array_keys($def['columns'])));
        $st = $pdo->prepare('SELECT `' . implode('`,`', $cols) . "` FROM `$t` WHERE customer_id=?");
        $st->execute([$cid]);
        $out[(string)$name] = $st->fetchAll();
    }
    return $out;
}

/**
 * Validate the `entities` part of a backup BEFORE anything is deleted. Returns the clean plan {entity: [row,...]}.
 * Unknown/invisible entities are rejected; rows are re-validated with the same coercion as normal saves (refs are not
 * existence-checked here because rows are restored together; they are checked against the file's own uids).
 */
function pb_entity_import_validate(PDO $pdo, int $cid, $entities, array $edition): array {
    if (!is_array($entities)) bad('فایل پشتیبان معتبر نیست: بخش داده‌های تکمیلی نامعتبر است');
    $plan = [];
    $total = 0;
    foreach ($entities as $name => $rows) {
        $def = pb_entity_def((string)$name);
        if ($def === null || !pb_entity_visible($def, $edition)) bad('فایل پشتیبان معتبر نیست: بخش ناشناخته «' . mb_substr((string)$name, 0, 40) . '»');
        if (!is_array($rows)) bad('فایل پشتیبان معتبر نیست: «' . $name . '»');
        $uids = [];
        $plan[(string)$name] = [];
        foreach ($rows as $r) {
            if (++$total > 300000) bad('فایل پشتیبان بیش از حد بزرگ است');
            if (!is_array($r) || !pb_is_uid($r['uid'] ?? null) || isset($uids[$r['uid']])) bad('فایل پشتیبان معتبر نیست: شناسهٔ نامعتبر یا تکراری در «' . $name . '»');
            $uids[$r['uid']] = true;
            $clean = ['uid' => (string)$r['uid'], 'deleted_at' => null, 'created_by' => null, 'created_at' => null, 'updated_at' => null];
            if (!empty($r['deleted_at'])) $clean['deleted_at'] = pb_entity_coerce($pdo, $cid, 'deleted_at', ['type' => 'datetime', 'label' => 'تاریخ حذف'], $r['deleted_at']);
            foreach (['created_at', 'updated_at'] as $dc) {
                if (!empty($r[$dc])) $clean[$dc] = pb_entity_coerce($pdo, $cid, $dc, ['type' => 'datetime', 'label' => $dc], $r[$dc]);
            }
            foreach ($def['columns'] as $cn => $col) {
                $v = $r[$cn] ?? null;
                if ($col['type'] === 'ref') { // checked below against the file itself
                    if ($v !== null && $v !== '' && !pb_is_uid($v)) bad('فایل پشتیبان معتبر نیست: ارجاع نامعتبر در «' . $name . '»');
                    $clean[(string)$cn] = ($v === '' ? null : $v);
                    continue;
                }
                $clean[(string)$cn] = pb_entity_coerce($pdo, $cid, (string)$cn, $col, $v);
                if (!empty($col['required']) && $clean[(string)$cn] === null) bad('فایل پشتیبان معتبر نیست: مقدار الزامی خالی است در «' . $name . '»');
            }
            $plan[(string)$name][] = $clean;
        }
    }
    // ref targets must exist in the file (or be absent entity => reject)
    foreach ($plan as $name => $rows) {
        $def = pb_entity_def((string)$name);
        foreach ($def['columns'] as $cn => $col) {
            if ($col['type'] !== 'ref') continue;
            $target = (string)$col['ref'];
            $have = [];
            foreach (($plan[$target] ?? []) as $tr) $have[$tr['uid']] = true;
            foreach ($rows as $r) {
                if ($r[(string)$cn] !== null && !isset($have[$r[(string)$cn]])) bad('فایل پشتیبان معتبر نیست: ارجاع به رکورد ناموجود در «' . $name . '»');
            }
        }
    }
    return $plan;
}

/** Replace this customer's rows of the listed entities with the validated plan. Call INSIDE the import transaction. */
function pb_entity_import_apply(PDO $pdo, int $cid, array $plan): void {
    foreach ($plan as $name => $rows) {
        $def = pb_entity_def((string)$name);
        $t = pb_entity_table((string)$name);
        $pdo->prepare("DELETE FROM `$t` WHERE customer_id=?")->execute([$cid]);
        $cols = array_merge(['uid', 'customer_id', 'rev', 'deleted_at', 'created_at', 'updated_at'], array_map('strval', array_keys($def['columns'])));
        $ins = $pdo->prepare("INSERT INTO `$t` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        foreach ($rows as $r) {
            // fresh revision per restored row => every client re-pulls the restored data
            $rev = pb_entity_next_rev($pdo, $cid, (string)$name);
            $args = [$r['uid'], $cid, $rev, $r['deleted_at'], $r['created_at'] ?? date('Y-m-d H:i:s'), $r['updated_at'] ?? date('Y-m-d H:i:s')];
            foreach ($def['columns'] as $cn => $col) $args[] = $r[(string)$cn];
            $ins->execute($args);
        }
    }
}
