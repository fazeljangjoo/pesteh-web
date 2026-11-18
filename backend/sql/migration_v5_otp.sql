-- ورود با پیامک: جدول کدهای یکبار مصرف
-- (کد خودش این جدول را در اولین درخواست می‌سازد؛ اجرای دستی این فایل اختیاری است.)
CREATE TABLE IF NOT EXISTS otp_codes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone      VARCHAR(15) NOT NULL,
  code_hash  CHAR(64)    NOT NULL,
  code_hash2 CHAR(64)    NULL,
  ip         VARCHAR(45) NOT NULL DEFAULT '',
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used       TINYINT(1)  NOT NULL DEFAULT 0,
  expires_at DATETIME    NOT NULL,
  created_at DATETIME    NOT NULL,
  KEY idx_otp_phone (phone, created_at),
  KEY idx_otp_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
