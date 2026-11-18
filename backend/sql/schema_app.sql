-- اسکیمای داده‌های اصلی «بازار پسته» برای نسخه وب
-- این فایل را یک‌بار، بعد از schema.sql (جدول customers)، از phpMyAdmin اجرا کنید.
-- همه‌ی جدول‌ها ستون customer_id دارند تا اطلاعات هر کاربر جدا و خصوصی بماند.

CREATE TABLE IF NOT EXISTS categories (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,
  preset_key    VARCHAR(50)  NULL,
  KEY idx_cat_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS types (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  category_id   INT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,
  KEY idx_typ_customer (customer_id),
  KEY idx_typ_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchases (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id       INT UNSIGNED NOT NULL,
  code              VARCHAR(40)  NULL,
  category_id       INT UNSIGNED NOT NULL,
  type_name         VARCHAR(100) NOT NULL,
  date              DATE NOT NULL,
  ounce             DECIMAL(12,3) NULL,
  price             DECIMAL(14,2) NOT NULL,
  total_weight      DECIMAL(12,3) NOT NULL,
  small_sack_count  DECIMAL(10,2) NOT NULL DEFAULT 0,
  large_sack_count  DECIMAL(10,2) NOT NULL DEFAULT 0,
  small_sacks_remaining DECIMAL(10,2) NOT NULL DEFAULT 0, -- گونی کوچک باقی‌مانده (مستقل از وزن remaining)
  large_sacks_remaining DECIMAL(10,2) NOT NULL DEFAULT 0, -- گونی بزرگ باقی‌مانده
  net_weight        DECIMAL(12,3) NOT NULL,
  remaining         DECIMAL(12,3) NOT NULL,
  gram              DECIMAL(10,2) NULL,
  pct_dombest       DECIMAL(6,2) NULL,
  pct_tuchin        DECIMAL(6,2) NULL,
  pct_crooked       DECIMAL(6,2) NULL,
  pct_khandan       DECIMAL(6,2) NULL,
  custom_specs      TEXT NULL,
  photo             MEDIUMTEXT NULL,
  seller            VARCHAR(150) NULL,
  note              VARCHAR(500) NULL,
  waste             DECIMAL(12,3) NULL, -- ضایعات/خاکِ بار مخلوط/جداسازی (وزن برداشته‌شده = خالص + ضایعات)
  is_mix            TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  warehouse_uid     CHAR(36) NULL, -- v13 (NULL = default warehouse)
  ounce_uid         CHAR(36) NULL, -- v13
  KEY idx_pur_customer (customer_id),
  KEY idx_pur_wh (customer_id, warehouse_uid),
  KEY idx_pur_seller (seller(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mix_sources (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id         INT UNSIGNED NOT NULL,
  mix_purchase_id     INT UNSIGNED NOT NULL,
  source_purchase_id  INT UNSIGNED NOT NULL,
  weight              DECIMAL(12,3) NOT NULL,
  small_sacks         DECIMAL(10,2) NULL,
  large_sacks         DECIMAL(10,2) NULL,
  warehouse_uid       CHAR(36) NULL,
  KEY idx_mix_customer (customer_id),
  KEY idx_mix_purchase (mix_purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  purchase_id   INT UNSIGNED NOT NULL UNIQUE,
  pay_type      VARCHAR(10) NOT NULL, -- lump | staged
  staged_type   VARCHAR(10) NULL,     -- credit | cheque
  KEY idx_pay_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lump_tx (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  payment_id    INT UNSIGNED NOT NULL,
  from_account  VARCHAR(150) NULL,
  payer_name    VARCHAR(150) NULL,
  to_account    VARCHAR(150) NULL,
  seller_name   VARCHAR(150) NULL,
  amount        DECIMAL(14,2) NOT NULL,
  date          DATE NOT NULL,
  paid          TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_lump_customer (customer_id),
  KEY idx_lump_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS credit_inst (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  payment_id    INT UNSIGNED NOT NULL,
  due_date      DATE NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  from_account  VARCHAR(150) NULL,
  payer_name    VARCHAR(150) NULL,
  to_account    VARCHAR(150) NULL,
  seller_name   VARCHAR(150) NULL,
  paid          TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_credit_customer (customer_id),
  KEY idx_credit_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cheque_inst (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  payment_id    INT UNSIGNED NOT NULL,
  due_date      DATE NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  cheque_no     VARCHAR(60) NULL,
  sayad_id      VARCHAR(16) NULL,
  bank_name     VARCHAR(100) NULL,
  from_account  VARCHAR(150) NULL,
  payer_name    VARCHAR(150) NULL,
  to_account    VARCHAR(150) NULL,
  seller_name   VARCHAR(150) NULL,
  paid          TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_cheque_customer (customer_id),
  KEY idx_cheque_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id       INT UNSIGNED NOT NULL,
  date              DATE NOT NULL,
  price             DECIMAL(14,2) NOT NULL,
  total_weight      DECIMAL(12,3) NOT NULL,
  small_sack_count  DECIMAL(10,2) NOT NULL DEFAULT 0,
  large_sack_count  DECIMAL(10,2) NOT NULL DEFAULT 0,
  net_weight        DECIMAL(12,3) NOT NULL,
  buyer             VARCHAR(150) NULL,
  note              VARCHAR(500) NULL,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sale_customer (customer_id),
  KEY idx_sale_buyer (buyer(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  sale_id       INT UNSIGNED NOT NULL,
  purchase_id   INT UNSIGNED NOT NULL,
  weight        DECIMAL(12,3) NOT NULL,
  item_price    DECIMAL(14,2) NULL,
  small_sacks   DECIMAL(10,2) NULL,
  large_sacks   DECIMAL(10,2) NULL,
  warehouse_uid CHAR(36) NULL,
  KEY idx_si_customer (customer_id),
  KEY idx_si_sale (sale_id),
  KEY idx_si_purchase (purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  sale_id       INT UNSIGNED NOT NULL UNIQUE,
  recv_type     VARCHAR(10) NOT NULL, -- lump | staged
  staged_type   VARCHAR(10) NULL,     -- credit | cheque
  KEY idx_rec_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_lump_tx (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id     INT UNSIGNED NOT NULL,
  receipt_id      INT UNSIGNED NOT NULL,
  from_account    VARCHAR(150) NULL,
  payer_name      VARCHAR(150) NULL,
  to_account      VARCHAR(150) NULL,
  receiver_name   VARCHAR(150) NULL,
  amount          DECIMAL(14,2) NOT NULL,
  date            DATE NOT NULL,
  paid            TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_rlt_customer (customer_id),
  KEY idx_rlt_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_credit_inst (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id     INT UNSIGNED NOT NULL,
  receipt_id      INT UNSIGNED NOT NULL,
  due_date        DATE NOT NULL,
  amount          DECIMAL(14,2) NOT NULL,
  from_account    VARCHAR(150) NULL,
  payer_name      VARCHAR(150) NULL,
  to_account      VARCHAR(150) NULL,
  receiver_name   VARCHAR(150) NULL,
  paid            TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_rci_customer (customer_id),
  KEY idx_rci_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_cheque_inst (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id     INT UNSIGNED NOT NULL,
  receipt_id      INT UNSIGNED NOT NULL,
  due_date        DATE NOT NULL,
  amount          DECIMAL(14,2) NOT NULL,
  cheque_no       VARCHAR(60) NULL,
  sayad_id      VARCHAR(16) NULL,
  bank_name       VARCHAR(100) NULL,
  from_account    VARCHAR(150) NULL,
  payer_name      VARCHAR(150) NULL,
  to_account      VARCHAR(150) NULL,
  receiver_name   VARCHAR(150) NULL,
  paid            TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_rch_customer (customer_id),
  KEY idx_rch_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v14 stock ledger (S01.T01.2)
CREATE TABLE IF NOT EXISTS stock_moves (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NOT NULL,
  warehouse_uid CHAR(36) NULL,
  ounce_uid CHAR(36) NULL,
  kind VARCHAR(20) NOT NULL,
  doc_type VARCHAR(20) NOT NULL,
  doc_id BIGINT UNSIGNED NOT NULL,
  qty_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
  small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
  large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(18,4) NULL,
  move_date DATE NOT NULL,
  ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversal_of BIGINT UNSIGNED NULL,
  reversed TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_sm_lot (customer_id, lot_id),
  KEY idx_sm_doc (customer_id, doc_type, doc_id),
  KEY idx_sm_wh (customer_id, warehouse_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS stock_state (
  customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
  built_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  uid CHAR(36) NOT NULL,
  lot_id INT UNSIGNED NOT NULL,
  from_warehouse_uid CHAR(36) NOT NULL,
  to_warehouse_uid CHAR(36) NOT NULL,
  qty_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
  small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
  large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
  move_date DATE NOT NULL,
  note VARCHAR(500) NULL,
  cancelled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_st_uid (customer_id, uid),
  KEY idx_st_lot (customer_id, lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v16 (S02.T02.1): staff accounts & roles (see migration_v16_staff.sql)
CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  perms TEXT NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_roles_cid (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS staff_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  username VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  allowed_fy_ids TEXT NULL,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_staff_user (customer_id, username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v17 (S02.T02.3): audit log (see migration_v17_audit_log.sql)
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  staff_id INT UNSIGNED NOT NULL DEFAULT 0,
  staff_name VARCHAR(120) NOT NULL DEFAULT '',
  action VARCHAR(20) NOT NULL,
  entity VARCHAR(60) NOT NULL,
  entity_id VARCHAR(64) NOT NULL DEFAULT '',
  summary VARCHAR(255) NOT NULL DEFAULT '',
  before_json TEXT NULL,
  after_json TEXT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  prev_hash CHAR(40) NULL, -- v55 S31.T31.5 hash chain
  row_hash CHAR(40) NULL,
  KEY idx_audit_cid (customer_id, id),
  KEY idx_audit_ent (customer_id, entity, entity_id),
  KEY idx_audit_staff (customer_id, staff_id),
  KEY idx_audit_ts (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v18 (S03.T03.1): party master (see migration_v18_parties.sql)
CREATE TABLE IF NOT EXISTS parties (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  types VARCHAR(200) NULL, phone VARCHAR(30) NULL, address VARCHAR(300) NULL,
  national_id VARCHAR(20) NULL, economic_code VARCHAR(20) NULL, postal_code VARCHAR(20) NULL,
  credit_limit BIGINT NULL, opening_balance BIGINT NOT NULL DEFAULT 0,
  opening_side VARCHAR(10) NOT NULL DEFAULT 'debtor', active TINYINT(1) NOT NULL DEFAULT 1,
  note VARCHAR(500) NULL, created_at DATETIME NULL,
  tags VARCHAR(300) NULL, stage VARCHAR(20) NOT NULL DEFAULT 'active', -- v19 (T03.3)
  UNIQUE KEY uq_party (customer_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S04.T04.1 purchase returns (v20)
CREATE TABLE IF NOT EXISTS purchase_returns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(40) NOT NULL,
        number INT UNSIGNED NOT NULL,
        party VARCHAR(150) NOT NULL,
        date DATE NOT NULL,
        refund_mode VARCHAR(20) NOT NULL DEFAULT 'reduce_payable',
        refund_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        cheque_no VARCHAR(60) NULL,
        cheque_due DATE NULL,
        note VARCHAR(500) NULL,
        tax_amount DECIMAL(18,2) NULL,
        voucher_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pr_uid (customer_id, uid),
        UNIQUE KEY uq_pr_number (customer_id, number),
        KEY idx_pr_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_return_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        return_id BIGINT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        warehouse_uid CHAR(36) NULL,
        kg DECIMAL(16,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit_price DECIMAL(18,4) NOT NULL,
        KEY idx_pri_ret (customer_id, return_id),
        KEY idx_pri_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S04.T04.2 sale returns (v21)
CREATE TABLE IF NOT EXISTS sale_returns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(40) NOT NULL,
        number INT UNSIGNED NOT NULL,
        party VARCHAR(150) NOT NULL,
        date DATE NOT NULL,
        refund_mode VARCHAR(20) NOT NULL DEFAULT 'reduce_receivable',
        refund_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        cheque_no VARCHAR(60) NULL,
        cheque_due DATE NULL,
        note VARCHAR(500) NULL,
        tax_amount DECIMAL(18,2) NULL,
        voucher_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sr_uid (customer_id, uid),
        UNIQUE KEY uq_sr_number (customer_id, number),
        KEY idx_sr_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_return_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        return_id BIGINT UNSIGNED NOT NULL,
        sale_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        warehouse_uid CHAR(36) NULL,
        kg DECIMAL(16,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit_price DECIMAL(18,4) NOT NULL,
        KEY idx_sri_ret (customer_id, return_id),
        KEY idx_sri_sale (customer_id, sale_id),
        KEY idx_sri_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S04.T04.3 proformas (v22)
CREATE TABLE IF NOT EXISTS proformas (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid VARCHAR(40) NOT NULL,
        number INT UNSIGNED NOT NULL,
        party VARCHAR(150) NOT NULL,
        date DATE NOT NULL,
        valid_until DATE NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        reserve TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(500) NULL,
        terms VARCHAR(1000) NULL,
        converted_at DATETIME NULL,
        tax_amount DECIMAL(18,2) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pf_uid (customer_id, uid),
        UNIQUE KEY uq_pf_number (customer_id, number),
        KEY idx_pf_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proforma_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        proforma_id BIGINT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NULL,
        description VARCHAR(200) NULL,
        kg DECIMAL(16,3) NOT NULL,
        unit_price DECIMAL(18,4) NOT NULL,
        discount DECIMAL(18,2) NOT NULL DEFAULT 0,
        sale_id INT UNSIGNED NULL,
        KEY idx_pfi_pf (customer_id, proforma_id),
        KEY idx_pfi_lot (customer_id, lot_id),
        KEY idx_pfi_sale (customer_id, sale_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- sales.proforma_id is added by pb_proforma_ensure() (migration v22)

-- S05.T05.1 brokers + commissions (v23)
CREATE TABLE IF NOT EXISTS broker_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    broker_id INT UNSIGNED NOT NULL,
    basis VARCHAR(10) NOT NULL DEFAULT 'percent',
    value DECIMAL(14,4) NOT NULL DEFAULT 0,
    applies VARCHAR(10) NOT NULL DEFAULT 'both',
    payer VARCHAR(15) NOT NULL DEFAULT 'us',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    UNIQUE KEY uq_broker_rule (customer_id, broker_id, applies),
    KEY idx_broker_rule_c (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS broker_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    uid VARCHAR(64) NULL,
    broker_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    amount BIGINT NOT NULL DEFAULT 0,
    note VARCHAR(300) NULL,
    created_at DATETIME NULL,
    UNIQUE KEY uq_broker_pay_uid (customer_id, uid),
    KEY idx_broker_pay_c (customer_id, broker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- purchases/sales broker_* columns are added by migration v23 (pb_broker_ensure) on fresh installs too.

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
    bank_ref VARCHAR(64) NULL,
    bank_charge BIGINT NULL,
    created_at DATETIME NULL,
    KEY idx_cheque_ev (customer_id, cheque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S07.T07.1 (v25)
CREATE TABLE IF NOT EXISTS separations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        sep_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        labor_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
        transport_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
        waste_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        cost_alloc VARCHAR(10) NOT NULL DEFAULT 'by_weight',
        warehouse_uid CHAR(36) NULL,
        input_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        input_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        abnormal_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        abnormal_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sep_uid (customer_id, uid),
        KEY idx_sep_cust (customer_id, sep_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS separation_inputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        separation_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NULL,
        large_sacks DECIMAL(10,2) NULL,
        warehouse_uid CHAR(36) NULL,
        unit_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
        cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        KEY idx_si_sep (customer_id, separation_id),
        KEY idx_si_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS separation_outputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        separation_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        type_name VARCHAR(100) NOT NULL,
        ounce_uid CHAR(36) NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        alloc_price DECIMAL(16,2) NULL,
        cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        KEY idx_so_sep (customer_id, separation_id),
        KEY idx_so_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lot_lineage (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        separation_id INT UNSIGNED NOT NULL,
        parent_lot_id INT UNSIGNED NOT NULL,
        child_lot_id INT UNSIGNED NOT NULL,
        parent_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        KEY idx_ll_parent (customer_id, parent_lot_id),
        KEY idx_ll_child (customer_id, child_lot_id),
        KEY idx_ll_sep (customer_id, separation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS separation_settings (
        customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
        tolerance_kg DECIMAL(10,4) NOT NULL DEFAULT 0.0015,
        abnormal_pct DECIMAL(6,2) NOT NULL DEFAULT 5.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S08.T08.1 consignment (v26). Consigned-in lots live in purchases with is_mix=3.
CREATE TABLE IF NOT EXISTS consignments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        direction VARCHAR(4) NOT NULL DEFAULT 'in',
        party VARCHAR(150) NOT NULL,
        cons_date DATE NOT NULL,
        warehouse_uid CHAR(36) NULL,
        settlement_mode VARCHAR(20) NOT NULL DEFAULT 'commission',
        commission_type VARCHAR(10) NOT NULL DEFAULT 'percent',
        commission_value DECIMAL(14,4) NOT NULL DEFAULT 0,
        agreed_price DECIMAL(16,2) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cons_uid (customer_id, uid),
        KEY idx_cons_cust (customer_id, cons_date),
        KEY idx_cons_party (customer_id, party)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consignment_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        consignment_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        KEY idx_ci_cons (customer_id, consignment_id),
        KEY idx_ci_lot (customer_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consignment_returns (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        consignment_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(10,2) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        ret_date DATE NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cr_uid (customer_id, uid),
        KEY idx_cr_cons (customer_id, consignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consignment_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        consignment_id INT UNSIGNED NOT NULL,
        pay_date DATE NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cp_uid (customer_id, uid),
        KEY idx_cp_cons (customer_id, consignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S08.T08.2 consignment OUT (v27): sold reports of goods held by consignees (each links to an ordinary sales row).
CREATE TABLE IF NOT EXISTS consignment_sales (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        consignment_id INT UNSIGNED NOT NULL,
        sale_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        sold_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_csl_uid (customer_id, uid),
        UNIQUE KEY uq_csl_sale (customer_id, sale_id),
        KEY idx_csl_cons (customer_id, consignment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S09.T09.1 processing orders (v28)
CREATE TABLE IF NOT EXISTS proc_orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        direction VARCHAR(10) NOT NULL,
        party VARCHAR(150) NOT NULL,
        order_date DATE NOT NULL,
        service VARCHAR(20) NOT NULL DEFAULT 'other',
        fee_basis VARCHAR(10) NOT NULL DEFAULT 'per_kg',
        fee_value DECIMAL(18,4) NOT NULL DEFAULT 0,
        fee_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        standard_yield_pct DECIMAL(6,2) NOT NULL DEFAULT 100,
        waste_charge_per_kg DECIMAL(18,4) NOT NULL DEFAULT 0,
        charge_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'in_progress',
        warehouse_uid CHAR(36) NULL,
        input_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        input_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        output_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        waste_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        yield_pct DECIMAL(8,3) NULL,
        receive_date DATE NULL,
        deliver_date DATE NULL,
        note VARCHAR(500) NULL,
        op_type VARCHAR(20) NULL,
        owner VARCHAR(150) NULL,
        pistachio_type VARCHAR(100) NULL,
        defect_note VARCHAR(255) NULL,
        initial_kg DECIMAL(14,3) NULL,
        bale_count DECIMAL(10,2) NULL,
        loss_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        balance_diff_kg DECIMAL(14,3) NULL,
        balance_ok TINYINT NULL,
        undelivered_kg DECIMAL(14,3) NULL,
        price_per_kg DECIMAL(18,4) NULL,
        wage_per_kg DECIMAL(18,4) NULL,
        other_cost DECIMAL(18,2) NULL,
        price_deformed DECIMAL(18,4) NULL,
        price_discolored DECIMAL(18,4) NULL,
        price_hull DECIMAL(18,4) NULL,
        price_soil DECIMAL(18,4) NULL,
        price_kernel DECIMAL(18,4) NULL,
        value_in DECIMAL(18,2) NULL,
        cost_total DECIMAL(18,2) NULL,
        recovery_value DECIMAL(18,2) NULL,
        cost_sound DECIMAL(18,2) NULL,
        cost_per_kg_sound DECIMAL(18,4) NULL,
        sent_gram_pct DECIMAL(7,3) NULL,
        recv_kernel_kg DECIMAL(14,3) NULL,
        recv_gram_pct DECIMAL(7,3) NULL,
        receipt_no VARCHAR(60) NULL,
        cash_note VARCHAR(150) NULL,
        non_company_note VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_proc_uid (customer_id, uid),
        KEY idx_proc_cust (customer_id, order_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proc_inputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        unit_cost DECIMAL(18,4) NULL,
        cost DECIMAL(18,2) NULL,
        KEY idx_pri_order (customer_id, order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proc_outputs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED NOT NULL,
        type_name VARCHAR(100) NOT NULL,
        kg DECIMAL(14,3) NOT NULL,
        small_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        large_sacks DECIMAL(14,3) NOT NULL DEFAULT 0,
        cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        warehouse_uid CHAR(36) NULL,
        grade VARCHAR(20) NULL,
        owner VARCHAR(150) NULL,
        source_lot_id INT UNSIGNED NULL,
        KEY idx_pro_order (customer_id, order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S10.T10.1 orchards (v29)
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

-- S13.T13.2b import batches (v33)
CREATE TABLE IF NOT EXISTS import_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  batch_id VARCHAR(64) NOT NULL,
  kind VARCHAR(30) NOT NULL,
  all_or_nothing TINYINT(1) NOT NULL DEFAULT 1,
  total_rows INT NOT NULL DEFAULT 0,
  created_n INT NOT NULL DEFAULT 0,
  updated_n INT NOT NULL DEFAULT 0,
  skipped_n INT NOT NULL DEFAULT 0,
  failed_n INT NOT NULL DEFAULT 0,
  result MEDIUMTEXT NULL,
  created_at DATETIME NULL,
  UNIQUE KEY uq_import_batch (customer_id, batch_id),
  KEY idx_import_batch_c (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S15.T15.1 fiscal years (v34)
CREATE TABLE IF NOT EXISTS fiscal_years (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  vat_enabled TINYINT(1) NOT NULL DEFAULT 0,
  vat_rate DECIMAL(6,3) NULL,
  vat_levy_rate DECIMAL(6,3) NULL,
  vat_note VARCHAR(500) NULL,
  vat_source VARCHAR(300) NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  UNIQUE KEY uq_fy_start (customer_id, start_date),
  KEY idx_fy_c (customer_id, start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- S15.T15.2 chart of accounts (v35)
CREATE TABLE IF NOT EXISTS accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  level TINYINT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  nature VARCHAR(8) NOT NULL,
  type VARCHAR(10) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  system_role VARCHAR(40) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  UNIQUE KEY uq_acc_code (customer_id, code),
  KEY idx_acc_parent (customer_id, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  role VARCHAR(40) NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_role (customer_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tafsili (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  kind VARCHAR(12) NOT NULL,
  ref_key VARCHAR(150) NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  UNIQUE KEY uq_taf_code (customer_id, code),
  UNIQUE KEY uq_taf_ref (customer_id, kind, ref_key),
  KEY idx_taf_kind (customer_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S16.T16.1 vouchers (v37)
CREATE TABLE IF NOT EXISTS vouchers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    fiscal_year_id INT UNSIGNED NOT NULL,
    number INT UNSIGNED NULL,
    voucher_date DATE NOT NULL,
    description VARCHAR(500) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    source_type VARCHAR(30) NOT NULL DEFAULT 'manual',
    source_id VARCHAR(64) NULL,
    total BIGINT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_voucher_no (customer_id, fiscal_year_id, number),
    KEY idx_voucher_date (customer_id, voucher_date),
    KEY idx_voucher_src (customer_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS voucher_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    voucher_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    tafsili_id INT UNSIGNED NULL,
    cost_center_id INT UNSIGNED NULL,
    debit BIGINT NOT NULL DEFAULT 0,
    credit BIGINT NOT NULL DEFAULT 0,
    description VARCHAR(500) NULL,
    ccy VARCHAR(8) NULL,
    ccy_amount DECIMAL(20,4) NULL,
    rate DECIMAL(20,6) NULL,
    KEY idx_vl_voucher (voucher_id),
    KEY idx_vl_account (customer_id, account_id),
    KEY idx_vl_tafsili (customer_id, tafsili_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- S19.T19.1 stock count
CREATE TABLE IF NOT EXISTS stock_counts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  uid VARCHAR(40) NOT NULL,
  count_date DATE NOT NULL,
  warehouse_uid CHAR(36) NULL,
  note VARCHAR(500) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sc_uid (customer_id, uid),
  KEY idx_sc_cust (customer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_count_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  count_id INT UNSIGNED NOT NULL,
  lot_id INT UNSIGNED NOT NULL,
  system_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
  counted_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
  diff_kg DECIMAL(16,3) NOT NULL DEFAULT 0,
  reason VARCHAR(200) NULL,
  KEY idx_scl_count (customer_id, count_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v41 (S20.T20.1) upgrade_runs
CREATE TABLE IF NOT EXISTS upgrade_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'running',
    step VARCHAR(16) NOT NULL DEFAULT 'snapshot',
    params TEXT NULL,
    steps MEDIUMTEXT NULL,
    cursor_key VARCHAR(80) NULL,
    snapshot_file VARCHAR(200) NULL,
    fingerprint MEDIUMTEXT NULL,
    report MEDIUMTEXT NULL,
    error VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    KEY idx_ug_c (customer_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v42 (S20.T20.2) currencies + fx_rates
CREATE TABLE IF NOT EXISTS currencies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    code VARCHAR(8) NOT NULL,
    name VARCHAR(60) NOT NULL,
    symbol VARCHAR(8) NULL,
    decimals TINYINT NOT NULL DEFAULT 2,
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    UNIQUE KEY uq_ccy (customer_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fx_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    ccy VARCHAR(8) NOT NULL,
    rate_date DATE NOT NULL,
    rate DECIMAL(18,6) NOT NULL,
    source VARCHAR(60) NULL,
    created_at DATETIME NULL,
    UNIQUE KEY uq_fxr (customer_id, ccy, rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v45 (S24.T24.2) per-customer settings (money_unit gate)
CREATE TABLE IF NOT EXISTS app_settings (
    customer_id INT UNSIGNED NOT NULL,
    k VARCHAR(60) NOT NULL,
    v VARCHAR(255) NOT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (customer_id, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S35.T35.1: grade lines + daily price table (columns on purchases/sales are added by migration v50)
CREATE TABLE IF NOT EXISTS doc_grade_lines (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  doc_type    VARCHAR(10) NOT NULL,
  doc_id      INT UNSIGNED NOT NULL,
  grade_key   VARCHAR(40) NOT NULL,
  kg          DECIMAL(12,3) NOT NULL,
  unit_price  BIGINT NOT NULL,
  KEY idx_dgl_doc (customer_id, doc_type, doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grade_prices (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT UNSIGNED NOT NULL,
  type_name    VARCHAR(100) NOT NULL,
  ounce_uid    CHAR(36) NULL,
  grade_key    VARCHAR(40) NULL,
  price_per_kg BIGINT NOT NULL,
  price_date   DATE NOT NULL,
  KEY idx_gp_lookup (customer_id, type_name, price_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S31.T31.1 bank reconciliation (v53)
CREATE TABLE IF NOT EXISTS bank_stmt_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  bank_uid VARCHAR(40) NOT NULL,
  line_date DATE NOT NULL,
  amount BIGINT NOT NULL,
  description VARCHAR(300) NULL,
  ref VARCHAR(60) NULL,
  line_hash CHAR(40) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  voucher_line_id INT UNSIGNED NULL,
  diff BIGINT NOT NULL DEFAULT 0,
  note VARCHAR(200) NULL,
  created_at DATETIME NULL,
  matched_at DATETIME NULL,
  UNIQUE KEY uq_bsl_hash (customer_id, bank_uid, line_hash),
  KEY idx_bsl_bank (customer_id, bank_uid, status, line_date),
  KEY idx_bsl_vl (customer_id, voucher_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S31.T31.3 pending payment approvals (v54)
CREATE TABLE IF NOT EXISTS pay_approvals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  doc_type VARCHAR(12) NOT NULL DEFAULT 'purchase',
  doc_id INT UNSIGNED NOT NULL,
  amount BIGINT NOT NULL DEFAULT 0,
  payload MEDIUMTEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  requested_by VARCHAR(120) NULL,
  decided_by VARCHAR(120) NULL,
  reason VARCHAR(200) NULL,
  created_at DATETIME NULL,
  decided_at DATETIME NULL,
  KEY idx_pa_cust (customer_id, status, id),
  KEY idx_pa_doc (customer_id, doc_type, doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S31.T31.10 export fields on sales invoice (v56)
CREATE TABLE IF NOT EXISTS sale_export (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  sale_id INT UNSIGNED NOT NULL,
  incoterm VARCHAR(4) NULL,
  ship_method VARCHAR(12) NULL,
  port_loading VARCHAR(100) NULL,
  port_destination VARCHAR(100) NULL,
  ccy_code VARCHAR(8) NULL,
  remit_no VARCHAR(40) NULL,
  lc_bank VARCHAR(100) NULL,
  lc_no VARCHAR(40) NULL,
  pkg_count INT UNSIGNED NULL,
  pkg_type VARCHAR(30) NULL,
  gross_kg DECIMAL(12,3) NULL,
  marks VARCHAR(200) NULL,
  origin_country VARCHAR(60) NULL,
  hs_code VARCHAR(20) NULL,
  cert_no VARCHAR(40) NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_sx_sale (customer_id, sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S31.T31.12 letters of credit (v58)
CREATE TABLE IF NOT EXISTS letters_of_credit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  sale_id INT UNSIGNED NOT NULL,
  lc_no VARCHAR(40) NOT NULL,
  issuing_bank VARCHAR(100) NOT NULL,
  advising_bank VARCHAR(100) NULL,
  ccy_code VARCHAR(8) NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  open_date DATE NULL,
  expiry_date DATE NOT NULL,
  latest_ship_date DATE NULL,
  presentation_days SMALLINT UNSIGNED NOT NULL DEFAULT 21,
  status VARCHAR(12) NOT NULL DEFAULT 'open',
  ship_date DATE NULL,
  presented_date DATE NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_lc_no (customer_id, lc_no),
  KEY idx_lc_sale (customer_id, sale_id),
  KEY idx_lc_exp (customer_id, status, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S31.T31.15 backup restore drill log (v59; not exported in backups)
CREATE TABLE IF NOT EXISTS backup_drills (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  run_at DATETIME NOT NULL,
  source VARCHAR(8) NOT NULL DEFAULT 'live',
  ok TINYINT(1) NOT NULL DEFAULT 0,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  tables_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  checksum CHAR(64) NULL,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(500) NULL,
  report MEDIUMTEXT NULL,
  KEY idx_bd_c (customer_id, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S31.T31.18 error monitoring events (v60; operational log, not exported in backups)
CREATE TABLE IF NOT EXISTS error_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL DEFAULT 0,
  source VARCHAR(8) NOT NULL DEFAULT 'server',
  fingerprint CHAR(40) NOT NULL,
  message VARCHAR(500) NOT NULL,
  location VARCHAR(300) NULL,
  action VARCHAR(80) NULL,
  app_version VARCHAR(30) NULL,
  platform VARCHAR(20) NULL,
  stack TEXT NULL,
  occurrences INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_err_fp (customer_id, source, fingerprint),
  KEY idx_err_c (customer_id, resolved, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- S31.T31.19 TOTP two-step login (v61; credentials, not exported in backups)
CREATE TABLE IF NOT EXISTS customer_2fa (
  customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
  secret VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  last_step BIGINT NOT NULL DEFAULT 0,
  recovery TEXT NULL,
  created_at DATETIME NOT NULL,
  enabled_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v62 (S1 اتاق انس): see migration_v62_ounce_room.sql  (staff_users.staff_code CHAR(2) NULL + UNIQUE(customer_id, staff_code))
CREATE TABLE IF NOT EXISTS lot_code_seq (
  customer_id INT UNSIGNED NOT NULL,
  day CHAR(8) NOT NULL,
  staff_code CHAR(2) NOT NULL,
  last_seq INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (customer_id, day, staff_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v66 گزارش خط شکست (کارگاه مرحلهٔ ۳)
CREATE TABLE IF NOT EXISTS crack_runs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        run_date DATE NOT NULL,
        end_date DATE NULL,
        shift VARCHAR(30) NULL,
        operator_name VARCHAR(100) NULL,
        customer_name VARCHAR(150) NULL,
        line_code VARCHAR(40) NULL,
        pistachio_type VARCHAR(100) NULL,
        customer_code VARCHAR(40) NULL,
        shipment_code VARCHAR(40) NULL,
        input_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        ounce_by VARCHAR(100) NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        machine_setting VARCHAR(100) NULL,
        analyst VARCHAR(100) NULL,
        bale_count DECIMAL(14,3) NULL,
        gram_pct DECIMAL(7,3) NULL,
        moisture_pct DECIMAL(7,3) NULL,
        fines_pct DECIMAL(7,3) NULL,
        run_minutes DECIMAL(14,3) NULL,
        kernel_kg DECIMAL(14,3) NULL,
        hull_kg DECIMAL(14,3) NULL,
        soil_kg DECIMAL(14,3) NULL,
        hull_left_kg DECIMAL(14,3) NULL,
        sound_kg DECIMAL(14,3) NULL,
        wheat_kg DECIMAL(14,3) NULL,
        super_fine_kg DECIMAL(14,3) NULL,
        flower_kg DECIMAL(14,3) NULL,
        split_kg DECIMAL(14,3) NULL,
        unbreakable_kg DECIMAL(14,3) NULL,
        pink_kg DECIMAL(14,3) NULL,
        waste_kernel_kg DECIMAL(14,3) NULL,
        initial_kernel_kg DECIMAL(14,3) NULL,
        power_kwh DECIMAL(14,3) NULL,
        note VARCHAR(500) NULL,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_by_name VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_crack_uid (customer_id, uid),
        KEY idx_crack_cust (customer_id, run_date),
        KEY idx_crack_by (customer_id, created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crack_stops (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        run_id INT UNSIGNED NOT NULL,
        reason VARCHAR(150) NULL,
        minutes DECIMAL(10,2) NOT NULL DEFAULT 0,
        KEY idx_cstop_run (customer_id, run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS feed_adjust (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        adj_date DATE NOT NULL,
        kind VARCHAR(3) NOT NULL DEFAULT 'in',
        owner VARCHAR(150) NULL,
        workshop VARCHAR(150) NULL,
        receipt_no VARCHAR(60) NULL,
        kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        reason VARCHAR(300) NULL,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_by_name VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_feedadj_uid (customer_id, uid),
        KEY idx_feedadj_cust (customer_id, adj_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
