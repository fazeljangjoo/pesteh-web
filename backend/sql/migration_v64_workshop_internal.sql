-- v64 (کارگاه مرحلهٔ ۱) — مستندات دستی. اجرای واقعی: pb_mig_64_workshop_internal (PHP، idempotent).
-- جهت جدید proc_orders.direction = 'internal' (کارگاه داخلی). ستون‌های جدید (فقط اگر نیستند):
ALTER TABLE proc_orders
  ADD COLUMN op_type VARCHAR(20) NULL,            -- dehull|desoil|split|fines|crack
  ADD COLUMN owner VARCHAR(150) NULL,             -- C اکسل
  ADD COLUMN pistachio_type VARCHAR(100) NULL,    -- B
  ADD COLUMN defect_note VARCHAR(255) NULL,       -- D ایراد بار
  ADD COLUMN initial_kg DECIMAL(14,3) NULL,       -- F وزن خالص اولیه
  ADD COLUMN bale_count DECIMAL(10,2) NULL,       -- G تعداد عدل
  ADD COLUMN loss_kg DECIMAL(14,3) NOT NULL DEFAULT 0, -- O افت (به موجودی برنمی‌گردد)
  ADD COLUMN balance_diff_kg DECIMAL(14,3) NULL,  -- R = H-(I+J+K+L+M+N+O)
  ADD COLUMN balance_ok TINYINT NULL,             -- U: |R| < 0.01
  ADD COLUMN undelivered_kg DECIMAL(14,3) NULL;   -- T = F-H
ALTER TABLE proc_outputs
  ADD COLUMN grade VARCHAR(20) NULL,              -- sound|deformed_fine|hull|kernel|soil|discolored
  ADD COLUMN owner VARCHAR(150) NULL,
  ADD COLUMN source_lot_id INT UNSIGNED NULL;     -- لات اصلی (بزرگ‌ترین ورودی)
-- مجوزها: مالک += workshop.view/operate/manage ؛ حسابدار += workshop.view ؛ نقش سیستمی «اپراتور کارگاه» = [workshop.operate, workshop.view] (بدون price.view)
