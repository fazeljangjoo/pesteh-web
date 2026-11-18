<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S19.T19.1 — stock count / انبارگردانی (both tiers; voucher only in GOLD via loader 'count_adj' + existing rule in posting_rules.json).
 * Actions (perm stock.view to read, stock.count to change):
 *   stock_count.sheet   {warehouse_uid?}                       lots with system kg (per warehouse, or all) — the count sheet
 *   stock_count.save    {id?|uid?, date, warehouse_uid?, note?, lines:[{lot_id, counted_kg, reason?}]}   draft only; idempotent by uid
 *   stock_count.list    {}        stock_count.get {id}        stock_count.delete {id} (draft only)
 *   stock_count.approve {id}      recomputes system kg, writes `adjust` stock_moves (doc_type 'count', at lot cost), updates purchases.remaining, posts voucher (gold).
 * Sacks (T19.1b): a line may carry counted_small / counted_large (optional, null = sacks not counted -> no sack adjustment); approve writes the sack diff into the adjust move and purchases.*_sacks_remaining.
 * S31.T31.8 lifecycle: draft -> submitted (stock_count.submit: counter is done, lines frozen) -> approved. stock_count.reopen sends submitted back to draft.
 *   approve works from draft OR submitted (old clients unchanged). When form switch `approval` is on and approval.limit > 0, a STAFF user cannot approve a count whose
 *   valued shortage+surplus (rial, lot cost) >= limit: only the owner can (second approval). get() carries `summary` (diff kg and rial value per direction, needs_owner_approval).
 * Approved counts are immutable (correct with a new count). Client may save offline and push later: save is idempotent by `uid` (also returns `uid`), so the local mirror (stock_count_local) can retry safely.
 */
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/warehouses.php';

function pb_sc_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS stock_counts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(40) NOT NULL,
        count_date DATE NOT NULL,
        warehouse_uid CHAR(36) NULL,
        note VARCHAR(500) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'draft',
        approved_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sc_uid (customer_id, uid),
        KEY idx_sc_cust (customer_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS stock_count_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        count_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        system_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        counted_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        diff_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
        reason VARCHAR(200) NULL,
        KEY idx_scl_count (customer_id, count_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c = $pdo->query("SHOW COLUMNS FROM stock_count_lines LIKE 'counted_small'");
    if (!$c || !$c->fetch()) {
        $pdo->exec('ALTER TABLE stock_count_lines ADD COLUMN system_small DECIMAL(10,2) NOT NULL DEFAULT 0, ADD COLUMN system_large DECIMAL(10,2) NOT NULL DEFAULT 0,
            ADD COLUMN counted_small DECIMAL(10,2) NULL, ADD COLUMN counted_large DECIMAL(10,2) NULL,
            ADD COLUMN diff_small DECIMAL(10,2) NOT NULL DEFAULT 0, ADD COLUMN diff_large DECIMAL(10,2) NOT NULL DEFAULT 0');
    }
}

/**
 * Σ per-warehouse balances of a lot for a count. «Whole lot» (wh = null) must NOT include the virtual «نزد امانت‌گیرندگان»
 * warehouse: those goods are still in `remaining` but physically are not in our warehouses, so counting them as expected
 * stock would book a false shortage (and later block the consignee's sale report).
 */
function pb_sc_balances(PDO $pdo, int $cid, int $lot, ?string $wh): array {
    static $virt = [];
    $bal = pb_stock_wh_balances($pdo, $cid, $lot);
    if ($wh === null) {
        if (!isset($virt[$cid])) $virt[$cid] = pb_wh_virtual_uids($pdo, $cid);
        foreach ($virt[$cid] as $vu) unset($bal[$vu]);
    }
    return $bal;
}

/** [kg, small, large] system balance of a lot in the warehouse (null = whole lot, own warehouses only). */
function pb_sc_system_all(PDO $pdo, int $cid, int $lot, ?string $wh): array {
    $bal = pb_sc_balances($pdo, $cid, $lot, $wh);
    $k = 0.0; $s = 0.0; $l = 0.0;
    foreach ($bal as $w => $b) {
        if ($wh !== null && (string)$w !== $wh) continue;
        $k += $b['kg']; $s += $b['small']; $l += $b['large'];
    }
    return [round($k, 3), round($s, 2), round($l, 2)];
}

/** Optional sack count: null/'' = not counted. */
function pb_sc_sack($v): ?float {
    if ($v === null || $v === '') return null;
    $n = round(num($v), 2);
    if ($n < 0 || $n > 1e7) bad('تعداد گونی نامعتبر است');
    return $n;
}

/** System kg of a lot in the warehouse (null = whole lot). */
function pb_sc_system_kg(PDO $pdo, int $cid, int $lot, ?string $wh): float {
    $bal = pb_sc_balances($pdo, $cid, $lot, $wh);
    if ($wh === null) { $t = 0.0; foreach ($bal as $b) $t += $b['kg']; return round($t, 3); }
    return round($bal[$wh]['kg'] ?? 0.0, 3);
}

function pb_sc_sheet(PDO $pdo, int $cid, array $p): array {
    $wh = ($p['warehouse_uid'] ?? '') !== '' ? pb_wh_require($pdo, $cid, $p['warehouse_uid']) : null;
    $st = $pdo->prepare('SELECT id, code, type_name, ounce, remaining FROM purchases WHERE customer_id=? AND is_mix<>3 ORDER BY id DESC LIMIT 2000');
    $st->execute([$cid]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        [$kg, $sm, $lg] = pb_sc_system_all($pdo, $cid, (int)$r['id'], $wh);
        if ($kg <= PB_STOCK_EPS) continue;
        $items[] = ['lot_id' => (int)$r['id'], 'code' => (string)($r['code'] ?? ''), 'type_name' => (string)$r['type_name'], 'ounce' => $r['ounce'], 'system_kg' => $kg, 'system_small' => $sm, 'system_large' => $lg];
    }
    return ['items' => $items];
}

function pb_sc_head_out(array $r): array {
    return ['id' => (int)$r['id'], 'uid' => (string)$r['uid'], 'date' => (string)$r['count_date'], 'warehouse_uid' => $r['warehouse_uid'], 'note' => $r['note'], 'status' => (string)$r['status'], 'approved_at' => $r['approved_at']];
}

function pb_sc_get(PDO $pdo, int $cid, int $id): array {
    $st = $pdo->prepare('SELECT * FROM stock_counts WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $h = $st->fetch();
    if (!$h) bad('انبارگردانی یافت نشد', 404);
    $q = $pdo->prepare('SELECT l.lot_id, l.system_kg, l.counted_kg, l.diff_kg, l.reason, l.system_small, l.system_large, l.counted_small, l.counted_large, l.diff_small, l.diff_large, p.code, p.type_name FROM stock_count_lines l LEFT JOIN purchases p ON p.id=l.lot_id AND p.customer_id=l.customer_id WHERE l.count_id=? AND l.customer_id=? ORDER BY l.id');
    $q->execute([$id, $cid]);
    $out = pb_sc_head_out($h);
    $out['summary'] = pb_sc_summary($pdo, $cid, $id);
    $out['lines'] = array_map(static function ($r) {
        return ['lot_id' => (int)$r['lot_id'], 'code' => (string)($r['code'] ?? ''), 'type_name' => (string)($r['type_name'] ?? ''), 'system_kg' => (float)$r['system_kg'], 'counted_kg' => (float)$r['counted_kg'], 'diff_kg' => (float)$r['diff_kg'], 'reason' => $r['reason'],
            'system_small' => (float)$r['system_small'], 'system_large' => (float)$r['system_large'], 'counted_small' => $r['counted_small'] === null ? null : (float)$r['counted_small'],
            'counted_large' => $r['counted_large'] === null ? null : (float)$r['counted_large'], 'diff_small' => (float)$r['diff_small'], 'diff_large' => (float)$r['diff_large']];
    }, $q->fetchAll());
    return $out;
}

/** Valued difference of a count from the stored line diffs (rial, lot cost). */
function pb_sc_value_diffs(PDO $pdo, int $cid, int $id): array {
    $q = $pdo->prepare('SELECT lot_id, diff_kg FROM stock_count_lines WHERE count_id=? AND customer_id=? AND diff_kg<>0');
    $q->execute([$id, $cid]);
    $r = ['short_kg' => 0.0, 'over_kg' => 0.0, 'short_value' => 0, 'over_value' => 0, 'lines_with_diff' => 0];
    foreach ($q->fetchAll() as $l) {
        $kg = (float)$l['diff_kg'];
        $v = pb_pl_lot_value($pdo, $cid, (int)$l['lot_id'], abs($kg));
        $r['lines_with_diff']++;
        if ($kg < 0) { $r['short_kg'] += -$kg; $r['short_value'] += $v === null ? 0 : (int)$v[0]; }
        else { $r['over_kg'] += $kg; $r['over_value'] += $v === null ? 0 : (int)$v[0]; }
    }
    $r['short_kg'] = round($r['short_kg'], 3); $r['over_kg'] = round($r['over_kg'], 3);
    return $r;
}

function pb_sc_needs_owner(PDO $pdo, int $cid, array $summary): bool {
    if (!function_exists('pb_form_on') || !pb_form_on($pdo, $cid, 'approval')) return false;
    $limit = function_exists('pb_appr_limit') ? pb_appr_limit($pdo, $cid) : 0;
    return $limit > 0 && ($summary['short_value'] + $summary['over_value']) >= $limit;
}

function pb_sc_summary(PDO $pdo, int $cid, int $id): array {
    $s = pb_sc_value_diffs($pdo, $cid, $id);
    $s['needs_owner_approval'] = pb_sc_needs_owner($pdo, $cid, $s);
    return $s;
}

function pb_sc_set_status(PDO $pdo, array $customer, int $id, string $from, string $to, string $msg): array {
    $cid = (int)$customer['id'];
    $st = $pdo->prepare('UPDATE stock_counts SET status=? WHERE id=? AND customer_id=? AND status=?');
    $st->execute([$to, $id, $cid, $from]);
    if ($st->rowCount() < 1) {
        $c = $pdo->prepare('SELECT status FROM stock_counts WHERE id=? AND customer_id=?');
        $c->execute([$id, $cid]);
        $cur = $c->fetchColumn();
        if ($cur === false) bad('انبارگردانی یافت نشد', 404);
        if ($cur !== $to) bad($msg);
    }
    // audit row comes from the central map (audit.php) for submit/reopen
    return pb_sc_get($pdo, $cid, $id);
}

function pb_sc_list(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM stock_count_lines l WHERE l.count_id=c.id AND l.customer_id=c.customer_id) AS n_lines FROM stock_counts c WHERE c.customer_id=? ORDER BY c.count_date DESC, c.id DESC LIMIT 200');
    $st->execute([$cid]);
    return ['items' => array_map(static function ($r) { $o = pb_sc_head_out($r); $o['lines_count'] = (int)$r['n_lines']; return $o; }, $st->fetchAll())];
}

function pb_sc_save(PDO $pdo, int $cid, array $p): array {
    $wh = ($p['warehouse_uid'] ?? '') !== '' ? pb_wh_require($pdo, $cid, $p['warehouse_uid']) : null;
    $date = vdate($p['date'] ?? null);
    $note = str_or_null($p['note'] ?? null, 500);
    $lines = $p['lines'] ?? null;
    if (!is_array($lines) || count($lines) === 0) bad('حداقل یک ردیف شمارش لازم است');
    $pdo->beginTransaction();
    $id = (int)($p['id'] ?? 0);
    $uid = (string)($p['uid'] ?? '');
    if ($id === 0 && $uid !== '') {
        $ex = $pdo->prepare('SELECT id FROM stock_counts WHERE customer_id=? AND uid=? FOR UPDATE');
        $ex->execute([$cid, $uid]);
        $id = (int)$ex->fetchColumn();
    }
    if ($id > 0) {
        $c = $pdo->prepare('SELECT status FROM stock_counts WHERE id=? AND customer_id=? FOR UPDATE');
        $c->execute([$id, $cid]);
        $s = $c->fetchColumn();
        if ($s === false) { $pdo->rollBack(); bad('انبارگردانی یافت نشد', 404); }
        if ($s !== 'draft') { $pdo->rollBack(); bad($s === 'submitted' ? 'انبارگردانی ارسال‌شده است؛ ابتدا بازگشایی کنید' : 'انبارگردانی تأییدشده قابل ویرایش نیست'); }
        $pdo->prepare('UPDATE stock_counts SET count_date=?, warehouse_uid=?, note=? WHERE id=? AND customer_id=?')->execute([$date, $wh, $note, $id, $cid]);
        $pdo->prepare('DELETE FROM stock_count_lines WHERE count_id=? AND customer_id=?')->execute([$id, $cid]);
    } else {
        if ($uid === '') $uid = pb_uuid4();
        if (!pb_is_uid($uid)) { $pdo->rollBack(); bad('شناسه نامعتبر است'); }
        $pdo->prepare('INSERT INTO stock_counts (customer_id, uid, count_date, warehouse_uid, note) VALUES (?,?,?,?,?)')->execute([$cid, $uid, $date, $wh, $note]);
        $id = (int)$pdo->lastInsertId();
    }
    $ins = $pdo->prepare('INSERT INTO stock_count_lines (customer_id, count_id, lot_id, system_kg, counted_kg, diff_kg, reason, system_small, system_large, counted_small, counted_large, diff_small, diff_large) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $seen = [];
    foreach ($lines as $l) {
        $lot = (int)($l['lot_id'] ?? 0);
        if ($lot <= 0 || isset($seen[$lot])) { $pdo->rollBack(); bad('ردیف شمارش نامعتبر یا تکراری است'); }
        $seen[$lot] = true;
        $lk = $pdo->prepare('SELECT is_mix FROM purchases WHERE id=? AND customer_id=?');
        $lk->execute([$lot, $cid]);
        $m = $lk->fetchColumn();
        if ($m === false) { $pdo->rollBack(); bad('قلم یافت نشد', 404); }
        if ((int)$m === 3) { $pdo->rollBack(); bad('کالای امانی در انبارگردانی نمی‌آید'); }
        $counted = round(num($l['counted_kg'] ?? 0), 3);
        if ($counted < 0 || $counted > 1e9) { $pdo->rollBack(); bad('وزن شمارش‌شده نامعتبر است'); }
        [$sys, $sSm, $sLg] = pb_sc_system_all($pdo, $cid, $lot, $wh);
        $cSm = pb_sc_sack($l['counted_small'] ?? null);
        $cLg = pb_sc_sack($l['counted_large'] ?? null);
        $ins->execute([$cid, $id, $lot, $sys, $counted, round($counted - $sys, 3), str_or_null($l['reason'] ?? null, 200), $sSm, $sLg, $cSm, $cLg,
            $cSm === null ? 0 : round($cSm - $sSm, 2), $cLg === null ? 0 : round($cLg - $sLg, 2)]);
    }
    $pdo->commit();
    return pb_sc_get($pdo, $cid, $id);
}

function pb_sc_delete(PDO $pdo, int $cid, int $id): void {
    $pdo->beginTransaction();
    $c = $pdo->prepare('SELECT status FROM stock_counts WHERE id=? AND customer_id=? FOR UPDATE');
    $c->execute([$id, $cid]);
    $s = $c->fetchColumn();
    if ($s === false) { $pdo->commit(); return; }
    if ($s !== 'draft') { $pdo->rollBack(); bad('انبارگردانی تأییدشده حذف نمی‌شود'); }
    $pdo->prepare('DELETE FROM stock_count_lines WHERE count_id=? AND customer_id=?')->execute([$id, $cid]);
    $pdo->prepare('DELETE FROM stock_counts WHERE id=? AND customer_id=?')->execute([$id, $cid]);
    $pdo->commit();
}

/** Stock-ledger moves of an approved count (called from pb_stock_desired). Item: [lot, kind, kg, small, large, unit_cost, date, wh]. */
function pb_sc_desired(PDO $pdo, int $cid, int $docId): array {
    $st = $pdo->prepare("SELECT count_date, warehouse_uid FROM stock_counts WHERE id=? AND customer_id=? AND status='approved'");
    $st->execute([$docId, $cid]);
    $h = $st->fetch();
    if (!$h) return [];
    $q = $pdo->prepare('SELECT l.lot_id, l.diff_kg, l.diff_small, l.diff_large, ' . PB_BROKER_COST_SQL . ' AS price FROM stock_count_lines l LEFT JOIN purchases p ON p.id=l.lot_id AND p.customer_id=l.customer_id WHERE l.count_id=? AND l.customer_id=? AND (l.diff_kg<>0 OR l.diff_small<>0 OR l.diff_large<>0) ORDER BY l.id');
    $q->execute([$docId, $cid]);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[] = [(int)$r['lot_id'], 'adjust', (float)$r['diff_kg'], (float)$r['diff_small'], (float)$r['diff_large'], $r['price'] === null ? null : (float)$r['price'], (string)$h['count_date'], $h['warehouse_uid'] ?: null];
    return $out;
}

function pb_sc_approve(PDO $pdo, array $customer, int $id): array {
    $cid = (int)$customer['id'];
    $pdo->beginTransaction();
    $c = $pdo->prepare('SELECT * FROM stock_counts WHERE id=? AND customer_id=? FOR UPDATE');
    $c->execute([$id, $cid]);
    $h = $c->fetch();
    if (!$h) { $pdo->rollBack(); bad('انبارگردانی یافت نشد', 404); }
    if ($h['status'] === 'approved') { $pdo->commit(); return pb_sc_get($pdo, $cid, $id); } // idempotent
    $wh = $h['warehouse_uid'] ?: null;
    $q = $pdo->prepare('SELECT id, lot_id, counted_kg, counted_small, counted_large FROM stock_count_lines WHERE count_id=? AND customer_id=?');
    $q->execute([$id, $cid]);
    $upd = $pdo->prepare('UPDATE stock_count_lines SET system_kg=?, diff_kg=?, system_small=?, system_large=?, diff_small=?, diff_large=? WHERE id=? AND customer_id=?');
    $rem = $pdo->prepare('UPDATE purchases SET remaining = remaining + ?, small_sacks_remaining = small_sacks_remaining + ?, large_sacks_remaining = large_sacks_remaining + ? WHERE id=? AND customer_id=?');
    $diffs = [];
    foreach ($q->fetchAll() as $l) {
        $lk = $pdo->prepare('SELECT id FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $lk->execute([(int)$l['lot_id'], $cid]);
        [$sys, $sSm, $sLg] = pb_sc_system_all($pdo, $cid, (int)$l['lot_id'], $wh);
        $diff = round((float)$l['counted_kg'] - $sys, 3);
        $dSm = $l['counted_small'] === null ? 0.0 : round((float)$l['counted_small'] - $sSm, 2);
        $dLg = $l['counted_large'] === null ? 0.0 : round((float)$l['counted_large'] - $sLg, 2);
        $upd->execute([$sys, $diff, $sSm, $sLg, $dSm, $dLg, (int)$l['id'], $cid]);
        if (abs($diff) > PB_STOCK_EPS || abs($dSm) > 0.004 || abs($dLg) > 0.004) $diffs[(int)$l['lot_id']] = [$diff, $dSm, $dLg];
    }
    if ((int)($customer['_staff_id'] ?? 0) > 0) { // S31.T31.8: second approval above the company limit
        $sum = pb_sc_value_diffs($pdo, $cid, $id);
        if (pb_sc_needs_owner($pdo, $cid, $sum)) { $pdo->rollBack(); bad('اختلاف انبارگردانی بالاتر از سقف تأیید است؛ فقط مالک می‌تواند تأیید کند', 403); }
    }
    $pdo->prepare("UPDATE stock_counts SET status='approved', approved_at=NOW() WHERE id=? AND customer_id=?")->execute([$id, $cid]);
    pb_stock_post_doc($pdo, $cid, 'count', $id);
    foreach ($diffs as $lot => $d) $rem->execute([$d[0], $d[1], $d[2], $lot, $cid]);
    pb_post_hook($pdo, $customer, 'count_adj', $id);
    pb_audit($pdo, $customer, 'stock_count.approve', 'stock_count', (string)$id, 'تأیید انبارگردانی #' . $id, null, ['lots' => count($diffs)]);
    $pdo->commit();
    return pb_sc_get($pdo, $cid, $id);
}

/** Posting loader (gold): value of shortages/surpluses at ORIGINAL lot cost, split raw / processed. */
function pb_sc_load_post(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare("SELECT count_date FROM stock_counts WHERE id=? AND customer_id=? AND status='approved'");
    $st->execute([$id, $cid]);
    $d = $st->fetchColumn();
    if ($d === false) return null;
    $q = $pdo->prepare('SELECT lot_id, diff_kg FROM stock_count_lines WHERE count_id=? AND customer_id=? AND diff_kg<>0');
    $q->execute([$id, $cid]);
    $f = ['short_raw' => 0, 'short_proc' => 0, 'over_raw' => 0, 'over_proc' => 0];
    foreach ($q->fetchAll() as $r) {
        $kg = (float)$r['diff_kg'];
        $v = pb_pl_lot_value($pdo, $cid, (int)$r['lot_id'], abs($kg));
        if ($v === null) continue;
        $k = ($kg < 0 ? 'short_' : 'over_') . ($v[1] ? 'proc' : 'raw');
        $f[$k] += (int)$v[0];
    }
    if (array_sum($f) <= 0) return null;
    return ['date' => (string)$d, 'description' => 'انبارگردانی #' . $id, 'fields' => $f, 'dims' => []];
}
pb_posting_register_loader('count_adj', 'pb_sc_load_post');

function pb_sc_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    pb_sc_ensure($pdo);
    $write = in_array($action, ['stock_count.save', 'stock_count.delete', 'stock_count.approve', 'stock_count.submit', 'stock_count.reopen'], true);
    if (!pb_perm_check($customer, $write ? 'stock.count' : 'stock.view')) bad('دسترسی لازم را ندارید', 403);
    switch ($action) {
        case 'stock_count.sheet': out(pb_sc_sheet($pdo, $cid, $p));
        case 'stock_count.list': out(pb_sc_list($pdo, $cid));
        case 'stock_count.get': out(pb_sc_get($pdo, $cid, (int)($p['id'] ?? 0)));
        case 'stock_count.save': out(pb_sc_save($pdo, $cid, $p));
        case 'stock_count.delete': pb_sc_delete($pdo, $cid, (int)($p['id'] ?? 0)); out(['ok' => true]);
        case 'stock_count.submit': out(pb_sc_set_status($pdo, $customer, (int)($p['id'] ?? 0), 'draft', 'submitted', 'فقط پیش‌نویس قابل ارسال است'));
        case 'stock_count.reopen': out(pb_sc_set_status($pdo, $customer, (int)($p['id'] ?? 0), 'submitted', 'draft', 'فقط انبارگردانی ارسال‌شده قابل بازگشایی است'));
        case 'stock_count.approve': out(pb_sc_approve($pdo, $customer, (int)($p['id'] ?? 0)));
    }
    bad('عملیات نامعتبر', 404);
}
