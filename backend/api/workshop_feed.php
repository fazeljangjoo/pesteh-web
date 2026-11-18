<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
require_once __DIR__ . '/workshop_calc.php';
/**
 * کارگاه — مرحلهٔ ۵-الف: «موجودی پستهٔ خرجی» (نمای جدا از موجودی انبار اصلی؛ تصمیم قطعی ۷ = «ترکیبی»).
 * خودکار از: ۱) کارگاه بیرونی (proc_orders با direction='paid') ۲) خط شکست (crack_runs)؛ دستی از: feed_adjust (تعدیل ورود/خروج با دلیل).
 * هیچ حرکت موجودی/لات نمی‌سازد و هیچ مبلغی ندارد. محاسبه فقط با pb_feed_calc / pb_feed_totals (workshop_calc.php).
 * مجوزها: feed.stock / feed.adjust_list = workshop.feed (مالک، دارندگان stock.view و «اپراتور کارگاه» در v68؛ اپراتور فقط‌مشاهده)
 *         feed.adjust_create / feed.adjust_delete = workshop.manage.
 */
const PB_FEED_LIMIT = 5000;

function pb_feed_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS feed_adjust (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        adj_date DATE NOT NULL,
        kind VARCHAR(3) NOT NULL DEFAULT 'in',
        owner VARCHAR(150) NULL,
        workshop VARCHAR(150) NULL,
        receipt_no VARCHAR(60) NULL,
        kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        reason VARCHAR(300) NULL,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_by_name VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_feedadj_uid (customer_id, uid),
        KEY idx_feedadj_cust (customer_id, adj_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_feed_adj_row(array $r): array {
    return ['id' => (int)$r['id'], 'uid' => $r['uid'], 'date' => $r['adj_date'], 'kind' => $r['kind'], 'owner' => $r['owner'], 'workshop' => $r['workshop'],
        'receipt_no' => $r['receipt_no'], 'kg' => (float)$r['kg'], 'reason' => $r['reason'], 'created_by' => (int)$r['created_by'], 'created_by_name' => $r['created_by_name'], 'created_at' => $r['created_at']];
}

/** feed.adjust_create {uid?, date?, kind:'in'|'out', owner?, workshop?, receipt_no?, kg, reason} — idempotent با uid؛ دلیل اجباری. */
function pb_feed_adjust_create(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_feed_ensure($pdo);
    $uid = str_or_null($p['uid'] ?? null, 36);
    if ($uid !== null) {
        $ex = $pdo->prepare('SELECT * FROM feed_adjust WHERE customer_id=? AND uid=?');
        $ex->execute([$cid, $uid]);
        $row = $ex->fetch();
        if ($row) return pb_feed_adj_row($row);
    }
    $kind = (string)($p['kind'] ?? '');
    if ($kind !== 'in' && $kind !== 'out') pb_proc_fail('نوع تعدیل باید ورود (in) یا خروج (out) باشد');
    $kg = num($p['kg'] ?? 0);
    if ($kg <= 0 || $kg > 1e9) pb_proc_fail('وزن تعدیل باید بیشتر از صفر باشد');
    $reason = str_or_null($p['reason'] ?? null, 300);
    if ($reason === null) pb_proc_fail('دلیل تعدیل را وارد کنید');
    $date = ($p['date'] ?? $p['adj_date'] ?? '') !== '' ? vdate($p['date'] ?? $p['adj_date']) : today();
    $pdo->prepare('INSERT INTO feed_adjust (customer_id, uid, adj_date, kind, owner, workshop, receipt_no, kg, reason, created_by, created_by_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $uid, $date, $kind, str_or_null($p['owner'] ?? null, 150), str_or_null($p['workshop'] ?? null, 150), str_or_null($p['receipt_no'] ?? null, 60),
            round($kg, 3), $reason, (int)($customer['_staff_id'] ?? 0), str_or_null($customer['_staff_name'] ?? null, 100)]);
    $id = (int)$pdo->lastInsertId();
    $st = $pdo->prepare('SELECT * FROM feed_adjust WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    return pb_feed_adj_row($st->fetch());
}

/** feed.adjust_list {from?, to?, owner?, workshop?} */
function pb_feed_adjust_list(PDO $pdo, int $cid, array $p): array {
    pb_feed_ensure($pdo);
    $sql = 'SELECT * FROM feed_adjust WHERE customer_id=?';
    $args = [$cid];
    if (($p['from'] ?? '') !== '') { $sql .= ' AND adj_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND adj_date <= ?'; $args[] = vdate($p['to']); }
    foreach (['owner', 'workshop'] as $k) if (($p[$k] ?? '') !== '') { $sql .= " AND $k = ?"; $args[] = (string)$p[$k]; }
    $st = $pdo->prepare($sql . ' ORDER BY adj_date DESC, id DESC LIMIT 1000');
    $st->execute($args);
    return ['items' => array_map('pb_feed_adj_row', $st->fetchAll())];
}

/** feed.adjust_delete {id} */
function pb_feed_adjust_delete(PDO $pdo, int $cid, array $p): array {
    pb_feed_ensure($pdo);
    $id = (int)($p['id'] ?? 0);
    $st = $pdo->prepare('SELECT id FROM feed_adjust WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    if (!$st->fetchColumn()) pb_proc_fail('تعدیل یافت نشد', 404);
    $pdo->prepare('DELETE FROM feed_adjust WHERE id=? AND customer_id=?')->execute([$id, $cid]);
    return ['ok' => true];
}

/**
 * feed.stock {from?, to?, owner?, workshop?, include_done?} — ردیف‌ها + جمع‌ها + summary (کارت صفحهٔ اصلی).
 * کارگاه بیرونی: فقط in_progress (مگر include_done=1)؛ خط شکست: تجمیع بر اساس (customer_name, customer_code, line_code)؛ تعدیل: هر رکورد یک ردیف.
 * ردیف: {source, id?, date, workshop, owner, receipt_no, code?, status?, kind?, reason?, sent_kg, returned_kg, waste_kg, remaining_kg}. بی‌قیمت.
 */
function pb_feed_stock(PDO $pdo, int $cid, array $p): array {
    pb_feed_ensure($pdo);
    pb_cr_ensure($pdo);
    $from = ($p['from'] ?? '') !== '' ? vdate($p['from']) : null;
    $to = ($p['to'] ?? '') !== '' ? vdate($p['to']) : null;
    $fOwner = trim((string)($p['owner'] ?? ''));
    $fWs = trim((string)($p['workshop'] ?? ''));
    $inclDone = !empty($p['include_done']);
    $rows = [];

    // الف) کارگاه بیرونی (paid)
    $sql = "SELECT id, order_date, party, owner, receipt_no, status, input_kg, output_kg, waste_kg FROM proc_orders WHERE customer_id=? AND direction='paid'";
    $args = [$cid];
    if (!$inclDone) $sql .= " AND status='in_progress'";
    if ($from !== null) { $sql .= ' AND order_date >= ?'; $args[] = $from; }
    if ($to !== null) { $sql .= ' AND order_date <= ?'; $args[] = $to; }
    $st = $pdo->prepare($sql . ' ORDER BY order_date DESC, id DESC LIMIT ' . PB_FEED_LIMIT);
    $st->execute($args);
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['source' => 'outside', 'id' => (int)$r['id'], 'date' => $r['order_date'], 'workshop' => (string)$r['party'], 'owner' => (string)($r['owner'] ?? ''),
            'receipt_no' => (string)($r['receipt_no'] ?? ''), 'status' => $r['status']] + pb_feed_calc(['source' => 'outside'] + $r);
    }

    // ب) خط شکست — تجمیع
    $sql = 'SELECT run_date, customer_name, customer_code, line_code, input_kg, kernel_kg, hull_kg, soil_kg FROM crack_runs WHERE customer_id=?';
    $args = [$cid];
    if ($from !== null) { $sql .= ' AND run_date >= ?'; $args[] = $from; }
    if ($to !== null) { $sql .= ' AND run_date <= ?'; $args[] = $to; }
    $st = $pdo->prepare($sql . ' ORDER BY run_date DESC, id DESC LIMIT ' . PB_FEED_LIMIT);
    $st->execute($args);
    $groups = [];
    foreach ($st->fetchAll() as $r) {
        $key = (string)$r['customer_name'] . "\x1f" . (string)$r['customer_code'] . "\x1f" . (string)$r['line_code'];
        if (!isset($groups[$key])) $groups[$key] = ['name' => (string)$r['customer_name'], 'code' => (string)$r['customer_code'], 'line' => (string)$r['line_code'], 'date' => $r['run_date'], 'in' => 0, 'k' => 0, 'h' => 0, 's' => 0];
        $groups[$key]['in'] += pb_ws_g($r['input_kg']); $groups[$key]['k'] += pb_ws_g($r['kernel_kg']);
        $groups[$key]['h'] += pb_ws_g($r['hull_kg']); $groups[$key]['s'] += pb_ws_g($r['soil_kg']);
    }
    foreach ($groups as $g) {
        $rows[] = ['source' => 'crack', 'date' => $g['date'], 'workshop' => trim('خط شکست ' . $g['line']), 'owner' => $g['name'], 'receipt_no' => '', 'code' => $g['code']]
            + pb_feed_calc(['source' => 'crack', 'input_kg' => $g['in'] / 1000, 'kernel_kg' => $g['k'] / 1000, 'hull_kg' => $g['h'] / 1000, 'soil_kg' => $g['s'] / 1000]);
    }

    // ج) تعدیل دستی
    $sql = 'SELECT * FROM feed_adjust WHERE customer_id=?';
    $args = [$cid];
    if ($from !== null) { $sql .= ' AND adj_date >= ?'; $args[] = $from; }
    if ($to !== null) { $sql .= ' AND adj_date <= ?'; $args[] = $to; }
    $st = $pdo->prepare($sql . ' ORDER BY adj_date DESC, id DESC LIMIT ' . PB_FEED_LIMIT);
    $st->execute($args);
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['source' => 'adjust', 'id' => (int)$r['id'], 'date' => $r['adj_date'], 'workshop' => (string)($r['workshop'] ?? ''), 'owner' => (string)($r['owner'] ?? ''),
            'receipt_no' => (string)($r['receipt_no'] ?? ''), 'kind' => $r['kind'], 'reason' => $r['reason']] + pb_feed_calc(['source' => 'adjust', 'kind' => $r['kind'], 'kg' => $r['kg']]);
    }

    if ($fOwner !== '') $rows = array_values(array_filter($rows, fn($x) => $x['owner'] === $fOwner));
    if ($fWs !== '') $rows = array_values(array_filter($rows, fn($x) => $x['workshop'] === $fWs));
    usort($rows, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
    $t = pb_feed_totals($rows);
    $summary = $t['summary']; unset($t['summary']);
    return ['items' => $rows, 'totals' => $t, 'summary' => $summary];
}
