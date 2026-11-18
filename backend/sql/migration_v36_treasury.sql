-- v36 (S15.T15.3b): mirror of pb_mig_36_treasury() (PHP does this idempotently; run by hand only if needed)
ALTER TABLE lump_tx ADD COLUMN treasury_tafsili_id INT UNSIGNED NULL;
ALTER TABLE credit_inst ADD COLUMN treasury_tafsili_id INT UNSIGNED NULL;
ALTER TABLE cheque_inst ADD COLUMN treasury_tafsili_id INT UNSIGNED NULL;
ALTER TABLE receipt_lump_tx ADD COLUMN treasury_tafsili_id INT UNSIGNED NULL;
ALTER TABLE receipt_credit_inst ADD COLUMN treasury_tafsili_id INT UNSIGNED NULL;
ALTER TABLE receipt_cheque_inst ADD COLUMN treasury_tafsili_id INT UNSIGNED NULL;
ALTER TABLE orchard_costs ADD COLUMN cost_center_uid CHAR(36) NULL;
ALTER TABLE broker_payments ADD COLUMN cost_center_uid CHAR(36) NULL;
ALTER TABLE payroll_lines ADD COLUMN cost_center_uid CHAR(36) NULL;
ALTER TABLE employee_advances ADD COLUMN cost_center_uid CHAR(36) NULL;
-- Legacy text (from_account/to_account) is kept; rows are mapped to ent_cash_boxes/ent_bank_accounts + tafsili by pb_treasury_map_legacy().
