<?php
declare(strict_types=1);
/**
 * S16.T16.2 — posting engine (GOLD). Generic interpreter of posting_rules.json; rules are DATA (DECISIONS B12).
 * Same JSON is interpreted by tests/oracle.py. Keep semantics identical (see _doc in the JSON).
 * - pb_post_document($pdo,$customer,$type,$id): idempotent; replaces lines of the existing auto voucher (number + status kept).
 * - pb_unpost_document(): removes the auto voucher (audit-logged).  - pb_post_hook()/pb_unpost_hook(): no-op for green / unknown type.
 * Hooks must be called INSIDE the document's DB transaction (pb_voucher_write joins it); failures exit via bad() and roll it back.
 * Loaders are registered per doc type by later tasks: pb_posting_register_loader($type, callable(PDO,int $cid,int $id): ?array).
 */
require_once __DIR__ . '/vouchers.php';

function pb_posting_rules(): array {
    static $rules = null;
    if ($rules === null) {
        $j = json_decode((string)file_get_contents(__DIR__ . '/posting_rules.json'), true);
        if (!is_array($j) || !isset($j['rules']) || !is_array($j['rules'])) {
            error_log('[posting] posting_rules.json invalid');
            $j = ['rules' => [], 'round_tolerance' => 0, 'auto_status' => 'temporary'];
        }
        $rules = $j;
    }
    return $rules;
}

/** @param callable|null $fn when given, registers; returns the registry. */
function pb_posting_loaders(string $type = '', $fn = null): array {
    static $reg = [];
    if ($type !== '' && $fn !== null) $reg[$type] = $fn;
    return $reg;
}

function pb_posting_register_loader(string $type, callable $fn): void { pb_posting_loaders($type, $fn); }

/** Overflow-checked integer multiply. */
function pb_posting_mul(int $a, int $b): int {
    $r = $a * $b; // PHP promotes to float on overflow
    if (!is_int($r)) bad('خطا در قانون سندزنی: سرریز عدد', 500);
    return $r;
}

function pb_posting_divround(int $a, int $b): int {
    if ($b === 0) bad('خطا در قانون سندزنی: تقسیم بر صفر', 500);
    if ($a < 0 || $b < 0) bad('خطا در قانون سندزنی: مقدار منفی در تقسیم', 500);
    return intdiv($a + intdiv($b, 2), $b);
}

/** @param mixed $e expression @param array $f fields */
function pb_posting_eval($e, array $f): int {
    if (is_int($e)) return $e;
    if (is_string($e)) {
        if ($e === '' || $e[0] !== '@') bad('خطا در قانون سندزنی: عبارت نامعتبر', 500);
        $v = $f[substr($e, 1)] ?? 0;
        if (!is_int($v)) bad('خطا در قانون سندزنی: فیلد غیرصحیح ' . substr($e, 1), 500);
        return $v;
    }
    if (!is_array($e) || !isset($e['op'], $e['args']) || !is_array($e['args'])) bad('خطا در قانون سندزنی: عبارت نامعتبر', 500);
    $op = (string)$e['op'];
    $a = $e['args'];
    if ($op === 'if') {
        if (count($a) !== 3) bad('خطا در قانون سندزنی: if', 500);
        return pb_posting_eval($a[0], $f) !== 0 ? pb_posting_eval($a[1], $f) : pb_posting_eval($a[2], $f);
    }
    $v = [];
    foreach ($a as $x) $v[] = pb_posting_eval($x, $f);
    $n = count($v);
    switch ($op) {
        case 'add': return array_sum($v);
        case 'sub': if ($n !== 2) break; return $v[0] - $v[1];
        case 'mul': if ($n < 2) break; $r = 1; foreach ($v as $x) $r = pb_posting_mul($r, $x); return $r;
        case 'max': if ($n < 1) break; return max($v);
        case 'min': if ($n < 1) break; return min($v);
        case 'pos': if ($n !== 1) break; return max(0, $v[0]);
        case 'divround': if ($n !== 2) break; return pb_posting_divround($v[0], $v[1]);
        case 'muldiv': if ($n !== 3) break; return pb_posting_divround(pb_posting_mul($v[0], $v[1]), $v[2]);
        case 'pct': if ($n !== 2) break; return pb_posting_divround(pb_posting_mul($v[0], $v[1]), 100000);
        case 'gt': if ($n !== 2) break; return $v[0] > $v[1] ? 1 : 0;
        case 'ge': if ($n !== 2) break; return $v[0] >= $v[1] ? 1 : 0;
        case 'lt': if ($n !== 2) break; return $v[0] < $v[1] ? 1 : 0;
        case 'le': if ($n !== 2) break; return $v[0] <= $v[1] ? 1 : 0;
        case 'eq': if ($n !== 2) break; return $v[0] === $v[1] ? 1 : 0;
        case 'ne': if ($n !== 2) break; return $v[0] !== $v[1] ? 1 : 0;
        case 'and': foreach ($v as $x) if ($x === 0) return 0; return 1;
        case 'or': foreach ($v as $x) if ($x !== 0) return 1; return 0;
        case 'not': if ($n !== 1) break; return $v[0] === 0 ? 1 : 0;
    }
    bad('خطا در قانون سندزنی: عملگر ' . $op, 500);
}

/**
 * Rule evaluation -> normalized lines [{role, debit, credit, tafsili_key, cc_key, desc, acct_dim, tafsili_id? (only for each-lines)}] (zero lines dropped, B1 rounding applied).
 * Pure function of (rules, docType, ctx): mirrored by oracle.py.
 */
function pb_posting_build(array $rules, string $type, array $ctx): array {
    $f = is_array($ctx['fields'] ?? null) ? $ctx['fields'] : [];
    $lines = [];
    foreach ($rules['rules'] as $rule) {
        if (($rule['doc_type'] ?? '') !== $type) continue;
        if (array_key_exists('when', $rule) && pb_posting_eval($rule['when'], $f) === 0) continue;
        foreach ((array)($rule['lines'] ?? []) as $ln) {
            // fx-reval step 3: line {"each":"groups"} is emitted once per ctx['groups'] member ({fields, dims, desc?}); when/debit/credit use THAT group's fields,
            // tafsili comes from THAT group's dims (stored as direct tafsili_id). Missing/empty groups => no line. Lines without "each" are unchanged.
            $each = ($ln['each'] ?? null) === 'groups';
            $scopes = $each ? (is_array($ctx['groups'] ?? null) ? array_values($ctx['groups']) : []) : [null];
            foreach ($scopes as $g) {
                $gf = $each ? (is_array($g['fields'] ?? null) ? $g['fields'] : []) : $f;
                if (array_key_exists('when', $ln) && pb_posting_eval($ln['when'], $gf) === 0) continue;
                $d = array_key_exists('debit', $ln) ? pb_posting_eval($ln['debit'], $gf) : 0;
                $c = array_key_exists('credit', $ln) ? pb_posting_eval($ln['credit'], $gf) : 0;
                if ($d < 0 || $c < 0) bad('خطا در قانون سندزنی: مبلغ منفی در قانون ' . ($rule['id'] ?? ''), 500);
                if ($d > 0 && $c > 0) bad('خطا در قانون سندزنی: ردیف دوطرفه در قانون ' . ($rule['id'] ?? ''), 500);
                if ($d === 0 && $c === 0) continue;
                $row = ['role' => (string)($ln['account'] ?? ''), 'debit' => $d, 'credit' => $c,
                    'tafsili_key' => $ln['tafsili'] ?? null, 'cc_key' => $ln['cost_center'] ?? null, 'desc' => (string)($ln['desc'] ?? ''), 'acct_dim' => $ln['account_dim'] ?? null];
                if ($each) {
                    $gd = is_array($g['dims'] ?? null) ? $g['dims'] : [];
                    $row['tafsili_id'] = isset($ln['tafsili']) ? (int)($gd[$ln['tafsili']] ?? 0) : 0;
                    $row['tafsili_key'] = null;
                    if (isset($g['desc']) && (string)$g['desc'] !== '') $row['desc'] = (string)$g['desc'];
                }
                $lines[] = $row;
            }
        }
    }
    if (!$lines) return [];
    $sd = 0;
    $sc = 0;
    foreach ($lines as $l) { $sd += $l['debit']; $sc += $l['credit']; }
    $diff = $sd - $sc;
    if ($diff !== 0) {
        if (abs($diff) > (int)($rules['round_tolerance'] ?? 0)) bad('خطا در قانون سندزنی: سند تراز نیست (اختلاف ' . $diff . ')', 500);
        $side = $diff > 0 ? 'credit' : 'debit'; // deficient side gets the remainder
        $best = -1;
        foreach ($lines as $i => $l) if ($l[$side] > 0 && ($best < 0 || $l[$side] > $lines[$best][$side])) $best = $i;
        if ($best < 0) bad('خطا در قانون سندزنی: ردیف برای تراز کردن یافت نشد', 500);
        $lines[$best][$side] += abs($diff);
    }
    return $lines;
}

function pb_posting_find(PDO $pdo, int $cid, string $type, string $id): ?array {
    $st = $pdo->prepare("SELECT * FROM vouchers WHERE customer_id=? AND source_type=? AND source_id=? LIMIT 1");
    $st->execute([$cid, $type, $id]);
    $r = $st->fetch();
    return $r ?: null;
}

function pb_posting_has_type(string $type): bool {
    foreach (pb_posting_rules()['rules'] as $r) if (($r['doc_type'] ?? '') === $type) return true;
    return false;
}

/** S20.T20.1: built lines (roles) -> voucher lines (account ids, tafsili, cost center). Shared by pb_post_document and the upgrade history summary. */
function pb_posting_resolve(PDO $pdo, int $cid, array $built, array $ctx): array {
    $dims = is_array($ctx['dims'] ?? null) ? $ctx['dims'] : [];
    $need = [];
    foreach ($built as $l) if ($l['acct_dim'] === null || (int)($dims[$l['acct_dim']] ?? 0) <= 0) $need[] = $l['role']; // T16.4: account chosen by the document
    $miss = pb_acc_roles_missing($pdo, $cid, array_values(array_unique($need)));
    if ($miss) bad('حساب نقش‌های زیر تنظیم نشده است: ' . implode('، ', $miss), 409);
    $st = $pdo->prepare('SELECT role, account_id FROM account_roles WHERE customer_id=?');
    $st->execute([$cid]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[(string)$r['role']] = (int)$r['account_id'];
    $cc = is_array($ctx['ccy'] ?? null) ? $ctx['ccy'] : null; // T30.3d: ['code','amount'(foreign),'rial','roles'[]] => proportional ccy stamp on those roles
    $lines = [];
    foreach ($built as $l) {
        $stamp = [];
        $val = (int)$l['debit'] + (int)$l['credit'];
        if ($cc && $val > 0 && (float)($cc['amount'] ?? 0) > 0 && (int)($cc['rial'] ?? 0) > 0 && in_array($l['role'], (array)($cc['roles'] ?? []), true)) {
            $rl = (int)(((array)($cc['rial_by_role'] ?? []))[$l['role']] ?? 0); // T30.3e: settled rial of this role (settlement rate)
            if ($rl <= 0) $rl = (int)$cc['rial'];
            $stamp = ['ccy' => (string)$cc['code'], 'ccy_amount' => round((float)$cc['amount'] * $val / $rl, 4), 'rate' => round($rl / (float)$cc['amount'], 6)];
        }
        $lines[] = $stamp + [
            'account_id' => ($l['acct_dim'] !== null && (int)($dims[$l['acct_dim']] ?? 0) > 0) ? (int)$dims[$l['acct_dim']] : $map[$l['role']],
            'tafsili_id' => array_key_exists('tafsili_id', $l) ? (int)$l['tafsili_id'] : ($l['tafsili_key'] !== null ? (int)($dims[$l['tafsili_key']] ?? 0) : 0), // each-lines carry a direct id
            'cost_center_id' => $l['cc_key'] !== null ? (int)($dims[$l['cc_key']] ?? 0) : 0,
            'debit' => $l['debit'], 'credit' => $l['credit'], 'description' => $l['desc'],
        ];
    }
    return $lines;
}

/** Posts (or re-posts) the document. Returns voucher id, or 0 when nothing to post / not applicable. */
function pb_post_document(PDO $pdo, array $customer, string $type, $docId): int {
    if (!pb_fy_is_gold($customer)) return 0;
    $reg = pb_posting_loaders();
    if (!isset($reg[$type]) || !pb_posting_has_type($type)) return 0;
    $cid = (int)$customer['id'];
    $sid = (string)$docId;
    pb_voucher_ensure($pdo);
    $ctx = $reg[$type]($pdo, $cid, is_numeric($docId) ? (int)$docId : 0);
    if ($ctx === null) { pb_unpost_document($pdo, $customer, $type, $docId); return 0; }
    $rules = pb_posting_rules();
    $built = pb_posting_build($rules, $type, $ctx);
    if (!$built) { pb_unpost_document($pdo, $customer, $type, $docId); return 0; }
    $lines = pb_posting_resolve($pdo, $cid, $built, $ctx);
    $old = pb_posting_find($pdo, $cid, $type, $sid);
    $status = $old ? (string)$old['status'] : (in_array($rules['auto_status'] ?? '', PB_VOUCHER_STATUSES, true) ? (string)$rules['auto_status'] : 'temporary');
    return pb_voucher_write($pdo, $customer, [
        'date' => (string)($ctx['date'] ?? ''), 'description' => (string)($ctx['description'] ?? ''), 'status' => $status,
        'source_type' => $type, 'source_id' => $sid,
    ], $lines, $old ? (int)$old['id'] : 0);
}

/** Removes the auto voucher of a document (audit-logged). Numbering gap is accepted (see DECISIONS_TAKEN). */
function pb_unpost_document(PDO $pdo, array $customer, string $type, $docId): bool {
    if (!pb_fy_is_gold($customer)) return false;
    $cid = (int)$customer['id'];
    pb_voucher_ensure($pdo);
    $old = pb_posting_find($pdo, $cid, $type, (string)$docId);
    if (!$old) return false;
    pb_fy_guard($pdo, $cid, $customer, (string)$old['voucher_date']);
    $before = pb_voucher_get($pdo, $cid, $customer, (int)$old['id']);
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM voucher_lines WHERE voucher_id=? AND customer_id=?')->execute([(int)$old['id'], $cid]);
        $pdo->prepare('DELETE FROM vouchers WHERE id=? AND customer_id=?')->execute([(int)$old['id'], $cid]);
        if ($own) $pdo->commit();
    } catch (PDOException $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[posting] ' . $e->getMessage());
        bad('حذف سند خودکار ناموفق بود', 500);
    }
    pb_audit($pdo, $customer, 'voucher.unpost', 'voucher', (string)$old['id'], 'حذف سند خودکار ' . $type . '#' . $docId, $before, null);
    return true;
}

/** Hook points for document actions (no-op for green / types without loader+rules). */
function pb_post_hook(PDO $pdo, array $customer, string $type, $docId): void {
    pb_post_document($pdo, $customer, $type, $docId);
    if ($type === 'sale') pb_post_document($pdo, $customer, 'cons_sale', $docId); // S16.T16.5b: consigned-in part of the same sale (null loader => no voucher)
    if ($type === 'sale') pb_post_document($pdo, $customer, 'fx_settle_recv', $docId); // S30.T30.1: fx settlement diff of the same sale (null loader => no voucher)
    if ($type === 'purchase') pb_post_document($pdo, $customer, 'fx_settle_pay', $docId); // S30.T30.1
}
function pb_unpost_hook(PDO $pdo, array $customer, string $type, $docId): void {
    pb_unpost_document($pdo, $customer, $type, $docId);
    if ($type === 'sale') pb_unpost_document($pdo, $customer, 'cons_sale', $docId);
    if ($type === 'sale') pb_unpost_document($pdo, $customer, 'fx_settle_recv', $docId); // S30.T30.1
    if ($type === 'purchase') pb_unpost_document($pdo, $customer, 'fx_settle_pay', $docId); // S30.T30.1
    if ($type === 'sale' || $type === 'purchase') { // T30.3c: rate adjustments of this document go with it
        try {
            $q = $pdo->prepare('SELECT id FROM fx_adjustments WHERE customer_id=? AND doc_type=? AND doc_id=?');
            $q->execute([(int)$customer['id'], $type === 'sale' ? 'sales' : 'purchases', (int)$docId]);
            foreach ($q->fetchAll() as $r) pb_unpost_document($pdo, $customer, 'fx_adjust', (int)$r['id']);
        } catch (Throwable $e) { /* table absent: nothing to unpost */ }
    }
}

require_once __DIR__ . '/posting_loaders_a.php'; // S16.T16.3: loaders for purchase/sale/preturn/sreturn
require_once __DIR__ . '/posting_loaders_b.php'; // S16.T16.4: cheque lifecycle, installments, loans, expenses, broker pay, owner draws
require_once __DIR__ . '/posting_loaders_c1.php'; // S16.T16.5a: separation (sep), processing (proc_send|proc_recv|proc_toll)
require_once __DIR__ . '/posting_loaders_c2.php'; // S16.T16.5b: consignment memo (cons_in)
require_once __DIR__ . '/posting_loaders_c4.php'; // S16.T16.5b2: payroll (payroll|payroll_adv|payroll_pay)
require_once __DIR__ . '/posting_loaders_c3.php'; // S16.T16.5b: orchard costs (orch_cost|orch_alloc)
require_once __DIR__ . '/posting_loaders_c5.php'; // S30.T30.1: fx settlement differences (fx_settle_recv|fx_settle_pay)

function pb_posting_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $type = mb_substr((string)($p['type'] ?? ''), 0, 30);
    $id = (string)($p['id'] ?? '');
    if ($type === '' || $id === '') bad('نوع یا شناسهٔ سند مبدأ ارسال نشده است');
    if ($action === 'posting.post') {
        $vid = pb_post_document($pdo, $customer, $type, $id);
        out(['voucher_id' => $vid]);
    }
    if ($action === 'posting.unpost') out(['removed' => pb_unpost_document($pdo, $customer, $type, $id)]);
    bad('عملیات نامعتبر', 404);
}
