-- v54 (S31.T31.3): pending payment approvals. Mirrors pb_payappr_ensure().
CREATE TABLE IF NOT EXISTS pay_approvals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  doc_type VARCHAR(12) NOT NULL DEFAULT 'purchase',
  doc_id INT UNSIGNED NOT NULL,
  amount BIGINT NOT NULL DEFAULT 0,
  payload MEDIUMTEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  requested_by VARCHAR(120) NULL,
  decided_by VARCHAR(120) NULL,
  reason VARCHAR(200) NULL,
  created_at DATETIME NULL,
  decided_at DATETIME NULL,
  KEY idx_pa_cust (customer_id, status, id),
  KEY idx_pa_doc (customer_id, doc_type, doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
