-- v57 (S31.T31.11): packing list / certificate of origin fields. Mirrors pb_sx_ensure().
ALTER TABLE sale_export ADD COLUMN pkg_count INT UNSIGNED NULL, ADD COLUMN pkg_type VARCHAR(30) NULL, ADD COLUMN gross_kg DECIMAL(12,3) NULL,
  ADD COLUMN marks VARCHAR(200) NULL, ADD COLUMN origin_country VARCHAR(60) NULL, ADD COLUMN hs_code VARCHAR(20) NULL, ADD COLUMN cert_no VARCHAR(40) NULL;
