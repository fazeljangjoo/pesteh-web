-- v65 (کارگاه مرحلهٔ ۲: بهای تمام‌شده) — مستندات دستی. اجرای واقعی: pb_mig_65_workshop_cost (PHP، idempotent).
-- فرمول (ریال): W=H*V · Z=H*X+Y · AF=J*AA+K*AC+L*AE+M*AD+N*AB (افت ارزش ندارد) · AG=W+Z-AF (می‌تواند منفی باشد) · AH=AG/I
ALTER TABLE proc_orders
  ADD COLUMN price_per_kg DECIMAL(18,4) NULL,       -- V قیمت خرید هر کیلو (پیش‌فرض: میانگین موزون بهای لات‌های ورودی)
  ADD COLUMN wage_per_kg DECIMAL(18,4) NULL,        -- X اجرت تولید هر کیلو
  ADD COLUMN other_cost DECIMAL(18,2) NULL,         -- Y سایر هزینه تولید (مبلغ کل)
  ADD COLUMN price_deformed DECIMAL(18,4) NULL,     -- AA قیمت بدشکل و ریز
  ADD COLUMN price_discolored DECIMAL(18,4) NULL,   -- AB قیمت بدرنگ
  ADD COLUMN price_hull DECIMAL(18,4) NULL,         -- AC قیمت فروش پوست
  ADD COLUMN price_soil DECIMAL(18,4) NULL,         -- AD قیمت خاک
  ADD COLUMN price_kernel DECIMAL(18,4) NULL,       -- AE قیمت فروش مغز
  ADD COLUMN value_in DECIMAL(18,2) NULL,           -- W ارزش کل ورودی
  ADD COLUMN cost_total DECIMAL(18,2) NULL,         -- Z کل هزینه تولید
  ADD COLUMN recovery_value DECIMAL(18,2) NULL,     -- AF ارزش بازیافتی محصولات فرعی
  ADD COLUMN cost_sound DECIMAL(18,2) NULL,         -- AG بهای تمام‌شدهٔ سالم (منفی‌پذیر)
  ADD COLUMN cost_per_kg_sound DECIMAL(18,4) NULL;  -- AH بهای هر کیلو سالم (I=0 ⇒ NULL)
-- مجوز جدید workshop.cost (مالک/حسابدار)؛ اپراتور کارگاه: بدون workshop.cost و بدون price.view.
