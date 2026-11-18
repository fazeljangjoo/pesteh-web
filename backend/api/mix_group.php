<?php
declare(strict_types=1);
/**
 * T3a (ردیابی پسته) — «کلید گروه مخلوط» برای رنگ‌آمیزی انبار. تابع خالص (بدون PDO).
 * آینه: Dart `mixGroupKeys` در lib/code_kind.dart و JS `PBCode.mixGroupKeys`؛ تست مشترک: tests/code_kind_cases.json (بخش mix_group_cases).
 *
 * قواعد:
 *  • لات مخلوط (is_mix=1) همیشه کلید خودش را دارد (حتی اگر خودش وارد مخلوط بزرگ‌تری شده باشد).
 *  • لات غیرمخلوط (is_mix=0) که وارد چند مخلوط شده ⇒ کلید «جدیدترین» مخلوط (بزرگ‌ترین id).
 *  • لات بدون مخلوط، و لات‌های is_mix=2/3 ⇒ null (بدون رنگ گروه).
 * هیچ مبلغی در این فایل نیست.
 */

/** عدد صحیح از مقدار ورودی (رشتهٔ عددی هم پذیرفته)؛ غیرعدد ⇒ null. */
function pb_mix_group_int($v): ?int {
    if ($v === null || $v === '' || is_bool($v) || is_array($v)) return null;
    return is_numeric($v) ? (int)$v : null;
}

/**
 * @param array $lots    هر عنصر: ['id'=>…, 'is_mix'=>…]
 * @param array $sources هر عنصر: mix_purchase_id|mix_id و source_purchase_id|source_id
 * @return array<int,?int> برای «هر» لات ورودی یک ورودی: lotId ⇒ کلید گروه یا null
 */
function pb_mix_group_keys(array $lots, array $sources): array {
    $newest = []; // sourceId ⇒ بزرگ‌ترین mixId
    foreach ($sources as $s) {
        $mix = pb_mix_group_int($s['mix_purchase_id'] ?? $s['mix_id'] ?? null);
        $src = pb_mix_group_int($s['source_purchase_id'] ?? $s['source_id'] ?? null);
        if ($mix === null || $src === null) continue;
        if (!isset($newest[$src]) || $mix > $newest[$src]) $newest[$src] = $mix;
    }
    $out = [];
    foreach ($lots as $l) {
        $id = pb_mix_group_int($l['id'] ?? null);
        if ($id === null) continue;
        $kind = pb_mix_group_int($l['is_mix'] ?? null) ?? 0;
        if ($kind === 1) $out[$id] = $id;
        elseif ($kind === 0) $out[$id] = $newest[$id] ?? null;
        else $out[$id] = null;
    }
    return $out;
}
