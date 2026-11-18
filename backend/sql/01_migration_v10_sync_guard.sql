-- مهاجرت نسخهٔ ۱۰ (مرحلهٔ ۱۱): سپر همگام‌سازی — فقط ۳ جدول جدید، هیچ تغییری روی جدول‌های موجود.
-- ایدم‌پوتنت: CREATE TABLE IF NOT EXISTS روی همهٔ نسخه‌های MySQL و MariaDB کار می‌کند (نیازی به ADD COLUMN IF NOT EXISTS نیست).
-- تا وقتی PB_SYNC_GUARD روشن نشود این جدول‌ها استفاده نمی‌شوند؛ اجرای SQL بی‌خطر و بدون اثر بر رفتار فعلی است.
-- بازگشت: migration_v10_rollback.sql

-- لاگ تسویهٔ دستیار: دستیار پیش از mark_paid می‌نویسد؛ fp = due_date|amount|cheque_no (یکجا: date|amount|)
CREATE TABLE IF NOT EXISTS assistant_settle_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  owner_type  VARCHAR(10)  NOT NULL,            -- purchase | sale
  owner_id    INT UNSIGNED NOT NULL,
  fp          VARCHAR(255) NOT NULL,
  paid_at     DATETIME     NOT NULL,
  source      VARCHAR(20)  NULL DEFAULT NULL,
  KEY idx_asl_owner (customer_id, owner_type, owner_id),
  KEY idx_asl_paid (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- تداخل‌های شناسایی‌شده (نسخهٔ اپ برنده شد و ثبت دستیار حفظ نشد)
CREATE TABLE IF NOT EXISTS assistant_sync_conflicts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  owner_type  VARCHAR(10)  NOT NULL,
  owner_id    INT UNSIGNED NOT NULL,
  fp          VARCHAR(255) NOT NULL,
  reason      VARCHAR(40)  NOT NULL,            -- app_edited | ...
  detected_at DATETIME     NOT NULL,
  KEY idx_asc_owner (customer_id, owner_type, owner_id),
  KEY idx_asc_det (detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ادعای تطبیق بازپرداخت R… (ساخته‌شدهٔ سرور) با بازپرداخت اپ؛ هر R… فقط یک‌بار قابل ادعاست
CREATE TABLE IF NOT EXISTS repay_claims (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  ruid        VARCHAR(40)  NOT NULL,
  claimed_by  VARCHAR(40)  NULL DEFAULT NULL,   -- uid بازپرداخت اپ یا due_ref
  claimed_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_claim (customer_id, ruid),
  KEY idx_rc_owner (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
