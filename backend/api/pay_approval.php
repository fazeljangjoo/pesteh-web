<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S31.T31.3 — two-step approval for payments above a limit (maker-checker).
 * Setting: pay_approval.limit (integer rial, 0 = off), gated by the existing form switch `approval`. Owner-controlled.
 * A payments.save by a STAFF user that INCREASES the paid total of the purchase by >= limit is NOT applied:
 * the payload is stored as a pending request (table pay_approvals) and the owner approves (applies it) or rejects it.
 * Owner payments and payments below the limit behave exactly as before. Receipts are not covered (see PROGRESS).
 * Actions: pay_approval.list | set_limit | approve | reject (owner only, deliberately absent from permissions.php).
 */

function pb_payappr_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS pay_approvals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        doc_type VARCHAR(12) NOT NULL DEFAULT 'purchase',
        doc_id INT UNSIGNED NOT NULL,
        amount BIGINT NOT NULL DEFAULT 0,
        payload MEDIUMTEXT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        requested_by VARCHAR(120) NULL,
        decided_by VARCHAR(120) NULL,
        reason VARCHAR(200) NULL,
        created_at DATETIME NULL,
        decided_at DATETIME NULL,
        KEY idx_pa_cust (customer_id, status, id),
        KEY idx_pa_doc (customer_id, doc_type, doc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_payappr_limit(PDO $pdo, int $cid): int {
    return max(0, (int)pb_app_setting_get($pdo, $cid, 'pay_approval.limit', '0'));
}

function pb_payappr_owner_only(array $customer): void {
    if ((int)($customer['_staff_id'] ?? 0) > 0) bad('فقط مالک می‌تواند این کار را انجام دهد', 403);
}

/** Sum of PAID rows in a payment payload (stored unit). */
function pb_payappr_payload_paid(array $p): float {
    $sum = 0.0;
    foreach (['lump', 'installments', 'lump_lines', 'credit_lines', 'cheque_lines'] as $k) {
        if (!isset($p[$k]) || !is_array($p[$k])) continue;
        foreach ($p[$k] as $t) {
            if (is_array($t) && !empty($t['paid'])) $sum += (float)num($t['amount'] ?? 0);
        }
    }
    return $sum;
}

/** Sum of PAID rows already stored for the purchase (stored unit). */
function pb_payappr_stored_paid(PDO $pdo, int $cid, int $purchaseId): float {
    $sum = 0.0;
    foreach (['lump_tx', 'credit_inst', 'cheque_inst'] as $t) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(x.amount),0) FROM $t x JOIN payments p ON p.id=x.payment_id
            WHERE p.purchase_id=? AND p.customer_id=? AND x.customer_id=? AND x.paid=1");
        $st->execute([$purchaseId, $cid, $cid]);
        $sum += (float)$st->fetchColumn();
    }
    return $sum;
}

/** @return array|null request summary when the save was diverted to approval, null = apply normally. */
function pb_payappr_intercept(PDO $pdo, int $cid, array $customer, int $purchaseId, array $payIn, $snapAt = null): ?array {
    if ((int)($customer['_staff_id'] ?? 0) <= 0) return null; // owner needs no second approval
    if (!pb_form_on($pdo, $cid, 'approval')) return null;
    $limit = pb_payappr_limit($pdo, $cid);
    if ($limit <= 0) return null;
    $delta = pb_payappr_payload_paid($payIn) - pb_payappr_stored_paid($pdo, $cid, $purchaseId);
    $rial = (int)round($delta * pb_posting_unit_mult());
    if ($rial < $limit) return null;
    pb_payappr_ensure($pdo);
    // STAGE10: snap_at اپ همراه درخواست نگه داشته می‌شود (پاکت نسخه‌دار؛ ردیف‌های قدیمیِ بدون پاکت همچنان خوانده می‌شوند)
    // تا هنگام تأیید، تسویه‌های دستیارِ بین درخواست و تأیید با sg_merge_incoming حفظ شوند.
    $env = ['__pb_env' => 1, 'payment' => $payIn];
    if (is_string($snapAt) && $snapAt !== '' && strlen($snapAt) <= 40) $env['snap_at'] = $snapAt;
    $json = json_encode($env, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 2000000) bad('حجم درخواست پرداخت نامعتبر است');
    // one open request per document: a newer one replaces the older pending one
    $pdo->prepare("UPDATE pay_approvals SET status='rejected', decided_at=NOW(), reason='replaced' WHERE customer_id=? AND doc_type='purchase' AND doc_id=? AND status='pending'")
        ->execute([$cid, $purchaseId]);
    $by = (string)($customer['_staff_name'] ?? ('staff#' . (int)$customer['_staff_id']));
    $pdo->prepare('INSERT INTO pay_approvals (customer_id, doc_type, doc_id, amount, payload, status, requested_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())')
        ->execute([$cid, 'purchase', $purchaseId, $rial, $json, 'pending', mb_substr($by, 0, 120)]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('pb_audit')) pb_audit($pdo, $customer, 'payments.save', 'pay_approval', (string)$id, 'درخواست پرداخت بالای سقف (در انتظار تأیید مالک)', null, ['purchase_id' => $purchaseId, 'amount_rial' => $rial]);
    return ['ok' => true, 'pending_approval' => true, 'approval_id' => $id, 'amount_rial' => $rial, 'limit' => $limit];
}

function pb_payappr_list(PDO $pdo, int $cid): array {
    pb_payappr_ensure($pdo);
    $q = $pdo->prepare("SELECT a.id, a.doc_id, a.amount, a.requested_by, a.created_at, p.code, p.type_name, p.seller AS party
        FROM pay_approvals a LEFT JOIN purchases p ON p.id=a.doc_id AND p.customer_id=a.customer_id
        WHERE a.customer_id=? AND a.status='pending' ORDER BY a.id DESC LIMIT 200");
    $q->execute([$cid]);
    $rows = [];
    foreach ($q->fetchAll() as $r) {
        $rows[] = ['id' => (int)$r['id'], 'doc_type' => 'purchase', 'doc_id' => (int)$r['doc_id'], 'code' => $r['code'], 'title' => (string)($r['type_name'] ?? ''),
            'party' => $r['party'], 'amount_rial' => (int)$r['amount'], 'requested_by' => $r['requested_by'], 'created_at' => $r['created_at']];
    }
    return ['enabled' => pb_form_on($pdo, $cid, 'approval'), 'limit' => pb_payappr_limit($pdo, $cid), 'pending' => $rows];
}

function pb_payappr_set_limit(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_payappr_owner_only($customer);
    $v = $p['limit'] ?? null;
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1e15) bad('سقف مبلغ نامعتبر است');
    pb_app_setting_set($pdo, $cid, 'pay_approval.limit', (string)(int)round((float)$v));
    return pb_payappr_list($pdo, $cid);
}

function pb_payappr_decide(PDO $pdo, int $cid, array $customer, array $p, bool $approve): array {
    pb_payappr_owner_only($customer);
    pb_payappr_ensure($pdo);
    $id = (int)($p['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM pay_approvals WHERE id=? AND customer_id=? AND status='pending'");
    $st->execute([$id, $cid]);
    $row = $st->fetch();
    if (!$row) bad('درخواست در انتظار تأیید یافت نشد', 404);
    $who = (string)($customer['_staff_name'] ?? 'owner');
    if ($approve) {
        $pid = (int)$row['doc_id'];
        $chk = $pdo->prepare('SELECT id FROM purchases WHERE id=? AND customer_id=?');
        $chk->execute([$pid, $cid]);
        if (!$chk->fetch()) bad('خرید یافت نشد', 404);
        $payIn = json_decode((string)$row['payload'], true);
        if (!is_array($payIn)) bad('داده درخواست نامعتبر است');
        $snapAt = null;
        if (($payIn['__pb_env'] ?? null) === 1 && is_array($payIn['payment'] ?? null)) { // STAGE10: پاکت نسخه‌دار
            $snapAt = $payIn['snap_at'] ?? null;
            $payIn = $payIn['payment'];
        }
        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare("UPDATE pay_approvals SET status='approved', decided_by=?, decided_at=NOW() WHERE id=? AND customer_id=? AND status='pending'");
            $claim->execute([mb_substr($who, 0, 120), $id, $cid]);
            if ($claim->rowCount() < 1) { $pdo->rollBack(); bad('درخواست قبلاً بررسی شده است', 409); }
            // STAGE10: همان حفاظتِ مسیر مستقیم payments.save؛ تسویهٔ دستیار بین درخواست و تأیید بازنویسی نشود
            if (function_exists('sg_merge_incoming')) $payIn = sg_merge_incoming($pdo, $cid, 'purchase', $pid, $payIn, $snapAt);
            savePayment($pdo, $cid, $pid, $payIn);
            pb_post_hook($pdo, $customer, 'purchase', $pid);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } else {
        $reason = mb_substr(trim((string)($p['reason'] ?? '')), 0, 200);
        $pdo->prepare("UPDATE pay_approvals SET status='rejected', decided_by=?, reason=?, decided_at=NOW() WHERE id=? AND customer_id=? AND status='pending'")
            ->execute([mb_substr($who, 0, 120), $reason, $id, $cid]);
    }
    return pb_payappr_list($pdo, $cid);
}

function pb_payappr_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    switch ($action) {
        case 'pay_approval.list': out(pb_payappr_list($pdo, $cid)); break;
        case 'pay_approval.set_limit': out(pb_payappr_set_limit($pdo, $cid, $customer, $p)); break;
        case 'pay_approval.approve': out(pb_payappr_decide($pdo, $cid, $customer, $p, true)); break;
        case 'pay_approval.reject': out(pb_payappr_decide($pdo, $cid, $customer, $p, false)); break;
    }
}
