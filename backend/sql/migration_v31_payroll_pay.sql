-- S11.T11.2 payment lines (v31). Run once; the PHP migration (pb_mig_31_payroll_pay) does the same idempotently.

CREATE TABLE IF NOT EXISTS payroll_pay_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        line_id INT UNSIGNED NOT NULL,
        ccy VARCHAR(8) NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        net_part DECIMAL(18,2) NOT NULL,
        due_date DATE NOT NULL,
        method VARCHAR(10) NOT NULL DEFAULT 'transfer',
        cheque_ref VARCHAR(60) NULL,
        paid TINYINT NOT NULL DEFAULT 0,
        paid_date DATE NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ppl_uid (customer_id, uid),
        KEY idx_ppl_line (customer_id, line_id),
        KEY idx_ppl_due (customer_id, paid, due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
