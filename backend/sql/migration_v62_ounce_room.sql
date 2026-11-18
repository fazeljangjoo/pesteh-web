-- v62 (S1 اتاق انس): also applied automatically by pb_migrate() (pb_mig_62_ounce_room).
-- شمارندهٔ کد پسته (KH + yyyMMdd شمسی + کد پرسنل ۲ رقمی + ترتیب روز)
CREATE TABLE IF NOT EXISTS lot_code_seq (
  customer_id INT UNSIGNED NOT NULL,
  day CHAR(8) NOT NULL,
  staff_code CHAR(2) NOT NULL,
  last_seq INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (customer_id, day, staff_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE staff_users ADD COLUMN staff_code CHAR(2) NULL, ADD UNIQUE KEY uq_staff_code (customer_id, staff_code);
-- پر کردن staff_code برای کارکنان موجود و افزودن مجوزها/نقش «اتاق انس»: توسط pb_mig_62_ounce_room (PHP) انجام می‌شود، نه SQL دستی.
