<?php
declare(strict_types=1);
/**
 * S35.T35.9e — second approval above a company limit (minimal mechanism; no T31.3 exists).
 * Setting: form.approval (switch) + approval.limit (integer rial, 0 = off). Both owner-controlled.
 * A NEW purchase/sale created by a STAFF user whose total >= limit is flagged doc_status='pending';
 * the OWNER approves it (doc_status='approved'). The document keeps today's stock/ledger effect (flag only, never blocks).
 * Actions: approval.get | approval.set_limit (owner) | approval.approve (owner). Approve/limit changes are audited (audit.php map).
 */

function pb_appr_limit(PDO $pdo, int $cid): int {
    return max(0, (int)pb_app_setting_get($pdo, $cid, 'approval.limit', '0'));
}

function pb_appr_total(array $r): int {
    return (int)round((float)$r['price'] * (float)$r['net_weight'] * pb_posting_unit_mult());
}

/** Called right after a NEW document is created (inside its transaction). */
function pb_appr_mark(PDO $pdo, int $cid, array $customer, string $docType, int $docId): void {
    if ((int)($customer['_staff_id'] ?? 0) <= 0) return; // owner documents need no approval
    if (!pb_form_on($pdo, $cid, 'approval')) return;
    $limit = pb_appr_limit($pdo, $cid);
    if ($limit <= 0) return;
    $tbl = $docType === 'sale' ? 'sales' : 'purchases';
    $st = $pdo->prepare("SELECT price, net_weight FROM $tbl WHERE id=? AND customer_id=?");
    $st->execute([$docId, $cid]);
    $r = $st->fetch();
    if (!$r || pb_appr_total($r) < $limit) return;
    $pdo->prepare("UPDATE $tbl SET doc_status='pending' WHERE id=? AND customer_id=?")->execute([$docId, $cid]);
}

function pb_appr_owner_only(array $customer): void {
    if ((int)($customer['_staff_id'] ?? 0) > 0) bad('فقط مالک می‌تواند این کار را انجام دهد', 403);
}

function pb_appr_get(PDO $pdo, int $cid): array {
    $pending = [];
    $q = $pdo->prepare("SELECT id, code, type_name, date, price, net_weight, seller AS party FROM purchases WHERE customer_id=? AND doc_status='pending' ORDER BY id DESC LIMIT 200");
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) {
        $pending[] = ['doc_type' => 'purchase', 'id' => (int)$r['id'], 'code' => $r['code'], 'title' => (string)$r['type_name'], 'date' => $r['date'],
            'net_weight' => (float)$r['net_weight'], 'total_rial' => pb_appr_total($r), 'party' => $r['party']];
    }
    $q = $pdo->prepare("SELECT id, date, price, net_weight, buyer AS party FROM sales WHERE customer_id=? AND doc_status='pending' ORDER BY id DESC LIMIT 200");
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) {
        $pending[] = ['doc_type' => 'sale', 'id' => (int)$r['id'], 'code' => null, 'title' => 'فروش', 'date' => $r['date'],
            'net_weight' => (float)$r['net_weight'], 'total_rial' => pb_appr_total($r), 'party' => $r['party']];
    }
    return ['enabled' => pb_form_on($pdo, $cid, 'approval'), 'limit' => pb_appr_limit($pdo, $cid), 'pending' => $pending];
}

function pb_appr_set_limit(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_appr_owner_only($customer);
    $v = $p['limit'] ?? null;
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1e15) bad('سقف مبلغ نامعتبر است');
    pb_app_setting_set($pdo, $cid, 'approval.limit', (string)(int)round((float)$v));
    return pb_appr_get($pdo, $cid);
}

function pb_appr_approve(PDO $pdo, int $cid, array $customer, array $p): array {
    pb_appr_owner_only($customer);
    $type = (string)($p['doc_type'] ?? '');
    if (!in_array($type, ['purchase', 'sale'], true)) bad('نوع سند نامعتبر است');
    $tbl = $type === 'sale' ? 'sales' : 'purchases';
    $st = $pdo->prepare("UPDATE $tbl SET doc_status='approved' WHERE id=? AND customer_id=? AND doc_status='pending'");
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    if ($st->rowCount() < 1) bad('سند در انتظار تأیید یافت نشد', 404);
    return pb_appr_get($pdo, $cid);
}
