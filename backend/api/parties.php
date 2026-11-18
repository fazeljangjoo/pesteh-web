<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S03.T03.1 — party master. `parties` (customer_id, name) stays the key table; matching with purchases/sales/loans is by
 * normalized name (partyKey in app.php). Extra columns are additive; name-only parties remain valid.
 * Opening balance: integer (same unit as stored amounts), side debtor = they owe us, creditor = we owe them.
 */
const PB_PARTY_STAGES = ['lead', 'active', 'inactive', 'lost']; // T03.3
const PB_PARTY_TYPES = ['customer', 'supplier', 'broker', 'employee', 'processor', 'consignor', 'other'];

/** Adds the T03.1 columns (idempotent). */
function pb_party_ensure_cols(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS parties (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        UNIQUE KEY uq_party (customer_id, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $add = [
        'types' => 'VARCHAR(200) NULL', 'phone' => 'VARCHAR(30) NULL', 'address' => 'VARCHAR(300) NULL',
        'national_id' => 'VARCHAR(20) NULL', 'economic_code' => 'VARCHAR(20) NULL', 'postal_code' => 'VARCHAR(20) NULL',
        'credit_limit' => 'BIGINT NULL', 'opening_balance' => 'BIGINT NOT NULL DEFAULT 0',
        'opening_side' => "VARCHAR(10) NOT NULL DEFAULT 'debtor'", 'active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'note' => 'VARCHAR(500) NULL', 'created_at' => 'DATETIME NULL',
        'tags' => 'VARCHAR(300) NULL', 'stage' => "VARCHAR(20) NOT NULL DEFAULT 'active'", // T03.3 CRM
    ];
    foreach ($add as $col => $def) {
        if (!pb_col_exists($pdo, 'parties', $col)) $pdo->exec("ALTER TABLE parties ADD COLUMN `$col` $def");
    }
}

/** Creates a master row for every name used in purchases (seller), sales (buyer) and loans (party). Idempotent (INSERT IGNORE). */
function pb_party_sync(PDO $pdo, int $cid): void {
    $norm = "REPLACE(REPLACE(TRIM(%s),'ي','ی'),'ك','ک')";
    $srcs = [['purchases', 'seller', 'AND is_mix=0'], ['sales', 'buyer', ''], ['loan_entries', 'party', '']];
    foreach ($srcs as [$t, $c, $extra]) {
        try {
            $n = sprintf($norm, $c);
            $pdo->prepare("INSERT IGNORE INTO parties (customer_id, name, created_at)
                SELECT DISTINCT ?, $n, NOW() FROM `$t` WHERE customer_id=? AND $c IS NOT NULL AND TRIM($c) <> '' AND CHAR_LENGTH($n) <= 100 $extra")
                ->execute([$cid, $cid]);
        } catch (Throwable $e) {
            error_log('[party_sync] ' . $t . ': ' . $e->getMessage());
        }
    }
}

/** T03.3: tags are stored as a JSON array of short strings. */
function pb_party_tags_decode($raw): array {
    $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($j) ? array_values(array_filter($j, fn($t) => is_string($t) && $t !== '')) : [];
}

/** T03.3: normalize tags input (max 10 tags x 30 chars, unique). */
function pb_party_tags_clean($v): array {
    $out = [];
    foreach ((array)$v as $t) {
        if (!is_string($t)) continue;
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t === '') continue;
        if (mb_strlen($t) > 30) bad('هر برچسب حداکثر ۳۰ حرف است');
        if (!in_array($t, $out, true)) $out[] = $t;
    }
    if (count($out) > 10) bad('حداکثر ۱۰ برچسب مجاز است');
    return $out;
}

/** Public/extra fields of a master row (null row = name-only defaults). */
function pb_party_public(?array $r): array {
    $types = [];
    if ($r && !empty($r['types'])) {
        $j = json_decode((string)$r['types'], true);
        if (is_array($j)) $types = array_values(array_filter($j, fn($t) => is_string($t) && in_array($t, PB_PARTY_TYPES, true)));
    }
    $bal = $r ? (int)($r['opening_balance'] ?? 0) : 0;
    $side = ($r['opening_side'] ?? 'debtor') === 'creditor' ? 'creditor' : 'debtor';
    return [
        'party_id' => $r ? (int)$r['id'] : null, 'types' => $types,
        'phone' => $r['phone'] ?? null, 'address' => $r['address'] ?? null, 'national_id' => $r['national_id'] ?? null,
        'economic_code' => $r['economic_code'] ?? null, 'postal_code' => $r['postal_code'] ?? null,
        'credit_limit' => isset($r['credit_limit']) ? (int)$r['credit_limit'] : null,
        'opening_balance' => $bal, 'opening_side' => $side, 'opening' => $side === 'creditor' ? -$bal : $bal,
        'active' => $r ? ((int)($r['active'] ?? 1) === 1) : true, 'note' => $r['note'] ?? null,
        'tags' => pb_party_tags_decode($r['tags'] ?? null), 'stage' => in_array($r['stage'] ?? '', PB_PARTY_STAGES, true) ? $r['stage'] : 'active',
    ];
}

function pb_party_txt($v, int $max, string $label): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if (mb_strlen($s) > $max) bad($label . ' بیش از حد طولانی است');
    return $s;
}

/** parties.save: create (by name) or update (by id). The name of an existing party is immutable (purchases/sales match by name). */
function pb_party_save(PDO $pdo, int $cid, array $p): void {
    $id = (int)($p['id'] ?? 0);
    $name = partyKey($p['name'] ?? '');
    $row = null;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM parties WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $row = $st->fetch() ?: null;
        if (!$row) bad('طرف‌حساب پیدا نشد', 404);
    } else {
        if ($name === '' || mb_strlen($name) > 100) bad('نام طرف‌حساب را (حداکثر ۱۰۰ حرف) وارد کنید');
        $st = $pdo->prepare('SELECT * FROM parties WHERE customer_id=? AND name=?');
        $st->execute([$cid, $name]);
        $row = $st->fetch() ?: null;
    }
    $types = [];
    foreach ((array)($p['types'] ?? []) as $t) {
        if (is_string($t) && in_array($t, PB_PARTY_TYPES, true) && !in_array($t, $types, true)) $types[] = $t;
    }
    $side = ($p['opening_side'] ?? 'debtor') === 'creditor' ? 'creditor' : 'debtor';
    $bal = $p['opening_balance'] ?? 0;
    if ($bal === '' || $bal === null) $bal = 0;
    if (!is_numeric($bal) || (float)$bal < 0 || (float)$bal > 1e13) bad('مانده افتتاحیه نامعتبر است');
    $bal = (int)round((float)$bal);
    $lim = $p['credit_limit'] ?? null;
    if ($lim === '' || $lim === null) $lim = null;
    elseif (!is_numeric($lim) || (float)$lim < 0 || (float)$lim > 1e13) bad('سقف اعتبار نامعتبر است');
    else $lim = (int)round((float)$lim);
    $vals = [
        json_encode($types), pb_party_txt($p['phone'] ?? null, 30, 'تلفن'), pb_party_txt($p['address'] ?? null, 300, 'نشانی'),
        pb_party_txt($p['national_id'] ?? null, 20, 'کد/شناسهٔ ملی'), pb_party_txt($p['economic_code'] ?? null, 20, 'کد اقتصادی'),
        pb_party_txt($p['postal_code'] ?? null, 20, 'کد پستی'), $lim, $bal, $side,
        array_key_exists('active', $p) ? ((($p['active'] === false) || $p['active'] === 0 || $p['active'] === '0') ? 0 : 1) : 1,
        pb_party_txt($p['note'] ?? null, 500, 'یادداشت'),
    ];
    $set = 'types=?, phone=?, address=?, national_id=?, economic_code=?, postal_code=?, credit_limit=?, opening_balance=?, opening_side=?, active=?, note=?';
    $crmSet = ''; $crmVals = [];
    if (array_key_exists('tags', $p)) { $crmSet .= ', tags=?'; $crmVals[] = json_encode(pb_party_tags_clean($p['tags']), JSON_UNESCAPED_UNICODE); }
    if (array_key_exists('stage', $p)) {
        if (!in_array($p['stage'], PB_PARTY_STAGES, true)) bad('مرحلهٔ طرف‌حساب نامعتبر است');
        $crmSet .= ', stage=?'; $crmVals[] = $p['stage'];
    }
    if ($row) {
        $pdo->prepare("UPDATE parties SET $set$crmSet WHERE id=? AND customer_id=?")->execute(array_merge($vals, $crmVals, [(int)$row['id'], $cid]));
        pb_taf_hook($pdo, $cid, 'party', $name, $name);
        out(['id' => (int)$row['id']]);
    }
    $pdo->prepare("INSERT INTO parties (customer_id, name, created_at, types, phone, address, national_id, economic_code, postal_code, credit_limit, opening_balance, opening_side, active, note)
        VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$cid, $name], $vals));
    $newId = (int)$pdo->lastInsertId();
    if ($crmSet !== '') $pdo->prepare('UPDATE parties SET ' . ltrim($crmSet, ', ') . ' WHERE id=? AND customer_id=?')->execute(array_merge($crmVals, [$newId, $cid]));
    pb_taf_hook($pdo, $cid, 'party', $name, $name);
    out(['id' => $newId]);
}

/** S35.T35.7e: party balance sums (purchases, sales, returns, broker) — verbatim extraction of the parties.list aggregation. Returns the raw map keyed by partyKey(). */
function pb_party_balance_sums(PDO $pdo, int $cid): array {
    $map = [];
    $acc = function ($name) use (&$map) {
        $k = partyKey($name);
        if (!isset($map[$k])) $map[$k] = ['key' => $k, 'bought' => 0, 'paid' => 0, 'sold' => 0, 'received' => 0, 'entries' => [],
            'loans' => [], 'dues' => [], 'loanGiven' => 0, 'loanTaken' => 0, 'repaidToUs' => 0, 'repaidByUs' => 0];
        return $k;
    };
    $settledOf = function ($type, $staged, $lp, $cp, $hp) {
        if ($type === 'lump') return (float)$lp;
        if ($type === 'staged' && $staged === 'credit') return (float)$cp;
        if ($type === 'staged' && $staged === 'cheque') return (float)$hp;
        if ($type === 'mixed') return (float)$lp + (float)$cp + (float)$hp; // S35.T35.7h: all paid lines of a mixed document
        return 0.0;
    };
    $pur = $pdo->prepare("SELECT p.id AS id, p.seller AS party, p.date AS date, p.type_name AS label, p.net_weight AS net, p.price AS price,
        pay.pay_type AS pt, pay.staged_type AS st,
        COALESCE((SELECT SUM(amount) FROM lump_tx WHERE payment_id=pay.id AND paid=1),0) AS lp,
        COALESCE((SELECT SUM(amount) FROM credit_inst WHERE payment_id=pay.id AND paid=1),0) AS cp,
        COALESCE((SELECT SUM(amount) FROM cheque_inst WHERE payment_id=pay.id AND paid=1),0) AS hp
        FROM purchases p LEFT JOIN payments pay ON pay.purchase_id=p.id AND pay.customer_id=p.customer_id WHERE p.customer_id=? AND p.is_mix=0");
    $pur->execute([$cid]);
    foreach ($pur->fetchAll() as $r) {
        $total = (float)$r['net'] * (float)$r['price'];
        $settled = $settledOf($r['pt'], $r['st'], $r['lp'], $r['cp'], $r['hp']);
        $k = $acc($r['party']);
        $map[$k]['bought'] += $total;
        $map[$k]['paid'] += $settled;
        $map[$k]['entries'][] = ['is_purchase' => true, 'id' => (int)$r['id'], 'date' => $r['date'], 'label' => (string)$r['label'],
            'weight' => (float)$r['net'], 'total' => $total, 'settled' => $settled];
    }
    $sal = $pdo->prepare("SELECT s.id AS id, s.buyer AS party, s.date AS date, s.net_weight AS net, s.price AS price, rec.recv_type AS rt, rec.staged_type AS st,
        COALESCE((SELECT SUM(amount) FROM receipt_lump_tx WHERE receipt_id=rec.id AND paid=1),0) AS lp,
        COALESCE((SELECT SUM(amount) FROM receipt_credit_inst WHERE receipt_id=rec.id AND paid=1),0) AS cp,
        COALESCE((SELECT SUM(amount) FROM receipt_cheque_inst WHERE receipt_id=rec.id AND paid=1),0) AS hp
        FROM sales s LEFT JOIN receipts rec ON rec.sale_id=s.id AND rec.customer_id=s.customer_id WHERE s.customer_id=?");
    $sal->execute([$cid]);
    foreach ($sal->fetchAll() as $r) {
        $total = (float)$r['net'] * (float)$r['price'];
        $settled = $settledOf($r['rt'], $r['st'], $r['lp'], $r['cp'], $r['hp']);
        $k = $acc($r['party']);
        $map[$k]['sold'] += $total;
        $map[$k]['received'] += $settled;
        $map[$k]['entries'][] = ['is_purchase' => false, 'id' => (int)$r['id'], 'date' => $r['date'], 'label' => 'فروش',
            'weight' => (float)$r['net'], 'total' => $total, 'settled' => $settled];
    }
    foreach (pb_preturn_party_sums($pdo, $cid) as $rk => $rs) { // S04.T04.1: return = credit against payable; refund = reverse of a payment
        $k = $acc($rk);
        $map[$k]['bought'] -= $rs['value'];
        $map[$k]['paid'] -= $rs['refunded'];
        $map[$k]['returnedOut'] = $rs['value'];
        $map[$k]['returnRefunded'] = $rs['refunded'];
    }
    foreach (pb_sreturn_party_sums($pdo, $cid) as $rk => $rs) { // S04.T04.2: sale return = credit against receivable; refund = reverse of a receipt
        $k = $acc($rk);
        $map[$k]['sold'] -= $rs['value'];
        $map[$k]['received'] -= $rs['refunded'];
        $map[$k]['returnedIn'] = $rs['value'];
        $map[$k]['returnInRefunded'] = $rs['refunded'];
    }
    foreach (pb_broker_party_sums($pdo, $cid) as $rk => $rs) { // S05.T05.1: commission due = payable, payments = paid
        $k = $acc($rk);
        $map[$k]['bought'] += $rs['due'];
        $map[$k]['paid'] += $rs['paid'];
        $map[$k]['brokerDue'] = $rs['due'];
        $map[$k]['brokerPaid'] = $rs['paid'];
    }
    return $map;
}
