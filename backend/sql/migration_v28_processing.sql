-- S09.T09.1 processing orders (v28). Run once; the PHP migration (pb_mig_28_processing) does the same idempotently.
-- direction paid|received; status in_progress|done|closed. Lots: paid outputs is_mix=2, received consigned is_mix=3.

CREATE TABLE IF NOT EXISTS proc_orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        direction VARCHAR(10) NOT NULL,
        party VARCHAR(150) NOT NULL,
        order_date DATE NOT NULL,
        service VARCHAR(20) NOT NULL DEFAULT 'other',
        fee_basis VARCHAR(10) NOT NULL DEFAULT 'per_kg',
        fee_value DECIMAL(18,4) NOT NULL DEFAULT 0,
        fee_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        standard_yield_pct DECIMAL(6,2) NOT NULL DEFAULT 100,
        waste_charge_per_kg DECIMAL(18,4) NOT NULL DEFAULT 0,
        charge_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'in_progress',
        warehouse_uid CHAR(36) NULL,
        input_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        input_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        output_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        waste_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        yield_pct DECIMAL(8,3) NULL,
        receive_date DATE NULL,
        deliver_date DATE NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_proc_uid (customer_id, uid),
        KEY idx_proc_cust (customer_id, order_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proc_inputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        unit_cost DECIMAL(18,4) NULL,
        cost DECIMAL(18,2) NULL,
        KEY idx_pri_order (customer_id, order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proc_outputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        type_name VARCHAR(100) NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        KEY idx_pro_order (customer_id, order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
