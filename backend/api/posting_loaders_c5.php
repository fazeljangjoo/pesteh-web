<?php
declare(strict_types=1);
/**
 * S30.T30.1 — posting loaders "C5" (GOLD, fx plan): foreign-currency settlement differences.
 *  - fx_settle_recv  sales.id    : paid lump receipt lines in the SAME currency as the sale (receipt_lump_tx.ccy = sales.ccy)
 *  - fx_settle_pay   purchases.id: paid lump payment lines in the SAME currency as the purchase (lump_tx.ccy = purchases.ccy)
 * One voucher per document (source_id = document id, stable although line rows are re-created on every save).
 * Fields: carry = round(SUM(ccy_amount) * document booked rate) (one rounding per voucher, T32.2b) (the rial booked on ar/ap at document time),
 *         cash/bank = rial actually moved (line amount); the rule books diff = paid - carry to fx_diff 5207 (loss/gain).
 * The doc voucher (sale/purchase) must NOT also post these lines: pb_pl_settle() excludes them via pb_plc5_excl_sql().
 * Booked rate = the ORIGINAL document rate (fx_adjustments are not posted yet: S30 follow-up), so ar/ap net to zero.
 * SIGNOFF: cheque / installment lines of foreign documents stay at their nominal rial (no diff until a later task);
 * gain and loss of several lines of one document are netted. Requires posting_loaders_a.php.
 */

/** Cached: do the fx columns exist (fx plan migrated)? */
function pb_plc5_has_fx(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM lump_tx LIKE 'ccy'")->fetch() !== false
            && $pdo->query("SHOW COLUMNS FROM sales LIKE 'fx_rate'")->fetch() !== false;
    } catch (Throwable $e) { $has = false; }
    return $has;
}

/** SQL fragment (AND ...) for pb_pl_settle: skips lump lines that fx_settle_* posts instead. '' when fx columns are absent. */
function pb_plc5_excl_sql(PDO $pdo, bool $sale): string {
    if (!pb_plc5_has_fx($pdo)) return '';
    [$doc, $key] = $sale ? ['sales', 'sale_id'] : ['purchases', 'purchase_id'];
    return " AND NOT (t.ccy IS NOT NULL AND t.fx_rate IS NOT NULL AND EXISTS (SELECT 1 FROM `$doc` d WHERE d.id=h.$key AND d.customer_id=t.customer_id AND d.ccy=t.ccy AND d.fx_rate IS NOT NULL))";
}

function pb_plc5_load(PDO $pdo, int $cid, int $id, bool $sale): ?array {
    if (!pb_plc5_has_fx($pdo)) return null;
    [$doc, $hdr, $key, $lump, $idc, $nameCol] = $sale
        ? ['sales', 'receipts', 'sale_id', 'receipt_lump_tx', 'receipt_id', 'buyer']
        : ['purchases', 'payments', 'purchase_id', 'lump_tx', 'payment_id', 'seller'];
    $st = $pdo->prepare("SELECT t.amount, t.ccy_amount, t.date, t.treasury_tafsili_id AS tid, d.fx_rate AS doc_rate, d.$nameCol AS party
        FROM `$lump` t JOIN `$hdr` h ON h.id=t.$idc AND h.customer_id=t.customer_id
        JOIN `$doc` d ON d.id=h.$key AND d.customer_id=t.customer_id
        WHERE h.$key=? AND h.customer_id=? AND t.paid=1 AND t.ccy IS NOT NULL AND t.fx_rate IS NOT NULL AND t.ccy_amount > 0
          AND d.ccy=t.ccy AND d.fx_rate IS NOT NULL");
    try { $st->execute([$id, $cid]); } catch (Throwable $e) { error_log('[fx_settle] ' . $e->getMessage()); return null; }
    $rows = $st->fetchAll();
    if (!$rows) return null;
    $carry = 0; $cash = 0; $bank = 0; $date = ''; $party = null;
    $ccySum = 0.0; $docRate = 0.0;
    foreach ($rows as $r) {
        // T32.2b: accumulate the foreign amount and round ONCE (cumulative-minus-carried collapses to one rounding per
        // document voucher), so the last settlement absorbs the residual and ar/ap net to the doc rial total exactly.
        $ccySum = round($ccySum + (float)$r['ccy_amount'], 6);
        $docRate = (float)$r['doc_rate'];
        $amt = pb_pl_rial($r['amount']);
        if (pb_pl_taf_kind($pdo, $cid, $r['tid']) === 'cash') $cash += $amt; else $bank += $amt;
        if ((string)$r['date'] > $date) $date = (string)$r['date'];
        $party = $r['party'];
    }
    $carry = (int)round($ccySum * $docRate); // rial per unit x total settled foreign amount
    if ($carry <= 0 && $cash + $bank <= 0) return null;
    return ['date' => $date, 'description' => ($sale ? 'تسویه ارزی فروش #' : 'تسویه ارزی خرید #') . $id . ' ' . (string)$party,
        'fields' => ['carry' => $carry, 'cash' => $cash, 'bank' => $bank],
        'dims' => ['party' => pb_pl_party_tid($pdo, $cid, $party === null ? null : (string)$party)]];
}

pb_posting_register_loader('fx_settle_recv', function (PDO $pdo, int $cid, int $id): ?array { return pb_plc5_load($pdo, $cid, $id, true); });
pb_posting_register_loader('fx_settle_pay', function (PDO $pdo, int $cid, int $id): ?array { return pb_plc5_load($pdo, $cid, $id, false); });

/* ---- S30.T30.2: period-end revaluation (fx_revals row; fx_reval = original voucher, fx_reval_rev = reversing voucher) ---- */
require_once __DIR__ . '/fx_reval.php';
pb_posting_register_loader('fx_reval', function (PDO $pdo, int $cid, int $id): ?array { return pb_fxr_load($pdo, $cid, $id, false); });
pb_posting_register_loader('fx_reval_rev', function (PDO $pdo, int $cid, int $id): ?array { return pb_fxr_load($pdo, $cid, $id, true); });

/* ---- T30.3c: fx_adjust = rate adjustment row (fx_adjustments) of a foreign sale/purchase; diff_rial>0 => receivable/payable goes up ---- */
require_once __DIR__ . '/fx_adjust.php';
function pb_plc5_adjust_load(PDO $pdo, int $cid, int $id): ?array {
    try {
        $st = $pdo->prepare('SELECT doc_type, doc_id, diff_rial, created_at FROM fx_adjustments WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $a = $st->fetch();
        if (!$a) return null;
        $sale = (string)$a['doc_type'] === 'sales';
        $d = $pdo->prepare($sale ? 'SELECT buyer AS party FROM sales WHERE id=? AND customer_id=?' : 'SELECT seller AS party FROM purchases WHERE id=? AND customer_id=?');
        $d->execute([(int)$a['doc_id'], $cid]);
        $doc = $d->fetch();
    } catch (Throwable $e) { error_log('[fx_adjust] ' . $e->getMessage()); return null; }
    if (!$doc) return null;
    $diff = (int)round((float)$a['diff_rial']);
    if ($diff === 0) return null;
    $up = max($diff, 0); $down = max(-$diff, 0);
    $party = $doc['party'] === null ? null : (string)$doc['party'];
    return ['date' => substr((string)$a['created_at'], 0, 10),
        'description' => 'تعدیل نرخ ارز ' . ($sale ? 'فروش #' : 'خرید #') . (int)$a['doc_id'] . ' (تعدیل ' . $id . ')',
        'fields' => ['ar_up' => $sale ? $up : 0, 'ar_down' => $sale ? $down : 0, 'ap_up' => $sale ? 0 : $up, 'ap_down' => $sale ? 0 : $down],
        'dims' => ['party' => pb_pl_party_tid($pdo, $cid, $party)]];
}
pb_posting_register_loader('fx_adjust', function (PDO $pdo, int $cid, int $id): ?array { return pb_plc5_adjust_load($pdo, $cid, $id); });
