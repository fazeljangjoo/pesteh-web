-- S13.T13.2b import batches (v33). Run once; PHP migration pb_mig_33_import_batches does the same idempotently.

CREATE TABLE IF NOT EXISTS import_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  batch_id VARCHAR(64) NOT NULL,
  kind VARCHAR(30) NOT NULL,
  all_or_nothing TINYINT(1) NOT NULL DEFAULT 1,
  total_rows INT NOT NULL DEFAULT 0,
  created_n INT NOT NULL DEFAULT 0,
  updated_n INT NOT NULL DEFAULT 0,
  skipped_n INT NOT NULL DEFAULT 0,
  failed_n INT NOT NULL DEFAULT 0,
  result MEDIUMTEXT NULL,
  created_at DATETIME NULL,
  UNIQUE KEY uq_import_batch (customer_id, batch_id),
  KEY idx_import_batch_c (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
