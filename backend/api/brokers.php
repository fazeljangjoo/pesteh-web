<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S05.T05.1 — brokers (دلال) and commissions. Both tiers.
 * - Broker = a `parties` row (any party can be picked; type 'broker' is a UI hint only).
 * - broker_rules: default commission per broker. broker_payments: settlement payments to the broker party.
 * - purchases/sales get additive nullable columns broker_id, broker_basis, broker_value, broker_amount, broker_payer.
 *   Amounts are integers (same stored unit as all other amounts).
 * - payer 'us': purchase => capitalized into lot cost (see PB_BROKER_COST_SQL, used by stock.php);
 *               sale     => selling expense (reduces profit, NOT lot cost). We owe the broker the amount.
 * - payer 'counterparty': informational only, no effect on cost/profit/balances.
 * - Deleting the document removes the commission (columns live on the document row).
 */
const PB_BROKER_BASES = ['percent', 'per_kg', 'fixed'];
const PB_BROKER_APPLIES = ['purchase', 'sale', 'both'];
const PB_BROKER_PAYERS = ['us', 'counterparty'];
/** SQL: per-kg lot cost = price + capitalized purchase commission (alias `p` = purchases). */
const PB_BROKER_COST_SQL = "(p.price + COALESCE(CASE WHEN p.broker_payer='us' THEN p.broker_amount / NULLIF(p.net_weight,0) END, 0))";

function pb_broker_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS broker_rules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        broker_id INT UNSIGNED NOT NULL,
        basis VARCHAR(10) NOT NULL DEFAULT 'percent',
        value DECIMAL(14,4) NOT NULL DEFAULT 0,
        applies VARCHAR(10) NOT NULL DEFAULT 'both',
        payer VARCHAR(15) NOT NULL DEFAULT 'us',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        UNIQUE KEY uq_broker_rule (customer_id, broker_id, applies),
        KEY idx_broker_rule_c (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS broker_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(64) NULL,
        broker_id INT UNSIGNED NOT NULL,
        date DATE NOT NULL,
        amount BIGINT NOT NULL DEFAULT 0,
        note VARCHAR(300) NULL,
        created_at DATETIME NULL,
        UNIQUE KEY uq_broker_pay_uid (customer_id, uid),
        KEY idx_broker_pay_c (customer_id, broker_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cols = ['broker_id' => 'INT UNSIGNED NULL DEFAULT NULL', 'broker_basis' => 'VARCHAR(10) NULL DEFAULT NULL',
        'broker_value' => 'DECIMAL(14,4) NULL DEFAULT NULL', 'broker_amount' => 'BIGINT NULL DEFAULT NULL',
        'broker_payer' => 'VARCHAR(15) NULL DEFAULT NULL'];
    foreach (['purchases', 'sales'] as $tbl) {
        foreach ($cols as $c => $def) {
            if (!pb_col_exists($pdo, $tbl, $c)) $pdo->exec("ALTER TABLE $tbl ADD COLUMN `$c` $def");
        }
    }
}

/** Commission amount (integer) for a document of $kg at $price per kg. */
function pb_broker_calc(string $basis, float $value, float $kg, float $price): int {
    if ($basis === 'percent') return (int)round($kg * $price * $value / 100.0);
    if ($basis === 'per_kg') return (int)round($kg * $value);
    return (int)round($value); // fixed
}

function pb_broker_party(PDO $pdo, int $cid, int $id): ?array {
    $st = $pdo->prepare('SELECT id, name FROM parties WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    return $r ?: null;
}

/**
 * Called inside the doc transaction after the purchase/sale row exists (before pb_stock_post_doc).
 * $kind 'purchase'|'sale'. If the payload has no broker_* key the stored broker is kept and only the amount is
 * recomputed (weight/price may have changed); old clients therefore keep working.
 */
function pb_broker_apply(PDO $pdo, int $cid, string $kind, int $docId, array $payload): void {
    $tbl = $kind === 'purchase' ? 'purchases' : 'sales';
    $st = $pdo->prepare("SELECT net_weight, price, broker_id, broker_basis, broker_value, broker_payer FROM $tbl WHERE id=? AND customer_id=?");
    $st->execute([$docId, $cid]);
    $d = $st->fetch();
    if (!$d) return;
    $touch = false;
    foreach (['broker_id', 'broker_basis', 'broker_value', 'broker_payer'] as $k) if (array_key_exists($k, $payload)) $touch = true;
    $bid = (int)($d['broker_id'] ?? 0); $basis = $d['broker_basis']; $value = $d['broker_value']; $payer = $d['broker_payer'];
    if ($touch) {
        $bid = (int)($payload['broker_id'] ?? 0);
        if ($bid <= 0) { // clear
            $pdo->prepare("UPDATE $tbl SET broker_id=NULL, broker_basis=NULL, broker_value=NULL, broker_amount=NULL, broker_payer=NULL WHERE id=? AND customer_id=?")->execute([$docId, $cid]);
            return;
        }
        if (!pb_broker_party($pdo, $cid, $bid)) bad('دلال انتخابی یافت نشد', 404);
        $basis = $payload['broker_basis'] ?? null; $value = $payload['broker_value'] ?? null; $payer = $payload['broker_payer'] ?? null;
        if ($basis === null || $basis === '' || $value === null || $value === '') { // fall back to the broker's default rule
            $r = pb_broker_default_rule($pdo, $cid, $bid, $kind);
            if ($basis === null || $basis === '') $basis = $r['basis'] ?? null;
            if ($value === null || $value === '') $value = $r['value'] ?? null;
            if ($payer === null || $payer === '') $payer = $r['payer'] ?? 'us';
        }
        if (!in_array($basis, PB_BROKER_BASES, true)) bad('مبنای کمیسیون نامعتبر است');
        if (!is_numeric($value) || (float)$value < 0 || (float)$value > 1e12) bad('مقدار کمیسیون نامعتبر است');
        if ($basis === 'percent' && (float)$value > 100) bad('درصد کمیسیون نباید از ۱۰۰ بیشتر باشد');
        if (!in_array($payer, PB_BROKER_PAYERS, true)) bad('پرداخت‌کنندهٔ کمیسیون نامعتبر است');
    } elseif ($bid <= 0 || !$basis) {
        return; // no broker on this doc
    }
    $amount = pb_broker_calc((string)$basis, (float)$value, (float)$d['net_weight'], (float)$d['price']);
    $pdo->prepare("UPDATE $tbl SET broker_id=?, broker_basis=?, broker_value=?, broker_amount=?, broker_payer=? WHERE id=? AND customer_id=?")
        ->execute([$bid, $basis, (float)$value, $amount, $payer ?: 'us', $docId, $cid]);
}

function pb_broker_default_rule(PDO $pdo, int $cid, int $bid, string $kind): array {
    $st = $pdo->prepare("SELECT basis, value, payer FROM broker_rules WHERE customer_id=? AND broker_id=? AND active=1 AND applies IN (?, 'both') ORDER BY (applies='both') ASC LIMIT 1");
    $st->execute([$cid, $bid, $kind]);
    return $st->fetch() ?: [];
}

// ---------------- rules ----------------
function pb_broker_rules_list(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare('SELECT r.id, r.broker_id, pa.name AS broker_name, r.basis, r.value, r.applies, r.payer, r.active
        FROM broker_rules r JOIN parties pa ON pa.id=r.broker_id AND pa.customer_id=r.customer_id WHERE r.customer_id=? ORDER BY pa.name, r.id');
    $st->execute([$cid]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['broker_id'] = (int)$r['broker_id']; $r['value'] = (float)$r['value']; $r['active'] = (int)$r['active']; }
    return ['rules' => $rows];
}

function pb_broker_rules_save(PDO $pdo, int $cid, array $p): array {
    $bid = (int)($p['broker_id'] ?? 0);
    if (!pb_broker_party($pdo, $cid, $bid)) bad('دلال یافت نشد', 404);
    $basis = (string)($p['basis'] ?? 'percent');
    $applies = (string)($p['applies'] ?? 'both');
    $payer = (string)($p['payer'] ?? 'us');
    $value = $p['value'] ?? 0;
    if (!in_array($basis, PB_BROKER_BASES, true) || !in_array($applies, PB_BROKER_APPLIES, true) || !in_array($payer, PB_BROKER_PAYERS, true)) bad('مقادیر قانون کمیسیون نامعتبر است');
    if (!is_numeric($value) || (float)$value < 0 || (float)$value > 1e12) bad('مقدار کمیسیون نامعتبر است');
    if ($basis === 'percent' && (float)$value > 100) bad('درصد کمیسیون نباید از ۱۰۰ بیشتر باشد');
    $active = array_key_exists('active', $p) ? (empty($p['active']) ? 0 : 1) : 1;
    $pdo->prepare('INSERT INTO broker_rules (customer_id, broker_id, basis, value, applies, payer, active, created_at) VALUES (?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE basis=VALUES(basis), value=VALUES(value), payer=VALUES(payer), active=VALUES(active)')
        ->execute([$cid, $bid, $basis, (float)$value, $applies, $payer, $active]);
    $st = $pdo->prepare('SELECT id FROM broker_rules WHERE customer_id=? AND broker_id=? AND applies=?');
    $st->execute([$cid, $bid, $applies]);
    return ['id' => (int)$st->fetchColumn()];
}

function pb_broker_rules_delete(PDO $pdo, int $cid, array $p): array {
    $pdo->prepare('DELETE FROM broker_rules WHERE id=? AND customer_id=?')->execute([(int)($p['id'] ?? 0), $cid]);
    return ['ok' => true];
}

// ---------------- settlement payments ----------------
function pb_broker_pay_save(PDO $pdo, int $cid, array $p): array {
    $bid = (int)($p['broker_id'] ?? 0);
    if (!pb_broker_party($pdo, $cid, $bid)) bad('دلال یافت نشد', 404);
    $amount = vamount($p['amount'] ?? 0);
    if ($amount <= 0) bad('مبلغ پرداخت نامعتبر است');
    $uid = str_or_null($p['uid'] ?? null, 64);
    if ($uid !== null) { // idempotent by client uid
        $q = $pdo->prepare('SELECT id FROM broker_payments WHERE customer_id=? AND uid=?');
        $q->execute([$cid, $uid]);
        $ex = $q->fetchColumn();
        if ($ex) return ['id' => (int)$ex, 'duplicate' => true];
    }
    $cu = function_exists('pb_post_hook') ? pb_plb_customer($pdo, $cid) : null; // S16.T16.4: gold posts the payment (one voucher, atomic with the row)
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO broker_payments (customer_id, uid, broker_id, date, amount, note, cost_center_uid, created_at) VALUES (?,?,?,?,?,?,?,NOW())')
        ->execute([$cid, $uid, $bid, vdate($p['date'] ?? null), (int)round((float)$amount), str_or_null($p['note'] ?? null, 300), pb_cc_check($pdo, $cid, $p['cost_center_uid'] ?? null)]);
    $newId = (int)$pdo->lastInsertId();
    if ($cu) pb_post_hook($pdo, $cu, 'bpay', $newId);
    if ($own) $pdo->commit();
    return ['id' => $newId];
}

function pb_broker_pay_delete(PDO $pdo, int $cid, array $p): array {
    $cu = function_exists('pb_unpost_hook') ? pb_plb_customer($pdo, $cid) : null;
    if ($cu) pb_unpost_hook($pdo, $cu, 'bpay', (int)($p['id'] ?? 0)); // S16.T16.4
    $pdo->prepare('DELETE FROM broker_payments WHERE id=? AND customer_id=?')->execute([(int)($p['id'] ?? 0), $cid]);
    return ['ok' => true];
}

function pb_broker_pay_list(PDO $pdo, int $cid, array $p): array {
    $sql = 'SELECT b.id, b.uid, b.broker_id, pa.name AS broker_name, b.date, b.amount, b.note FROM broker_payments b
        JOIN parties pa ON pa.id=b.broker_id AND pa.customer_id=b.customer_id WHERE b.customer_id=?';
    $args = [$cid];
    if ((int)($p['broker_id'] ?? 0) > 0) { $sql .= ' AND b.broker_id=?'; $args[] = (int)$p['broker_id']; }
    $st = $pdo->prepare($sql . ' ORDER BY b.date DESC, b.id DESC');
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['broker_id'] = (int)$r['broker_id']; $r['amount'] = (int)$r['amount']; }
    return ['payments' => $rows];
}

// ---------------- party balance / statement integration ----------------
/** [partyKey => ['due'=>commission we owe, 'paid'=>payments made]] (payer 'us' only). */
function pb_broker_party_sums(PDO $pdo, int $cid): array {
    $o = [];
    foreach (['purchases', 'sales'] as $tbl) {
        $st = $pdo->prepare("SELECT pa.name, COALESCE(SUM(d.broker_amount),0) AS s FROM $tbl d JOIN parties pa ON pa.id=d.broker_id AND pa.customer_id=d.customer_id
            WHERE d.customer_id=? AND d.broker_payer='us' AND d.broker_amount > 0 GROUP BY pa.name");
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) { $k = partyKey($r['name']); $o[$k]['due'] = ($o[$k]['due'] ?? 0) + (float)$r['s']; $o[$k]['paid'] = $o[$k]['paid'] ?? 0; }
    }
    $st = $pdo->prepare('SELECT pa.name, COALESCE(SUM(b.amount),0) AS s FROM broker_payments b JOIN parties pa ON pa.id=b.broker_id AND pa.customer_id=b.customer_id WHERE b.customer_id=? GROUP BY pa.name');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) { $k = partyKey($r['name']); $o[$k]['paid'] = ($o[$k]['paid'] ?? 0) + (float)$r['s']; $o[$k]['due'] = $o[$k]['due'] ?? 0; }
    return $o;
}

/** Statement events: commission due = credit (we owe broker), payment to broker = debit. Sign convention: balance>0 = party owes us. */
function pb_broker_party_events(PDO $pdo, int $cid): array {
    $ev = [];
    foreach (['purchase' => ['purchases', 'کمیسیون دلال (خرید)'], 'sale' => ['sales', 'کمیسیون دلال (فروش)']] as $kind => [$tbl, $label]) {
        $st = $pdo->prepare("SELECT d.id, d.date, d.broker_amount AS amt, pa.name FROM $tbl d JOIN parties pa ON pa.id=d.broker_id AND pa.customer_id=d.customer_id
            WHERE d.customer_id=? AND d.broker_payer='us' AND d.broker_amount > 0");
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) {
            $ev[] = ['key' => partyKey($r['name']), 'date' => (string)$r['date'], 'type' => 'broker_commission', 'ref' => null, 'label' => $label,
                'effect' => -(float)$r['amt'], 'doc_id' => (int)$r['id'], 'ord' => 1];
        }
    }
    $st = $pdo->prepare('SELECT b.id, b.date, b.amount, pa.name FROM broker_payments b JOIN parties pa ON pa.id=b.broker_id AND pa.customer_id=b.customer_id WHERE b.customer_id=?');
    $st->execute([$cid]);
    foreach ($st->fetchAll() as $r) {
        $ev[] = ['key' => partyKey($r['name']), 'date' => (string)$r['date'], 'type' => 'broker_payment', 'ref' => null, 'label' => 'پرداخت کمیسیون به دلال',
            'effect' => (float)$r['amount'], 'doc_id' => (int)$r['id'], 'ord' => 2];
    }
    return $ev;
}

// ---------------- report ----------------
/** reports.broker_commission {from?,to?,broker_id?}: per broker, commission on purchases/sales (payer us / counterparty), paid in period, due vs paid. */
function pb_broker_report(PDO $pdo, int $cid, array $p): array {
    $from = ($p['from'] ?? '') !== '' ? vdate($p['from']) : null;
    $to = ($p['to'] ?? '') !== '' ? vdate($p['to']) : null;
    $only = (int)($p['broker_id'] ?? 0);
    $rows = [];
    $get = function (int $id, string $name) use (&$rows): void {
        if (!isset($rows[$id])) $rows[$id] = ['broker_id' => $id, 'broker_name' => $name, 'purchase_count' => 0, 'sale_count' => 0,
            'purchase_commission' => 0, 'sale_commission' => 0, 'counterparty_commission' => 0, 'due' => 0, 'paid' => 0, 'balance' => 0, 'total_due_all' => 0, 'total_paid_all' => 0];
    };
    foreach (['purchase' => 'purchases', 'sale' => 'sales'] as $kind => $tbl) {
        $sql = "SELECT d.broker_id AS bid, pa.name, d.broker_payer AS payer, COUNT(*) AS n, COALESCE(SUM(d.broker_amount),0) AS s
            FROM $tbl d JOIN parties pa ON pa.id=d.broker_id AND pa.customer_id=d.customer_id WHERE d.customer_id=? AND d.broker_amount > 0";
        $args = [$cid];
        if ($from) { $sql .= ' AND d.date >= ?'; $args[] = $from; }
        if ($to) { $sql .= ' AND d.date <= ?'; $args[] = $to; }
        if ($only > 0) { $sql .= ' AND d.broker_id=?'; $args[] = $only; }
        $st = $pdo->prepare($sql . ' GROUP BY d.broker_id, pa.name, d.broker_payer');
        $st->execute($args);
        foreach ($st->fetchAll() as $r) {
            $get((int)$r['bid'], (string)$r['name']);
            $id = (int)$r['bid'];
            $rows[$id][$kind . '_count'] += (int)$r['n'];
            if ($r['payer'] === 'us') { $rows[$id][$kind . '_commission'] += (int)$r['s']; $rows[$id]['due'] += (int)$r['s']; }
            else $rows[$id]['counterparty_commission'] += (int)$r['s'];
        }
    }
    $sql = 'SELECT b.broker_id AS bid, pa.name, COALESCE(SUM(b.amount),0) AS s FROM broker_payments b JOIN parties pa ON pa.id=b.broker_id AND pa.customer_id=b.customer_id WHERE b.customer_id=?';
    $args = [$cid];
    if ($from) { $sql .= ' AND b.date >= ?'; $args[] = $from; }
    if ($to) { $sql .= ' AND b.date <= ?'; $args[] = $to; }
    if ($only > 0) { $sql .= ' AND b.broker_id=?'; $args[] = $only; }
    $st = $pdo->prepare($sql . ' GROUP BY b.broker_id, pa.name');
    $st->execute($args);
    foreach ($st->fetchAll() as $r) { $get((int)$r['bid'], (string)$r['name']); $rows[(int)$r['bid']]['paid'] += (int)$r['s']; }
    // all-time outstanding per broker (independent of the period)
    foreach ($rows as $id => &$r) {
        $due = 0;
        foreach (['purchases', 'sales'] as $tbl) {
            $q = $pdo->prepare("SELECT COALESCE(SUM(broker_amount),0) FROM $tbl WHERE customer_id=? AND broker_id=? AND broker_payer='us' AND broker_amount > 0");
            $q->execute([$cid, $id]);
            $due += (int)$q->fetchColumn();
        }
        $q = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM broker_payments WHERE customer_id=? AND broker_id=?');
        $q->execute([$cid, $id]);
        $paid = (int)$q->fetchColumn();
        $r['total_due_all'] = $due; $r['total_paid_all'] = $paid; $r['balance'] = $due - $paid; // >0 = we still owe the broker
    }
    unset($r);
    $list = array_values($rows);
    usort($list, fn($a, $b) => strcmp($a['broker_name'], $b['broker_name']));
    $tot = ['purchase_commission' => 0, 'sale_commission' => 0, 'counterparty_commission' => 0, 'due' => 0, 'paid' => 0, 'balance' => 0];
    foreach ($list as $r) foreach ($tot as $k => $_) $tot[$k] += $r[$k];
    return ['from' => $from, 'to' => $to, 'brokers' => $list, 'totals' => $tot];
}

// ---------------- attach to an existing document (T05.2; used by the online-only Flutter dialog) ----------------
/** broker.attach {kind:'purchase'|'sale', doc_id, broker_id (0/empty = clear), broker_basis?, broker_value?, broker_payer?} */
function pb_broker_attach(PDO $pdo, int $cid, array $p, $customer): array {
    $kind = (string)($p['kind'] ?? '');
    if (!in_array($kind, ['purchase', 'sale'], true)) bad('نوع سند نامعتبر است');
    if (!pb_perm_check($customer, $kind === 'purchase' ? 'purchases.create' : 'sales.create')) bad('دسترسی لازم را ندارید', 403);
    $id = (int)($p['doc_id'] ?? 0);
    $tbl = $kind === 'purchase' ? 'purchases' : 'sales';
    $st = $pdo->prepare("SELECT id FROM $tbl WHERE id=? AND customer_id=?" . ($kind === 'purchase' ? ' AND is_mix=0' : ''));
    $st->execute([$id, $cid]);
    if (!$st->fetchColumn()) bad('سند یافت نشد', 404);
    $pl = ['broker_id' => (int)($p['broker_id'] ?? 0)];
    foreach (['broker_basis', 'broker_value', 'broker_payer'] as $k) if (array_key_exists($k, $p)) $pl[$k] = $p[$k];
    $pdo->beginTransaction();
    try {
        pb_broker_apply($pdo, $cid, $kind, $id, $pl);
        if ($kind === 'purchase') pb_stock_post_doc($pdo, $cid, 'purchase', $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $q = $pdo->prepare("SELECT broker_id, broker_basis, broker_value, broker_amount, broker_payer FROM $tbl WHERE id=? AND customer_id=?");
    $q->execute([$id, $cid]);
    return ['ok' => true, 'broker' => $q->fetch() ?: null];
}
