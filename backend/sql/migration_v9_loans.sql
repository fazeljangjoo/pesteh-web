-- نسخهٔ ۹: طرف‌حساب‌های دستی + دفتر قرض و وام
-- اجرای دستی لازم نیست (app.php خودش می‌سازد)؛ این فایل فقط برای مستندسازی/نصب تازه است.
-- مبالغ قرض/وام هرگز در سود و زیان حساب نمی‌شوند.

CREATE TABLE IF NOT EXISTS parties (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_party (customer_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- kind: given = قرض اعطایی | taken = قرض دریافتی | repay_in = بازپرداخت دریافتی | repay_out = بازپرداخت پرداختی
CREATE TABLE IF NOT EXISTS loan_entries (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  uid         VARCHAR(40) NOT NULL,
  party       VARCHAR(150) NOT NULL,
  kind        VARCHAR(12) NOT NULL,
  amount      DECIMAL(14,2) NOT NULL,
  date        DATE NOT NULL,
  note        VARCHAR(500) NULL,
  created_at  DATETIME NULL,
  UNIQUE KEY uq_loan_uid (customer_id, uid),
  KEY idx_loan_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- سررسید/اقساط هر قرض (حداکثر ۲۰ مورد) + ستون زیردستهٔ مخلوط
CREATE TABLE IF NOT EXISTS loan_dues (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  loan_uid    VARCHAR(40) NOT NULL,
  seq         TINYINT UNSIGNED NOT NULL,
  due_date    DATE NOT NULL,
  amount      DECIMAL(14,2) NOT NULL,
  note        VARCHAR(500) NULL,
  paid        TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_loan_due (customer_id, loan_uid, seq),
  KEY idx_due_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE purchases ADD COLUMN base_type VARCHAR(100) NULL DEFAULT NULL;

-- هزینه کارگر و حمل‌ونقل/باسکول (خرید و فروش)
ALTER TABLE purchases ADD COLUMN labor_cost DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE purchases ADD COLUMN transport_cost DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE sales ADD COLUMN labor_cost DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE sales ADD COLUMN transport_cost DECIMAL(14,2) NULL DEFAULT NULL;

-- پیام‌های مدیر (تب خانه)
CREATE TABLE IF NOT EXISTS announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NULL,
  title VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  link_url VARCHAR(500) NULL,
  link_label VARCHAR(60) NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'info',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_ann_target (customer_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS announcement_reads (
  customer_id INT UNSIGNED NOT NULL,
  announcement_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL,
  PRIMARY KEY (customer_id, announcement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
