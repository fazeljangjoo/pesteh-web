-- S05.T05.1 brokers + commissions (v23)
CREATE TABLE IF NOT EXISTS broker_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    broker_id INT UNSIGNED NOT NULL,
    basis VARCHAR(10) NOT NULL DEFAULT 'percent',
    value DECIMAL(14,4) NOT NULL DEFAULT 0,
    applies VARCHAR(10) NOT NULL DEFAULT 'both',
    payer VARCHAR(15) NOT NULL DEFAULT 'us',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    UNIQUE KEY uq_broker_rule (customer_id, broker_id, applies),
    KEY idx_broker_rule_c (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS broker_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    uid VARCHAR(64) NULL,
    broker_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    amount BIGINT NOT NULL DEFAULT 0,
    note VARCHAR(300) NULL,
    created_at DATETIME NULL,
    UNIQUE KEY uq_broker_pay_uid (customer_id, uid),
    KEY idx_broker_pay_c (customer_id, broker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- run once (columns are also added idempotently by pb_broker_ensure)
ALTER TABLE purchases ADD COLUMN broker_id INT UNSIGNED NULL DEFAULT NULL, ADD COLUMN broker_basis VARCHAR(10) NULL DEFAULT NULL, ADD COLUMN broker_value DECIMAL(14,4) NULL DEFAULT NULL, ADD COLUMN broker_amount BIGINT NULL DEFAULT NULL, ADD COLUMN broker_payer VARCHAR(15) NULL DEFAULT NULL;
ALTER TABLE sales ADD COLUMN broker_id INT UNSIGNED NULL DEFAULT NULL, ADD COLUMN broker_basis VARCHAR(10) NULL DEFAULT NULL, ADD COLUMN broker_value DECIMAL(14,4) NULL DEFAULT NULL, ADD COLUMN broker_amount BIGINT NULL DEFAULT NULL, ADD COLUMN broker_payer VARCHAR(15) NULL DEFAULT NULL;
