<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
require_once __DIR__ . '/auth_token.php'; // STAGE14
/**
 * توابع مشترک دستیار پسته بازار (پروتکل نسخه ۱).
 * قوانین: فقط‌خواندنی روی داده‌های کسب‌وکار؛ customer_id فقط از احراز هویت می‌آید؛
 * تنها نوشتن مجاز: جدول‌های assistant_* (حد نرخ، لاگ حسابرسی و بعداً چت پشتیبانی).
 * این فایل app.php را require نمی‌کند (آن فایل هنگام require اجرا می‌شود).
 */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }

// ------------------------------------------------------------ تنظیمات و زمینه

function as_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $c = require __DIR__ . '/assistant_config.php';
        $cfg = is_array($c) ? $c : [];
    }
    return $cfg;
}

/** زمینهٔ درخواست جاری (برای لاگ حسابرسی). با آرایه، ادغام می‌کند. */
function as_ctx(?array $set = null): array {
    static $ctx = [];
    if ($set !== null) $ctx = array_merge($ctx, $set);
    return $ctx;
}

// ------------------------------------------------------------ خروجی

/** پاسخ JSON و پایان درخواست. برای درخواست‌های احراز‌شده یک ردیف حسابرسی (بدون محتوای مالی) می‌نویسد. */
function as_out(array $data, int $code = 200): void {
    $ctx = as_ctx();
    if (!empty($ctx['cid']) && !empty($ctx['pdo']) && (as_config()['audit_log'] ?? true)) {
        try {
            $ctx['pdo']->prepare('INSERT INTO assistant_audit (customer_id, q, http_code, created_at) VALUES (?,?,?,NOW())')
                ->execute([(int)$ctx['cid'], mb_substr((string)($ctx['q'] ?? ''), 0, 30), $code]);
        } catch (Throwable $e) {
            error_log('[assistant] audit: ' . $e->getMessage());
        }
    }
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function as_bad(string $msg, int $code = 400): void {
    as_out(['v' => (int)(as_config()['protocol'] ?? 1), 'error' => $msg], $code);
}

// ------------------------------------------------------------ ورودی

function as_read_request(): array {
    $max = (int)(as_config()['limits']['max_body_bytes'] ?? 65536);
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $max) as_bad('درخواست نامعتبر است', 413);
    $body = (string)file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($body) > $max) as_bad('درخواست نامعتبر است', 413);
    $raw = json_decode($body, true);
    if (!is_array($raw)) as_bad('درخواست نامعتبر است', 400);
    return $raw;
}

function as_client_ip(): string {
    // عمداً فقط REMOTE_ADDR (هدر X-Forwarded-For قابل جعل است)
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0');
}

/** مقدار متنی از params با سقف طول؛ نامعتبر = null */
function as_param_str(array $params, string $key, int $max = 200): ?string {
    if (!isset($params[$key]) || !is_string($params[$key])) return null;
    $v = trim($params[$key]);
    if ($v === '' || mb_strlen($v) > $max) return null;
    return $v;
}

// ------------------------------------------------------------ احراز هویت و پلن

/**
 * مشتری را با phone+token احراز می‌کند و فقط پلن ارزی را می‌پذیرد.
 * هر شکستی با پاسخ عمومی و خروج. customer_id هرگز از ورودی کلاینت خوانده نمی‌شود.
 */
function as_authenticate(PDO $pdo, array $req): array {
    $lim = as_config()['limits'] ?? [];
    $ipKey = 'assistant|ip|' . as_client_ip();
    $win = (int)($lim['ip_fail_window'] ?? 900);
    throttle_check($ipKey, (int)($lim['ip_fail_max'] ?? 30), $win); // خودش 429 می‌دهد

    $phoneRaw = $req['phone'] ?? '';
    $token = $req['token'] ?? '';
    if (!is_string($phoneRaw) || !is_string($token) || $phoneRaw === '' || $token === ''
        || strlen($phoneRaw) > 40 || strlen($token) > 200) {
        as_bad('نیاز به ورود مجدد است', 401);
    }
    $phone = normalize_phone($phoneRaw);
    ensure_plan_column($pdo);
    // STAGE14: احراز یکپارچه با auth_token.php (هش؛ در P1 فال‌بک ستون قدیمی)
    $c = tok_auth($pdo, $phone, $token);
    if (!is_array($c)) {
        throttle_fail($ipKey, $win);
        // پیام یکسان برای «شماره ناموجود» و «توکن اشتباه»
        as_bad('نشست شما نامعتبر است، دوباره وارد شوید', 401);
    }
    $c['_presented_token'] = $token; // STAGE14: فقط در حافظهٔ درخواست؛ حلقهٔ ثبت دستیار همین را می‌فرستد
    if (!$c['active']) as_bad('اشتراک شما فعال نیست', 403);
    if (strtotime((string)$c['expiry_date']) < strtotime('today')) as_bad('اشتراک شما منقضی شده است', 403);
    if (customer_plan($c) !== PLAN_FX) as_bad('دستیار فقط برای پلن ارزی فعال است', 403);
    return $c;
}

// ------------------------------------------------------------ جدول‌ها و حد نرخ

function as_ensure_tables(PDO $pdo): void {
    try {
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS assistant_rate (
            customer_id INT UNSIGNED NOT NULL,
            ts INT UNSIGNED NOT NULL,
            KEY idx_ar (customer_id, ts),
            KEY idx_ar_ts (ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS assistant_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            q VARCHAR(30) NOT NULL DEFAULT '',
            http_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            created_at DATETIME NOT NULL,
            KEY idx_aa (customer_id, created_at),
            KEY idx_aa_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // چت پشتیبانی (مرحله ۴): یک گفتگو برای هر مشتری
        // unread_support = پیام‌های کاربر که پشتیبان نخوانده؛ unread_user = پیام‌های پشتیبان که کاربر نخوانده
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS assistant_threads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            last_msg_at DATETIME NULL,
            unread_support INT UNSIGNED NOT NULL DEFAULT 0,
            unread_user INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_at_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS assistant_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            sender ENUM('user','support') NOT NULL,
            body TEXT NOT NULL,
            client_id VARCHAR(40) NULL,
            created_at DATETIME NOT NULL,
            read_at DATETIME NULL,
            KEY idx_am_thread (thread_id, id),
            KEY idx_am_customer (customer_id, id),
            UNIQUE KEY uniq_am_client (customer_id, client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // پسته‌کاو (A3): مصرف روزانهٔ هر مشتری (فقط عدد؛ بدون محتوای پرسش/پاسخ)
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS assistant_ai_usage (
            customer_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            calls INT UNSIGNED NOT NULL DEFAULT 0,
            tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
            tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (customer_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // پسته‌کاو (A9): سهمیهٔ روزانهٔ اختصاصی هر مشتری (فقط ادمین می‌نویسد). نبود ردیف = سقف پیش‌فرض کانفیگ.
        pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS assistant_ai_quota (
            customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
            daily_tokens INT UNSIGNED NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[assistant] ensure_tables: ' . $e->getMessage());
    }
}

/** حداکثر N درخواست در دقیقه برای هر مشتری (جدول assistant_rate) */
function as_rate_limit(PDO $pdo, int $cid): void {
    $limit = (int)(as_config()['limits']['per_minute'] ?? 60);
    $now = time();
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM assistant_rate WHERE customer_id=? AND ts > ?');
        $st->execute([$cid, $now - 60]);
        $over = (int)$st->fetchColumn() >= $limit;
        if (!$over) {
            $pdo->prepare('INSERT INTO assistant_rate (customer_id, ts) VALUES (?,?)')->execute([$cid, $now]);
            if (mt_rand(1, 100) === 1) { // پاکسازی تصادفی ردیف‌های قدیمی
                $pdo->prepare('DELETE FROM assistant_rate WHERE ts < ?')->execute([$now - 3600]);
                $pdo->prepare('DELETE FROM assistant_audit WHERE created_at < (NOW() - INTERVAL 90 DAY)')->execute();
            }
        }
    } catch (Throwable $e) {
        error_log('[assistant] rate: ' . $e->getMessage());
        return; // خطای زیرساخت نباید کاربر را قفل کند
    }
    if ($over) {
        as_ctx(['cid' => 0]); // درخواست‌های ردشده را در لاگ حسابرسی نمی‌نویسیم (جلوگیری از پرشدن جدول)
        as_bad('درخواست‌ها بیش از حد است؛ چند لحظه بعد دوباره تلاش کنید', 429);
    }
}

// ------------------------------------------------------------ تاریخ شمسی و قالب عدد

/** میلادی → شمسی: [سال، ماه، روز] */
function as_g2j(int $gy, int $gm, int $gd): array {
    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function as_fa_digits(string $s): string {
    return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

/** 'YYYY-MM-DD' میلادی → '۱۴۰۵/۰۷/۲۸'. ورودی نامعتبر همان‌طور برمی‌گردد. */
function as_jdate(?string $ymd): string {
    if ($ymd === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return (string)$ymd;
    }
    [$jy, $jm, $jd] = as_g2j((int)$m[1], (int)$m[2], (int)$m[3]);
    return as_fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}

/** عدد با ارقام فارسی و جداکنندهٔ هزارگان. $dec رقم اعشار؛ صفرهای انتهایی اعشار حذف می‌شوند. */
function as_num(float $n, int $dec = 0): string {
    $n = round($n, $dec);
    if ($n == 0.0) $n = 0.0; // جلوگیری از «-0»
    $s = number_format($n, $dec, '.', ',');
    if ($dec > 0 && strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
    return strtr(as_fa_digits($s), [',' => '٬', '.' => '٫']);
}

function as_money_unit(): string {
    $m = as_ctx()['money'] ?? ['display' => 'toman'];
    return $m['display'] === 'rial' ? 'ریال' : 'تومان';
}

/** S35.T35.12: مبلغ ریالی/تومانی با واحد ذخیره/نمایش شرکت (pb_fmt)؛ برچسب واحد نمایش همیشه نشان داده می‌شود. */
function as_money($n, bool $label = true): string {
    $m = as_ctx()['money'] ?? ['stored' => 'toman', 'display' => 'toman'];
    return pb_fmt_money((int)round((float)$n), (string)$m['stored'], (string)$m['display'], 'fa', $label);
}

// ------------------------------------------------------------ سازندهٔ بلوک‌ها (پروتکل v1)

/** عملی که کلاینت وقتی کاربر روی دکمه/گزینه می‌زند عیناً به سرور می‌فرستد */
function as_action(string $q, string $step = 'start', array $params = []): array {
    $a = ['q' => $q, 'step' => $step];
    if ($params) $a['params'] = $params;
    return $a;
}
function blk_text(string $text, string $role = 'assistant'): array {
    return ['type' => 'text', 'role' => $role, 'text' => $text];
}
function blk_notice(string $text, string $tone = 'info'): array {
    return ['type' => 'notice', 'tone' => $tone, 'text' => $text];
}
/** items: [{id,label,icon?,badge?,action}] */
function blk_buttons(array $items): array {
    return ['type' => 'buttons', 'items' => array_values($items)];
}
/** items: [{label,sublabel?,action}] — فهرست انتخابی (جنس/طرف‌حساب) */
function blk_choice(string $title, array $items, bool $search = false): array {
    return ['type' => 'choice', 'title' => $title, 'search' => $search, 'items' => array_values($items)];
}
/**
 * fields: [{name,label,kind:'number|text',hint?}]؛ اگر opts['repeat'] باشد، فیلدها یک «ردیف» تکرارشونده‌اند
 * (opts: repeat{min,max,add_label}). submit: {label, action}
 */
function blk_form(string $title, array $fields, array $submit, array $opts = []): array {
    return ['type' => 'form', 'title' => $title, 'fields' => array_values($fields), 'submit' => $submit] + $opts;
}
/** columns: [{key,label,align?}]؛ rows: آرایه‌ای از آرایه‌های هم‌ترتیب با columns (همه رشتهٔ آمادهٔ نمایش) */
function blk_table(string $title, array $columns, array $rows, ?array $footer = null): array {
    $b = ['type' => 'table', 'title' => $title, 'columns' => array_values($columns), 'rows' => array_values($rows)];
    if ($footer !== null) $b['footer'] = array_values($footer);
    return $b;
}
/** lines: [{label,value,raw?,tone?}]؛ highlight: متن درشت بالای کارت */
function blk_card(string $title, array $lines, ?string $highlight = null): array {
    $b = ['type' => 'card', 'title' => $title, 'lines' => array_values($lines)];
    if ($highlight !== null) $b['highlight'] = $highlight;
    return $b;
}
/** messages: [{id,sender:'user|support|assistant',body,time}]؛ poll/send: عمل‌هایی که کلاینت می‌فرستد */
function blk_chat(array $messages, array $poll, array $send): array {
    return ['type' => 'chat', 'messages' => array_values($messages), 'poll' => $poll, 'send' => $send];
}
/** تأیید پیش از ثبت (برای فاز آینده) */
function blk_confirm(string $title, array $lines, array $confirmAction, string $label = 'تأیید'): array {
    return ['type' => 'confirm', 'title' => $title, 'lines' => array_values($lines),
            'confirm' => ['label' => $label, 'action' => $confirmAction], 'cancel' => ['label' => 'انصراف']];
}

/** پاسخ استاندارد. $extra می‌تواند data_note را null/عوض کند. */
function as_response(array $blocks, array $extra = []): array {
    $cfg = as_config();
    return array_merge([
        'v'         => (int)($cfg['protocol'] ?? 1),
        'title'     => (string)($cfg['strings']['title'] ?? ''),
        'theme'     => (array)($cfg['theme'] ?? []),
        'blocks'    => array_values($blocks),
        'data_note' => (string)($cfg['strings']['data_note'] ?? ''),
    ], $extra);
}

// ------------------------------------------------------------ رجیستری اکشن‌ها

/** رجیستری (ثبت با as_register؛ خواندن بدون آرگومان) */
function as_registry(?string $id = null, $fn = null, bool $mutates = false): array {
    static $r = [];
    if ($id !== null) $r[$id] = ['fn' => $fn, 'mutates' => $mutates];
    return $r;
}
/** handler(array $ctx): array — ctx: pdo, cid, customer, step, params, q */
function as_register(string $id, callable $handler, bool $mutates = false): void {
    as_registry($id, $handler, $mutates);
}

/** تعداد پیام خوانده‌نشدهٔ پشتیبان؛ مرحلهٔ ۴ تابع as_support_unread را تعریف می‌کند */
function as_unread_support(PDO $pdo, int $cid): int {
    return function_exists('as_support_unread') ? (int)as_support_unread($pdo, $cid) : 0;
}

function as_h_menu(array $ctx): array {
    $cfg = as_config();
    $items = [];
    $unread = as_unread_support($ctx['pdo'], (int)$ctx['cid']);
    foreach ((array)($cfg['menu'] ?? []) as $m) {
        if (empty($m['enabled']) || empty($m['id'])) continue;
        $it = ['id' => (string)$m['id'], 'label' => (string)($m['label'] ?? $m['id']),
               'icon' => (string)($m['icon'] ?? ''), 'action' => as_action((string)$m['id'], 'start')];
        if ($m['id'] === 'support' && $unread > 0) $it['badge'] = $unread;
        $items[] = $it;
    }
    return as_response(
        [blk_text((string)($cfg['strings']['greeting'] ?? '')), blk_buttons($items)],
        ['data_note' => null, 'unread_support' => $unread]
    );
}

/** اکشن را اجرا می‌کند و پاسخ می‌دهد (همیشه با exit). q باید قبلاً قالب‌اش اعتبارسنجی شده باشد. */
function as_dispatch(array $ctx, string $q): void {
    $cfg = as_config();
    if ($q === 'menu') as_out(as_h_menu($ctx));

    $allowed = false;
    foreach ((array)($cfg['menu'] ?? []) as $m) {
        if (($m['id'] ?? '') === $q && !empty($m['enabled'])) { $allowed = true; break; }
    }
    if (!$allowed) as_bad('درخواست نامعتبر است', 400);

    $reg = as_registry();
    if (!isset($reg[$q])) { // هنوز پیاده‌سازی نشده
        as_out(as_response([
            blk_notice((string)($cfg['strings']['coming_soon'] ?? ''), 'info'),
            blk_buttons([['id' => 'menu', 'label' => 'بازگشت به منو', 'icon' => '↩️', 'action' => as_action('menu')]]),
        ], ['data_note' => null]));
    }
    if ($reg[$q]['mutates'] && empty($cfg['writes_enabled'])) {
        as_bad('این عملیات در حال حاضر فعال نیست', 403);
    }
    $res = ($reg[$q]['fn'])($ctx);
    if (!is_array($res)) as_bad('خطای داخلی؛ لطفاً دوباره تلاش کنید', 500);
    as_out($res);
}
