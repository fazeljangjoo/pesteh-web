<?php
declare(strict_types=1);
/**
 * S17.T17.1c — persists document tax (GOLD) just before the posting hook.
 * Payload (additive, ignored by green/old clients): tax = {status: taxable|exempt|zero, rate_milli?: int}.
 *  - No `tax` in the payload and nothing stored before => legacy behaviour (posting uses the header-level vat_rate_milli).
 *  - Update without `tax` but with a stored choice (sales.tax_status / purchases.tax_status) => recomputed from the stored status/rate (goods may have changed).
 * Base = goods - discount (document `discount` column). Surcharge/per-line discount: not supported yet (T17.1d) because posting rules do not post them.
 * Rate default (taxable, no rate given) = fiscal-year VAT rate via pb_pl_vat_milli(); rounding from fiscal_years.tax_rounding / tax_round_to.
 * Never throws: a failure here must not break the sale/purchase save (logged; posting then falls back to the legacy rate).
 */
require_once __DIR__ . '/tax.php';

function pb_tax_apply_doc(PDO $pdo, array $customer, string $type, int $docId, array $payload): void {
    try {
        if (!pb_fy_is_gold($customer) || $docId <= 0) return;
        $cid = (int)$customer['id'];
        $in = isset($payload['tax']) && is_array($payload['tax']) ? $payload['tax'] : null;
        if ($in === null) $in = pb_fin_tax_hint($pdo, $cid, $payload); // S35.T35.5: per-document «taxable» flag (fin_block on)
        if ($type === 'sale') {
            $h = $pdo->prepare('SELECT date, discount, tax_status, tax_rate_milli FROM sales WHERE id=? AND customer_id=?');
            $h->execute([$docId, $cid]);
            $doc = $h->fetch();
            if (!$doc) return;
            $it = $pdo->prepare('SELECT weight, item_price FROM sale_items WHERE sale_id=? AND customer_id=? ORDER BY id LIMIT 1');
            $it->execute([$docId, $cid]);
            $item = $it->fetch();
            if (!$item) return;
            $row = ['tax_status' => $doc['tax_status'], 'tax_rate_milli' => $doc['tax_rate_milli'], 'weight' => $item['weight'], 'item_price' => $item['item_price']];
            $gross = (int)round((float)$row['weight'] * (float)$row['item_price']);
        } elseif ($type === 'purchase') {
            $h = $pdo->prepare('SELECT date, discount, price, net_weight, tax_status, tax_rate_milli FROM purchases WHERE id=? AND customer_id=?');
            $h->execute([$docId, $cid]);
            $doc = $h->fetch();
            if (!$doc) return;
            $row = $doc;
            $gross = (int)round((float)$doc['price'] * (float)$doc['net_weight']);
        } else {
            return;
        }
        $status = $in !== null ? (string)($in['status'] ?? 'taxable') : (string)($row['tax_status'] ?? '');
        if ($status === '') { // legacy document (sales.update re-inserts items, so a stale total must not survive)
            if ($type === 'sale') $pdo->prepare('UPDATE sales SET total_before_tax=NULL, total_tax=NULL, total_after_tax=NULL WHERE id=? AND customer_id=?')->execute([$docId, $cid]);
            return;
        }
        if (!in_array($status, PB_TAX_STATUSES, true)) $status = 'taxable';
        $rate = ($in !== null && isset($in['rate_milli'])) ? (int)$in['rate_milli'] : ($in === null && $row['tax_rate_milli'] !== null ? (int)$row['tax_rate_milli'] : -1);
        if ($rate < 0) $rate = pb_pl_vat_milli($pdo, $cid, (string)$doc['date']);
        $rate = min(100000, max(0, $rate));
        $fy = $pdo->prepare('SELECT tax_rounding, tax_round_to FROM fiscal_years WHERE customer_id=? AND start_date<=? AND end_date>=? ORDER BY is_current DESC LIMIT 1');
        $fy->execute([$cid, (string)$doc['date'], (string)$doc['date']]);
        $f = $fy->fetch();
        $rounding = ($f && $f['tax_rounding'] === 'doc') ? 'doc' : 'line';
        $roundTo = $f ? max(1, (int)$f['tax_round_to']) : 1;
        $disc = (int)round((float)($doc['discount'] ?? 0));
        $t = pb_tax_doc([[$gross, $disc, 0, $status, $rate]], $rounding, $roundTo);
        $l = $t['lines'][0];
        if ($type === 'sale') {
            $pdo->prepare('UPDATE sale_items SET tax_status=?, tax_rate_milli=?, tax_amount=? WHERE sale_id=? AND customer_id=?')->execute([$l['status'], $rate, $l['tax'], $docId, $cid]);
            $pdo->prepare('UPDATE sales SET tax_status=?, tax_rate_milli=?, total_before_tax=?, total_tax=?, total_after_tax=? WHERE id=? AND customer_id=?')->execute([$l['status'], $rate, $t['before_tax'], $t['tax'], $t['after_tax'], $docId, $cid]);
        } else {
            $pdo->prepare('UPDATE purchases SET tax_status=?, tax_rate_milli=?, tax_amount=?, total_before_tax=?, total_tax=?, total_after_tax=? WHERE id=? AND customer_id=?')
                ->execute([$l['status'], $rate, $l['tax'], $t['before_tax'], $t['tax'], $t['after_tax'], $docId, $cid]);
        }
    } catch (Throwable $e) {
        error_log('[tax_apply] ' . $e->getMessage());
    }
}

/**
 * Returns copy the ORIGINAL document tax (S17.T17.1b): tax of a return = original total_tax x returned value / original goods.
 * $parts: [originalDocId => returned goods value (kg x unit_price, stored unit)]. Only when at least one original has a stored tax
 * (otherwise tax_amount stays NULL and posting keeps the legacy fiscal-year rate). Originals without stored tax use the fiscal-year rate.
 * @param string $type 'sale'|'purchase'  @param string $retTable 'sale_returns'|'purchase_returns'
 */
function pb_tax_return_copy(PDO $pdo, array $customer, string $type, string $retTable, int $rid, array $parts): void {
    try {
        if (!pb_fy_is_gold($customer) || $rid <= 0 || !in_array($retTable, ['sale_returns', 'purchase_returns'], true)) return;
        $cid = (int)$customer['id'];
        $sum = 0; $any = false;
        foreach ($parts as $docId => $value) {
            $docId = (int)$docId; $v = (int)round((float)$value);
            if ($docId <= 0 || $v <= 0) continue;
            if ($type === 'sale') {
                $q = $pdo->prepare('SELECT s.date, s.total_tax, (SELECT COALESCE(SUM(weight*item_price),0) FROM sale_items WHERE sale_id=s.id AND customer_id=s.customer_id) AS goods FROM sales s WHERE s.id=? AND s.customer_id=?');
            } else {
                $q = $pdo->prepare('SELECT date, total_tax, price*net_weight AS goods FROM purchases WHERE id=? AND customer_id=?');
            }
            $q->execute([$docId, $cid]);
            $d = $q->fetch();
            if (!$d) continue;
            $goods = (int)round((float)$d['goods']);
            if ($d['total_tax'] !== null && $goods > 0) {
                $any = true;
                $sum += pb_tax_div_round((int)round((float)$d['total_tax']) * $v, $goods);
            } else {
                $sum += pb_tax_div_round($v * pb_pl_vat_milli($pdo, $cid, (string)$d['date']), 100000);
            }
        }
        if ($any) $pdo->prepare("UPDATE $retTable SET tax_amount=? WHERE id=? AND customer_id=?")->execute([$sum, $rid, $cid]);
    } catch (Throwable $e) {
        error_log('[tax_return_copy] ' . $e->getMessage());
    }
}
