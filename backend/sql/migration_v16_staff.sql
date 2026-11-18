-- v16 (S02.T02.1): staff accounts, roles, staff_id on tokens. Also applied automatically by pb_migrate() (pb_mig_16_staff).
CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  perms TEXT NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_roles_cid (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS staff_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  username VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  allowed_fy_ids TEXT NULL,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_staff_user (customer_id, username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE customer_tokens ADD COLUMN staff_id INT UNSIGNED NULL, ADD KEY idx_tok_staff (staff_id);
