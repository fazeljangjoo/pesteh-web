-- v48 (S27b) mirror of pb_mig_48_fx_rate_source
ALTER TABLE sales ADD COLUMN fx_rate_source VARCHAR(10) NULL;
ALTER TABLE sales ADD COLUMN fx_rate_entered_at VARCHAR(30) NULL;
ALTER TABLE purchases ADD COLUMN fx_rate_source VARCHAR(10) NULL;
ALTER TABLE purchases ADD COLUMN fx_rate_entered_at VARCHAR(30) NULL;
CREATE TABLE IF NOT EXISTS fx_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    doc_type VARCHAR(10) NOT NULL,
    doc_id INT UNSIGNED NOT NULL,
    ccy VARCHAR(8) NOT NULL,
    foreign_amount DECIMAL(20,4) NOT NULL,
    old_rate DECIMAL(18,6) NOT NULL,
    new_rate DECIMAL(18,6) NOT NULL,
    diff_rial DECIMAL(20,2) NOT NULL,
    reason VARCHAR(300) NOT NULL,
    created_by VARCHAR(120) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fxadj_doc (customer_id, doc_type, doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
