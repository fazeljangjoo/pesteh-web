<?php
declare(strict_types=1);
/**
 * S35.T35.9d — voucher dry-run preview (GOLD, display-only).
 * Action `posting.preview` {type:'purchase'|'sale', doc:{columns}, items:[{purchase_id,weight,item_price}] (sale), settle:{cash,bank,cheque} (toman)}
 * Runs the SAME loader + declarative rules + role resolution as the real posting (posting.php) on an UNSAVED document.
 * It never writes: no voucher, no numbering, no audit row. Missing account roles are reported, not fatal.
 * Returns {ok, lines:[{account_code,account_name,tafsili_name,debit,credit,description}], total_debit, total_credit, balanced, date, description, missing[]}.
 */

const PB_PREVIEW_COLS = ['date', 'price', 'net_weight', 'discount', 'labor_cost', 'transport_cost', 'weighbridge_cost', 'side_cost_payer',
    'broker_id', 'broker_payer', 'broker_amount', 'total_tax', 'seller', 'buyer', 'code'];

function pb_preview_action(array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    $type = (string)($p['type'] ?? '');
    if ($type !== 'purchase' && $type !== 'sale') bad('نوع سند برای پیش‌نمایش پشتیبانی نمی‌شود');
    $doc = is_array($p['doc'] ?? null) ? $p['doc'] : [];
    $row = [];
    foreach (PB_PREVIEW_COLS as $c) if (array_key_exists($c, $doc)) $row[$c] = $doc[$c];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($row['date'] ?? ''))) bad('تاریخ سند نامعتبر است');
    if (!array_key_exists('discount', $row) && isset($doc['discount_amount']) && is_numeric($doc['discount_amount'])) $row['discount'] = $doc['discount_amount']; // client form key (T35.6c/d)
    foreach (['price', 'net_weight', 'discount', 'labor_cost', 'transport_cost', 'weighbridge_cost', 'broker_amount', 'total_tax'] as $c) {
        if (array_key_exists($c, $row) && $row[$c] !== null && !is_numeric($row[$c])) bad('مقدار عددی نامعتبر: ' . $c);
    }
    $sv = is_array($p['settle'] ?? null) ? $p['settle'] : [];
    $settle = [pb_pl_rial((float)($sv['cash'] ?? 0)), pb_pl_rial((float)($sv['bank'] ?? 0)), pb_pl_rial((float)($sv['cheque'] ?? 0))];
    $pv = ['row' => $row, 'settle' => $settle];
    if ($type === 'sale') {
        $items = [];
        foreach ((array)($p['items'] ?? []) as $i) {
            if (!is_array($i)) continue;
            $items[] = ['purchase_id' => (int)($i['purchase_id'] ?? 0), 'weight' => (float)($i['weight'] ?? 0), 'item_price' => (float)($i['item_price'] ?? 0)];
            if (count($items) >= 200) break;
        }
        if (!$items) bad('ردیف کالا برای پیش‌نمایش ارسال نشده است');
        $pv['items'] = $items;
        $goodsT = 0.0;
        foreach ($items as $i) $goodsT += $i['weight'] * $i['item_price'];
        pb_preview_tax($row, $doc, $goodsT);
        $pv['row'] = $row;
        $ctx = pb_pl_load_sale($pdo, $cid, 0, $pv);
    } else {
        pb_preview_tax($row, $doc, (float)($row['price'] ?? 0) * (float)($row['net_weight'] ?? 0));
        $pv['row'] = $row;
        $ctx = pb_pl_load_purchase($pdo, $cid, 0, $pv);
    }
    $base = ['ok' => true, 'type' => $type, 'lines' => [], 'total_debit' => 0, 'total_credit' => 0, 'balanced' => true, 'missing' => []];
    if ($ctx === null || !pb_posting_has_type($type)) out($base + ['note' => 'برای این سند، سند حسابداری صادر نمی‌شود']);
    $built = pb_posting_build(pb_posting_rules(), $type, $ctx);
    if (!$built) out($base + ['note' => 'برای این سند، سند حسابداری صادر نمی‌شود']);
    $roles = [];
    foreach ($built as $l) $roles[] = $l['role'];
    $miss = pb_acc_roles_missing($pdo, $cid, array_values(array_unique($roles)));
    if ($miss) out(['ok' => false, 'type' => $type, 'lines' => [], 'missing' => $miss, 'note' => 'حساب نقش‌های زیر تنظیم نشده است: ' . implode('، ', $miss)]);
    $lines = pb_posting_resolve($pdo, $cid, $built, $ctx);
    $accIds = [];
    $tafIds = [];
    foreach ($lines as $l) { $accIds[(int)$l['account_id']] = 1; if ((int)$l['tafsili_id'] > 0) $tafIds[(int)$l['tafsili_id']] = 1; }
    $accs = pb_preview_names($pdo, 'accounts', $cid, array_keys($accIds), true);
    $tafs = pb_preview_names($pdo, 'tafsili', $cid, array_keys($tafIds), false);
    $out = [];
    $sd = 0;
    $sc = 0;
    foreach ($lines as $l) {
        $a = $accs[(int)$l['account_id']] ?? ['', ''];
        $out[] = ['account_code' => $a[0], 'account_name' => $a[1], 'tafsili_name' => (string)(($tafs[(int)$l['tafsili_id']] ?? ['', ''])[1]),
            'debit' => (int)$l['debit'], 'credit' => (int)$l['credit'], 'description' => (string)$l['description']];
        $sd += (int)$l['debit'];
        $sc += (int)$l['credit'];
    }
    out(['ok' => true, 'type' => $type, 'date' => (string)$ctx['date'], 'description' => (string)$ctx['description'], 'lines' => $out,
        'total_debit' => $sd, 'total_credit' => $sc, 'balanced' => $sd === $sc, 'missing' => []]);
}

/** APPROXIMATE document tax for the preview (the real value is computed by the server at save; rate = data sent by the form, never hard-coded). */
function pb_preview_tax(array &$row, array $doc, float $goodsToman): void {
    if (array_key_exists('total_tax', $row) && $row['total_tax'] !== null) return;
    $milli = (int)($doc['tax_rate_milli'] ?? 0);
    if (($doc['tax_status'] ?? '') === 'taxable' && $milli > 0) $row['total_tax'] = (int)round($goodsToman * $milli / 100000);
}

/** id => [code, name] (cid scoped). */
function pb_preview_names(PDO $pdo, string $table, int $cid, array $ids, bool $acc): array {
    $res = [];
    if (!$ids) return $res;
    $tbl = $acc ? 'accounts' : 'tafsili';
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, code, name FROM $tbl WHERE customer_id=? AND id IN ($in)");
    $st->execute(array_merge([$cid], array_map('intval', $ids)));
    foreach ($st->fetchAll() as $r) $res[(int)$r['id']] = [(string)$r['code'], (string)$r['name']];
    return $res;
}
