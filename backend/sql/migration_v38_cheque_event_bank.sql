-- S16.T16.4 (v38): bank account ref + bank charge on cheque events
ALTER TABLE cheque_events ADD COLUMN bank_ref VARCHAR(64) NULL;
ALTER TABLE cheque_events ADD COLUMN bank_charge BIGINT NULL;
