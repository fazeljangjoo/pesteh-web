-- S15.T15.2 accounts / account_roles / tafsili (v35). PHP migration pb_mig_35_accounts does the same idempotently.

CREATE TABLE IF NOT EXISTS accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  level TINYINT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  nature VARCHAR(8) NOT NULL,
  type VARCHAR(10) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  system_role VARCHAR(40) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  UNIQUE KEY uq_acc_code (customer_id, code),
  KEY idx_acc_parent (customer_id, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  role VARCHAR(40) NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_role (customer_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tafsili (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  kind VARCHAR(12) NOT NULL,
  ref_key VARCHAR(150) NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  UNIQUE KEY uq_taf_code (customer_id, code),
  UNIQUE KEY uq_taf_ref (customer_id, kind, ref_key),
  KEY idx_taf_kind (customer_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
