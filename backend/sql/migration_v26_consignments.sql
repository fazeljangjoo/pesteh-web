-- S08.T08.1 consignment (v26). Consigned-in lots live in purchases with is_mix=3.
CREATE TABLE IF NOT EXISTS consignments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        direction VARCHAR(4) NOT NULL DEFAULT 'in',
        party VARCHAR(150) NOT NULL,
        cons_date DATE NOT NULL,
        warehouse_uid CHAR(36) NULL,
        settlement_mode VARCHAR(20) NOT NULL DEFAULT 'commission',
        commission_type VARCHAR(10) NOT NULL DEFAULT 'percent',
        commission_value DECIMAL(14,4) NOT NULL DEFAULT 0,
        agreed_price DECIMAL(16,2) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cons_uid (customer_id, uid),
        KEY idx_cons_cust (customer_id, cons_date),
        KEY idx_cons_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consignment_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        consignment_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        KEY idx_ci_cons (customer_id, consignment_id),
        KEY idx_ci_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consignment_returns (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        consignment_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        ret_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cr_uid (customer_id, uid),
        KEY idx_cr_cons (customer_id, consignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consignment_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        consignment_id INT UNSIGNED NOT NULL,
        pay_date DATE NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cp_uid (customer_id, uid),
        KEY idx_cp_cons (customer_id, consignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
