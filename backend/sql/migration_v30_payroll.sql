-- S11.T11.1 payroll (v30). Run once; the PHP migration (pb_mig_30_payroll) does the same idempotently.

CREATE TABLE IF NOT EXISTS employees (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        name VARCHAR(120) NOT NULL,
        position VARCHAR(100) NULL,
        hire_date DATE NULL,
        base_salary DECIMAL(18,2) NOT NULL DEFAULT 0,
        ccy VARCHAR(8) NOT NULL DEFAULT 'IRR',
        pay_schedule VARCHAR(8) NOT NULL DEFAULT 'monthly',
        bank_account VARCHAR(40) NULL,
        insurance_no VARCHAR(30) NULL,
        active TINYINT NOT NULL DEFAULT 1,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_emp_uid (customer_id, uid),
        KEY idx_emp_cust (customer_id, active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        name VARCHAR(100) NOT NULL,
        kind VARCHAR(10) NOT NULL,
        taxable TINYINT NOT NULL DEFAULT 0,
        insurable TINYINT NOT NULL DEFAULT 0,
        formula VARCHAR(8) NOT NULL DEFAULT 'fixed',
        default_value DECIMAL(18,4) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pitem_uid (customer_id, uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_runs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        year SMALLINT UNSIGNED NOT NULL,
        month TINYINT UNSIGNED NOT NULL,
        ccy VARCHAR(8) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'draft',
        approved_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_prun_period (customer_id, year, month, ccy)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        run_id INT UNSIGNED NOT NULL,
        employee_id INT UNSIGNED NOT NULL,
        base DECIMAL(18,2) NOT NULL DEFAULT 0,
        overtime DECIMAL(18,2) NOT NULL DEFAULT 0,
        allowances DECIMAL(18,2) NOT NULL DEFAULT 0,
        earnings DECIMAL(18,2) NOT NULL DEFAULT 0,
        deductions DECIMAL(18,2) NOT NULL DEFAULT 0,
        other_deductions DECIMAL(18,2) NOT NULL DEFAULT 0,
        advances_deducted DECIMAL(18,2) NOT NULL DEFAULT 0,
        net DECIMAL(18,2) NOT NULL DEFAULT 0,
        ccy VARCHAR(8) NOT NULL,
        UNIQUE KEY uq_pline (customer_id, run_id, employee_id),
        KEY idx_pline_run (customer_id, run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_line_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        line_id INT UNSIGNED NOT NULL,
        pay_item_id INT UNSIGNED NULL,
        name VARCHAR(100) NOT NULL,
        kind VARCHAR(10) NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        KEY idx_pli_line (customer_id, line_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_advances (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        employee_id INT UNSIGNED NOT NULL,
        adv_date DATE NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        deducted DECIMAL(18,2) NOT NULL DEFAULT 0,
        ccy VARCHAR(8) NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_adv_uid (customer_id, uid),
        KEY idx_adv_emp (customer_id, employee_id, ccy)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
