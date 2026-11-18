<?php
declare(strict_types=1);
/**
 * S23.T23.2 — audit.run: one Persian "data health" report (سلامت داده‌ها). Read-only; owner/accountant (perm audit.view).
 * Result: {ok, errors, warnings, checks:[{code,title,status:ok|warn|error,count,items:[Persian text]}], summary}
 * Checks: stock ledger vs cached lot remaining (stock.reconcile), orphan child rows, negative lot remaining, legacy cheque flag vs cheque register,
 * stuck idempotency keys, and (gold) the accounting integrity report. Each sub-check is isolated: a missing/optional table never breaks the report.
 */
require_once __DIR__ . '/stock.php';

function pb_ar_check(string $code, string $title, string $status, array $items): array {
    return ['code' => $code, 'title' => $title, 'status' => count($items) ? $status : 'ok', 'count' => count($items), 'items' => array_slice($items, 0, 20)];
}

/** orphan = child row whose parent row (same customer) no longer exists. */
function pb_ar_orphans(PDO $pdo, int $cid): array {
    $defs = [
        ['payments', 'purchase_id', 'purchases', 'پرداخت بدون خرید'],
        ['receipts', 'sale_id', 'sales', 'دریافت بدون فروش'],
        ['sale_items', 'sale_id', 'sales', 'قلم فروش بدون فروش'],
        ['sale_items', 'purchase_id', 'purchases', 'قلم فروش با محموله‌ی نامعتبر'],
        ['lump_tx', 'payment_id', 'payments', 'پرداخت نقدی بدون پرداخت'],
        ['credit_inst', 'payment_id', 'payments', 'قسط نسیه بدون پرداخت'],
        ['cheque_inst', 'payment_id', 'payments', 'چک پرداختی بدون پرداخت'],
        ['receipt_cheque_inst', 'receipt_id', 'receipts', 'چک دریافتی بدون دریافت'],
        ['receipt_lump_tx', 'receipt_id', 'receipts', 'دریافت نقدی بدون دریافت'],
        ['receipt_credit_inst', 'receipt_id', 'receipts', 'قسط دریافتی بدون دریافت'],
        ['purchase_return_items', 'return_id', 'purchase_returns', 'قلم برگشت از خرید بدون سند'],
        ['sale_return_items', 'return_id', 'sale_returns', 'قلم برگشت از فروش بدون سند'],
    ];
    $items = [];
    foreach ($defs as [$child, $fk, $parent, $label]) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `$child` c LEFT JOIN `$parent` p ON p.id=c.`$fk` AND p.customer_id=c.customer_id WHERE c.customer_id=? AND p.id IS NULL");
            $st->execute([$cid]);
            $n = (int)$st->fetchColumn();
            if ($n > 0) $items[] = $label . ': ' . $n . ' مورد';
        } catch (Throwable $e) { /* table/column not present in this install */ }
    }
    return $items;
}

function pb_ar_cheques(PDO $pdo, int $cid): array {
    $items = [];
    try {
        $q = $pdo->prepare('SELECT id, number, status, source_table, source_id FROM cheques WHERE customer_id=? AND source_table IS NOT NULL');
        $q->execute([$cid]);
        foreach ($q->fetchAll() as $c) {
            $t = (string)$c['source_table'];
            if (!isset(PB_CHEQUE_SRC[$t])) continue;
            $r = $pdo->prepare("SELECT paid FROM `$t` WHERE id=? AND customer_id=?");
            $r->execute([(int)$c['source_id'], $cid]);
            $paid = $r->fetchColumn();
            if ($paid === false) { $items[] = 'چک ' . $c['number'] . ': ردیف مبدأ حذف شده است'; continue; }
            $want = (string)$c['status'] === 'cleared' ? 1 : 0;
            if ((int)$paid !== $want) $items[] = 'چک ' . $c['number'] . ': وضعیت «' . $c['status'] . '» با پرچم قدیمی پرداخت‌شده (' . (int)$paid . ') نمی‌خواند';
        }
        foreach (pb_cheque_sources($pdo, $cid) as $s) { // legacy rows with no register entry
            $k = $pdo->prepare('SELECT COUNT(*) FROM cheques WHERE customer_id=? AND source_table=? AND source_id=?');
            $k->execute([$cid, $s['t'], $s['id']]);
            if ((int)$k->fetchColumn() === 0) $items[] = 'چک ' . ($s['no'] !== '' ? $s['no'] : '#' . $s['id']) . ': در دفتر چک ثبت نشده است';
        }
    } catch (Throwable $e) { /* cheque register optional */ }
    return $items;
}

function pb_audit_run(PDO $pdo, array $customer): array {
    $cid = (int)$customer['id'];
    $checks = [];
    try {
        $r = pb_stock_reconcile($pdo, $cid);
        $it = [];
        foreach ($r['mismatches'] as $m) $it[] = 'محموله ' . $m['code'] . ': موجودی ذخیره‌شده ' . $m['cached_kg'] . ' کیلو، دفتر انبار ' . $m['ledger_kg'] . ' کیلو';
        foreach ($r['orphan_moves'] as $m) $it[] = 'حرکت انبار بدون محموله (شناسه ' . $m['lot_id'] . ')، مانده ' . $m['ledger_kg'] . ' کیلو';
        foreach ($r['sack_mismatches'] as $m) $it[] = 'محموله ' . $m['code'] . ': اختلاف تعداد گونی';
        $checks[] = pb_ar_check('stock_ledger', 'تطبیق موجودی انبار با دفتر حرکت‌ها', 'error', $it);
    } catch (Throwable $e) { $checks[] = ['code' => 'stock_ledger', 'title' => 'تطبیق موجودی انبار با دفتر حرکت‌ها', 'status' => 'warn', 'count' => 1, 'items' => ['بررسی انجام نشد']]; }
    try {
        $st = $pdo->prepare('SELECT code, remaining FROM purchases WHERE customer_id=? AND remaining < -0.0005');
        $st->execute([$cid]);
        $it = [];
        foreach ($st->fetchAll() as $l) $it[] = 'محموله ' . $l['code'] . ': موجودی منفی (' . $l['remaining'] . ' کیلو)';
        $checks[] = pb_ar_check('negative_lots', 'محموله‌های با موجودی منفی', 'error', $it);
    } catch (Throwable $e) { /* skip */ }
    $checks[] = pb_ar_check('orphans', 'ردیف‌های یتیم (بدون سند مادر)', 'error', pb_ar_orphans($pdo, $cid));
    $checks[] = pb_ar_check('cheque_flags', 'دفتر چک و پرچم قدیمی «پرداخت‌شده»', 'warn', pb_ar_cheques($pdo, $cid));
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM idempotency_keys WHERE customer_id=? AND status=0 AND created_at < (NOW() - INTERVAL 1 HOUR)');
        $st->execute([$cid]);
        $n = (int)$st->fetchColumn();
        $checks[] = pb_ar_check('idem_stuck', 'درخواست‌های همگام‌سازی نیمه‌کاره', 'warn', $n > 0 ? [$n . ' درخواست بیش از یک ساعت در وضعیت «در حال اجرا» مانده است'] : []);
    } catch (Throwable $e) { /* table is created lazily */ }
    if (function_exists('pb_fy_is_gold') && pb_fy_is_gold($customer)) {
        try {
            require_once __DIR__ . '/accounting_tools.php';
            $a = pb_acct_integrity($pdo, $customer, []);
            $err = []; $warn = [];
            foreach ($a['issues'] as $i) { $line = ($i['source'] ?? '') . ' ' . $i['detail']; if ($i['level'] === 'error') $err[] = trim($line); else $warn[] = trim($line); }
            $checks[] = pb_ar_check('acct_errors', 'یکپارچگی حسابداری (اسناد خودکار، تراز)', 'error', $err);
            $checks[] = pb_ar_check('acct_warnings', 'هشدارهای حسابداری (موجودی دفتری، چک، تفصیلی)', 'warn', $warn);
        } catch (Throwable $e) { $checks[] = ['code' => 'acct_errors', 'title' => 'یکپارچگی حسابداری', 'status' => 'warn', 'count' => 1, 'items' => ['بررسی انجام نشد']]; }
    }
    $errors = 0; $warnings = 0;
    foreach ($checks as $c) { if ($c['status'] === 'error') $errors++; elseif ($c['status'] === 'warn') $warnings++; }
    $summary = $errors > 0 ? $errors . ' خطا و ' . $warnings . ' هشدار پیدا شد؛ پیش از ادامه‌ی کار بررسی شود.'
        : ($warnings > 0 ? 'خطایی نیست؛ ' . $warnings . ' هشدار برای بررسی وجود دارد.' : 'همه‌ی بررسی‌ها سالم است.');
    return ['ok' => $errors === 0, 'errors' => $errors, 'warnings' => $warnings, 'checks' => $checks, 'summary' => $summary, 'at' => date('Y-m-d H:i:s')];
}
