-- مهاجرت نسخه ۴: گونیِ باقی‌ماندهٔ هر خرید (مستقل از وزن باقی‌مانده)
-- توجه: اجرای دستی اختیاری است؛ اگر اجرا نکنید app.php در اولین درخواست، همین کار را خودکار انجام می‌دهد.
-- اگر ستون‌ها از قبل وجود داشته باشند، ALTER خطا می‌دهد که بی‌خطر است (یعنی قبلاً اجرا شده؛ UPDATE را دوباره اجرا نکنید).

ALTER TABLE purchases
  ADD COLUMN small_sacks_remaining DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER large_sack_count,
  ADD COLUMN large_sacks_remaining DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER small_sacks_remaining;

-- مقدار اولیه برای داده‌های قدیمی = گونی خرید منهای گونی‌های ثبت‌شدهٔ فروش/مخلوط
-- (اگر فروش/مخلوطی گونی ثبت نکرده، سهم آن به‌نسبت وزن برآورد می‌شود؛ همان منطق قبلیِ صفحهٔ انبار)
UPDATE purchases p
LEFT JOIN (
  SELECT purchase_id AS pid,
         SUM(CASE WHEN small_sacks IS NULL AND large_sacks IS NULL THEN weight ELSE 0 END) AS unk_w,
         SUM(COALESCE(small_sacks, 0)) AS ks,
         SUM(COALESCE(large_sacks, 0)) AS kl
  FROM (
    SELECT purchase_id, weight, small_sacks, large_sacks FROM sale_items
    UNION ALL
    SELECT source_purchase_id AS purchase_id, weight, small_sacks, large_sacks FROM mix_sources
  ) u GROUP BY purchase_id
) s ON s.pid = p.id
SET p.small_sacks_remaining = GREATEST(0, ROUND(p.small_sack_count - COALESCE(s.ks, 0)
        - p.small_sack_count * IF(p.net_weight > 0, COALESCE(s.unk_w, 0) / p.net_weight, 0))),
    p.large_sacks_remaining = GREATEST(0, ROUND(p.large_sack_count - COALESCE(s.kl, 0)
        - p.large_sack_count * IF(p.net_weight > 0, COALESCE(s.unk_w, 0) / p.net_weight, 0)));
