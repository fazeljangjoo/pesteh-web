-- مرحلهٔ ۱۳ (v11) — P1: جدول نشست‌های هش‌شدهٔ توکن + بک‌فیل. ایدم‌پوتنت.
-- هیچ ستونی روی جدول‌های موجود اضافه/تغییر نمی‌شود. customers.api_token دست‌نخورده می‌ماند (fallback).
-- نسخهٔ MySQL/MariaDB (InnoDB). اجرای مجدد خطا نمی‌دهد و ردیف تکراری نمی‌سازد.

CREATE TABLE IF NOT EXISTS customer_tokens (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT UNSIGNED NOT NULL,
  token_hash   CHAR(64)     NOT NULL,
  device       VARCHAR(64)  NULL,
  created_at   DATETIME     NOT NULL,
  last_used_at DATETIME     NULL,
  expires_at   DATETIME     NULL,
  UNIQUE KEY uniq_token_hash (token_hash),
  KEY idx_ct_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- بک‌فیل: هر api_token موجود یک ردیف هش می‌شود. SHA2(x,256) = هگز کوچک sha256 (با hash('sha256') در PHP یکی).
-- IGNORE فقط برای تکراری‌ها (اجرای مجدد) است؛ شرط پاسخ: migration_v11_verify.sql بند missing_hash = 0.
INSERT IGNORE INTO customer_tokens (customer_id, token_hash, created_at)
SELECT id, SHA2(api_token, 256), NOW()
FROM customers
WHERE api_token IS NOT NULL AND api_token <> '';
