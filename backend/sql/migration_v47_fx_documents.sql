-- v47 (S27.T27.1/2) mirror of pb_mig_47_fx_documents: currency columns on sales and purchases.
ALTER TABLE sales ADD COLUMN ccy VARCHAR(8) NULL;
ALTER TABLE sales ADD COLUMN fx_rate DECIMAL(18,6) NULL;
ALTER TABLE sales ADD COLUMN total_ccy DECIMAL(20,4) NULL;
ALTER TABLE purchases ADD COLUMN ccy VARCHAR(8) NULL;
ALTER TABLE purchases ADD COLUMN fx_rate DECIMAL(18,6) NULL;
ALTER TABLE purchases ADD COLUMN price_ccy DECIMAL(20,4) NULL;
