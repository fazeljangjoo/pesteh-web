<?php
declare(strict_types=1);
/**
 * S31.T31.14 — unified due reminders feed (read-only, all editions). Action: reminders.due {days?: 0..90 (default 7), overdue?: bool (default true)}
 * One sorted list of everything that falls due within `days` (plus overdue, unless overdue=false):
 *   cheque_received / cheque_issued (open cheques from the register), receivable_inst / payable_inst (unpaid credit installments),
 *   lc (fx plan: next open letter-of-credit deadline). Cheque installments are NOT read from *_cheque_inst (the register mirrors them: no double count).
 * Item: {kind, title, party, amount, due_date, days_left, overdue, ref:{table,id}, note?}. Client uses it for the reminder center + rescheduling local notifications.
 */
const PB_REM_OPEN_CHEQUE = ['received' => ['in_hand', 'deposited'], 'issued' => ['pending']];

function pb_rem_due(PDO $pdo, int $cid, array $customer, array $p): array {
    $days = max(0, min(90, isset($p['days']) ? (int)$p['days'] : 7));
    $withOver = !array_key_exists('overdue', $p) || (bool)$p['overdue'];
    $today = today();
    $limit = date('Y-m-d', strtotime($today . ' +' . $days . ' days'));
    $items = [];
    $push = static function (string $kind, string $title, ?string $party, float $amount, string $due, string $table, int $id, ?string $note = null) use (&$items, $today, $withOver): void {
        $left = (int)floor((strtotime($due) - strtotime($today)) / 86400);
        if ($left < 0 && !$withOver) return;
        $it = ['kind' => $kind, 'title' => $title, 'party' => $party, 'amount' => round($amount, 2), 'due_date' => $due, 'days_left' => $left, 'overdue' => $left < 0,
            'ref' => ['table' => $table, 'id' => $id]];
        if ($note !== null) $it['note'] = $note;
        $items[] = $it;
    };

    pb_cheque_ensure($pdo);
    pb_cheque_sync($pdo, $cid);
    $st = $pdo->prepare("SELECT id, direction, number, bank, amount, due_date, party, status FROM cheques WHERE customer_id=? AND due_date <= ?
        AND ((direction='received' AND status IN ('in_hand','deposited')) OR (direction='issued' AND status='pending')) ORDER BY due_date, id LIMIT 1000");
    $st->execute([$cid, $limit]);
    foreach ($st->fetchAll() as $r) {
        $recv = $r['direction'] === 'received';
        $push($recv ? 'cheque_received' : 'cheque_issued', ($recv ? 'چک دریافتی' : 'چک پرداختنی') . ($r['number'] ? ' ' . $r['number'] : ''), $r['party'], (float)$r['amount'], (string)$r['due_date'], 'cheques', (int)$r['id'], $r['bank'] ?: null);
    }
    $creditMap = [];
    try { $creditMap = pb_dues_credit_map($pdo, $cid); } catch (Throwable $e) { /* v71: return credit (same adjustment as the dues screen) */ }
    foreach ([['receipt_credit_inst', 'receivable_inst', 'قسط دریافتنی', 'payer_name'], ['credit_inst', 'payable_inst', 'قسط پرداختنی', 'seller_name']] as [$t, $kind, $title, $pcol]) {
        $st = $pdo->prepare("SELECT id, amount, due_date, $pcol AS party FROM $t WHERE customer_id=? AND paid=0 AND due_date <= ? ORDER BY due_date, id LIMIT 1000");
        $st->execute([$cid, $limit]);
        foreach ($st->fetchAll() as $r) {
            $amt = (float)$r['amount'] - (float)($creditMap[$t . '#' . (int)$r['id']] ?? 0.0);
            if ($amt < 0.5) continue; // fully covered by return credit
            $push($kind, $title, $r['party'], $amt, (string)$r['due_date'], $t, (int)$r['id']);
        }
    }
    if (pb_plan_of($customer) === 'fx') {
        require_once __DIR__ . '/lc.php';
        pb_lc_ensure($pdo);
        foreach (pb_lc_list($pdo, $cid, [])['items'] as $r) {
            if ($r['next_deadline'] === null || $r['next_deadline'] > $limit) continue;
            $lbl = ['ship' => 'مهلت حمل', 'present' => 'مهلت ارائه مدارک', 'expiry' => 'انقضای اعتبار'][$r['next_kind']] ?? 'مهلت';
            $push('lc', 'اعتبار اسنادی ' . $r['lc_no'] . ' — ' . $lbl, $r['buyer'] ?? null, (float)$r['amount'], (string)$r['next_deadline'], 'letters_of_credit', (int)$r['id'], (string)$r['ccy_code']);
        }
    }
    usort($items, static fn($a, $b) => [$a['due_date'], $a['kind']] <=> [$b['due_date'], $b['kind']]);
    $over = array_filter($items, static fn($i) => $i['overdue']);
    return ['today' => $today, 'days' => $days, 'items' => $items, 'count' => count($items), 'overdue_count' => count($over),
        'by_kind' => array_count_values(array_column($items, 'kind'))];
}

function pb_rem_action(string $action, array $p, PDO $pdo, array $customer): void {
    out(pb_rem_due($pdo, (int)$customer['id'], $customer, $p));
}
