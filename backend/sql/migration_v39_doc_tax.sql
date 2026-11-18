-- v39 (S17.T17.1) mirror of pb_mig_39_doc_tax (runtime migration is authoritative; idempotent there)
ALTER TABLE sale_items ADD COLUMN tax_status VARCHAR(10) NULL, ADD COLUMN tax_rate_milli INT NULL, ADD COLUMN tax_amount BIGINT NULL, ADD COLUMN discount BIGINT NULL, ADD COLUMN surcharge BIGINT NULL;
ALTER TABLE sales ADD COLUMN total_before_tax BIGINT NULL, ADD COLUMN total_tax BIGINT NULL, ADD COLUMN total_after_tax BIGINT NULL;
ALTER TABLE purchases ADD COLUMN tax_status VARCHAR(10) NULL, ADD COLUMN tax_rate_milli INT NULL, ADD COLUMN tax_amount BIGINT NULL, ADD COLUMN surcharge BIGINT NULL, ADD COLUMN total_before_tax BIGINT NULL, ADD COLUMN total_tax BIGINT NULL, ADD COLUMN total_after_tax BIGINT NULL;
ALTER TABLE fiscal_years ADD COLUMN tax_rounding VARCHAR(8) NOT NULL DEFAULT 'line', ADD COLUMN tax_round_to INT NOT NULL DEFAULT 1;
