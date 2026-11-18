<?php
declare(strict_types=1);
/** میزان بدهی ما به یک طرف‌حساب (debt_party) — فقط‌خواندنی. */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }
require_once __DIR__ . '/assistant_h_common.php';

function as_h_debt_party(array $ctx): array {
    $pdo = $ctx['pdo'];
    $cid = (int)$ctx['cid'];
    $step = (string)($ctx['step'] ?? 'start');
    $cur = as_money_unit();
    $eps = 0.5; // کمتر از نیم تومان = صفر
    $parties = as_rd_party_debts($pdo, $cid); // فقط طرف‌حساب‌های همین مشتری

    if ($step === 'start') {
        if (!$parties) {
            return as_response([
                blk_notice('هنوز طرف‌حسابی ثبت نشده است.', 'info'),
                blk_buttons([['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')]]),
            ]);
        }
        $items = [];
        foreach ($parties as $p) {
            $items[] = ['label' => $p['name'] === '' ? 'بدون نام' : $p['name'],
                'sublabel' => $p['debt'] > $eps ? 'بدهی: ' . as_money($p['debt']) : 'بدون بدهی',
                'action' => as_action('debt_party', 'answer', ['value' => $p['key']])];
        }
        return as_response([blk_text('بدهی به کدام طرف‌حساب را می‌خواهید ببینید؟'), blk_choice('یک طرف‌حساب را انتخاب کنید', $items, true)], ['data_note' => null]);
    }

    if ($step !== 'answer') as_bad('درخواست نامعتبر است', 400);
    $value = as_param_str((array)$ctx['params'], 'value', 64);
    $found = null;
    if ($value !== null) {
        foreach ($parties as $p) { if (hash_equals($p['key'], $value)) { $found = $p; break; } }
    }
    if ($found === null) as_bad('مورد یافت نشد', 404); // طرف‌حسابِ مشتری دیگر/نامعتبر ⇒ ۴۰۴ عمومی

    $name = $found['name'] === '' ? 'بدون نام' : $found['name'];
    $blocks = [];
    if ($found['debt'] > $eps) {
        // مؤلفه‌ی منفی (بستانکاری در یک بخش) به‌صورت عدد منفی نشان داده نمی‌شود
        $buy = max(0.0, $found['weOwe']);
        $loan = max(0.0, $found['loanPayable']);
        $rows = [
            ['label' => 'بابت خرید', 'value' => as_money($buy), 'raw' => round($buy, 2)],
            ['label' => 'بابت قرض', 'value' => as_money($loan), 'raw' => round($loan, 2)],
        ];
        if ($found['openingDebt'] > $eps) $rows[] = ['label' => 'بابت مانده اول دوره', 'value' => as_money($found['openingDebt']), 'raw' => round($found['openingDebt'], 2)];
        $blocks[] = blk_card('بدهی شما به «' . $name . '»', $rows, as_money($found['debt']));
        if ($found['weOwe'] < -$eps || $found['loanPayable'] < -$eps) {
            $blocks[] = blk_notice('بخشی از حساب شما با این طرف‌حساب بستانکار است و از مبلغ کل کم شده است.', 'info');
        }
    } else {
        $credit = $found['debt'] < -$eps;
        $blocks[] = blk_card('بدهی شما به «' . $name . '»', [
            ['label' => 'میزان بدهی', 'value' => as_money(0.0), 'raw' => 0.0],
        ], $credit ? 'بدهی صفر — بستانکار هستید' : 'بدهی‌ای وجود ندارد');
    }
    $blocks[] = as_rd_nav('debt_party', 'طرف‌حساب دیگر');
    return as_response($blocks);
}

as_register('debt_party', 'as_h_debt_party');
