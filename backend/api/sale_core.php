<?php
declare(strict_types=1);
/**
 * S08.T08.2 — core of `sales.create`, moved VERBATIM out of app.php so other documents (consignee "sold" reports) can create a
 * sale through the exact same code path. Same validation, same stock/ledger/broker/receipt side effects, same error messages.
 * The only differences from the old inline case: it RETURNS ['id'=>..] instead of calling out(), `$isFx` is derived from `$customer`,
 * and an optional $beforeCommit(int $saleId) hook runs inside the transaction right before commit (null = old behaviour).
 */
function pb_sale_create_core(PDO $pdo, int $cid, array $payload, array $customer, ?callable $beforeCommit = null): array {
    $isFx = customer_plan($customer) === PLAN_FX;
    pb_weigh_boot($pdo, $cid); // S35.T35.2
    $fallback = num($payload['price'] ?? 0);
    if ($fallback < 0 || $fallback > 1e13) bad('فی فروش نامعتبر است');
    $it = readSingleSaleItem($payload, $fallback);
    $grades = pb_grades_read($pdo, $cid, $payload, (float)$it['weight']); // S35.T35.3: weighted price from grade lines
    if ($grades) $it['price'] = (float)$grades['price'];
    pb_wh_check_sale($pdo, $cid, $payload, (int)$it['purchase_id']); // S01: optional warehouse_uid
    $saleUsdRate = readUsdRate($payload, $isFx);
    $laborCost = costNum($payload['labor_cost'] ?? null);
    $transportCost = costNum($payload['transport_cost'] ?? null);
    $sDate = vdate($payload['date'] ?? null);
    $fxDoc = pb_fx_doc_currency($pdo, $cid, $payload, $sDate, $isFx); // S27.T27.1: foreign sale (rate from table)
    if ($fxDoc) $it['price'] = $fxDoc['price_stored'];
    if ($fxDoc) $saleUsdRate = null; // T30.3b: foreign-currency docs: legacy usd_rate not written
    pb_fy_guard($pdo, $cid, $customer, $sDate); // S15.T15.1 (gold only)
    $buyer = str_or_null($payload['buyer'] ?? null, 150);
    $note = str_or_null($payload['note'] ?? null, 500);

    $pdo->beginTransaction();
    $lockSt = $pdo->prepare('SELECT remaining, small_sacks_remaining, large_sacks_remaining FROM purchases WHERE id=? AND customer_id=? FOR UPDATE');
    $lockSt->execute([$it['purchase_id'], $cid]);
    $row = $lockSt->fetch();
    if (!$row) { $pdo->rollBack(); bad('قلم انتخاب‌شده در انبار یافت نشد'); }
    if ($it['weight'] - (float)$row['remaining'] > 1e-6) { $pdo->rollBack(); bad('وزن فروش از موجودی این قلم بیشتر است'); }
    checkSacksAvailable($pdo, $row, $it['small_sacks'], $it['large_sacks']);
    $saleWh = pb_stock_pick_wh_or_bad($pdo, $cid, (int)$it['purchase_id'], (float)$it['weight'], $payload['warehouse_uid'] ?? null); // S01.T01.3

    $net = $it['weight'];
    $total = $net + tare($it['small_sacks'], $it['large_sacks']);
    $weigh = pb_weigh_read($pdo, $cid, $payload + ['net_weight' => $net], (float)($it['small_sacks'] ?? 0), (float)($it['large_sacks'] ?? 0)); // S35.T35.2: block must add up to the stock weight
    if ($weigh) { if (abs($weigh['net'] - $net) > PB_WEIGH_TOL) { $pdo->rollBack(); bad('وزن خالص با وزن کل منهای تارا و کسر کیفیت نمی‌خواند'); } $total = $weigh['gross']; }
    $pdo->prepare('INSERT INTO sales (customer_id, date, price, total_weight, small_sack_count, large_sack_count, net_weight, buyer, note, usd_rate, labor_cost, transport_cost) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $sDate, $it['price'], $total, (float)($it['small_sacks'] ?? 0), (float)($it['large_sacks'] ?? 0), $net, $buyer, $note, $saleUsdRate, $laborCost, $transportCost]);
    $saleId = (int)$pdo->lastInsertId();
    pb_weigh_store($pdo, $cid, 'sales', $saleId, $weigh); // S35.T35.2
    pb_grades_store($pdo, $cid, 'sale', $saleId, $grades, (float)$net); // S35.T35.3
    if ($fxDoc) pb_fx_doc_stamp($pdo, $cid, 'sales', $saleId, $fxDoc, $fxDoc['price_ccy'] * $net); // S27.T27.1
    $pdo->prepare('INSERT INTO sale_items (customer_id, sale_id, purchase_id, weight, item_price, small_sacks, large_sacks) VALUES (?,?,?,?,?,?,?)')
        ->execute([$cid, $saleId, $it['purchase_id'], $net, $it['price'], $it['small_sacks'], $it['large_sacks']]);
    $pdo->prepare('UPDATE sale_items SET warehouse_uid=? WHERE sale_id=? AND customer_id=?')->execute([$saleWh, $saleId, $cid]); // S01.T01.3
    // وزن همیشه کم می‌شود؛ گونی فقط اگر وارد شده باشد (هر کدام جدا)
    $pdo->prepare('UPDATE purchases SET remaining = remaining - ?, small_sacks_remaining = small_sacks_remaining - ?, large_sacks_remaining = large_sacks_remaining - ? WHERE id=? AND customer_id=?')
        ->execute([$net, (float)($it['small_sacks'] ?? 0), (float)($it['large_sacks'] ?? 0), $it['purchase_id'], $cid]);
    pb_broker_apply($pdo, $cid, 'sale', $saleId, $payload); // S05.T05.1: selling expense when payer=us
    if (isset($payload['receipt'])) saveReceipt($pdo, $cid, $saleId, is_array($payload['receipt']) ? $payload['receipt'] : []);
    pb_stock_post_doc($pdo, $cid, 'sale', $saleId); // S01.T01.2
    pb_fin_apply($pdo, $cid, 'sales', (int)$saleId, $payload); // S35.T35.5
    pb_tax_apply_doc($pdo, $customer, 'sale', (int)$saleId, $payload); // S17.T17.1c
    pb_post_hook($pdo, $customer, 'sale', $saleId); // S16.T16.3 (before the consignment link: a consigned-goods sale posts nothing here)
    pb_appr_mark($pdo, $cid, $customer, 'sale', $saleId); // S35.T35.9e
    if ($beforeCommit !== null) $beforeCommit($saleId); // S08.T08.2: link the sale to its consignment atomically
    $pdo->commit();
    return ['id' => $saleId];
}
