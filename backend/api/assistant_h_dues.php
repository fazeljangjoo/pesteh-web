<?php
declare(strict_types=1);
/**
 * سررسیدها (dues): دو فهرست جدا «سررسید وصول» و «سررسید بدهی»، هرکدام برای امروز / فردا / ۷ روز آینده.
 * start = خلاصه + دکمه‌ی بازه‌ها؛ answer(params.range = today|tomorrow|week|overdue) = ریز ردیف‌ها.
 * بازه‌ها از assistant_config.php (dues.today/tomorrow/week/show_overdue/max_rows). فقط‌خواندنی.
 */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }
require_once __DIR__ . '/assistant_h_common.php';

/** تعریف بازه‌ها از config: [id => [label, from, to]] (null = بی‌کران) */
function as_rd_dues_ranges(): array {
    $c = (array)(as_config()['dues'] ?? []);
    $t = (int)($c['today'] ?? 0);
    $tm = (int)($c['tomorrow'] ?? 1);
    $w = (int)($c['week'] ?? 7);
    $d0 = as_rd_date_offset($t);
    $ranges = [
        'today'    => ['label' => 'امروز',  'from' => $d0, 'to' => $d0],
        'tomorrow' => ['label' => 'فردا',   'from' => as_rd_date_offset($tm), 'to' => as_rd_date_offset($tm)],
        'week'     => ['label' => as_fa_digits((string)$w) . ' روز آینده', 'from' => $d0, 'to' => as_rd_date_offset($w)],
    ];
    if (!empty($c['show_overdue'])) {
        $ranges['overdue'] = ['label' => 'گذشته‌های وصول‌نشده', 'from' => null, 'to' => as_rd_date_offset($t - 1)];
    }
    return $ranges;
}

function as_rd_dues_sum(array $rows): float {
    $s = 0.0;
    foreach ($rows as $r) $s += (float)$r['amount'];
    return $s;
}

function as_rd_dues_summary_value(array $rows, string $cur): string {
    if (!$rows) return 'موردی نیست';
    return as_num((float)count($rows)) . ' مورد · ' . as_money(as_rd_dues_sum($rows));
}

function as_h_dues(array $ctx): array {
    $pdo = $ctx['pdo'];
    $cid = (int)$ctx['cid'];
    $step = (string)($ctx['step'] ?? 'start');
    $cur = as_money_unit();
    $ranges = as_rd_dues_ranges();
    $all = as_rd_dues_all($pdo, $cid);

    if ($step === 'start') {
        $recLines = []; $payLines = []; $buttons = [];
        foreach ($ranges as $id => $rg) {
            $rec = as_rd_dues_band($all, true, $rg['from'], $rg['to']);
            $pay = as_rd_dues_band($all, false, $rg['from'], $rg['to']);
            $recLines[] = ['label' => $rg['label'], 'value' => as_rd_dues_summary_value($rec, $cur), 'raw' => round(as_rd_dues_sum($rec), 2)];
            $payLines[] = ['label' => $rg['label'], 'value' => as_rd_dues_summary_value($pay, $cur), 'raw' => round(as_rd_dues_sum($pay), 2)];
            $buttons[] = ['id' => 'dues_' . $id, 'label' => $rg['label'], 'icon' => '📅', 'action' => as_action('dues', 'answer', ['range' => $id])];
        }
        $buttons[] = ['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')];
        return as_response([
            blk_card('سررسید وصول (باید دریافت کنیم)', $recLines),
            blk_card('سررسید بدهی (باید بپردازیم)', $payLines),
            blk_text('برای دیدن ریز هر بازه، یکی را انتخاب کنید.'),
            blk_buttons($buttons),
        ]);
    }

    if ($step !== 'answer') as_bad('درخواست نامعتبر است', 400);
    $range = as_param_str((array)$ctx['params'], 'range', 20);
    if ($range === null || !isset($ranges[$range])) as_bad('درخواست نامعتبر است', 400);
    $rg = $ranges[$range];
    $maxRows = max(1, (int)((as_config()['dues'] ?? [])['max_rows'] ?? 200));

    $blocks = [];
    $heading = $rg['label'] . ($rg['from'] !== null && $rg['from'] === $rg['to'] ? ' — ' . as_jdate($rg['from']) : '');
    $blocks[] = blk_text($heading);
    foreach ([[true, 'سررسید وصول'], [false, 'سررسید بدهی']] as [$receivable, $title]) {
        $band = as_rd_dues_band($all, $receivable, $rg['from'], $rg['to']);
        if (!$band) {
            $blocks[] = blk_card($title, [['label' => $rg['label'], 'value' => 'موردی نیست']]);
            continue;
        }
        $shown = array_slice($band, 0, $maxRows);
        $rows = [];
        foreach ($shown as $it) {
            $extra = trim($it['ref'] . ($it['ref'] !== '' && $it['bank'] !== '' ? ' / ' : '') . $it['bank']);
            $rows[] = [$it['party'] === '' ? 'بدون نام' : $it['party'], $it['kind'],
                as_money($it['amount']), as_jdate($it['date']), $extra === '' ? '—' : $extra];
        }
        $blocks[] = blk_table($title . ' (' . as_num((float)count($band)) . ' مورد)', [
            ['key' => 'party', 'label' => 'طرف‌حساب'],
            ['key' => 'kind', 'label' => 'نوع'],
            ['key' => 'amount', 'label' => 'مبلغ', 'align' => 'end'],
            ['key' => 'date', 'label' => 'تاریخ'],
            ['key' => 'cheque', 'label' => 'شماره چک / بانک'],
        ], $rows, ['جمع', '', as_money(as_rd_dues_sum($band)), '', '']);
        if (count($band) > $maxRows) {
            $blocks[] = blk_notice('فقط ' . as_num((float)$maxRows) . ' ردیف اول نمایش داده شد؛ جمع بالا مربوط به همه‌ی موارد است.', 'info');
        }
    }
    $blocks[] = as_rd_nav('dues', 'بازه‌ی دیگر');
    return as_response($blocks);
}

as_register('dues', 'as_h_dues');
