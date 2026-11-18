-- v50 (S35.T35.1) mirror of pb_mig_50_form_columns: nullable form columns on purchases/sales, taxable flags, grade tables (tables also in schema_app.sql).
ALTER TABLE purchases ADD COLUMN gross_kg DECIMAL(12,3) NULL;
ALTER TABLE purchases ADD COLUMN tare_kg DECIMAL(12,3) NULL;
ALTER TABLE purchases ADD COLUMN quality_deduct_kg DECIMAL(12,3) NULL;
ALTER TABLE purchases ADD COLUMN weigh_ticket_no VARCHAR(40) NULL;
ALTER TABLE purchases ADD COLUMN vehicle_plate VARCHAR(20) NULL;
ALTER TABLE purchases ADD COLUMN driver_name VARCHAR(80) NULL;
ALTER TABLE purchases ADD COLUMN discount_amount BIGINT NULL;
ALTER TABLE purchases ADD COLUMN tax_amount BIGINT NULL;
ALTER TABLE purchases ADD COLUMN doc_status VARCHAR(10) NULL;
ALTER TABLE purchases ADD COLUMN side_cost_payer VARCHAR(10) NULL;
ALTER TABLE sales ADD COLUMN gross_kg DECIMAL(12,3) NULL;
ALTER TABLE sales ADD COLUMN tare_kg DECIMAL(12,3) NULL;
ALTER TABLE sales ADD COLUMN quality_deduct_kg DECIMAL(12,3) NULL;
ALTER TABLE sales ADD COLUMN weigh_ticket_no VARCHAR(40) NULL;
ALTER TABLE sales ADD COLUMN vehicle_plate VARCHAR(20) NULL;
ALTER TABLE sales ADD COLUMN driver_name VARCHAR(80) NULL;
ALTER TABLE sales ADD COLUMN discount_amount BIGINT NULL;
ALTER TABLE sales ADD COLUMN tax_amount BIGINT NULL;
ALTER TABLE sales ADD COLUMN doc_status VARCHAR(10) NULL;
ALTER TABLE sales ADD COLUMN side_cost_payer VARCHAR(10) NULL;
ALTER TABLE sale_items ADD COLUMN taxable TINYINT NULL;
ALTER TABLE purchases ADD COLUMN taxable TINYINT NULL;

CREATE TABLE IF NOT EXISTS doc_grade_lines (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  doc_type    VARCHAR(10) NOT NULL,
  doc_id      INT UNSIGNED NOT NULL,
  grade_key   VARCHAR(40) NOT NULL,
  kg          DECIMAL(12,3) NOT NULL,
  unit_price  BIGINT NOT NULL,
  KEY idx_dgl_doc (customer_id, doc_type, doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grade_prices (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT UNSIGNED NOT NULL,
  type_name    VARCHAR(100) NOT NULL,
  ounce_uid    CHAR(36) NULL,
  grade_key    VARCHAR(40) NULL,
  price_per_kg BIGINT NOT NULL,
  price_date   DATE NOT NULL,
  KEY idx_gp_lookup (customer_id, type_name, price_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
