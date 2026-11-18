<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S31.T31.18 — error monitoring (server + app).
 * Server: uncaught exceptions in app.php (the 500 handler) and PHP fatals (shutdown handler) are recorded in `error_events`.
 * App: the Flutter app batches uncaught errors and sends them with `error.report` (any logged-in user, capped).
 * Events are de-duplicated per (customer, fingerprint): one row with an occurrence counter, re-opened when it happens again.
 * Privacy: NEVER stores the request payload; digit runs (6+) in messages are masked; server file paths are reduced to basename:line.
 * Actions: error.report {events:[{message,stack?,location?,count?,screen?}], app_version?, platform?}  (perm '' = any logged-in user)
 *          error.list {source?:server|app, resolved?:0|1, limit?<=200}  |  error.summary  |  error.resolve {id | all:true}   (perm settings.manage)
 * Operational log: NOT part of backup export/import (see tools/audit_backup_coverage.py). Retention 90 days.
 */
const PB_ERR_RETENTION_DAYS = 90;
const PB_ERR_APP_HOURLY_CAP = 500; // max app occurrences accepted per customer per hour (flood guard)

function pb_err_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS error_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL DEFAULT 0,
        source VARCHAR(8) NOT NULL DEFAULT 'server',
        fingerprint CHAR(40) NOT NULL,
        message VARCHAR(500) NOT NULL,
        location VARCHAR(300) NULL,
        action VARCHAR(80) NULL,
        app_version VARCHAR(30) NULL,
        platform VARCHAR(20) NULL,
        stack TEXT NULL,
        occurrences INT UNSIGNED NOT NULL DEFAULT 1,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_err_fp (customer_id, source, fingerprint),
        KEY idx_err_c (customer_id, resolved, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pb_err_clip(string $s, int $max): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

/** Masks long digit runs (phones, ids, amounts) and collapses whitespace. */
function pb_err_clean(string $s, int $max): string {
    $s = (string)preg_replace('/\d{6,}/', '#', $s);
    $s = (string)preg_replace('/\s+/u', ' ', $s);
    return pb_err_clip(trim($s), $max);
}

/** Records one event; never throws (monitoring must not break a request). Returns true when stored. */
function pb_err_record(PDO $pdo, int $cid, string $source, string $message, string $location = '', string $action = '', string $appVersion = '', string $platform = '', string $stack = '', int $count = 1): bool {
    try {
        pb_err_ensure($pdo);
        $source = $source === 'app' ? 'app' : 'server';
        $msg = pb_err_clean($message, 500);
        if ($msg === '') $msg = '(empty)';
        $loc = pb_err_clean($location, 300);
        $fp = sha1($source . '|' . $msg . '|' . $loc);
        $count = max(1, min(1000, $count));
        $pdo->prepare('INSERT INTO error_events (customer_id, source, fingerprint, message, location, action, app_version, platform, stack, occurrences, first_seen, last_seen, resolved)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),0)
            ON DUPLICATE KEY UPDATE occurrences = occurrences + VALUES(occurrences), last_seen = NOW(), resolved = 0,
                app_version = VALUES(app_version), action = VALUES(action)')
            ->execute([$cid, $source, $fp, $msg, $loc !== '' ? $loc : null, $action !== '' ? pb_err_clip($action, 80) : null,
                $appVersion !== '' ? pb_err_clip($appVersion, 30) : null, $platform !== '' ? pb_err_clip($platform, 20) : null,
                $stack !== '' ? pb_err_clean($stack, 3000) : null, $count]);
        return true;
    } catch (Throwable $e) {
        error_log('[error_monitor] ' . get_class($e));
        return false;
    }
}

/** Server-side hook: call from the top-level catch in app.php (after rollBack). */
function pb_err_server(Throwable $e): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) return;
    $cust = $GLOBALS['customer'] ?? null;
    $cid = (is_array($cust) && isset($cust['id'])) ? (int)$cust['id'] : 0;
    pb_err_record($pdo, $cid, 'server', get_class($e) . ': ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine(), (string)($GLOBALS['action'] ?? ''));
}

/** Registers a shutdown handler that records PHP fatals (they bypass the try/catch). Idempotent. */
function pb_err_install(): void {
    static $on = false;
    if ($on) return;
    $on = true;
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) return;
        $cust = $GLOBALS['customer'] ?? null;
        $cid = (is_array($cust) && isset($cust['id'])) ? (int)$cust['id'] : 0;
        pb_err_record($pdo, $cid, 'server', 'FATAL: ' . (string)$err['message'], basename((string)$err['file']) . ':' . (int)$err['line'], (string)($GLOBALS['action'] ?? ''));
    });
}

function pb_err_report(PDO $pdo, int $cid, array $p): array {
    $events = is_array($p['events'] ?? null) ? array_slice($p['events'], 0, 20) : [];
    $ver = is_string($p['app_version'] ?? null) ? $p['app_version'] : '';
    $plat = is_string($p['platform'] ?? null) ? $p['platform'] : '';
    pb_err_ensure($pdo);
    $st = $pdo->prepare("SELECT COALESCE(SUM(occurrences),0) FROM error_events WHERE customer_id=? AND source='app' AND last_seen > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $st->execute([$cid]);
    $recent = (int)$st->fetchColumn();
    $accepted = 0;
    $dropped = 0;
    foreach ($events as $ev) {
        if (!is_array($ev)) continue;
        $cnt = max(1, min(1000, (int)($ev['count'] ?? 1)));
        if ($recent + $cnt > PB_ERR_APP_HOURLY_CAP) { $dropped++; continue; }
        $loc = trim((is_string($ev['screen'] ?? null) ? $ev['screen'] . ' ' : '') . (is_string($ev['location'] ?? null) ? $ev['location'] : ''));
        if (pb_err_record($pdo, $cid, 'app', is_string($ev['message'] ?? null) ? $ev['message'] : '', $loc, '', $ver, $plat, is_string($ev['stack'] ?? null) ? $ev['stack'] : '', $cnt)) { $accepted++; $recent += $cnt; }
    }
    if (mt_rand(1, 50) === 1) { // lazy retention purge
        try { $pdo->prepare('DELETE FROM error_events WHERE customer_id=? AND last_seen < DATE_SUB(NOW(), INTERVAL ? DAY)')->execute([$cid, PB_ERR_RETENTION_DAYS]); } catch (Throwable $e) { error_log('[error_monitor] purge'); }
    }
    return ['ok' => true, 'accepted' => $accepted, 'dropped' => $dropped];
}

function pb_err_list(PDO $pdo, int $cid, array $p): array {
    $w = ['customer_id=?'];
    $a = [$cid];
    if (in_array($p['source'] ?? '', ['server', 'app'], true)) { $w[] = 'source=?'; $a[] = $p['source']; }
    if (isset($p['resolved']) && $p['resolved'] !== '') { $w[] = 'resolved=?'; $a[] = (int)(bool)$p['resolved']; }
    $lim = max(1, min(200, (int)($p['limit'] ?? 50)));
    $st = $pdo->prepare('SELECT id, source, message, location, action, app_version, platform, stack, occurrences, first_seen, last_seen, resolved FROM error_events WHERE ' . implode(' AND ', $w) . ' ORDER BY resolved ASC, last_seen DESC LIMIT ' . $lim);
    $st->execute($a);
    return ['events' => $st->fetchAll()];
}

function pb_err_summary(PDO $pdo, int $cid): array {
    pb_err_ensure($pdo);
    $st = $pdo->prepare("SELECT
        COALESCE(SUM(source='server' AND resolved=0),0) AS open_server,
        COALESCE(SUM(source='app' AND resolved=0),0) AS open_app,
        COALESCE(SUM(CASE WHEN last_seen > DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END),0) AS kinds_24h,
        MAX(last_seen) AS last_error_at
        FROM error_events WHERE customer_id=?");
    $st->execute([$cid]);
    $r = $st->fetch() ?: [];
    return ['open_server' => (int)($r['open_server'] ?? 0), 'open_app' => (int)($r['open_app'] ?? 0), 'kinds_24h' => (int)($r['kinds_24h'] ?? 0), 'last_error_at' => $r['last_error_at'] ?? null];
}

function pb_err_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    pb_err_ensure($pdo);
    if ($action === 'error.report') out(pb_err_report($pdo, $cid, $p));
    if ($action === 'error.summary') out(pb_err_summary($pdo, $cid));
    if ($action === 'error.resolve') {
        if (!empty($p['all'])) {
            $n = $pdo->prepare('UPDATE error_events SET resolved=1 WHERE customer_id=? AND resolved=0');
            $n->execute([$cid]);
        } else {
            $id = (int)($p['id'] ?? 0);
            if ($id <= 0) bad('شناسهٔ خطا نامعتبر است');
            $n = $pdo->prepare('UPDATE error_events SET resolved=1 WHERE customer_id=? AND id=?');
            $n->execute([$cid, $id]);
        }
        out(['ok' => true, 'resolved' => $n->rowCount()]);
    }
    out(pb_err_list($pdo, $cid, $p));
}
