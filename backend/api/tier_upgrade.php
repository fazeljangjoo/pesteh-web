<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S20.T20.1 — Green -> Gold upgrade procedure (owner only).
 *  tier.upgrade_preview  {first_fy_start?, opening_date?}  read-only: stats, issues, estimate.
 *  tier.upgrade_apply    {confirm:1, first_fy_start, opening_date?, vat_enabled?, vat_rate?, vat_levy_rate?, vat_note?, vat_source?}
 *                        resumable + idempotent step machine persisted in `upgrade_runs`:
 *                        snapshot -> tier -> setup (FY, CoA, tafsili) -> opening (ONE voucher, history before FY start) -> backfill (chronological) -> reconcile.
 *                        Returns status running (call again: time-budgeted) | failed (call again: resumes at the failed step) | done (+report).
 *  tier.upgrade_rollback {dry?:1}  removes the DERIVED gold layer (vouchers, CoA, tafsili, fiscal years) and sets tier=green; allowed within
 *                        PB_UG_ROLLBACK_DAYS and only while no gold-only data exists. Operational documents are never touched.
 *  tier.upgrade_status   latest run (steps, report).
 * History = every document dated before the FY start. Its posting lines are built in memory by the same engine (pb_posting_build) and summed
 * per account/tafsili; balance-sheet nets become the opening voucher, profit/loss nets + party master balances are balanced into `retained`.
 */
require_once __DIR__ . '/jdate.php';
require_once __DIR__ . '/fiscal.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/vouchers.php';
require_once __DIR__ . '/posting.php';
require_once __DIR__ . '/accounting_tools.php';

const PB_UG_ROLLBACK_DAYS = 7;       // rollback window after the run finished
const PB_UG_BUDGET_SECONDS = 20;     // backfill time budget per request (resumable)
const PB_UG_STEPS = ['snapshot', 'tier', 'setup', 'opening', 'backfill', 'reconcile'];
const PB_UG_TOLERANCE = 10;          // rial; party/inventory differences up to this are rounding

/** Thrown instead of exiting when out()/bad() is called while $GLOBALS['pb_out_throw'] is set (lets a step isolate one failing document). */
class PbOutError extends RuntimeException {
    public $data;
    public function __construct($data, int $code) {
        $this->data = $data;
        parent::__construct(is_array($data) && isset($data['error']) ? (string)$data['error'] : 'error', $code);
    }
}

/**
 * S22.T22.3b — حق ارتقا به طلایی: سفارش tier_upgrade پرداخت‌شده‌ای که بعد از شروع آخرین ارتقای done/rolled_back ثبت شده باشد.
 * یک خرید = یک ارتقا. ادمین ارتقای دستی هم همین حق را (method=manual) ثبت می‌کند.
 * زمان‌ها از خود MySQL (NOW) گرفته می‌شوند تا منطقهٔ زمانی PHP/MySQL ناسازگار نشود.
 */
function pb_ug_entitled(PDO $pdo, int $cid): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM pay_orders o
        WHERE o.customer_id = ? AND o.order_type = 'tier_upgrade' AND o.status = 'paid' AND o.activated_at IS NOT NULL
          AND o.activated_at > COALESCE((SELECT MAX(r.started_at) FROM upgrade_runs r
                WHERE r.customer_id = ? AND r.status IN ('done','rolled_back')), '1970-01-01 00:00:00')");
    try { $st->execute([$cid, $cid]); return (int)$st->fetchColumn() > 0; }
    catch (PDOException $e) { error_log('pb_ug_entitled: ' . $e->getMessage()); return false; } // جدول پرداخت نصب نیست => حق ندارد
}

function pb_ug_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS upgrade_runs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'running',
        step VARCHAR(16) NOT NULL DEFAULT 'snapshot',
        params TEXT NULL,
        steps MEDIUMTEXT NULL,
        cursor_key VARCHAR(80) NULL,
        snapshot_file VARCHAR(200) NULL,
        fingerprint MEDIUMTEXT NULL,
        report MEDIUMTEXT NULL,
        error VARCHAR(500) NULL,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        KEY idx_ug_c (customer_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_ug_latest(PDO $pdo, int $cid, ?array $statuses = null): ?array {
    pb_ug_ensure($pdo);
    $sql = 'SELECT * FROM upgrade_runs WHERE customer_id=?';
    $args = [$cid];
    if ($statuses) { $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'; $args = array_merge($args, $statuses); }
    $st = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 1');
    $st->execute($args);
    $r = $st->fetch();
    return $r ?: null;
}

/** First-FY start of the live (running|failed|done) upgrade run, or null. Documents before it are history (summarized by the opening voucher). */
function pb_ug_boundary(PDO $pdo, int $cid): ?string {
    $b = null;
    try {
        $st = $pdo->prepare("SELECT params FROM upgrade_runs WHERE customer_id=? AND status IN ('running','failed','done') ORDER BY id DESC LIMIT 1");
        $st->execute([$cid]);
        $j = $st->fetchColumn();
        $p = $j ? json_decode((string)$j, true) : null;
        if (is_array($p) && !empty($p['fy_start'])) $b = (string)$p['fy_start'];
    } catch (Throwable $e) { /* table not created yet */ }
    return $b;
}

function pb_ug_perr(Throwable $e): string {
    return $e->getMessage();
}

/** [date, type, id, ctx] of every postable document of the tenant (unfiltered), sorted chronologically. Loader failures go to $errors. */
function pb_ug_docs(PDO $pdo, int $cid, array &$errors): array {
    $reg = pb_posting_loaders();
    $docs = [];
    foreach (pb_acct_documents($pdo, $cid, true) as $type => $ids) {
        foreach ($ids as $id) {
            try { $ctx = $reg[$type]($pdo, $cid, $id); }
            catch (Throwable $e) { $errors[] = ['type' => $type, 'id' => $id, 'error' => mb_substr(pb_ug_perr($e), 0, 160)]; continue; }
            if ($ctx === null) continue;
            $d = (string)($ctx['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d === '0000-00-00') { $errors[] = ['type' => $type, 'id' => $id, 'error' => 'تاریخ سند نامعتبر است']; continue; }
            $docs[] = [$d, $type, (int)$id, $ctx];
        }
    }
    usort($docs, static fn($a, $b) => [$a[0], $a[2], $a[1]] <=> [$b[0], $b[2], $b[1]]);
    return $docs;
}

function pb_ug_key(string $date, string $type, int $id): string { return $date . '|' . str_pad((string)$id, 12, '0', STR_PAD_LEFT) . '|' . $type; }

function pb_ug_count(PDO $pdo, string $sql, array $args): int {
    try { $st = $pdo->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** Validates + normalizes the wizard parameters. Returns [fy_start, fy_end, opening_date, vat[]]. */
function pb_ug_params(array $p): array {
    $d = (string)($p['first_fy_start'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) bad('تاریخ شروع اولین سال مالی نامعتبر است');
    [$s, $e] = pb_jyear_bounds(pb_jyear_of($d)); // the fiscal year is always the whole Jalali year (DECISIONS A11)
    $od = (string)($p['opening_date'] ?? '');
    if ($od === '') $od = $s;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) || $od < $s || $od > $e) bad('تاریخ سند افتتاحیه باید داخل اولین سال مالی باشد');
    $vat = ['enabled' => !empty($p['vat_enabled']), 'rate' => pb_fy_rate($p['vat_rate'] ?? null, 'نرخ مالیات'), 'levy' => pb_fy_rate($p['vat_levy_rate'] ?? null, 'نرخ عوارض'),
        'note' => mb_substr(trim((string)($p['vat_note'] ?? '')), 0, 500), 'source' => mb_substr(trim((string)($p['vat_source'] ?? '')), 0, 300)];
    if ($vat['enabled'] && $vat['rate'] === null) bad('برای فعال‌سازی مالیات نرخ را وارد کنید (نرخ‌ها داده‌اند و در سیستم ثابت نیستند)');
    return [$s, $e, $od, $vat];
}

function pb_ug_issue(string $code, string $level, int $n, string $detail): array { return ['code' => $code, 'level' => $level, 'count' => $n, 'detail' => $detail]; }

function pb_ug_preview(PDO $pdo, array $customer, array $p): array {
    $cid = (int)$customer['id'];
    $issues = [];
    if (pb_tier_of($customer) === PB_TIER_GOLD) $issues[] = pb_ug_issue('already_gold', 'error', 1, 'این حساب هم‌اکنون طلایی است');
    foreach (['accounts', 'vouchers', 'fiscal_years', 'tafsili'] as $t) {
        $n = pb_ug_count($pdo, "SELECT COUNT(*) FROM `$t` WHERE customer_id=?", [$cid]);
        if ($n > 0 && pb_tier_of($customer) !== PB_TIER_GOLD) $issues[] = pb_ug_issue('gold_data_exists', 'error', $n, "در جدول $t از قبل داده وجود دارد؛ ارتقا انجام نمی‌شود");
    }
    $fyStart = null; $fyEnd = null; $od = null;
    if (!empty($p['first_fy_start'])) { [$fyStart, $fyEnd, $od] = pb_ug_params($p); }
    $errs = [];
    $docs = pb_ug_docs($pdo, $cid, $errs);
    $byType = []; $before = 0; $after = 0; $min = null; $max = null;
    foreach ($docs as [$d, $type]) {
        $byType[$type] = ($byType[$type] ?? 0) + 1;
        if ($fyStart !== null) { if ($d < $fyStart) $before++; else $after++; }
        if ($min === null || $d < $min) $min = $d;
        if ($max === null || $d > $max) $max = $d;
    }
    if ($errs) $issues[] = pb_ug_issue('docs_unloadable', 'warning', count($errs), 'اسنادی که سند حسابداری آن‌ها ساخته نمی‌شود (تاریخ یا داده نامعتبر): ' . implode('، ', array_map(static fn($e) => $e['type'] . '#' . $e['id'], array_slice($errs, 0, 8))));
    $n = pb_ug_count($pdo, "SELECT COUNT(*) FROM parties WHERE customer_id=? AND (types IS NULL OR types='')", [$cid]);
    if ($n > 0) $issues[] = pb_ug_issue('parties_no_type', 'warning', $n, 'طرف‌حساب بدون نوع (خریدار/فروشنده/…)');
    $n = pb_ug_count($pdo, 'SELECT COUNT(*) FROM purchases WHERE customer_id=? AND is_mix=0 AND remaining>0 AND (price IS NULL OR price<=0)', [$cid]);
    if ($n > 0) $issues[] = pb_ug_issue('lots_no_cost', 'warning', $n, 'محموله‌های باقی‌مانده بدون قیمت خرید (ارزش موجودی صفر می‌شود)');
    foreach ([['lump_tx', 'payments', 'payment_id'], ['receipt_lump_tx', 'receipts', 'receipt_id']] as [$tx, $hdr, $fk]) {
        $n = pb_ug_count($pdo, "SELECT COUNT(*) FROM $tx WHERE customer_id=? AND paid=1 AND (treasury_tafsili_id IS NULL OR treasury_tafsili_id=0)", [$cid]);
        if ($n > 0) $issues[] = pb_ug_issue('payments_no_treasury', 'warning', $n, "$tx: پرداخت/دریافت بدون حساب خزانه (صندوق/بانک)");
    }
    $issues[] = pb_ug_issue('opening_cash_not_recorded', 'info', 0, 'مانده نقد و بانک اول دوره در سامانه ثبت نشده است؛ پس از ارتقا با یک سند دستی ثبت شود');
    $errors = count(array_filter($issues, static fn($i) => $i['level'] === 'error'));
    return ['ok' => $errors === 0, 'fy_start' => $fyStart, 'fy_end' => $fyEnd, 'opening_date' => $od,
        'stats' => ['documents' => count($docs), 'before_fy' => $before, 'in_fy' => $after, 'by_type' => $byType, 'first_date' => $min, 'last_date' => $max,
            'parties' => pb_ug_count($pdo, 'SELECT COUNT(*) FROM parties WHERE customer_id=?', [$cid]),
            'open_lots' => pb_ug_count($pdo, 'SELECT COUNT(*) FROM purchases WHERE customer_id=? AND remaining>0', [$cid]),
            'open_cheques' => pb_ug_count($pdo, "SELECT COUNT(*) FROM cheques WHERE customer_id=? AND status IN ('in_hand','deposited','pending')", [$cid])],
        'issues' => $issues, 'estimate_seconds' => (int)ceil(5 + $after * 0.04 + $before * 0.01),
        'rollback_days' => PB_UG_ROLLBACK_DAYS, 'last_run' => ($r = pb_ug_latest($pdo, $cid)) ? pb_ug_run_out($r, false) : null];
}

function pb_ug_run_out(array $r, bool $withReport = true): array {
    $o = ['id' => (int)$r['id'], 'status' => (string)$r['status'], 'step' => (string)$r['step'], 'params' => json_decode((string)$r['params'], true),
        'steps' => json_decode((string)$r['steps'], true) ?: new stdClass(), 'error' => $r['error'], 'started_at' => $r['started_at'], 'finished_at' => $r['finished_at']];
    if ($withReport) $o['report'] = $r['report'] ? json_decode((string)$r['report'], true) : null;
    return $o;
}

function pb_ug_save(PDO $pdo, array &$run, array $set): void {
    $cols = []; $args = [];
    foreach ($set as $k => $v) { $cols[] = "`$k`=?"; $args[] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v; $run[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v; }
    $args[] = (int)$run['id'];
    $pdo->prepare('UPDATE upgrade_runs SET ' . implode(',', $cols) . ' WHERE id=?')->execute($args);
}

/* ---------------- steps ---------------- */

function pb_ug_snapshot(PDO $pdo, array $customer, array &$run): array {
    if (!empty($run['snapshot_file']) && is_file((string)$run['snapshot_file'])) return ['file' => basename((string)$run['snapshot_file']), 'reused' => true];
    $cid = (int)$customer['id'];
    $out = ['exported_at' => date('c'), 'phone' => $customer['phone'] ?? '', 'run_id' => (int)$run['id'], 'tables' => []];
    $fp = [];
    foreach (BACKUP_TABLES as $table => $cols) {
        $st = $pdo->prepare('SELECT ' . implode(',', $cols) . " FROM `$table` WHERE customer_id=?");
        $st->execute([$cid]);
        $rows = $st->fetchAll();
        $out['tables'][$table] = $rows;
        $fp[$table] = [count($rows), md5(json_encode($rows))];
    }
    $root = dirname(__DIR__, 3) . '/private/upgrade_snapshots';
    if (!is_dir($root) && !@mkdir($root, 0755, true)) throw new RuntimeException('پوشهٔ نسخهٔ پشتیبان سرور در دسترس نیست');
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    $dir = $root . '/' . $cid;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('پوشهٔ نسخهٔ پشتیبان مشتری ساخته نشد');
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);
    $gz = function_exists('gzencode');
    $file = $dir . '/run' . (int)$run['id'] . '_' . bin2hex(random_bytes(6)) . ($gz ? '.json.gz' : '.json');
    if (@file_put_contents($file, $gz ? gzencode($json, 6) : $json) === false) throw new RuntimeException('ذخیرهٔ نسخهٔ پشتیبان ناموفق بود');
    if (!is_file($file) || filesize($file) < 20) throw new RuntimeException('نسخهٔ پشتیبان ذخیره‌شده معتبر نیست');
    pb_ug_save($pdo, $run, ['snapshot_file' => $file, 'fingerprint' => $fp]);
    return ['file' => basename($file), 'bytes' => (int)filesize($file), 'tables' => count($fp)];
}

function pb_ug_setup(PDO $pdo, array $customer, array $params, array &$errs): array {
    $cid = (int)$customer['id'];
    pb_fy_ensure($pdo); pb_acc_ensure($pdo); pb_voucher_ensure($pdo);
    $jy0 = pb_jyear_of($params['fy_start']);
    $docs = pb_ug_docs($pdo, $cid, $errs);
    $jy1 = pb_jyear_of(date('Y-m-d'));
    foreach ($docs as $d) $jy1 = max($jy1, pb_jyear_of($d[0]));
    $jy1 = min($jy1, $jy0 + 10);
    $created = 0;
    for ($jy = $jy0; $jy <= $jy1; $jy++) { $before = pb_ug_count($pdo, 'SELECT COUNT(*) FROM fiscal_years WHERE customer_id=?', [$cid]); pb_fy_insert($pdo, $cid, $jy, false); $created += pb_ug_count($pdo, 'SELECT COUNT(*) FROM fiscal_years WHERE customer_id=?', [$cid]) - $before; }
    $today = date('Y-m-d');
    $pdo->prepare('UPDATE fiscal_years SET is_current=0 WHERE customer_id=?')->execute([$cid]);
    $q = $pdo->prepare('UPDATE fiscal_years SET is_current=1 WHERE customer_id=? AND start_date<=? AND end_date>=?');
    $q->execute([$cid, $today, $today]);
    if ($q->rowCount() === 0) $pdo->prepare('UPDATE fiscal_years SET is_current=1 WHERE customer_id=? ORDER BY start_date DESC LIMIT 1')->execute([$cid]);
    $v = $params['vat'];
    $pdo->prepare('UPDATE fiscal_years SET vat_enabled=?, vat_rate=?, vat_levy_rate=?, vat_note=?, vat_source=? WHERE customer_id=? AND start_date>=?')
        ->execute([$v['enabled'] ? 1 : 0, $v['rate'], $v['levy'], $v['note'] === '' ? null : $v['note'], $v['source'] === '' ? null : $v['source'], $cid, $params['fy_start']]);
    if (function_exists('pb_party_sync')) pb_party_sync($pdo, $cid); // parties table mirrors every buyer/seller name before tafsili are created
    pb_acc_seed($pdo, $cid);
    $t = pb_taf_sync($pdo, $cid);
    $np = pb_ug_count($pdo, 'SELECT COUNT(*) FROM tafsili WHERE customer_id=?', [$cid]);
    return ['fiscal_years' => $jy1 - $jy0 + 1, 'fiscal_years_created' => $created, 'tafsili' => $np, 'tafsili_added' => (int)($t['added'] ?? 0), 'document_errors' => count($errs)];
}

/** account_id => type, role => account_id. */
function pb_ug_maps(PDO $pdo, int $cid): array {
    $types = []; $roles = [];
    $q = $pdo->prepare('SELECT id, type FROM accounts WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) $types[(int)$r['id']] = (string)$r['type'];
    $q = $pdo->prepare('SELECT role, account_id FROM account_roles WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) $roles[(string)$r['role']] = (int)$r['account_id'];
    return [$types, $roles];
}

function pb_ug_opening(PDO $pdo, array $customer, array $params): array {
    $cid = (int)$customer['id'];
    $errs = [];
    $docs = pb_ug_docs($pdo, $cid, $errs);
    [$types, $roles] = pb_ug_maps($pdo, $cid);
    $rules = pb_posting_rules();
    $nets = []; $hist = 0; $failed = [];
    $GLOBALS['pb_out_throw'] = true;
    try {
        foreach ($docs as [$d, $type, $id, $ctx]) {
            if ($d >= $params['fy_start']) continue;
            try {
                $built = pb_posting_build($rules, $type, $ctx);
                if (!$built) continue;
                foreach (pb_posting_resolve($pdo, $cid, $built, $ctx) as $l) {
                    $k = $l['account_id'] . ':' . (int)$l['tafsili_id'];
                    $nets[$k] = ($nets[$k] ?? 0) + (int)$l['debit'] - (int)$l['credit'];
                }
                $hist++;
            } catch (Throwable $e) { $failed[] = ['type' => $type, 'id' => $id, 'error' => mb_substr(pb_ug_perr($e), 0, 160)]; }
        }
    } finally { unset($GLOBALS['pb_out_throw']); }
    // party master (opening) balances: debtor -> AR debit, creditor -> AP credit, tafsili of the party
    $pm = 0;
    $q = $pdo->prepare('SELECT name, opening_balance, opening_side FROM parties WHERE customer_id=? AND opening_balance<>0');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) {
        $name = trim((string)$r['name']);
        if ($name === '') continue;
        $debtor = ($r['opening_side'] ?? '') !== 'creditor';
        $acc = $roles[$debtor ? 'ar' : 'ap'] ?? 0;
        if ($acc <= 0) continue;
        $amt = pb_pl_rial($r['opening_balance']);
        $k = $acc . ':' . pb_taf_upsert($pdo, $cid, 'party', mb_substr($name, 0, 150), $name);
        $nets[$k] = ($nets[$k] ?? 0) + ($debtor ? $amt : -$amt);
        $pm++;
    }
    $lines = []; $plNet = 0; $sum = 0;
    foreach ($nets as $k => $net) {
        if ($net === 0) continue;
        [$acc, $taf] = array_map('intval', explode(':', $k));
        $t = $types[$acc] ?? '';
        if ($t === 'revenue' || $t === 'expense') { $plNet += $net; continue; }
        $lines[] = ['account_id' => $acc, 'tafsili_id' => $taf, 'cost_center_id' => 0, 'debit' => $net > 0 ? $net : 0, 'credit' => $net < 0 ? -$net : 0, 'description' => 'مانده اول دوره (ارتقا به طلایی)'];
        $sum += $net;
    }
    $plug = $sum; // balance-sheet imbalance == -(profit/loss history) + party masters -> retained earnings
    if ($plug !== 0) $lines[] = ['account_id' => (int)($roles['retained'] ?? 0), 'tafsili_id' => 0, 'cost_center_id' => 0, 'debit' => $plug < 0 ? -$plug : 0, 'credit' => $plug > 0 ? $plug : 0, 'description' => 'سود (زیان) انباشتهٔ دوره‌های قبل و تراز مانده‌ها'];
    $res = ['history_documents' => $hist, 'history_failed' => $failed, 'lines' => count($lines), 'voucher_id' => 0, 'history_profit_loss_net' => $plNet, 'party_masters' => $pm, 'retained_balancing' => $plug];
    if (!$lines) return $res;
    $fy = pb_voucher_fy_for($pdo, $cid, $params['opening_date']);
    $old = $fy ? pb_posting_find($pdo, $cid, 'upg_open', (string)$fy['id']) : null;
    $res['voucher_id'] = pb_voucher_write($pdo, $customer, ['date' => $params['opening_date'], 'description' => 'سند افتتاحیهٔ ارتقا به نسخهٔ طلایی', 'status' => 'final',
        'source_type' => 'upg_open', 'source_id' => $fy ? (string)$fy['id'] : '0', 'max_lines' => 50000], $lines, $old ? (int)$old['id'] : 0);
    return $res;
}

/** Chronological posting of every document dated >= FY start. Time-budgeted; returns [done?, info]. */
function pb_ug_backfill(PDO $pdo, array $customer, array $params, array &$run): array {
    $cid = (int)$customer['id'];
    $errs = [];
    $docs = pb_ug_docs($pdo, $cid, $errs);
    $st = json_decode((string)$run['steps'], true) ?: [];
    $info = $st['backfill'] ?? ['posted' => 0, 'skipped' => []];
    $info['posted'] = (int)($info['posted'] ?? 0);
    $cursor = (string)($run['cursor_key'] ?? '');
    $t0 = microtime(true);
    $total = 0; $left = 0; $finished = true;
    $GLOBALS['pb_out_throw'] = true;
    try {
        foreach ($docs as [$d, $type, $id]) {
            if ($d < $params['fy_start']) continue;
            $total++;
            $key = pb_ug_key($d, $type, $id);
            if ($cursor !== '' && strcmp($key, $cursor) <= 0) continue;
            if (microtime(true) - $t0 > PB_UG_BUDGET_SECONDS) { $finished = false; $left++; continue; }
            try {
                if (pb_post_document($pdo, $customer, $type, $id) > 0) $info['posted']++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (count($info['skipped']) < 100) $info['skipped'][] = ['type' => $type, 'id' => $id, 'error' => mb_substr(pb_ug_perr($e), 0, 160)];
                $info['skipped_count'] = (int)($info['skipped_count'] ?? 0) + 1;
            }
            $cursor = $key;
        }
    } finally { unset($GLOBALS['pb_out_throw']); }
    $info['total'] = $total;
    $info['remaining'] = $left;
    pb_ug_save($pdo, $run, ['cursor_key' => $cursor]);
    return [$finished, $info];
}

function pb_ug_role_net(PDO $pdo, int $cid, array $roles, ?string $onlySource = null): int {
    $ph = implode(',', array_fill(0, count($roles), '?'));
    $sql = "SELECT COALESCE(SUM(l.debit-l.credit),0) FROM voucher_lines l JOIN account_roles r ON r.account_id=l.account_id AND r.customer_id=l.customer_id
        JOIN vouchers v ON v.id=l.voucher_id AND v.customer_id=l.customer_id WHERE l.customer_id=? AND r.role IN ($ph)" . ($onlySource ? ' AND v.source_type=?' : '');
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$cid], $roles, $onlySource ? [$onlySource] : []));
    return (int)$st->fetchColumn();
}

function pb_ug_reconcile(PDO $pdo, array $customer, array $run): array {
    $cid = (int)$customer['id'];
    $diffs = [];
    $add = static function (string $code, string $level, string $what, $book, $expected) use (&$diffs): void {
        $diffs[] = ['code' => $code, 'level' => $level, 'item' => $what, 'book' => $book, 'expected' => $expected, 'diff' => (int)$book - (int)$expected];
    };
    // 1. inventory account vs lot valuation
    $book = pb_ug_role_net($pdo, $cid, ['inventory', 'inventory_processed']);
    $val = 0;
    $lq = $pdo->prepare('SELECT id, remaining FROM purchases WHERE customer_id=? AND remaining>0 AND is_mix<>3');
    $lq->execute([$cid]);
    foreach ($lq->fetchAll() as $lot) {
        $l = pb_pl_lot($pdo, $cid, (int)$lot['id']);
        if ($l && $l[1] > 0) $val += pb_posting_divround(pb_posting_mul($l[0], (int)round((float)$lot['remaining'] * 1000)), $l[1]);
    }
    $inv = ['book' => $book, 'valuation' => $val, 'diff' => $book - $val];
    if (abs($inv['diff']) > PB_UG_TOLERANCE) $add('inventory', 'error', 'موجودی انبار ≠ حساب موجودی', $book, $val);
    // 2. party balances: AR/AP per party tafsili vs the party statement engine
    $expected = [];
    foreach (pb_party_balances($pdo, $cid) as $k => $b) $expected[$k] = (float)$b['rial']; // T30.3b: single party balance function
    $bk = pb_party_ledger_balances($pdo, $cid);
    $parties = 0; $pdiff = 0;
    foreach (array_unique(array_merge(array_keys($expected), array_keys($bk))) as $k) {
        $exp = pb_pl_rial($expected[$k] ?? 0);
        $b = $bk[$k] ?? 0;
        if ($exp === 0 && $b === 0) continue;
        $parties++;
        if (abs($b - $exp) > PB_UG_TOLERANCE) { $pdiff++; if ($pdiff <= 200) $add('party_balance', 'warning', 'طرف‌حساب «' . $k . '»', $b, $exp); }
    }
    // 3. cheques
    $mult = pb_posting_unit_mult();
    foreach ([['cheque_in_hand', 'received', 'in_hand', 1], ['cheque_collect', 'received', 'deposited', 1], ['cheque_payable', 'issued', 'pending', -1]] as [$role, $dir, $stt, $sign]) {
        $r = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM cheques WHERE customer_id=? AND direction=? AND status=?');
        $r->execute([$cid, $dir, $stt]);
        $reg = (int)round((float)$r->fetchColumn() * $mult);
        $b = $sign * pb_ug_role_net($pdo, $cid, [$role]);
        if (abs($b - $reg) > PB_UG_TOLERANCE) $add('cheque_register', 'error', 'چک‌ها: ' . $role, $b, $reg);
    }
    // 4. VAT must be zero for history (opening voucher carries no VAT lines)
    $hv = pb_ug_role_net($pdo, $cid, ['vat_in', 'vat_out'], 'upg_open');
    if ($hv !== 0) $add('vat_history', 'error', 'مالیات ارزش افزودهٔ دورهٔ قبل از ارتقا', $hv, 0);
    // 5. trial balance
    $t = $pdo->prepare('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM voucher_lines WHERE customer_id=?');
    $t->execute([$cid]);
    $tb = $t->fetch();
    if ((string)$tb['d'] !== (string)$tb['c']) $add('trial_balance', 'error', 'تراز آزمایشی: بدهکار ≠ بستانکار', (int)$tb['d'], (int)$tb['c']);
    // 6. engine integrity (voucher vs document, orphans, unbalanced)
    $ig = pb_acct_integrity($pdo, $customer, []);
    foreach (array_slice($ig['issues'], 0, 100) as $i) { if (in_array($i['code'], ['inventory_valuation', 'cheque_register'], true)) continue; $diffs[] = ['code' => 'integrity:' . $i['code'], 'level' => $i['level'], 'item' => $i['detail'], 'source' => $i['source'] ?? null, 'book' => null, 'expected' => null, 'diff' => null]; }
    $steps = json_decode((string)$run['steps'], true) ?: [];
    $skipped = (int)($steps['backfill']['skipped_count'] ?? 0) + count($steps['opening']['history_failed'] ?? []);
    $errors = count(array_filter($diffs, static fn($d) => $d['level'] === 'error')) + ($skipped > 0 ? 1 : 0);
    return ['ok' => $errors === 0, 'errors' => $errors, 'warnings' => count($diffs) - count(array_filter($diffs, static fn($d) => $d['level'] === 'error')),
        'inventory' => $inv, 'parties_checked' => $parties, 'parties_differing' => $pdiff, 'trial_balance' => ['debit' => (int)$tb['d'], 'credit' => (int)$tb['c']],
        'history_vat' => $hv, 'skipped_documents' => $skipped, 'tolerance_rial' => PB_UG_TOLERANCE, 'differences' => $diffs,
        'note' => 'مانده نقد/بانک اول دوره ثبت نمی‌شود؛ مغایرت طرف‌حساب‌ها ممکن است از وام، دلال یا فرآوری باشد (حساب‌های جدا)'];
}

/* ---------------- apply ---------------- */

function pb_ug_apply(PDO $pdo, array $customer, array $p): array {
    $cid = (int)$customer['id'];
    pb_ug_ensure($pdo);
    $lk = $pdo->query("SELECT GET_LOCK('pb_upgrade_$cid', 0)"); // one runner per customer (double click / retry while a request is still working)
    if (!$lk || (int)$lk->fetchColumn() !== 1) return ['status' => 'running', 'busy' => true, 'resume' => true];
    try { return pb_ug_apply_locked($pdo, $customer, $p); }
    finally { $pdo->query("SELECT RELEASE_LOCK('pb_upgrade_$cid')"); }
}

function pb_ug_apply_locked(PDO $pdo, array $customer, array $p): array {
    $cid = (int)$customer['id'];
    $run = pb_ug_latest($pdo, $cid, ['running', 'failed']);
    if (!$run) {
        $done = pb_ug_latest($pdo, $cid, ['done']);
        if ($done && pb_tier_of($customer) === PB_TIER_GOLD) return ['status' => 'done', 'already' => true] + pb_ug_run_out($done); // apply twice = no-op
        if (pb_tier_of($customer) === PB_TIER_GOLD) bad('این حساب هم‌اکنون طلایی است', 409);
        if (empty($p['confirm'])) bad('ابتدا پیش‌نمایش ارتقا را ببینید و تأیید کنید');
        if (!pb_ug_entitled($pdo, $cid)) out(['error' => 'tier_not_entitled', 'message' => 'ابتدا «ارتقا به طلایی» را خریداری کنید یا ادمین ارتقای دستی ثبت کند', 'need' => 'tier_purchase'], 402);
        [$fyStart, $fyEnd, $od, $vat] = pb_ug_params($p);
        $pv = pb_ug_preview($pdo, $customer, $p);
        if (!$pv['ok']) out(['error' => 'upgrade_blocked', 'message' => 'ارتقا به‌دلیل خطاهای پیش‌نمایش ممکن نیست', 'issues' => $pv['issues']], 409);
        $uid = (int)($customer['_staff_id'] ?? 0);
        $pdo->prepare('INSERT INTO upgrade_runs (customer_id, status, step, params, steps, started_at, created_by) VALUES (?,?,?,?,?,NOW(),?)')
            ->execute([$cid, 'running', 'snapshot', json_encode(['fy_start' => $fyStart, 'fy_end' => $fyEnd, 'opening_date' => $od, 'vat' => $vat], JSON_UNESCAPED_UNICODE), '{}', $uid]);
        $st = $pdo->prepare('SELECT * FROM upgrade_runs WHERE id=?');
        $st->execute([(int)$pdo->lastInsertId()]);
        $run = $st->fetch();
        pb_audit($pdo, $customer, 'tier.upgrade_start', 'upgrade_run', (string)$run['id'], 'شروع ارتقا به نسخهٔ طلایی از ' . $fyStart);
    }
    $params = json_decode((string)$run['params'], true);
    $steps = json_decode((string)$run['steps'], true) ?: [];
    if (!empty($steps['tier']['done'])) $customer['tier'] = PB_TIER_GOLD; // resumed run: the tier step already ran
    pb_ug_save($pdo, $run, ['status' => 'running', 'error' => null]);
    foreach (PB_UG_STEPS as $step) {
        if (!empty($steps[$step]['done'])) continue;
        pb_ug_save($pdo, $run, ['step' => $step]);
        $GLOBALS['pb_out_throw'] = true;
        try {
            $errs = [];
            if ($step === 'snapshot') $info = pb_ug_snapshot($pdo, $customer, $run);
            elseif ($step === 'tier') {
                $pdo->prepare("UPDATE customers SET tier='gold' WHERE id=?")->execute([$cid]);
                $customer['tier'] = PB_TIER_GOLD;
                $info = ['tier' => 'gold'];
            } elseif ($step === 'setup') $info = pb_ug_setup($pdo, $customer, $params, $errs);
            elseif ($step === 'opening') $info = pb_ug_opening($pdo, $customer, $params);
            elseif ($step === 'backfill') {
                [$fin, $info] = pb_ug_backfill($pdo, $customer, $params, $run);
                if (!$fin) {
                    $steps[$step] = $info + ['done' => false];
                    pb_ug_save($pdo, $run, ['steps' => $steps]);
                    unset($GLOBALS['pb_out_throw']);
                    return ['status' => 'running', 'resume' => true] + pb_ug_run_out($run, false);
                }
            } else {
                $rep = pb_ug_reconcile($pdo, $customer, $run);
                pb_ug_save($pdo, $run, ['report' => $rep]);
                $info = ['ok' => $rep['ok'], 'errors' => $rep['errors'], 'warnings' => $rep['warnings']];
            }
            unset($GLOBALS['pb_out_throw']);
        } catch (Throwable $e) {
            unset($GLOBALS['pb_out_throw']);
            if ($pdo->inTransaction()) $pdo->rollBack();
            $steps[$step] = ['done' => false, 'error' => mb_substr(pb_ug_perr($e), 0, 300)];
            pb_ug_save($pdo, $run, ['status' => 'failed', 'steps' => $steps, 'error' => mb_substr($step . ': ' . pb_ug_perr($e), 0, 480)]);
            return ['status' => 'failed', 'failed_step' => $step, 'resume' => true] + pb_ug_run_out($run, false);
        }
        $steps[$step] = $info + ['done' => true, 'at' => date('c')];
        pb_ug_save($pdo, $run, ['steps' => $steps]);
    }
    pb_ug_save($pdo, $run, ['status' => 'done', 'step' => 'reconcile', 'finished_at' => date('Y-m-d H:i:s')]);
    pb_audit($pdo, $customer, 'tier.upgrade_done', 'upgrade_run', (string)$run['id'], 'ارتقا به نسخهٔ طلایی کامل شد');
    return ['status' => 'done'] + pb_ug_run_out($run);
}

/* ---------------- rollback ---------------- */

function pb_ug_blockers(PDO $pdo, int $cid, array $run): array {
    $b = [];
    $n = pb_ug_count($pdo, "SELECT COUNT(*) FROM vouchers WHERE customer_id=? AND source_type='manual'", [$cid]);
    if ($n > 0) $b[] = ['code' => 'manual_vouchers', 'count' => $n, 'detail' => 'سند دستی ثبت شده است'];
    $n = pb_ug_count($pdo, "SELECT COUNT(*) FROM fiscal_years WHERE customer_id=? AND status<>'open'", [$cid]) + pb_ug_count($pdo, "SELECT COUNT(*) FROM vouchers WHERE customer_id=? AND source_type IN ('fy_open','fy_close')", [$cid]);
    if ($n > 0) $b[] = ['code' => 'fiscal_year_closed', 'count' => $n, 'detail' => 'سال مالی بسته یا سند اختتامیه/افتتاحیه ثبت شده است'];
    foreach (['cost_centers', 'cash_boxes', 'bank_accounts', 'expenses', 'owner_draws'] as $e) {
        $n = pb_ug_count($pdo, "SELECT COUNT(*) FROM `ent_$e` WHERE customer_id=? AND deleted_at IS NULL", [$cid]);
        if ($n > 0) $b[] = ['code' => 'gold_entity_' . $e, 'count' => $n, 'detail' => 'داده‌های اختصاصی طلایی (' . $e . ') ثبت شده است'];
    }
    foreach (['sales', 'purchases'] as $t) {
        $n = pb_ug_count($pdo, "SELECT COUNT(*) FROM `$t` WHERE customer_id=? AND total_tax IS NOT NULL AND total_tax<>0", [$cid]);
        if ($n > 0) $b[] = ['code' => 'taxed_documents_' . $t, 'count' => $n, 'detail' => 'اسناد دارای مالیات ثبت شده است'];
    }
    $n = pb_ug_count($pdo, "SELECT COUNT(*) FROM accounts WHERE customer_id=? AND is_system=0", [$cid]);
    if ($n > 0) $b[] = ['code' => 'custom_accounts', 'count' => $n, 'detail' => 'حساب دلخواه به سرفصل‌ها اضافه شده است'];
    return $b;
}

function pb_ug_rollback(PDO $pdo, array $customer, array $p): array {
    $cid = (int)$customer['id'];
    $run = pb_ug_latest($pdo, $cid, ['running', 'failed', 'done']);
    if ($run && pb_tier_of($customer) !== PB_TIER_GOLD) { // run abandoned before the tier step: nothing was changed except the snapshot
        if (empty($p['dry'])) $pdo->prepare("UPDATE upgrade_runs SET status='rolled_back', finished_at=NOW() WHERE id=?")->execute([(int)$run['id']]);
        return ['status' => 'rolled_back', 'run_id' => (int)$run['id'], 'dry' => !empty($p['dry']), 'blockers' => [], 'note' => 'ارتقا پیش از تغییر نسخه متوقف شده بود؛ تغییری برای بازگردانی نبود'];
    }
    if (!$run) bad('ارتقایی برای بازگشت وجود ندارد', 409);
    $ref = strtotime((string)($run['finished_at'] ?: $run['started_at']));
    $days = (int)floor((time() - $ref) / 86400);
    $blockers = pb_ug_blockers($pdo, $cid, $run);
    $within = $days <= PB_UG_ROLLBACK_DAYS;
    if (!$within) array_unshift($blockers, ['code' => 'window_expired', 'count' => $days, 'detail' => 'مهلت بازگشت (' . PB_UG_ROLLBACK_DAYS . ' روز) گذشته است']);
    $fp = json_decode((string)$run['fingerprint'], true) ?: [];
    $changed = [];
    foreach (BACKUP_TABLES as $table => $cols) {
        if (!isset($fp[$table])) continue;
        $st = $pdo->prepare('SELECT ' . implode(',', $cols) . " FROM `$table` WHERE customer_id=?");
        $st->execute([$cid]);
        $rows = $st->fetchAll();
        if (count($rows) !== (int)$fp[$table][0] || md5(json_encode($rows)) !== (string)$fp[$table][1]) $changed[] = $table;
    }
    $res = ['run_id' => (int)$run['id'], 'dry' => !empty($p['dry']), 'blockers' => $blockers, 'operational_changed_since_snapshot' => $changed,
        'snapshot' => $run['snapshot_file'] ? basename((string)$run['snapshot_file']) : null];
    if ($blockers) out(['error' => 'rollback_blocked', 'message' => 'بازگشت ممکن نیست'] + $res, 409);
    if (!empty($p['dry'])) return $res + ['would_remove' => [
        'vouchers' => pb_ug_count($pdo, 'SELECT COUNT(*) FROM vouchers WHERE customer_id=?', [$cid]), 'accounts' => pb_ug_count($pdo, 'SELECT COUNT(*) FROM accounts WHERE customer_id=?', [$cid]),
        'tafsili' => pb_ug_count($pdo, 'SELECT COUNT(*) FROM tafsili WHERE customer_id=?', [$cid]), 'fiscal_years' => pb_ug_count($pdo, 'SELECT COUNT(*) FROM fiscal_years WHERE customer_id=?', [$cid])]];
    $pdo->beginTransaction();
    try {
        foreach (['voucher_lines', 'vouchers', 'account_roles', 'accounts', 'tafsili', 'fiscal_years'] as $t) $pdo->prepare("DELETE FROM `$t` WHERE customer_id=?")->execute([$cid]);
        $pdo->prepare("UPDATE customers SET tier='green' WHERE id=?")->execute([$cid]);
        $pdo->prepare("UPDATE upgrade_runs SET status='rolled_back', finished_at=NOW() WHERE id=?")->execute([(int)$run['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[upgrade_rollback] ' . $e->getMessage());
        bad('بازگشت ناموفق بود؛ هیچ تغییری اعمال نشد', 500);
    }
    $customer['tier'] = PB_TIER_GREEN;
    pb_audit($pdo, $customer, 'tier.upgrade_rollback', 'upgrade_run', (string)$run['id'], 'بازگشت از نسخهٔ طلایی به سبز');
    return ['status' => 'rolled_back'] + $res;
}

function pb_ug_action(string $action, array $p, PDO $pdo, array $customer): void {
    if ((int)($customer['_staff_id'] ?? 0) > 0) out(['error' => 'forbidden', 'message' => 'ارتقای نسخه فقط توسط مالک انجام می‌شود', 'need' => 'owner'], 403);
    pb_ug_ensure($pdo);
    @set_time_limit(120);
    if ($action === 'tier.upgrade_preview') out(pb_ug_preview($pdo, $customer, $p));
    if ($action === 'tier.upgrade_apply') out(pb_ug_apply($pdo, $customer, $p));
    if ($action === 'tier.upgrade_rollback') out(pb_ug_rollback($pdo, $customer, $p));
    if ($action === 'tier.upgrade_status') { $r = pb_ug_latest($pdo, (int)$customer['id']); out(['run' => $r ? pb_ug_run_out($r) : null, 'tier' => pb_tier_of($customer), 'entitled' => pb_ug_entitled($pdo, (int)$customer['id'])]); }
    bad('عملیات نامعتبر', 404);
}
