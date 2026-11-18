<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S30.T30.2 — period-end revaluation of open foreign-currency receivables/payables (GOLD + fx plan, DECISIONS B10).
 * Actions (permission accounting.post; apply/reverse have own keys since step 6): accounting.fx_reval.preview {as_of} | .apply {as_of} | .reverse {id} | .list | .check {as_of} | .detail {id}
 * Exposure = per foreign sale (receivable) / purchase (payable): remaining_ccy = document ccy total - paid same-ccy lump lines (<= as_of).
 * Delta = remaining_ccy * (rate at as_of - booked document rate), rial integers. Step 2: pure pb_fxr_build() (per-invoice docs, per-party
 * netting by side, ar_up/ar_down/ap_up/ap_down = sums of party up/down, doc_count, net_delta, fingerprint) + DB layer pb_fxr_compute();
 * reversal posts rule fx_reval_rev (swapped sides) — the original voucher stays.
 * Step 5: AUTO reversal. rev_date = as_of + 1 day; pb_fxr_rev_plan() (pure) says post|pending. apply posts fx_reval_rev at rev_date in the same transaction
 * (rev_status='posted') or stores rev_status='pending'; pb_fxr_materialize() posts the pending ones once the plan allows (called at the start of every fx_reval action,
 * after fy.open_next and after period_lock.reopen). Manual reverse (cancel) = fx_reval_rev dated as_of, status='reversed', rev_status='cancelled'; 409 when already 'posted'.
 * Legacy rows (rev_status='none'): manual reverse keeps the old behaviour (voucher dated reversed_at).
 * Step 6: read-only actions accounting.fx_reval.check {as_of} and .detail {id} (permission accounting.post); apply/reverse need their own keys
 * accounting.fx_reval.apply / accounting.fx_reval.reverse (permissions.php; v74 gave them to every role that had accounting.post).
 * Per-invoice selection (post-step-15): preview/check/apply accept optional doc_keys ["ar:12","ap:7"]; .docs lists every open invoice (key, rem, rate, delta, covered_by).
 *   No doc_keys = all open invoices NOT already part of an active revaluation of that as_of. Several revaluations per as_of are allowed as long as their invoices are disjoint
 *   (cancel one => its invoices are selectable again). Stale/fingerprint, year-end warning and the apply race guard all work per selection.
 * SIGNOFF: cheque/installment lines are not treated as settlements (stay in the open amount); the voucher has no party tafsili (control-account level);
 * rate = latest fx_rates row <= as_of (rate_missing 422 when absent).
 */
require_once __DIR__ . '/fx_documents.php';

function pb_fxr_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS fx_revals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        as_of DATE NOT NULL,
        ar_up BIGINT NOT NULL DEFAULT 0, ar_down BIGINT NOT NULL DEFAULT 0,
        ap_up BIGINT NOT NULL DEFAULT 0, ap_down BIGINT NOT NULL DEFAULT 0,
        detail TEXT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        created_by VARCHAR(120) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reversed_at DATETIME NULL,
        KEY idx_fxrv_customer (customer_id, as_of)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // v73 (fx-reval pro): additive columns for tables created before. rev_status: none|pending|posted|cancelled (none = legacy row, no auto reversal).
    static $colsDone = false;
    if ($colsDone) return;
    $cols = [
        'rev_date'    => 'DATE NULL',
        'rev_status'  => "VARCHAR(10) NOT NULL DEFAULT 'none'",
        'doc_count'   => 'INT NOT NULL DEFAULT 0',
        'net_delta'   => 'BIGINT NOT NULL DEFAULT 0',
        'note'        => 'VARCHAR(255) NULL',
        'reversed_by' => 'VARCHAR(120) NULL',
    ];
    foreach ($cols as $c => $def) {
        $st = $pdo->prepare('SHOW COLUMNS FROM `fx_revals` LIKE ?');
        $st->execute([$c]);
        if (!$st->fetch()) $pdo->exec("ALTER TABLE `fx_revals` ADD COLUMN `$c` $def");
    }
    $colsDone = true;
}

/** Pure: split a signed delta into [up, down] (both >= 0). */
function pb_fxr_split(int $delta): array { return $delta >= 0 ? [$delta, 0] : [0, -$delta]; }

/** Label of the group used when an invoice has no counterparty name. */
const PB_FXR_NO_PARTY = 'بدون طرف‌حساب';

/**
 * Pure (no DB): build the revaluation figures from already-loaded rows.
 *
 * UNIT NOTE (why delta is NOT multiplied by pb_posting_unit_mult()): the app stores money in toman and the voucher is
 * integer rials, so stored amounts need the unit multiplier. Here the amount is a FOREIGN-currency quantity (rem_ccy)
 * and the rates (fx_rates.rate, documents' fx_rate) are already rials per unit of that currency, so
 * rem_ccy x (rate - booked_rate) is directly rials. A further x10 would overstate the voucher tenfold.
 *
 * @param array $docs  rows: side('ar'|'ap'), id, ref, party, ccy, fx_rate (booked rial/unit), amt (total in ccy), date
 * @param array $paid  "side:id" => same-currency amount already settled (<= as_of)
 * @param array $rates ccy => rate at as_of (rial per unit); a ccy of an open doc must be present (else InvalidArgumentException)
 */
function pb_fxr_build(array $docs, array $paid, array $rates): array {
    $outDocs = []; $items = []; $parties = [];
    foreach ($docs as $d) {
        $side = (string)$d['side']; $id = (int)$d['id'];
        $total = (float)$d['amt'];
        $paidCcy = (float)($paid[$side . ':' . $id] ?? 0.0);
        $rem = round($total - $paidCcy, 4);
        if ($rem <= 0) continue;
        $ccy = (string)$d['ccy'];
        if (!isset($rates[$ccy])) throw new InvalidArgumentException('rate_missing:' . $ccy);
        $rate = (float)$rates[$ccy];
        $booked = (float)$d['fx_rate'];
        $delta = (int)round($rem * ($rate - $booked));
        $party = trim((string)($d['party'] ?? ''));
        $noParty = $party === '';
        $label = $noParty ? PB_FXR_NO_PARTY : $party;
        $outDocs[] = ['side' => $side, 'doc_id' => $id, 'ref' => (string)($d['ref'] ?? ''), 'party' => $label, 'ccy' => $ccy,
            'booked_rate' => $booked, 'total_ccy' => round($total, 4), 'paid_ccy' => round($paidCcy, 4), 'rem_ccy' => $rem, 'rate' => $rate, 'delta' => $delta];
        $k = $side . ':' . $ccy;
        if (!isset($items[$k])) $items[$k] = ['side' => $side, 'ccy' => $ccy, 'rate' => $rate, 'open_ccy' => 0.0, 'delta' => 0];
        $items[$k]['open_ccy'] = round($items[$k]['open_ccy'] + $rem, 4);
        $items[$k]['delta'] += $delta;
        $pk = $side . "\x1f" . $label;
        if (!isset($parties[$pk])) $parties[$pk] = ['party' => $label, 'side' => $side, 'no_party' => $noParty, 'delta' => 0];
        $parties[$pk]['delta'] += $delta;
    }
    usort($outDocs, fn($a, $b) => [$a['side'], $a['doc_id']] <=> [$b['side'], $b['doc_id']]);
    $partyOut = []; $tot = ['ar' => ['up' => 0, 'down' => 0], 'ap' => ['up' => 0, 'down' => 0]];
    $arNet = 0; $apNet = 0;
    ksort($parties, SORT_STRING);
    foreach ($parties as $p) {
        [$up, $down] = pb_fxr_split($p['delta']);
        $partyOut[] = ['party' => $p['party'], 'side' => $p['side'], 'no_party' => $p['no_party'], 'up' => $up, 'down' => $down];
        $tot[$p['side']]['up'] += $up; $tot[$p['side']]['down'] += $down;
        if ($p['side'] === 'ar') $arNet += $p['delta']; else $apNet += $p['delta'];
    }
    $fp = [];
    foreach ($outDocs as $d) $fp[] = [$d['side'], $d['doc_id'], sprintf('%.4f', $d['rem_ccy']), sprintf('%.6F', $d['rate'])];
    return ['items' => array_values($items), 'docs' => $outDocs, 'parties' => $partyOut,
        'ar_up' => $tot['ar']['up'], 'ar_down' => $tot['ar']['down'], 'ap_up' => $tot['ap']['up'], 'ap_down' => $tot['ap']['down'],
        'doc_count' => count($outDocs),
        'net_delta' => $arNet - $apNet, // receivable gain is profit; payable "up" is a loss
        'fingerprint' => sha1(json_encode($fp))];
}

/** DB: open-invoice rows + settled amounts as of $asOf. @return array{0:array,1:array} [docs, paid] */
function pb_fxr_fetch(PDO $pdo, int $cid, string $asOf): array {
    $sides = [
        'ar' => ["SELECT id, CONCAT('#', id) AS ref, buyer AS party, ccy, fx_rate, total_ccy AS amt, date FROM sales WHERE customer_id=? AND ccy IS NOT NULL AND fx_rate IS NOT NULL AND date<=?",
                 "SELECT h.sale_id AS did, SUM(t.ccy_amount) AS s FROM receipt_lump_tx t JOIN receipts h ON h.id=t.receipt_id AND h.customer_id=t.customer_id
                  JOIN sales d ON d.id=h.sale_id AND d.customer_id=t.customer_id WHERE t.customer_id=? AND t.paid=1 AND t.ccy=d.ccy AND t.date<=? GROUP BY h.sale_id"],
        'ap' => ["SELECT id, COALESCE(NULLIF(code, ''), CONCAT('#', id)) AS ref, seller AS party, ccy, fx_rate, price_ccy * net_weight AS amt, date FROM purchases WHERE customer_id=? AND ccy IS NOT NULL AND fx_rate IS NOT NULL AND date<=?",
                 "SELECT h.purchase_id AS did, SUM(t.ccy_amount) AS s FROM lump_tx t JOIN payments h ON h.id=t.payment_id AND h.customer_id=t.customer_id
                  JOIN purchases d ON d.id=h.purchase_id AND d.customer_id=t.customer_id WHERE t.customer_id=? AND t.paid=1 AND t.ccy=d.ccy AND t.date<=? GROUP BY h.purchase_id"],
    ];
    $docs = []; $paid = [];
    foreach ($sides as $side => [$docSql, $paidSql]) {
        $q = $pdo->prepare($paidSql); $q->execute([$cid, $asOf]);
        foreach ($q->fetchAll() as $r) $paid[$side . ':' . (int)$r['did']] = (float)$r['s'];
        $q = $pdo->prepare($docSql); $q->execute([$cid, $asOf]);
        foreach ($q->fetchAll() as $d) $docs[] = ['side' => $side, 'id' => (int)$d['id'], 'ref' => (string)$d['ref'], 'party' => $d['party'],
            'ccy' => (string)$d['ccy'], 'fx_rate' => (float)$d['fx_rate'], 'amt' => (float)$d['amt'], 'date' => (string)$d['date']];
    }
    return [$docs, $paid];
}

/** Rate row (rate + its date) on/before $asOf, or null. */
function pb_fxr_rate_row(PDO $pdo, int $cid, string $ccy, string $asOf): ?array {
    $st = $pdo->prepare('SELECT rate, rate_date FROM fx_rates WHERE customer_id=? AND ccy=? AND rate_date<=? ORDER BY rate_date DESC LIMIT 1');
    $st->execute([$cid, $ccy, $asOf]);
    $r = $st->fetch();
    return $r ? ['rate' => (float)$r['rate'], 'rate_date' => (string)$r['rate_date']] : null;
}

/** Currencies that still have an open amount (rem > 0), sorted. */
function pb_fxr_open_ccys(array $docs, array $paid): array {
    $c = [];
    foreach ($docs as $d) if (round((float)$d['amt'] - (float)($paid[$d['side'] . ':' . (int)$d['id']] ?? 0.0), 4) > 0) $c[(string)$d['ccy']] = true;
    $c = array_keys($c); sort($c);
    return $c;
}

/** @return array<string,array{rate?:float,rate_date?:string,missing:bool}> per currency having an open invoice. */
function pb_fxr_rates_for(PDO $pdo, int $cid, string $asOf, array $ccys): array {
    $out = [];
    foreach ($ccys as $ccy) {
        $r = pb_fxr_rate_row($pdo, $cid, $ccy, $asOf);
        $out[$ccy] = $r === null ? ['missing' => true] : ['rate' => $r['rate'], 'rate_date' => $r['rate_date'], 'missing' => false];
    }
    return $out;
}

function pb_fxr_rates_check(PDO $pdo, int $cid, string $asOf): array {
    [$docs, $paid] = pb_fxr_fetch($pdo, $cid, $asOf);
    return pb_fxr_rates_for($pdo, $cid, $asOf, pb_fxr_open_ccys($docs, $paid));
}

/** Pure: key of one open invoice as used by the per-invoice selection: "ar:12" / "ap:7". */
function pb_fxr_doc_key(string $side, int $id): string { return $side . ':' . $id; }

/**
 * Pure: normalize the client's `doc_keys`. null (absent) => null = "no explicit selection"; otherwise a de-duplicated list of valid "ar|ap:id" keys.
 * A malformed entry makes the whole request invalid (InvalidArgumentException) rather than being silently dropped.
 * @param mixed $raw
 * @return string[]|null
 */
function pb_fxr_parse_keys($raw): ?array {
    if ($raw === null) return null;
    if (!is_array($raw)) throw new InvalidArgumentException('bad_keys');
    $out = [];
    foreach ($raw as $k) {
        $k = is_string($k) ? trim($k) : '';
        if (!preg_match('/^(ar|ap):([1-9]\d{0,18})$/', $k)) throw new InvalidArgumentException('bad_keys');
        $out[$k] = true;
    }
    return array_keys($out);
}

/**
 * Pure: which open invoices take part. $keys null => every open invoice not in $covered; $keys list => exactly those (still dropping nothing:
 * validation of "is it open / already covered" belongs to pb_fxr_gather, so callers such as the stale check can re-evaluate a stored selection).
 * @param array $docs rows from pb_fxr_fetch; $covered "side:id" => reval id
 */
function pb_fxr_filter_docs(array $docs, ?array $keys, array $covered): array {
    $out = [];
    $want = $keys === null ? null : array_flip($keys);
    foreach ($docs as $d) {
        $k = pb_fxr_doc_key((string)$d['side'], (int)$d['id']);
        if ($want === null) { if (isset($covered[$k])) continue; }
        elseif (!isset($want[$k])) continue;
        $out[] = $d;
    }
    return $out;
}

/**
 * Pure: one row per OPEN invoice (rem > 0) for the selection list. rate/delta are null when the currency has no rate yet (still listed, not an error).
 * covered_by = id of the active revaluation (same as_of) that already includes the invoice, else null; $coveredAll = id of a legacy active row (no per-invoice detail) that covers everything.
 * @param array $rates ccy => rate (may lack currencies)
 */
function pb_fxr_candidates(array $docs, array $paid, array $rates, array $covered, ?int $coveredAll = null): array {
    $out = [];
    foreach ($docs as $d) {
        $side = (string)$d['side']; $id = (int)$d['id'];
        $paidCcy = (float)($paid[$side . ':' . $id] ?? 0.0);
        $rem = round((float)$d['amt'] - $paidCcy, 4);
        if ($rem <= 0) continue;
        $ccy = (string)$d['ccy'];
        $has = isset($rates[$ccy]);
        $party = trim((string)($d['party'] ?? ''));
        $out[] = ['key' => pb_fxr_doc_key($side, $id), 'side' => $side, 'doc_id' => $id, 'ref' => (string)($d['ref'] ?? ''),
            'party' => $party === '' ? PB_FXR_NO_PARTY : $party, 'ccy' => $ccy, 'date' => (string)($d['date'] ?? ''),
            'booked_rate' => (float)$d['fx_rate'], 'rem_ccy' => $rem,
            'rate' => $has ? (float)$rates[$ccy] : null,
            'delta' => $has ? (int)round($rem * ((float)$rates[$ccy] - (float)$d['fx_rate'])) : null,
            'rate_missing' => !$has,
            'covered_by' => $covered[pb_fxr_doc_key($side, $id)] ?? $coveredAll];
    }
    usort($out, fn($a, $b) => [$a['ccy'], $a['side'], $a['doc_id']] <=> [$b['ccy'], $b['side'], $b['doc_id']]);
    return $out;
}

/**
 * Pure: from the stored detail of ACTIVE revaluations at one as_of, which invoices are already revalued.
 * @param array $rows each {id, detail(decoded array|null)}; a row without a per-invoice docs list (legacy) covers everything
 * @return array{keys:array<string,int>, all:?int} keys "side:id" => reval id; all = id of a legacy row or null
 */
function pb_fxr_covered_from_rows(array $rows): array {
    $keys = []; $all = null;
    foreach ($rows as $r) {
        $d = $r['detail'] ?? null;
        $docs = is_array($d) && isset($d['docs']) && is_array($d['docs']) ? $d['docs'] : null;
        if ($docs === null || ($docs === [] && empty($d['items']))) { $all = $all ?? (int)$r['id']; continue; }
        foreach ($docs as $x) {
            if (!is_array($x) || !isset($x['side'], $x['doc_id'])) continue;
            $k = pb_fxr_doc_key((string)$x['side'], (int)$x['doc_id']);
            $keys[$k] = $keys[$k] ?? (int)$r['id'];
        }
    }
    return ['keys' => $keys, 'all' => $all];
}

/** DB: active revaluations at $asOf and what they cover. $lock => FOR UPDATE (inside the apply transaction, serializes concurrent applies). */
function pb_fxr_covered(PDO $pdo, int $cid, string $asOf, bool $lock = false): array {
    $st = $pdo->prepare("SELECT id, detail FROM fx_revals WHERE customer_id=? AND as_of=? AND status='active' ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$cid, $asOf]);
    $rows = [];
    foreach ($st->fetchAll() as $r) $rows[] = ['id' => (int)$r['id'], 'detail' => json_decode((string)($r['detail'] ?? ''), true)];
    return pb_fxr_covered_from_rows($rows) + ['ids' => array_map(fn($r) => $r['id'], $rows)];
}

/**
 * @param bool $soft true => throw RuntimeException('rate_missing') instead of out(422) (for internal callers such as the year-close warning)
 * @param string[]|null $keys explicit invoice selection ("ar:12"); null => every open invoice that is not already part of an active revaluation at $asOf
 *                      (a legacy active row without per-invoice detail covers all of them). Rates are required only for the currencies of the selected invoices.
 * @param bool $ignoreCovered true => with $keys === null do NOT exclude already-covered invoices (stale check of a whole stored revaluation)
 * @return array{as_of:string, items:array, docs:array, parties:array, rates:array, ar_up:int, ar_down:int, ap_up:int, ap_down:int, doc_count:int, net_delta:int, fingerprint:string}
 */
function pb_fxr_compute(PDO $pdo, int $cid, string $asOf, bool $soft = false, ?array $keys = null, bool $ignoreCovered = false): array {
    [$docs, $paid] = pb_fxr_fetch($pdo, $cid, $asOf);
    $cov = ($keys === null && !$ignoreCovered) ? pb_fxr_covered($pdo, $cid, $asOf) : ['keys' => [], 'all' => null];
    if ($cov['all'] !== null) $docs = []; // a legacy full revaluation already covers every invoice
    else $docs = pb_fxr_filter_docs($docs, $keys, $cov['keys']);
    $rates = pb_fxr_rates_for($pdo, $cid, $asOf, pb_fxr_open_ccys($docs, $paid));
    $missing = [];
    foreach ($rates as $ccy => $r) if ($r['missing']) $missing[] = $ccy;
    if ($missing) {
        if ($soft) throw new RuntimeException('rate_missing');
        out(['error' => 'rate_missing', 'ccy' => $missing[0], 'ccys' => $missing,
            'message' => 'نرخ ارز ' . implode('، ', $missing) . ' تا تاریخ تسعیر ثبت نشده است'], 422);
    }
    $plain = [];
    foreach ($rates as $ccy => $r) $plain[$ccy] = $r['rate'];
    return ['as_of' => $asOf] + pb_fxr_build($docs, $paid, $plain) + ['rates' => $rates];
}

function pb_fxr_date(array $p): string {
    $d = (string)($p['as_of'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) bad('تاریخ تسعیر نامعتبر است');
    return $d;
}

/** Pure: the day after $asOf (Y-m-d, calendar arithmetic in UTC so DST/timezone cannot shift it). */
function pb_fxr_next_day(string $asOf): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $asOf, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new InvalidArgumentException('bad_date');
    return (new DateTimeImmutable($asOf, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
}

/**
 * Pure: when/how is the automatic reversal of a revaluation at $asOf posted?
 * rev_date = as_of + 1 day. action 'post' only when rev_date lies in an OPEN fiscal year ($fyOfRevDate = that year's row or null when none)
 * and is after the last period lock ($lockedThrough = Y-m-d or ''/null); otherwise 'pending' (materialized later by pb_fxr_materialize).
 * @param array|null $fyOfRevDate row with at least 'status' ('open'|'closed'|'locked'...), null = no fiscal year contains rev_date
 * @return array{rev_date:string, action:string, reason:string} reason: ok | no_fiscal_year | fy_not_open | period_locked
 */
function pb_fxr_rev_plan(string $asOf, ?array $fyOfRevDate, ?string $lockedThrough): array {
    $rev = pb_fxr_next_day($asOf);
    if ($fyOfRevDate === null) return ['rev_date' => $rev, 'action' => 'pending', 'reason' => 'no_fiscal_year'];
    if ((string)($fyOfRevDate['status'] ?? '') !== 'open') return ['rev_date' => $rev, 'action' => 'pending', 'reason' => 'fy_not_open'];
    if ($lockedThrough !== null && $lockedThrough !== '' && $rev <= $lockedThrough) return ['rev_date' => $rev, 'action' => 'pending', 'reason' => 'period_locked'];
    return ['rev_date' => $rev, 'action' => 'post', 'reason' => 'ok'];
}

/** DB glue for pb_fxr_rev_plan: fiscal year of rev_date (a year the caller may not access counts as not open) + period lock. */
function pb_fxr_rev_plan_db(PDO $pdo, int $cid, array $customer, string $asOf): array {
    $rev = pb_fxr_next_day($asOf);
    $st = $pdo->prepare('SELECT id, status FROM fiscal_years WHERE customer_id=? AND start_date<=? AND end_date>=? LIMIT 1');
    $st->execute([$cid, $rev, $rev]);
    $fy = $st->fetch();
    if (!$fy) $fy = null;
    elseif (function_exists('pb_fy_access') && !pb_fy_access($customer, (int)$fy['id'])) $fy = ['status' => 'forbidden'];
    return pb_fxr_rev_plan($asOf, $fy, function_exists('pb_pl_through') ? pb_pl_through($pdo, $cid) : '');
}

/**
 * Posts every pending automatic reversal that is due (status='active', rev_status='pending', rev_date <= today) and now allowed by the plan.
 * Idempotent: each row flips pending->posted with a conditional UPDATE (row lock) inside its own transaction together with the voucher, so a
 * concurrent run cannot post twice; a failure (guard error etc.) rolls that row back and leaves it pending. Never throws.
 * @return int number of reversals posted
 */
function pb_fxr_materialize(PDO $pdo, array $customer): int {
    if ((string)($customer['plan'] ?? '') !== 'fx') return 0;
    if (function_exists('pb_fy_is_gold') && !pb_fy_is_gold($customer)) return 0;
    if ($pdo->inTransaction()) return 0; // never join a caller's transaction
    $cid = (int)$customer['id'];
    try {
        $st = $pdo->prepare("SELECT id, as_of FROM fx_revals WHERE customer_id=? AND status='active' AND rev_status='pending' AND rev_date<=? ORDER BY rev_date, id");
        $st->execute([$cid, date('Y-m-d')]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) { return 0; } // table/columns not there yet
    if (!$rows) return 0;
    $had = array_key_exists('pb_out_throw', $GLOBALS); $prev = $GLOBALS['pb_out_throw'] ?? null;
    $GLOBALS['pb_out_throw'] = true; // bad()/out() inside the voucher engine throw (PbOutError) instead of exiting
    $n = 0;
    try {
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            try {
                if (pb_fxr_rev_plan_db($pdo, $cid, $customer, (string)$r['as_of'])['action'] !== 'post') continue;
                $pdo->beginTransaction();
                $u = $pdo->prepare("UPDATE fx_revals SET rev_status='posted' WHERE id=? AND customer_id=? AND status='active' AND rev_status='pending'");
                $u->execute([$id, $cid]);
                if ($u->rowCount() !== 1) { $pdo->rollBack(); continue; } // someone else posted/cancelled it
                if (pb_post_document($pdo, $customer, 'fx_reval_rev', $id) <= 0) { $pdo->rollBack(); continue; }
                $pdo->commit();
                $n++;
                try { pb_audit($pdo, $customer, 'fx.reval_auto_reverse', 'fx_reval', (string)$id, 'برگشت خودکار تسعیر ارز #' . $id, null, null); } catch (Throwable $e) {}
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
            }
        }
    } finally {
        if ($had) $GLOBALS['pb_out_throw'] = $prev; else unset($GLOBALS['pb_out_throw']);
    }
    return $n;
}

/** For hooks in other actions (fy.open_next, period_lock.reopen): materialize and swallow everything so the main action is never broken. */
function pb_fxr_materialize_quiet(PDO $pdo, array $customer): void {
    try { pb_fxr_materialize($pdo, $customer); } catch (Throwable $e) {}
}

/**
 * Posting-document loader. $rev=false: the original voucher (date = as_of). $rev=true: the reversing voucher, only when it exists:
 *  rev_status='posted'    (auto reversal)   date = rev_date (fallback as_of+1), «برگشت خودکار تسعیر ارز #id»
 *  rev_status='cancelled' (manual cancel)   status must be 'reversed', date = as_of, «ابطال تسعیر ارز #id»
 *  rev_status='none'      (legacy row)      status must be 'reversed', date = reversed_at (old behaviour), «برگشت تسعیر ارز #id»
 *  'pending' / anything else => null (no reversing voucher yet).
 */
function pb_fxr_load(PDO $pdo, int $cid, int $id, bool $rev): ?array {
    try {
        $st = $pdo->prepare('SELECT * FROM fx_revals WHERE id=? AND customer_id=?');
        $st->execute([$id, $cid]);
        $r = $st->fetch();
    } catch (Throwable $e) { return null; }
    if (!$r) return null;
    $asOf = (string)$r['as_of'];
    if (!$rev) {
        $date = $asOf;
        $desc = 'تسعیر ارز پایان دوره #' . $id . ' (' . $asOf . ')';
    } else {
        $rs = (string)($r['rev_status'] ?? 'none');
        $reversed = (string)$r['status'] === 'reversed';
        if ($rs === 'posted') {
            $rd = (string)($r['rev_date'] ?? '');
            $date = $rd !== '' ? substr($rd, 0, 10) : pb_fxr_next_day(substr($asOf, 0, 10));
            $desc = 'برگشت خودکار تسعیر ارز #' . $id . ' (' . $asOf . ')';
        } elseif ($rs === 'cancelled') {
            if (!$reversed) return null;
            $date = substr($asOf, 0, 10);
            $desc = 'ابطال تسعیر ارز #' . $id . ' (' . $asOf . ')';
        } elseif ($rs === 'none') {
            if (!$reversed) return null;
            $date = substr((string)($r['reversed_at'] ?: $asOf), 0, 10);
            $desc = 'برگشت تسعیر ارز #' . $id . ' (' . $asOf . ')';
        } else {
            return null;
        }
    }
    $ctx = pb_fxr_ctx($pdo, $cid, $r);
    return ['date' => $date, 'description' => $desc] + $ctx;
}

/**
 * Posting context of a fx_revals row: per-party groups (rules fx_reval / fx_reval_rev use each:groups + tafsili:party for ar/ap lines,
 * global fields for the aggregate fx_unrealized lines). Built from the STORED detail.parties (never recomputed) so the reversal mirrors the original.
 * Party tafsili: detail.parties[].tafsili_id (resolved at apply) else resolved by name now; "no party" / unknown name => 0 (voucher line without tafsili).
 * Legacy rows (detail missing / old items-array / no parties) => one group without tafsili from the ar_up.. columns.
 * @return array{fields:array, dims:array, groups:array}
 */
function pb_fxr_ctx(PDO $pdo, int $cid, array $r): array {
    $detail = json_decode((string)($r['detail'] ?? ''), true);
    $parties = is_array($detail) && isset($detail['parties']) && is_array($detail['parties']) ? $detail['parties'] : [];
    $zero = ['ar_up' => 0, 'ar_down' => 0, 'ap_up' => 0, 'ap_down' => 0];
    $groups = []; $tot = $zero;
    foreach ($parties as $p) {
        $side = (string)($p['side'] ?? '');
        if ($side !== 'ar' && $side !== 'ap') continue;
        $up = (int)($p['up'] ?? 0); $down = (int)($p['down'] ?? 0);
        if ($up <= 0 && $down <= 0) continue;
        $f = $zero; $f[$side . '_up'] = $up; $f[$side . '_down'] = $down;
        $tot[$side . '_up'] += $up; $tot[$side . '_down'] += $down;
        if (!empty($p['no_party'])) $tid = 0;
        elseif (isset($p['tafsili_id'])) $tid = (int)$p['tafsili_id'];
        else $tid = pb_pl_party_tid($pdo, $cid, (string)($p['party'] ?? ''));
        $groups[] = ['fields' => $f, 'dims' => ['party' => $tid], 'desc' => ''];
    }
    if (!$groups) { // legacy row
        $tot = ['ar_up' => (int)$r['ar_up'], 'ar_down' => (int)$r['ar_down'], 'ap_up' => (int)$r['ap_up'], 'ap_down' => (int)$r['ap_down']];
        $groups[] = ['fields' => $tot, 'dims' => ['party' => 0]];
    }
    return ['fields' => $tot, 'dims' => [], 'groups' => $groups];
}

/** Pure: invoice keys ("ar:12") stored in a revaluation detail, or null when the row has no per-invoice list (legacy => whole as_of). */
function pb_fxr_stored_keys($detail): ?array {
    if (!is_array($detail) || !isset($detail['docs']) || !is_array($detail['docs']) || !$detail['docs']) return null;
    $k = [];
    foreach ($detail['docs'] as $x) if (is_array($x) && isset($x['side'], $x['doc_id'])) $k[] = pb_fxr_doc_key((string)$x['side'], (int)$x['doc_id']);
    return $k ?: null;
}

/** Year-end revaluation check (used by fy.close_preview): warning array or null. fx_reval_missing (none yet) | fx_reval_stale (active but inputs changed since). */
function pb_fxr_close_warning(PDO $pdo, int $cid, array $customer, string $endDate): ?array {
    try {
        if ((string)($customer['plan'] ?? '') !== 'fx') return null;
        pb_fxr_ensure($pdo);
        // 1) every ACTIVE revaluation of that date must still match the data it was computed from (each over its own invoices)
        $st = $pdo->prepare("SELECT detail FROM fx_revals WHERE customer_id=? AND as_of=? AND status='active' ORDER BY id");
        $st->execute([$cid, $endDate]);
        $rows = $st->fetchAll();
        foreach ($rows as $row) {
            $d = json_decode((string)$row['detail'], true);
            $storedFp = is_array($d) && isset($d['fingerprint']) ? (string)$d['fingerprint'] : '';
            if ($storedFp === '') continue;
            $nowFp = pb_fxr_compute($pdo, $cid, $endDate, true, pb_fxr_stored_keys($d), true)['fingerprint'];
            if (pb_fxr_close_kind(true, $storedFp, $nowFp, true) === 'stale')
                return ['code' => 'fx_reval_stale', 'count' => 1, 'message' => 'تسعیر ارز پایان دوره با داده‌های فعلی (فاکتور/پرداخت/نرخ) هم‌خوان نیست؛ بررسی و تسعیر مجدد لازم است'];
        }
        // 2) open invoices NOT covered by any active revaluation (all of them when none exists) that would move
        $c = pb_fxr_compute($pdo, $cid, $endDate, true); // default selection = uncovered invoices
        $hasDelta = (bool)($c['ar_up'] || $c['ar_down'] || $c['ap_up'] || $c['ap_down']);
        if (pb_fxr_close_kind(false, '', (string)$c['fingerprint'], $hasDelta) === 'missing')
            return ['code' => 'fx_reval_missing', 'count' => 1, 'message' => $rows
                ? 'برخی فاکتورهای ارزی در تسعیر ارز پایان دوره لحاظ نشده‌اند (accounting.fx_reval.apply)'
                : 'تسعیر ارز پایان دوره انجام نشده است (accounting.fx_reval.apply)'];
        return null;
    } catch (Throwable $e) { return null; } // rate missing etc.: no extra warning
}

/**
 * Pure: is a stored revaluation out of date? Only an ACTIVE row can be stale (reversed / cancelled / auto-reversed rows are not), and only when a
 * fingerprint was stored (legacy rows have none => cannot tell => false). stale = the open invoices / remaining amounts / rates as of as_of no longer
 * match what was revalued (e.g. a payment dated <= as_of was added afterwards).
 */
function pb_fxr_is_stale(string $storedFp, string $nowFp, string $status): bool {
    if ($status !== 'active' || $storedFp === '') return false;
    return $storedFp !== $nowFp;
}

/** Foreign-currency installments/cheques still unpaid on documents dated <= $asOf: they do NOT count as settlement (SIGNOFF), so they stay in the open amount. */
function pb_fxr_ignored_cheques(PDO $pdo, int $cid, string $asOf): int {
    $qs = [
        'SELECT COUNT(*) FROM receipt_credit_inst i JOIN receipts h ON h.id=i.receipt_id AND h.customer_id=i.customer_id JOIN sales d ON d.id=h.sale_id AND d.customer_id=i.customer_id WHERE i.customer_id=? AND i.paid=0 AND d.ccy IS NOT NULL AND d.date<=?',
        'SELECT COUNT(*) FROM receipt_cheque_inst i JOIN receipts h ON h.id=i.receipt_id AND h.customer_id=i.customer_id JOIN sales d ON d.id=h.sale_id AND d.customer_id=i.customer_id WHERE i.customer_id=? AND i.paid=0 AND d.ccy IS NOT NULL AND d.date<=?',
        'SELECT COUNT(*) FROM credit_inst i JOIN payments h ON h.id=i.payment_id AND h.customer_id=i.customer_id JOIN purchases d ON d.id=h.purchase_id AND d.customer_id=i.customer_id WHERE i.customer_id=? AND i.paid=0 AND d.ccy IS NOT NULL AND d.date<=?',
        'SELECT COUNT(*) FROM cheque_inst i JOIN payments h ON h.id=i.payment_id AND h.customer_id=i.customer_id JOIN purchases d ON d.id=h.purchase_id AND d.customer_id=i.customer_id WHERE i.customer_id=? AND i.paid=0 AND d.ccy IS NOT NULL AND d.date<=?',
    ];
    $n = 0;
    foreach ($qs as $q) {
        try { $st = $pdo->prepare($q); $st->execute([$cid, $asOf]); $n += (int)$st->fetchColumn(); } catch (Throwable $e) { /* table/column absent: counts 0 */ }
    }
    return $n;
}

/**
 * Pure: validate a revaluation request (shared by action .check and .apply). At most one problem per code, in this order:
 * in_future | no_fiscal_year | locked | rate_missing (+ccys) | duplicate | nothing_to_reval.
 * @param array $in as_of, today (Y-m-d), fy: null | {ok:bool, status:string, start?:string, end?:string} (the fiscal year containing as_of; ok=false => closed/locked/no access),
 *                  lock_through ('' or Y-m-d), missing_ccys: string[], has_active: bool, doc_count: int (open foreign invoices)
 * @return array{ok:bool, problems:array<int,array{code:string,message:string,ccys?:array}>}
 */
function pb_fxr_validate(array $in): array {
    $asOf = (string)($in['as_of'] ?? ''); $today = (string)($in['today'] ?? '');
    $fy = $in['fy'] ?? null; $lock = (string)($in['lock_through'] ?? '');
    $problems = [];
    if ($asOf !== '' && $today !== '' && $asOf > $today) $problems[] = ['code' => 'in_future', 'message' => 'تاریخ تسعیر نباید در آینده باشد'];
    $outside = is_array($fy) && ((isset($fy['start']) && $asOf < (string)$fy['start']) || (isset($fy['end']) && $asOf > (string)$fy['end']));
    if (!is_array($fy) || $outside) $problems[] = ['code' => 'no_fiscal_year', 'message' => 'تاریخ تسعیر در هیچ سال مالی تعریف‌شده‌ای نیست'];
    elseif (empty($fy['ok']) || (string)($fy['status'] ?? 'open') !== 'open') $problems[] = ['code' => 'locked', 'message' => 'سال مالی این تاریخ بسته یا قفل است (یا به آن دسترسی ندارید)'];
    elseif ($lock !== '' && $asOf <= $lock) $problems[] = ['code' => 'locked', 'message' => 'دورهٔ این تاریخ بسته شده است (تا ' . $lock . ')'];
    $missing = array_values(array_map('strval', (array)($in['missing_ccys'] ?? [])));
    if ($missing) $problems[] = ['code' => 'rate_missing', 'message' => 'نرخ ارز ' . implode('، ', $missing) . ' تا تاریخ تسعیر ثبت نشده است', 'ccys' => $missing];
    if (!empty($in['has_active'])) $problems[] = ['code' => 'duplicate', 'message' => 'فاکتور(های) انتخاب‌شده قبلاً در تسعیر فعالِ همین تاریخ تسعیر شده‌اند؛ برای تسعیر دوباره ابتدا آن تسعیر را ابطال کنید'];
    if ((int)($in['doc_count'] ?? 0) <= 0) $problems[] = ['code' => 'nothing_to_reval', 'message' => 'فاکتور ارزی بازی برای تسعیر وجود ندارد'];
    return ['ok' => !$problems, 'problems' => $problems];
}

/** Pure: year-end warning kind: null (fine / nothing to revalue) | 'missing' (no active revaluation) | 'stale' (active but out of date). */
function pb_fxr_close_kind(bool $hasActive, string $storedFp, string $nowFp, bool $hasDelta): ?string {
    if ($hasActive) return pb_fxr_is_stale($storedFp, $nowFp, 'active') ? 'stale' : null;
    return $hasDelta ? 'missing' : null;
}

/**
 * DB: gathers everything pb_fxr_validate needs (+ the pieces .check also returns).
 * $keys = explicit invoice selection (null => every open invoice not yet covered by an active revaluation of the same as_of).
 * A key that is not an open invoice at $asOf is a client error (400); a key already covered by an active revaluation surfaces as the `duplicate` problem.
 * rate_missing only concerns the currencies of the SELECTED invoices.
 */
function pb_fxr_gather(PDO $pdo, int $cid, array $customer, string $asOf, ?array $keys = null): array {
    [$docs, $paid] = pb_fxr_fetch($pdo, $cid, $asOf);
    $open = ['ar' => 0, 'ap' => 0]; $openKeys = [];
    foreach ($docs as $d) {
        if (round((float)$d['amt'] - (float)($paid[$d['side'] . ':' . (int)$d['id']] ?? 0.0), 4) > 0) {
            $open[(string)$d['side']]++; $openKeys[pb_fxr_doc_key((string)$d['side'], (int)$d['id'])] = true;
        }
    }
    $cov = pb_fxr_covered($pdo, $cid, $asOf);
    $hasCov = $cov['keys'] || $cov['all'] !== null;
    if ($keys !== null) {
        foreach ($keys as $k) if (!isset($openKeys[$k])) bad('فاکتور انتخاب‌شده (' . $k . ') در این تاریخ باز نیست', 400);
        $overlap = $cov['all'] !== null && $keys ? true : (bool)array_intersect($keys, array_keys($cov['keys']));
        $sel = pb_fxr_filter_docs($docs, $keys, []);
        $hasActive = $overlap;
    } else {
        $sel = $cov['all'] !== null ? [] : pb_fxr_filter_docs($docs, null, $cov['keys']);
        $selOpen = array_filter($sel, fn($d) => round((float)$d['amt'] - (float)($paid[$d['side'] . ':' . (int)$d['id']] ?? 0.0), 4) > 0);
        $hasActive = $hasCov && !$selOpen; // everything open is already revalued
    }
    $rates = []; $missing = [];
    foreach (pb_fxr_rates_for($pdo, $cid, $asOf, pb_fxr_open_ccys($sel, $paid)) as $ccy => $r) {
        $rates[] = ['ccy' => $ccy, 'rate' => $r['missing'] ? null : $r['rate'], 'rate_date' => $r['missing'] ? null : $r['rate_date'], 'missing' => $r['missing']];
        if ($r['missing']) $missing[] = $ccy;
    }
    $actId = $cov['ids'] ? (int)end($cov['ids']) : 0;
    $act = $actId > 0 ? ['id' => $actId, 'status' => 'active'] : null;
    pb_fy_seed($pdo, $cid);
    $fq = $pdo->prepare('SELECT id, name, status, start_date, end_date FROM fiscal_years WHERE customer_id=? AND start_date<=? AND end_date>=? LIMIT 1');
    $fq->execute([$cid, $asOf, $asOf]);
    $fy = $fq->fetch();
    $fyOk = $fy && (string)$fy['status'] === 'open' && pb_fy_access($customer, (int)$fy['id']);
    $lock = pb_pl_through($pdo, $cid);
    $docCount = 0;
    foreach ($sel as $d) if (round((float)$d['amt'] - (float)($paid[$d['side'] . ':' . (int)$d['id']] ?? 0.0), 4) > 0) $docCount++;
    $in = ['as_of' => $asOf, 'today' => date('Y-m-d'), 'lock_through' => $lock, 'missing_ccys' => $missing, 'has_active' => $hasActive, 'doc_count' => $docCount,
        'fy' => $fy ? ['ok' => (bool)$fyOk, 'status' => (string)$fy['status'], 'start' => (string)$fy['start_date'], 'end' => (string)$fy['end_date']] : null];
    return ['in' => $in, 'validation' => pb_fxr_validate($in), 'rates' => $rates, 'open' => $open, 'act' => $act, 'fy' => $fy ?: null, 'fyOk' => (bool)$fyOk, 'lock' => $lock,
        'selected' => $docCount, 'covered_ids' => $cov['ids']];
}

/** Read-only pre-flight for as_of (action .check); problems come from pb_fxr_validate (same as .apply). */
function pb_fxr_check(PDO $pdo, int $cid, array $customer, string $asOf, ?array $keys = null): array {
    $g = pb_fxr_gather($pdo, $cid, $customer, $asOf, $keys);
    $locked = $g['lock'] !== '' && $asOf <= $g['lock'];
    $problems = $g['validation']['problems'];
    return ['as_of' => $asOf, 'rates' => $g['rates'], 'open_docs' => $g['open'],
        'has_active_reval' => $g['act'] ? ['id' => (int)$g['act']['id'], 'status' => (string)$g['act']['status']] : null,
        'fiscal' => ['ok' => $g['fyOk'], 'fy_name' => $g['fy'] ? (string)$g['fy']['name'] : null, 'status' => $g['fy'] ? (string)$g['fy']['status'] : null],
        'period_locked' => $locked, 'in_future' => $asOf > $g['in']['today'],
        'selected_docs' => $g['selected'], 'active_reval_ids' => $g['covered_ids'],
        'ignored_cheques' => ['count' => pb_fxr_ignored_cheques($pdo, $cid, $asOf)],
        'can_apply' => $g['validation']['ok'], 'problems' => $problems];
}

/** Stored row + decoded detail + stale flag (recomputed fingerprint; only for rows that are still in effect). */
function pb_fxr_detail(PDO $pdo, int $cid, int $id): array {
    $st = $pdo->prepare('SELECT * FROM fx_revals WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) bad('تسعیر یافت نشد', 404);
    $detail = json_decode((string)($r['detail'] ?? ''), true); // new rows: object {items,docs,parties,rates,fingerprint}; legacy: items array or null
    $storedFp = is_array($detail) && isset($detail['fingerprint']) ? (string)$detail['fingerprint'] : '';
    $status = (string)$r['status'];
    $rs = (string)($r['rev_status'] ?? 'none');
    $effective = $rs === 'posted' ? 'reversed' : $status; // an auto-reversed revaluation has no remaining effect
    $stale = false;
    if ($effective === 'active' && $storedFp !== '') {
        $keys = pb_fxr_stored_keys($detail); // recompute over the SAME invoices that were revalued (selection-aware)
        try { $nowFp = pb_fxr_compute($pdo, $cid, substr((string)$r['as_of'], 0, 10), true, $keys, true)['fingerprint']; }
        catch (Throwable $e) { $nowFp = 'unavailable'; } // e.g. a rate disappeared: inputs no longer reproduce => stale
        $stale = pb_fxr_is_stale($storedFp, $nowFp, $effective);
    }
    $out = ['id' => (int)$r['id'], 'as_of' => (string)$r['as_of'], 'status' => $status, 'rev_date' => $r['rev_date'] ?? null, 'rev_status' => $rs,
        'ar_up' => (int)$r['ar_up'], 'ar_down' => (int)$r['ar_down'], 'ap_up' => (int)$r['ap_up'], 'ap_down' => (int)$r['ap_down'],
        'doc_count' => (int)($r['doc_count'] ?? 0), 'net_delta' => (int)($r['net_delta'] ?? 0), 'note' => $r['note'] ?? null,
        'created_by' => $r['created_by'] ?? null, 'created_at' => $r['created_at'] ?? null, 'reversed_at' => $r['reversed_at'] ?? null, 'reversed_by' => $r['reversed_by'] ?? null,
        'detail' => is_array($detail) ? $detail : null, 'stale' => $stale];
    return $out;
}

/* ================= fx-reval pro S8: report helpers (pure) ================= */

/**
 * Pure: per-currency net effect of a stored revaluation detail, gain > 0 / loss < 0 (receivable delta counts +, payable delta counts −).
 * Accepts the new object shape {items,...} and the legacy bare items array. @return array<string,int> ccy => net (sorted by ccy)
 */
function pb_fxr_ccy_nets($detail): array {
    $items = is_array($detail) ? (isset($detail['items']) && is_array($detail['items']) ? $detail['items'] : $detail) : [];
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['ccy'], $it['side'])) continue;
        $c = strtoupper(trim((string)$it['ccy']));
        $d = (int)($it['delta'] ?? 0);
        $out[$c] = ($out[$c] ?? 0) + ((string)$it['side'] === 'ap' ? -$d : $d);
    }
    ksort($out, SORT_STRING);
    return $out;
}

/**
 * Pure: split the net credit−debit of one revaluation voucher over currencies using the stored per-currency nets.
 * $rev=true (reversing voucher) negates them. Whatever the stored detail cannot explain (legacy row / manual line) lands on ccy ''.
 * @return array<string,int> ccy => signed net (credit−debit), zero entries dropped
 */
function pb_fxr_alloc_net(int $net, array $ccyNets, bool $rev): array {
    $out = []; $sum = 0;
    foreach ($ccyNets as $c => $n) {
        $n = $rev ? -(int)$n : (int)$n;
        if ($n === 0) continue;
        $out[(string)$c] = $n; $sum += $n;
    }
    $rest = $net - $sum;
    if ($rest !== 0) $out[''] = ($out[''] ?? 0) + $rest;
    ksort($out, SORT_STRING);
    return $out;
}

/**
 * Pure: month × currency rollup for kind=diff. Input rows: {month:'YYYY-MM', ccy, role:'fx_diff'|'fx_unrealized', debit, credit} (rial integers).
 * Legacy fields (gain/loss/count/net + total_gain/total_loss/net) describe the REALIZED account (fx_diff) only, exactly as before;
 * new fields: realized / unrealized (net, gain > 0) + their gain/loss split, total (= realized + unrealized).
 */
function pb_fxr_report_rollup(array $rows): array {
    $g = [];
    foreach ($rows as $r) {
        $role = (string)($r['role'] ?? '');
        if ($role !== 'fx_diff' && $role !== 'fx_unrealized') continue;
        $m = (string)($r['month'] ?? ''); $c = (string)($r['ccy'] ?? '');
        $k = $m . '|' . $c;
        if (!isset($g[$k])) $g[$k] = ['month' => $m, 'ccy' => $c, 'gain' => 0, 'loss' => 0, 'count' => 0,
            'realized_gain' => 0, 'realized_loss' => 0, 'unrealized_gain' => 0, 'unrealized_loss' => 0, 'unrealized_count' => 0];
        $cr = (int)($r['credit'] ?? 0); $db = (int)($r['debit'] ?? 0);
        if ($role === 'fx_diff') { $g[$k]['gain'] += $cr; $g[$k]['loss'] += $db; $g[$k]['count']++; $g[$k]['realized_gain'] += $cr; $g[$k]['realized_loss'] += $db; }
        else { $g[$k]['unrealized_gain'] += $cr; $g[$k]['unrealized_loss'] += $db; $g[$k]['unrealized_count']++; }
    }
    ksort($g, SORT_STRING);
    $out = []; $t = ['gain' => 0, 'loss' => 0, 'ug' => 0, 'ul' => 0];
    foreach ($g as $x) {
        $x['net'] = $x['gain'] - $x['loss'];
        $x['realized'] = $x['realized_gain'] - $x['realized_loss'];
        $x['unrealized'] = $x['unrealized_gain'] - $x['unrealized_loss'];
        $x['total'] = $x['realized'] + $x['unrealized'];
        $t['gain'] += $x['gain']; $t['loss'] += $x['loss']; $t['ug'] += $x['unrealized_gain']; $t['ul'] += $x['unrealized_loss'];
        $out[] = $x;
    }
    $realized = $t['gain'] - $t['loss']; $unrealized = $t['ug'] - $t['ul'];
    return ['rows' => $out, 'total_gain' => $t['gain'], 'total_loss' => $t['loss'], 'net' => $realized,
        'total_realized' => $realized, 'total_unrealized' => $unrealized, 'total_unrealized_gain' => $t['ug'], 'total_unrealized_loss' => $t['ul'],
        'total_net' => $realized + $unrealized];
}

/**
 * Pure: unrealized gain/loss per currency over a list of revaluations {status, detail}. Only status='active' rows count
 * (a manually cancelled revaluation has no effect; an auto-reversed one still existed on its as_of, so it counts there).
 * Per revaluation the per-currency net decides gain vs loss. @return list<array{ccy:string,gain:int,loss:int,net:int}>
 */
function pb_fxr_reval_ccy_totals(array $revals): array {
    $t = [];
    foreach ($revals as $r) {
        if ((string)($r['status'] ?? 'active') !== 'active') continue;
        foreach (pb_fxr_ccy_nets($r['detail'] ?? null) as $c => $n) {
            if (!isset($t[$c])) $t[$c] = ['ccy' => (string)$c, 'gain' => 0, 'loss' => 0, 'net' => 0];
            if ($n > 0) $t[$c]['gain'] += $n; else $t[$c]['loss'] += -$n;
            $t[$c]['net'] += $n;
        }
    }
    ksort($t, SORT_STRING);
    return array_values($t);
}

/**
 * Pure: flat export of a STORED revaluation (row columns + decoded detail; no recomputation). Persian headers, raw numbers (client formats).
 * @return array{title:string,as_of:string,headers:array,rows:array,totals:array,sheets:array}
 */
function pb_fxr_export_build(array $row, $detail): array {
    $side = fn($s) => (string)$s === 'ap' ? 'بدهی (خرید)' : 'طلب (فروش)';
    $items = is_array($detail) ? (isset($detail['items']) && is_array($detail['items']) ? $detail['items'] : (($detail === [] || array_keys($detail) === range(0, count($detail) - 1)) ? $detail : [])) : [];
    $docs = is_array($detail) && isset($detail['docs']) && is_array($detail['docs']) ? $detail['docs'] : [];
    $parties = is_array($detail) && isset($detail['parties']) && is_array($detail['parties']) ? $detail['parties'] : [];
    $sum = []; $itemRows = [];
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['ccy'], $it['side'])) continue;
        $c = strtoupper(trim((string)$it['ccy'])); $d = (int)($it['delta'] ?? 0); $o = (float)($it['open_ccy'] ?? 0);
        $itemRows[] = [$side($it['side']), $c, (float)($it['rate'] ?? 0), $o, $d];
        if (!isset($sum[$c])) $sum[$c] = ['rate' => (float)($it['rate'] ?? 0), 'ar_open' => 0.0, 'ap_open' => 0.0, 'ar_delta' => 0, 'ap_delta' => 0];
        if ((string)$it['side'] === 'ap') { $sum[$c]['ap_open'] = round($sum[$c]['ap_open'] + $o, 4); $sum[$c]['ap_delta'] += $d; }
        else { $sum[$c]['ar_open'] = round($sum[$c]['ar_open'] + $o, 4); $sum[$c]['ar_delta'] += $d; }
    }
    ksort($sum, SORT_STRING);
    $rows = [];
    foreach ($sum as $c => $x) $rows[] = [(string)$c, $x['rate'], $x['ar_open'], $x['ap_open'], $x['ar_delta'], $x['ap_delta'], $x['ar_delta'] - $x['ap_delta']];
    $docRows = [];
    foreach ($docs as $d) {
        if (!is_array($d)) continue;
        $docRows[] = [$side($d['side'] ?? 'ar'), (string)($d['ref'] ?? ''), (string)($d['party'] ?? ''), (string)($d['ccy'] ?? ''), (float)($d['booked_rate'] ?? 0),
            (float)($d['total_ccy'] ?? 0), (float)($d['paid_ccy'] ?? 0), (float)($d['rem_ccy'] ?? 0), (float)($d['rate'] ?? 0), (int)($d['delta'] ?? 0)];
    }
    $partyRows = [];
    foreach ($parties as $p) {
        if (!is_array($p)) continue;
        $up = (int)($p['up'] ?? 0); $dn = (int)($p['down'] ?? 0);
        $partyRows[] = [$side($p['side'] ?? 'ar'), (string)($p['party'] ?? ''), $up, $dn, ((string)($p['side'] ?? 'ar') === 'ap') ? $dn - $up : $up - $dn];
    }
    $au = (int)($row['ar_up'] ?? 0); $ad = (int)($row['ar_down'] ?? 0); $pu = (int)($row['ap_up'] ?? 0); $pd = (int)($row['ap_down'] ?? 0);
    $net = isset($row['net_delta']) && (int)$row['net_delta'] !== 0 ? (int)$row['net_delta'] : ($au - $ad) - ($pu - $pd);
    return [
        'title' => 'گزارش تسعیر ارز شمارهٔ ' . (int)($row['id'] ?? 0),
        'as_of' => substr((string)($row['as_of'] ?? ''), 0, 10),
        'headers' => ['ارز', 'نرخ تسعیر', 'مانده طلب ارزی', 'مانده بدهی ارزی', 'سود/زیان طلب (ریال)', 'سود/زیان بدهی (ریال)', 'خالص سود/زیان (ریال)'],
        'rows' => $rows,
        'totals' => ['ar_up' => $au, 'ar_down' => $ad, 'ap_up' => $pu, 'ap_down' => $pd, 'gain' => $au + $pd, 'loss' => $ad + $pu, 'net_delta' => $net, 'doc_count' => (int)($row['doc_count'] ?? 0) ?: count($docRows)],
        'sheets' => [
            'docs' => ['headers' => ['نوع', 'شمارهٔ سند', 'طرف‌حساب', 'ارز', 'نرخ ثبت‌شده', 'مبلغ ارزی', 'پرداخت‌شده', 'مانده ارزی', 'نرخ تسعیر', 'تفاوت (ریال)'], 'rows' => $docRows],
            'parties' => ['headers' => ['نوع', 'طرف‌حساب', 'افزایش (ریال)', 'کاهش (ریال)', 'سود/زیان خالص (ریال)'], 'rows' => $partyRows],
            'items' => ['headers' => ['نوع', 'ارز', 'نرخ تسعیر', 'مانده ارزی', 'تفاوت (ریال)'], 'rows' => $itemRows],
        ],
    ];
}

function pb_fxr_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (!pb_fy_is_gold($customer)) out(['error' => 'edition_required', 'need' => 'gold'], 403);
    if ((string)($customer['plan'] ?? '') !== 'fx') out(['error' => 'edition_required', 'need' => 'fx'], 403);
    $cid = (int)$customer['id'];
    pb_fxr_ensure($pdo);
    pb_fxr_materialize_quiet($pdo, $customer); // pending automatic reversals that became postable (idempotent)
    if ($action === 'accounting.fx_reval.list') {
        $st = $pdo->prepare('SELECT id, as_of, ar_up, ar_down, ap_up, ap_down, status, created_by, created_at, reversed_at, rev_date, rev_status, doc_count, net_delta, note FROM fx_revals WHERE customer_id=? ORDER BY id DESC LIMIT 200');
        $st->execute([$cid]);
        out(['revals' => $st->fetchAll()]);
    }
    // optional per-invoice selection (doc_keys: ["ar:12","ap:7"]); absent => every open invoice not yet revalued at that as_of (batch, as before)
    $keys = null;
    if (array_key_exists('doc_keys', $p)) {
        try { $keys = pb_fxr_parse_keys($p['doc_keys']); } catch (InvalidArgumentException $e) { bad('فهرست فاکتورهای انتخابی نامعتبر است'); }
    }
    if ($action === 'accounting.fx_reval.preview') out(pb_fxr_compute($pdo, $cid, pb_fxr_date($p), false, $keys));
    if ($action === 'accounting.fx_reval.check') out(pb_fxr_check($pdo, $cid, $customer, pb_fxr_date($p), $keys));
    if ($action === 'accounting.fx_reval.docs') { // selection list: every open invoice at as_of (never fails on a missing rate: rate/delta = null)
        $asOf = pb_fxr_date($p);
        [$docs, $paid] = pb_fxr_fetch($pdo, $cid, $asOf);
        $plain = [];
        foreach (pb_fxr_rates_for($pdo, $cid, $asOf, pb_fxr_open_ccys($docs, $paid)) as $ccy => $r) if (!$r['missing']) $plain[$ccy] = $r['rate'];
        $cov = pb_fxr_covered($pdo, $cid, $asOf);
        out(['as_of' => $asOf, 'candidates' => pb_fxr_candidates($docs, $paid, $plain, $cov['keys'], $cov['all'])]);
    }
    if ($action === 'accounting.fx_reval.detail') out(pb_fxr_detail($pdo, $cid, (int)($p['id'] ?? 0)));
    if ($action === 'accounting.fx_reval.export') { // flat Excel/PDF data from the STORED detail (no recompute, no stale check)
        $st = $pdo->prepare('SELECT * FROM fx_revals WHERE id=? AND customer_id=?');
        $st->execute([(int)($p['id'] ?? 0), $cid]);
        $r = $st->fetch();
        if (!$r) bad('تسعیر یافت نشد', 404);
        out(pb_fxr_export_build($r, json_decode((string)($r['detail'] ?? ''), true)));
    }
    $who = mb_substr((string)($customer['name'] ?? $customer['phone'] ?? ''), 0, 120);
    if ($action === 'accounting.fx_reval.apply') {
        $asOf = pb_fxr_date($p);
        $g = pb_fxr_gather($pdo, $cid, $customer, $asOf, $keys); // same validator as .check; before any write
        if (!$g['validation']['ok']) {
            $pr = $g['validation']['problems']; $first = $pr[0];
            if ($first['code'] === 'rate_missing') out(['error' => 'rate_missing', 'ccy' => $first['ccys'][0], 'ccys' => $first['ccys'], 'message' => $first['message'], 'problems' => $pr], 422);
            out(['error' => $first['message'], 'code' => $first['code'], 'problems' => $pr], $first['code'] === 'nothing_to_reval' ? 400 : 409);
        }
        $note = trim((string)($p['note'] ?? ''));
        $note = $note === '' ? null : mb_substr($note, 0, 255);
        $c = pb_fxr_compute($pdo, $cid, $asOf, false, $keys);
        if (!$c['ar_up'] && !$c['ar_down'] && !$c['ap_up'] && !$c['ap_down']) bad('تفاوتی برای تسعیر وجود ندارد');
        $plan = pb_fxr_rev_plan_db($pdo, $cid, $customer, $asOf); // rev_date = as_of+1; post now or keep pending
        $revStatus = $plan['action'] === 'post' ? 'posted' : 'pending';
        $pdo->beginTransaction();
        // concurrent-apply guard: lock this date's active revaluations and re-check that none of the chosen invoices got revalued meanwhile
        $cov2 = pb_fxr_covered($pdo, $cid, $asOf, true);
        $chosen = array_map(fn($d) => pb_fxr_doc_key((string)$d['side'], (int)$d['doc_id']), $c['docs']);
        if ($cov2['all'] !== null || array_intersect($chosen, array_keys($cov2['keys']))) bad('فاکتور(های) انتخاب‌شده هم‌اکنون در تسعیر دیگری لحاظ شده‌اند؛ صفحه را تازه کنید', 409);
        // detail = full snapshot (items/docs/parties/rates/fingerprint) — later steps build the reversal from it, not from a recompute
        foreach ($c['parties'] as &$pp) $pp['tafsili_id'] = !empty($pp['no_party']) ? 0 : pb_pl_party_tid($pdo, $cid, (string)$pp['party']); // frozen into detail: the reversal must hit the same tafsili
        unset($pp);
        $detail = json_encode(['items' => $c['items'], 'docs' => $c['docs'], 'parties' => $c['parties'], 'rates' => $c['rates'], 'fingerprint' => $c['fingerprint']], JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO fx_revals (customer_id, as_of, ar_up, ar_down, ap_up, ap_down, doc_count, net_delta, detail, status, created_by, created_at, rev_date, rev_status, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $asOf, $c['ar_up'], $c['ar_down'], $c['ap_up'], $c['ap_down'], $c['doc_count'], $c['net_delta'], $detail, 'active', $who, date('Y-m-d H:i:s'), $plan['rev_date'], $revStatus, $note]);
        $id = (int)$pdo->lastInsertId();
        pb_post_document($pdo, $customer, 'fx_reval', $id);
        if ($revStatus === 'posted' && pb_post_document($pdo, $customer, 'fx_reval_rev', $id) <= 0) bad('ثبت سند برگشت خودکار تسعیر انجام نشد'); // same transaction: out() rolls back
        $pdo->commit();
        pb_audit($pdo, $customer, 'fx.reval', 'fx_reval', (string)$id, 'تسعیر ارز تا ' . $asOf . ($note !== null ? ' — ' . $note : ''), null,
            ['as_of' => $asOf, 'rev_date' => $plan['rev_date'], 'rev_status' => $revStatus, 'note' => $note, 'doc_count' => $c['doc_count'], 'net_delta' => $c['net_delta'],
             'ar_up' => $c['ar_up'], 'ar_down' => $c['ar_down'], 'ap_up' => $c['ap_up'], 'ap_down' => $c['ap_down']]);
        out(['id' => $id, 'rev_date' => $plan['rev_date'], 'rev_status' => $revStatus, 'note' => $note] + $c);
    }
    if ($action === 'accounting.fx_reval.reverse') {
        // Manual cancel. Allowed only while no automatic reversal was posted. The cancelling voucher (fx_reval_rev) is dated as_of so it
        // neutralizes the revaluation completely; legacy rows (rev_status='none') keep the old behaviour (dated today = reversed_at).
        $id = (int)($p['id'] ?? 0);
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT as_of, status, rev_status, rev_date, note FROM fx_revals WHERE id=? AND customer_id=? FOR UPDATE');
        $st->execute([$id, $cid]);
        $row = $st->fetch();
        if (!$row) bad('تسعیر یافت نشد', 404);
        $rs = (string)$row['rev_status'];
        if ($rs === 'posted') bad('این تسعیر قبلاً خودکار برگشت شده است', 409);
        if ((string)$row['status'] !== 'active') bad('این تسعیر قبلاً برگردانده شده است', 409);
        $now = date('Y-m-d H:i:s');
        $asOf = substr((string)$row['as_of'], 0, 10);
        pb_fy_guard($pdo, $cid, $customer, $rs === 'none' ? substr($now, 0, 10) : $asOf); // open year + unlocked period for the cancelling voucher date
        $newRs = $rs === 'none' ? 'none' : 'cancelled';
        $u = $pdo->prepare("UPDATE fx_revals SET status='reversed', rev_status=?, reversed_at=?, reversed_by=? WHERE id=? AND customer_id=? AND status='active' AND rev_status<>'posted'");
        $u->execute([$newRs, $now, $who, $id, $cid]);
        if ($u->rowCount() !== 1) bad('این تسعیر قبلاً برگردانده شده است', 409);
        pb_post_document($pdo, $customer, 'fx_reval_rev', $id);
        $pdo->commit();
        pb_audit($pdo, $customer, 'fx.reval_reverse', 'fx_reval', (string)$id, 'ابطال تسعیر ارز #' . $id . ' (' . $asOf . ')' . ($row['note'] ? ' — ' . $row['note'] : ''),
            ['status' => 'active', 'rev_status' => $rs, 'rev_date' => $row['rev_date'] ?? null, 'note' => $row['note'] ?? null], ['status' => 'reversed', 'rev_status' => $newRs, 'as_of' => $asOf]);
        out(['ok' => true, 'id' => $id, 'rev_status' => $newRs]);
    }
    bad('عملیات نامعتبر', 404);
}
