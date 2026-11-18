<?php
require_once __DIR__ . '/ddl_guard.php';
/**
 * S27b.T27b.8 — FX adjustments (سند اصلاحیه/تعدیل نرخ) and rate-mismatch list.
 *
 * Policy (FX_CONTRACT «سیاست نرخ»): a saved foreign document never changes its rate silently.
 * A rate change after the fact is an ADJUSTMENT row that references the original document,
 * keeps old and new rate, the amount difference, the reason and the user. The original row is not edited.
 * Every adjustment is written to the audit log.
 * Actions: fx.adjust.list | fx.adjust.preview | fx.adjust.apply | fx.rate_mismatch.list
 */

function pb_fx_ensure_adjust(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS fx_adjustments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        doc_type VARCHAR(10) NOT NULL,
        doc_id INT UNSIGNED NOT NULL,
        ccy VARCHAR(8) NOT NULL,
        foreign_amount DECIMAL(20,4) NOT NULL,
        old_rate DECIMAL(18,6) NOT NULL,
        new_rate DECIMAL(18,6) NOT NULL,
        diff_rial DECIMAL(20,2) NOT NULL,
        reason VARCHAR(300) NOT NULL,
        created_by VARCHAR(120) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_fxadj_doc (customer_id, doc_type, doc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Document row with the fields the adjustment needs (scoped by cid). Null when not a foreign document. */
function pb_fx_adjust_doc(PDO $pdo, int $cid, string $docType, int $docId): ?array {
    if ($docType === 'sales') {
        $st = $pdo->prepare('SELECT id, date, ccy, fx_rate, total_ccy AS amount_ccy FROM sales WHERE id=? AND customer_id=?');
    } elseif ($docType === 'purchases') {
        $st = $pdo->prepare('SELECT id, date, ccy, fx_rate, price_ccy * net_weight AS amount_ccy FROM purchases WHERE id=? AND customer_id=?');
    } else {
        bad('نوع سند نامعتبر است');
    }
    $st->execute([$docId, $cid]);
    $r = $st->fetch();
    if (!$r || $r['ccy'] === null || $r['fx_rate'] === null) return null;
    return $r;
}

/** Effective rate of a document = rate of its latest adjustment, else the original rate. */
function pb_fx_adjust_effective_rate(PDO $pdo, int $cid, string $docType, int $docId, float $originalRate): float {
    $st = $pdo->prepare('SELECT new_rate FROM fx_adjustments WHERE customer_id=? AND doc_type=? AND doc_id=? ORDER BY id DESC LIMIT 1');
    $st->execute([$cid, $docType, $docId]);
    $v = $st->fetchColumn();
    return $v === false ? $originalRate : (float)$v;
}

/** Pure: difference in rial when a foreign amount is re-rated (exact to 2 decimals). */
function pb_fx_adjust_diff(float $amountCcy, float $oldRate, float $newRate): float {
    return round($amountCcy * ($newRate - $oldRate), 2);
}

function pb_fx_adjust_preview(PDO $pdo, int $cid, array $p): array {
    pb_fx_ensure_adjust($pdo);
    $docType = (string)($p['doc_type'] ?? '');
    $docId = (int)($p['doc_id'] ?? 0);
    $newRate = nullableNum($p['new_rate'] ?? null);
    if ($newRate === null || $newRate <= 0 || $newRate > 1e12) bad('نرخ جدید نامعتبر است');
    $doc = pb_fx_adjust_doc($pdo, $cid, $docType, $docId);
    if (!$doc) bad('سند ارزی یافت نشد', 404);
    $oldRate = pb_fx_adjust_effective_rate($pdo, $cid, $docType, $docId, (float)$doc['fx_rate']);
    $amount = (float)$doc['amount_ccy'];
    return ['doc_type' => $docType, 'doc_id' => $docId, 'ccy' => (string)$doc['ccy'],
            'amount_ccy' => round($amount, 4), 'old_rate' => round($oldRate, 6), 'new_rate' => round($newRate, 6),
            'diff_rial' => pb_fx_adjust_diff($amount, $oldRate, $newRate)];
}

function pb_fx_adjust_apply(PDO $pdo, int $cid, array $customer, array $p): array {
    $reason = trim((string)($p['reason'] ?? ''));
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) bad('دلیل تعدیل باید ۵ تا ۳۰۰ نویسه باشد');
    $prev = pb_fx_adjust_preview($pdo, $cid, $p);
    if (abs($prev['new_rate'] - $prev['old_rate']) < 1e-9) bad('نرخ جدید با نرخ فعلی یکسان است');
    $who = (string)($customer['name'] ?? $customer['phone'] ?? '');
    $pdo->prepare('INSERT INTO fx_adjustments (customer_id, doc_type, doc_id, ccy, foreign_amount, old_rate, new_rate, diff_rial, reason, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $prev['doc_type'], $prev['doc_id'], $prev['ccy'], $prev['amount_ccy'], $prev['old_rate'], $prev['new_rate'], $prev['diff_rial'], $reason, mb_substr($who, 0, 120), date('Y-m-d H:i:s')]); // تاریخ سند از created_at ⇒ ساعت تهران
    $id = (int)$pdo->lastInsertId();
    if (function_exists('pb_audit')) {
        pb_audit($pdo, $customer, 'fx.adjust', $prev['doc_type'], (string)$prev['doc_id'],
            "تعدیل نرخ {$prev['ccy']}: {$prev['old_rate']} ← {$prev['new_rate']} (اختلاف {$prev['diff_rial']} ریال)",
            ['rate' => $prev['old_rate']], ['rate' => $prev['new_rate'], 'reason' => $reason]);
    }
    return ['id' => $id] + $prev;
}

function pb_fx_adjust_list(PDO $pdo, int $cid, array $p): array {
    pb_fx_ensure_adjust($pdo);
    $docType = (string)($p['doc_type'] ?? '');
    $docId = (int)($p['doc_id'] ?? 0);
    $st = $pdo->prepare('SELECT id, doc_type, doc_id, ccy, foreign_amount, old_rate, new_rate, diff_rial, reason, created_by, created_at
        FROM fx_adjustments WHERE customer_id=? AND doc_type=? AND doc_id=? ORDER BY id');
    $st->execute([$cid, $docType, $docId]);
    return ['adjustments' => $st->fetchAll()];
}

/**
 * Offline documents whose kept rate differs from the server table rate on the document date.
 * Nothing is changed: the list is for the accountant to decide (adjustment or accept).
 */
function pb_fx_rate_mismatch_list(PDO $pdo, int $cid): array {
    pb_fx_ensure_adjust($pdo);
    $out = [];
    foreach (['sales' => "SELECT id, date, ccy, fx_rate FROM sales WHERE customer_id=? AND fx_rate_source='offline' AND ccy IS NOT NULL",
              'purchases' => "SELECT id, date, ccy, fx_rate FROM purchases WHERE customer_id=? AND fx_rate_source='offline' AND ccy IS NOT NULL"] as $type => $sql) {
        $st = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 500');
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) {
            $server = pb_fx_rate_at($pdo, $cid, (string)$r['ccy'], (string)$r['date']);
            if ($server === null) continue;
            $kept = (float)$r['fx_rate'];
            if (abs($server - $kept) < 1e-9) continue;
            $out[] = ['doc_type' => $type, 'doc_id' => (int)$r['id'], 'ccy' => (string)$r['ccy'], 'date' => (string)$r['date'],
                      'kept_rate' => round($kept, 6), 'server_rate' => round($server, 6)];
        }
    }
    return ['mismatches' => $out];
}
