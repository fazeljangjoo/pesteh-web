<?php
declare(strict_types=1);
require_once __DIR__ . '/ddl_guard.php';
/**
 * S31.T31.15 — monthly backup-restore drill (NON-destructive: nothing is deleted or written to business tables).
 * Actions (perm settings.manage):
 *   backup_drill.run {tables?}  -> builds a backup snapshot of the live data (same tables as backup.export; or validates an uploaded backup's `tables`),
 *        round-trips it through JSON like a real file, runs validateBackup() (the exact checks backup.import does BEFORE it deletes anything) under pb_out_throw,
 *        reconciles per-table row counts live vs. validated plan, computes a sha256 checksum, stores the report in `backup_drills`.
 *   backup_drill.list {limit?}  -> history, newest first.   backup_drill.status -> {last_run, last_ok, days_since, due} (due = never run or > 30 days).
 * ok = validation passed AND every table's validated row count equals the snapshot's. A failed drill means a real restore could fail: fix before you need it.
 */
const PB_DRILL_DUE_DAYS = 30;

function pb_drill_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS backup_drills (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        run_at DATETIME NOT NULL,
        source VARCHAR(8) NOT NULL DEFAULT 'live',
        ok TINYINT(1) NOT NULL DEFAULT 0,
        total_rows INT UNSIGNED NOT NULL DEFAULT 0,
        tables_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        checksum CHAR(64) NULL,
        duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
        error VARCHAR(500) NULL,
        report MEDIUMTEXT NULL,
        KEY idx_bd_c (customer_id, run_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Same rows backup.export would write for BACKUP_TABLES, after a JSON round trip (as if saved to and read from a file). */
function pb_drill_snapshot(PDO $pdo, int $cid): array {
    $tables = [];
    foreach (BACKUP_TABLES as $table => $cols) {
        $st = $pdo->prepare('SELECT ' . implode(',', $cols) . " FROM `$table` WHERE customer_id=?");
        $st->execute([$cid]);
        $tables[$table] = $st->fetchAll();
    }
    $json = json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return json_decode((string)$json, true) ?: [];
}

function pb_drill_run(PDO $pdo, int $cid, array $p): array {
    $t0 = microtime(true);
    $fromFile = isset($p['tables']) && is_array($p['tables']);
    $tables = $fromFile ? $p['tables'] : pb_drill_snapshot($pdo, $cid);
    $checksum = hash('sha256', (string)json_encode($tables, JSON_UNESCAPED_UNICODE));
    $counts = [];
    $total = 0;
    foreach ($tables as $t => $rows) { $n = is_array($rows) ? count($rows) : 0; $counts[(string)$t] = $n; $total += $n; }

    $error = null; $planCounts = [];
    $GLOBALS['pb_out_throw'] = true;
    try {
        $plan = validateBackup($tables);
        foreach ($plan as $t => $rows) $planCounts[$t] = count($rows);
    } catch (PbOutError $e) {
        $error = mb_substr($e->getMessage(), 0, 480);
    } catch (Throwable $e) {
        $error = mb_substr('خطای غیرمنتظره: ' . $e->getMessage(), 0, 480);
    } finally { unset($GLOBALS['pb_out_throw']); }

    $mismatch = [];
    if ($error === null) {
        foreach ($planCounts as $t => $n) {
            $have = $counts[$t] ?? 0; // optional tables missing from an old file validate as empty
            if ($have !== $n) $mismatch[] = ['table' => $t, 'snapshot' => $have, 'validated' => $n];
        }
        if ($mismatch) $error = 'عدم تطابق تعداد ردیف‌ها در ' . count($mismatch) . ' جدول';
    }
    $ok = $error === null;
    $report = ['tables' => array_filter($counts, static fn($n) => $n > 0), 'mismatch' => $mismatch, 'validated_tables' => count($planCounts)];
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $pdo->prepare('INSERT INTO backup_drills (customer_id, run_at, source, ok, total_rows, tables_count, checksum, duration_ms, error, report) VALUES (?,NOW(),?,?,?,?,?,?,?,?)')
        ->execute([$cid, $fromFile ? 'file' : 'live', $ok ? 1 : 0, $total, count($counts), $checksum, $ms, $error, json_encode($report, JSON_UNESCAPED_UNICODE)]);
    return ['id' => (int)$pdo->lastInsertId(), 'ok' => $ok, 'source' => $fromFile ? 'file' : 'live', 'total_rows' => $total, 'tables_count' => count($counts),
        'checksum' => $checksum, 'duration_ms' => $ms, 'error' => $error, 'report' => $report];
}

function pb_drill_list(PDO $pdo, int $cid, int $limit): array {
    $st = $pdo->prepare('SELECT id, run_at, source, ok, total_rows, tables_count, checksum, duration_ms, error FROM backup_drills WHERE customer_id=? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    $st->execute([$cid]);
    return ['items' => array_map(static fn($r) => $r + ['ok_bool' => (int)$r['ok'] === 1], $st->fetchAll())];
}

function pb_drill_status(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT run_at, ok FROM backup_drills WHERE customer_id=? ORDER BY id DESC LIMIT 1');
    $st->execute([$cid]);
    $r = $st->fetch();
    if (!$r) return ['last_run' => null, 'last_ok' => null, 'days_since' => null, 'due' => true, 'due_days' => PB_DRILL_DUE_DAYS];
    $d = (int)floor((time() - strtotime((string)$r['run_at'])) / 86400);
    return ['last_run' => $r['run_at'], 'last_ok' => (int)$r['ok'] === 1, 'days_since' => $d, 'due' => $d >= PB_DRILL_DUE_DAYS || (int)$r['ok'] !== 1, 'due_days' => PB_DRILL_DUE_DAYS];
}

function pb_drill_action(string $action, array $p, PDO $pdo, array $customer): void {
    $cid = (int)$customer['id'];
    pb_drill_ensure($pdo);
    if ($action === 'backup_drill.run') out(pb_drill_run($pdo, $cid, $p));
    if ($action === 'backup_drill.status') out(pb_drill_status($pdo, $cid));
    out(pb_drill_list($pdo, $cid, (int)($p['limit'] ?? 12)));
}
