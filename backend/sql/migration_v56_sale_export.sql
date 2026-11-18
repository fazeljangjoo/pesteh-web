-- v56 (S31.T31.10): export fields side table. Mirrors pb_sx_ensure().
CREATE TABLE IF NOT EXISTS sale_export (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  sale_id INT UNSIGNED NOT NULL,
  incoterm VARCHAR(4) NULL,
  ship_method VARCHAR(12) NULL,
  port_loading VARCHAR(100) NULL,
  port_destination VARCHAR(100) NULL,
  ccy_code VARCHAR(8) NULL,
  remit_no VARCHAR(40) NULL,
  lc_bank VARCHAR(100) NULL,
  lc_no VARCHAR(40) NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_sx_sale (customer_id, sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
