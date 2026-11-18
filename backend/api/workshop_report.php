<?php
declare(strict_types=1);
require_once __DIR__ . '/workshop_calc.php';
/**
 * کارگاه — مرحلهٔ ۶-۲: «خلاصهٔ مدیریتی» و «مقایسهٔ کارگاه‌ها/اپراتورها». فقط‌خواندنی، بدون هیچ قیمتی؛ بدون migration.
 *  reports.workshop_summary {from?, to?, owner?}  — بخش internal (جمع ستون‌های کنترل ورود و خروج) + بخش crack (خط شکست). مجوز: stock.view (اپراتور کارگاه ندارد).
 *  reports.workshop_compare {from?, to?, by?: workshop|operator|registrar} — مجوز: workshop.feed (اپراتور فقط‌مشاهده؛ تصمیم کاربر).
 * منطق در pb_wsum_internal / pb_wsum_crack / pb_wcmp_build (workshop_calc.php). مقدار از سفارش‌های internal (proc_orders + proc_outputs)، proc_orders paid (فقط دریافت‌شده‌ها) و crack_runs.
 * کارگاه داخلی نام اپراتور ندارد ⇒ مقایسهٔ اپراتورها/ثبت‌کنندگان فقط از خط شکست است.
 */
const PB_WREPORT_LIMIT = 5000;

/** @return array{0:string,1:array} شرط تاریخ/مالک */
function pb_wreport_where(string $col, array $p, string $ownerCol = ''): array {
    $sql = ''; $args = [];
    if (($p['from'] ?? '') !== '') { $sql .= " AND $col >= ?"; $args[] = vdate($p['from']); }
    if (($p['to'] ?? '') !== '') { $sql .= " AND $col <= ?"; $args[] = vdate($p['to']); }
    if ($ownerCol !== '' && trim((string)($p['owner'] ?? '')) !== '') { $sql .= " AND $ownerCol = ?"; $args[] = trim((string)$p['owner']); }
    return [$sql, $args];
}

/** ردیف‌های internal با مجموع خروجی هر دسته. @return array[] */
function pb_wreport_internal_rows(PDO $pdo, int $cid, array $p): array {
    [$w, $a] = pb_wreport_where('order_date', $p, 'owner');
    $st = $pdo->prepare("SELECT id, party, initial_kg, input_kg, loss_kg FROM proc_orders WHERE customer_id=? AND direction='internal'$w ORDER BY order_date ASC, id ASC LIMIT " . PB_WREPORT_LIMIT);
    $st->execute(array_merge([$cid], $a));
    $orders = $st->fetchAll();
    $byOrder = [];
    if ($orders) {
        $ids = implode(',', array_map(fn($o) => (int)$o['id'], $orders));
        $os = $pdo->prepare("SELECT order_id, grade, SUM(kg) AS kg FROM proc_outputs WHERE customer_id=? AND order_id IN ($ids) GROUP BY order_id, grade");
        $os->execute([$cid]);
        foreach ($os->fetchAll() as $r) $byOrder[(int)$r['order_id']][(string)$r['grade']] = (float)$r['kg'];
    }
    $rows = [];
    foreach ($orders as $o) {
        $g = $byOrder[(int)$o['id']] ?? [];
        $rows[] = ['party' => (string)$o['party'], 'initial_kg' => $o['initial_kg'] === null ? null : (float)$o['initial_kg'], 'input_kg' => (float)$o['input_kg'], 'loss_kg' => (float)$o['loss_kg'],
            'sound_kg' => $g['sound'] ?? 0, 'deformed_fine_kg' => $g['deformed_fine'] ?? 0, 'hull_kg' => $g['hull'] ?? 0, 'kernel_kg' => $g['kernel'] ?? 0, 'soil_kg' => $g['soil'] ?? 0, 'discolored_kg' => $g['discolored'] ?? 0];
    }
    return $rows;
}

/** ردیف‌های خط شکست (+ مجموع دقیقهٔ توقف). @return array[] */
function pb_wreport_crack_rows(PDO $pdo, int $cid, array $p): array {
    pb_cr_ensure($pdo);
    [$w, $a] = pb_wreport_where('run_date', $p, 'customer_name');
    $cols = 'id, line_code, operator_name, created_by_name, input_kg, run_minutes, ' . implode(', ', array_merge(['kernel_kg', 'hull_kg', 'soil_kg'], PB_WSUM_SUB_KEYS));
    $st = $pdo->prepare("SELECT $cols FROM crack_runs WHERE customer_id=?$w ORDER BY run_date ASC, id ASC LIMIT " . PB_WREPORT_LIMIT);
    $st->execute(array_merge([$cid], $a));
    $rows = $st->fetchAll();
    $stops = [];
    if ($rows) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $ss = $pdo->prepare("SELECT run_id, SUM(minutes) AS m FROM crack_stops WHERE customer_id=? AND run_id IN ($ids) GROUP BY run_id");
        $ss->execute([$cid]);
        foreach ($ss->fetchAll() as $r) $stops[(int)$r['run_id']] = (float)$r['m'];
    }
    foreach ($rows as $i => $r) $rows[$i]['stop_minutes'] = $stops[(int)$r['id']] ?? 0.0;
    return $rows;
}

/** reports.workshop_summary — {internal:{…pb_wsum_internal}, crack:{…pb_wsum_crack}}. */
function pb_wreport_summary(PDO $pdo, int $cid, array $p): array {
    return ['internal' => pb_wsum_internal(pb_wreport_internal_rows($pdo, $cid, $p)), 'crack' => pb_wsum_crack(pb_wreport_crack_rows($pdo, $cid, $p))];
}

/** reports.workshop_compare — {by, rows:[pb_wcmp_build…]}. */
function pb_wreport_compare(PDO $pdo, int $cid, array $p): array {
    $by = (string)($p['by'] ?? 'workshop');
    if (!in_array($by, ['workshop', 'operator', 'registrar'], true)) pb_proc_fail('نوع مقایسه نامعتبر است');
    $unknown = 'نامشخص';
    $items = [];
    foreach (pb_wreport_crack_rows($pdo, $cid, $p) as $r) {
        $name = $by === 'workshop' ? trim('خط شکست ' . trim((string)($r['line_code'] ?? '')))
            : ($by === 'operator' ? (trim((string)($r['operator_name'] ?? '')) !== '' ? trim((string)$r['operator_name']) : trim((string)($r['created_by_name'] ?? '')))
                : trim((string)($r['created_by_name'] ?? '')));
        $rm = (float)($r['run_minutes'] ?? 0);
        $items[] = ['source' => 'crack', 'name' => $name !== '' ? $name : $unknown, 'input_kg' => (float)$r['input_kg'], 'good_kg' => (float)$r['kernel_kg'],
            'loss_kg' => (float)$r['input_kg'] - (float)$r['kernel_kg'], 'cap_kg' => (float)$r['kernel_kg'], 'cap_min' => $rm];
    }
    if ($by === 'workshop') {
        foreach (pb_wreport_internal_rows($pdo, $cid, $p) as $r) {
            $items[] = ['source' => 'internal', 'name' => trim($r['party']) !== '' ? trim($r['party']) : 'کارگاه داخلی', 'input_kg' => $r['input_kg'], 'good_kg' => $r['sound_kg'], 'loss_kg' => $r['loss_kg']];
        }
        [$w, $a] = pb_wreport_where('order_date', $p, 'owner');
        $st = $pdo->prepare("SELECT party, input_kg, output_kg, waste_kg, sent_gram_pct, recv_gram_pct FROM proc_orders WHERE customer_id=? AND direction='paid' AND status='done'$w ORDER BY order_date ASC, id ASC LIMIT " . PB_WREPORT_LIMIT);
        $st->execute(array_merge([$cid], $a));
        foreach ($st->fetchAll() as $r) {
            $gl = ($r['sent_gram_pct'] === null || $r['recv_gram_pct'] === null) ? null : (float)$r['sent_gram_pct'] - (float)$r['recv_gram_pct'];
            $items[] = ['source' => 'outside', 'name' => trim((string)$r['party']), 'input_kg' => (float)$r['input_kg'], 'good_kg' => (float)$r['output_kg'], 'loss_kg' => (float)$r['waste_kg'], 'gram_loss' => $gl];
        }
    }
    return ['by' => $by, 'rows' => pb_wcmp_build($items)];
}
