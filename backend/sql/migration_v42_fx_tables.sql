-- v42 (S20.T20.2) mirror of pb_mig_42_fx_tables
CREATE TABLE IF NOT EXISTS currencies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    code VARCHAR(8) NOT NULL,
    name VARCHAR(60) NOT NULL,
    symbol VARCHAR(8) NULL,
    decimals TINYINT NOT NULL DEFAULT 2,
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    UNIQUE KEY uq_ccy (customer_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fx_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    ccy VARCHAR(8) NOT NULL,
    rate_date DATE NOT NULL,
    rate DECIMAL(18,6) NOT NULL,
    source VARCHAR(60) NULL,
    created_at DATETIME NULL,
    UNIQUE KEY uq_fxr (customer_id, ccy, rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
