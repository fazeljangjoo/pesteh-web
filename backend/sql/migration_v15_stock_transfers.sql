-- v15 (S01.T01.3): warehouse transfers. Also applied automatically by pb_migrate().
ALTER TABLE sale_items ADD COLUMN warehouse_uid CHAR(36) NULL;
ALTER TABLE mix_sources ADD COLUMN warehouse_uid CHAR(36) NULL;
CREATE TABLE IF NOT EXISTS stock_transfers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  uid CHAR(36) NOT NULL,
  lot_id INT UNSIGNED NOT NULL,
  from_warehouse_uid CHAR(36) NOT NULL,
  to_warehouse_uid CHAR(36) NOT NULL,
  qty_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
  small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
  large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
  move_date DATE NOT NULL,
  note VARCHAR(500) NULL,
  cancelled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_st_uid (customer_id, uid),
  KEY idx_st_lot (customer_id, lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
