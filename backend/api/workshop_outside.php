<?php
declare(strict_types=1);
require_once __DIR__ . '/workshop_calc.php';
/**
 * کارگاه — مرحلهٔ ۴: «کارگاه بیرونی با قبض». روی جهت موجود `paid` (برون‌سپاری) در proc_orders سوار است (جدول موازی ندارد):
 * پسته از موجودی کم می‌شود (processing.create) و با دریافت خروجی (processing.receive) برمی‌گردد (تصمیم قطعی ۱).
 * ستون‌های قبض (v67، همه NULL‌پذیر، بدون هیچ مبلغ): receipt_no (شمارهٔ قبض) · cash_note (کش) · non_company_note (غیر شرکتی)
 * · sent_gram_pct (F) · recv_kernel_kg (H) · recv_gram_pct (I)؛ و ستون‌های موجود: owner (نام مالک) · bale_count (عدل) · input_kg (E).
 * «کش» و «غیر شرکتی» تا پاسخ کاربر فقط متن اختیاری‌اند. G/J/K در pb_ow_calc (workshop_calc.php) مشتق می‌شوند و ذخیره نمی‌شوند.
 * گزارش «فایل خام شکست کارگاه‌ها»: workshop.outside_raw (ردیف‌ها A..K + ردیف جمع)؛ فقط‌خواندنی، بدون قیمت، با stock.view.
 * هر فیلد مالی (کرایه/بها) مثل قبل فقط از مسیر price.view دیده می‌شود؛ نقش «اپراتور کارگاه» به processing.* و این گزارش دسترسی ندارد.
 */
const PB_OW_STR = ['receipt_no' => 60, 'cash_note' => 150, 'non_company_note' => 150, 'owner' => 150];
/** ستون‌های عددی نامنفی: [نام ⇒ سقف] */
const PB_OW_NUM = ['bale_count' => 1e7, 'sent_gram_pct' => 100, 'recv_kernel_kg' => 1e9, 'recv_gram_pct' => 100];
const PB_OW_COLS = [['sent_gram_pct', 'DECIMAL(7,3) NULL'], ['recv_kernel_kg', 'DECIMAL(14,3) NULL'], ['recv_gram_pct', 'DECIMAL(7,3) NULL'],
    ['receipt_no', 'VARCHAR(60) NULL'], ['cash_note', 'VARCHAR(150) NULL'], ['non_company_note', 'VARCHAR(150) NULL']];

/** ورودی قبض را اعتبارسنجی می‌کند؛ فقط کلیدهای حاضر در $p برمی‌گردند (ذخیرهٔ جزئی). @param string[]|null $only محدود به این کلیدها */
function pb_ow_normalize(array $p, ?array $only = null): array {
    $c = [];
    foreach (PB_OW_STR as $k => $len) {
        if (($only !== null && !in_array($k, $only, true)) || !array_key_exists($k, $p)) continue;
        $c[$k] = str_or_null($p[$k], $len);
    }
    foreach (PB_OW_NUM as $k => $max) {
        if (($only !== null && !in_array($k, $only, true)) || !array_key_exists($k, $p)) continue;
        $v = nullableNum($p[$k]);
        if ($v !== null && ($v < 0 || $v > $max)) pb_proc_fail('مقدار نامعتبر: ' . $k);
        $c[$k] = $v;
    }
    return $c;
}

function pb_ow_apply(PDO $pdo, int $cid, int $id, array $cols): void {
    if (!$cols) return;
    $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($cols)));
    $pdo->prepare("UPDATE proc_orders SET $set WHERE id=? AND customer_id=? AND direction='paid'")->execute(array_merge(array_values($cols), [$id, $cid]));
}

/** فیلدهای قبض یک سفارش paid (+ calc) برای pb_proc_get / pb_proc_list. بدون مبلغ. */
function pb_ow_fields(array $o): array {
    $n = fn($k) => (!array_key_exists($k, $o) || $o[$k] === null) ? null : (float)$o[$k];
    $f = ['receipt_no' => $o['receipt_no'] ?? null, 'cash_note' => $o['cash_note'] ?? null, 'non_company_note' => $o['non_company_note'] ?? null,
        'owner' => $o['owner'] ?? null, 'bale_count' => $n('bale_count'), 'sent_kg' => (float)$o['input_kg'],
        'sent_gram_pct' => $n('sent_gram_pct'), 'recv_kernel_kg' => $n('recv_kernel_kg'), 'recv_gram_pct' => $n('recv_gram_pct')];
    $f['calc'] = pb_ow_calc($f);
    return $f;
}

/** processing.receipt_save {id, receipt_no?, cash_note?, non_company_note?, owner?, bale_count?, sent_gram_pct?, recv_kernel_kg?, recv_gram_pct?}
 *  ذخیرهٔ جزئی (فقط کلیدهای ارسالی)؛ فقط سفارش paid؛ در هر وضعیتی مجاز (قبض و گرم‌ها معمولاً بعداً کامل می‌شوند). */
function pb_ow_receipt_save(PDO $pdo, int $cid, array $p): array {
    $id = (int)($p['id'] ?? 0);
    $st = $pdo->prepare('SELECT direction FROM proc_orders WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $d = $st->fetchColumn();
    if ($d === false) pb_proc_fail('سفارش فراوری یافت نشد', 404);
    if ($d !== 'paid') pb_proc_fail('قبض فقط برای کارگاه بیرونی (برون‌سپاری) ثبت می‌شود');
    pb_ow_apply($pdo, $cid, $id, pb_ow_normalize($p));
    return pb_proc_get($pdo, $cid, ['id' => $id]);
}

/** workshop.outside_raw {from?, to?, owner?, party?, status?} — «فایل خام شکست کارگاه‌ها»: ردیف‌ها (A..K) + ردیف جمع. */
function pb_ow_raw(PDO $pdo, int $cid, array $p): array {
    $sql = "SELECT * FROM proc_orders WHERE customer_id=? AND direction='paid'";
    $args = [$cid];
    if (($p['from'] ?? '') !== '') { $sql .= ' AND order_date >= ?'; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= ' AND order_date <= ?'; $args[] = vdate($p['to']); }
    foreach (['owner', 'party', 'status'] as $k) if (($p[$k] ?? '') !== '') { $sql .= " AND $k = ?"; $args[] = (string)$p[$k]; }
    $st = $pdo->prepare($sql . ' ORDER BY order_date DESC, id DESC LIMIT 1000');
    $st->execute($args);
    $items = []; $src = [];
    foreach ($st->fetchAll() as $r) {
        $f = pb_ow_fields($r);
        $src[] = $f;
        $items[] = ['id' => (int)$r['id'], 'date' => $r['order_date'], 'party' => $r['party'], 'status' => $r['status']] + $f;
    }
    return ['items' => $items, 'totals' => pb_ow_totals($src)];
}
