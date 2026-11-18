-- v71 (اعتبار برگشت مصرف‌شده روی اقساط) — مستندات دستی. اجرای واقعی: pb_mig_71_credit_used (PHP، idempotent).
-- credit_used = بخشی از مبلغ اصلی ردیف که با اعتبار برگشت تسویه شده؛ amount بعد از «پرداخت‌شد» به مبلغ کاهش‌یافته تغییر می‌کند.
ALTER TABLE lump_tx ADD COLUMN credit_used DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE credit_inst ADD COLUMN credit_used DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE cheque_inst ADD COLUMN credit_used DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE receipt_lump_tx ADD COLUMN credit_used DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE receipt_credit_inst ADD COLUMN credit_used DECIMAL(14,2) NULL DEFAULT NULL;
ALTER TABLE receipt_cheque_inst ADD COLUMN credit_used DECIMAL(14,2) NULL DEFAULT NULL;
