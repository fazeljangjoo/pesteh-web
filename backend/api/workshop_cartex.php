<?php
declare(strict_types=1);
require_once __DIR__ . '/workshop_calc.php';
/**
 * کارگاه — مرحلهٔ ۶-۱: «کارتکس مالک» (دفتر ورود/خروج پستهٔ هر مالک با مانده تجمعی). فقط‌خواندنی، بدون هیچ قیمتی.
 * منبع (تصمیم کاربر): فقط کارگاه بیرونی با قبض (proc_orders با direction='paid'): ارسال = خروج در order_date؛ دریافت (status='done') = ورود در receive_date (یا order_date).
 * ستون‌های «خرید/فروش» = نوع رویداد (ارسال/دریافت)، «گرم» = sent_gram_pct (ارسال) / recv_gram_pct (دریافت)؛ انس و دهنبست خالی. منطق در pb_cartex_build (workshop_calc.php).
 * مجوز: workshop.feed (اپراتور کارگاه فقط‌مشاهده).
 */
const PB_CARTEX_LIMIT = 5000;

/** reports.cartex {from?, to?, owner?, party?} — فیلتر مالک/کارگاه روی خود سفارش‌ها (مانده هر مالک مستقل است)؛ from/to فقط ردیف‌ها را می‌برند و مانده افتتاحیه حفظ می‌شود. */
function pb_cartex_report(PDO $pdo, int $cid, array $p): array {
    $from = ($p['from'] ?? '') !== '' ? vdate($p['from']) : null;
    $to = ($p['to'] ?? '') !== '' ? vdate($p['to']) : null;
    $sql = "SELECT id, order_date, receive_date, party, owner, receipt_no, status, bale_count, input_kg, output_kg, waste_kg, sent_gram_pct, recv_gram_pct FROM proc_orders WHERE customer_id=? AND direction='paid'";
    $args = [$cid];
    foreach (['owner', 'party'] as $k) if (($p[$k] ?? '') !== '') { $sql .= " AND $k = ?"; $args[] = (string)$p[$k]; }
    $st = $pdo->prepare($sql . ' ORDER BY order_date ASC, id ASC LIMIT ' . PB_CARTEX_LIMIT);
    $st->execute($args);
    $kg = fn($v) => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
    $ev = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int)$r['id'];
        $base = trim((string)($r['party'] ?? '')) . (trim((string)($r['receipt_no'] ?? '')) !== '' ? ' · قبض ' . trim((string)$r['receipt_no']) : '');
        $bale = ($r['bale_count'] === null || $r['bale_count'] === '') ? null : (float)$r['bale_count'];
        $ev[] = ['date' => (string)$r['order_date'], 'ord' => $id * 2, 'event' => 'send', 'owner' => (string)($r['owner'] ?? ''), 'bale_count' => $bale,
            'out_kg' => (float)$r['input_kg'], 'gram_pct' => $r['sent_gram_pct'], 'note' => 'ارسال به ' . $base, 'ref_id' => $id];
        if ($r['status'] === 'done') {
            $w = pb_ws_g($r['waste_kg'] ?? 0);
            $ev[] = ['date' => (string)(($r['receive_date'] ?? '') !== '' ? $r['receive_date'] : $r['order_date']), 'ord' => $id * 2 + 1, 'event' => 'receive', 'owner' => (string)($r['owner'] ?? ''), 'bale_count' => null,
                'in_kg' => (float)$r['output_kg'], 'gram_pct' => $r['recv_gram_pct'], 'note' => 'دریافت از ' . $base . ($w > 0 ? ' · ضایعات ' . $kg($w / 1000) . ' کیلو' : ''), 'ref_id' => $id];
        }
    }
    return pb_cartex_build($ev, $from, $to);
}
