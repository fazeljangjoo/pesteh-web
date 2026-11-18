-- v72 (کارگاه داخلی، مرحلهٔ ۴: قیمت هر کیلوی ردیف خروجی + «خروجی جدید») — مستندات دستی.
-- اجرای واقعی: pb_mig_72_workshop_outputs_price (PHP، idempotent با SHOW COLUMNS).
-- out_price = قیمت هر کیلوی همان ردیف خروجی (NULL = ثبت‌نشده یا ردیف «سالم»؛ بهای سالم محاسبه می‌شود).
-- grade جدید 'custom' (نام آزاد در type_name، چند ردیف مجاز) بدون تغییر ساختار: proc_outputs.grade از قبل VARCHAR(20) است.
ALTER TABLE proc_outputs ADD COLUMN out_price DECIMAL(18,4) NULL;
