-- S10.T10.1 orchards (v29). Run once; the PHP migration (pb_mig_29_orchards) does the same idempotently.

CREATE TABLE IF NOT EXISTS orchards (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        name VARCHAR(120) NOT NULL,
        location VARCHAR(200) NULL,
        area_ha DECIMAL(10,2) NOT NULL DEFAULT 0,
        tree_count INT UNSIGNED NOT NULL DEFAULT 0,
        variety VARCHAR(100) NULL,
        ownership VARCHAR(8) NOT NULL DEFAULT 'own',
        note VARCHAR(500) NULL,
        cost_center_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_orch_name (customer_id, name),
        UNIQUE KEY uq_orch_uid (customer_id, uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orchard_costs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        orchard_id INT UNSIGNED NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        category VARCHAR(12) NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        cost_date DATE NOT NULL,
        party VARCHAR(150) NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ocost_uid (customer_id, uid),
        KEY idx_ocost_season (customer_id, orchard_id, season_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS harvests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        orchard_id INT UNSIGNED NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        harvest_date DATE NOT NULL,
        wet_kg DECIMAL(14,3) NOT NULL,
        dry_kg DECIMAL(14,3) NOT NULL,
        lot_id INT UNSIGNED NULL,
        cost DECIMAL(18,2) NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_harv_uid (customer_id, uid),
        KEY idx_harv_season (customer_id, orchard_id, season_year),
        KEY idx_harv_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orchard_allocations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        orchard_id INT UNSIGNED NOT NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        basis VARCHAR(8) NOT NULL,
        market_per_kg DECIMAL(18,2) NULL,
        pool_cost DECIMAL(18,2) NOT NULL,
        allocated_cost DECIMAL(18,2) NOT NULL,
        residual DECIMAL(18,2) NOT NULL DEFAULT 0,
        dry_kg DECIMAL(14,3) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_oalloc_season (customer_id, orchard_id, season_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
