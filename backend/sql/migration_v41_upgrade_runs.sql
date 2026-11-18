-- v41 (S20.T20.1) mirror of pb_mig_41_upgrade_runs
CREATE TABLE IF NOT EXISTS upgrade_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'running',
    step VARCHAR(16) NOT NULL DEFAULT 'snapshot',
    params TEXT NULL,
    steps MEDIUMTEXT NULL,
    cursor_key VARCHAR(80) NULL,
    snapshot_file VARCHAR(200) NULL,
    fingerprint MEDIUMTEXT NULL,
    report MEDIUMTEXT NULL,
    error VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    KEY idx_ug_c (customer_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
