<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S35.T35.9c — correction document (سند اصلاحی) for a FINAL purchase/sale: corrects price and/or net weight.
 * Action: docs.correct {doc_type: purchase|sale, id, price?, net_weight?, reason(5-300)}  (owner only, online).
 * Keeps old/new values + reason + user in doc_corrections and re-posts stock + voucher through the normal hooks.
 * Refused (safe, explicit message) for documents whose price/weight feed other computed data: foreign-currency docs,
 * grade-line docs, taxed docs, mixes/consigned lots, documents with returns, multi-item sales, and weight cuts below what was already sold.
 * Payments/receipts are NOT touched: the user adjusts them on the payment screen if the total changed.
 */

function pb_corr_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS doc_corrections (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        doc_type VARCHAR(10) NOT NULL,
        doc_id INT UNSIGNED NOT NULL,
        old_price DECIMAL(20,4) NOT NULL,
        new_price DECIMAL(20,4) NOT NULL,
        old_net DECIMAL(12,3) NOT NULL,
        new_net DECIMAL(12,3) NOT NULL,
        reason VARCHAR(300) NOT NULL,
        created_by VARCHAR(120) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY k_corr_doc (customer_id, doc_type, doc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_corr_fail(PDO $pdo, string $msg): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    bad($msg);
}

function pb_corr_apply(PDO $pdo, int $cid, array $customer, array $p): array {
    if ((int)($customer['_staff_id'] ?? 0) > 0) bad('فقط مالک می‌تواند سند اصلاحی ثبت کند', 403);
    $type = (string)($p['doc_type'] ?? '');
    if (!in_array($type, ['purchase', 'sale'], true)) bad('نوع سند نامعتبر است');
    $id = (int)($p['id'] ?? 0);
    if ($id <= 0) bad('شناسهٔ سند نامعتبر است');
    $reason = trim((string)($p['reason'] ?? ''));
    $rl = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($rl < 5 || $rl > 300) bad('دلیل اصلاح باید ۵ تا ۳۰۰ نویسه باشد');
    $newPrice = (isset($p['price']) && $p['price'] !== '' && $p['price'] !== null) ? (float)$p['price'] : null;
    $newNet = (isset($p['net_weight']) && $p['net_weight'] !== '' && $p['net_weight'] !== null) ? round((float)$p['net_weight'], 3) : null;
    if ($newPrice !== null && ($newPrice <= 0 || $newPrice > 1e12)) bad('فی نامعتبر است');
    if ($newNet !== null && ($newNet <= 0 || $newNet > 1e9)) bad('وزن نامعتبر است');
    if ($newPrice === null && $newNet === null) bad('فی یا وزن جدید را وارد کنید');
    pb_corr_ensure($pdo);

    $pdo->beginTransaction();
    $tbl = $type === 'sale' ? 'sales' : 'purchases';
    $st = $pdo->prepare("SELECT * FROM $tbl WHERE id=? AND customer_id=? FOR UPDATE");
    $st->execute([$id, $cid]);
    $d = $st->fetch();
    if (!$d) pb_corr_fail($pdo, 'سند یافت نشد');
    if (!empty($d['ccy'])) pb_corr_fail($pdo, 'این سند ارزی است؛ برای اصلاح نرخ از «سند تعدیل نرخ» استفاده کنید');
    if ($d['tax_amount'] !== null) pb_corr_fail($pdo, 'این سند مالیات دارد؛ اصلاح مستقیم ممکن نیست (حذف و ثبت مجدد)');
    $g = $pdo->prepare('SELECT COUNT(*) FROM doc_grade_lines WHERE customer_id=? AND doc_type=? AND doc_id=?');
    $g->execute([$cid, $type, $id]);
    if ((int)$g->fetchColumn() > 0) pb_corr_fail($pdo, 'این سند فی درجه‌ای دارد؛ اصلاح مستقیم ممکن نیست');
    $oldPrice = (float)$d['price'];
    $oldNet = (float)$d['net_weight'];
    $price = $newPrice ?? $oldPrice;
    $net = $newNet ?? $oldNet;
    $delta = round($net - $oldNet, 3);

    if ($type === 'purchase') {
        if ((int)$d['is_mix'] !== 0) pb_corr_fail($pdo, 'اصلاح مخلوط یا کالای امانی ممکن نیست');
        if (pb_sreturn_lot_has_returns($pdo, $cid, $id)) pb_corr_fail($pdo, 'این خرید برگشت دارد؛ اصلاح ممکن نیست');
        $newRemaining = round((float)$d['remaining'] + $delta, 3);
        if ($newRemaining < -1e-9) pb_corr_fail($pdo, 'وزن جدید کمتر از مقدار فروخته‌شده است');
        $pdo->prepare('UPDATE purchases SET price=?, net_weight=?, remaining=?, total_weight=total_weight+? WHERE id=? AND customer_id=?')
            ->execute([$price, $net, max(0.0, $newRemaining), $delta, $id, $cid]);
    } else {
        if (pb_sreturn_sale_has_returns($pdo, $cid, $id)) pb_corr_fail($pdo, 'این فروش برگشت دارد؛ اصلاح ممکن نیست');
        $it = $pdo->prepare('SELECT purchase_id FROM sale_items WHERE sale_id=? AND customer_id=?');
        $it->execute([$id, $cid]);
        $items = $it->fetchAll();
        if (count($items) !== 1) pb_corr_fail($pdo, 'اصلاح فقط برای فروش تک‌قلمی ممکن است');
        $lot = $pdo->prepare('SELECT remaining FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
        $lot->execute([(int)$items[0]['purchase_id'], $cid]);
        $rem = $lot->fetchColumn();
        if ($rem === false) pb_corr_fail($pdo, 'پستهٔ این فروش یافت نشد');
        if ($delta > 0 && $delta > (float)$rem + 1e-9) pb_corr_fail($pdo, 'موجودی پسته برای افزایش وزن فروش کافی نیست');
        $pdo->prepare('UPDATE purchases SET remaining = remaining - ? WHERE id=? AND customer_id=?')->execute([$delta, (int)$items[0]['purchase_id'], $cid]);
        $pdo->prepare('UPDATE sales SET price=?, net_weight=?, total_weight=total_weight+? WHERE id=? AND customer_id=?')->execute([$price, $net, $delta, $id, $cid]);
        $pdo->prepare('UPDATE sale_items SET item_price=?, weight=? WHERE sale_id=? AND customer_id=?')->execute([$price, $net, $id, $cid]);
    }
    pb_stock_post_doc($pdo, $cid, $type, $id); // reverse + repost the stock ledger of this document
    pb_post_hook($pdo, $customer, $type, $id); // replaces the document voucher (gold only)
    $by = (string)($customer['_staff_name'] ?? '') !== '' ? (string)$customer['_staff_name'] : 'مالک';
    $pdo->prepare('INSERT INTO doc_corrections (customer_id, doc_type, doc_id, old_price, new_price, old_net, new_net, reason, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $type, $id, $oldPrice, $price, $oldNet, $net, $reason, $by]);
    $cid2 = (int)$pdo->lastInsertId();
    $pdo->commit();
    return ['ok' => true, 'id' => $cid2, 'price' => $price, 'net_weight' => $net];
}
