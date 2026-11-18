-- S15.T15.1 fiscal years (v34). PHP migration pb_mig_34_fiscal_years does the same idempotently.

CREATE TABLE IF NOT EXISTS fiscal_years (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  vat_enabled TINYINT(1) NOT NULL DEFAULT 0,
  vat_rate DECIMAL(6,3) NULL,
  vat_levy_rate DECIMAL(6,3) NULL,
  vat_note VARCHAR(500) NULL,
  vat_source VARCHAR(300) NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  UNIQUE KEY uq_fy_start (customer_id, start_date),
  KEY idx_fy_c (customer_id, start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
