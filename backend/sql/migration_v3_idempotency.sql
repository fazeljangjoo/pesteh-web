-- مهاجرت نسخه ۳: جدول کلیدهای یکتا برای جلوگیری از ثبت تکراری روی اینترنت ضعیف
-- (اگر اجرا نکنید هم app.php این جدول را در اولین درخواست خودش می‌سازد؛ اجرای دستی اختیاری است)
CREATE TABLE IF NOT EXISTS idempotency_keys (
  customer_id INT UNSIGNED NOT NULL,
  idem_key    VARCHAR(64)  NOT NULL,
  status      TINYINT      NOT NULL DEFAULT 0,
  http_code   SMALLINT     NOT NULL DEFAULT 0,
  response    MEDIUMTEXT   NULL,
  created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id, idem_key),
  KEY idx_idem_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
