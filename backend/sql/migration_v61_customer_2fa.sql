-- v61 (S31.T31.19): TOTP 2FA state. Mirrors pb_2fa_ensure(). Credentials: NOT part of backup export/import.
CREATE TABLE IF NOT EXISTS customer_2fa (
  customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
  secret VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  last_step BIGINT NOT NULL DEFAULT 0,
  recovery TEXT NULL,
  created_at DATETIME NOT NULL,
  enabled_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
