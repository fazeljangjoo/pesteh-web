<?php
declare(strict_types=1);
/**
 * S35.T35.5 — financial block of purchase/sale documents (active only when form.fin_block = 1; otherwise nothing here runs).
 * Payload (all optional, additive): discount_amount (header discount, stored money unit, 0..goods), weighbridge_cost (stored unit),
 * side_cost_payer ('us'|'party'), taxable (bool; per-document item flag => tax status taxable|exempt when no explicit payload.tax).
 * Storage: discount (legacy column, read by tax_apply.php + posting loaders) AND discount_amount (v50 mirror), weighbridge_cost (v51),
 * side_cost_payer, taxable (purchases.taxable / sale_items.taxable). VAT itself stays in tax_apply.php (rate from fiscal_years only).
 * Block (action fin.block {doc_type, id}): goods, discount, before_tax, tax, side costs, grand total (+ Persian words). All integers in the
 * stored money unit; `unit_mult` tells the client how to convert to rial (see money_unit.php).
 * grand_total = before_tax + tax + (side costs when side_cost_payer = 'party': charged to the buyer on a sale, deducted from the seller on a purchase).
 * Side costs paid by 'us' are our own expense and never change the amount owed by/to the other party.
 */
require_once __DIR__ . '/form_settings.php';

/** Rolls back an open document transaction, then refuses (the hooks run inside the save transaction). */
function pb_fin_fail(PDO $pdo, string $msg): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    bad($msg);
}

/** Validates the financial payload. Returns null when the feature is off. $goods = price × net weight (stored unit). */
function pb_fin_read(PDO $pdo, int $cid, array $p, float $goods): ?array {
    if (!pb_form_on($pdo, $cid, 'fin_block')) return null;
    $num = static function ($v, string $label, float $max) use ($pdo): float {
        if ($v === null || $v === '') return 0.0;
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > $max) pb_fin_fail($pdo, $label . ' نامعتبر است');
        return (float)$v;
    };
    $disc = $num($p['discount_amount'] ?? null, 'تخفیف', 1.0e13);
    if ($disc > round($goods) + 0.5) pb_fin_fail($pdo, 'تخفیف نمی‌تواند از مبلغ کالا بیشتر باشد');
    $wb = $num($p['weighbridge_cost'] ?? null, 'هزینهٔ باسکول', 1.0e13);
    $payer = (string)($p['side_cost_payer'] ?? 'us');
    if ($payer !== 'us' && $payer !== 'party') pb_fin_fail($pdo, 'پرداخت‌کنندهٔ هزینه‌های جانبی نامعتبر است');
    $tx = $p['taxable'] ?? null;
    $taxable = ($tx === null || $tx === '') ? null : (($tx === true || $tx === 1 || $tx === '1') ? 1 : 0);
    return ['discount' => round($disc), 'weighbridge' => round($wb), 'payer' => $payer, 'taxable' => $taxable];
}

/** One-call hook for the save paths: goods from the stored document, validate payload, store. Call right BEFORE pb_tax_apply_doc. */
function pb_fin_apply(PDO $pdo, int $cid, string $table, int $id, array $payload): void {
    if (!pb_form_on($pdo, $cid, 'fin_block')) return;
    if ($table === 'purchases') {
        $q = $pdo->prepare('SELECT price*net_weight FROM purchases WHERE id=? AND customer_id=?');
    } else {
        $q = $pdo->prepare('SELECT COALESCE(SUM(weight*item_price),0) FROM sale_items WHERE sale_id=? AND customer_id=?');
    }
    $q->execute([$id, $cid]);
    pb_fin_store($pdo, $cid, $table, $id, pb_fin_read($pdo, $cid, $payload, (float)$q->fetchColumn()));
}

/** Persists the block (call BEFORE pb_tax_apply_doc so the tax sees the discount). $table purchases|sales. */
function pb_fin_store(PDO $pdo, int $cid, string $table, int $id, ?array $f): void {
    if ($f === null || ($table !== 'purchases' && $table !== 'sales')) return;
    $pdo->prepare("UPDATE `$table` SET discount=?, discount_amount=?, weighbridge_cost=?, side_cost_payer=? WHERE id=? AND customer_id=?")
        ->execute([$f['discount'], (int)$f['discount'], (int)$f['weighbridge'], $f['payer'], $id, $cid]);
    if ($table === 'purchases') $pdo->prepare('UPDATE purchases SET taxable=? WHERE id=? AND customer_id=?')->execute([$f['taxable'], $id, $cid]);
    else $pdo->prepare('UPDATE sale_items SET taxable=? WHERE sale_id=? AND customer_id=?')->execute([$f['taxable'], $id, $cid]);
}

/** Tax choice implied by the per-document «taxable» flag (null = nothing implied). Used by tax_apply when payload.tax is absent. */
function pb_fin_tax_hint(PDO $pdo, int $cid, array $payload): ?array {
    if (!array_key_exists('taxable', $payload) || $payload['taxable'] === null || $payload['taxable'] === '') return null;
    if (!pb_form_on($pdo, $cid, 'fin_block')) return null;
    $t = $payload['taxable'];
    return ['status' => ($t === true || $t === 1 || $t === '1') ? 'taxable' : 'exempt'];
}

/** Computed block of a stored document (works for old documents too: NULL discount/tax/side costs count as 0). */
function pb_fin_block_of(PDO $pdo, int $cid, string $docType, int $id): array {
    if ($docType === 'purchase') {
        $st = $pdo->prepare('SELECT price, net_weight, discount, total_before_tax, total_tax, labor_cost, transport_cost, weighbridge_cost, side_cost_payer FROM purchases WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $d = $st->fetch();
        if (!$d) bad('سند پیدا نشد', 404);
        $goods = (int)round((float)$d['price'] * (float)$d['net_weight']);
    } elseif ($docType === 'sale') {
        $st = $pdo->prepare('SELECT discount, total_before_tax, total_tax, labor_cost, transport_cost, weighbridge_cost, side_cost_payer FROM sales WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $d = $st->fetch();
        if (!$d) bad('سند پیدا نشد', 404);
        $q = $pdo->prepare('SELECT COALESCE(SUM(weight*item_price),0) FROM sale_items WHERE sale_id=? AND customer_id=?');
        $q->execute([$id, $cid]);
        $goods = (int)round((float)$q->fetchColumn());
    } else {
        bad('نوع سند نامعتبر است');
    }
    $disc = (int)round((float)($d['discount'] ?? 0));
    $before = $d['total_before_tax'] !== null ? (int)$d['total_before_tax'] : max(0, $goods - $disc);
    $tax = $d['total_tax'] !== null ? (int)$d['total_tax'] : 0;
    $side = ['labor' => (int)round((float)($d['labor_cost'] ?? 0)), 'transport' => (int)round((float)($d['transport_cost'] ?? 0)),
             'weighbridge' => (int)round((float)($d['weighbridge_cost'] ?? 0))];
    $payer = ($d['side_cost_payer'] ?? null) === 'party' ? 'party' : 'us';
    $sideSum = array_sum($side);
    $grand = $before + $tax;
    if ($payer === 'party') $grand += $docType === 'sale' ? $sideSum : -$sideSum;
    $mult = pb_posting_unit_mult();
    return ['doc_type' => $docType, 'id' => $id, 'goods' => $goods, 'discount' => $disc, 'before_tax' => $before, 'tax' => $tax, 'side_costs' => $side,
            'side_cost_payer' => $payer, 'grand_total' => $grand, 'unit_mult' => $mult, 'words' => pb_amount_words($grand * $mult) . ' ریال'];
}

/** Persian words of a non-negative integer (rial), e.g. 1250 → «یک هزار و دویست و پنجاه». Up to 999 تریلیون; larger → digits. */
function pb_amount_words(int $n): string {
    if ($n < 0) return 'منفی ' . pb_amount_words(-$n);
    if ($n === 0) return 'صفر';
    $orig = $n;
    static $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    static $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    static $hund = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    static $scale = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون'];
    $three = static function (int $x) use ($ones, $tens, $hund): string {
        $p = [];
        if ($x >= 100) { $p[] = $hund[intdiv($x, 100)]; $x %= 100; }
        if ($x >= 20) { $p[] = $tens[intdiv($x, 10)]; $x %= 10; }
        if ($x > 0) $p[] = $ones[$x];
        return implode(' و ', $p);
    };
    $groups = [];
    while ($n > 0) { $groups[] = $n % 1000; $n = intdiv($n, 1000); }
    if (count($groups) > count($scale)) return (string)$orig;
    $parts = [];
    for ($i = count($groups) - 1; $i >= 0; $i--) {
        if ($groups[$i] === 0) continue;
        $parts[] = $three($groups[$i]) . ($scale[$i] !== '' ? ' ' . $scale[$i] : '');
    }
    return implode(' و ', $parts);
}
