<?php
declare(strict_types=1);
/**
 * T4 (مسیر پسته) — گراف خام دودویی لات‌ها. فقط خواندن، بدون جدول جدید، بدون هیچ مبلغ/قیمت/بها.
 * T4a: pb_chain_load (این فایل). T4b: pb_chain_build (پیمایش). T4c: اکشن lot_chain.get در app.php.
 *
 * شکل خروجی pb_chain_load:
 *  'lots'  => [ lotId => {id, code, is_mix, type_name, category, date, net_weight, remaining, seller} ]
 *  'edges' => [ {from:"<key>", to:"<key>", kg:float, via:"<harvest|sep|mix|crack|ws_internal|ws_outside|consign|sale|purchase_return|sale_return|waste>"} ]
 *  'nodes' => [ key => {kind, label, code?, date?, ref?, meta?} ]
 * کلیدها: lot:<id> · orchard:<orchardId>|<season> · sep:<id> · crack:<runId> · ws:<id> · sale:<id> · purchase:<lotId> · consign:<id> · preturn:<id> · waste:<ref>
 * جهت یال‌ها = جهت جریان پسته: from ⇒ to (باغ/خرید/امانی‌ورودی → لات → عملیات → لات → فروش/برگشت/ضایعات).
 * نام فروشنده/خریدار/طرف‌حساب فقط وقتی $withNames=true؛ وگرنه «—» (هیچ‌جا غیر از lots[].seller و nodes[].meta).
 */

const PB_CHAIN_NONAME = '—';

/** کوئری اختیاری: اگر جدول در این نصب وجود نداشت (نسخهٔ قدیمی) نتیجهٔ خالی. فقط برای جدول‌های «افزوده‌شده در نسخه‌های بعد». */
function pb_chain_opt(PDO $pdo, string $sql, array $args): array {
    try {
        $q = $pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function pb_chain_edge(array &$edges, string $from, string $to, float $kg, string $via): void {
    $edges[] = ['from' => $from, 'to' => $to, 'kg' => round($kg, 3), 'via' => $via];
}

function pb_chain_node(array &$nodes, string $key, string $kind, string $label, array $extra = []): void {
    if (!isset($nodes[$key])) $nodes[$key] = ['kind' => $kind, 'label' => $label] + $extra;
}

/**
 * گراف کامل یک مشتری (مقیاس کوچک: هر جدول یک کوئری، بدون N+1).
 * @return array{lots: array<int,array>, edges: list<array>, nodes: array<string,array>}
 */
function pb_chain_load(PDO $pdo, int $cid, bool $withNames = false): array {
    $nm = static fn($v): string => $withNames && trim((string)$v) !== '' ? (string)$v : PB_CHAIN_NONAME;
    $lots = []; $edges = []; $nodes = [];

    // ── لات‌ها (خرید/مخلوط/خروجی‌ها) ──
    $q = $pdo->prepare('SELECT p.id, p.code, p.is_mix, p.type_name, p.date, p.net_weight, p.remaining, p.seller, c.name AS cat
        FROM purchases p LEFT JOIN categories c ON c.id=p.category_id AND c.customer_id=p.customer_id WHERE p.customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) {
        $id = (int)$r['id'];
        $lots[$id] = ['id' => $id, 'code' => $r['code'] !== null ? (string)$r['code'] : null, 'is_mix' => (int)$r['is_mix'], 'type_name' => (string)$r['type_name'],
            'category' => (string)($r['cat'] ?? ''), 'date' => $r['date'], 'net_weight' => (float)$r['net_weight'], 'remaining' => (float)$r['remaining'],
            'seller' => $nm($r['seller'])];
        $nodes["lot:$id"] = ['kind' => 'lot', 'label' => (string)$r['type_name'], 'code' => $lots[$id]['code'], 'date' => $r['date'], 'ref' => $id];
    }

    // ── باغ → لات (برداشت) ──
    $harvLots = [];
    foreach (pb_chain_opt($pdo, 'SELECT h.lot_id, h.orchard_id, h.season_year, h.harvest_date, h.dry_kg, o.name
            FROM harvests h JOIN orchards o ON o.id=h.orchard_id AND o.customer_id=h.customer_id WHERE h.customer_id=? AND h.lot_id IS NOT NULL', [$cid]) as $r) {
        $lot = (int)$r['lot_id']; $k = 'orchard:' . (int)$r['orchard_id'] . '|' . (int)$r['season_year'];
        $harvLots[$lot] = true;
        pb_chain_node($nodes, $k, 'orchard', (string)$r['name'] . ' · ' . (int)$r['season_year'], ['date' => $r['harvest_date'], 'ref' => (int)$r['orchard_id']]);
        pb_chain_edge($edges, $k, "lot:$lot", (float)$r['dry_kg'], 'harvest');
    }

    // ── خرید عادی از فروشنده → لات (فقط لات‌های خریدِ مستقیم؛ نه مخلوط/خروجی عملیات/امانی/باغ) ──
    foreach ($lots as $id => $l) {
        if ($l['is_mix'] !== 0 || isset($harvLots[$id])) continue;
        pb_chain_node($nodes, "purchase:$id", 'purchase', $l['seller'], ['code' => $l['code'], 'date' => $l['date'], 'ref' => $id, 'meta' => ['seller' => $l['seller']]]);
        pb_chain_edge($edges, "purchase:$id", "lot:$id", $l['net_weight'], 'purchase');
    }

    // ── جداسازی ──
    $q = $pdo->prepare('SELECT id, sep_date, waste_kg, abnormal_kg FROM separations WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) {
        $sid = (int)$r['id'];
        pb_chain_node($nodes, "sep:$sid", 'sep', 'جداسازی #' . $sid, ['date' => $r['sep_date'], 'ref' => $sid]);
        $w = (float)$r['waste_kg'] + (float)$r['abnormal_kg'];
        if ($w > 0) {
            pb_chain_node($nodes, "waste:sep$sid", 'waste', 'ضایعات جداسازی #' . $sid, ['date' => $r['sep_date'], 'ref' => $sid]);
            pb_chain_edge($edges, "sep:$sid", "waste:sep$sid", $w, 'waste');
        }
    }
    $q = $pdo->prepare('SELECT separation_id, lot_id, kg FROM separation_inputs WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) pb_chain_edge($edges, 'lot:' . (int)$r['lot_id'], 'sep:' . (int)$r['separation_id'], (float)$r['kg'], 'sep');
    $q = $pdo->prepare('SELECT separation_id, lot_id, kg FROM separation_outputs WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) pb_chain_edge($edges, 'sep:' . (int)$r['separation_id'], 'lot:' . (int)$r['lot_id'], (float)$r['kg'], 'sep');

    // ── مخلوط: منبع ⇒ لات مخلوط (مستقیم، بدون گرهٔ میانی؛ مثل lot_trace) ──
    $q = $pdo->prepare('SELECT mix_purchase_id, source_purchase_id, weight FROM mix_sources WHERE customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) pb_chain_edge($edges, 'lot:' . (int)$r['source_purchase_id'], 'lot:' . (int)$r['mix_purchase_id'], (float)$r['weight'], 'mix');

    // ── کارگاه/فرآوری: internal ⇒ ws_internal · paid (بیرونی) ⇒ ws_outside · received (فراوری برای دیگران) ⇒ ws_internal با meta.direction ──
    $wsVia = [];
    foreach (pb_chain_opt($pdo, 'SELECT id, direction, party, order_date, waste_kg, loss_kg, receipt_no FROM proc_orders WHERE customer_id=?', [$cid]) as $r) {
        $oid = (int)$r['id']; $dir = (string)$r['direction'];
        $wsVia[$oid] = $dir === 'paid' ? 'ws_outside' : 'ws_internal';
        $label = ($dir === 'internal' ? 'کارگاه داخلی #' : ($dir === 'paid' ? 'کارگاه بیرونی #' : 'فراوری امانی #')) . $oid;
        pb_chain_node($nodes, "ws:$oid", 'ws', $label, ['date' => $r['order_date'], 'ref' => $oid,
            'meta' => ['direction' => $dir, 'party' => $nm($r['party']), 'receipt_no' => $r['receipt_no'] !== null ? (string)$r['receipt_no'] : null]]);
        foreach ([['waste_kg', 'waste:ws', 'ضایعات'], ['loss_kg', 'waste:wsl', 'افت']] as [$col, $pre, $txt]) {
            $v = (float)$r[$col];
            if ($v > 0) {
                pb_chain_node($nodes, $pre . $oid, 'waste', "$txt $label", ['date' => $r['order_date'], 'ref' => $oid]);
                pb_chain_edge($edges, "ws:$oid", $pre . $oid, $v, 'waste');
            }
        }
    }
    foreach (pb_chain_opt($pdo, 'SELECT order_id, lot_id, kg FROM proc_inputs WHERE customer_id=?', [$cid]) as $r)
        pb_chain_edge($edges, 'lot:' . (int)$r['lot_id'], 'ws:' . (int)$r['order_id'], (float)$r['kg'], $wsVia[(int)$r['order_id']] ?? 'ws_internal');
    foreach (pb_chain_opt($pdo, 'SELECT order_id, lot_id, kg FROM proc_outputs WHERE customer_id=?', [$cid]) as $r)
        pb_chain_edge($edges, 'ws:' . (int)$r['order_id'], 'lot:' . (int)$r['lot_id'], (float)$r['kg'], $wsVia[(int)$r['order_id']] ?? 'ws_internal');

    // ── شکستن (خط شکست): لات مبدأ ⇒ گرهٔ crack ⇒ لات‌های خروجی (crack_run_lots) ──
    // مصرف واقعی از لات مبدأ = crack_pools.input_g (در حالت full شامل افت)؛ بدون استخر = جمع kg لات‌های همان مبدأ
    // (همان قاعدهٔ pb_stock_desired('crack')). مابه‌التفاوت مصرف و جمع خروجی‌ها = ضایعات/افت شکستن.
    $crl = pb_chain_opt($pdo, 'SELECT run_id, lot_id, source_lot_id, kg FROM crack_run_lots WHERE customer_id=? ORDER BY run_id, id', [$cid]);
    if ($crl) {
        $runDate = [];
        foreach (pb_chain_opt($pdo, 'SELECT id, run_date FROM crack_runs WHERE customer_id=?', [$cid]) as $r) $runDate[(int)$r['id']] = $r['run_date'];
        $poolKg = [];
        foreach (pb_chain_opt($pdo, 'SELECT run_id, source_lot_id, input_g FROM crack_pools WHERE customer_id=?', [$cid]) as $r)
            $poolKg[(int)$r['run_id'] . ':' . (int)$r['source_lot_id']] = (int)$r['input_g'] / 1000.0;
        $srcKg = []; $outKg = [];
        foreach ($crl as $r) {
            $rid = (int)$r['run_id']; $src = (int)$r['source_lot_id']; $kg = (float)$r['kg'];
            $srcKg[$rid][$src] = ($srcKg[$rid][$src] ?? 0.0) + $kg;
            $outKg[$rid] = ($outKg[$rid] ?? 0.0) + $kg;
            pb_chain_node($nodes, "crack:$rid", 'crack', 'شکستن #' . $rid, ['date' => $runDate[$rid] ?? null, 'ref' => $rid]);
            pb_chain_edge($edges, "crack:$rid", 'lot:' . (int)$r['lot_id'], $kg, 'crack');
        }
        foreach ($srcKg as $rid => $bySrc) {
            $used = 0.0;
            foreach ($bySrc as $src => $kg) {
                $u = $poolKg["$rid:$src"] ?? $kg;
                $used += $u;
                pb_chain_edge($edges, 'lot:' . (int)$src, "crack:$rid", $u, 'crack');
            }
            $lost = $used - ($outKg[$rid] ?? 0.0);
            if ($lost > 0.0005) {
                pb_chain_node($nodes, "waste:crack$rid", 'waste', 'ضایعات/افت شکستن #' . $rid, ['date' => $runDate[$rid] ?? null, 'ref' => $rid]);
                pb_chain_edge($edges, "crack:$rid", "waste:crack$rid", $lost, 'waste');
            }
        }
    }

    // ── امانی: in ⇒ مالک→لات (و برگشت به مالک: لات→امانی) · out ⇒ لات→امانی (نزد امانت‌گیرنده) و برگشت: امانی→لات ──
    $cdir = [];
    foreach (pb_chain_opt($pdo, 'SELECT id, direction, party, cons_date FROM consignments WHERE customer_id=?', [$cid]) as $r) {
        $cidn = (int)$r['id']; $cdir[$cidn] = (string)$r['direction'];
        pb_chain_node($nodes, "consign:$cidn", 'consign', ($r['direction'] === 'out' ? 'امانی خروجی #' : 'امانی ورودی #') . $cidn,
            ['date' => $r['cons_date'], 'ref' => $cidn, 'meta' => ['direction' => (string)$r['direction'], 'party' => $nm($r['party'])]]);
    }
    foreach (pb_chain_opt($pdo, 'SELECT consignment_id, lot_id, kg FROM consignment_items WHERE customer_id=?', [$cid]) as $r) {
        $c = (int)$r['consignment_id']; $lot = 'lot:' . (int)$r['lot_id'];
        if (($cdir[$c] ?? 'in') === 'out') pb_chain_edge($edges, $lot, "consign:$c", (float)$r['kg'], 'consign');
        else pb_chain_edge($edges, "consign:$c", $lot, (float)$r['kg'], 'consign');
    }
    foreach (pb_chain_opt($pdo, 'SELECT consignment_id, lot_id, kg FROM consignment_returns WHERE customer_id=?', [$cid]) as $r) {
        $c = (int)$r['consignment_id']; $lot = 'lot:' . (int)$r['lot_id'];
        if (($cdir[$c] ?? 'in') === 'out') pb_chain_edge($edges, "consign:$c", $lot, (float)$r['kg'], 'consign');
        else pb_chain_edge($edges, $lot, "consign:$c", (float)$r['kg'], 'consign');
    }
    // consignment_sales عمداً نیست: هر ردیفش به یک ردیف عادی sales/sale_items وصل است و پایین‌تر خوانده می‌شود.

    // ── فروش ──
    $q = $pdo->prepare('SELECT si.purchase_id, si.weight, s.id AS sale_id, s.date, s.buyer
        FROM sale_items si JOIN sales s ON s.id=si.sale_id AND s.customer_id=si.customer_id WHERE si.customer_id=?');
    $q->execute([$cid]);
    foreach ($q->fetchAll() as $r) {
        $sid = (int)$r['sale_id'];
        pb_chain_node($nodes, "sale:$sid", 'sale', 'فروش #' . $sid, ['date' => $r['date'], 'ref' => $sid, 'meta' => ['buyer' => $nm($r['buyer'])]]);
        pb_chain_edge($edges, 'lot:' . (int)$r['purchase_id'], "sale:$sid", (float)$r['weight'], 'sale');
    }

    // ── برگشت از خرید (لات → فروشنده) و برگشت از فروش (فروش → لات) ──
    foreach (pb_chain_opt($pdo, 'SELECT i.return_id, i.lot_id, i.kg, r.date, r.party
            FROM purchase_return_items i JOIN purchase_returns r ON r.id=i.return_id AND r.customer_id=i.customer_id WHERE i.customer_id=?', [$cid]) as $r) {
        $rid = (int)$r['return_id'];
        pb_chain_node($nodes, "preturn:$rid", 'purchase_return', 'برگشت از خرید #' . $rid, ['date' => $r['date'], 'ref' => $rid, 'meta' => ['party' => $nm($r['party'])]]);
        pb_chain_edge($edges, 'lot:' . (int)$r['lot_id'], "preturn:$rid", (float)$r['kg'], 'purchase_return');
    }
    foreach (pb_chain_opt($pdo, 'SELECT i.sale_id, i.lot_id, i.kg, r.date, r.party
            FROM sale_return_items i JOIN sale_returns r ON r.id=i.return_id AND r.customer_id=i.customer_id WHERE i.customer_id=?', [$cid]) as $r) {
        $sid = (int)$r['sale_id'];
        pb_chain_node($nodes, "sale:$sid", 'sale', 'فروش #' . $sid, ['ref' => $sid, 'meta' => ['buyer' => $nm($r['party'])]]);
        pb_chain_edge($edges, "sale:$sid", 'lot:' . (int)$r['lot_id'], (float)$r['kg'], 'sale_return');
    }

    return ['lots' => $lots, 'edges' => $edges, 'nodes' => $nodes];
}

// ═══════════════════════════════════════════════════════════════════════════
// T4b — pb_chain_build: پیمایش «خالص» (بدون DB) روی خروجی pb_chain_load
// ═══════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/code_kind.php';

const PB_CHAIN_MAX_NODES = 300;   // سقف گرهٔ یکتا (اجداد + فرزندان + خود لات)
const PB_CHAIN_MAX_DEPTH = 50;    // سقف سخت برای پارامتر maxDepth

/** نوع خام گره از روی پیشوند کلید (وقتی گره در g['nodes'] نیست). */
function pb_chain_prefix_kind(string $key): string {
    $p = strstr($key, ':', true);
    $map = ['lot' => 'lot', 'orchard' => 'orchard', 'purchase' => 'purchase', 'sep' => 'sep', 'ws' => 'ws', 'consign' => 'consign',
        'sale' => 'sale', 'preturn' => 'purchase_return', 'waste' => 'waste', 'crack' => 'crack'];
    return $map[$p === false ? '' : $p] ?? 'lot';
}

/** نوع خروجی گره (orchard, purchase, consign, lot, separation, crack, mix, workshop_internal, workshop_outside, sale, purchase_return, waste). */
function pb_chain_out_kind(string $key, array $raw, array $lots): string {
    $k = (string)($raw['kind'] ?? pb_chain_prefix_kind($key));
    if ($k === 'lot') {
        $id = (int)substr($key, 4);
        return ((int)($lots[$id]['is_mix'] ?? 0)) === 1 ? 'mix' : 'lot';
    }
    if ($k === 'sep') return 'separation';
    if ($k === 'ws') return (($raw['meta']['direction'] ?? '') === 'paid') ? 'workshop_outside' : 'workshop_internal';
    return $k;
}

/** آیا از این گره می‌شود (در جهت dir) ادامه داد؟  up: فروش/برگشت از خرید/ضایعات و امانیِ ورودی پایان‌اند · down: ضایعات/برگشت از خرید و امانیِ ورودی پایان‌اند. */
function pb_chain_expandable(string $dir, string $rawKind, array $raw): bool {
    if ($rawKind === 'consign' && (($raw['meta']['direction'] ?? 'in') === 'in')) return false; // همنوعان امانی نباید وارد شوند
    if ($rawKind === 'waste' || $rawKind === 'purchase_return') return false;
    if ($dir === 'up' && $rawKind === 'sale') return false;   // برگشت از فروش: لات را دوباره تغذیه می‌کند؛ بالاتر (تا باغ) نمی‌رود
    return true;
}

/**
 * BFS یک‌جهته. $adj = فهرست مجاورت یال‌ها (up: بر اساس `to`، down: بر اساس `from`).
 * @param array<string,bool> $included  مجموعهٔ گره‌های یکتا (مشترک بین دو جهت؛ برای سقف ۳۰۰)
 * @return array{items:list<array>, trav:list<array>, cut:array<string,bool>, trunc:bool}
 */
function pb_chain_walk(string $dir, string $root, array $adj, callable $rawOf, int $maxDepth, array &$included): array {
    $items = [['key' => $root, 'parent' => -1, 'edges' => [], 'seen' => false]];
    $vis = [$root => true]; $queue = [[0, 0]]; $trav = []; $cut = []; $trunc = false;
    for ($qi = 0; $qi < count($queue); $qi++) {
        [$i, $d] = $queue[$qi];
        $key = $items[$i]['key'];
        $raw = $rawOf($key);
        if (!pb_chain_expandable($dir, (string)($raw['kind'] ?? pb_chain_prefix_kind($key)), $raw)) continue;
        $by = [];
        foreach ($adj[$key] ?? [] as $e) $by[$dir === 'up' ? $e['from'] : $e['to']][] = $e;
        uksort($by, 'strnatcmp');
        foreach ($by as $nb => $edges) {
            $nb = (string)$nb;
            if (isset($vis[$nb])) {                       // قبلاً دیده‌شده (چرخه/الماس): فقط ارجاع
                $items[] = ['key' => $nb, 'parent' => $i, 'edges' => $edges, 'seen' => true];
                foreach ($edges as $e) $trav[] = $e;
                continue;
            }
            if ($d >= $maxDepth || (!isset($included[$nb]) && count($included) >= PB_CHAIN_MAX_NODES)) {
                $trunc = true; $cut[$key] = true;
                continue;
            }
            $vis[$nb] = true; $included[$nb] = true;
            $items[] = ['key' => $nb, 'parent' => $i, 'edges' => $edges, 'seen' => false];
            foreach ($edges as $e) $trav[] = $e;
            $queue[] = [count($items) - 1, $d + 1];
        }
    }
    return ['items' => $items, 'trav' => $trav, 'cut' => $cut, 'trunc' => $trunc];
}

/** درخت تو در تو از items (بدون خود ریشه): [{node:key, edges:[…], children:[…], seen?:true}] */
function pb_chain_tree(array $items): array {
    $kids = [];
    foreach ($items as $idx => $it) if ($it['parent'] >= 0) $kids[$it['parent']][] = $idx;
    $mk = function (int $idx) use (&$mk, $items, $kids): array {
        $it = $items[$idx];
        $el = ['node' => $it['key'], 'edges' => $it['edges'], 'children' => array_map($mk, $kids[$idx] ?? [])];
        if ($it['seen']) $el['seen'] = true;
        return $el;
    };
    return array_map($mk, $kids[0] ?? []);
}

/**
 * سهم منشأها برای ریشه: پخش وزنی (kg) روی یال‌های «اجداد»؛ در هر گره بر اساس شاخه‌های دارای منشأ نرمال می‌شود.
 * منشأ = باغ / خرید (فروشنده) / امانیِ ورودی؛ گرهٔ بی‌والد: کارگاه یا لاتِ is_mix=2 ⇒ outside، لاتِ is_mix=3 ⇒ consign، بقیه نادیده.
 * شاخه‌ای که به‌خاطر سقف عمق/گره قطع شده سهمی ندارد (نرمال می‌شود). جمع درصدها = ۱۰۰٫۰ (روش بیشترین باقی‌مانده، یک رقم اعشار).
 * @return list<array{kind:string,name:string,pct:float}>
 */
function pb_chain_origins(string $root, array $upTrav, array $cut, callable $rawOf, array $lots): array {
    $upIn = [];
    foreach ($upTrav as $e) if ($e['via'] !== 'sale_return') $upIn[$e['to']][] = $e;
    $meta = []; $memo = []; $stack = [];
    $originOf = function (string $key) use ($rawOf, $lots, &$meta): ?string {
        $raw = $rawOf($key);
        $k = (string)($raw['kind'] ?? pb_chain_prefix_kind($key));
        $label = (string)($raw['label'] ?? $key);
        $kind = null; $name = $label;
        if ($k === 'orchard') $kind = 'orchard';
        elseif ($k === 'purchase') $kind = 'purchase';
        elseif ($k === 'consign') { if (($raw['meta']['direction'] ?? 'in') === 'in') $kind = 'consign'; }
        elseif ($k === 'ws') $kind = 'outside';
        elseif ($k === 'lot') {
            $m = (int)($lots[(int)substr($key, 4)]['is_mix'] ?? 0);
            $name = (string)($raw['code'] ?? '') !== '' ? (string)$raw['code'] : $label;
            if ($m === 2) $kind = 'outside'; elseif ($m === 3) $kind = 'consign';
        }
        if ($kind === null) return null;
        $id = $kind . "\x1f" . $name;
        $meta[$id] = ['kind' => $kind, 'name' => $name];
        return $id;
    };
    $dist = function (string $key) use (&$dist, &$memo, &$stack, $upIn, $cut, $originOf): array {
        if (isset($memo[$key])) return $memo[$key];
        if (isset($cut[$key]) || isset($stack[$key])) return [];
        $in = $upIn[$key] ?? [];
        if (!$in) { $o = $originOf($key); return $memo[$key] = ($o === null ? [] : [$o => 1.0]); }
        $stack[$key] = true;
        $parts = [];
        foreach ($in as $e) { $d = $dist($e['from']); if ($d) $parts[] = [max(0.0, (float)$e['kg']), $d]; }
        unset($stack[$key]);
        if (!$parts) return $memo[$key] = [];
        $W = 0.0; foreach ($parts as $p) $W += $p[0];
        if ($W <= 0) { foreach ($parts as &$p) $p[0] = 1.0; unset($p); $W = (float)count($parts); }
        $acc = [];
        foreach ($parts as [$w, $d]) foreach ($d as $id => $f) $acc[$id] = ($acc[$id] ?? 0.0) + $f * $w / $W;
        return $memo[$key] = $acc;
    };
    $d = $dist($root);
    if (!$d) return [];
    $tot = array_sum($d);
    $units = []; $rem = []; $sum = 0;
    foreach ($d as $id => $f) {
        $x = $f / $tot * 1000;
        $fl = (int)floor($x + 1e-9);
        $units[$id] = $fl; $rem[$id] = $x - $fl; $sum += $fl;
    }
    $ids = array_keys($d);
    usort($ids, static fn($a, $b) => [round($rem[$b], 6), round($d[$b], 9), $a] <=> [round($rem[$a], 6), round($d[$a], 9), $b]);
    for ($k = 0, $left = 1000 - $sum; $k < $left && $k < count($ids); $k++) $units[$ids[$k]]++;
    $out = [];
    foreach ($units as $id => $u) if ($u > 0) $out[] = ['kind' => $meta[$id]['kind'], 'name' => $meta[$id]['name'], 'pct' => $u / 10];
    usort($out, static fn($a, $b) => [$b['pct'], $a['name'], $a['kind']] <=> [$a['pct'], $b['name'], $b['kind']]);
    return $out;
}

/**
 * مسیر یک لات: اجداد (از کجا آمده) + فرزندان (به کجا رفته). بدون DB؛ ورودی = خروجی pb_chain_load.
 * @return array|null  null اگر لات در g['lots'] نباشد (T4c ⇒ خطای ۴۰۴)
 *  {lot:{id,code,letter,kind,type_name,category,date,net_weight,remaining,is_mix},
 *   up:[{node:key,edges:[{from,to,kg,via}],children:[…],seen?:true}], down:[…],
 *   nodes:[{key,kind,label,code,date,kg,meta}], edges:[{from,to,kg,via}],
 *   summary:{origins:[{kind,name,pct}],sold_kg,remaining_kg,waste_kg,returned_kg}, truncated:bool}
 */
function pb_chain_build(array $g, int $lotId, int $maxDepth = 10): ?array {
    $lots = $g['lots'] ?? []; $rawNodes = $g['nodes'] ?? [];
    if (!isset($lots[$lotId])) return null;
    $maxDepth = max(0, min(PB_CHAIN_MAX_DEPTH, $maxDepth));
    $root = "lot:$lotId";

    // یال‌های هم‌جفت و هم‌نوع (from,to,via) ادغام می‌شوند؛ kg جمع می‌شود.
    $merged = [];
    foreach ($g['edges'] ?? [] as $e) {
        $k = $e['from'] . "\x1f" . $e['to'] . "\x1f" . $e['via'];
        if (!isset($merged[$k])) $merged[$k] = ['from' => (string)$e['from'], 'to' => (string)$e['to'], 'kg' => 0.0, 'via' => (string)$e['via']];
        $merged[$k]['kg'] += (float)$e['kg'];
    }
    $in = []; $out = [];
    foreach ($merged as $k => $e) { $e['kg'] = round($e['kg'], 3); $merged[$k] = $e; $in[$e['to']][] = $e; $out[$e['from']][] = $e; }
    $rawOf = static function (string $key) use ($rawNodes): array {
        return $rawNodes[$key] ?? ['kind' => pb_chain_prefix_kind($key), 'label' => $key];
    };

    $included = [$root => true];
    $order = [$root];
    $U = pb_chain_walk('up', $root, $in, $rawOf, $maxDepth, $included);
    $D = pb_chain_walk('down', $root, $out, $rawOf, $maxDepth, $included);
    foreach ([$U, $D] as $W) foreach ($W['items'] as $it) if ($it['parent'] >= 0 && !in_array($it['key'], $order, true)) $order[] = $it['key'];

    // گراف تخت یکتا: همهٔ یال‌های پیموده‌شده (بدون تکرار)
    $flat = [];
    foreach (array_merge($U['trav'], $D['trav']) as $e) $flat[$e['from'] . "\x1f" . $e['to'] . "\x1f" . $e['via']] = $e;
    $flat = array_values($flat);
    $inKg = []; $outKg = []; $hasSale = []; $hasSaleRet = [];
    $sum = ['sale' => 0.0, 'waste' => 0.0, 'purchase_return' => 0.0, 'sale_return' => 0.0];
    foreach ($flat as $e) {
        $inKg[$e['to']] = ($inKg[$e['to']] ?? 0.0) + $e['kg'];
        $outKg[$e['from']] = ($outKg[$e['from']] ?? 0.0) + $e['kg'];
        if ($e['via'] === 'sale') $hasSale[$e['to']] = true;
        if ($e['via'] === 'sale_return') $hasSaleRet[$e['from']] = true;
        if (isset($sum[$e['via']])) $sum[$e['via']] += $e['kg'];
    }
    $nodes = [];
    foreach ($order as $key) {
        $raw = $rawOf($key);
        $kind = pb_chain_out_kind($key, $raw, $lots);
        if ($kind === 'sale' && !isset($hasSale[$key]) && isset($hasSaleRet[$key])) $kind = 'sale_return'; // فقط به‌عنوان «برگشتی» دیده شد
        $meta = $raw['meta'] ?? null; $kg = round($inKg[$key] ?? ($outKg[$key] ?? 0.0), 3);
        if ($kind === 'lot' || $kind === 'mix') {
            $l = $lots[(int)substr($key, 4)] ?? [];
            $kg = (float)($l['net_weight'] ?? 0);
            $ck = pb_code_kind_of((string)($l['code'] ?? ''));
            $meta = ['type_name' => (string)($l['type_name'] ?? ''), 'category' => (string)($l['category'] ?? ''), 'is_mix' => (int)($l['is_mix'] ?? 0),
                'remaining' => (float)($l['remaining'] ?? 0), 'seller' => (string)($l['seller'] ?? PB_CHAIN_NONAME), 'letter' => $ck['letter'], 'code_kind' => $ck['kind']];
        }
        $nodes[] = ['key' => $key, 'kind' => $kind, 'label' => (string)($raw['label'] ?? $key), 'code' => $raw['code'] ?? null,
            'date' => $raw['date'] ?? null, 'kg' => $kg, 'meta' => $meta];
    }

    // خلاصه
    $remaining = (float)$lots[$lotId]['remaining'];
    $seen = [$root => true];
    foreach ($D['items'] as $it) {
        $k = $it['key'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        if (str_starts_with($k, 'lot:')) $remaining += (float)($lots[(int)substr($k, 4)]['remaining'] ?? 0);
    }
    $l = $lots[$lotId]; $ck = pb_code_kind_of((string)($l['code'] ?? ''));
    return [
        'lot' => ['id' => $lotId, 'code' => $l['code'] ?? null, 'letter' => $ck['letter'], 'kind' => $ck['kind'], 'type_name' => (string)($l['type_name'] ?? ''),
            'category' => (string)($l['category'] ?? ''), 'date' => $l['date'] ?? null, 'net_weight' => (float)($l['net_weight'] ?? 0),
            'remaining' => (float)($l['remaining'] ?? 0), 'is_mix' => (int)($l['is_mix'] ?? 0)],
        'up' => pb_chain_tree($U['items']),
        'down' => pb_chain_tree($D['items']),
        'nodes' => $nodes,
        'edges' => $flat,
        'summary' => [
            'origins' => pb_chain_origins($root, $U['trav'], $U['cut'], $rawOf, $lots),
            'sold_kg' => round($sum['sale'], 3),
            'remaining_kg' => round($remaining, 3),
            'waste_kg' => round($sum['waste'], 3),
            'returned_kg' => round($sum['purchase_return'] + $sum['sale_return'], 3),
        ],
        'truncated' => $U['trunc'] || $D['trunc'],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// T4c — اکشن lot_chain.get (فقط خواندن؛ مجوز در permissions.php = همان lot_trace.get ⇒ purchases.view؛ اپراتور کارگاه آن را ندارد)
// ═══════════════════════════════════════════════════════════════════════════

/** شناسهٔ لات از lot_id یا کد (قدیمی/جدید)؛ فقط در همان مشتری. 0 = پیدا نشد. */
function pb_chain_resolve_lot(PDO $pdo, int $cid, array $p): int {
    $id = (int)($p['lot_id'] ?? 0);
    if ($id > 0) {
        $q = $pdo->prepare('SELECT id FROM purchases WHERE customer_id=? AND id=?');
        $q->execute([$cid, $id]);
        return (int)($q->fetchColumn() ?: 0);
    }
    $code = trim((string)($p['code'] ?? ''));
    if ($code === '') return 0;
    $q = $pdo->prepare('SELECT id FROM purchases WHERE customer_id=? AND UPPER(code)=UPPER(?) ORDER BY id DESC LIMIT 1');
    $q->execute([$cid, $code]);
    return (int)($q->fetchColumn() ?: 0);
}

/** منطق اکشن بدون out(): آرایهٔ پاسخ یا null (= پیدا نشد). نام‌ها فقط وقتی $withNames. */
function pb_chain_get(PDO $pdo, int $cid, array $p, bool $withNames): ?array {
    $lot = pb_chain_resolve_lot($pdo, $cid, $p);
    if ($lot <= 0) return null;
    $depth = max(1, min(10, (int)($p['max_depth'] ?? 10)));
    return pb_chain_build(pb_chain_load($pdo, $cid, $withNames), $lot, $depth);
}

function pb_chain_action(string $action, array $p, PDO $pdo, array $customer): void {
    $r = pb_chain_get($pdo, (int)$customer['id'], $p, pb_staff_can($customer, 'purchases.view'));
    if ($r === null) bad('لات یافت نشد', 404);
    out($r);
}
