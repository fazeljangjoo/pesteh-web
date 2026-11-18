-- S08.T08.2 consignment OUT (v27). Run once; the PHP migration (pb_mig_27_consignment_out) does the same idempotently.
-- consignments.direction='out': our own lots held by a consignee. Stock moves into the virtual warehouse (ent_warehouses.type='consignment').
CREATE TABLE IF NOT EXISTS consignment_sales (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        consignment_id INT UNSIGNED NOT NULL,
        sale_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        sold_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_csl_uid (customer_id, uid),
        UNIQUE KEY uq_csl_sale (customer_id, sale_id),
        KEY idx_csl_cons (customer_id, consignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- skip if the column already exists:
ALTER TABLE consignment_items ADD COLUMN warehouse_uid CHAR(36) NULL;
