-- v14 (S01.T01.2): stock moves ledger. Also applied automatically by pb_migrate(); per-customer backfill is lazy (pb_stock_ensure).
CREATE TABLE IF NOT EXISTS stock_moves (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NOT NULL,
  warehouse_uid CHAR(36) NULL,
  ounce_uid CHAR(36) NULL,
  kind VARCHAR(20) NOT NULL,
  doc_type VARCHAR(20) NOT NULL,
  doc_id BIGINT UNSIGNED NOT NULL,
  qty_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
  small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
  large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(18,4) NULL,
  move_date DATE NOT NULL,
  ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversal_of BIGINT UNSIGNED NULL,
  reversed TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_sm_lot (customer_id, lot_id),
  KEY idx_sm_doc (customer_id, doc_type, doc_id),
  KEY idx_sm_wh (customer_id, warehouse_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS stock_state (
  customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
  built_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
