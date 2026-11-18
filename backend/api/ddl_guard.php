<?php
declare(strict_types=1);
/**
 * DDL guard. "CREATE TABLE IF NOT EXISTS" is DDL: MySQL ends the open transaction (implicit commit) EVEN when the table already exists.
 * Many ensure*() helpers run inside beginTransaction()...commit(), so the later commit() threw "There is no active transaction" (HTTP 500).
 * pb_ddl_exec() runs the CREATE only when the table is really missing (SHOW TABLES is not DDL); positives are cached per request.
 */
if (!function_exists('pb_ddl_exec')) {
    function pb_ddl_exec($pdo, string $sql): void {
        static $seen = [];
        if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
            $t = $m[1];
            if (isset($seen[$t])) return;
            try {
                if ($pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t))->fetchColumn()) { $seen[$t] = true; return; }
            } catch (Throwable $e) { /* fall through and let CREATE decide */ }
            $pdo->exec($sql);
            $seen[$t] = true;
            return;
        }
        $pdo->exec($sql);
    }
}
