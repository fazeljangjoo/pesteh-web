-- v58 (S31.T31.12): letters of credit. Mirrors pb_lc_ensure().
CREATE TABLE IF NOT EXISTS letters_of_credit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  sale_id INT UNSIGNED NOT NULL,
  lc_no VARCHAR(40) NOT NULL,
  issuing_bank VARCHAR(100) NOT NULL,
  advising_bank VARCHAR(100) NULL,
  ccy_code VARCHAR(8) NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  open_date DATE NULL,
  expiry_date DATE NOT NULL,
  latest_ship_date DATE NULL,
  presentation_days SMALLINT UNSIGNED NOT NULL DEFAULT 21,
  status VARCHAR(12) NOT NULL DEFAULT 'open',
  ship_date DATE NULL,
  presented_date DATE NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_lc_no (customer_id, lc_no),
  KEY idx_lc_sale (customer_id, sale_id),
  KEY idx_lc_exp (customer_id, status, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
