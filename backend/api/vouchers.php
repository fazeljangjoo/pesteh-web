<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S16.T16.1 — vouchers (اسناد حسابداری, GOLD). Model + numbering + manual CRUD + copy.
 * - vouchers(header) + voucher_lines; amounts are INTEGER rial (BIGINT). Statuses: draft | temporary | final.
 * - THE single write path is pb_voucher_write(): used by manual actions here and by the posting engine (T16.2).
 *   It enforces: >=2 lines, one-sided non-negative integer lines, sum(debit)=sum(credit) (re-verified in DB inside the
 *   transaction), accounts active + leaf (no children) + level>=2, tafsili active, date inside an OPEN fiscal year.
 * - Numbering: per fiscal year, sequential, assigned when a voucher leaves 'draft' (drafts have number NULL),
 *   so numbered vouchers have no gaps; a numbered voucher can only be deleted when it holds the highest number (and never when final).
 * - Auto vouchers (source_type != 'manual') are not editable/deletable through manual actions (engine owns them).
 * Actions: vouchers.list | vouchers.get | vouchers.save | vouchers.set_status | vouchers.delete | vouchers.copy
 */
require_once __DIR__ . '/fiscal.php';
require_once __DIR__ . '/accounts.php';

if (!function_exists('pb_ddl_tables_exist')) {
    /** True when every table exists. SHOW TABLES is not DDL: unlike CREATE TABLE IF NOT EXISTS it never ends an open transaction (implicit commit). */
    function pb_ddl_tables_exist(PDO $pdo, array $tables): bool {
        foreach ($tables as $t) {
            $st = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t));
            if (!$st || $st->fetchColumn() === false) return false;
        }
        return true;
    }
}

const PB_VOUCHER_STATUSES = ['draft', 'temporary', 'final'];
const PB_VOUCHER_MAX_AMOUNT = 9000000000000000; // < 2^63, safe for sums of many lines

function pb_voucher_ensure(PDO $pdo): void {
    static $ok = false; // hotfix: DDL inside a posting transaction would implicitly commit it ("There is no active transaction")
    if ($ok) return;
    if (pb_ddl_tables_exist($pdo, ['vouchers', 'voucher_lines'])) { $ok = true; return; }
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS vouchers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        fiscal_year_id INT UNSIGNED NOT NULL,
        number INT UNSIGNED NULL,
        voucher_date DATE NOT NULL,
        description VARCHAR(500) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'draft',
        source_type VARCHAR(30) NOT NULL DEFAULT 'manual',
        source_id VARCHAR(64) NULL,
        total BIGINT NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_voucher_no (customer_id, fiscal_year_id, number),
        KEY idx_voucher_date (customer_id, voucher_date),
        KEY idx_voucher_src (customer_id, source_type, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS voucher_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        voucher_id INT UNSIGNED NOT NULL,
        line_no SMALLINT UNSIGNED NOT NULL,
        account_id INT UNSIGNED NOT NULL,
        tafsili_id INT UNSIGNED NULL,
        cost_center_id INT UNSIGNED NULL,
        debit BIGINT NOT NULL DEFAULT 0,
        credit BIGINT NOT NULL DEFAULT 0,
        description VARCHAR(500) NULL,
        ccy VARCHAR(8) NULL,
        ccy_amount DECIMAL(20,4) NULL,
        rate DECIMAL(20,6) NULL,
        KEY idx_vl_voucher (voucher_id),
        KEY idx_vl_account (customer_id, account_id),
        KEY idx_vl_tafsili (customer_id, tafsili_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Strict integer-rial parser: int, or string of digits (no fraction). Never floats with a fraction. */
function pb_voucher_int($v, string $label): int {
    if ($v === null || $v === '') return 0;
    if (is_int($v)) $n = $v;
    elseif (is_float($v)) {
        if ($v != floor($v)) bad("«$label» باید عدد صحیح (ریال) باشد");
        $n = (int)$v;
    } elseif (is_string($v) && preg_match('/^-?[0-9]{1,18}$/', trim($v))) $n = (int)trim($v);
    else bad("«$label» نامعتبر است");
    if ($n < 0 || $n > PB_VOUCHER_MAX_AMOUNT) bad("«$label» خارج از محدوده است");
    return $n;
}

function pb_voucher_date($v): string {
    $d = trim((string)$v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) bad('تاریخ سند نامعتبر است');
    return $d;
}

/** Fiscal year row containing $date (any status) or null. */
function pb_voucher_fy_for(PDO $pdo, int $cid, string $date): ?array {
    $st = $pdo->prepare('SELECT id, status FROM fiscal_years WHERE customer_id=? AND start_date<=? AND end_date>=? LIMIT 1');
    $st->execute([$cid, $date, $date]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'status' => (string)$r['status']] : null;
}

/**
 * Validates + normalizes lines. Returns [lines, totalDebit]. Calls bad() on any violation.
 * Each output line: account_id, tafsili_id|null, cost_center_id|null, debit, credit, description, ccy, ccy_amount, rate.
 */
function pb_voucher_check_lines(PDO $pdo, int $cid, $rawLines, int $maxLines = 500): array {
    if (!is_array($rawLines) || count($rawLines) < 2) bad('سند باید حداقل دو ردیف داشته باشد');
    if (count($rawLines) > $maxLines) bad('تعداد ردیف‌های سند بیش از حد مجاز است');
    $lines = [];
    $sumD = 0;
    $sumC = 0;
    $accIds = [];
    $tafIds = [];
    $ccIds = [];
    $i = 0;
    foreach ($rawLines as $rl) {
        $i++;
        if (!is_array($rl)) bad("ردیف $i نامعتبر است");
        $acc = (int)($rl['account_id'] ?? 0);
        if ($acc <= 0) bad("حساب ردیف $i انتخاب نشده است");
        $d = pb_voucher_int($rl['debit'] ?? 0, "بدهکار ردیف $i");
        $c = pb_voucher_int($rl['credit'] ?? 0, "بستانکار ردیف $i");
        if (($d > 0) === ($c > 0)) bad("ردیف $i باید فقط یکی از بدهکار یا بستانکار را مقدار داشته باشد");
        $sumD += $d;
        $sumC += $c;
        if ($sumD > PB_VOUCHER_MAX_AMOUNT * 2 || $sumC > PB_VOUCHER_MAX_AMOUNT * 2) bad('جمع سند خارج از محدوده است');
        $taf = (int)($rl['tafsili_id'] ?? 0);
        $cc = (int)($rl['cost_center_id'] ?? 0);
        $ccy = isset($rl['ccy']) && $rl['ccy'] !== '' ? strtoupper(trim((string)$rl['ccy'])) : null;
        if ($ccy !== null && !preg_match('/^[A-Z0-9]{2,8}$/', $ccy)) bad("ارز ردیف $i نامعتبر است");
        $ccyAmt = null;
        $rate = null;
        if ($ccy !== null) {
            foreach (['ccy_amount', 'rate'] as $f) {
                $v = $rl[$f] ?? null;
                if ($v === null || $v === '') continue;
                if (!is_numeric($v) || (float)$v < 0) bad("مقدار ارزی ردیف $i نامعتبر است");
                if ($f === 'ccy_amount') $ccyAmt = (string)round((float)$v, 4); else $rate = (string)round((float)$v, 6);
            }
        }
        $lines[] = [
            'account_id' => $acc, 'tafsili_id' => $taf > 0 ? $taf : null, 'cost_center_id' => $cc > 0 ? $cc : null,
            'debit' => $d, 'credit' => $c, 'description' => mb_substr(trim((string)($rl['description'] ?? '')), 0, 500),
            'ccy' => $ccy, 'ccy_amount' => $ccyAmt, 'rate' => $rate,
        ];
        $accIds[$acc] = true;
        if ($taf > 0) $tafIds[$taf] = true;
        if ($cc > 0) $ccIds[$cc] = true;
    }
    if ($sumD !== $sumC) bad('سند تراز نیست: جمع بدهکار با جمع بستانکار برابر نیست');
    if ($sumD <= 0) bad('جمع سند باید بیشتر از صفر باشد');

    $ids = array_keys($accIds);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, code, level, active FROM accounts WHERE customer_id=? AND id IN ($ph)");
    $st->execute(array_merge([$cid], $ids));
    $accs = [];
    foreach ($st->fetchAll() as $r) $accs[(int)$r['id']] = $r;
    $st = $pdo->prepare("SELECT DISTINCT parent_id FROM accounts WHERE customer_id=? AND parent_id IN ($ph)");
    $st->execute(array_merge([$cid], $ids));
    $hasKids = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($ids as $aid) {
        if (!isset($accs[$aid])) bad('حساب انتخاب‌شده وجود ندارد');
        $a = $accs[$aid];
        if ((int)$a['active'] !== 1) bad('حساب ' . $a['code'] . ' غیرفعال است');
        if ((int)$a['level'] < 2 || isset($hasKids[$aid])) bad('روی حساب ' . $a['code'] . ' سند ثبت نمی‌شود؛ حساب سطح پایین‌تر (معین) را انتخاب کنید');
    }
    if ($tafIds) {
        $t = array_keys($tafIds);
        $ph = implode(',', array_fill(0, count($t), '?'));
        $st = $pdo->prepare("SELECT id, active FROM tafsili WHERE customer_id=? AND id IN ($ph)");
        $st->execute(array_merge([$cid], $t));
        $found = [];
        foreach ($st->fetchAll() as $r) $found[(int)$r['id']] = (int)$r['active'];
        foreach ($t as $tid) if (!isset($found[$tid]) || $found[$tid] !== 1) bad('تفصیلی انتخاب‌شده وجود ندارد یا غیرفعال است');
    }
    if ($ccIds) {
        $t = array_keys($ccIds);
        $ph = implode(',', array_fill(0, count($t), '?'));
        try {
            $st = $pdo->prepare("SELECT id FROM ent_cost_centers WHERE customer_id=? AND deleted_at IS NULL AND id IN ($ph)");
            $st->execute(array_merge([$cid], $t));
            if (count($st->fetchAll(PDO::FETCH_COLUMN)) !== count($t)) bad('مرکز هزینهٔ انتخاب‌شده وجود ندارد');
        } catch (PDOException $e) {
            bad('مرکز هزینه در دسترس نیست');
        }
    }
    return [$lines, $sumD];
}

/**
 * THE write path for every voucher (manual + posting engine).
 * $h: date (Y-m-d), description, status, source_type, source_id. $existingId = 0 creates; >0 replaces header+lines (number kept).
 * Returns voucher id. Fiscal year must be OPEN (pb_fy_guard) and accessible (pb_fy_require).
 */
function pb_voucher_write(PDO $pdo, array $customer, array $h, $rawLines, int $existingId = 0): int {
    $cid = (int)$customer['id'];
    pb_voucher_ensure($pdo);
    $date = pb_voucher_date($h['date'] ?? '');
    $status = (string)($h['status'] ?? 'draft');
    if (!in_array($status, PB_VOUCHER_STATUSES, true)) bad('وضعیت سند نامعتبر است');
    $srcType = mb_substr((string)($h['source_type'] ?? 'manual'), 0, 30);
    $srcId = isset($h['source_id']) && $h['source_id'] !== '' ? mb_substr((string)$h['source_id'], 0, 64) : null;
    $desc = mb_substr(trim((string)($h['description'] ?? '')), 0, 500);

    pb_fy_guard($pdo, $cid, $customer, $date); // gold: date must be in an OPEN, accessible year
    $fy = pb_voucher_fy_for($pdo, $cid, $date);
    if (!$fy) bad('تاریخ سند در هیچ سال مالی تعریف‌شده قرار ندارد');
    pb_fy_require($customer, $fy['id']);

    $old = null;
    if ($existingId > 0) {
        $st = $pdo->prepare('SELECT * FROM vouchers WHERE id=? AND customer_id=?');
        $st->execute([$existingId, $cid]);
        $old = $st->fetch();
        if (!$old) bad('سند یافت نشد', 404);
        pb_fy_guard($pdo, $cid, $customer, (string)$old['voucher_date']); // the stored date's year must also be open
        if ((int)$old['fiscal_year_id'] !== $fy['id'] && $old['number'] !== null) bad('تاریخ سند شمارهٔ‌دار باید در همان سال مالی بماند');
        if ($old['status'] !== 'draft' && $status === 'draft') bad('سند شمارهٔ‌دار به پیش‌نویس برنمی‌گردد');
    }
    [$lines, $total] = pb_voucher_check_lines($pdo, $cid, $rawLines, max(500, min(50000, (int)($h['max_lines'] ?? 500)))); // fy_open may carry many party lines

    $uid = (int)($customer['_staff_id'] ?? 0);
    $own = !$pdo->inTransaction(); // posting hooks call us inside the document's transaction: join it instead of nesting
    $attempts = 0;
    while (true) {
        $attempts++;
        if ($own) $pdo->beginTransaction();
        try {
            if ($old) {
                $id = (int)$old['id'];
                $number = $old['number'] === null ? null : (int)$old['number'];
                $pdo->prepare('DELETE FROM voucher_lines WHERE voucher_id=? AND customer_id=?')->execute([$id, $cid]);
                if ($number === null && $status !== 'draft') $number = pb_voucher_next_number($pdo, $cid, $fy['id']);
                $pdo->prepare('UPDATE vouchers SET fiscal_year_id=?, number=?, voucher_date=?, description=?, status=?, total=?, updated_at=NOW() WHERE id=? AND customer_id=?')
                    ->execute([$fy['id'], $number, $date, $desc, $status, $total, $id, $cid]);
            } else {
                $number = $status === 'draft' ? null : pb_voucher_next_number($pdo, $cid, $fy['id']);
                $pdo->prepare('INSERT INTO vouchers (customer_id, fiscal_year_id, number, voucher_date, description, status, source_type, source_id, total, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
                    ->execute([$cid, $fy['id'], $number, $date, $desc, $status, $srcType, $srcId, $total, $uid]);
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO voucher_lines (customer_id, voucher_id, line_no, account_id, tafsili_id, cost_center_id, debit, credit, description, ccy, ccy_amount, rate) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $n = 0;
            foreach ($lines as $l) {
                $n++;
                $ins->execute([$cid, $id, $n, $l['account_id'], $l['tafsili_id'], $l['cost_center_id'], $l['debit'], $l['credit'], $l['description'], $l['ccy'], $l['ccy_amount'], $l['rate']]);
            }
            // Final safety net: re-verify the balance from the database itself.
            $chk = $pdo->prepare('SELECT COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c, COUNT(*) AS n FROM voucher_lines WHERE voucher_id=? AND customer_id=?');
            $chk->execute([$id, $cid]);
            $r = $chk->fetch();
            if ((string)$r['d'] !== (string)$r['c'] || (int)$r['n'] < 2 || (string)$r['d'] !== (string)$total) {
                if ($own) $pdo->rollBack(); // joined outer transaction: bad() exits and PDO rolls it back
                bad('سند تراز نیست و ذخیره نشد');
            }
            if ($own) $pdo->commit();
            return $id;
        } catch (PDOException $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            if ($own && $attempts < 3 && (string)$e->getCode() === '23000') continue; // duplicate number race -> retry
            error_log('[voucher] ' . $e->getMessage());
            bad('ذخیرهٔ سند ناموفق بود', 500);
        }
    }
}

/** Next sequential number inside the fiscal year. Call inside a transaction. */
function pb_voucher_next_number(PDO $pdo, int $cid, int $fyId): int {
    $st = $pdo->prepare('SELECT COALESCE(MAX(number),0) FROM vouchers WHERE customer_id=? AND fiscal_year_id=? FOR UPDATE');
    $st->execute([$cid, $fyId]);
    return (int)$st->fetchColumn() + 1;
}

function pb_voucher_load(PDO $pdo, int $cid, array $customer, int $id): array {
    $st = $pdo->prepare('SELECT * FROM vouchers WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $v = $st->fetch();
    if (!$v) bad('سند یافت نشد', 404);
    pb_fy_require($customer, (int)$v['fiscal_year_id']);
    return $v;
}

function pb_voucher_head_out(array $v): array {
    return [
        'id' => (int)$v['id'], 'fiscal_year_id' => (int)$v['fiscal_year_id'], 'number' => $v['number'] === null ? null : (int)$v['number'],
        'date' => (string)$v['voucher_date'], 'description' => (string)($v['description'] ?? ''), 'status' => (string)$v['status'],
        'source_type' => (string)$v['source_type'], 'source_id' => $v['source_id'], 'total' => (int)$v['total'],
        'created_by' => (int)$v['created_by'], 'created_at' => $v['created_at'], 'updated_at' => $v['updated_at'],
    ];
}

function pb_voucher_get(PDO $pdo, int $cid, array $customer, int $id): array {
    $v = pb_voucher_load($pdo, $cid, $customer, $id);
    $st = $pdo->prepare('SELECT l.*, a.code AS account_code, a.name AS account_name, t.code AS tafsili_code, t.name AS tafsili_name
        FROM voucher_lines l JOIN accounts a ON a.id=l.account_id AND a.customer_id=l.customer_id
        LEFT JOIN tafsili t ON t.id=l.tafsili_id AND t.customer_id=l.customer_id
        WHERE l.voucher_id=? AND l.customer_id=? ORDER BY l.line_no');
    $st->execute([$id, $cid]);
    $lines = [];
    foreach ($st->fetchAll() as $r) {
        $lines[] = [
            'line_no' => (int)$r['line_no'], 'account_id' => (int)$r['account_id'], 'account_code' => (string)$r['account_code'], 'account_name' => (string)$r['account_name'],
            'tafsili_id' => $r['tafsili_id'] === null ? null : (int)$r['tafsili_id'], 'tafsili_code' => $r['tafsili_code'], 'tafsili_name' => $r['tafsili_name'],
            'cost_center_id' => $r['cost_center_id'] === null ? null : (int)$r['cost_center_id'],
            'debit' => (int)$r['debit'], 'credit' => (int)$r['credit'], 'description' => (string)($r['description'] ?? ''),
            'ccy' => $r['ccy'], 'ccy_amount' => $r['ccy_amount'] === null ? null : (float)$r['ccy_amount'], 'rate' => $r['rate'] === null ? null : (float)$r['rate'],
        ];
    }
    return ['voucher' => pb_voucher_head_out($v), 'lines' => $lines];
}

function pb_voucher_list(PDO $pdo, int $cid, array $customer, array $p): array {
    $w = ['v.customer_id=?'];
    $a = [$cid];
    if (!empty($p['fiscal_year_id'])) { $w[] = 'v.fiscal_year_id=?'; $a[] = (int)$p['fiscal_year_id']; }
    if (!empty($p['status']) && in_array((string)$p['status'], PB_VOUCHER_STATUSES, true)) { $w[] = 'v.status=?'; $a[] = (string)$p['status']; }
    if (!empty($p['source_type'])) { $w[] = 'v.source_type=?'; $a[] = mb_substr((string)$p['source_type'], 0, 30); }
    if (isset($p['source_id']) && $p['source_id'] !== '') { $w[] = 'v.source_id=?'; $a[] = mb_substr((string)$p['source_id'], 0, 64); } // T16.7
    if (!empty($p['from'])) { $w[] = 'v.voucher_date>=?'; $a[] = pb_voucher_date($p['from']); }
    if (!empty($p['to'])) { $w[] = 'v.voucher_date<=?'; $a[] = pb_voucher_date($p['to']); }
    if (isset($p['min_total']) && $p['min_total'] !== '') { $w[] = 'v.total>=?'; $a[] = pb_voucher_int($p['min_total'], 'حداقل مبلغ'); }
    if (isset($p['max_total']) && $p['max_total'] !== '') { $w[] = 'v.total<=?'; $a[] = pb_voucher_int($p['max_total'], 'حداکثر مبلغ'); }
    if (!empty($p['account_id'])) { $w[] = 'EXISTS (SELECT 1 FROM voucher_lines x WHERE x.voucher_id=v.id AND x.customer_id=v.customer_id AND x.account_id=?)'; $a[] = (int)$p['account_id']; }
    if (!empty($p['tafsili_id'])) { $w[] = 'EXISTS (SELECT 1 FROM voucher_lines x WHERE x.voucher_id=v.id AND x.customer_id=v.customer_id AND x.tafsili_id=?)'; $a[] = (int)$p['tafsili_id']; }
    $q = trim((string)($p['q'] ?? ''));
    if ($q !== '') {
        if (ctype_digit($q)) { $w[] = '(v.number=? OR v.description LIKE ?)'; $a[] = (int)$q; }
        else $w[] = '(v.description LIKE ?)';
        $a[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
    $limit = max(1, min(200, (int)($p['limit'] ?? 50)));
    $off = max(0, (int)($p['offset'] ?? 0));
    $sql = 'FROM vouchers v WHERE ' . implode(' AND ', $w);
    $st = $pdo->prepare('SELECT v.* ' . $sql . " ORDER BY v.voucher_date DESC, COALESCE(v.number, 4294967295) DESC, v.id DESC LIMIT $limit OFFSET $off");
    $st->execute($a);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        if (!pb_fy_access($customer, (int)$r['fiscal_year_id'])) continue;
        $items[] = pb_voucher_head_out($r);
    }
    $ct = $pdo->prepare('SELECT COUNT(*) ' . $sql);
    $ct->execute($a);
    return ['items' => $items, 'count' => (int)$ct->fetchColumn(), 'limit' => $limit, 'offset' => $off];
}

function pb_voucher_require_manual(array $v): void {
    if ((string)$v['source_type'] !== 'manual') bad('این سند خودکار است و از روی سند مبدأ ساخته می‌شود؛ ویرایش دستی ممکن نیست');
}

function pb_voucher_save(PDO $pdo, int $cid, array $customer, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $status = (string)($p['status'] ?? 'draft');
    if (!in_array($status, PB_VOUCHER_STATUSES, true)) bad('وضعیت سند نامعتبر است');
    if ($status === 'final' && !pb_perm_check($customer, 'accounting.post')) out(['error' => 'forbidden', 'message' => 'مجوز قطعی‌کردن سند را ندارید'], 403);
    if ($id > 0) {
        $old = pb_voucher_load($pdo, $cid, $customer, $id);
        pb_voucher_require_manual($old);
        if ($old['status'] === 'final' && !pb_perm_check($customer, 'accounting.post')) out(['error' => 'forbidden', 'message' => 'ویرایش سند قطعی نیاز به مجوز ثبت قطعی دارد'], 403);
        if (!array_key_exists('status', $p)) $status = (string)$old['status'];
    }
    $newId = pb_voucher_write($pdo, $customer, [
        'date' => $p['date'] ?? '', 'description' => $p['description'] ?? '', 'status' => $status, 'source_type' => 'manual',
    ], $p['lines'] ?? [], $id);
    $out = pb_voucher_get($pdo, $cid, $customer, $newId);
    pb_audit($pdo, $customer, $id > 0 ? 'voucher.update' : 'voucher.create', 'voucher', (string)$newId,
        ($id > 0 ? 'ویرایش سند ' : 'ثبت سند ') . ($out['voucher']['number'] ?? 'پیش‌نویس'), null, $out);
    return $out;
}

function pb_voucher_set_status(PDO $pdo, int $cid, array $customer, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $to = (string)($p['status'] ?? '');
    if (!in_array($to, PB_VOUCHER_STATUSES, true)) bad('وضعیت سند نامعتبر است');
    $v = pb_voucher_load($pdo, $cid, $customer, $id);
    $from = (string)$v['status'];
    if ($from === $to) return pb_voucher_get($pdo, $cid, $customer, $id);
    if ($to === 'draft') bad('سند شمارهٔ‌دار به پیش‌نویس برنمی‌گردد');
    if (!pb_perm_check($customer, 'accounting.post')) out(['error' => 'forbidden', 'message' => 'مجوز تغییر وضعیت سند را ندارید'], 403);
    pb_fy_guard($pdo, $cid, $customer, (string)$v['voucher_date']); // closed/locked year: no status change
    $cur = pb_voucher_get($pdo, $cid, $customer, $id);
    $lines = array_map(function ($l) { return $l; }, $cur['lines']);
    // Re-validate through the single write path (balance, accounts still active, ...) with the new status.
    pb_voucher_write($pdo, $customer, [
        'date' => (string)$v['voucher_date'], 'description' => (string)$v['description'], 'status' => $to, 'source_type' => (string)$v['source_type'], 'source_id' => $v['source_id'],
    ], $lines, $id);
    $out = pb_voucher_get($pdo, $cid, $customer, $id);
    pb_audit($pdo, $customer, 'voucher.status', 'voucher', (string)$id, "تغییر وضعیت سند $from → $to");
    return $out;
}

function pb_voucher_delete(PDO $pdo, int $cid, array $customer, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $v = pb_voucher_load($pdo, $cid, $customer, $id);
    pb_voucher_require_manual($v);
    if ($v['status'] === 'final') bad('سند قطعی حذف نمی‌شود؛ برای اصلاح، سند معکوس یا سند جدید ثبت کنید');
    pb_fy_guard($pdo, $cid, $customer, (string)$v['voucher_date']);
    if ($v['number'] !== null) {
        $st = $pdo->prepare('SELECT COALESCE(MAX(number),0) FROM vouchers WHERE customer_id=? AND fiscal_year_id=?');
        $st->execute([$cid, (int)$v['fiscal_year_id']]);
        if ((int)$st->fetchColumn() !== (int)$v['number']) bad('فقط آخرین سند شمارهٔ‌دار قابل حذف است تا شماره‌ها بدون وقفه بمانند');
    }
    $before = pb_voucher_get($pdo, $cid, $customer, $id);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM voucher_lines WHERE voucher_id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->prepare('DELETE FROM vouchers WHERE id=? AND customer_id=?')->execute([$id, $cid]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[voucher] ' . $e->getMessage());
        bad('حذف سند ناموفق بود', 500);
    }
    pb_audit($pdo, $customer, 'voucher.delete', 'voucher', (string)$id, 'حذف سند ' . ($v['number'] ?? 'پیش‌نویس'), $before, null);
    return ['ok' => true];
}

/** «رونوشت سند»: copies lines into a NEW draft (manual) on the given/same date. */
function pb_voucher_copy(PDO $pdo, int $cid, array $customer, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $src = pb_voucher_get($pdo, $cid, $customer, $id);
    $date = !empty($p['date']) ? pb_voucher_date($p['date']) : $src['voucher']['date'];
    $desc = trim((string)($p['description'] ?? ''));
    if ($desc === '') $desc = 'رونوشت: ' . $src['voucher']['description'];
    $newId = pb_voucher_write($pdo, $customer, [
        'date' => $date, 'description' => $desc, 'status' => 'draft', 'source_type' => 'manual',
    ], $src['lines'], 0);
    $out = pb_voucher_get($pdo, $cid, $customer, $newId);
    pb_audit($pdo, $customer, 'voucher.copy', 'voucher', (string)$newId, 'رونوشت از سند ' . ($src['voucher']['number'] ?? ('#' . $id)));
    return $out;
}

function pb_voucher_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_voucher_ensure($pdo);
    switch ($action) {
        case 'vouchers.list': out(pb_voucher_list($pdo, $cid, $customer, $p));
        case 'vouchers.get': out(pb_voucher_get($pdo, $cid, $customer, (int)($p['id'] ?? 0)));
        case 'vouchers.save': out(pb_voucher_save($pdo, $cid, $customer, $p));
        case 'vouchers.set_status': out(pb_voucher_set_status($pdo, $cid, $customer, $p));
        case 'vouchers.delete': out(pb_voucher_delete($pdo, $cid, $customer, $p));
        case 'vouchers.copy': out(pb_voucher_copy($pdo, $cid, $customer, $p));
    }
    bad('عملیات نامعتبر', 404);
}
