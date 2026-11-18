<?php
declare(strict_types=1);
/** تست خودکار pb_fxr_build (بدون DB). اجرا: php fx_reval_selftest.php [مسیر cases.json] — روی هاست: JSON را کنار این فایل بگذارید؛ بعد از اجرا هر دو را حذف کنید. */
require_once __DIR__ . '/fx_reval.php';
require_once __DIR__ . '/posting.php';
require_once __DIR__ . '/money_unit.php'; // pb_app_setting_get (period lock)
// CLI stubs (app.php is not loaded): engine errors surface as exceptions in the test only
if (!function_exists('bad')) { function bad(string $msg, int $code = 400): void { throw new RuntimeException($msg); } }
$cands = [$argv[1] ?? '', __DIR__ . '/fx_reval_cases.json', __DIR__ . '/../../../WINDOWS/test/fx_reval_cases.json'];
$file = '';
foreach ($cands as $c) if ($c !== '' && is_file($c)) { $file = $c; break; }
if ($file === '') { echo "fx_reval_cases.json پیدا نشد\n"; exit(2); }
$doc = json_decode((string)file_get_contents($file), true);
$norm = fn($v) => json_decode(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
$eq = function ($a, $b) use (&$eq): bool {
    if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b)) return false;
        foreach ($a as $k => $v) if (!array_key_exists($k, $b) || !$eq($v, $b[$k])) return false;
        return true;
    }
    if (is_numeric($a) && is_numeric($b)) return abs((float)$a - (float)$b) < 1e-9;
    return $a === $b;
};
$fail = 0;
$check = function (string $name, bool $ok, string $extra = '') use (&$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . "\n";
    if (!$ok) { $fail++; if ($extra !== '') echo $extra . "\n"; }
};
foreach ($doc['cases'] as $c) {
    $res = pb_fxr_build($c['docs'], $c['paid'], $c['rates']);
    $fp = $res['fingerprint']; unset($res['fingerprint']);
    $ok = $eq($norm($res), $c['expect']) && preg_match('/^[0-9a-f]{40}$/', $fp) === 1;
    // invariant: gross up/down per side = sum of party up/down; net_delta = sum(ar docs) - sum(ap docs)
    $net = 0; foreach ($res['docs'] as $d) $net += $d['side'] === 'ar' ? $d['delta'] : -$d['delta'];
    $ok = $ok && $net === $res['net_delta'];
    $check($c['name'], $ok, $ok ? '' : '  got: ' . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n  exp: " . json_encode($c['expect'], JSON_UNESCAPED_UNICODE));
}
// fingerprint: independent of input order, sensitive to remaining amount / rate / document set
$cs = array_values(array_filter($doc['cases'], fn($c) => count($c['docs']) >= 3));
$c = $cs[0];
$f1 = pb_fxr_build($c['docs'], $c['paid'], $c['rates'])['fingerprint'];
$f2 = pb_fxr_build(array_reverse($c['docs']), $c['paid'], $c['rates'])['fingerprint'];
$check('fingerprint_order_independent', $f1 === $f2);
$paid2 = $c['paid']; $paid2['ar:1'] = 1.0;
$check('fingerprint_changes_on_payment', pb_fxr_build($c['docs'], $paid2, $c['rates'])['fingerprint'] !== $f1);
$rates2 = $c['rates']; foreach ($rates2 as $k => $v) $rates2[$k] = $v + 1;
$check('fingerprint_changes_on_rate', pb_fxr_build($c['docs'], $c['paid'], $rates2)['fingerprint'] !== $f1);
$check('fingerprint_changes_on_docset', pb_fxr_build(array_slice($c['docs'], 1), $c['paid'], $c['rates'])['fingerprint'] !== $f1);
// missing rate for an open doc => exception (callers pre-check with pb_fxr_rates_check)
$thrown = false;
try { pb_fxr_build([['side' => 'ar', 'id' => 1, 'ref' => '#1', 'party' => 'x', 'ccy' => 'EUR', 'fx_rate' => 1, 'amt' => 1, 'date' => '2026-01-01']], [], ['USD' => 1]); }
catch (InvalidArgumentException $e) { $thrown = $e->getMessage() === 'rate_missing:EUR'; }
$check('rate_missing_throws', $thrown);
// helpers
$check('open_ccys', pb_fxr_open_ccys([['side' => 'ar', 'id' => 1, 'ccy' => 'USD', 'amt' => 5], ['side' => 'ap', 'id' => 2, 'ccy' => 'EUR', 'amt' => 5], ['side' => 'ar', 'id' => 3, 'ccy' => 'AED', 'amt' => 5]],
    ['ar:1' => 5.0, 'ap:2' => 1.0]) === ['AED', 'EUR']); // USD fully paid => excluded; sorted
/* ================= بخش «موتور»: each:groups در pb_posting_build / pb_posting_resolve (قوانین ساختگی) ================= */
$mk = fn($d) => ['op' => 'pos', 'args' => [$d]];
$fake = ['round_tolerance' => 0, 'rules' => [
    ['id' => 't_plain', 'doc_type' => 't_plain', 'lines' => [
        ['account' => 'ar', 'debit' => '@a', 'tafsili' => 'party', 'desc' => 'x'],
        ['account' => 'sales', 'credit' => '@a']]],
    ['id' => 't_each', 'doc_type' => 't_each', 'lines' => [
        ['account' => 'ar', 'each' => 'groups', 'debit' => $mk('@d'), 'credit' => $mk(['op' => 'sub', 'args' => [0, '@d']]), 'tafsili' => 'party'],
        ['account' => 'fx_unrealized', 'credit' => '@up'],
        ['account' => 'fx_unrealized', 'debit' => '@down']]],
]];
// (الف) بدون each: خروجی دقیقاً ثابت و بدون کلید tafsili_id
$plain = pb_posting_build($fake, 't_plain', ['fields' => ['a' => 50], 'dims' => ['party' => 99], 'groups' => [['fields' => ['d' => 1]]]]);
$plainExp = [
    ['role' => 'ar', 'debit' => 50, 'credit' => 0, 'tafsili_key' => 'party', 'cc_key' => null, 'desc' => 'x', 'acct_dim' => null],
    ['role' => 'sales', 'debit' => 0, 'credit' => 50, 'tafsili_key' => null, 'cc_key' => null, 'desc' => '', 'acct_dim' => null]];
$check('engine_plain_unchanged', $plain === $plainExp, json_encode($plain));
// (ب) each با سه گروه (دو غیرصفر + یک صفر) و (ج) حذف گروه صفر
$ctx = ['fields' => ['up' => 100, 'down' => 30], 'dims' => ['party' => 99],
    'groups' => [['fields' => ['d' => 100], 'dims' => ['party' => 11]], ['fields' => ['d' => -30], 'dims' => ['party' => 12]], ['fields' => ['d' => 0], 'dims' => ['party' => 13]]]];
$b = pb_posting_build($fake, 't_each', $ctx);
$brief = array_map(fn($l) => [$l['role'], $l['debit'], $l['credit'], $l['tafsili_id'] ?? 'n/a', $l['tafsili_key']], $b);
$check('engine_each_three_groups_zero_dropped', $brief === [['ar', 100, 0, 11, null], ['ar', 0, 30, 12, null], ['fx_unrealized', 0, 100, 'n/a', null], ['fx_unrealized', 30, 0, 'n/a', null]], json_encode($brief));
// (د) groups خالی/غایب ⇒ بدون سطر و بدون خطا
$check('engine_each_empty_groups', pb_posting_build($fake, 't_each', ['fields' => ['up' => 0, 'down' => 0], 'groups' => []]) === []);
$check('engine_each_missing_groups', pb_posting_build($fake, 't_each', ['fields' => ['up' => 0, 'down' => 0]]) === []);
// (هـ) تراز بودن سند ساخته‌شده
$sd = 0; $sc = 0; foreach ($b as $l) { $sd += $l['debit']; $sc += $l['credit']; }
$check('engine_each_balanced', $sd === $sc && $sd > 0, "$sd vs $sc");
// نبودن when گروه: when روی fields همان گروه سنجیده می‌شود
$fake2 = ['round_tolerance' => 0, 'rules' => [['id' => 'w', 'doc_type' => 'w', 'lines' => [
    ['account' => 'ar', 'each' => 'groups', 'when' => '@on', 'debit' => '@d'], ['account' => 'sales', 'credit' => '@tot']]]]];
$w = pb_posting_build($fake2, 'w', ['fields' => ['on' => 0, 'tot' => 7], 'groups' => [['fields' => ['on' => 1, 'd' => 3]], ['fields' => ['on' => 0, 'd' => 4]], ['fields' => ['on' => 1, 'd' => 4]]]]);
$check('engine_each_when_per_group', count($w) === 3 && $w[0]['debit'] === 3 && $w[1]['debit'] === 4 && $w[2]['credit'] === 7, json_encode($w));
// resolve: tafsili_id مستقیم گروه؛ خطوط بدون each با dims سراسری (نیاز به pdo_sqlite؛ در نبود آن رد می‌شود)
if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, customer_id INT, active INT)');
    $pdo->exec('CREATE TABLE account_roles (customer_id INT, role TEXT, account_id INT)');
    foreach (['ar' => 1, 'sales' => 2, 'fx_unrealized' => 3] as $role => $aid) {
        $pdo->exec("INSERT INTO accounts VALUES ($aid, 5, 1)");
        $pdo->exec("INSERT INTO account_roles VALUES (5, '$role', $aid)");
    }
    $r = pb_posting_resolve($pdo, 5, $b, $ctx);
    $check('resolve_each_tafsili_ids', array_column($r, 'tafsili_id') === [11, 12, 0, 0], json_encode(array_column($r, 'tafsili_id')));
    $r2 = pb_posting_resolve($pdo, 5, $plain, ['dims' => ['party' => 99]]);
    $check('resolve_plain_uses_global_dims', array_column($r2, 'tafsili_id') === [99, 0], json_encode(array_column($r2, 'tafsili_id')));
    $rd = 0; $rc = 0; foreach ($r as $l) { $rd += $l['debit']; $rc += $l['credit']; }
    $check('resolve_each_balanced', $rd === $rc && $rd > 0);
} else {
    echo "SKIP resolve tests (pdo_sqlite missing)\n";
}
/* ================= بخش «برگشت خودکار»: pb_fxr_rev_plan (خالص) ================= */
foreach ($doc['rev_plan'] ?? [] as $rc) {
    $got = pb_fxr_rev_plan($rc['as_of'], $rc['fy'], $rc['locked_through']);
    $check('rev_plan: ' . $rc['name'], $eq($got, $rc['expect']), json_encode($got, JSON_UNESCAPED_UNICODE));
}
$check('rev_plan_cases_present', count($doc['rev_plan'] ?? []) >= 10);
$check('rev_plan_bad_date_throws', (function () { try { pb_fxr_rev_plan('2026-02-30', ['status' => 'open'], null); return false; } catch (InvalidArgumentException $e) { return true; } })());

/* ================= بخش «stale»: pb_fxr_is_stale (خالص) ================= */
foreach ($doc['stale'] ?? [] as $sc) $check('stale: ' . $sc['name'], pb_fxr_is_stale($sc['stored'], $sc['now'], $sc['status']) === $sc['expect']);
$check('stale_cases_present', count($doc['stale'] ?? []) >= 6);

/* ================= بخش «اعتبارسنجی»: pb_fxr_validate + pb_fxr_close_kind (خالص) ================= */
foreach ($doc['validate'] ?? [] as $vc) {
    $v = pb_fxr_validate($vc['in']);
    $codes = array_column($v['problems'], 'code');
    $ok = $v['ok'] === $vc['expect']['ok'] && $codes === $vc['expect']['codes'] && count($codes) === count(array_unique($codes));
    if ($ok && isset($vc['expect']['ccys'])) {
        $rm = array_values(array_filter($v['problems'], fn($x) => $x['code'] === 'rate_missing'))[0];
        $ok = $rm['ccys'] === $vc['expect']['ccys'];
        foreach ($vc['expect']['message_has'] ?? [] as $w) if (strpos($rm['message'], $w) === false) $ok = false;
    }
    foreach ($v['problems'] as $pr) if (!isset($pr['code'], $pr['message']) || $pr['message'] === '') $ok = false;
    $check('validate: ' . $vc['name'], $ok, json_encode($v, JSON_UNESCAPED_UNICODE));
}
$check('validate_cases_present', count($doc['validate'] ?? []) >= 15);
foreach ($doc['close_kind'] ?? [] as $kc) $check('close_kind: ' . $kc['name'], pb_fxr_close_kind($kc['has_active'], $kc['stored'], $kc['now'], $kc['has_delta']) === $kc['expect']);

/* ================= بخش «گزارش» (مرحلهٔ ۸): تجمیع تحقق‌یافته/تحقق‌نیافته، خالص ارزها، تخصیص، جمع به تفکیک ارز، خروجی تخت (خالص، بدون DB) ================= */
foreach ($doc['report'] ?? [] as $rc) {
    $kind = (string)$rc['kind'];
    if ($kind === 'rollup') { $got = pb_fxr_report_rollup($rc['rows']); $ok = $eq($norm($got), $rc['expect']); }
    elseif ($kind === 'ccy_nets') { $got = pb_fxr_ccy_nets($rc['detail']); $ok = $eq($norm($got), $rc['expect']); }
    elseif ($kind === 'alloc') { $got = pb_fxr_alloc_net((int)$rc['net'], $rc['nets'], (bool)$rc['rev']); $ok = $eq($norm($got), $rc['expect']); }
    elseif ($kind === 'reval_ccy') { $got = pb_fxr_reval_ccy_totals($rc['revals']); $ok = $eq($norm($got), $rc['expect']); }
    elseif ($kind === 'export') {
        $got = pb_fxr_export_build($rc['row'], $rc['detail']); $x = $rc['expect'];
        $ok = $got['title'] === $x['title'] && $got['as_of'] === $x['as_of'] && $eq($norm($got['rows']), $x['rows']) && $eq($norm($got['totals']), $x['totals'])
            && $eq($norm($got['sheets']['docs']['rows']), $x['docs_rows']) && $eq($norm($got['sheets']['parties']['rows']), $x['parties_rows']) && $eq($norm($got['sheets']['items']['rows']), $x['items_rows'])
            && count($got['headers']) === $x['header_counts']['main'] && count($got['sheets']['docs']['headers']) === $x['header_counts']['docs']
            && count($got['sheets']['parties']['headers']) === $x['header_counts']['parties'] && count($got['sheets']['items']['headers']) === $x['header_counts']['items'];
        foreach ($got['rows'] as $row) if (count($row) !== count($got['headers'])) $ok = false; // هر سطر هم‌اندازهٔ سرستون‌ها
        foreach (['docs', 'parties', 'items'] as $sh) foreach ($got['sheets'][$sh]['rows'] as $row) if (count($row) !== count($got['sheets'][$sh]['headers'])) $ok = false;
    } else { $got = null; $ok = false; }
    $check('report[' . $kind . ']: ' . $rc['name'], $ok, '  got: ' . json_encode($got, JSON_UNESCAPED_UNICODE));
}
$check('report_cases_present', count($doc['report'] ?? []) >= 12);
// پایداری: ورودی rollup دست‌نخورده می‌ماند و ترتیب ورودی در خروجی بی‌اثر است
$rr = $doc['report'][0]['rows'] ?? [];
$check('report_rollup_order_independent', $eq($norm(pb_fxr_report_rollup($rr)), $norm(pb_fxr_report_rollup(array_reverse($rr)))));
// ناوردایی: جمع کل تحقق‌یافته + تحقق‌نیافته = جمع ردیف‌ها
$ro = pb_fxr_report_rollup($rr); $sumTot = 0; foreach ($ro['rows'] as $x) $sumTot += $x['total'];
$check('report_rollup_total_invariant', $sumTot === $ro['total_net'] && $ro['total_net'] === $ro['total_realized'] + $ro['total_unrealized']);

/* ================= بخش «سند تسعیر»: لودر + قوانین واقعی fx_reval / fx_reval_rev (sqlite درون‌حافظه) ================= */
if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, customer_id INT, active INT)');
    $pdo->exec('CREATE TABLE account_roles (customer_id INT, role TEXT, account_id INT)');
    $roleAcc = ['ar' => 1, 'ap' => 2, 'fx_unrealized' => 3, 'cash' => 4, 'bank' => 5, 'fx_diff' => 6];
    foreach ($roleAcc as $role => $aid) { $pdo->exec("INSERT INTO accounts VALUES ($aid, 5, 1)"); $pdo->exec("INSERT INTO account_roles VALUES (5, '$role', $aid)"); }
    $pdo->exec('CREATE TABLE tafsili (id INTEGER PRIMARY KEY, customer_id INT, kind TEXT, ref_key TEXT)');
    $pdo->exec("INSERT INTO tafsili VALUES (14, 5, 'party', 'دی')");
    $pdo->exec('CREATE TABLE fx_revals (id INTEGER PRIMARY KEY, customer_id INT, as_of TEXT, ar_up INT, ar_down INT, ap_up INT, ap_down INT, detail TEXT, status TEXT, reversed_at TEXT)');
    $parties = [
        ['party' => 'الف', 'side' => 'ar', 'no_party' => false, 'up' => 1000, 'down' => 0, 'tafsili_id' => 11],
        ['party' => 'ب', 'side' => 'ar', 'no_party' => false, 'up' => 0, 'down' => 400, 'tafsili_id' => 12],
        ['party' => 'ج', 'side' => 'ap', 'no_party' => false, 'up' => 300, 'down' => 0, 'tafsili_id' => 13],
        ['party' => 'دی', 'side' => 'ap', 'no_party' => false, 'up' => 0, 'down' => 50],              // بدون tafsili_id ذخیره‌شده => با نام پیدا می‌شود (۱۴)
        ['party' => PB_FXR_NO_PARTY, 'side' => 'ar', 'no_party' => true, 'up' => 70, 'down' => 0, 'tafsili_id' => 0],
    ];
    $det = json_encode(['items' => [], 'docs' => [], 'parties' => $parties, 'rates' => [], 'fingerprint' => 'x']);
    $ins = $pdo->prepare('INSERT INTO fx_revals VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([1, 5, '2026-03-20', 1070, 400, 300, 50, $det, 'reversed', '2026-03-20 10:00:00']);
    $ins->execute([2, 5, '2026-03-20', 500, 0, 0, 200, null, 'reversed', '2026-03-20 10:00:00']);                       // ردیف قدیمی بدون detail
    $ins->execute([3, 5, '2026-03-20', 500, 0, 0, 200, json_encode([['side' => 'ar', 'ccy' => 'USD', 'delta' => 500]]), 'reversed', '2026-03-20 10:00:00']); // detail قدیمی (آرایهٔ items)
    $rules = pb_posting_rules();
    $voucher = function (string $type, int $id, bool $rev) use ($pdo, $rules) {
        $ctx = pb_fxr_load($pdo, 5, $id, $rev);
        $built = pb_posting_build($rules, $type, $ctx);
        return [$built, pb_posting_resolve($pdo, 5, $built, $ctx)];
    };
    [$bo, $lo] = $voucher('fx_reval', 1, false);
    $sd = array_sum(array_column($lo, 'debit')); $sc = array_sum(array_column($lo, 'credit'));
    $check('reval_balanced', $sd === $sc && $sd === 1070 + 300 + 400 + 50, "$sd vs $sc");
    $sig = fn(array $ls, int $acc) => array_values(array_map(fn($l) => [(int)$l['tafsili_id'], (int)$l['debit'], (int)$l['credit']], array_filter($ls, fn($l) => $l['account_id'] === $acc)));
    $check('reval_ar_lines_by_party', $sig($lo, 1) === [[11, 1000, 0], [0, 70, 0], [12, 0, 400]], json_encode($sig($lo, 1)));
    $check('reval_ap_lines_by_party', $sig($lo, 2) === [[13, 0, 300], [14, 50, 0]], json_encode($sig($lo, 2)));
    $check('reval_fx_unrealized_aggregate_no_tafsili', $sig($lo, 3) === [[0, 0, 1070], [0, 400, 0], [0, 300, 0], [0, 0, 50]], json_encode($sig($lo, 3)));
    // برگشت: جمع (بدهکار − بستانکار) هر حساب/تفصیلی بین دو سند = صفر
    [$br, $lr] = $voucher('fx_reval_rev', 1, true);
    $net = [];
    foreach (array_merge($lo, $lr) as $l) { $k = $l['account_id'] . ':' . $l['tafsili_id']; $net[$k] = ($net[$k] ?? 0) + $l['debit'] - $l['credit']; }
    $check('reval_rev_mirrors_to_zero', count(array_filter($net)) === 0 && count($lr) === count($lo), json_encode($net));
    $check('reval_rev_only_when_reversed', pb_fxr_load($pdo, 5, 1, true) !== null && $pdo->exec("UPDATE fx_revals SET status='active' WHERE id=1") === 1 && pb_fxr_load($pdo, 5, 1, true) === null);
    $pdo->exec("UPDATE fx_revals SET status='reversed' WHERE id=1");
    // ردیف‌های قدیمی: یک گروه بدون تفصیلی از ستون‌ها
    foreach ([2, 3] as $legacy) {
        [$b2, $l2] = $voucher('fx_reval', $legacy, false);
        $d2 = array_sum(array_column($l2, 'debit')); $c2 = array_sum(array_column($l2, 'credit'));
        $ok = $d2 === $c2 && $d2 > 0 && array_sum(array_column($l2, 'tafsili_id')) === 0 && $sig($l2, 1) === [[0, 500, 0]] && $sig($l2, 2) === [[0, 200, 0]];
        $check("reval_legacy_row_$legacy", $ok, json_encode($l2));
        [, $r2] = $voucher('fx_reval_rev', $legacy, true);
        $n2 = []; foreach (array_merge($l2, $r2) as $l) { $k = $l['account_id']; $n2[$k] = ($n2[$k] ?? 0) + $l['debit'] - $l['credit']; }
        $check("reval_legacy_rev_zero_$legacy", count(array_filter($n2)) === 0);
    }
    // --- حالت‌های لودر fx_reval_rev (مرحلهٔ ۵): posted / cancelled / none / pending ---
    $pdo->exec('ALTER TABLE fx_revals ADD COLUMN rev_date TEXT');
    $pdo->exec("ALTER TABLE fx_revals ADD COLUMN rev_status TEXT DEFAULT 'none'");
    $setRs = fn(int $id, string $status, string $rs, ?string $rd) => $pdo->prepare('UPDATE fx_revals SET status=?, rev_status=?, rev_date=? WHERE id=?')->execute([$status, $rs, $rd, $id]);
    $setRs(1, 'active', 'posted', '2026-03-21');
    $lp = pb_fxr_load($pdo, 5, 1, true);
    $check('rev_load_posted_date_and_desc', $lp !== null && $lp['date'] === '2026-03-21' && str_starts_with($lp['description'], 'برگشت خودکار تسعیر ارز #1'), json_encode($lp, JSON_UNESCAPED_UNICODE));
    $setRs(1, 'active', 'posted', null);
    $check('rev_load_posted_fallback_next_day', (pb_fxr_load($pdo, 5, 1, true)['date'] ?? '') === '2026-03-21');
    $setRs(1, 'reversed', 'cancelled', '2026-03-21');
    $pdo->exec("UPDATE fx_revals SET reversed_at='2026-05-01 09:00:00' WHERE id=1");
    $lc = pb_fxr_load($pdo, 5, 1, true);
    $check('rev_load_cancelled_dated_as_of', $lc !== null && $lc['date'] === '2026-03-20' && str_starts_with($lc['description'], 'ابطال تسعیر ارز #1'), json_encode($lc, JSON_UNESCAPED_UNICODE));
    $setRs(1, 'active', 'cancelled', null);
    $check('rev_load_cancelled_needs_reversed_status', pb_fxr_load($pdo, 5, 1, true) === null);
    $setRs(1, 'reversed', 'none', null);
    $ln = pb_fxr_load($pdo, 5, 1, true);
    $check('rev_load_legacy_dated_reversed_at', $ln !== null && $ln['date'] === '2026-05-01' && str_starts_with($ln['description'], 'برگشت تسعیر ارز #1'), json_encode($ln, JSON_UNESCAPED_UNICODE));
    $setRs(1, 'active', 'pending', '2026-03-21');
    $check('rev_load_pending_is_null', pb_fxr_load($pdo, 5, 1, true) === null);
    $setRs(1, 'reversed', 'posted', '2026-03-21');
    $check('rev_load_posted_ignores_status', pb_fxr_load($pdo, 5, 1, true) !== null);
    $check('orig_load_unchanged_by_rev_status', (pb_fxr_load($pdo, 5, 1, false)['date'] ?? '') === '2026-03-20');
    // سند برگشت خودکار هم‌ارزِ برگشت دستی است (همان خطوط قرینه)
    $setRs(1, 'active', 'posted', '2026-03-21');
    [, $lrp] = $voucher('fx_reval_rev', 1, true);
    [, $lo1] = $voucher('fx_reval', 1, false);
    $netp = []; foreach (array_merge($lo1, $lrp) as $l) { $k = $l['account_id'] . ':' . $l['tafsili_id']; $netp[$k] = ($netp[$k] ?? 0) + $l['debit'] - $l['credit']; }
    $check('rev_posted_mirrors_original_to_zero', count(array_filter($netp)) === 0 && count($lrp) === count($lo1));
    // --- pb_fxr_materialize: مسیرهای «ثبت نشود» (مسیر ثبت واقعی نیاز به MySQL/جدول سند دارد) ---
    $pdo->exec('CREATE TABLE fiscal_years (id INTEGER PRIMARY KEY, customer_id INT, start_date TEXT, end_date TEXT, status TEXT)');
    $cust = ['id' => 5, 'plan' => 'fx'];
    $pdo->exec("UPDATE fx_revals SET status='active', rev_status='pending', rev_date='2026-03-21' WHERE id=1");
    $pdo->exec("UPDATE fx_revals SET status='active', rev_status='pending', rev_date='2999-01-01' WHERE id=2");
    $pdo->exec("UPDATE fx_revals SET status='reversed', rev_status='cancelled', rev_date='2026-03-21' WHERE id=3");
    $check('materialize_no_fiscal_year_stays_pending', pb_fxr_materialize($pdo, $cust) === 0 && $pdo->query('SELECT rev_status FROM fx_revals WHERE id=1')->fetchColumn() === 'pending');
    $pdo->exec("INSERT INTO fiscal_years VALUES (1, 5, '2026-03-21', '2027-03-20', 'closed')");
    $check('materialize_closed_year_stays_pending', pb_fxr_materialize($pdo, $cust) === 0 && $pdo->query('SELECT rev_status FROM fx_revals WHERE id=1')->fetchColumn() === 'pending');
    $check('materialize_future_and_cancelled_untouched', $pdo->query('SELECT rev_status FROM fx_revals WHERE id=2')->fetchColumn() === 'pending' && $pdo->query('SELECT rev_status FROM fx_revals WHERE id=3')->fetchColumn() === 'cancelled');
    $check('materialize_non_fx_plan_noop', pb_fxr_materialize($pdo, ['id' => 5, 'plan' => 'basic']) === 0);
    $check('materialize_rev_plan_db_open_year', (function () use ($pdo, $cust) { $pdo->exec("UPDATE fiscal_years SET status='open'"); return pb_fxr_rev_plan_db($pdo, 5, $cust, '2026-03-20') === ['rev_date' => '2026-03-21', 'action' => 'post', 'reason' => 'ok']; })());
    $pdo->exec('CREATE TABLE app_settings (customer_id INT, k TEXT, v TEXT)');
    $pdo->exec("INSERT INTO app_settings VALUES (5, 'period.lock_through', '2026-03-21')");
    $check('rev_plan_db_period_lock', pb_fxr_rev_plan_db($pdo, 5, $cust, '2026-03-20') === ['rev_date' => '2026-03-21', 'action' => 'pending', 'reason' => 'period_locked']);
    $check('materialize_locked_period_stays_pending', pb_fxr_materialize($pdo, $cust) === 0 && $pdo->query('SELECT rev_status FROM fx_revals WHERE id=1')->fetchColumn() === 'pending');
    $check('materialize_quiet_never_throws', (function () use ($pdo) { try { pb_fxr_materialize_quiet($pdo, ['id' => 5, 'plan' => 'fx']); return true; } catch (Throwable $e) { return false; } })());
    // --- pb_fxr_detail (sqlite: ستون‌های کمتر ⇒ فیلدهای اختیاری null) ---
    $pdo->exec("UPDATE fx_revals SET status='active', rev_status='posted' WHERE id=1");
    $d1 = pb_fxr_detail($pdo, 5, 1);
    $check('detail_posted_not_stale_and_decoded', $d1['stale'] === false && $d1['rev_status'] === 'posted' && is_array($d1['detail']) && $d1['detail']['fingerprint'] === 'x' && $d1['note'] === null, json_encode($d1, JSON_UNESCAPED_UNICODE));
    $pdo->exec("UPDATE fx_revals SET status='reversed', rev_status='cancelled' WHERE id=1");
    $check('detail_reversed_not_stale', pb_fxr_detail($pdo, 5, 1)['stale'] === false);
    $pdo->exec("UPDATE fx_revals SET status='active', rev_status='pending' WHERE id=1");
    $check('detail_active_uncomputable_is_stale', pb_fxr_detail($pdo, 5, 1)['stale'] === true); // سایر جدول‌ها در این sqlite نیستند ⇒ بازمحاسبه ممکن نیست
    $check('detail_legacy_rows', pb_fxr_detail($pdo, 5, 2)['detail'] === null && is_array(pb_fxr_detail($pdo, 5, 3)['detail']) && pb_fxr_detail($pdo, 5, 3)['stale'] === false); // بدون detail ⇒ null؛ آرایهٔ items قدیمی همان‌طور برمی‌گردد؛ بدون fingerprint ⇒ stale=false
    $check('detail_not_found_404', (function () use ($pdo) { try { pb_fxr_detail($pdo, 5, 999); return false; } catch (RuntimeException $e) { return $e->getMessage() === 'تسعیر یافت نشد'; } })());
    $pdo->exec("UPDATE fx_revals SET status='reversed', rev_status='none' WHERE id=1"); // محیط را برای رگرسیون پایین دست‌نخورده نگه می‌داریم

    // رگرسیون: قاعدهٔ واقعی بدون each (fx_settle_recv) با ctx ثابت
    $sr = pb_posting_build($rules, 'fx_settle_recv', ['fields' => ['carry' => 1000, 'cash' => 600, 'bank' => 500], 'dims' => ['party' => 7]]);
    $srExp = [
        ['role' => 'cash', 'debit' => 600, 'credit' => 0, 'tafsili_key' => null, 'cc_key' => null, 'desc' => '', 'acct_dim' => null],
        ['role' => 'bank', 'debit' => 500, 'credit' => 0, 'tafsili_key' => null, 'cc_key' => null, 'desc' => '', 'acct_dim' => null],
        ['role' => 'ar', 'debit' => 0, 'credit' => 1000, 'tafsili_key' => 'party', 'cc_key' => null, 'desc' => 'تسویه مطالبهٔ ارزی به مبلغ دفتری', 'acct_dim' => null],
        ['role' => 'fx_diff', 'debit' => 0, 'credit' => 100, 'tafsili_key' => null, 'cc_key' => null, 'desc' => 'سود تفاوت نرخ ارز (تسویه)', 'acct_dim' => null]];
    $check('regression_fx_settle_recv', $sr === $srExp, json_encode($sr, JSON_UNESCAPED_UNICODE));
    $srl = pb_posting_resolve($pdo, 5, $sr, ['dims' => ['party' => 7]]);
    $check('regression_fx_settle_recv_resolve', array_column($srl, 'tafsili_id') === [0, 0, 7, 0]);
} else {
    echo "SKIP fx_reval voucher tests (pdo_sqlite missing)\n";
}

/* ================= انتخاب جداگانهٔ فاکتور (doc_keys): توابع خالص ================= */
$selDocs = [
    ['side' => 'ar', 'id' => 1, 'ref' => '#1', 'party' => 'الف', 'ccy' => 'USD', 'fx_rate' => 500000.0, 'amt' => 100.0, 'date' => '2026-01-01'],
    ['side' => 'ar', 'id' => 2, 'ref' => '#2', 'party' => 'ب', 'ccy' => 'USD', 'fx_rate' => 520000.0, 'amt' => 50.0, 'date' => '2026-01-02'],
    ['side' => 'ap', 'id' => 1, 'ref' => 'P1', 'party' => 'ج', 'ccy' => 'EUR', 'fx_rate' => 600000.0, 'amt' => 10.0, 'date' => '2026-01-03'],
    ['side' => 'ar', 'id' => 3, 'ref' => '#3', 'party' => '', 'ccy' => 'USD', 'fx_rate' => 500000.0, 'amt' => 20.0, 'date' => '2026-01-04'],
];
$selPaid = ['ar:3' => 20.0, 'ar:2' => 10.0];
$check('keys_null_is_null', pb_fxr_parse_keys(null) === null);
$check('keys_dedup', pb_fxr_parse_keys(['ar:1', 'ar:1', 'ap:2']) === ['ar:1', 'ap:2']);
$check('keys_empty_list', pb_fxr_parse_keys([]) === []);
foreach ([['x:1'], ['ar:0'], ['ar:1a'], ['ar:-1'], [5], [['ar:1']], 'ar:1', ['ar:01']] as $i => $bad) {
    $thrown = false; try { pb_fxr_parse_keys($bad); } catch (InvalidArgumentException $e) { $thrown = true; }
    $check('keys_invalid_' . $i, $thrown);
}
$ids = fn(array $r) => array_map(fn($d) => $d['side'] . ':' . $d['id'], $r);
$check('filter_default_all', $ids(pb_fxr_filter_docs($selDocs, null, [])) === ['ar:1', 'ar:2', 'ap:1', 'ar:3']);
$check('filter_default_skips_covered', $ids(pb_fxr_filter_docs($selDocs, null, ['ar:1' => 9])) === ['ar:2', 'ap:1', 'ar:3']);
$check('filter_explicit', $ids(pb_fxr_filter_docs($selDocs, ['ap:1', 'ar:2'], [])) === ['ar:2', 'ap:1']);
$check('filter_explicit_ignores_covered', $ids(pb_fxr_filter_docs($selDocs, ['ar:1'], ['ar:1' => 9])) === ['ar:1']);
$check('filter_explicit_empty', pb_fxr_filter_docs($selDocs, [], []) === []);
// selecting a subset changes totals and fingerprint, and equals building from just those invoices
$full = pb_fxr_build($selDocs, $selPaid, ['USD' => 510000.0, 'EUR' => 650000.0]);
$sub = pb_fxr_build(pb_fxr_filter_docs($selDocs, ['ar:1'], []), $selPaid, ['USD' => 510000.0]);
$check('subset_totals', $sub['doc_count'] === 1 && $sub['ar_up'] === 1000000 && $sub['ar_down'] === 0 && $sub['ap_up'] === 0 && $sub['net_delta'] === 1000000, json_encode($sub, JSON_UNESCAPED_UNICODE));
$check('subset_fingerprint_differs', $sub['fingerprint'] !== $full['fingerprint']);
$check('subset_party_only_selected', array_column($sub['parties'], 'party') === ['الف']);
// candidates: open only (ar:3 fully paid), rate/delta null for a currency without rate, covered flags
$cd = pb_fxr_candidates($selDocs, $selPaid, ['USD' => 510000.0], ['ar:1' => 7]);
$check('cand_open_only', array_column($cd, 'key') === ['ap:1', 'ar:1', 'ar:2'], json_encode(array_column($cd, 'key')));
$byKey = []; foreach ($cd as $x) $byKey[$x['key']] = $x;
$check('cand_missing_rate', $byKey['ap:1']['rate'] === null && $byKey['ap:1']['delta'] === null && $byKey['ap:1']['rate_missing'] === true);
$check('cand_delta', $byKey['ar:1']['delta'] === 1000000 && $byKey['ar:2']['rem_ccy'] === 40.0 && $byKey['ar:2']['delta'] === -400000);
$check('cand_covered_flags', $byKey['ar:1']['covered_by'] === 7 && $byKey['ar:2']['covered_by'] === null);
$check('cand_legacy_all_covered', array_unique(array_column(pb_fxr_candidates($selDocs, $selPaid, ['USD' => 1.0, 'EUR' => 1.0], [], 5), 'covered_by')) === [5]);
$check('cand_no_party_label', array_column(pb_fxr_candidates([['side' => 'ar', 'id' => 9, 'ref' => '#9', 'party' => ' ', 'ccy' => 'USD', 'fx_rate' => 1.0, 'amt' => 1.0, 'date' => '']], [], ['USD' => 2.0], []), 'party') === [PB_FXR_NO_PARTY]);
// covered rows
$cv = pb_fxr_covered_from_rows([
    ['id' => 4, 'detail' => ['docs' => [['side' => 'ar', 'doc_id' => 1], ['side' => 'ap', 'doc_id' => 1]], 'items' => [1]]],
    ['id' => 6, 'detail' => ['docs' => [['side' => 'ar', 'doc_id' => 1], ['side' => 'ar', 'doc_id' => 2]]]],
]);
$check('covered_union_first_wins', $cv['keys'] === ['ar:1' => 4, 'ap:1' => 4, 'ar:2' => 6] && $cv['all'] === null);
$check('covered_legacy_null_detail', pb_fxr_covered_from_rows([['id' => 3, 'detail' => null]])['all'] === 3);
$check('covered_legacy_items_array', pb_fxr_covered_from_rows([['id' => 3, 'detail' => [['side' => 'ar', 'ccy' => 'USD', 'delta' => 5]]]])['all'] === 3);
$check('covered_none', pb_fxr_covered_from_rows([]) === ['keys' => [], 'all' => null]);
$check('stored_keys', pb_fxr_stored_keys(['docs' => [['side' => 'ar', 'doc_id' => 5], ['side' => 'ap', 'doc_id' => 2]]]) === ['ar:5', 'ap:2']);
$check('stored_keys_legacy', pb_fxr_stored_keys(null) === null && pb_fxr_stored_keys([['ccy' => 'USD']]) === null && pb_fxr_stored_keys(['docs' => []]) === null);
// validate: explicit selection overlapping an active revaluation => duplicate; clean selection while another doc is revalued => ok
$vbase = ['as_of' => '2026-03-20', 'today' => '2026-11-17', 'fy' => ['ok' => true, 'status' => 'open', 'start' => '2026-03-21', 'end' => '2027-03-20'], 'lock_through' => '', 'missing_ccys' => [], 'doc_count' => 2];
$vbase['fy'] = ['ok' => true, 'status' => 'open', 'start' => '2025-03-21', 'end' => '2026-03-20'];
$check('validate_selection_disjoint_ok', pb_fxr_validate($vbase + ['has_active' => false])['ok'] === true);
$check('validate_selection_overlap_duplicate', array_column(pb_fxr_validate($vbase + ['has_active' => true])['problems'], 'code') === ['duplicate']);

echo $fail === 0 ? "ALL OK\n" : "FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
