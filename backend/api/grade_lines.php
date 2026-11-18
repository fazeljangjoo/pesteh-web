<?php
declare(strict_types=1);
/**
 * S35.T35.3 — grade lines (separately priced sub-grades inside ONE purchase/sale), active only when form.grades = 1.
 * payload.grade_lines = [{grade_key, kg, unit_price}] (unit_price = stored money unit per kg, integer). Rules enforced here:
 *  - grade_key must exist in the company grade list (app_settings k='grades.list', JSON [{key,label}]; editable; defaults below)
 *  - Σ line kg must equal the document net weight (±0.001 kg)
 *  - the document price becomes the weighted average Σ(round(kg×price)) ÷ Σkg (integer), so lot cost / ledger logic is unchanged
 *  - pct_khandan / pct_dombest / pct_crooked of a purchase are auto-filled from line weights (keys khandan / dombest / pook)
 * Lines live in doc_grade_lines (doc_type purchase|sale).
 */
require_once __DIR__ . '/form_settings.php';

const PB_GRADES_DEFAULT = [
    ['key' => 'khandan', 'label' => 'خندان'],
    ['key' => 'dombest', 'label' => 'دنبست'],
    ['key' => 'dahan_basteh', 'label' => 'دهان‌بسته'],
    ['key' => 'kalleh_ghouchi', 'label' => 'کله‌قوچی'],
    ['key' => 'pook', 'label' => 'پوک'],
];

/** Company grade list [{key,label}] (defaults when none saved). */
function pb_grades_list(PDO $pdo, int $cid): array {
    $raw = pb_app_setting_get($pdo, $cid, 'grades.list', '');
    $l = $raw === '' ? null : json_decode($raw, true);
    if (!is_array($l) || !$l) return PB_GRADES_DEFAULT;
    $o = [];
    foreach ($l as $g) if (is_array($g) && isset($g['key'], $g['label'])) $o[] = ['key' => (string)$g['key'], 'label' => (string)$g['label']];
    return $o ?: PB_GRADES_DEFAULT;
}

/** Saves the company grade list: payload [{key,label}], keys [a-z0-9_] ≤ 40, labels ≤ 60, max 30 grades, unique keys. */
function pb_grades_save(PDO $pdo, int $cid, $list): void {
    if (!is_array($list) || !$list || count($list) > 30) bad('فهرست درجه‌ها نامعتبر است');
    $seen = []; $o = [];
    foreach ($list as $g) {
        $k = is_array($g) ? (string)($g['key'] ?? '') : '';
        $lb = is_array($g) ? trim((string)($g['label'] ?? '')) : '';
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $k) || $lb === '' || mb_strlen($lb) > 60 || isset($seen[$k])) bad('درجهٔ نامعتبر یا تکراری');
        $seen[$k] = true; $o[] = ['key' => $k, 'label' => $lb];
    }
    pb_app_setting_set($pdo, $cid, 'grades.list', json_encode($o, JSON_UNESCAPED_UNICODE));
}

/**
 * Validates payload.grade_lines against $net. Returns null (feature off / no lines => single-price flow) or
 * ['lines'=>[[key,kg,price]], 'price'=>int weighted unit price, 'total'=>int Σ line totals].
 */
function pb_grades_read(PDO $pdo, int $cid, array $p, float $net): ?array {
    if (!pb_form_on($pdo, $cid, 'grades')) return null;
    $gl = $p['grade_lines'] ?? null;
    if (!is_array($gl) || !$gl) return null;
    if (count($gl) > 30) bad('تعداد ردیف درجه‌ها بیش از حد است');
    $valid = array_column(pb_grades_list($pdo, $cid), 'key');
    $lines = []; $sumKg = 0.0; $total = 0; $seen = [];
    foreach ($gl as $r) {
        if (!is_array($r)) bad('ردیف درجه نامعتبر است');
        $k = (string)($r['grade_key'] ?? '');
        if (!in_array($k, $valid, true)) bad('درجهٔ نامعتبر: ' . $k);
        if (isset($seen[$k])) bad('یک درجه فقط یک‌بار می‌تواند در سند بیاید');
        $seen[$k] = true;
        if (!is_numeric($r['kg'] ?? null) || !is_numeric($r['unit_price'] ?? null)) bad('وزن یا فی ردیف درجه نامعتبر است');
        $kg = round((float)$r['kg'], 3); $up = (float)$r['unit_price'];
        if ($kg <= 0 || $kg > 1e9) bad('وزن هر درجه باید بزرگ‌تر از صفر باشد');
        if ($up < 0 || $up > 1e13) bad('فی درجه نامعتبر است');
        $up = (int)round($up);
        $lines[] = [$k, $kg, $up]; $sumKg += $kg; $total += (int)round($kg * $up);
    }
    if (abs($sumKg - $net) > PB_WEIGH_TOL) bad('مجموع وزن درجه‌ها باید با وزن خالص سند برابر باشد');
    return ['lines' => $lines, 'price' => (int)round($total / $sumKg), 'total' => $total];
}

/** Replaces the stored lines of a document (inside its transaction); also fills pct_* on purchases. $g null => lines removed only when $clear. */
function pb_grades_store(PDO $pdo, int $cid, string $docType, int $docId, ?array $g, float $net, bool $clear = true): void {
    if ($docType !== 'purchase' && $docType !== 'sale') return;
    if ($g === null && !$clear) return;
    $pdo->prepare('DELETE FROM doc_grade_lines WHERE customer_id=? AND doc_type=? AND doc_id=?')->execute([$cid, $docType, $docId]);
    if ($g === null) return;
    $ins = $pdo->prepare('INSERT INTO doc_grade_lines (customer_id, doc_type, doc_id, grade_key, kg, unit_price) VALUES (?,?,?,?,?,?)');
    $pct = [];
    foreach ($g['lines'] as [$k, $kg, $up]) {
        $ins->execute([$cid, $docType, $docId, $k, $kg, $up]);
        if ($k === 'khandan') $pct['pct_khandan'] = round($kg / $net * 100, 2);
        if ($k === 'dombest') $pct['pct_dombest'] = round($kg / $net * 100, 2);
        if ($k === 'pook') $pct['pct_crooked'] = round($kg / $net * 100, 2);
    }
    if ($docType === 'purchase' && $pct) {
        foreach ($pct as $col => $v) $pdo->prepare("UPDATE purchases SET `$col`=? WHERE id=? AND customer_id=?")->execute([$v, $docId, $cid]);
    }
}

/** Lines of a document for loaders/PDF: [{grade_key,label,kg,unit_price,total}]. */
function pb_grades_of(PDO $pdo, int $cid, string $docType, int $docId): array {
    $st = $pdo->prepare('SELECT grade_key, kg, unit_price FROM doc_grade_lines WHERE customer_id=? AND doc_type=? AND doc_id=? ORDER BY id');
    $st->execute([$cid, $docType, $docId]);
    $lab = []; foreach (pb_grades_list($pdo, $cid) as $g) $lab[$g['key']] = $g['label'];
    $o = [];
    foreach ($st->fetchAll() as $r) {
        $o[] = ['grade_key' => $r['grade_key'], 'label' => $lab[$r['grade_key']] ?? $r['grade_key'], 'kg' => (float)$r['kg'],
                'unit_price' => (int)$r['unit_price'], 'total' => (int)round((float)$r['kg'] * (int)$r['unit_price'])];
    }
    return $o;
}
