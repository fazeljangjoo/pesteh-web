<?php
declare(strict_types=1);
/**
 * مرحلهٔ ۴ — چت پشتیبانی (سمت کاربر). اکشن: support
 *   step = start | poll : params.after_id (اختیاری) ⇒ بلوک chat با پیام‌های همین مشتری
 *   step = send         : params.body (الزامی)، params.client_id (اختیاری؛ جلوگیری از ارسال تکراری)
 * قوانین: customer_id فقط از ctx (احراز‌شده) می‌آید؛ متن ساده است و هیچ HTML ذخیره/رندر نمی‌شود.
 * این فایل توسط assistant.php با glob بارگذاری می‌شود.
 */
if (!defined('PB_ASSISTANT')) { http_response_code(403); exit; }

const AS_SUP_PAGE = 50;        // حداکثر پیام در هر پاسخ
const AS_SUP_DUP_SECONDS = 2;  // ارسال متن یکسان پشت‌سرهم در این بازه، نادیده گرفته می‌شود

// ------------------------------------------------------------ اعتبارسنجی (توابع خالص؛ خطا = InvalidArgumentException با پیام فارسی)

/** متن پیام: حذف کاراکترهای کنترلی، یکسان‌سازی خط‌جدید و trim. null = غیررشته یا UTF-8 خراب. */
function as_sup_clean_body($raw): ?string {
    if (!is_string($raw)) return null;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $raw); // کنترلی + bidi override (جعل نمایش)
    if ($s === null) return null;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    return trim($s);
}

/** بررسی طول و خالی‌نبودن متن تمیز‌شده */
function as_sup_check_body(?string $clean, int $max): string {
    if ($clean === null) throw new InvalidArgumentException('پیام نامعتبر است');
    if ($clean === '') throw new InvalidArgumentException('پیام خالی است');
    if (mb_strlen($clean) > $max) throw new InvalidArgumentException('پیام بیش از ' . as_num((float)$max) . ' حرف است');
    return $clean;
}

/** شناسهٔ کلاینتی برای جلوگیری از تکرار: null اگر ارسال نشده؛ نامعتبر ⇒ خطا */
function as_sup_client_id($raw): ?string {
    if ($raw === null || $raw === '') return null;
    if (!is_string($raw) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $raw)) {
        throw new InvalidArgumentException('درخواست نامعتبر است');
    }
    return $raw;
}

/** after_id: عدد صحیح نامنفی؛ خالی = ۰ (از ابتدای آخرین صفحه) */
function as_sup_parse_after($raw): int {
    if ($raw === null || $raw === '') return 0;
    if (is_int($raw)) $n = $raw;
    elseif (is_string($raw) && ctype_digit($raw) && strlen($raw) <= 18) $n = (int)$raw;
    else throw new InvalidArgumentException('درخواست نامعتبر است');
    if ($n < 0) throw new InvalidArgumentException('درخواست نامعتبر است');
    return $n;
}

/** زمان پیام به شمسی: '۱۴۰۵/۰۷/۲۸ ۱۴:۳۰' (منطقهٔ زمانی همان پیش‌فرض برنامه است) */
function as_sup_time(string $dt): string {
    $ts = strtotime($dt);
    if ($ts === false) return '';
    return as_jdate(date('Y-m-d', $ts)) . ' ' . as_fa_digits(date('H:i', $ts));
}

/** ردیف دیتابیس ⇒ پیام پروتکل */
function as_sup_fmt(array $r): array {
    return [
        'id'     => (int)$r['id'],
        'sender' => (string)$r['sender'],
        'body'   => (string)$r['body'],
        'time'   => as_sup_time((string)$r['created_at']),
    ];
}

// ------------------------------------------------------------ دسترسی به داده (همه با customer_id)

function as_sup_find_thread(PDO $pdo, int $cid): ?array {
    $st = $pdo->prepare('SELECT id, status, unread_user FROM assistant_threads WHERE customer_id = ? LIMIT 1');
    $st->execute([$cid]);
    $r = $st->fetch();
    return is_array($r) ? $r : null;
}

function as_sup_ensure_thread(PDO $pdo, int $cid): array {
    $pdo->prepare("INSERT IGNORE INTO assistant_threads (customer_id, status, unread_support, unread_user) VALUES (?, 'open', 0, 0)")
        ->execute([$cid]);
    $t = as_sup_find_thread($pdo, $cid);
    if ($t === null) throw new RuntimeException('thread not created');
    return $t;
}

function as_sup_by_client(PDO $pdo, int $cid, string $clientId): ?array {
    $st = $pdo->prepare('SELECT id, sender, body, created_at FROM assistant_messages WHERE customer_id = ? AND client_id = ? LIMIT 1');
    $st->execute([$cid, $clientId]);
    $r = $st->fetch();
    return is_array($r) ? $r : null;
}

/** تعداد پیام‌های خوانده‌نشدهٔ پشتیبان برای کاربر (نشانگر منو). خطا ⇒ صفر، تا منو خراب نشود. */
function as_support_unread(PDO $pdo, int $cid): int {
    try {
        $t = as_sup_find_thread($pdo, $cid);
        return $t ? (int)$t['unread_user'] : 0;
    } catch (Throwable $e) {
        error_log('[assistant] support_unread: ' . $e->getMessage());
        return 0;
    }
}

/** پیام‌های پشتیبانی تا شناسهٔ $upTo را خوانده‌شده می‌کند و شمارندهٔ کاربر را دوباره حساب می‌کند */
function as_sup_mark_read(PDO $pdo, int $tid, int $cid, int $upTo): void {
    if ($upTo <= 0) return;
    $pdo->prepare("UPDATE assistant_messages SET read_at = ? WHERE thread_id = ? AND customer_id = ? AND sender = 'support' AND read_at IS NULL AND id <= ?")
        ->execute([date('Y-m-d H:i:s'), $tid, $cid, $upTo]);
    $st = $pdo->prepare("SELECT COUNT(*) FROM assistant_messages WHERE thread_id = ? AND customer_id = ? AND sender = 'support' AND read_at IS NULL");
    $st->execute([$tid, $cid]);
    $pdo->prepare('UPDATE assistant_threads SET unread_user = ? WHERE id = ? AND customer_id = ?')->execute([(int)$st->fetchColumn(), $tid, $cid]);
}

/** پیام‌های جدید (بعد از after) یا آخرین صفحه (after=0) */
function as_sup_load(PDO $pdo, int $cid, int $after): array {
    $t = as_sup_find_thread($pdo, $cid);
    if ($t === null) return ['messages' => [], 'status' => 'open', 'welcome' => $after <= 0, 'unread_user' => 0];
    $tid = (int)$t['id'];
    $page = AS_SUP_PAGE;
    if ($after <= 0) {
        $st = $pdo->prepare("SELECT id, sender, body, created_at FROM (
                SELECT id, sender, body, created_at FROM assistant_messages
                WHERE thread_id = ? AND customer_id = ? ORDER BY id DESC LIMIT {$page}) x ORDER BY id ASC");
        $st->execute([$tid, $cid]);
    } else {
        $st = $pdo->prepare("SELECT id, sender, body, created_at FROM assistant_messages
                WHERE thread_id = ? AND customer_id = ? AND id > ? ORDER BY id ASC LIMIT {$page}");
        $st->execute([$tid, $cid, $after]);
    }
    $rows = $st->fetchAll();

    $maxSupport = 0;
    foreach ($rows as $r) {
        if ($r['sender'] === 'support') $maxSupport = max($maxSupport, (int)$r['id']);
    }
    as_sup_mark_read($pdo, $tid, $cid, $maxSupport);

    $fresh = as_sup_find_thread($pdo, $cid) ?? $t;
    return [
        'messages'    => array_map('as_sup_fmt', $rows),
        'status'      => (string)$fresh['status'],
        'welcome'     => $after <= 0 && count($rows) === 0,
        'unread_user' => (int)$fresh['unread_user'],
    ];
}

/** بلوک‌های پاسخ: (گفتگوی بسته) نوتیس + بلوک chat؛ پیام خوش‌آمد فقط در پاسخ است و ذخیره نمی‌شود */
function as_sup_response(array $data, array $cfg): array {
    $sup = $cfg['support'] ?? [];
    $blocks = [];
    if (($data['status'] ?? 'open') === 'closed') {
        $blocks[] = blk_notice((string)($sup['closed_notice'] ?? ''), 'info');
    }
    $msgs = $data['messages'];
    if (!empty($data['welcome'])) {
        array_unshift($msgs, ['id' => 0, 'sender' => 'assistant', 'body' => (string)($sup['welcome'] ?? ''), 'time' => '']);
    }
    $blocks[] = blk_chat(
        $msgs,
        ['interval_ms' => (int)($sup['poll_interval_ms'] ?? 5000), 'action' => as_action('support', 'poll')],
        ['action' => as_action('support', 'send')]
    );
    return as_response($blocks, ['data_note' => null, 'unread_support' => (int)($data['unread_user'] ?? 0)]);
}

/** محدودیت نرخ ارسال کاربر: هر دقیقه و هر ۲۴ ساعت (شمارش از خود جدول پیام‌ها) */
function as_sup_rate_check(PDO $pdo, int $cid): void {
    $sup = as_config()['support'] ?? [];
    $perMin = (int)($sup['send_per_minute'] ?? 10);
    $perDay = (int)($sup['send_per_day'] ?? 200);
    $st = $pdo->prepare("SELECT COUNT(*) FROM assistant_messages WHERE customer_id = ? AND sender = 'user' AND created_at > ?");
    $st->execute([$cid, date('Y-m-d H:i:s', time() - 60)]);
    if ((int)$st->fetchColumn() >= $perMin) as_bad('پیام‌های شما زیاد است؛ کمی صبر کنید', 429);
    $st->execute([$cid, date('Y-m-d H:i:s', time() - 86400)]);
    if ((int)$st->fetchColumn() >= $perDay) as_bad('به سقف پیام‌های ۲۴ ساعت گذشته رسیده‌اید', 429);
}

/** پاسخ ارسال: فقط همان یک پیام (کلاینت با poll بقیه را می‌گیرد؛ تکراری‌ها با id یکی می‌شوند) */
function as_sup_send_reply(PDO $pdo, int $cid, array $row): array {
    $t = as_sup_find_thread($pdo, $cid);
    return as_sup_response([
        'messages'    => [as_sup_fmt($row)],
        'status'      => (string)($t['status'] ?? 'open'),
        'welcome'     => false,
        'unread_user' => (int)($t['unread_user'] ?? 0),
    ], as_config());
}

function as_sup_send(array $ctx): array {
    $pdo = $ctx['pdo'];
    $cid = (int)$ctx['cid'];
    $params = is_array($ctx['params'] ?? null) ? $ctx['params'] : [];
    $max = (int)((as_config()['support'] ?? [])['max_chars'] ?? 1000);
    try {
        $body = as_sup_check_body(as_sup_clean_body($params['body'] ?? null), $max);
        $clientId = as_sup_client_id($params['client_id'] ?? null);
    } catch (InvalidArgumentException $e) {
        as_bad($e->getMessage(), 400);
    }

    // ۱) همان client_id قبلاً ثبت شده ⇒ پیام قبلی (بدون درج دوباره)
    if ($clientId !== null) {
        $dup = as_sup_by_client($pdo, $cid, $clientId);
        if ($dup !== null) return as_sup_send_reply($pdo, $cid, $dup);
    }

    // ۲) همان متن کمتر از ۲ ثانیه پیش ⇒ نادیده (پیام تازه ثبت نمی‌شود)
    $t = as_sup_find_thread($pdo, $cid);
    if ($t !== null) {
        $st = $pdo->prepare("SELECT id, sender, body, created_at FROM assistant_messages
                WHERE thread_id = ? AND customer_id = ? AND sender = 'user' ORDER BY id DESC LIMIT 1");
        $st->execute([(int)$t['id'], $cid]);
        $last = $st->fetch();
        if (is_array($last) && $last['body'] === $body
            && (time() - (int)strtotime((string)$last['created_at'])) <= AS_SUP_DUP_SECONDS) {
            return as_sup_send_reply($pdo, $cid, $last);
        }
    }

    as_sup_rate_check($pdo, $cid);
    $tid = (int)as_sup_ensure_thread($pdo, $cid)['id'];
    $now = date('Y-m-d H:i:s');
    try {
        $pdo->prepare("INSERT INTO assistant_messages (thread_id, customer_id, sender, body, client_id, created_at)
                VALUES (?, ?, 'user', ?, ?, ?)")
            ->execute([$tid, $cid, $body, $clientId, $now]);
    } catch (PDOException $e) {
        // درج هم‌زمان با همان client_id (کلید یکتا) ⇒ همان پیام قبلی
        if ($clientId !== null && (string)$e->getCode() === '23000') {
            $dup = as_sup_by_client($pdo, $cid, $clientId);
            if ($dup !== null) return as_sup_send_reply($pdo, $cid, $dup);
        }
        throw $e;
    }
    $newId = (int)$pdo->lastInsertId();
    // ارسال کاربر، گفتگوی بسته را دوباره باز می‌کند
    $pdo->prepare("UPDATE assistant_threads SET status = 'open', last_msg_at = ?, unread_support = unread_support + 1 WHERE id = ? AND customer_id = ?")
        ->execute([$now, $tid, $cid]);

    return as_sup_send_reply($pdo, $cid, [
        'id' => $newId, 'sender' => 'user', 'body' => $body, 'created_at' => $now,
    ]);
}

// ------------------------------------------------------------ ورودی اکشن

function as_h_support(array $ctx): array {
    $step = (string)($ctx['step'] ?? '');
    if ($step === 'send') return as_sup_send($ctx);
    if (!in_array($step, ['start', 'poll'], true)) as_bad('درخواست نامعتبر است', 400);

    $params = is_array($ctx['params'] ?? null) ? $ctx['params'] : [];
    try {
        $after = as_sup_parse_after($params['after_id'] ?? null);
    } catch (InvalidArgumentException $e) {
        as_bad($e->getMessage(), 400);
    }
    $data = as_sup_load($ctx['pdo'], (int)$ctx['cid'], $after);
    return as_sup_response($data, as_config());
}

as_register('support', 'as_h_support');
