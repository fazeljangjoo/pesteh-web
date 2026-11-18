-- v22 (S04.T04.3): proformas. Applied automatically by pb_migrate() (pb_mig_22_proformas).
CREATE TABLE IF NOT EXISTS proformas (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(40) NOT NULL,
        number INT UNSIGNED NOT NULL,
        party VARCHAR(150) NOT NULL,
        date DATE NOT NULL,
        valid_until DATE NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        reserve TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(500) NULL,
        terms VARCHAR(1000) NULL,
        converted_at DATETIME NULL,
        tax_amount DECIMAL(18,2) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pf_uid (customer_id, uid),
        UNIQUE KEY uq_pf_number (customer_id, number),
        KEY idx_pf_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proforma_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        proforma_id BIGINT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NULL,
        description VARCHAR(200) NULL,
        kg DECIMAL(16,3) NOT NULL,
        unit_price DECIMAL(18,4) NOT NULL,
        discount DECIMAL(18,2) NOT NULL DEFAULT 0,
        sale_id INT UNSIGNED NULL,
        KEY idx_pfi_pf (customer_id, proforma_id),
        KEY idx_pfi_lot (customer_id, lot_id),
        KEY idx_pfi_sale (customer_id, sale_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ALTER TABLE sales ADD COLUMN proforma_id BIGINT UNSIGNED NULL; (guarded by SHOW COLUMNS in pb_proforma_ensure)
