<?php
declare(strict_types=1);
/**
 * CP1 — «استخر هزینهٔ خط شکست» (MASTER_PLAN D1..D11). هر ردیف خط شکستِ لات‌شده یک رکورد crack_pools دارد:
 *   pool = source_cost_rial (بهای kg مصرف‌شده از لات مبدأ، ریال صحیح، با pb_pl_lot_value) + extra_cost_rial (هزینهٔ اضافهٔ دستی مدیر).
 * consume_mode: 'lots_only' (فقط جمع لات‌ها از مبدأ کم می‌شود؛ رفتار S5) | 'full' (کل ورودی L کم می‌شود؛ هزینهٔ افت روی گروه می‌افتد).
 * این فایل: بخش اول توابع خالص (بدون DB؛ تست: _PLAN/tests/crack_pool_selftest.php) و بخش دوم توابع DB.
 * هیچ مبلغی در پاسخ‌های اپراتور/crack_* نیست؛ موتور سود و اکشن‌های مدیریتی (فقط gold + profit.view) در بخش CP2 انتهای این فایل است.
 */
require_once __DIR__ . '/ddl_guard.php';

const PB_CP_MODES = ['lots_only', 'full'];

// ===================================================================== توابع خالص (بدون DB)

/**
 * floor(a*b/c) بدون سرریز int64 (جمع‌وجور: ضرب دودویی با باقی‌مانده). شرط: 0 ≤ b ≤ c ، a ≥ 0 ، c < 2^62.
 * نتیجه ≤ a است، پس در int جا می‌شود.
 */
function pb_cp_muldiv_floor(int $a, int $b, int $c): int {
    if ($c <= 0 || $a < 0 || $b < 0 || $b > $c || $c >= (1 << 62)) throw new InvalidArgumentException('pb_cp_muldiv_floor: ورودی نامعتبر');
    if ($a === 0 || $b === 0) return 0;
    $aq = intdiv($a, $c);
    $ar = $a % $c; // a = aq*c + ar
    $q = 0; $r = 0; // invariant: a*prefix(b) = q*c + r , 0 ≤ r < c
    for ($i = 61; $i >= 0; $i--) {
        $q <<= 1; $r <<= 1;
        if ($r >= $c) { $r -= $c; $q++; }
        if (($b >> $i) & 1) {
            $q += $aq; $r += $ar;
            if ($r >= $c) { $r -= $c; $q++; }
        }
    }
    return $q;
}

/**
 * سهم‌بندی استخر بین لات‌ها (D4). $revenueByLot: [lotId => درآمد خالص ریال]. وزن = max(R,0).
 * ΣR>0: کف برای همه، باقیِ ریالی به لاتی با بیشترین R (برابری ⇒ کوچک‌ترین id). ΣR=0: همه صفر. جمع نتیجه = pool (وقتی ΣR>0).
 * @return array<int,int> [lotId => هزینهٔ سهم‌بندی‌شده]
 */
function pb_cp_allocate(array $revenueByLot, int $poolRial): array {
    $pool = max(0, $poolRial);
    $w = [];
    foreach ($revenueByLot as $id => $r) $w[(int)$id] = max(0, (int)$r);
    ksort($w);
    $sum = array_sum($w);
    $out = array_fill_keys(array_keys($w), 0);
    if ($sum <= 0 || $pool === 0) return $out;
    $acc = 0;
    foreach ($w as $id => $r) {
        if ($r <= 0) continue;
        $out[$id] = pb_cp_muldiv_floor($pool, $r, $sum);
        $acc += $out[$id];
    }
    $rest = $pool - $acc;
    if ($rest > 0) {
        $best = null;
        foreach ($w as $id => $r) if ($r > 0 && ($best === null || $r > $w[$best])) $best = $id; // ksort ⇒ برابری: اولی (کوچک‌ترین id)
        $out[$best] += $rest;
    }
    return $out;
}

/**
 * خلاصهٔ گروه (D4/D5): سود = درآمد − استخر ؛ موقت تا بسته‌شدن.
 * @return array{revenue:int,pool:int,profit:int,margin_pct:?float,provisional:bool,sold_kg:float,unsold_kg:float}
 */
function pb_cp_summary(int $revenueRial, int $poolRial, float $soldKg, float $unsoldKg, string $status): array {
    $profit = $revenueRial - $poolRial;
    return [
        'revenue' => $revenueRial, 'pool' => $poolRial, 'profit' => $profit,
        'margin_pct' => $revenueRial > 0 ? round($profit * 100 / $revenueRial, 2) : null,
        'provisional' => $status !== 'closed',
        'sold_kg' => round($soldKg, 3), 'unsold_kg' => round(max(0.0, $unsoldKg), 3),
    ];
}

/** حالت مصرف از ورودی: کلید نیامده/خالی ⇒ $fallback ؛ نامعتبر ⇒ null. */
function pb_cp_mode_of($v, string $fallback = 'lots_only'): ?string {
    if ($v === null || $v === '') return $fallback;
    return in_array($v, PB_CP_MODES, true) ? (string)$v : null;
}

/**
 * قاعدهٔ مصرف از لات مبدأ (D3). همه‌چیز روی گرم صحیح.
 * lots_only: مصرف = جمع لات‌ها. full: مصرف = L (ورودی)، شرط جمع لات‌ها ≤ L ≤ موجودی مبدأ.
 * @return array{mode:string,consumed_g:int,loss_g:int}|array{error:string}
 */
function pb_cp_consume_plan(string $mode, float $inputKg, int $lotsTotalG, int $sourceRemainingG): array {
    if (!in_array($mode, PB_CP_MODES, true)) return ['error' => 'حالت مصرف نامعتبر است'];
    if ($lotsTotalG <= 0) return ['error' => 'جمع وزن لات‌ها صفر است'];
    if ($mode === 'lots_only') {
        if ($lotsTotalG > $sourceRemainingG) return ['error' => 'موجودی لات مبدأ کافی نیست'];
        return ['mode' => $mode, 'consumed_g' => $lotsTotalG, 'loss_g' => 0];
    }
    $inG = (int)round($inputKg * 1000);
    if ($inG <= 0) return ['error' => 'برای تبدیل کامل، «ورودی (کیلو)» گزارش را وارد کنید'];
    if ($lotsTotalG > $inG) return ['error' => 'جمع وزن لات‌ها از ورودی خط شکست بیشتر است'];
    if ($inG > $sourceRemainingG) return ['error' => 'موجودی لات مبدأ کافی نیست'];
    return ['mode' => $mode, 'consumed_g' => $inG, 'loss_g' => $inG - $lotsTotalG];
}

/** floor(a/b) برای b>0 و a با هر علامت. */
function pb_cp_floor_div(int $a, int $b): int {
    if ($b <= 0) throw new InvalidArgumentException('pb_cp_floor_div: مقسوم‌علیه نامعتبر');
    $q = intdiv($a, $b);
    if ($a % $b < 0) $q--;
    return $q;
}

/**
 * CP2: بهای تجمعی = floor(C × cum ÷ R) برای هر cum صحیح (منفی یا بیشتر از R هم مجاز: مرجوعی). شرط: C ≥ 0 ، R > 0.
 * cum = k·R + m با 0 ≤ m < R ⇒ C·k + floor(C·m/R) (بدون سرریز).
 */
function pb_cp_cum_cost(int $c, int $cum, int $r): int {
    if ($c < 0 || $r <= 0) throw new InvalidArgumentException('pb_cp_cum_cost: ورودی نامعتبر');
    if ($c === 0 || $cum === 0) return 0;
    $k = pb_cp_floor_div($cum, $r);
    $m = $cum - $k * $r;
    return $c * $k + pb_cp_muldiv_floor($c, $m, $r);
}

/**
 * CP2/ج: سهم هزینهٔ هر ردیف فروش(+)/مرجوعی(−) یک لات استخر، به ترتیب زمانی (آرایهٔ مرتب‌شده).
 * COGS ردیف = C × (درآمد ردیف ÷ درآمد کل لات) به روش «تجمعی»: delta_k = floor(C·cum_k/R) − floor(C·cum_{k−1}/R)
 * ⇒ جمع همهٔ ردیف‌ها دقیقاً C می‌شود (R = جمع علامت‌دار درآمدها). مرجوعی ⇒ delta منفی.
 * @param int[] $signedRevs درآمد علامت‌دار هر ردیف (ریال)
 * @return int[] هزینهٔ علامت‌دار هر ردیف (هم‌اندازهٔ ورودی). C ≤ 0 یا R ≤ 0 ⇒ همه صفر.
 */
function pb_cp_row_costs(int $c, int $r, array $signedRevs): array {
    $out = array_fill(0, count($signedRevs), 0);
    if ($c <= 0 || $r <= 0) return $out;
    $cum = 0; $prev = 0; $i = 0;
    foreach ($signedRevs as $x) {
        $cum += (int)$x;
        $cc = pb_cp_cum_cost($c, $cum, $r);
        $out[$i++] = $cc - $prev;
        $prev = $cc;
    }
    return $out;
}

/** CP2: مبلغ ریالِ صحیح ≥۰ از ورودی (عدد، رشتهٔ رقمی یا اعشار صحیح)؛ نامعتبر ⇒ null. */
function pb_cp_parse_rial($v): ?int {
    if (is_string($v)) $v = trim($v);
    if ($v === null || $v === '' || is_bool($v) || !is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < 0 || $f != floor($f) || $f > 1e15) return null;
    return (int)$f;
}

// ===================================================================== توابع DB

function pb_cp_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS crack_pools (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        run_id INT UNSIGNED NOT NULL,
        source_lot_id INT UNSIGNED NOT NULL,
        consume_mode VARCHAR(10) NOT NULL DEFAULT 'lots_only',
        input_g INT UNSIGNED NOT NULL DEFAULT 0,
        source_cost_rial BIGINT NOT NULL DEFAULT 0,
        extra_cost_rial BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        closed_at DATETIME NULL,
        closed_by INT UNSIGNED NULL,
        backfilled TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cpool_run (customer_id, run_id),
        KEY idx_cpool_src (customer_id, source_lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** بهای kg مصرف‌شده از لات مبدأ، ریال صحیح (D2). نامحاسبه‌پذیر (امانی/ناموجود/وزن صفر) ⇒ 0. */
function pb_cp_source_cost(PDO $pdo, int $cid, int $sourceLotId, int $consumedG): int {
    require_once __DIR__ . '/posting.php'; // pb_pl_lot_value (posting_loaders_a)
    $v = pb_pl_lot_value($pdo, $cid, $sourceLotId, $consumedG / 1000);
    return $v === null ? 0 : (int)$v[0];
}

/** رکورد استخر یک گزارش یا null (جدول هنوز نبود ⇒ null). */
function pb_cp_load(PDO $pdo, int $cid, int $runId): ?array {
    if ($runId <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT * FROM crack_pools WHERE customer_id=? AND run_id=?');
        $st->execute([$cid, $runId]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function pb_cp_save(PDO $pdo, int $cid, int $runId, int $sourceLotId, string $mode, int $consumedG, int $sourceCostRial, int $extraRial = 0, int $backfilled = 0): void {
    $pdo->prepare('DELETE FROM crack_pools WHERE customer_id=? AND run_id=?')->execute([$cid, $runId]);
    $pdo->prepare('INSERT INTO crack_pools (customer_id, run_id, source_lot_id, consume_mode, input_g, source_cost_rial, extra_cost_rial, backfilled) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$cid, $runId, $sourceLotId, $mode, $consumedG, $sourceCostRial, $extraRial, $backfilled]);
}

function pb_cp_delete(PDO $pdo, int $cid, int $runId): void {
    try { $pdo->prepare('DELETE FROM crack_pools WHERE customer_id=? AND run_id=?')->execute([$cid, $runId]); } catch (Throwable $e) { /* جدول هنوز نیست */ }
}

/**
 * اطلاعات بدون مبلغِ استخر چند گزارش ⇒ [run_id => {consume, consumed_kg, loss_kg, status}] (برای مدیر؛ D6).
 * loss = مصرف − جمع لات‌ها.
 */
function pb_cp_info_of(PDO $pdo, int $cid, array $runIds): array {
    $out = [];
    if (!$runIds) return $out;
    $in = implode(',', array_map('intval', $runIds));
    try {
        $st = $pdo->query('SELECT p.run_id, p.consume_mode, p.input_g, p.status, (SELECT COALESCE(SUM(ROUND(l.kg*1000)),0) FROM crack_run_lots l WHERE l.customer_id=p.customer_id AND l.run_id=p.run_id) AS lots_g
            FROM crack_pools p WHERE p.customer_id=' . (int)$cid . " AND p.run_id IN ($in)");
        foreach ($st->fetchAll() as $r) {
            $g = (int)$r['input_g'];
            $out[(int)$r['run_id']] = ['consume' => (string)$r['consume_mode'], 'consumed_kg' => $g / 1000, 'loss_kg' => max(0, $g - (int)$r['lots_g']) / 1000, 'status' => (string)$r['status']];
        }
    } catch (Throwable $e) { /* جدول هنوز نیست */ }
    return $out;
}

/**
 * D9: ردیف‌های دارای crack_run_lots و بدون استخر ⇒ استخر lots_only با بهای فعلی لات مبدأ (backfilled=1). idempotent.
 * @return int تعداد استخرهای ساخته‌شده
 */
function pb_cp_backfill(PDO $pdo): int {
    pb_cp_ensure($pdo);
    $n = 0;
    $rows = $pdo->query('SELECT l.customer_id, l.run_id, MIN(l.source_lot_id) AS src, SUM(ROUND(l.kg*1000)) AS g
        FROM crack_run_lots l LEFT JOIN crack_pools p ON p.customer_id=l.customer_id AND p.run_id=l.run_id
        WHERE p.id IS NULL GROUP BY l.customer_id, l.run_id')->fetchAll();
    foreach ($rows as $r) {
        $cid = (int)$r['customer_id']; $g = (int)$r['g'];
        if ($g <= 0) continue;
        pb_cp_save($pdo, $cid, (int)$r['run_id'], (int)$r['src'], 'lots_only', $g, pb_cp_source_cost($pdo, $cid, (int)$r['src'], $g), 0, 1);
        $n++;
    }
    return $n;
}

// ===================================================================== CP2 — موتور سود (فقط مدیریتی)

/**
 * CP2: ردیف‌های فروش/مرجوعی لات‌ها (بدون محدودیت تاریخ) با همان کوئری و واحد margin.php.
 * درآمد ردیف = round(قیمت × kg × pb_posting_unit_mult()) (ریال صحیح).
 * @return array<int,array{lot:int,date:string,k:int,iid:int,kg:float,rev:int,info:array}> مرتب: تاریخ، (فروش قبل از مرجوعی)، id
 */
function pb_cp_lot_rows(PDO $pdo, int $cid, array $lotIds): array {
    require_once __DIR__ . '/posting.php'; // pb_posting_unit_mult
    $ids = array_values(array_unique(array_filter(array_map('intval', $lotIds), fn($x) => $x > 0)));
    $rows = [];
    if (!$ids) return $rows;
    $m = pb_posting_unit_mult();
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', $chunk);
        $q = $pdo->prepare("SELECT i.id AS iid, s.date AS d, i.purchase_id AS lot_id, i.weight, COALESCE(i.item_price, s.price) AS pr, p.id, p.code, p.type_name, p.ounce, p.is_mix FROM sale_items i JOIN sales s ON s.id=i.sale_id AND s.customer_id=i.customer_id JOIN purchases p ON p.id=i.purchase_id AND p.customer_id=i.customer_id WHERE i.customer_id=? AND i.purchase_id IN ($in)");
        $q->execute([$cid]);
        foreach ($q->fetchAll() as $r) {
            $kg = (float)$r['weight'];
            $rows[] = ['lot' => (int)$r['lot_id'], 'date' => (string)$r['d'], 'k' => 0, 'iid' => (int)$r['iid'], 'kg' => $kg, 'rev' => (int)round((float)$r['pr'] * $kg * $m),
                'info' => ['id' => $r['id'], 'code' => $r['code'], 'type_name' => $r['type_name'], 'ounce' => $r['ounce'], 'is_mix' => $r['is_mix']]];
        }
        $q = $pdo->prepare("SELECT i.id AS iid, r.date AS d, i.lot_id, i.kg, i.unit_price, p.id, p.code, p.type_name, p.ounce, p.is_mix FROM sale_return_items i JOIN sale_returns r ON r.id=i.return_id AND r.customer_id=i.customer_id JOIN purchases p ON p.id=i.lot_id AND p.customer_id=i.customer_id WHERE i.customer_id=? AND i.lot_id IN ($in)");
        $q->execute([$cid]);
        foreach ($q->fetchAll() as $r) {
            $kg = (float)$r['kg'];
            $rows[] = ['lot' => (int)$r['lot_id'], 'date' => (string)$r['d'], 'k' => 1, 'iid' => (int)$r['iid'], 'kg' => $kg, 'rev' => (int)round((float)$r['unit_price'] * $kg * $m),
                'info' => ['id' => $r['id'], 'code' => $r['code'], 'type_name' => $r['type_name'], 'ounce' => $r['ounce'], 'is_mix' => $r['is_mix']]];
        }
    }
    usort($rows, static function ($a, $b) {
        return [$a['date'], $a['k'], $a['iid'], $a['lot']] <=> [$b['date'], $b['k'], $b['iid'], $b['lot']];
    });
    return $rows;
}

/**
 * CP2: درآمد خالص (فروش − مرجوعی) هر لات، ریال صحیح، و کیلوی خالص فروخته‌شده؛ بدون محدودیت تاریخ.
 * @return array<int,array{rev:int,kg:float}> برای همهٔ lotIdها (بدون فروش ⇒ صفر)
 */
function pb_cp_revenue_by_lot(PDO $pdo, int $cid, array $lotIds): array {
    $out = [];
    foreach ($lotIds as $id) if ((int)$id > 0) $out[(int)$id] = ['rev' => 0, 'kg' => 0.0];
    foreach (pb_cp_lot_rows($pdo, $cid, array_keys($out)) as $x) {
        $sg = $x['k'] === 0 ? 1 : -1;
        $out[$x['lot']]['rev'] += $sg * $x['rev'];
        $out[$x['lot']]['kg'] += $sg * $x['kg'];
    }
    foreach ($out as $id => $v) $out[$id]['kg'] = round($v['kg'], 3);
    return $out;
}

/**
 * CP2/ج: برای margin.php — هزینهٔ سهم‌بندی‌شدهٔ هر لاتِ استخر: [lotId => ['C'=>ریال, 'R'=>درآمد خالص کل لات, 'run_id'=>int]].
 * جدول‌ها نبود ⇒ [] (رفتار قبلی). لات‌های بدون استخر در نتیجه نیستند.
 */
function pb_cp_lot_costs(PDO $pdo, int $cid): array {
    try {
        $pools = [];
        $st = $pdo->prepare('SELECT run_id, source_cost_rial + extra_cost_rial AS pool FROM crack_pools WHERE customer_id=?');
        $st->execute([$cid]);
        foreach ($st->fetchAll() as $r) $pools[(int)$r['run_id']] = (int)$r['pool'];
        if (!$pools) return [];
        $st = $pdo->prepare('SELECT run_id, lot_id FROM crack_run_lots WHERE customer_id=?');
        $st->execute([$cid]);
        $byRun = [];
        foreach ($st->fetchAll() as $r) if (isset($pools[(int)$r['run_id']])) $byRun[(int)$r['run_id']][] = (int)$r['lot_id'];
        if (!$byRun) return [];
        $all = [];
        foreach ($byRun as $ids) foreach ($ids as $i) $all[] = $i;
        $rev = pb_cp_revenue_by_lot($pdo, $cid, $all);
        $out = [];
        foreach ($byRun as $runId => $ids) {
            $rr = [];
            foreach ($ids as $i) $rr[$i] = $rev[$i]['rev'];
            $alloc = pb_cp_allocate($rr, $pools[$runId]);
            foreach ($ids as $i) $out[$i] = ['C' => (int)($alloc[$i] ?? 0), 'R' => $rev[$i]['rev'], 'run_id' => $runId];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * CP2: گزارش سود استخرها (مبالغ ریال صحیح). فیلتر: from/to روی run_date ، run_id ، status (open|closed).
 * @return array<int,array> آیتم‌ها (جدیدترین اول؛ سقف ۵۰۰)
 */
function pb_cp_report(PDO $pdo, int $cid, array $filters): array {
    pb_cp_ensure($pdo);
    if (!defined('PB_CR_LOT_FIELDS')) require_once __DIR__ . '/workshop_crack.php';
    $sql = 'SELECT p.run_id, p.source_lot_id, p.consume_mode, p.input_g, p.source_cost_rial, p.extra_cost_rial, p.status, p.closed_at, p.closed_by,
            r.run_date, r.line_code, r.pistachio_type, r.customer_name, s.code AS src_code
        FROM crack_pools p JOIN crack_runs r ON r.id=p.run_id AND r.customer_id=p.customer_id
        LEFT JOIN purchases s ON s.id=p.source_lot_id AND s.customer_id=p.customer_id WHERE p.customer_id=?';
    $args = [$cid];
    if (($filters['from'] ?? '') !== '') { $sql .= ' AND r.run_date >= ?'; $args[] = (string)$filters['from']; }
    if (($filters['to'] ?? '') !== '') { $sql .= ' AND r.run_date <= ?'; $args[] = (string)$filters['to']; }
    if ((int)($filters['run_id'] ?? 0) > 0) { $sql .= ' AND p.run_id = ?'; $args[] = (int)$filters['run_id']; }
    if (in_array((string)($filters['status'] ?? ''), ['open', 'closed'], true)) { $sql .= ' AND p.status = ?'; $args[] = (string)$filters['status']; }
    $st = $pdo->prepare($sql . ' ORDER BY r.run_date DESC, p.run_id DESC LIMIT 500');
    $st->execute($args);
    $pools = $st->fetchAll();
    if (!$pools) return [];
    $runIds = array_map(fn($r) => (int)$r['run_id'], $pools);
    $in = implode(',', $runIds);
    $lq = $pdo->prepare("SELECT l.run_id, l.field_key, l.lot_id, l.kg, pu.code, pu.remaining FROM crack_run_lots l LEFT JOIN purchases pu ON pu.id=l.lot_id AND pu.customer_id=l.customer_id WHERE l.customer_id=? AND l.run_id IN ($in) ORDER BY l.id");
    $lq->execute([$cid]);
    $lotsBy = []; $allLots = [];
    foreach ($lq->fetchAll() as $l) { $lotsBy[(int)$l['run_id']][] = $l; $allLots[] = (int)$l['lot_id']; }
    $rev = pb_cp_revenue_by_lot($pdo, $cid, $allLots);
    $items = [];
    foreach ($pools as $pr) {
        $runId = (int)$pr['run_id'];
        $ls = $lotsBy[$runId] ?? [];
        $poolTotal = (int)$pr['source_cost_rial'] + (int)$pr['extra_cost_rial'];
        $rr = []; $lotsG = 0; $soldKg = 0.0; $unsold = 0.0; $revenue = 0;
        foreach ($ls as $l) {
            $id = (int)$l['lot_id'];
            $rr[$id] = $rev[$id]['rev'] ?? 0;
            $lotsG += (int)round((float)$l['kg'] * 1000);
            $soldKg += $rev[$id]['kg'] ?? 0.0;
            $unsold += (float)($l['remaining'] ?? 0);
            $revenue += $rr[$id];
        }
        $alloc = pb_cp_allocate($rr, $poolTotal);
        $lotOut = [];
        foreach ($ls as $l) {
            $id = (int)$l['lot_id'];
            $c = (int)($alloc[$id] ?? 0);
            $lotOut[] = ['lot_id' => $id, 'code' => $l['code'], 'label' => PB_CR_LOT_FIELDS[$l['field_key']] ?? (string)$l['field_key'],
                'kg' => (float)$l['kg'], 'remaining_kg' => round((float)($l['remaining'] ?? 0), 3), 'revenue' => $rr[$id], 'cost' => $c, 'profit' => $rr[$id] - $c];
        }
        $sum = pb_cp_summary($revenue, $poolTotal, $soldKg, $unsold, (string)$pr['status']);
        $g = (int)$pr['input_g'];
        $items[] = [
            'run_id' => $runId, 'run_date' => $pr['run_date'], 'line_code' => $pr['line_code'], 'pistachio_type' => $pr['pistachio_type'], 'customer_name' => $pr['customer_name'],
            'source_lot_code' => $pr['src_code'], 'consume' => (string)$pr['consume_mode'], 'consumed_kg' => $g / 1000, 'loss_kg' => max(0, $g - $lotsG) / 1000,
            'status' => (string)$pr['status'], 'closed_at' => $pr['closed_at'], 'closed_by' => $pr['closed_by'] === null ? null : (int)$pr['closed_by'],
            'pool' => ['source_cost' => (int)$pr['source_cost_rial'], 'extra' => (int)$pr['extra_cost_rial'], 'total' => $poolTotal],
            'revenue' => $sum['revenue'], 'profit' => $sum['profit'], 'margin_pct' => $sum['margin_pct'], 'provisional' => $sum['provisional'],
            'sold_kg' => $sum['sold_kg'], 'unsold_kg' => $sum['unsold_kg'], 'lots' => $lotOut,
        ];
    }
    return $items;
}

/** CP2: یک آیتم گزارش (یا ۴۰۴). */
function pb_cp_item(PDO $pdo, int $cid, int $runId): array {
    $it = pb_cp_report($pdo, $cid, ['run_id' => $runId]);
    if (!$it) out(['error' => 'استخر هزینهٔ این گزارش یافت نشد'], 404);
    return ['unit' => 'rial'] + $it[0];
}

/**
 * CP2: اکشن‌های مبلغ‌دار استخر. فقط gold + profit.view (D6)؛ نوشتنی‌ها علاوه بر آن workshop.manage (نگاشت اکشن در permissions.php).
 * reports.crack_profit {from?,to?,status?} | workshop.crack_pool_get {run_id} | ..._extra {run_id, extra_cost} | ..._close {run_id} | ..._reopen {run_id}
 */
function pb_cp_action(string $action, array $p, PDO $pdo, array $customer): void {
    if (pb_tier_of($customer) !== 'gold') out(['error' => 'edition_required', 'need' => 'gold'], 403);
    if (!pb_staff_can($customer, 'profit.view')) out(['error' => 'forbidden', 'message' => 'دسترسی لازم را ندارید', 'need' => 'profit.view'], 403);
    $cid = (int)$customer['id'];
    if (!defined('PB_CR_LOT_FIELDS')) require_once __DIR__ . '/workshop_crack.php';
    pb_cr_ensure($pdo); // crack_runs/crack_run_lots/crack_pools
    if ($action === 'reports.crack_profit') {
        $f = [];
        foreach (['from', 'to'] as $k) if (($p[$k] ?? '') !== '') $f[$k] = vdate($p[$k]);
        if (($p['status'] ?? '') !== '') {
            if (!in_array((string)$p['status'], ['open', 'closed'], true)) bad('وضعیت نامعتبر است');
            $f['status'] = (string)$p['status'];
        }
        $items = pb_cp_report($pdo, $cid, $f);
        $t = ['pool' => 0, 'revenue' => 0, 'profit' => 0];
        foreach ($items as $it) { $t['pool'] += $it['pool']['total']; $t['revenue'] += $it['revenue']; $t['profit'] += $it['profit']; }
        out(['unit' => 'rial', 'items' => $items, 'totals' => $t]);
    }
    if (!in_array($action, ['workshop.crack_pool_get', 'workshop.crack_pool_extra', 'workshop.crack_pool_close', 'workshop.crack_pool_reopen'], true)) bad('اکشن ناشناخته', 404);
    $runId = (int)($p['run_id'] ?? 0);
    if ($runId <= 0) bad('شناسهٔ گزارش لازم است');
    if ($action === 'workshop.crack_pool_get') out(pb_cp_item($pdo, $cid, $runId));
    if (!pb_staff_can($customer, 'workshop.manage')) out(['error' => 'forbidden', 'message' => 'دسترسی لازم را ندارید', 'need' => 'workshop.manage'], 403);
    $pool = pb_cp_load($pdo, $cid, $runId);
    if (!$pool) out(['error' => 'استخر هزینهٔ این گزارش یافت نشد'], 404);
    if ($action === 'workshop.crack_pool_extra') {
        $n = pb_cp_parse_rial($p['extra_cost'] ?? null);
        if ($n === null) bad('هزینهٔ اضافه باید عدد صحیح ریالی و نامنفی باشد');
        if ((string)$pool['status'] === 'closed') bad('استخر بسته است؛ ابتدا آن را باز کنید');
        $pdo->prepare("UPDATE crack_pools SET extra_cost_rial=? WHERE customer_id=? AND run_id=? AND status<>'closed'")->execute([$n, $cid, $runId]);
    } elseif ($action === 'workshop.crack_pool_close') {
        $by = max(0, (int)($customer['_staff_id'] ?? 0));
        $pdo->prepare("UPDATE crack_pools SET status='closed', closed_at=NOW(), closed_by=? WHERE customer_id=? AND run_id=? AND status<>'closed'")->execute([$by, $cid, $runId]);
    } else { // reopen
        $pdo->prepare("UPDATE crack_pools SET status='open', closed_at=NULL, closed_by=NULL WHERE customer_id=? AND run_id=? AND status<>'open'")->execute([$cid, $runId]);
    }
    out(pb_cp_item($pdo, $cid, $runId));
}
