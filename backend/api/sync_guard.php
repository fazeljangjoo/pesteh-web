<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * STAGE11 — سپر همگام‌سازی (طراحی: SYNC_CONFLICT_DESIGN.md مرحلهٔ ۱۰).
 * توابع خالص؛ هیچ exit/bad() اینجا نیست. هر خطای داخلی ⇒ برگرداندن ورودی بدون تغییر (رفتار قدیمی).
 * فقط وقتی app.php این فایل را require کند که PB_SYNC_GUARD روشن باشد.
 * به private/ و هیچ جدول کسب‌وکاری (به‌جز خواندن و اعمال paid روی ورودی درخواست) دست نمی‌زند.
 */

const SG_LEGACY_HOURS_DEFAULT = 24;
const SG_LOG_RETENTION_DAYS = 60;

/** ساخت ۳ جدول (ایدم‌پوتنت). DDL = commit ضمنی ⇒ فقط پیش از beginTransaction صدا زده شود. */
function sg_ensure_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        pb_ddl_exec($pdo, 'CREATE TABLE IF NOT EXISTS assistant_settle_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, owner_type VARCHAR(10) NOT NULL,
            owner_id INT UNSIGNED NOT NULL, fp VARCHAR(255) NOT NULL, paid_at DATETIME NOT NULL, source VARCHAR(20) NULL DEFAULT NULL,
            KEY idx_asl_owner (customer_id, owner_type, owner_id), KEY idx_asl_paid (paid_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        pb_ddl_exec($pdo, 'CREATE TABLE IF NOT EXISTS assistant_sync_conflicts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, owner_type VARCHAR(10) NOT NULL,
            owner_id INT UNSIGNED NOT NULL, fp VARCHAR(255) NOT NULL, reason VARCHAR(40) NOT NULL, detected_at DATETIME NOT NULL,
            KEY idx_asc_owner (customer_id, owner_type, owner_id), KEY idx_asc_det (detected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        pb_ddl_exec($pdo, 'CREATE TABLE IF NOT EXISTS repay_claims (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, ruid VARCHAR(40) NOT NULL,
            claimed_by VARCHAR(40) NULL DEFAULT NULL, claimed_at DATETIME NOT NULL,
            UNIQUE KEY uq_claim (customer_id, ruid), KEY idx_rc_owner (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $done = true;
    } catch (Throwable $e) {
        error_log('[sync_guard] ensure: ' . $e->getMessage());
    }
}

function sg_legacy_hours(): int {
    $h = defined('PB_SYNC_LEGACY_HOURS') ? (int)PB_SYNC_LEGACY_HOURS : SG_LEGACY_HOURS_DEFAULT;
    return $h < 0 ? SG_LEGACY_HOURS_DEFAULT : $h;
}

function sg_f($v): float { return ($v === null || $v === '' || is_array($v)) ? 0.0 : (float)$v; }

/** جدول‌های هر نوع مالک (نام‌ها ثابت‌اند؛ هرگز از ورودی کاربر) */
function sg_cfg(string $ownerType): array {
    if ($ownerType === 'purchase') {
        return ['owner' => 'payments', 'fk' => 'purchase_id', 'children' => [
            'lump' => ['lump_tx', 'payment_id', 'date'], 'credit' => ['credit_inst', 'payment_id', 'due_date'],
            'cheque' => ['cheque_inst', 'payment_id', 'due_date']]];
    }
    return ['owner' => 'receipts', 'fk' => 'sale_id', 'children' => [
        'lump' => ['receipt_lump_tx', 'receipt_id', 'date'], 'credit' => ['receipt_credit_inst', 'receipt_id', 'due_date'],
        'cheque' => ['receipt_cheque_inst', 'receipt_id', 'due_date']]];
}

/**
 * اثرانگشت ردیف: یکجا = date|amount| ؛ قسط = due_date|amount|cheque_no (برای credit، cheque_no خالی).
 * $row کلیدهای date / due_date / amount / cheque_no را می‌خواند؛ تاریخ خالیِ یکجا = $today (مثل vdate در app.php).
 */
function sg_fp(string $kind, array $row, string $today): string {
    $amt = sprintf('%.2f', round(sg_f($row['amount'] ?? null), 2));
    if ($kind === 'lump') {
        $d = trim((string)(is_array($row['date'] ?? null) ? '' : ($row['date'] ?? '')));
        return ($d === '' ? $today : $d) . '|' . $amt . '|';
    }
    $d = trim((string)(is_array($row['due_date'] ?? null) ? '' : ($row['due_date'] ?? '')));
    $c = '';
    if ($kind === 'cheque') {
        $c = trim((string)(is_array($row['cheque_no'] ?? null) ? '' : ($row['cheque_no'] ?? '')));
        if (mb_strlen($c) > 60) $c = mb_substr($c, 0, 60);
    }
    return $d . '|' . $amt . '|' . $c;
}

/** نوع ردیف‌های ورودی: ['kind'=>lump|credit|cheque,'key'=>lump|installments] یا null (بدون ردیف) */
function sg_incoming_kind(string $ownerType, array $p): ?array {
    $t = $p[$ownerType === 'purchase' ? 'pay_type' : 'recv_type'] ?? null;
    if ($t === 'lump') return ['kind' => 'lump', 'key' => 'lump'];
    if ($t === 'staged') {
        $s = $p['staged_type'] ?? null;
        if ($s === 'credit') return ['kind' => 'credit', 'key' => 'installments'];
        if ($s === 'cheque') return ['kind' => 'cheque', 'key' => 'installments'];
    }
    return null;
}

/** آستانهٔ زمانی: snap_at معتبر ⇒ همان؛ نبود/نامعتبر/آینده ⇒ پنجرهٔ legacy (محافظه‌کارانه) */
function sg_threshold($snapAt, int $legacyHours, int $now): int {
    $ts = false;
    if (is_string($snapAt) && $snapAt !== '' && strlen($snapAt) <= 40) $ts = strtotime($snapAt);
    if ($ts === false || $ts > $now + 300) return $now - $legacyHours * 3600;
    return (int)$ts;
}

/** ردیف‌های هم‌اکنون paid=1 در DB برای این مالک (با قفل ردیف). خروجی: [['kind'=>..,'fp'=>..], ...] */
function sg_db_paid_rows(PDO $pdo, int $cid, string $ownerType, int $ownerId, string $today): array {
    $cfg = sg_cfg($ownerType);
    $st = $pdo->prepare("SELECT id FROM {$cfg['owner']} WHERE {$cfg['fk']}=? AND customer_id=?");
    $st->execute([$ownerId, $cid]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return [];
    $in = implode(',', $ids); // فقط عدد صحیح
    $out = [];
    foreach ($cfg['children'] as $kind => [$tbl, $fk, $dcol]) {
        $cols = $kind === 'cheque' ? "$dcol AS d, amount, cheque_no" : "$dcol AS d, amount, NULL AS cheque_no";
        $q = $pdo->prepare("SELECT $cols FROM $tbl WHERE customer_id=? AND $fk IN ($in) AND paid=1 FOR UPDATE");
        $q->execute([$cid]);
        foreach ($q->fetchAll() as $r) {
            $out[] = ['kind' => $kind, 'fp' => sg_fp($kind, ['date' => $r['d'], 'due_date' => $r['d'],
                'amount' => $r['amount'], 'cheque_no' => $r['cheque_no']], $today)];
        }
    }
    return $out;
}

/** ورودی‌های لاگِ این مالک که paid_at آن‌ها بعد از آستانه است (مقایسه در PHP با همان منطقهٔ زمانی نوشتن) */
function sg_log_entries(PDO $pdo, int $cid, string $ownerType, int $ownerId, int $threshold): array {
    $st = $pdo->prepare('SELECT id, fp, paid_at FROM assistant_settle_log WHERE customer_id=? AND owner_type=? AND owner_id=? ORDER BY id');
    $st->execute([$cid, $ownerType, $ownerId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $ts = strtotime((string)$r['paid_at']);
        if ($ts !== false && $ts > $threshold) $out[] = ['id' => (int)$r['id'], 'fp' => (string)$r['fp']];
    }
    return $out;
}

/**
 * ادغام ورودی اپ با تسویه‌های دستیار؛ خروجی: همان $p با paid=1 برای ردیف‌هایی که دستیار تسویه کرده و اپِ کهنه از آن بی‌خبر است.
 * قاعده (طراحی §۴): ردیفِ «هم‌اکنون paid=1 در DB» + ورودی لاگ با paid_at > snap_at + ردیف هم‌اثرانگشت ورودی با paid=0 ⇒ paid=1.
 * اگر ردیفی با آن اثرانگشت در ورودی نباشد (اپ ویرایش/حذف کرده) ⇒ نسخهٔ اپ برنده + ثبت در assistant_sync_conflicts.
 * هر خطا ⇒ $p بدون تغییر. $stats: merged / conflicts / legacy.
 */
function sg_merge_incoming(PDO $pdo, int $cid, string $ownerType, int $ownerId, array $p, $snapAt = null,
                           ?array &$stats = null, ?int $now = null, ?string $today = null): array {
    $now = $now ?? time();
    $today = $today ?? date('Y-m-d', $now);
    $stats = ['merged' => 0, 'conflicts' => 0, 'legacy' => false];
    if ($ownerType !== 'purchase' && $ownerType !== 'sale') return $p;
    try {
        $thr = sg_threshold($snapAt, sg_legacy_hours(), $now);
        $stats['legacy'] = !(is_string($snapAt) && $snapAt !== '' && strlen($snapAt) <= 40 && ($t = strtotime($snapAt)) !== false && $t <= $now + 300);
        $log = sg_log_entries($pdo, $cid, $ownerType, $ownerId, $thr);
        if (!$log) return $p; // چیزی برای حفظ نیست
        $dbRows = sg_db_paid_rows($pdo, $cid, $ownerType, $ownerId, $today);
        if (!$dbRows) return $p; // «DB الان paid=1» حقیقت پایه است؛ بدون تسویهٔ واقعی هیچ‌چیز paid نمی‌شود
        $info = sg_incoming_kind($ownerType, $p);
        $rows = [];
        if ($info !== null && is_array($p[$info['key']] ?? null)) $rows = array_values(array_filter($p[$info['key']], 'is_array'));
        $logByFp = [];
        foreach ($log as $l) $logByFp[$l['fp']][] = $l['id'];
        $used = [];
        foreach ($dbRows as $r) {
            if (empty($logByFp[$r['fp']])) continue;
            array_shift($logByFp[$r['fp']]); // هر ورودی لاگ حداکثر یک‌بار
            $matched = false;
            if ($info !== null && $info['kind'] === $r['kind']) {
                foreach ($rows as $i => $row) {
                    if (isset($used[$i])) continue;
                    if (sg_fp($info['kind'], $row, $today) !== $r['fp']) continue;
                    $used[$i] = true;
                    $matched = true;
                    if (empty($row['paid'])) { $rows[$i]['paid'] = 1; $stats['merged']++; }
                    break;
                }
            }
            if (!$matched) {
                $stats['conflicts']++;
                try {
                    $pdo->prepare('INSERT INTO assistant_sync_conflicts (customer_id, owner_type, owner_id, fp, reason, detected_at) VALUES (?,?,?,?,?,?)')
                        ->execute([$cid, $ownerType, $ownerId, mb_substr($r['fp'], 0, 255), 'app_edited', date('Y-m-d H:i:s', $now)]);
                } catch (Throwable $e) { error_log('[sync_guard] conflict log: ' . $e->getMessage()); }
            }
        }
        if ($info !== null && $stats['merged'] > 0) $p[$info['key']] = $rows;
        return $p;
    } catch (Throwable $e) {
        error_log('[sync_guard] merge: ' . $e->getMessage());
        $stats = ['merged' => 0, 'conflicts' => 0, 'legacy' => false];
        return $p; // رفتار قدیمی
    }
}

/**
 * جلوگیری از بازپرداخت دوبل سررسید قرض (طراحی B / S3). پارامترها قبلاً در app.php اعتبارسنجی/نرمال شده‌اند.
 * خروجی: id ردیف موجودِ تطبیق‌یافته (⇒ app.php درج نکند و همان را برگرداند) یا null (درج عادی).
 * - اپ جدید: $dueRef = ['loan_uid'=>..,'seq'=>..] ⇒ ruid = 'R'+hash مثل dues.mark_paid.
 * - اپ قدیمی: kind=repay_*، یادداشت دقیقاً «تسویهٔ سررسید N»، هم‌طرف/هم‌نوع/هم‌مبلغ با یک R… که هنوز ادعا نشده.
 * ریسک شناخته‌شده B3: دو قرض هم‌طرف با seq و مبلغ یکسان در اپ قدیمی قابل تفکیک نیست.
 * باید داخل همان تراکنش/قفل loans.create صدا زده شود. خطا ⇒ null.
 */
function sg_due_dup(PDO $pdo, int $cid, string $party, string $kind, float $amount, ?string $note, string $uid, $dueRef = null): ?int {
    try {
        if (!in_array($kind, ['repay_in', 'repay_out'], true)) return null;
        // ارسال مجدد همان بازپرداخت اپ (همان uid) ⇒ همان ردیف ادعاشده؛ ردیف دوم ساخته نشود
        $q0 = $pdo->prepare('SELECT e.id FROM repay_claims c JOIN loan_entries e ON e.customer_id=c.customer_id AND e.uid=c.ruid WHERE c.customer_id=? AND c.claimed_by=? LIMIT 1');
        $q0->execute([$cid, substr($uid, 0, 40)]);
        $prev = $q0->fetchColumn();
        if ($prev) return (int)$prev;
        if (is_array($dueRef)) {
            $lu = (string)($dueRef['loan_uid'] ?? '');
            $sq = (int)($dueRef['seq'] ?? 0);
            if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $lu) || $sq < 1 || $sq > 255) return null;
            $ruid = 'R' . substr(hash('sha256', $lu . '|' . $sq), 0, 24);
            $q = $pdo->prepare('SELECT id FROM loan_entries WHERE customer_id=? AND uid=? FOR UPDATE');
            $q->execute([$cid, $ruid]);
            $id = $q->fetchColumn();
            if (!$id) return null;
            $pdo->prepare('INSERT IGNORE INTO repay_claims (customer_id, ruid, claimed_by, claimed_at) VALUES (?,?,?,NOW())')
                ->execute([$cid, $ruid, substr($uid, 0, 40)]);
            return (int)$id;
        }
        if ($note === null || !preg_match('/^تسویهٔ سررسید (\d{1,3})$/u', $note)) return null;
        $q = $pdo->prepare("SELECT e.id, e.uid FROM loan_entries e
            LEFT JOIN repay_claims c ON c.customer_id=e.customer_id AND c.ruid=e.uid
            WHERE e.customer_id=? AND e.party=? AND e.kind=? AND e.amount=? AND e.note=?
              AND e.uid REGEXP '^R[0-9a-f]{24}\$' AND c.id IS NULL ORDER BY e.id LIMIT 1 FOR UPDATE");
        $q->execute([$cid, $party, $kind, sprintf('%.2f', round($amount, 2)), $note]);
        $r = $q->fetch();
        if (!$r) return null;
        $ins = $pdo->prepare('INSERT IGNORE INTO repay_claims (customer_id, ruid, claimed_by, claimed_at) VALUES (?,?,?,NOW())');
        $ins->execute([$cid, (string)$r['uid'], substr($uid, 0, 40)]);
        return $ins->rowCount() === 1 ? (int)$r['id'] : null; // اگر هم‌زمان کس دیگری ادعا کرد ⇒ درج عادی
    } catch (Throwable $e) {
        error_log('[sync_guard] due_dup: ' . $e->getMessage());
        return null;
    }
}

/** قفل مشترک با دستیار: نام دقیقاً 'pb_as_w_'.$cid. شکست/تایم‌اوت ⇒ false (app.php بدون قفل ادامه می‌دهد؛ رفتار قدیمی). */
function sg_lock(PDO $pdo, int $cid, int $timeout = 3): bool {
    static $reg = false;
    try {
        $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute(['pb_as_w_' . $cid, $timeout]);
        if ((int)$st->fetchColumn() !== 1) return false;
    } catch (Throwable $e) { return false; }
    $GLOBALS['__sg_locks']['pb_as_w_' . $cid] = $pdo;
    if (!$reg) { register_shutdown_function('sg_unlock_all'); $reg = true; } // exit/bad() داخل کیس finally را اجرا نمی‌کنند
    return true;
}
function sg_unlock(PDO $pdo, int $cid): void {
    try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['pb_as_w_' . $cid]); } catch (Throwable $e) {}
    unset($GLOBALS['__sg_locks']['pb_as_w_' . $cid]);
}
function sg_unlock_all(): void {
    foreach ((array)($GLOBALS['__sg_locks'] ?? []) as $name => $pdo) {
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); } catch (Throwable $e) {}
    }
    $GLOBALS['__sg_locks'] = [];
}

/** پاکسازی لاگ تسویه‌های قدیمی‌تر از ۶۰ روز (گاهی صدا زده شود) */
function sg_purge(PDO $pdo, ?int $now = null): void {
    try {
        $cut = date('Y-m-d H:i:s', ($now ?? time()) - SG_LOG_RETENTION_DAYS * 86400);
        $pdo->prepare('DELETE FROM assistant_settle_log WHERE paid_at < ?')->execute([$cut]);
    } catch (Throwable $e) { error_log('[sync_guard] purge: ' . $e->getMessage()); }
}
