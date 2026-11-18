<?php
declare(strict_types=1);
/** سؤال‌های موجودی انبار: stock_total و stock_item (فقط‌خواندنی). */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }
require_once __DIR__ . '/assistant_h_common.php';

/** موجودی کل انبار: کارت جمع + جدول به تفکیک جنس */
function as_h_stock_total(array $ctx): array {
    $groups = as_rd_stock_groups($ctx['pdo'], (int)$ctx['cid']);
    $total = 0.0;
    $rows = [];
    foreach ($groups as $g) {
        $total += $g['kg'];
        $rows[] = [$g['type_name'], $g['category_name'], as_num($g['kg'], 2)];
    }
    $blocks = [];
    if (!$groups) {
        $blocks[] = blk_card('موجودی کل انبار', [['label' => 'موجودی کل (کیلوگرم)', 'value' => as_num(0.0), 'raw' => 0.0]], '۰ کیلوگرم');
        $blocks[] = blk_notice('در حال حاضر موجودی‌ای در انبار ثبت نشده است.', 'info');
    } else {
        $blocks[] = blk_card('موجودی کل انبار', [
            ['label' => 'موجودی کل (کیلوگرم)', 'value' => as_num($total, 2), 'raw' => round($total, 3)],
            ['label' => 'تعداد اجناس', 'value' => as_num((float)count($groups)), 'raw' => count($groups)],
        ], as_num($total, 2) . ' کیلوگرم');
        $blocks[] = blk_table('به تفکیک جنس', [
            ['key' => 'type', 'label' => 'جنس'],
            ['key' => 'category', 'label' => 'دسته'],
            ['key' => 'kg', 'label' => 'کیلوگرم', 'align' => 'end'],
        ], $rows, ['جمع', '', as_num($total, 2)]);
    }
    $blocks[] = as_rd_nav('stock_total', 'تازه‌سازی');
    return as_response($blocks);
}

/** موجودی یک جنس: start = فهرست اجناسِ دارای موجودی؛ answer = جزئیات لات‌ها */
function as_h_stock_item(array $ctx): array {
    $pdo = $ctx['pdo'];
    $cid = (int)$ctx['cid'];
    $step = (string)($ctx['step'] ?? 'start');
    $groups = as_rd_stock_groups($pdo, $cid); // همیشه فقط داده‌ی همین مشتری

    if ($step === 'start') {
        if (!$groups) {
            return as_response([
                blk_notice('در حال حاضر هیچ جنسی با موجودی در انبار ثبت نشده است.', 'info'),
                blk_buttons([['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')]]),
            ]);
        }
        $items = [];
        foreach ($groups as $g) {
            $items[] = ['label' => $g['type_name'], 'sublabel' => $g['category_name'] . ' · ' . as_num($g['kg'], 2) . ' کیلوگرم',
                'action' => as_action('stock_item', 'answer', ['value' => $g['key']])];
        }
        return as_response([blk_text('کدام جنس؟'), blk_choice('یک جنس را انتخاب کنید', $items, true)], ['data_note' => null]);
    }

    if ($step !== 'answer') as_bad('درخواست نامعتبر است', 400);
    $value = as_param_str((array)$ctx['params'], 'value', 64);
    if ($value === null || !isset($groups[$value])) as_bad('مورد یافت نشد', 404); // کلید غریبه/نامعتبر ⇒ ۴۰۴ عمومی
    $g = $groups[$value];

    $rows = [];
    foreach ($g['lots'] as $r) {
        $rows[] = [(string)($r['code'] ?? ''), as_jdate($r['date'] === null ? null : substr((string)$r['date'], 0, 10)),
            as_num((float)$r['remaining'], 2),
            as_num((float)($r['small_sacks_remaining'] ?? 0)), as_num((float)($r['large_sacks_remaining'] ?? 0))];
    }
    $blocks = [blk_card($g['type_name'], [
        ['label' => 'دسته', 'value' => $g['category_name']],
        ['label' => 'موجودی (کیلوگرم)', 'value' => as_num($g['kg'], 2), 'raw' => round($g['kg'], 3)],
        ['label' => 'گونی کوچک باقی‌مانده', 'value' => as_num($g['small']), 'raw' => round($g['small'])],
        ['label' => 'گونی بزرگ باقی‌مانده', 'value' => as_num($g['large']), 'raw' => round($g['large'])],
    ], as_num($g['kg'], 2) . ' کیلوگرم')];
    $blocks[] = blk_table(count($rows) > 1 ? 'لات‌ها (' . as_num((float)count($rows)) . ' مورد)' : 'لات', [
        ['key' => 'code', 'label' => 'کد'],
        ['key' => 'date', 'label' => 'تاریخ'],
        ['key' => 'kg', 'label' => 'کیلوگرم', 'align' => 'end'],
        ['key' => 'small', 'label' => 'گونی کوچک', 'align' => 'end'],
        ['key' => 'large', 'label' => 'گونی بزرگ', 'align' => 'end'],
    ], $rows, count($rows) > 1 ? ['جمع', '', as_num($g['kg'], 2), as_num($g['small']), as_num($g['large'])] : null);
    $blocks[] = as_rd_nav('stock_item', 'جنس دیگر');
    return as_response($blocks);
}

as_register('stock_total', 'as_h_stock_total');
as_register('stock_item', 'as_h_stock_item');
