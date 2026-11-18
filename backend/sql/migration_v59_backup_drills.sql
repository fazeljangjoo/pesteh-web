-- v59 (S31.T31.15): backup drill history. Mirrors pb_drill_ensure(). Operational log: NOT part of backup export/import.
CREATE TABLE IF NOT EXISTS backup_drills (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
