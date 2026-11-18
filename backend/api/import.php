<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S13.T13.2b — bulk import engine (server side of app/lib/import_engine.dart). Both tiers.
 * Action `import.commit` payload: {kind, import_batch_id, dry_run, all_or_nothing, on_duplicate?:'skip'|'update', rows:[{...fields,_row}]}
 * - The server validates EVERYTHING again (never trusts the client) and scopes every query by cid.
 * - dry_run=true: no writes at all; returns the per-row plan (create/update/skip/error).
 * - Idempotent: a committed (customer, import_batch_id) is stored in `import_batches`; re-sending it returns the stored
 *   result (replayed=true) without touching data. Rows are also idempotent by key (party name|phone, lot code, ...).
 * - all_or_nothing (default true): any bad row => nothing is written. Otherwise good rows commit, bad rows are reported.
 * - Existing keys are SKIPPED by default (on_duplicate='update' updates parties/ounces/warehouses; lots are never updated).
 * - Limits: PB_IMPORT_MAX_ROWS rows, PB_IMPORT_MAX_BYTES of row JSON.
 */
const PB_IMPORT_MAX_ROWS = 5000;
const PB_IMPORT_MAX_BYTES = 3000000;
const PB_IMPORT_DETAIL_MAX = 1000;

function pb_import_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS import_batches (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        batch_id VARCHAR(64) NOT NULL,
        kind VARCHAR(30) NOT NULL,
        all_or_nothing TINYINT(1) NOT NULL DEFAULT 1,
        total_rows INT NOT NULL DEFAULT 0,
        created_n INT NOT NULL DEFAULT 0,
        updated_n INT NOT NULL DEFAULT 0,
        skipped_n INT NOT NULL DEFAULT 0,
        failed_n INT NOT NULL DEFAULT 0,
        result MEDIUMTEXT NULL,
        created_at DATETIME NULL,
        UNIQUE KEY uq_import_batch (customer_id, batch_id),
        KEY idx_import_batch_c (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** kind => [perm key, field specs]. spec: [type text|num|enum|bool, required, max|enum values] */
function pb_import_kinds(): array {
    return [
        'parties' => ['parties.create', [
            'name' => ['text', true, 100], 'phone' => ['text', false, 30], 'address' => ['text', false, 300],
            'national_id' => ['text', false, 20], 'opening_balance' => ['num', false, 0],
            'opening_side' => ['enum', false, ['debtor', 'creditor']], 'note' => ['text', false, 500]]],
        'opening_stock' => ['purchases.create', [
            'code' => ['text', true, 40], 'category' => ['text', true, 100], 'type' => ['text', true, 100],
            'weight' => ['num', true, 0], 'price' => ['num', true, 0], 'ounce' => ['text', false, 40], 'warehouse' => ['text', false, 100]]],
        'categories_types' => ['catalog.manage', [
            'category' => ['text', true, 100], 'type' => ['text', true, 100]]],
        'ounces' => ['ounces.manage', [
            'code' => ['text', true, 40], 'from_val' => ['num', false, 0], 'to_val' => ['num', false, 0], 'note' => ['text', false, 250]]],
        'warehouses' => ['warehouses.manage', [
            'name' => ['text', true, 100], 'type' => ['enum', false, ['main', 'consignment', 'processing', 'virtual']],
            'address' => ['text', false, 250], 'active' => ['bool', false, 0]]],
        'grade_prices' => ['settings.manage', [ // S35.T35.4b: daily price table (date = Gregorian Y-m-d or Jalali Y/m/d; grade = key or label)
            'type' => ['text', true, 100], 'grade' => ['text', false, 40], 'price' => ['num', true, 0], 'date' => ['text', true, 30]]],
        'fx_rates' => ['settings.manage', [ // S31.T31.16 (fx plan only): daily currency rates; date = Gregorian Y-m-d or Jalali Y/m/d
            'ccy' => ['text', true, 8], 'date' => ['text', true, 30], 'rate' => ['num', true, 0], 'source' => ['text', false, 60]]],
        'accounts' => ['settings.manage', [ // S15.T15.2b (gold only; parents must precede children or already exist)
            'code' => ['text', true, 20], 'name' => ['text', true, 150], 'level' => ['num', true, 0],
            'parent_code' => ['text', false, 20], 'type' => ['enum', true, PB_ACC_TYPES],
            'nature' => ['enum', false, PB_ACC_NATURES], 'active' => ['bool', false, 0]]],
    ];
}

/** Returns [cleanRow, errors[]]. */
function pb_import_clean_row(array $specs, array $r): array {
    $o = [];
    $err = [];
    foreach ($specs as $k => $s) {
        $v = $r[$k] ?? null;
        if (is_string($v)) $v = trim($v);
        if ($v === null || $v === '') {
            if ($s[1]) $err[] = "«$k» الزامی است";
            continue;
        }
        if ($s[0] === 'text') {
            if (!is_scalar($v)) { $err[] = "«$k» نامعتبر است"; continue; }
            $v = trim((string)$v);
            if (mb_strlen($v) > (int)$s[2]) { $err[] = "«$k» بیش از {$s[2]} نویسه است"; continue; }
            $o[$k] = $v;
        } elseif ($s[0] === 'num') {
            if (!is_numeric($v)) { $err[] = "«$k» عدد معتبر نیست"; continue; }
            $n = (float)$v;
            if ($n < 0 || $n > 1e13) { $err[] = "«$k» خارج از محدوده است"; continue; }
            $o[$k] = $n;
        } elseif ($s[0] === 'enum') {
            if (!is_string($v) || !in_array($v, $s[2], true)) { $err[] = "«$k» مقدار مجاز نیست"; continue; }
            $o[$k] = $v;
        } else { // bool
            $o[$k] = ($v === true || $v === 1 || $v === '1') ? 1 : (($v === false || $v === 0 || $v === '0') ? 0 : null);
            if ($o[$k] === null) { $err[] = "«$k» نامعتبر است"; unset($o[$k]); }
        }
    }
    return [$o, $err];
}

function pb_import_key(string $kind, array $c): string {
    switch ($kind) {
        case 'parties': return 'p:' . mb_strtolower(partyKey($c['name'] ?? ''));
        case 'fx_rates': return 'fx:' . strtoupper((string)($c['ccy'] ?? '')) . '|' . preg_replace('/\D/', '', strtr((string)($c['date'] ?? ''), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
        case 'accounts': return 'a:' . (string)($c['code'] ?? '');
        case 'grade_prices': return 'gp:' . mb_strtolower((string)($c['type'] ?? '')) . '|' . mb_strtolower((string)($c['grade'] ?? '')) . '|' . preg_replace('/\D/', '', strtr((string)($c['date'] ?? ''), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'])); // S35.T35.4b
        case 'warehouses': return 'w:' . mb_strtolower((string)($c['name'] ?? ''));
        case 'categories_types': return 'ct:' . mb_strtolower((string)($c['category'] ?? '')) . '|' . mb_strtolower((string)($c['type'] ?? ''));
        default: return 'c:' . mb_strtolower((string)($c['code'] ?? ''));
    }
}

function pb_import_find(PDO $pdo, string $sql, array $args) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetch();
}

/** accounts row: same rules as pb_acc_save (numeric code, level 1-3, parent one level up with same type). Existing code => skip/update name+active. */
function pb_import_apply_account(PDO $pdo, int $cid, array $c, bool $write, bool $upd): array {
    static $planned = []; // code => [level, type, id|0] seen earlier in this request (so dry-run sees parents from the same file)
    static $defCodes = null;
    pb_acc_ensure($pdo);
    $code = (string)$c['code'];
    if (!preg_match('/^[0-9]{1,20}$/', $code)) throw new RuntimeException('کد حساب باید فقط عدد باشد');
    $level = (int)$c['level'];
    if ($level < 1 || $level > 3 || (float)$c['level'] !== (float)$level) throw new RuntimeException('سطح حساب باید ۱ تا ۳ باشد');
    $type = (string)$c['type'];
    $nature = (string)($c['nature'] ?? pb_acc_nature_of($type));
    $active = isset($c['active']) ? (int)$c['active'] : 1;
    if ($defCodes === null) {
        $n = pb_import_find($pdo, 'SELECT COUNT(*) AS n FROM accounts WHERE customer_id=?', [$cid]);
        $defCodes = [];
        if ((int)$n['n'] === 0) { // chart not seeded yet: default codes will exist after the lazy seed
            if ($write) pb_acc_seed($pdo, $cid);
            else foreach (pb_acc_default_tree() as $r) $defCodes[(string)$r[0]] = [(int)$r[2], (string)$r[4]];
        }
    }
    $ex = pb_import_find($pdo, 'SELECT id, name, active, is_system, system_role FROM accounts WHERE customer_id=? AND code=?', [$cid, $code]);
    if ($ex || isset($defCodes[$code])) {
        if (!$upd) return ['skipped', 'کد حساب از قبل وجود دارد'];
        if ($ex && $write) {
            if ($active === 0 && $ex['system_role'] !== null) throw new RuntimeException('حساب سیستمی دارای نقش را نمی‌توان غیرفعال کرد');
            $pdo->prepare('UPDATE accounts SET name=?, active=? WHERE id=? AND customer_id=?')->execute([$c['name'], $active, (int)$ex['id'], $cid]);
        }
        return ['updated', ''];
    }
    $parentId = null;
    if ($level > 1) {
        $pc = (string)($c['parent_code'] ?? '');
        if ($pc === '') throw new RuntimeException('«parent_code» برای سطح ' . $level . ' الزامی است');
        $pr = pb_import_find($pdo, 'SELECT id, level, type FROM accounts WHERE customer_id=? AND code=?', [$cid, $pc]);
        if ($pr) { $pl = (int)$pr['level']; $pt = (string)$pr['type']; $parentId = (int)$pr['id']; }
        elseif (isset($planned[$pc])) { $pl = $planned[$pc][0]; $pt = $planned[$pc][1]; $parentId = $planned[$pc][2] ?: null; }
        elseif (isset($defCodes[$pc])) { $pl = $defCodes[$pc][0]; $pt = $defCodes[$pc][1]; }
        else throw new RuntimeException('حساب والد «' . $pc . '» یافت نشد');
        if ($pl !== $level - 1) throw new RuntimeException('حساب والد باید یک سطح بالاتر باشد');
        if ($pt !== $type) throw new RuntimeException('نوع حساب باید با نوع حساب والد یکی باشد');
    }
    if ($write) {
        $pdo->prepare('INSERT INTO accounts (customer_id, code, name, level, parent_id, nature, type, is_system, system_role, active, created_at) VALUES (?,?,?,?,?,?,?,0,NULL,?,NOW())')
            ->execute([$cid, $code, $c['name'], $level, $parentId, $nature, $type, $active]);
        $planned[$code] = [$level, $type, (int)$pdo->lastInsertId()];
    } else {
        $planned[$code] = [$level, $type, 0];
    }
    return ['created', ''];
}

/** Applies (or, when $write=false, only plans) ONE row. Returns [status created|updated|skipped, message]. Throws on hard errors. */
/** S35.T35.4b: one price-table row. Same (type, grade, date) => skipped / updated (on_duplicate=update). */
function pb_import_apply_price(PDO $pdo, int $cid, array $c, bool $write, bool $upd): array {
    require_once __DIR__ . '/jdate.php';
    $d = preg_replace('/[\sT].*$/su', '', trim((string)$c['date'])); // Excel date cells arrive as «2026-10-29 00:00:00.000»
    $d = preg_replace('/\s+/', '', strtr($d, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '/'=>'-']));
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $d, $m)) throw new RuntimeException('تاریخ نامعتبر است');
    $y = (int)$m[1]; $mo = (int)$m[2]; $dd = (int)$m[3];
    $iso = $y < 1700 ? pb_j2g_str($y, $mo, $dd) : sprintf('%04d-%02d-%02d', $y, $mo, $dd);
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$dt || $dt->format('Y-m-d') !== $iso) throw new RuntimeException('تاریخ نامعتبر است');
    $gk = null;
    if (!empty($c['grade'])) {
        foreach (pb_grades_list($pdo, $cid) as $g) if ($g['key'] === $c['grade'] || $g['label'] === $c['grade']) { $gk = $g['key']; break; }
        if ($gk === null) throw new RuntimeException('درجهٔ «' . $c['grade'] . '» تعریف نشده است');
    }
    if ($c['price'] <= 0) throw new RuntimeException('«price» باید بزرگ‌تر از صفر باشد');
    $row = ['type_name' => $c['type'], 'grade_key' => $gk, 'price_per_kg' => (int)round($c['price']), 'price_date' => $iso];
    $ex = pb_import_find($pdo, 'SELECT id FROM grade_prices WHERE customer_id=? AND type_name=? AND ounce_uid IS NULL AND grade_key <=> ? AND price_date=? LIMIT 1', [$cid, $c['type'], $gk, $iso]);
    if ($ex && !$upd) return ['skipped', 'قیمت این روز از قبل وجود دارد'];
    if ($write) pb_gp_upsert($pdo, $cid, $row);
    return [$ex ? 'updated' : 'created', ''];
}

/** S31.T31.16: fx_rates row = same rules as fx.rates.save (active non-base currency, positive rate, real date; Jalali accepted). One rate per currency per day. */
function pb_import_apply_fxrate(PDO $pdo, int $cid, array $c, bool $write, bool $upd): array {
    require_once __DIR__ . '/jdate.php';
    pb_fx_seed_defaults($pdo, $cid);
    $ccy = strtoupper(trim((string)$c['ccy']));
    $cur = pb_fx_currency_by_code($pdo, $cid, $ccy);
    if (!$cur) throw new RuntimeException('ارز «' . $ccy . '» تعریف نشده است');
    if ((int)$cur['is_base'] === 1) throw new RuntimeException('برای ریال پایه نرخ ثبت نمی‌شود');
    if ((int)$cur['active'] !== 1) throw new RuntimeException('ارز «' . $ccy . '» غیرفعال است');
    $rate = pb_fx_validate_rate($c['rate']);
    if ($rate === null) throw new RuntimeException('نرخ باید عدد مثبت باشد');
    $d = preg_replace('/[\sT].*$/su', '', trim((string)$c['date']));
    $d = strtr($d, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '/'=>'-']);
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $d, $m)) throw new RuntimeException('تاریخ نامعتبر است');
    $y = (int)$m[1];
    $iso = $y < 1700 ? pb_fx_normalize_date(['rate_date_jalali' => sprintf('%04d-%d-%d', $y, (int)$m[2], (int)$m[3])]) : pb_fx_normalize_date(['rate_date' => sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3])]);
    if ($iso === null) throw new RuntimeException('تاریخ نامعتبر است');
    $ex = pb_import_find($pdo, 'SELECT id FROM fx_rates WHERE customer_id=? AND ccy=? AND rate_date=?', [$cid, $ccy, $iso]);
    if ($ex && !$upd) return ['skipped', 'نرخ این روز از قبل وجود دارد'];
    if ($write) {
        $pdo->prepare('INSERT INTO fx_rates (customer_id, ccy, rate_date, rate, source, created_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE rate=VALUES(rate), source=VALUES(source)')
            ->execute([$cid, $ccy, $iso, $rate, mb_substr((string)($c['source'] ?? 'import'), 0, 60)]);
    }
    return [$ex ? 'updated' : 'created', ''];
}

function pb_import_apply(PDO $pdo, int $cid, string $kind, array $c, bool $write, bool $upd): array {
    if ($kind === 'parties') {
        $name = partyKey($c['name']);
        if ($name === '' || mb_strlen($name) > 100) throw new RuntimeException('نام نامعتبر است');
        $ex = pb_import_find($pdo, 'SELECT id FROM parties WHERE customer_id=? AND name=?', [$cid, $name]);
        if (!$ex && !empty($c['phone'])) $ex = pb_import_find($pdo, 'SELECT id FROM parties WHERE customer_id=? AND phone=? ORDER BY id LIMIT 1', [$cid, $c['phone']]);
        if ($ex) {
            if (!$upd) return ['skipped', 'طرف‌حساب از قبل وجود دارد'];
            if ($write) {
                $set = []; $a = [];
                foreach (['phone', 'address', 'national_id', 'note'] as $f) if (isset($c[$f])) { $set[] = "$f=?"; $a[] = $c[$f]; }
                if (isset($c['opening_balance'])) { $set[] = 'opening_balance=?'; $a[] = (int)round($c['opening_balance']); }
                if (isset($c['opening_side'])) { $set[] = 'opening_side=?'; $a[] = $c['opening_side']; }
                if ($set) { $a[] = (int)$ex['id']; $a[] = $cid; $pdo->prepare('UPDATE parties SET ' . implode(', ', $set) . ' WHERE id=? AND customer_id=?')->execute($a); }
            }
            return ['updated', ''];
        }
        if ($write) {
            $pdo->prepare('INSERT INTO parties (customer_id, name, types, phone, address, national_id, opening_balance, opening_side, active, note, created_at) VALUES (?,?,?,?,?,?,?,?,1,?,NOW())')
                ->execute([$cid, $name, '[]', $c['phone'] ?? null, $c['address'] ?? null, $c['national_id'] ?? null,
                    (int)round($c['opening_balance'] ?? 0), $c['opening_side'] ?? 'debtor', $c['note'] ?? null]);
            pb_taf_hook($pdo, $cid, 'party', $name, $name);
        }
        return ['created', ''];
    }
    if ($kind === 'accounts') return pb_import_apply_account($pdo, $cid, $c, $write, $upd);
    if ($kind === 'fx_rates') return pb_import_apply_fxrate($pdo, $cid, $c, $write, $upd);
    if ($kind === 'grade_prices') return pb_import_apply_price($pdo, $cid, $c, $write, $upd);
    if ($kind === 'categories_types') {
        $cat = pb_import_find($pdo, 'SELECT id FROM categories WHERE customer_id=? AND name=? LIMIT 1', [$cid, $c['category']]);
        $typ = $cat ? pb_import_find($pdo, 'SELECT id FROM types WHERE customer_id=? AND category_id=? AND name=? LIMIT 1', [$cid, (int)$cat['id'], $c['type']]) : false;
        if ($cat && $typ) return ['skipped', 'از قبل وجود دارد'];
        if ($write) {
            $catId = $cat ? (int)$cat['id'] : 0;
            if (!$catId) {
                $pdo->prepare('INSERT INTO categories (customer_id, name, preset_key) VALUES (?,?,NULL)')->execute([$cid, $c['category']]);
                $catId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('INSERT INTO types (customer_id, category_id, name) VALUES (?,?,?)')->execute([$cid, $catId, $c['type']]);
        }
        return ['created', ''];
    }
    if ($kind === 'ounces') {
        if (isset($c['from_val'], $c['to_val']) && $c['from_val'] > $c['to_val']) throw new RuntimeException('«از» نمی‌تواند بزرگ‌تر از «تا» باشد');
        $ex = pb_import_find($pdo, 'SELECT uid FROM ent_ounces WHERE customer_id=? AND code=? AND deleted_at IS NULL LIMIT 1', [$cid, $c['code']]);
        if ($ex) {
            if (!$upd) return ['skipped', 'درجه از قبل وجود دارد'];
            if ($write) {
                $set = ['rev=?']; $a = [pb_entity_next_rev($pdo, $cid, 'ounces')];
                foreach (['from_val', 'to_val', 'note'] as $f) if (isset($c[$f])) { $set[] = "$f=?"; $a[] = $c[$f]; }
                $a[] = $cid; $a[] = (string)$ex['uid'];
                $pdo->prepare('UPDATE ent_ounces SET ' . implode(', ', $set) . ' WHERE customer_id=? AND uid=?')->execute($a);
            }
            return ['updated', ''];
        }
        if ($write) {
            $pdo->prepare('INSERT INTO ent_ounces (uid, customer_id, rev, created_by, code, from_val, to_val, note) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([pb_uuid4(), $cid, pb_entity_next_rev($pdo, $cid, 'ounces'), null, $c['code'], $c['from_val'] ?? null, $c['to_val'] ?? null, $c['note'] ?? null]);
        }
        return ['created', ''];
    }
    if ($kind === 'warehouses') {
        $ex = pb_import_find($pdo, 'SELECT uid FROM ent_warehouses WHERE customer_id=? AND name=? AND deleted_at IS NULL LIMIT 1', [$cid, $c['name']]);
        if ($ex) {
            if (!$upd) return ['skipped', 'انبار از قبل وجود دارد'];
            if ($write) {
                $set = ['rev=?']; $a = [pb_entity_next_rev($pdo, $cid, 'warehouses')];
                foreach (['type', 'address', 'active'] as $f) if (isset($c[$f])) { $set[] = "$f=?"; $a[] = $c[$f]; }
                $a[] = $cid; $a[] = (string)$ex['uid'];
                $pdo->prepare('UPDATE ent_warehouses SET ' . implode(', ', $set) . ' WHERE customer_id=? AND uid=?')->execute($a);
            }
            return ['updated', ''];
        }
        if ($write) {
            $pdo->prepare('INSERT INTO ent_warehouses (uid, customer_id, rev, created_by, name, type, active, address, is_default) VALUES (?,?,?,?,?,?,?,?,0)')
                ->execute([pb_uuid4(), $cid, pb_entity_next_rev($pdo, $cid, 'warehouses'), null, $c['name'], $c['type'] ?? 'main', $c['active'] ?? 1, $c['address'] ?? null]);
        }
        return ['created', ''];
    }
    // opening_stock: lots are never updated by import (stock-sensitive); existing code => skipped
    if ($c['weight'] <= 0) throw new RuntimeException('«weight» باید بزرگ‌تر از صفر باشد');
    if (pb_import_find($pdo, 'SELECT id FROM purchases WHERE customer_id=? AND code=? LIMIT 1', [$cid, $c['code']])) return ['skipped', 'کد لات از قبل وجود دارد'];
    $ounceUid = null;
    if (!empty($c['ounce'])) {
        $o = pb_import_find($pdo, 'SELECT uid FROM ent_ounces WHERE customer_id=? AND code=? AND deleted_at IS NULL LIMIT 1', [$cid, $c['ounce']]);
        if (!$o) throw new RuntimeException('درجهٔ اونس «' . $c['ounce'] . '» تعریف نشده است');
        $ounceUid = (string)$o['uid'];
    }
    $whUid = null;
    if (!empty($c['warehouse'])) {
        $w = pb_import_find($pdo, 'SELECT uid, active FROM ent_warehouses WHERE customer_id=? AND name=? AND deleted_at IS NULL LIMIT 1', [$cid, $c['warehouse']]);
        if (!$w) throw new RuntimeException('انبار «' . $c['warehouse'] . '» تعریف نشده است');
        if ((int)$w['active'] !== 1) throw new RuntimeException('انبار «' . $c['warehouse'] . '» غیرفعال است');
        $whUid = (string)$w['uid'];
    }
    if ($write) {
        $cat = pb_import_find($pdo, 'SELECT id FROM categories WHERE customer_id=? AND name=? LIMIT 1', [$cid, $c['category']]);
        $catId = $cat ? (int)$cat['id'] : 0;
        if (!$catId) {
            $pdo->prepare('INSERT INTO categories (customer_id, name, preset_key) VALUES (?,?,NULL)')->execute([$cid, $c['category']]);
            $catId = (int)$pdo->lastInsertId();
        }
        if (!pb_import_find($pdo, 'SELECT id FROM types WHERE customer_id=? AND category_id=? AND name=? LIMIT 1', [$cid, $catId, $c['type']])) {
            $pdo->prepare('INSERT INTO types (customer_id, category_id, name) VALUES (?,?,?)')->execute([$cid, $catId, $c['type']]);
        }
        if ($whUid === null) $whUid = pb_wh_default_uid($pdo, $cid);
        $w = round((float)$c['weight'], 3);
        $pdo->prepare('INSERT INTO purchases (customer_id, code, category_id, type_name, date, price, total_weight, small_sack_count, large_sack_count,
            small_sacks_remaining, large_sacks_remaining, net_weight, remaining, note, is_mix, warehouse_uid, ounce_uid) VALUES (?,?,?,?,?,?,?,0,0,0,0,?,?,?,0,?,?)')
            ->execute([$cid, $c['code'], $catId, $c['type'], date('Y-m-d'), $c['price'], $w, $w, $w, 'موجودی اول دوره (ایمپورت)', $whUid, $ounceUid]);
        pb_stock_post_doc($pdo, $cid, 'purchase', (int)$pdo->lastInsertId());
    }
    return ['created', ''];
}

function pb_import_commit(PDO $pdo, int $cid, array $customer, array $p): void {
    $kinds = pb_import_kinds();
    $kind = (string)($p['kind'] ?? '');
    if (!isset($kinds[$kind])) bad('نوع ایمپورت نامعتبر است');
    if ($kind === 'accounts' && !pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    if ($kind === 'fx_rates' && pb_plan_of($customer) !== 'fx') out(['error' => 'edition_required', 'need' => 'fx'], 403); // S31.T31.16
    if (!pb_perm_check($customer, $kinds[$kind][0])) bad('دسترسی این عملیات را ندارید', 403);
    $batchId = (string)($p['import_batch_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $batchId)) bad('شناسهٔ دستهٔ ایمپورت نامعتبر است');
    $dry = !array_key_exists('dry_run', $p) || !($p['dry_run'] === false || $p['dry_run'] === 0 || $p['dry_run'] === '0'); // default = dry
    $aon = !array_key_exists('all_or_nothing', $p) || !($p['all_or_nothing'] === false || $p['all_or_nothing'] === 0 || $p['all_or_nothing'] === '0');
    $upd = (($p['on_duplicate'] ?? 'skip') === 'update');
    $rows = $p['rows'] ?? null;
    if (!is_array($rows) || count($rows) < 1) bad('هیچ ردیفی برای ایمپورت ارسال نشده است');
    if (count($rows) > PB_IMPORT_MAX_ROWS) bad('حداکثر ' . PB_IMPORT_MAX_ROWS . ' ردیف در هر دسته مجاز است', 413);
    $js = json_encode($rows, JSON_UNESCAPED_UNICODE);
    if ($js === false || strlen($js) > PB_IMPORT_MAX_BYTES) bad('حجم داده بیش از حد مجاز است', 413);

    pb_import_ensure($pdo);
    if (!$dry) { // idempotent replay
        $old = pb_import_find($pdo, 'SELECT result FROM import_batches WHERE customer_id=? AND batch_id=?', [$cid, $batchId]);
        if ($old) {
            $res = json_decode((string)$old['result'], true);
            if (is_array($res)) { $res['replayed'] = true; out($res); }
            bad('این دسته قبلاً ثبت شده است', 409);
        }
    }

    $specs = $kinds[$kind][1];
    $seen = [];
    $items = []; // [rowNo, clean|null, errors[]]
    foreach ($rows as $i => $r) {
        if (!is_array($r)) { $items[] = [$i + 2, null, ['ردیف نامعتبر است']]; continue; }
        $no = (int)($r['_row'] ?? ($i + 2));
        [$c, $err] = pb_import_clean_row($specs, $r);
        if (!$err) {
            $key = pb_import_key($kind, $c);
            if (isset($seen[$key])) $err[] = 'کلید تکراری در فایل (ردیف ' . $seen[$key] . ')';
            else $seen[$key] = $no;
        }
        $items[] = [$no, $err ? null : $c, $err];
    }
    $nBad = 0;
    foreach ($items as $it) if ($it[2]) $nBad++;

    $detail = [];
    $cnt = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    $note = function (int $no, string $st, string $msg) use (&$detail): void {
        if (count($detail) < PB_IMPORT_DETAIL_MAX && ($st === 'error' || $msg !== '' || count($detail) < 200)) $detail[] = ['row' => $no, 'status' => $st, 'message' => $msg];
    };
    $write = !$dry && !($aon && $nBad > 0);
    $committed = false;
    if ($write) $pdo->beginTransaction();
    try {
        foreach ($items as $it) {
            [$no, $c, $err] = $it;
            if ($err) { $cnt['failed']++; $note($no, 'error', implode('؛ ', $err)); continue; }
            if ($write) $pdo->exec('SAVEPOINT imp_row');
            try {
                [$st, $msg] = pb_import_apply($pdo, $cid, $kind, $c, $write, $upd);
                $cnt[$st]++;
                $note($no, $st, $msg);
                if ($write) $pdo->exec('RELEASE SAVEPOINT imp_row');
            } catch (RuntimeException | PDOException $e) {
                if ($write) $pdo->exec('ROLLBACK TO SAVEPOINT imp_row');
                $cnt['failed']++;
                $nBad++;
                $note($no, 'error', $e instanceof PDOException ? 'خطای ذخیره‌سازی' : $e->getMessage());
            }
        }
        if ($write && $aon && $nBad > 0) { // a row failed while applying: undo everything
            $pdo->rollBack();
            $write = false;
        }
        if ($write) {
            $res = ['ok' => true, 'dry_run' => false, 'committed' => true, 'replayed' => false, 'batch_id' => $batchId, 'kind' => $kind,
                'all_or_nothing' => $aon, 'total' => count($items)] + $cnt + ['rows' => $detail];
            $pdo->prepare('INSERT INTO import_batches (customer_id, batch_id, kind, all_or_nothing, total_rows, created_n, updated_n, skipped_n, failed_n, result, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$cid, $batchId, $kind, $aon ? 1 : 0, count($items), $cnt['created'], $cnt['updated'], $cnt['skipped'], $cnt['failed'],
                    json_encode($res, JSON_UNESCAPED_UNICODE)]);
            $pdo->commit();
            $committed = true;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[import] ' . $e->getMessage());
        bad('ایمپورت انجام نشد؛ هیچ داده‌ای ثبت نشد', 500);
    }
    if ($committed) {
        pb_audit($pdo, $customer, 'import.commit', 'import', $batchId, "ایمپورت $kind: {$cnt['created']} جدید، {$cnt['updated']} ویرایش، {$cnt['skipped']} ردشده");
    }
    if (!$committed && !$dry && $aon) { // nothing written (bad rows)
        foreach (['created', 'updated', 'skipped'] as $k) $cnt[$k] = 0;
    }
    out(['ok' => $committed || ($dry && $nBad === 0), 'dry_run' => $dry, 'committed' => $committed, 'replayed' => false, 'batch_id' => $batchId,
        'kind' => $kind, 'all_or_nothing' => $aon, 'total' => count($items)] + $cnt + ['rows' => $detail]);
}
