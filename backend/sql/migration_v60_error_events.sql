-- v60 (S31.T31.18): error monitoring events (server + app). Mirrors pb_err_ensure(). Operational log: NOT part of backup export/import.
CREATE TABLE IF NOT EXISTS error_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
