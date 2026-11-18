-- v20 (S04.T04.1): purchase returns. Applied automatically by pb_migrate() (pb_mig_20_purchase_returns).
CREATE TABLE IF NOT EXISTS purchase_returns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(40) NOT NULL,
        number INT UNSIGNED NOT NULL,
        party VARCHAR(150) NOT NULL,
        date DATE NOT NULL,
        refund_mode VARCHAR(20) NOT NULL DEFAULT 'reduce_payable',
        refund_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        cheque_no VARCHAR(60) NULL,
        cheque_due DATE NULL,
        note VARCHAR(500) NULL,
        tax_amount DECIMAL(18,2) NULL,
        voucher_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pr_uid (customer_id, uid),
        UNIQUE KEY uq_pr_number (customer_id, number),
        KEY idx_pr_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_return_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        return_id BIGINT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        warehouse_uid CHAR(36) NULL,
        kg DECIMAL(16,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit_price DECIMAL(18,4) NOT NULL,
        KEY idx_pri_ret (customer_id, return_id),
        KEY idx_pri_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
