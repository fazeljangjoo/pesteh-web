-- مهاجرت نسخه ۸: ضایعات/خاک در مخلوط/جداسازی
-- توجه: اجرای دستی اختیاری است؛ app.php این ستون را در اولین درخواست خودکار می‌سازد.
-- waste = وزن ضایعات/خاک (کیلو). برای مخلوط‌های قدیمی NULL می‌ماند.
ALTER TABLE purchases ADD COLUMN waste DECIMAL(12,3) NULL DEFAULT NULL;
