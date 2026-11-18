<?php
declare(strict_types=1);
/**
 * توابع مشترک سؤال‌های داده‌محور (مرحله ۲): فقط SELECT، همه‌جا customer_id.
 * منطق مرجع: app.php (purchases.list، parties.list، dues.list، partyKey) — با همان فرمول بازنویسی شده؛ app.php تغییر نکرده.
 * این فایل خودکار توسط assistant.php (glob) و هر هندلر (require_once) بارگذاری می‌شود.
 */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }

/** عین partyKey() در app.php: یکسان‌سازی ی/ک عربی، trim و فشرده‌کردن فاصله‌ها */
function as_party_key($n): string {
    $n = (string)($n ?? '');
    $n = str_replace(['ي', 'ك'], ['ی', 'ک'], $n);
    $n = trim((string)preg_replace('/\s+/u', ' ', $n));
    return $n;
}

/** آیا جدول وجود دارد؟ (جدول‌های قرض/طرف‌حساب در app.php تنبل ساخته می‌شوند؛ دستیار نمی‌سازدشان) */
function as_rd_table_exists(PDO $pdo, string $name): bool {
    static $cache = [];
    $k = spl_object_id($pdo) . '|' . $name;
    if (!isset($cache[$k])) {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?");
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        }
        $st->execute([$name]);
        $cache[$k] = ((int)$st->fetchColumn()) > 0;
    }
    return $cache[$k];
}

/** کلید کوتاهِ ساخته‌شده توسط سرور برای گزینه‌ها؛ فقط در برابر فهرست خود همان مشتری تطبیق داده می‌شود */
function as_rd_key(int $cid, string $scope, string $value): string {
    return substr(hash('sha256', $cid . '|' . $scope . '|' . $value), 0, 16);
}

function as_rd_cur(): string {
    return (string)(as_config()['strings']['currency'] ?? 'تومان');
}

/** تاریخ YYYY-MM-DD به وقت Asia/Tehran، با $days روز اختلاف از امروز */
function as_rd_date_offset(int $days): string {
    $d = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $d = $d->setTime(0, 0, 0);
    if ($days !== 0) $d = $d->modify(($days > 0 ? '+' : '') . $days . ' day');
    return $d->format('Y-m-d');
}

/** دکمه‌های پایین پاسخ: پرسیدن دوباره + بازگشت به منو */
function as_rd_nav(string $q, string $label): array {
    return blk_buttons([
        ['id' => $q, 'label' => $label, 'icon' => '🔁', 'action' => as_action($q, 'start')],
        ['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')],
    ]);
}

// ------------------------------------------------------------------ موجودی

/** ردیف‌های خریدِ دارای موجودی (مخلوط‌ها هم هستند؛ مبدأها هنگام ساخت مخلوط از remaining کم شده‌اند ⇒ شمارش مضاعف نیست) */
function as_rd_stock_rows(PDO $pdo, int $cid): array {
    $base = 'FROM purchases p JOIN categories c ON c.id = p.category_id AND c.customer_id = p.customer_id
             WHERE p.customer_id = ? AND p.remaining > 0.000001 ORDER BY p.date DESC, p.id DESC';
    try {
        $st = $pdo->prepare('SELECT p.id, p.code, p.date, p.type_name, p.category_id, p.remaining, p.is_mix,
            p.small_sacks_remaining, p.large_sacks_remaining, c.name AS category_name ' . $base);
        $st->execute([$cid]);
    } catch (PDOException $e) { // دیتابیس قدیمی بدون ستون‌های گونی
        $st = $pdo->prepare('SELECT p.id, p.code, p.date, p.type_name, p.category_id, p.remaining, p.is_mix,
            NULL AS small_sacks_remaining, NULL AS large_sacks_remaining, c.name AS category_name ' . $base);
        $st->execute([$cid]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** گروه‌بندی بر اساس (دسته + نام جنس) در PHP (برای پرهیز از اختلاف collation). خروجی با کلید گروه، مرتب بر اساس کیلوگرم نزولی */
function as_rd_stock_groups(PDO $pdo, int $cid): array {
    $groups = [];
    foreach (as_rd_stock_rows($pdo, $cid) as $r) {
        $tn = (string)$r['type_name'];
        $catId = (int)$r['category_id'];
        $gk = as_rd_key($cid, 'item', $catId . '|' . $tn);
        if (!isset($groups[$gk])) {
            $groups[$gk] = ['key' => $gk, 'category_id' => $catId, 'category_name' => (string)$r['category_name'],
                'type_name' => $tn, 'kg' => 0.0, 'small' => 0.0, 'large' => 0.0, 'lots' => []];
        }
        $groups[$gk]['kg'] += (float)$r['remaining'];
        $groups[$gk]['small'] += (float)($r['small_sacks_remaining'] ?? 0);
        $groups[$gk]['large'] += (float)($r['large_sacks_remaining'] ?? 0);
        $groups[$gk]['lots'][] = $r;
    }
    uasort($groups, function (array $a, array $b): int {
        if (abs($a['kg'] - $b['kg']) > 1e-9) return $b['kg'] <=> $a['kg'];
        return strcmp($a['type_name'], $b['type_name']);
    });
    return $groups;
}

// ------------------------------------------------------------------ بدهی به طرف‌حساب‌ها

/**
 * STAGE11.2 — جمع‌های حساب طرف‌ها که در parties.list هست (برگشت از خرید/فروش، کمیسیون دلال، افتتاحیه، اعتبار مصرف‌شدهٔ سررسید).
 * همه فقط SELECT؛ کتابخانه‌های app.php (که جدول می‌سازند/می‌نویسند) لود نمی‌شوند. اگر جدول/ستونی هنوز ساخته نشده باشد (مشتریِ قدیمی که
 * هرگز آن بخش را استفاده نکرده)، همان بخش صفر حساب می‌شود. فرمول‌ها عیناً pb_preturn_party_sums / pb_sreturn_party_sums / pb_broker_party_sums /
 * pb_party_opening / pb_dues_credit_used_sums هستند؛ اگر آن‌ها عوض شدند اینجا هم باید عوض شود.
 */
function as_rd_safe_rows(PDO $pdo, string $sql, array $args): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return []; // جدول/ستون وجود ندارد = این بخش هرگز استفاده نشده
    }
}

/** برگشت‌ها: [partyKey => ['value'=>V,'refunded'=>R]] — $kind: 'purchase' | 'sale' */
function as_rd_return_sums(PDO $pdo, int $cid, string $kind): array {
    [$rt, $it] = $kind === 'sale' ? ['sale_returns', 'sale_return_items'] : ['purchase_returns', 'purchase_return_items'];
    $res = [];
    foreach (as_rd_safe_rows($pdo, "SELECT r.party, r.refund_amount, COALESCE((SELECT SUM(i.kg * i.unit_price) FROM $it i WHERE i.return_id=r.id AND i.customer_id=r.customer_id),0) AS total
        FROM $rt r WHERE r.customer_id=?", [$cid]) as $r) {
        $k = as_party_key($r['party']);
        if (!isset($res[$k])) $res[$k] = ['value' => 0.0, 'refunded' => 0.0];
        $res[$k]['value'] += (float)$r['total'];
        $res[$k]['refunded'] += (float)$r['refund_amount'];
    }
    return $res;
}

/** کمیسیون دلال (پرداخت‌کنندهٔ «ما»): [partyKey => ['due'=>..,'paid'=>..]] */
function as_rd_broker_sums(PDO $pdo, int $cid): array {
    $o = [];
    foreach (['purchases', 'sales'] as $tbl) {
        foreach (as_rd_safe_rows($pdo, "SELECT pa.name, COALESCE(SUM(d.broker_amount),0) AS s FROM $tbl d JOIN parties pa ON pa.id=d.broker_id AND pa.customer_id=d.customer_id
            WHERE d.customer_id=? AND d.broker_payer='us' AND d.broker_amount > 0 GROUP BY pa.name", [$cid]) as $r) {
            $k = as_party_key($r['name']);
            $o[$k]['due'] = ($o[$k]['due'] ?? 0.0) + (float)$r['s']; $o[$k]['paid'] = $o[$k]['paid'] ?? 0.0;
        }
    }
    foreach (as_rd_safe_rows($pdo, 'SELECT pa.name, COALESCE(SUM(b.amount),0) AS s FROM broker_payments b JOIN parties pa ON pa.id=b.broker_id AND pa.customer_id=b.customer_id WHERE b.customer_id=? GROUP BY pa.name', [$cid]) as $r) {
        $k = as_party_key($r['name']);
        $o[$k]['paid'] = ($o[$k]['paid'] ?? 0.0) + (float)$r['s']; $o[$k]['due'] = $o[$k]['due'] ?? 0.0;
    }
    return $o;
}

/** افتتاحیهٔ طرف‌حساب: [partyKey => signed] (+ بدهکار/طلب ما، − بستانکار/بدهی ما) */
function as_rd_opening_sums(PDO $pdo, int $cid): array {
    $res = [];
    foreach (as_rd_safe_rows($pdo, 'SELECT name, opening_balance, opening_side FROM parties WHERE customer_id=?', [$cid]) as $r) {
        $b = (float)(int)$r['opening_balance'];
        $res[as_party_key($r['name'])] = $r['opening_side'] === 'creditor' ? -$b : $b;
    }
    return $res;
}

/** Σ credit_used سررسیدهای تسویه‌شده: ['r'=>[party=>sum],'p'=>[party=>sum]] (مثل pb_dues_credit_used_sums) */
function as_rd_credit_used_sums(PDO $pdo, int $cid): array {
    $pay = 'JOIN payments pay ON pay.id = x.payment_id AND pay.customer_id = x.customer_id JOIN purchases p ON p.id = pay.purchase_id AND p.customer_id = x.customer_id';
    $rec = 'JOIN receipts rec ON rec.id = x.receipt_id AND rec.customer_id = x.customer_id JOIN sales s ON s.id = rec.sale_id AND s.customer_id = x.customer_id';
    $meta = [
        ['lump_tx', 'p.seller', $pay, false, "pay.pay_type IN ('lump','mixed')"],
        ['credit_inst', 'p.seller', $pay, false, "(pay.pay_type='mixed' OR (pay.pay_type='staged' AND pay.staged_type='credit'))"],
        ['cheque_inst', 'p.seller', $pay, false, "(pay.pay_type='mixed' OR (pay.pay_type='staged' AND pay.staged_type='cheque'))"],
        ['receipt_lump_tx', 's.buyer', $rec, true, "rec.recv_type='lump'"],
        ['receipt_credit_inst', 's.buyer', $rec, true, "rec.recv_type='staged' AND rec.staged_type='credit'"],
        ['receipt_cheque_inst', 's.buyer', $rec, true, "rec.recv_type='staged' AND rec.staged_type='cheque'"],
    ];
    $res = ['r' => [], 'p' => []];
    foreach ($meta as [$t, $pcol, $join, $recv, $cond]) {
        foreach (as_rd_safe_rows($pdo, "SELECT $pcol AS party, SUM(COALESCE(x.credit_used,0)) AS u FROM `$t` x $join WHERE x.customer_id = ? AND x.paid = 1 AND COALESCE(x.credit_used,0) > 0 AND $cond GROUP BY $pcol", [$cid]) as $r) {
            $k = as_party_key($r['party']);
            $side = $recv ? 'r' : 'p';
            $res[$side][$k] = ($res[$side][$k] ?? 0.0) + (float)$r['u'];
        }
    }
    return $res;
}


/**
 * حساب طرف‌ها مطابق parties.list: weOwe = خریدهای غیرمخلوط − پرداخت‌های paid=1؛ loanPayable = loanTaken − repaidByUs.
 * خروجی: آرایه‌ی ایندکس‌دار از ['key','name','weOwe','loanPayable','debt'] مرتب بر اساس بیشترین بدهی.
 */
function as_rd_party_debts(PDO $pdo, int $cid): array {
    $map = [];
    $acc = function ($name) use (&$map): string {
        $k = as_party_key($name);
        if (!isset($map[$k])) $map[$k] = ['name' => $k, 'bought' => 0.0, 'paid' => 0.0, 'loanTaken' => 0.0, 'repaidByUs' => 0.0,
                'sold' => 0.0, 'received' => 0.0, 'loanGiven' => 0.0, 'repaidToUs' => 0.0];
        return $k;
    };
    $settledOf = function ($type, $staged, $lp, $cp, $hp): float {
        if ($type === 'lump') return (float)$lp;
        if ($type === 'staged' && $staged === 'credit') return (float)$cp;
        if ($type === 'staged' && $staged === 'cheque') return (float)$hp;
        if ($type === 'mixed') return (float)$lp + (float)$cp + (float)$hp;
        return 0.0;
    };
    // خریدها (غیرمخلوط) و پرداخت‌های تسویه‌شده — همان SQL مرجع، با customer_id روی زیرکوئری‌ها هم
    $pur = $pdo->prepare("SELECT p.seller AS party, p.net_weight AS net, p.price AS price, pay.pay_type AS pt, pay.staged_type AS st,
        COALESCE((SELECT SUM(amount) FROM lump_tx WHERE payment_id=pay.id AND customer_id=pay.customer_id AND paid=1),0) AS lp,
        COALESCE((SELECT SUM(amount) FROM credit_inst WHERE payment_id=pay.id AND customer_id=pay.customer_id AND paid=1),0) AS cp,
        COALESCE((SELECT SUM(amount) FROM cheque_inst WHERE payment_id=pay.id AND customer_id=pay.customer_id AND paid=1),0) AS hp
        FROM purchases p LEFT JOIN payments pay ON pay.purchase_id=p.id AND pay.customer_id=p.customer_id
        WHERE p.customer_id=? AND p.is_mix=0");
    $pur->execute([$cid]);
    foreach ($pur->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = $acc($r['party']);
        $map[$k]['bought'] += (float)$r['net'] * (float)$r['price'];
        $map[$k]['paid'] += $settledOf($r['pt'], $r['st'], $r['lp'], $r['cp'], $r['hp']);
    }
    // فروش‌ها و وصولی‌ها (دریافتی‌های تسویه‌شده) — همان SQL مرجعِ parties.list؛ خریدارانِ فروش‌ها هم در فهرست طرف‌ها هستند
    $sal = $pdo->prepare("SELECT s.buyer AS party, s.net_weight AS net, s.price AS price, rec.recv_type AS rt, rec.staged_type AS st,
        COALESCE((SELECT SUM(amount) FROM receipt_lump_tx WHERE receipt_id=rec.id AND customer_id=rec.customer_id AND paid=1),0) AS lp,
        COALESCE((SELECT SUM(amount) FROM receipt_credit_inst WHERE receipt_id=rec.id AND customer_id=rec.customer_id AND paid=1),0) AS cp,
        COALESCE((SELECT SUM(amount) FROM receipt_cheque_inst WHERE receipt_id=rec.id AND customer_id=rec.customer_id AND paid=1),0) AS hp
        FROM sales s LEFT JOIN receipts rec ON rec.sale_id=s.id AND rec.customer_id=s.customer_id WHERE s.customer_id=?");
    $sal->execute([$cid]);
    foreach ($sal->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = $acc($r['party']);
        $map[$k]['sold'] += (float)$r['net'] * (float)$r['price'];
        $map[$k]['received'] += $settledOf($r['rt'], $r['st'], $r['lp'], $r['cp'], $r['hp']);
    }
    // طرف‌حساب‌های تعریف‌شده‌ی دستی
    if (as_rd_table_exists($pdo, 'parties')) {
        $ps = $pdo->prepare('SELECT name FROM parties WHERE customer_id=?');
        $ps->execute([$cid]);
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) $acc($r['name']);
    }
    // دفتر قرض
    if (as_rd_table_exists($pdo, 'loan_entries')) {
        $ls = $pdo->prepare('SELECT party, kind, amount FROM loan_entries WHERE customer_id=?');
        $ls->execute([$cid]);
        foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $acc($r['party']);
            $amt = (float)$r['amount'];
            if ($r['kind'] === 'taken') $map[$k]['loanTaken'] += $amt;
            elseif ($r['kind'] === 'repay_out') $map[$k]['repaidByUs'] += $amt;
            elseif ($r['kind'] === 'given') $map[$k]['loanGiven'] += $amt;
            elseif ($r['kind'] === 'repay_in') $map[$k]['repaidToUs'] += $amt;
        }
    }
    // STAGE11.2: برگشت از خرید = کم‌شدن بدهی؛ بازپرداخت آن = برعکسِ پرداخت. برگشت از فروش/بازپرداخت آن برای طلب. کمیسیون دلال = بدهی؛ پرداخت آن = پرداخت (مثل parties.list)
    foreach (as_rd_return_sums($pdo, $cid, 'purchase') as $rk => $rs) { $k = $acc($rk); $map[$k]['bought'] -= $rs['value']; $map[$k]['paid'] -= $rs['refunded']; }
    foreach (as_rd_return_sums($pdo, $cid, 'sale') as $rk => $rs) { $k = $acc($rk); $map[$k]['sold'] -= $rs['value']; $map[$k]['received'] -= $rs['refunded']; }
    foreach (as_rd_broker_sums($pdo, $cid) as $rk => $rs) { $k = $acc($rk); $map[$k]['bought'] += $rs['due']; $map[$k]['paid'] += $rs['paid']; }
    $opening = as_rd_opening_sums($pdo, $cid);
    foreach ($opening as $ok => $_) $acc($ok);
    $list = [];
    foreach ($map as $name => $a) {
        $weOwe = $a['bought'] - $a['paid'];
        $loanPayable = $a['loanTaken'] - $a['repaidByUs'];
        $theyOwe = $a['sold'] - $a['received'];              // طلب ما بابت فروش (هنوز وصول‌نشده)
        $loanReceivable = $a['loanGiven'] - $a['repaidToUs']; // طلب ما بابت قرضِ داده‌شده
        $nm = (string)$name; // کلیدهای عددی‌نما در PHP به int تبدیل می‌شوند
        $op = (float)($opening[$nm] ?? 0.0);       // + بدهکار (طلب ما) / − بستانکار (بدهی ما)
        $openDebt = $op < 0 ? -$op : 0.0;
        $openRecv = $op > 0 ? $op : 0.0;
        $list[] = ['key' => as_rd_key($cid, 'party', $nm), 'name' => $nm,
            'weOwe' => $weOwe, 'loanPayable' => $loanPayable, 'openingDebt' => $openDebt, 'debt' => $weOwe + $loanPayable + $openDebt,
            'theyOwe' => $theyOwe, 'loanReceivable' => $loanReceivable, 'openingReceivable' => $openRecv, 'receivable' => $theyOwe + $loanReceivable + $openRecv];
    }
    usort($list, function (array $a, array $b): int {
        if (abs($a['debt'] - $b['debt']) > 1e-9) return $b['debt'] <=> $a['debt'];
        return strcmp($a['name'], $b['name']);
    });
    return $list;
}

// ------------------------------------------------------------------ سررسیدها

/**
 * بازتولید dues.list: ۶ جدول + loan_dues؛ فقط paid=0؛ قرضِ کاملاً تسویه‌شده حذف.
 * هر ردیف: table,id,receivable,kind,party,label,ref,bank,amount,date(YYYY-MM-DD),paid. مرتب بر اساس تاریخ.
 */
function as_rd_dues_all(PDO $pdo, int $cid): array {
    $out = [];
    $add = function (string $sql, string $table, bool $receivable, string $kind) use ($pdo, $cid, &$out): void {
        $st = $pdo->prepare($sql);
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['paid'] === 1) continue; // سررسیدهای تسویه‌شده نمی‌آیند (مثل loadDues اپ)
            $out[] = ['table' => $table, 'id' => (int)$r['id'], 'receivable' => $receivable, 'kind' => $kind,
                'party' => as_party_key($r['party']), 'label' => (string)($r['label'] ?? ''),
                'ref' => ($r['ref'] === null ? '' : (string)$r['ref']), 'bank' => ($r['bank'] === null ? '' : (string)$r['bank']),
                'amount' => (float)$r['amount'], 'date' => substr((string)$r['date'], 0, 10), 'paid' => false];
        }
    };
    $payBase = 'FROM %T% x JOIN payments pay ON pay.id = x.payment_id AND pay.customer_id = x.customer_id JOIN purchases p ON p.id = pay.purchase_id AND p.customer_id = x.customer_id WHERE x.customer_id = ? AND (x.paid IS NULL OR x.paid <> 1) AND ';
    $recBase = 'FROM %T% x JOIN receipts rec ON rec.id = x.receipt_id AND rec.customer_id = x.customer_id JOIN sales s ON s.id = rec.sale_id AND s.customer_id = x.customer_id WHERE x.customer_id = ? AND (x.paid IS NULL OR x.paid <> 1) AND ';
    $add(str_replace('%T%', 'lump_tx', "SELECT x.id,x.amount,x.date,x.paid,p.seller AS party,p.type_name AS label,NULL AS ref,NULL AS bank $payBase pay.pay_type IN ('lump','mixed')"), 'lump_tx', false, 'یکجا');
    $add(str_replace('%T%', 'credit_inst', "SELECT x.id,x.amount,x.due_date AS date,x.paid,p.seller AS party,p.type_name AS label,NULL AS ref,NULL AS bank $payBase (pay.pay_type='mixed' OR (pay.pay_type='staged' AND pay.staged_type='credit'))"), 'credit_inst', false, 'قسط');
    $add(str_replace('%T%', 'cheque_inst', "SELECT x.id,x.amount,x.due_date AS date,x.paid,p.seller AS party,p.type_name AS label,x.cheque_no AS ref,x.bank_name AS bank $payBase (pay.pay_type='mixed' OR (pay.pay_type='staged' AND pay.staged_type='cheque'))"), 'cheque_inst', false, 'چک');
    $add(str_replace('%T%', 'receipt_lump_tx', "SELECT x.id,x.amount,x.date,x.paid,s.buyer AS party,'فروش' AS label,NULL AS ref,NULL AS bank $recBase rec.recv_type='lump'"), 'receipt_lump_tx', true, 'یکجا');
    $add(str_replace('%T%', 'receipt_credit_inst', "SELECT x.id,x.amount,x.due_date AS date,x.paid,s.buyer AS party,'فروش' AS label,NULL AS ref,NULL AS bank $recBase rec.recv_type='staged' AND rec.staged_type='credit'"), 'receipt_credit_inst', true, 'قسط');
    $add(str_replace('%T%', 'receipt_cheque_inst', "SELECT x.id,x.amount,x.due_date AS date,x.paid,s.buyer AS party,'فروش' AS label,x.cheque_no AS ref,x.bank_name AS bank $recBase rec.recv_type='staged' AND rec.staged_type='cheque'"), 'receipt_cheque_inst', true, 'چک');

    // v71: اعتبار برگشت از فروش/خرید (منهای بخش مصرف‌شده) مثل صفحهٔ سررسیدها از ردیف‌های پرداخت‌نشده کم می‌شود.
    // STAGE11.2: جمع‌ها با SELECT خودِ دستیار؛ تخصیص همان تابع خالص dues_credit.php (بدون DB/partyKey) است.
    require_once __DIR__ . '/dues_credit.php';
    $out = pb_dues_apply_return_credit($out, as_rd_return_sums($pdo, $cid, 'sale'), as_rd_return_sums($pdo, $cid, 'purchase'), as_rd_credit_used_sums($pdo, $cid));

    // سررسید قرض: اعطای قرض = دریافتیِ ما، دریافت قرض = پرداختیِ ما
    if (as_rd_table_exists($pdo, 'loan_entries') && as_rd_table_exists($pdo, 'loan_dues')) {
        $sumSt = $pdo->prepare('SELECT party, kind, SUM(amount) AS s FROM loan_entries WHERE customer_id=? GROUP BY party, kind');
        $sumSt->execute([$cid]);
        $sums = [];
        foreach ($sumSt->fetchAll(PDO::FETCH_ASSOC) as $r) $sums[$r['party'] . '|' . $r['kind']] = (float)$r['s'];
        $outstanding = function (string $party, string $kind) use ($sums): float {
            $rep = $kind === 'given' ? 'repay_in' : 'repay_out';
            return ($sums[$party . '|' . $kind] ?? 0.0) - ($sums[$party . '|' . $rep] ?? 0.0);
        };
        $ld = $pdo->prepare('SELECT d.id, d.amount, d.due_date, d.paid, l.party, l.kind FROM loan_dues d JOIN loan_entries l ON l.uid = d.loan_uid AND l.customer_id = d.customer_id WHERE d.customer_id = ? AND (d.paid IS NULL OR d.paid <> 1)');
        $ld->execute([$cid]);
        foreach ($ld->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['paid'] === 1) continue;
            if ($outstanding((string)$r['party'], (string)$r['kind']) <= 0.5) continue; // قرض کاملاً تسویه شده
            $given = $r['kind'] === 'given';
            $out[] = ['table' => 'loan_dues', 'id' => (int)$r['id'], 'receivable' => $given, 'kind' => 'قرض',
                'party' => as_party_key($r['party']), 'label' => $given ? 'قرض اعطایی' : 'قرض دریافتی', 'ref' => '', 'bank' => '',
                'amount' => (float)$r['amount'], 'date' => substr((string)$r['due_date'], 0, 10), 'paid' => false];
        }
    }
    usort($out, function (array $a, array $b): int {
        $c = strcmp($a['date'], $b['date']);
        return $c !== 0 ? $c : strcmp($a['party'], $b['party']);
    });
    return $out;
}

/** فیلتر بازه: $from/$to رشته‌ی YYYY-MM-DD یا null (بی‌کران)؛ مرزها شامل‌اند. $receivable: true=وصول، false=بدهی */
function as_rd_dues_band(array $items, bool $receivable, ?string $from, ?string $to): array {
    $res = [];
    foreach ($items as $it) {
        if ($it['receivable'] !== $receivable) continue;
        if ($from !== null && strcmp($it['date'], $from) < 0) continue;
        if ($to !== null && strcmp($it['date'], $to) > 0) continue;
        $res[] = $it;
    }
    return $res;
}
