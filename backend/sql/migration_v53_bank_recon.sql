-- v53 (S31.T31.1): bank statement lines for reconciliation (gold). Mirrors pb_bank_recon_ensure().
CREATE TABLE IF NOT EXISTS bank_stmt_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  bank_uid VARCHAR(40) NOT NULL,
  line_date DATE NOT NULL,
  amount BIGINT NOT NULL,
  description VARCHAR(300) NULL,
  ref VARCHAR(60) NULL,
  line_hash CHAR(40) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  voucher_line_id INT UNSIGNED NULL,
  diff BIGINT NOT NULL DEFAULT 0,
  note VARCHAR(200) NULL,
  created_at DATETIME NULL,
  matched_at DATETIME NULL,
  UNIQUE KEY uq_bsl_hash (customer_id, bank_uid, line_hash),
  KEY idx_bsl_bank (customer_id, bank_uid, status, line_date),
  KEY idx_bsl_vl (customer_id, voucher_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
