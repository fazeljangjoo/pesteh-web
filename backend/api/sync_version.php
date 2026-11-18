<?php
declare(strict_types=1);
/**
 * S4 — همگام‌سازی لحظهٔ سرور (هاست cPanel؛ بدون WebSocket): شمارندهٔ تغییر per-customer + poll سبک.
 *  sync_version(customer_id, ver)      : بعد از هر نوشتن موفق ver++ (یک UPSERT اتمیک)
 *  sync_log(customer_id, ver, action, entity) : حلقهٔ کوتاه (۲ روز) تا کلاینت بداند چه چیزی عوض شده (sync.changes)
 * کلاینت: هر poll_interval ثانیه sync.version (یا sync_ping.php) → اگر ver عوض شد → pull دلتا (entity.changes برای entityها؛
 * برای جدول‌های غیر-entity از فهرست action/entity در sync.changes می‌فهمد کدام لیست را دوباره بگیرد؛ reset=true → pull کامل).
 * فقط اکشن‌های «نوشتنی» ver را بالا می‌برند (فهرست مثبت، نه منفی) تا pull خودش ver را بالا نبرد (حلقهٔ بی‌پایان).
 */
require_once __DIR__ . '/ddl_guard.php';

const PB_SYNC_POLL_DEFAULT = 4;
const PB_SYNC_POLL_MIN = 2;
const PB_SYNC_POLL_MAX = 60;
const PB_SYNC_LOG_DAYS = 2;
const PB_SYNC_CHANGES_LIMIT = 500;
const PB_SYNC_WRITE_VERB_RE = '/\.(save|create|update|delete|transition|approve|apply|pay|receive|post|unpost|settle|rebuild|adjust|add|set_status|convert|return|transfer|transfer_cancel|cancel|mark_paid|attach|confirm|reject|mark_sample|submit|seed|import|allocate|deliver|sold|link_sales|settings_save|line_update|run_create|run_delete|pay_line_add|pay_line_delete|params_save|upgrade_apply|price_settings)$/';

/** جدول‌ها (idempotent؛ DDL → خارج از تراکنش. مهاجرت v63 هم همین را صدا می‌زند). */
function pb_sync_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS sync_version (
        customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
        ver BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS sync_log (
        customer_id INT UNSIGNED NOT NULL,
        ver BIGINT UNSIGNED NOT NULL,
        action VARCHAR(60) NOT NULL,
        entity VARCHAR(60) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (customer_id, ver),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** آیا این اکشن داده را تغییر می‌دهد؟ (فهرست مثبت: WRITE_ACTIONS ∪ فعل‌های نوشتنی) */
function pb_sync_is_write(string $action): bool {
    if ($action === '' || strpos($action, 'sync.') === 0 || strpos($action, 'reports.') === 0) return false;
    if (defined('WRITE_ACTIONS') && in_array($action, WRITE_ACTIONS, true)) return true;
    return preg_match(PB_SYNC_WRITE_VERB_RE, $action) === 1;
}

/** نام «ناحیهٔ» تغییر برای کلاینت: entity.* → نام entity ؛ intake.* → lot_intakes ؛ وگرنه بخش اول اکشن (purchases, sales, …). */
function pb_sync_entity_of(string $action, array $payload): string {
    if (strpos($action, 'entity.') === 0) {
        $e = (string)($payload['entity'] ?? '');
        return preg_match('/^[a-z][a-z0-9_]{0,59}$/', $e) === 1 ? $e : 'entity';
    }
    if (strpos($action, 'intake.') === 0) return 'lot_intakes'; // confirm هم purchases را می‌سازد (کلاینت خودش purchases را هم می‌گیرد)
    $p = strpos($action, '.');
    return substr($p === false ? $action : substr($action, 0, $p), 0, 60);
}

/** ver++ و ثبت در sync_log. هرگز استثنا نمی‌اندازد؛ بازگشت: ver جدید یا 0 در خطا. */
function pb_sync_bump(PDO $pdo, int $cid, string $action, array $payload = []): int {
    try {
        $pdo->prepare('INSERT INTO sync_version (customer_id, ver, updated_at) VALUES (?, LAST_INSERT_ID(1), NOW())
                       ON DUPLICATE KEY UPDATE ver = LAST_INSERT_ID(ver + 1), updated_at = NOW()')->execute([$cid]);
        $ver = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sync_log (customer_id, ver, action, entity, created_at) VALUES (?,?,?,?,NOW())')
            ->execute([$cid, $ver, substr($action, 0, 60), pb_sync_entity_of($action, $payload)]);
        if ($ver % 200 === 0) { // پاک‌سازی گاه‌به‌گاه (ارزان)
            $pdo->prepare('DELETE FROM sync_log WHERE customer_id = ? AND created_at < (NOW() - INTERVAL ' . PB_SYNC_LOG_DAYS . ' DAY)')->execute([$cid]);
        }
        return $ver;
    } catch (Throwable $e) {
        error_log('[sync] bump: ' . $e->getMessage());
        return 0;
    }
}

function pb_sync_version(PDO $pdo, int $cid): int {
    try {
        $st = $pdo->prepare('SELECT ver FROM sync_version WHERE customer_id = ?');
        $st->execute([$cid]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int)$v;
    } catch (Throwable $e) { return 0; }
}

/** فاصلهٔ poll (ثانیه) قابل‌تنظیم per-customer: app_settings کلید sync.poll_interval. */
function pb_sync_poll_interval(PDO $pdo, int $cid): int {
    try {
        $st = $pdo->prepare("SELECT v FROM app_settings WHERE customer_id = ? AND k = 'sync.poll_interval'");
        $st->execute([$cid]);
        $v = $st->fetchColumn();
        if ($v !== false && is_numeric($v)) return max(PB_SYNC_POLL_MIN, min(PB_SYNC_POLL_MAX, (int)$v));
    } catch (Throwable $e) { /* جدول نیست → پیش‌فرض */ }
    return PB_SYNC_POLL_DEFAULT;
}

/**
 * تغییرات پس از since_ver. reset=true یعنی «فهرست کامل نیست» (فاصلهٔ زیاد/پاک‌شده/خطا) → کلاینت pull کامل کند.
 * بازگشت: ['ver'=>int,'changes'=>[{ver,action,entity}],'reset'=>bool]
 */
function pb_sync_changes(PDO $pdo, int $cid, int $since): array {
    $cur = pb_sync_version($pdo, $cid);
    if ($since < 0) $since = 0;
    if ($since === $cur) return ['ver' => $cur, 'changes' => [], 'reset' => false];
    if ($since > $cur) return ['ver' => $cur, 'changes' => [], 'reset' => true]; // کلاینت جلوتر از سرور (restore/DB عوض شده)
    try {
        $st = $pdo->prepare('SELECT ver, action, entity FROM sync_log WHERE customer_id = ? AND ver > ? ORDER BY ver ASC LIMIT ' . (PB_SYNC_CHANGES_LIMIT + 1));
        $st->execute([$cid, $since]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) { return ['ver' => $cur, 'changes' => [], 'reset' => true]; }
    $expect = $cur - $since;
    if (count($rows) > PB_SYNC_CHANGES_LIMIT || count($rows) < $expect || ($rows && (int)$rows[0]['ver'] !== $since + 1)) {
        return ['ver' => $cur, 'changes' => [], 'reset' => true]; // حفره/خیلی زیاد
    }
    $out = [];
    foreach ($rows as $r) $out[] = ['ver' => (int)$r['ver'], 'action' => (string)$r['action'], 'entity' => $r['entity'] === null ? null : (string)$r['entity']];
    return ['ver' => $cur, 'changes' => $out, 'reset' => false];
}

/** sync.version {since_ver?} | sync.changes {since_ver} — با out() تمام می‌شود (در app.php). */
function pb_sync_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    $since = isset($p['since_ver']) && is_numeric($p['since_ver']) ? (int)$p['since_ver'] : null;
    if ($action === 'sync.changes') {
        out(pb_sync_changes($pdo, $cid, (int)($since ?? 0)) + ['poll_interval' => pb_sync_poll_interval($pdo, $cid)]);
    }
    $o = ['ver' => pb_sync_version($pdo, $cid), 'poll_interval' => pb_sync_poll_interval($pdo, $cid)];
    if ($since !== null) { $c = pb_sync_changes($pdo, $cid, $since); $o['changes'] = $c['changes']; $o['reset'] = $c['reset']; }
    out($o);
}
