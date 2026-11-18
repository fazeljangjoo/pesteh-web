-- v45 (S24.T24.2) mirror of pb_mig_45_app_settings (money_unit gate; see api/money_unit.php)
CREATE TABLE IF NOT EXISTS app_settings (
    customer_id INT UNSIGNED NOT NULL,
    k VARCHAR(60) NOT NULL,
    v VARCHAR(255) NOT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (customer_id, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
