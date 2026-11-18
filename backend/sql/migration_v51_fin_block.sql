-- S35.T35.5: weighbridge cost on purchases/sales (mirror of pb_mig_51_fin_block in api/migrations.php)
ALTER TABLE purchases ADD COLUMN weighbridge_cost BIGINT NULL;
ALTER TABLE sales ADD COLUMN weighbridge_cost BIGINT NULL;
