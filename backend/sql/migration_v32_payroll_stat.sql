-- S11.T11.3 statutory params + line statutory detail (v32). Run once; the PHP migration (pb_mig_32_payroll_stat) does the same idempotently.

CREATE TABLE IF NOT EXISTS payroll_params (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        fy SMALLINT UNSIGNED NOT NULL,
        employee_ins_rate DECIMAL(9,4) NULL,
        employer_ins_rate DECIMAL(9,4) NULL,
        unemployment_share_rate DECIMAL(9,4) NULL,
        overtime_multiplier DECIMAL(9,4) NULL,
        min_wage DECIMAL(18,2) NULL,
        exemption DECIMAL(18,2) NOT NULL DEFAULT 0,
        tax_brackets TEXT NULL,
        source VARCHAR(255) NULL,
        source_date DATE NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pparams_fy (customer_id, fy)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_line_stat (
        customer_id INT UNSIGNED NOT NULL,
        line_id INT UNSIGNED NOT NULL,
        fy SMALLINT UNSIGNED NOT NULL,
        insurable_base DECIMAL(18,2) NOT NULL,
        taxable_base DECIMAL(18,2) NOT NULL,
        ins_employee DECIMAL(18,2) NOT NULL,
        ins_employer DECIMAL(18,2) NOT NULL,
        exemption DECIMAL(18,2) NOT NULL,
        taxable DECIMAL(18,2) NOT NULL,
        tax DECIMAL(18,2) NOT NULL,
        PRIMARY KEY (customer_id, line_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
