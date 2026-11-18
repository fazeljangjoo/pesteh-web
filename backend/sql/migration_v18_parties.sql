-- v18 (S03.T03.1): party master columns. Applied automatically by pb_migrate() (pb_mig_18_parties), which also backfills rows from purchases/sales/loans names.
CREATE TABLE IF NOT EXISTS parties (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_party (customer_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE parties
  ADD COLUMN types VARCHAR(200) NULL, ADD COLUMN phone VARCHAR(30) NULL, ADD COLUMN address VARCHAR(300) NULL,
  ADD COLUMN national_id VARCHAR(20) NULL, ADD COLUMN economic_code VARCHAR(20) NULL, ADD COLUMN postal_code VARCHAR(20) NULL,
  ADD COLUMN credit_limit BIGINT NULL, ADD COLUMN opening_balance BIGINT NOT NULL DEFAULT 0,
  ADD COLUMN opening_side VARCHAR(10) NOT NULL DEFAULT 'debtor', ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN note VARCHAR(500) NULL, ADD COLUMN created_at DATETIME NULL;
