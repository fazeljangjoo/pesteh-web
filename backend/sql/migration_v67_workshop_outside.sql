-- v67 (کارگاه مرحلهٔ ۴: کارگاه بیرونی با قبض) — مستندات دستی. اجرای واقعی: pb_mig_67_workshop_outside (PHP، idempotent).
-- روی جهت موجود proc_orders.direction='paid'؛ owner / bale_count / input_kg از قبل هستند. هیچ ستون مالی و هیچ مجوز جدیدی نیست.
-- G = F*E/100 · J = G-H · K = F-I (مشتق در pb_ow_calc، ذخیره نمی‌شوند).
ALTER TABLE proc_orders
  ADD COLUMN sent_gram_pct DECIMAL(7,3) NULL,      -- F گرم پستهٔ ارسالی (٪)
  ADD COLUMN recv_kernel_kg DECIMAL(14,3) NULL,    -- H مقدار مغز دریافتی (کیلو)
  ADD COLUMN recv_gram_pct DECIMAL(7,3) NULL,      -- I گرم دریافتی (٪)
  ADD COLUMN receipt_no VARCHAR(60) NULL,          -- B شمارهٔ قبض
  ADD COLUMN cash_note VARCHAR(150) NULL,          -- A «کش» (متن اختیاری تا تأیید کاربر)
  ADD COLUMN non_company_note VARCHAR(150) NULL;   -- «غیر شرکتی» (متن اختیاری تا تأیید کاربر)
