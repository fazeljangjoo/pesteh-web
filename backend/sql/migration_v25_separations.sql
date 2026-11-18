-- S07.T07.1 multi-output separations + lot lineage (v25). Output lots live in purchases with is_mix=2.
CREATE TABLE IF NOT EXISTS separations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        sep_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        labor_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
        transport_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
        waste_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        cost_alloc VARCHAR(10) NOT NULL DEFAULT 'by_weight',
        warehouse_uid CHAR(36) NULL,
        input_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        input_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        abnormal_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        abnormal_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sep_uid (customer_id, uid),
        KEY idx_sep_cust (customer_id, sep_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS separation_inputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        separation_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NULL,
        large_sacks DECIMAL(10,2) NULL,
        warehouse_uid CHAR(36) NULL,
        unit_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
        cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        KEY idx_si_sep (customer_id, separation_id),
        KEY idx_si_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS separation_outputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        separation_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        type_name VARCHAR(100) NOT NULL,
        ounce_uid CHAR(36) NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        alloc_price DECIMAL(16,2) NULL,
        cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        KEY idx_so_sep (customer_id, separation_id),
        KEY idx_so_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lot_lineage (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        separation_id INT UNSIGNED NOT NULL,
        parent_lot_id INT UNSIGNED NOT NULL,
        child_lot_id INT UNSIGNED NOT NULL,
        parent_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        KEY idx_ll_parent (customer_id, parent_lot_id),
        KEY idx_ll_child (customer_id, child_lot_id),
        KEY idx_ll_sep (customer_id, separation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS separation_settings (
        customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
        tolerance_kg DECIMAL(10,4) NOT NULL DEFAULT 0.0015,
        abnormal_pct DECIMAL(6,2) NOT NULL DEFAULT 5.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
