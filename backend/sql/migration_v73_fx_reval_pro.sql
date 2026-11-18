-- v73 (تسعیر ارز حرفه‌ای، مرحلهٔ ۱) — مستندات دستی.
-- اجرای واقعی: pb_mig_73_fx_reval_pro (PHP، idempotent با SHOW COLUMNS) + pb_fxr_ensure در fx_reval.php.
-- rev_date: تاریخ سند برگشت خودکار (= as_of + ۱ روز). rev_status: none|pending|posted|cancelled (none = ردیف قدیمی بدون برگشت خودکار).
-- doc_count/net_delta: تعداد فاکتور و خالص تأثیر سود/زیان (ریال). note/reversed_by: یادداشت و ابطال‌کننده.
ALTER TABLE fx_revals ADD COLUMN rev_date DATE NULL;
ALTER TABLE fx_revals ADD COLUMN rev_status VARCHAR(10) NOT NULL DEFAULT 'none';
ALTER TABLE fx_revals ADD COLUMN doc_count INT NOT NULL DEFAULT 0;
ALTER TABLE fx_revals ADD COLUMN net_delta BIGINT NOT NULL DEFAULT 0;
ALTER TABLE fx_revals ADD COLUMN note VARCHAR(255) NULL;
ALTER TABLE fx_revals ADD COLUMN reversed_by VARCHAR(120) NULL;
-- نقش حساب جدید: fx_unrealized = «سود و زیان تسعیر ارز (تحقق‌نیافته)» کد ۵۲۰۹ (زیر ۵۲ هزینه‌های عملیاتی)؛ برای مشتریان طلایی-ارزی با pb_acc_backfill_roles ساخته می‌شود.
