<?php
/**
 * S12.T12.1 — company profile (singleton per customer) + document number sequences.
 * Both are generic entities (registered in entities.php); this file holds only their hooks.
 * View/edit of `doc_sequences` is open to every staff member (cashiers allocate numbers); `company_profile`
 * is edited with settings.manage. Statutory/identity numbers (economic code...) are free text, never validated
 * against a rate or statute.
 */

const PB_DOC_TYPES = ['sale', 'purchase', 'sale_return', 'purchase_return', 'proforma', 'receipt', 'payment', 'other'];

/** One profile row per customer: a second create is rejected (edit the existing row instead). */
function pb_company_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    if ($isNew) {
        $st = $pdo->prepare('SELECT 1 FROM ent_company_profile WHERE customer_id=? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$cid]);
        if ($st->fetchColumn()) bad('پروفایل شرکت قبلاً ثبت شده است؛ آن را ویرایش کنید');
    }
    if (isset($vals['logo']) && is_string($vals['logo']) && $vals['logo'] !== '' && strpos($vals['logo'], 'data:image/') !== 0) {
        bad('لوگو باید تصویر باشد');
    }
}

/** (doc_type, fy) must be unique per customer; next>=1; padding 0..12; prefix limited to safe characters. */
function pb_docseq_before_save(PDO $pdo, int $cid, bool $isNew, ?array $existing, array &$vals): void {
    $dt = array_key_exists('doc_type', $vals) ? (string)$vals['doc_type'] : (string)($existing['doc_type'] ?? '');
    $fy = array_key_exists('fy', $vals) ? (int)$vals['fy'] : (int)($existing['fy'] ?? 0);
    $next = array_key_exists('next_no', $vals) ? (int)$vals['next_no'] : (int)($existing['next_no'] ?? 1);
    if (!in_array($dt, PB_DOC_TYPES, true)) bad('نوع سند نامعتبر است');
    if ($next < 1) bad('شمارهٔ بعدی باید حداقل ۱ باشد');
    if (isset($vals['prefix']) && !preg_match('/^[A-Za-z0-9_\-\/]{0,12}$/', (string)$vals['prefix'])) {
        bad('پیشوند فقط حرف لاتین، عدد و - _ / (حداکثر ۱۲ نویسه)');
    }
    $st = $pdo->prepare('SELECT uid FROM ent_doc_sequences WHERE customer_id=? AND doc_type=? AND fy=? AND deleted_at IS NULL AND uid<>?');
    $st->execute([$cid, $dt, $fy, $existing['uid'] ?? '']);
    if ($st->fetchColumn()) bad('برای این نوع سند و سال مالی قبلاً شمارنده ثبت شده است');
    // never move the counter backwards (would re-issue numbers)
    if ($existing !== null && array_key_exists('next_no', $vals) && (int)$vals['next_no'] < (int)$existing['next_no']) {
        bad('شمارهٔ بعدی نمی‌تواند کمتر از مقدار فعلی شود');
    }
}
