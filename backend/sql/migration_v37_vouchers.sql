-- v37 (S16.T16.1): mirror of pb_mig_37_vouchers() (PHP runs this idempotently)
CREATE TABLE IF NOT EXISTS vouchers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    fiscal_year_id INT UNSIGNED NOT NULL,
    number INT UNSIGNED NULL,
    voucher_date DATE NOT NULL,
    description VARCHAR(500) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    source_type VARCHAR(30) NOT NULL DEFAULT 'manual',
    source_id VARCHAR(64) NULL,
    total BIGINT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_voucher_no (customer_id, fiscal_year_id, number),
    KEY idx_voucher_date (customer_id, voucher_date),
    KEY idx_voucher_src (customer_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS voucher_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    voucher_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    tafsili_id INT UNSIGNED NULL,
    cost_center_id INT UNSIGNED NULL,
    debit BIGINT NOT NULL DEFAULT 0,
    credit BIGINT NOT NULL DEFAULT 0,
    description VARCHAR(500) NULL,
    ccy VARCHAR(8) NULL,
    ccy_amount DECIMAL(20,4) NULL,
    rate DECIMAL(20,6) NULL,
    KEY idx_vl_voucher (voucher_id),
    KEY idx_vl_account (customer_id, account_id),
    KEY idx_vl_tafsili (customer_id, tafsili_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
