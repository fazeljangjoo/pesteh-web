-- v40 (S17.T17.1b) mirror of pb_mig_40_sale_tax_choice
ALTER TABLE sales ADD COLUMN tax_status VARCHAR(10) NULL, ADD COLUMN tax_rate_milli INT NULL;
