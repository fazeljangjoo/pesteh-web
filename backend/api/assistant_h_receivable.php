<?php
declare(strict_types=1);
/** میزان طلب ما از یک طرف‌حساب (receivable_party) — قرینهٔ debt_party؛ فقط‌خواندنی. */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }
require_once __DIR__ . '/assistant_h_common.php';

function as_h_receivable_party(array $ctx): array {
    $pdo = $ctx['pdo'];
    $cid = (int)$ctx['cid'];
    $step = (string)($ctx['step'] ?? 'start');
    $cur = as_money_unit();
    $eps = 0.5; // کمتر از نیم تومان = صفر
    $parties = as_rd_party_debts($pdo, $cid); // فقط طرف‌حساب‌های همین مشتری
    usort($parties, function (array $a, array $b): int { // بیشترین طلب اول
        if (abs($a['receivable'] - $b['receivable']) > 1e-9) return $b['receivable'] <=> $a['receivable'];
        return strcmp($a['name'], $b['name']);
    });

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
                'sublabel' => $p['receivable'] > $eps ? 'طلب: ' . as_money($p['receivable']) : 'بدون طلب',
                'action' => as_action('receivable_party', 'answer', ['value' => $p['key']])];
        }
        return as_response([blk_text('طلب از کدام طرف‌حساب (خریدار) را می‌خواهید ببینید؟'), blk_choice('یک طرف‌حساب را انتخاب کنید', $items, true)], ['data_note' => null]);
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
    if ($found['receivable'] > $eps) {
        // مؤلفهٔ منفی (بدهکاری ما در یک بخش) به‌صورت عدد منفی نشان داده نمی‌شود
        $sales = max(0.0, $found['theyOwe']);
        $loan = max(0.0, $found['loanReceivable']);
        $rows = [
            ['label' => 'بابت فروش', 'value' => as_money($sales), 'raw' => round($sales, 2)],
            ['label' => 'بابت قرض', 'value' => as_money($loan), 'raw' => round($loan, 2)],
        ];
        if ($found['openingReceivable'] > $eps) $rows[] = ['label' => 'بابت مانده اول دوره', 'value' => as_money($found['openingReceivable']), 'raw' => round($found['openingReceivable'], 2)];
        $blocks[] = blk_card('طلب شما از «' . $name . '»', $rows, as_money($found['receivable']));
        if ($found['theyOwe'] < -$eps || $found['loanReceivable'] < -$eps) {
            $blocks[] = blk_notice('بخشی از حساب شما با این طرف‌حساب بدهکار است و از مبلغ کل کم شده است.', 'info');
        }
    } else {
        $over = $found['receivable'] < -$eps;
        $blocks[] = blk_card('طلب شما از «' . $name . '»', [
            ['label' => 'میزان طلب', 'value' => as_money(0.0), 'raw' => 0.0],
        ], $over ? 'طلب صفر — بیش از حساب پرداخته است' : 'طلبی وجود ندارد');
    }
    $blocks[] = as_rd_nav('receivable_party', 'طرف‌حساب دیگر');
    return as_response($blocks);
}

as_register('receivable_party', 'as_h_receivable_party');
