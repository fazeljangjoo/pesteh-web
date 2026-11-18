-- v17 (S02.T02.3): audit log. Also applied automatically by pb_migrate() (pb_mig_17_audit_log).
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
  KEY idx_audit_cid (customer_id, id),
  KEY idx_audit_ent (customer_id, entity, entity_id),
  KEY idx_audit_staff (customer_id, staff_id),
  KEY idx_audit_ts (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
