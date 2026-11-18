-- v12: نسخهٔ سبز/طلایی. اجرای دستی اختیاری است؛ api/migrations.php خودکار و idempotent اجرا می‌کند.
-- اگر ستون از قبل باشد ALTER خطا می‌دهد که بی‌خطر است.
ALTER TABLE customers ADD COLUMN tier ENUM('green','gold') NOT NULL DEFAULT 'green';
CREATE TABLE IF NOT EXISTS schema_info (
  k VARCHAR(40) NOT NULL PRIMARY KEY,
  v VARCHAR(100) NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_info (k, v) VALUES ('version', '12') ON DUPLICATE KEY UPDATE v = '12';
