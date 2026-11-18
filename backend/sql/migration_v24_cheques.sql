-- S06.T06.1 unified cheque register (v24)
CREATE TABLE IF NOT EXISTS cheques (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    direction VARCHAR(10) NOT NULL,
    number VARCHAR(60) NULL,
    bank VARCHAR(100) NULL,
    branch VARCHAR(100) NULL,
    sayad_id VARCHAR(32) NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    due_date DATE NOT NULL,
    party VARCHAR(150) NULL,
    status VARCHAR(20) NOT NULL,
    source_table VARCHAR(30) NULL,
    source_id INT UNSIGNED NULL,
    endorsed_to VARCHAR(150) NULL,
    note VARCHAR(500) NULL,
    reinstated TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_cheque_src (customer_id, source_table, source_id),
    KEY idx_cheque_c (customer_id, status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cheque_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    cheque_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    event_date DATE NOT NULL,
    note VARCHAR(500) NULL,
    created_at DATETIME NULL,
    KEY idx_cheque_ev (customer_id, cheque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
