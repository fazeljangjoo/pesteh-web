-- مهاجرت نسخه ۷: پلن ارزی (ریالی / ارزی) + قیمت دلار روی خرید و فروش
-- توجه: اجرای دستی اختیاری است؛ config.php و app.php این ستون‌ها را در اولین درخواست خودکار می‌سازند.
-- اگر ستونی از قبل وجود داشته باشد، ALTER خطا می‌دهد که بی‌خطر است (یعنی قبلاً اجرا شده).

-- نوع اشتراک مشتری: rial (پیش‌فرض، همهٔ مشتری‌های فعلی) | fx
ALTER TABLE customers ADD COLUMN plan VARCHAR(10) NOT NULL DEFAULT 'rial';

-- usd_rate = قیمت دلار هنگام خرید (تومان)، price_usd = فی خرید به دلار برای هر کیلو (= price ÷ usd_rate)
-- برای بار مخلوط: price_usd میانگین وزنیِ فی دلاریِ مبدأها و usd_rate نرخ معادل (price ÷ price_usd) است.
ALTER TABLE purchases
  ADD COLUMN usd_rate  DECIMAL(14,2) NULL,
  ADD COLUMN price_usd DECIMAL(14,4) NULL;

-- قیمت دلار هنگام فروش (تومان)
ALTER TABLE sales ADD COLUMN usd_rate DECIMAL(14,2) NULL;
