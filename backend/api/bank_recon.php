<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S31.T31.1 — bank reconciliation (GOLD). Import a bank statement (CSV), match its lines against voucher lines of the bank
 * account's tafsili (kind 'bank', ref_key = ent_bank_accounts.uid), mark differences.
 * Sign convention (OUR books): amount > 0 = deposit (bank debit in our ledger), amount < 0 = withdrawal. Integer rial (voucher unit).
 * CSV header (any order; Persian or English): date|تاریخ, description|شرح, debit|withdrawal|برداشت|بدهکار (withdrawal),
 *   credit|deposit|واریز|بستانکار (deposit) OR amount|مبلغ (signed), ref|مرجع|شماره پیگیری. Dates: Jalali or Gregorian.
 * Line status: open | matched (amount equal) | diff (manually matched, amount differs; diff = statement - book) | ignored.
 * Re-importing the same file adds nothing (line_hash = date+amount+description+ref+occurrence index within the file).
 * Actions: bank_recon.list|report (accounting.view); bank_recon.import|auto|match|unmatch|ignore (accounting.post).
 */
require_once __DIR__ . '/jdate.php';

const PB_BR_MAX_LINES = 5000;
const PB_BR_MAX_BYTES = 2097152;

function pb_bank_recon_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS bank_stmt_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        bank_uid VARCHAR(40) NOT NULL,
        line_date DATE NOT NULL,
        amount BIGINT NOT NULL,
        description VARCHAR(300) NULL,
        ref VARCHAR(60) NULL,
        line_hash CHAR(40) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        voucher_line_id INT UNSIGNED NULL,
        diff BIGINT NOT NULL DEFAULT 0,
        note VARCHAR(200) NULL,
        created_at DATETIME NULL,
        matched_at DATETIME NULL,
        UNIQUE KEY uq_bsl_hash (customer_id, bank_uid, line_hash),
        KEY idx_bsl_bank (customer_id, bank_uid, status, line_date),
        KEY idx_bsl_vl (customer_id, voucher_line_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Persian/Arabic digits -> ASCII; strips thousands separators. */
function pb_br_digits(string $s): string {
    return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => '', '،' => '']);
}

function pb_br_amount(string $s): ?int {
    $s = trim(pb_br_digits($s));
    if ($s === '' || $s === '-') return 0;
    $neg = false;
    if ($s[0] === '(' && substr($s, -1) === ')') { $neg = true; $s = substr($s, 1, -1); }
    $s = str_replace([',', ' '], '', $s);
    if ($s !== '' && $s[0] === '-') { $neg = !$neg; $s = substr($s, 1); }
    if (!preg_match('/^\d{1,16}(\.0+)?$/', $s)) return null;
    $v = (int)$s;
    return $neg ? -$v : $v;
}

/** 'YYYY-MM-DD' (Gregorian) from a Jalali or Gregorian text; null when invalid. */
function pb_br_date(string $s): ?string {
    $s = trim(pb_br_digits($s));
    if (!preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $s, $m)) return null;
    [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
    if ($y < 1700) return pb_j2g_str($y, $mo, $d);
    if (!checkdate($mo, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

function pb_br_col(string $h): ?string {
    $h = mb_strtolower(trim(pb_br_digits($h)));
    $map = [
        'date' => ['date', 'تاریخ'],
        'desc' => ['description', 'desc', 'شرح', 'توضیحات', 'بابت'],
        'out' => ['debit', 'withdrawal', 'برداشت', 'بدهکار'],
        'in' => ['credit', 'deposit', 'واریز', 'بستانکار'],
        'amount' => ['amount', 'مبلغ'],
        'ref' => ['ref', 'reference', 'مرجع', 'شماره پیگیری', 'پیگیری'],
    ];
    foreach ($map as $k => $names) if (in_array($h, $names, true)) return $k;
    return null;
}

/** Bank account uid + its tafsili id (tenant-scoped); bad() when unknown. */
function pb_br_bank(PDO $pdo, int $cid, $uid): array {
    $uid = trim((string)$uid);
    if ($uid === '') bad('حساب بانکی انتخاب نشده است');
    $st = $pdo->prepare('SELECT uid, name FROM ent_bank_accounts WHERE customer_id=? AND uid=? AND deleted_at IS NULL');
    $st->execute([$cid, $uid]);
    $b = $st->fetch();
    if (!$b) bad('حساب بانکی یافت نشد', 404);
    $st = $pdo->prepare("SELECT id FROM tafsili WHERE customer_id=? AND kind='bank' AND ref_key=?");
    $st->execute([$cid, $uid]);
    $t = $st->fetchColumn();
    return ['uid' => $uid, 'name' => (string)$b['name'], 'tafsili_id' => $t === false ? 0 : (int)$t];
}

function pb_br_parse(string $csv): array {
    if ($csv === '' || strlen($csv) > PB_BR_MAX_BYTES) bad('فایل صورت‌حساب خالی یا بیش از حد بزرگ است');
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $lines = preg_split('/\r\n|\n|\r/', $csv);
    $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
    if (count($lines) < 2) bad('صورت‌حساب باید سطر عنوان و حداقل یک ردیف داشته باشد');
    if (count($lines) - 1 > PB_BR_MAX_LINES) bad('حداکثر ' . PB_BR_MAX_LINES . ' ردیف در هر ورود مجاز است');
    $best = ','; $bn = -1;
    foreach ([',', ';', "\t"] as $d) { $n = count(str_getcsv($lines[0], $d)); if ($n > $bn) { $bn = $n; $best = $d; } }
    $cols = [];
    foreach (str_getcsv($lines[0], $best) as $i => $h) { $k = pb_br_col((string)$h); if ($k !== null && !isset($cols[$k])) $cols[$k] = $i; }
    if (!isset($cols['date'])) bad('ستون تاریخ در عنوان‌ها یافت نشد');
    if (!isset($cols['amount']) && !isset($cols['in']) && !isset($cols['out'])) bad('ستون مبلغ (یا واریز/برداشت) یافت نشد');
    $rows = []; $errors = [];
    for ($i = 1; $i < count($lines); $i++) {
        $c = str_getcsv($lines[$i], $best);
        $date = pb_br_date((string)($c[$cols['date']] ?? ''));
        if ($date === null) { $errors[] = ['row' => $i + 1, 'error' => 'تاریخ نامعتبر']; continue; }
        if (isset($cols['amount'])) {
            $amt = pb_br_amount((string)($c[$cols['amount']] ?? ''));
        } else {
            $in = pb_br_amount((string)($c[$cols['in'] ?? -1] ?? '')); $out = pb_br_amount((string)($c[$cols['out'] ?? -1] ?? ''));
            $amt = ($in === null || $out === null) ? null : abs($in) - abs($out);
        }
        if ($amt === null) { $errors[] = ['row' => $i + 1, 'error' => 'مبلغ نامعتبر']; continue; }
        if ($amt === 0) { $errors[] = ['row' => $i + 1, 'error' => 'مبلغ صفر']; continue; }
        $rows[] = ['date' => $date, 'amount' => $amt, 'desc' => (string)mb_substr(trim((string)($c[$cols['desc'] ?? -1] ?? '')), 0, 300),
            'ref' => (string)mb_substr(trim((string)($c[$cols['ref'] ?? -1] ?? '')), 0, 60)];
    }
    return [$rows, $errors];
}

function pb_br_import(PDO $pdo, int $cid, array $p): array {
    $bank = pb_br_bank($pdo, $cid, $p['bank_uid'] ?? '');
    [$rows, $errors] = pb_br_parse((string)($p['csv'] ?? ''));
    $seen = []; $added = 0; $dups = 0;
    $ins = $pdo->prepare('INSERT IGNORE INTO bank_stmt_lines (customer_id, bank_uid, line_date, amount, description, ref, line_hash, status, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $base = $r['date'] . '|' . $r['amount'] . '|' . $r['desc'] . '|' . $r['ref'];
        $seen[$base] = ($seen[$base] ?? 0) + 1;
        $h = sha1($base . '|' . $seen[$base]);
        $ins->execute([$cid, $bank['uid'], $r['date'], $r['amount'], $r['desc'] === '' ? null : $r['desc'], $r['ref'] === '' ? null : $r['ref'], $h, 'open']);
        if ($ins->rowCount() > 0) $added++; else $dups++;
    }
    $pdo->commit();
    return ['added' => $added, 'duplicates' => $dups, 'errors' => $errors];
}

/** Voucher line usable for matching: tenant-scoped, on the bank's tafsili, not draft. */
function pb_br_vline(PDO $pdo, int $cid, int $tafsiliId, int $vlId): ?array {
    $st = $pdo->prepare("SELECT vl.id, v.voucher_date AS date, v.number, v.status, (vl.debit - vl.credit) AS net, COALESCE(vl.description, v.description) AS description
        FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id AND v.customer_id = vl.customer_id
        WHERE vl.id=? AND vl.customer_id=? AND vl.tafsili_id=? AND v.status<>'draft'");
    $st->execute([$vlId, $cid, $tafsiliId]);
    $r = $st->fetch();
    return $r ?: null;
}

function pb_br_auto(PDO $pdo, int $cid, array $p): array {
    $bank = pb_br_bank($pdo, $cid, $p['bank_uid'] ?? '');
    if ($bank['tafsili_id'] <= 0) return ['matched' => 0, 'ambiguous' => 0, 'open' => 0];
    $days = isset($p['days']) && is_numeric($p['days']) ? max(0, min(30, (int)$p['days'])) : 3;
    $open = $pdo->prepare("SELECT id, line_date, amount FROM bank_stmt_lines WHERE customer_id=? AND bank_uid=? AND status='open' ORDER BY line_date, id");
    $open->execute([$cid, $bank['uid']]);
    $lines = $open->fetchAll();
    $cand = $pdo->prepare("SELECT vl.id, ABS(DATEDIFF(v.voucher_date, ?)) AS dd
        FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id AND v.customer_id = vl.customer_id
        WHERE vl.customer_id=? AND vl.tafsili_id=? AND v.status<>'draft' AND (vl.debit - vl.credit) = ?
          AND v.voucher_date BETWEEN DATE_SUB(?, INTERVAL $days DAY) AND DATE_ADD(?, INTERVAL $days DAY)
          AND vl.id NOT IN (SELECT voucher_line_id FROM bank_stmt_lines WHERE customer_id=? AND voucher_line_id IS NOT NULL)
        ORDER BY dd, vl.id LIMIT 2");
    $upd = $pdo->prepare("UPDATE bank_stmt_lines SET status='matched', voucher_line_id=?, diff=0, matched_at=NOW() WHERE id=? AND customer_id=? AND status='open'");
    $m = 0; $amb = 0;
    $pdo->beginTransaction();
    foreach ($lines as $l) {
        $cand->execute([$l['line_date'], $cid, $bank['tafsili_id'], (int)$l['amount'], $l['line_date'], $l['line_date'], $cid]);
        $c = $cand->fetchAll();
        if (!$c) continue;
        if (count($c) > 1 && (int)$c[0]['dd'] === (int)$c[1]['dd']) { $amb++; continue; } // tie => leave to the human
        $upd->execute([(int)$c[0]['id'], (int)$l['id'], $cid]);
        $m += $upd->rowCount();
    }
    $pdo->commit();
    return ['matched' => $m, 'ambiguous' => $amb, 'open' => count($lines) - $m];
}

function pb_br_match(PDO $pdo, int $cid, array $p): array {
    $bank = pb_br_bank($pdo, $cid, $p['bank_uid'] ?? '');
    $id = (int)($p['id'] ?? 0); $vl = (int)($p['voucher_line_id'] ?? 0);
    $st = $pdo->prepare('SELECT id, amount, status FROM bank_stmt_lines WHERE id=? AND customer_id=? AND bank_uid=?');
    $st->execute([$id, $cid, $bank['uid']]);
    $l = $st->fetch();
    if (!$l) bad('ردیف صورت‌حساب یافت نشد', 404);
    $v = pb_br_vline($pdo, $cid, $bank['tafsili_id'], $vl);
    if (!$v) bad('ردیف سند برای این حساب بانکی یافت نشد', 404);
    $used = $pdo->prepare('SELECT COUNT(*) FROM bank_stmt_lines WHERE customer_id=? AND voucher_line_id=? AND id<>?');
    $used->execute([$cid, $vl, $id]);
    if ((int)$used->fetchColumn() > 0) bad('این ردیف سند قبلاً با ردیف دیگری تطبیق داده شده است');
    $diff = (int)$l['amount'] - (int)$v['net'];
    $pdo->prepare('UPDATE bank_stmt_lines SET status=?, voucher_line_id=?, diff=?, matched_at=NOW() WHERE id=? AND customer_id=?')
        ->execute([$diff === 0 ? 'matched' : 'diff', $vl, $diff, $id, $cid]);
    return ['id' => $id, 'status' => $diff === 0 ? 'matched' : 'diff', 'diff' => $diff];
}

function pb_br_unmatch(PDO $pdo, int $cid, array $p): array {
    $st = $pdo->prepare("UPDATE bank_stmt_lines SET status='open', voucher_line_id=NULL, diff=0, matched_at=NULL WHERE id=? AND customer_id=? AND status IN ('matched','diff','ignored')");
    $st->execute([(int)($p['id'] ?? 0), $cid]);
    if ($st->rowCount() < 1) bad('ردیف یافت نشد', 404);
    return ['id' => (int)$p['id'], 'status' => 'open'];
}

function pb_br_ignore(PDO $pdo, int $cid, array $p): array {
    $note = str_or_null($p['note'] ?? null, 200);
    $st = $pdo->prepare("UPDATE bank_stmt_lines SET status='ignored', voucher_line_id=NULL, diff=0, note=?, matched_at=NOW() WHERE id=? AND customer_id=? AND status='open'");
    $st->execute([$note, (int)($p['id'] ?? 0), $cid]);
    if ($st->rowCount() < 1) bad('ردیف باز یافت نشد', 404);
    return ['id' => (int)$p['id'], 'status' => 'ignored'];
}

function pb_br_list(PDO $pdo, int $cid, array $p): array {
    $bank = pb_br_bank($pdo, $cid, $p['bank_uid'] ?? '');
    $status = (string)($p['status'] ?? '');
    $where = ''; $args = [$cid, $bank['uid']];
    if (in_array($status, ['open', 'matched', 'diff', 'ignored'], true)) { $where = ' AND status=?'; $args[] = $status; }
    $lim = isset($p['limit']) && is_numeric($p['limit']) ? max(1, min(500, (int)$p['limit'])) : 200;
    $off = isset($p['offset']) && is_numeric($p['offset']) ? max(0, (int)$p['offset']) : 0;
    $st = $pdo->prepare("SELECT id, line_date AS date, amount, description, ref, status, voucher_line_id, diff, note FROM bank_stmt_lines WHERE customer_id=? AND bank_uid=? $where ORDER BY line_date DESC, id DESC LIMIT $lim OFFSET $off");
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['amount'] = (int)$r['amount']; $r['diff'] = (int)$r['diff']; $r['voucher_line_id'] = $r['voucher_line_id'] === null ? null : (int)$r['voucher_line_id']; }
    unset($r);
    return ['bank' => ['uid' => $bank['uid'], 'name' => $bank['name']], 'lines' => $rows];
}

/** Book balance vs statement balance and the items that explain the gap. Optional p.to = 'YYYY-MM-DD'. */
function pb_br_report(PDO $pdo, int $cid, array $p): array {
    $bank = pb_br_bank($pdo, $cid, $p['bank_uid'] ?? '');
    $to = isset($p['to']) ? pb_br_date((string)$p['to']) : null;
    $tf = $bank['tafsili_id'];
    $bookWhere = "vl.customer_id=? AND vl.tafsili_id=? AND v.status<>'draft'" . ($to ? ' AND v.voucher_date<=?' : '');
    $bookArgs = $to ? [$cid, $tf, $to] : [$cid, $tf];
    $q = $pdo->prepare("SELECT COALESCE(SUM(vl.debit - vl.credit),0) FROM voucher_lines vl JOIN vouchers v ON v.id=vl.voucher_id AND v.customer_id=vl.customer_id WHERE $bookWhere");
    $q->execute($bookArgs);
    $book = (int)$q->fetchColumn();
    $sw = $to ? ' AND line_date<=?' : '';
    $sa = $to ? [$cid, $bank['uid'], $to] : [$cid, $bank['uid']];
    $q = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) s, COALESCE(SUM(diff),0) d FROM bank_stmt_lines WHERE customer_id=? AND bank_uid=? $sw GROUP BY status");
    $q->execute($sa);
    $by = ['open' => [0, 0], 'matched' => [0, 0], 'diff' => [0, 0], 'ignored' => [0, 0]]; $diffSum = 0;
    foreach ($q->fetchAll() as $r) { $by[$r['status']] = [(int)$r['n'], (int)$r['s']]; if ($r['status'] === 'diff') $diffSum = (int)$r['d']; }
    $stmt = 0; foreach ($by as $v) $stmt += $v[1];
    $q = $pdo->prepare("SELECT vl.id, v.voucher_date AS date, v.number, (vl.debit - vl.credit) AS net, COALESCE(vl.description, v.description) AS description
        FROM voucher_lines vl JOIN vouchers v ON v.id=vl.voucher_id AND v.customer_id=vl.customer_id
        WHERE $bookWhere AND vl.id NOT IN (SELECT voucher_line_id FROM bank_stmt_lines WHERE customer_id=? AND voucher_line_id IS NOT NULL)
        ORDER BY v.voucher_date DESC, vl.id DESC LIMIT 200");
    $q->execute(array_merge($bookArgs, [$cid]));
    $unBook = $q->fetchAll(); $unBookSum = 0;
    foreach ($unBook as &$r) { $r['id'] = (int)$r['id']; $r['net'] = (int)$r['net']; $r['number'] = $r['number'] === null ? null : (int)$r['number']; $unBookSum += $r['net']; }
    unset($r);
    $q = $pdo->prepare("SELECT COALESCE(SUM(vl.debit - vl.credit),0) FROM voucher_lines vl JOIN vouchers v ON v.id=vl.voucher_id AND v.customer_id=vl.customer_id
        WHERE $bookWhere AND vl.id NOT IN (SELECT voucher_line_id FROM bank_stmt_lines WHERE customer_id=? AND voucher_line_id IS NOT NULL)");
    $q->execute(array_merge($bookArgs, [$cid]));
    $unBookTotal = (int)$q->fetchColumn(); // full sum (the list above is capped)
    return [
        'bank' => ['uid' => $bank['uid'], 'name' => $bank['name'], 'has_tafsili' => $tf > 0],
        'book_balance' => $book, 'statement_balance' => $stmt, 'difference' => $stmt - $book,
        'counts' => ['open' => $by['open'][0], 'matched' => $by['matched'][0], 'diff' => $by['diff'][0], 'ignored' => $by['ignored'][0]],
        'open_statement_total' => $by['open'][1], 'unmatched_book_total' => $unBookTotal, 'diff_total' => $diffSum,
        'unmatched_book' => $unBook,
    ];
}

function pb_bank_recon_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    $cid = (int)$customer['id'];
    pb_fy_seed($pdo, $cid);
    pb_acc_ensure($pdo);
    pb_voucher_ensure($pdo);
    pb_bank_recon_ensure($pdo);
    switch ($action) {
        case 'bank_recon.list': out(pb_br_list($pdo, $cid, $p)); break;
        case 'bank_recon.report': out(pb_br_report($pdo, $cid, $p)); break;
        case 'bank_recon.import': out(pb_br_import($pdo, $cid, $p)); break;
        case 'bank_recon.auto': out(pb_br_auto($pdo, $cid, $p)); break;
        case 'bank_recon.match': out(pb_br_match($pdo, $cid, $p)); break;
        case 'bank_recon.unmatch': out(pb_br_unmatch($pdo, $cid, $p)); break;
        case 'bank_recon.ignore': out(pb_br_ignore($pdo, $cid, $p)); break;
    }
    bad('عملیات نامعتبر', 404);
}
