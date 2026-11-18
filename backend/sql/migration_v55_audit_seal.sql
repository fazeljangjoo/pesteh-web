-- v55 (S31.T31.5): audit_log hash chain + append-only triggers. Mirrors pb_mig_55_audit_seal() (triggers need the TRIGGER privilege; the PHP step skips them if refused).
ALTER TABLE audit_log ADD COLUMN prev_hash CHAR(40) NULL;
ALTER TABLE audit_log ADD COLUMN row_hash CHAR(40) NULL;
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit_log is append-only';
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit_log is append-only';
