-- اسکیمای جدول مشتری‌ها/اشتراک‌ها
-- این فایل را فقط یک‌بار، از طریق phpMyAdmin (تب Import یا SQL) اجرا کنید.

CREATE TABLE IF NOT EXISTS customers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone         VARCHAR(15)  NOT NULL,
  name          VARCHAR(100) NULL,
  password_hash VARCHAR(255) NOT NULL,
  api_token     CHAR(64)     NULL,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  tier          ENUM('green','gold') NOT NULL DEFAULT 'green', -- v12
  expiry_date   DATE         NOT NULL,
  note          VARCHAR(255) NULL,
  created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
