-- S35.T35.7c: sayad id (شناسهٔ صیاد) on cheque lines. Mirror of pb_mig_52_sayad_id (idempotent via pb_col_exists in PHP).
ALTER TABLE cheque_inst ADD COLUMN sayad_id VARCHAR(16) NULL;
ALTER TABLE receipt_cheque_inst ADD COLUMN sayad_id VARCHAR(16) NULL;
