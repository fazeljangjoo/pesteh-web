<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S31.T31.10 — export fields on a sales invoice (FX plan only): Incoterms, shipping, currency code, remittance (حواله) number, LC-opening bank.
 * Side table `sale_export` (one row per sale, upsert) so the hot sales create/update paths stay untouched. Purely informational: no stock/ledger effect.
 * Actions: sale_export.get {sale_id} -> {sale_id, fields|null, defaults:{ccy_code}} | sale_export.docs {sale_id} (T31.11 packing list / origin data) | sale_export.save {sale_id, pkg_count?, pkg_type?, gross_kg?, marks?, origin_country?, hs_code?, cert_no?, incoterm?, ship_method?, port_loading?, port_destination?, ccy_code?, remit_no?, lc_bank?, lc_no?}
 * All fields optional; an all-empty save deletes the row. Server enforces the plan gate (403 edition_required need=fx).
 */
const PB_EXPORT_INCOTERMS = ['EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];
const PB_EXPORT_SHIP = ['sea', 'air', 'road', 'rail', 'multimodal'];

function pb_sx_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS sale_export (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        sale_id INT UNSIGNED NOT NULL,
        incoterm VARCHAR(4) NULL,
        ship_method VARCHAR(12) NULL,
        port_loading VARCHAR(100) NULL,
        port_destination VARCHAR(100) NULL,
        ccy_code VARCHAR(8) NULL,
        remit_no VARCHAR(40) NULL,
        lc_bank VARCHAR(100) NULL,
        lc_no VARCHAR(40) NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_sx_sale (customer_id, sale_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // S31.T31.11 packing list / certificate of origin fields (v57)
    require_once __DIR__ . '/migrations.php';
    $add = ['pkg_count' => 'INT UNSIGNED NULL', 'pkg_type' => 'VARCHAR(30) NULL', 'gross_kg' => 'DECIMAL(12,3) NULL', 'marks' => 'VARCHAR(200) NULL',
        'origin_country' => 'VARCHAR(60) NULL', 'hs_code' => 'VARCHAR(20) NULL', 'cert_no' => 'VARCHAR(40) NULL'];
    foreach ($add as $c => $def) {
        if (!pb_col_exists($pdo, 'sale_export', $c)) $pdo->exec("ALTER TABLE sale_export ADD COLUMN $c $def");
    }
}

function pb_sx_sale(PDO $pdo, int $cid, int $saleId): array {
    $st = $pdo->prepare('SELECT id, ccy FROM sales WHERE id=? AND customer_id=?');
    $st->execute([$saleId, $cid]);
    $s = $st->fetch();
    if (!$s) bad('فروش یافت نشد', 404);
    return $s;
}

function pb_sx_get(PDO $pdo, int $cid, int $saleId): array {
    $s = pb_sx_sale($pdo, $cid, $saleId);
    $st = $pdo->prepare('SELECT incoterm, ship_method, port_loading, port_destination, ccy_code, remit_no, lc_bank, lc_no, pkg_count, pkg_type, gross_kg, marks, origin_country, hs_code, cert_no FROM sale_export WHERE customer_id=? AND sale_id=?');
    $st->execute([$cid, $saleId]);
    $r = $st->fetch();
    return ['sale_id' => $saleId, 'fields' => $r ?: null, 'defaults' => ['ccy_code' => $s['ccy'] ? (string)$s['ccy'] : null],
        'incoterms' => PB_EXPORT_INCOTERMS, 'ship_methods' => PB_EXPORT_SHIP];
}

function pb_sx_save(PDO $pdo, int $cid, array $p): array {
    $saleId = (int)($p['sale_id'] ?? 0);
    pb_sx_sale($pdo, $cid, $saleId);
    $inc = strtoupper(trim((string)($p['incoterm'] ?? '')));
    if ($inc !== '' && !in_array($inc, PB_EXPORT_INCOTERMS, true)) bad('اینکوترمز نامعتبر است');
    $ship = trim((string)($p['ship_method'] ?? ''));
    if ($ship !== '' && !in_array($ship, PB_EXPORT_SHIP, true)) bad('روش حمل نامعتبر است');
    $ccy = strtoupper(trim((string)($p['ccy_code'] ?? '')));
    if ($ccy !== '' && !preg_match('/^[A-Z]{3,8}$/', $ccy)) bad('کد ارز نامعتبر است');
    $pk = $p['pkg_count'] ?? null;
    $pk = ($pk === null || $pk === '') ? null : (int)$pk;
    if ($pk !== null && ($pk < 0 || $pk > 10000000)) bad('تعداد بسته نامعتبر است');
    $gk = $p['gross_kg'] ?? null;
    $gk = ($gk === null || $gk === '') ? null : round((float)$gk, 3);
    if ($gk !== null && ($gk < 0 || $gk > 1e9)) bad('وزن ناخالص نامعتبر است');
    $cols = ['incoterm' => $inc ?: null, 'ship_method' => $ship ?: null, 'port_loading' => str_or_null($p['port_loading'] ?? null, 100),
        'port_destination' => str_or_null($p['port_destination'] ?? null, 100), 'ccy_code' => $ccy ?: null, 'remit_no' => str_or_null($p['remit_no'] ?? null, 40),
        'lc_bank' => str_or_null($p['lc_bank'] ?? null, 100), 'lc_no' => str_or_null($p['lc_no'] ?? null, 40),
        'pkg_count' => $pk, 'pkg_type' => str_or_null($p['pkg_type'] ?? null, 30), 'gross_kg' => $gk, 'marks' => str_or_null($p['marks'] ?? null, 200),
        'origin_country' => str_or_null($p['origin_country'] ?? null, 60), 'hs_code' => str_or_null($p['hs_code'] ?? null, 20), 'cert_no' => str_or_null($p['cert_no'] ?? null, 40)];
    if (count(array_filter($cols, static fn($x) => $x !== null)) === 0) {
        $pdo->prepare('DELETE FROM sale_export WHERE customer_id=? AND sale_id=?')->execute([$cid, $saleId]);
    } else {
        $names = array_keys($cols);
        $ph = implode(',', array_fill(0, count($names), '?'));
        $upd = implode(', ', array_map(static fn($n) => "$n=VALUES($n)", $names));
        $pdo->prepare('INSERT INTO sale_export (customer_id, sale_id, ' . implode(', ', $names) . ", updated_at) VALUES (?,?,$ph,NOW()) ON DUPLICATE KEY UPDATE $upd, updated_at=NOW()")
            ->execute(array_merge([$cid, $saleId], array_values($cols)));
    }
    return pb_sx_get($pdo, $cid, $saleId);
}

/**
 * sale_export.docs {sale_id} -> data for the packing list + certificate of origin PDFs (rendered client-side).
 * Read-only. Items are the sale's lots (lot code, product type, kg, sacks). Company = ent_company_profile (first live row).
 */
function pb_sx_docs(PDO $pdo, int $cid, int $saleId): array {
    $st = $pdo->prepare('SELECT id, date, total_weight, net_weight, small_sack_count, large_sack_count, buyer, note, ccy FROM sales WHERE id=? AND customer_id=?');
    $st->execute([$saleId, $cid]);
    $sale = $st->fetch();
    if (!$sale) bad('فروش یافت نشد', 404);
    $st = $pdo->prepare('SELECT si.weight, si.small_sacks, si.large_sacks, p.code AS lot_code, p.type_name FROM sale_items si
        LEFT JOIN purchases p ON p.id=si.purchase_id AND p.customer_id=si.customer_id WHERE si.customer_id=? AND si.sale_id=? ORDER BY si.id');
    $st->execute([$cid, $saleId]);
    $items = $st->fetchAll();
    $co = null;
    try {
        $st = $pdo->prepare('SELECT name, address, phones, economic_code, national_id, postal_code, registration_no FROM ent_company_profile WHERE customer_id=? AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $st->execute([$cid]);
        $co = $st->fetch() ?: null;
    } catch (Throwable $e) { $co = null; }
    $g = pb_sx_get($pdo, $cid, $saleId);
    return ['sale' => $sale, 'items' => $items, 'company' => $co, 'fields' => $g['fields'], 'defaults' => $g['defaults'],
        'note' => 'origin certificate is a data sheet only; the official certificate is issued by the Chamber of Commerce'];
}

function pb_sx_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (pb_plan_of($customer) !== 'fx') out(['error' => 'edition_required', 'need' => 'fx'], 403);
    $cid = (int)$customer['id'];
    pb_sx_ensure($pdo);
    if ($action === 'sale_export.save') out(pb_sx_save($pdo, $cid, $p));
    if ($action === 'sale_export.docs') out(pb_sx_docs($pdo, $cid, (int)($p['sale_id'] ?? 0)));
    out(pb_sx_get($pdo, $cid, (int)($p['sale_id'] ?? 0)));
}
