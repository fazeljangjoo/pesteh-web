-- مرحلهٔ ۱۳ (v11) — شمارش‌ها (فقط SELECT؛ هیچ تغییری نمی‌دهد).
-- انتظار قبل از P2 و P3: missing_hash = 0 (هر توکن متن‌ساده ردیف هش دارد).

SELECT COUNT(*) AS plain_tokens
FROM customers WHERE api_token IS NOT NULL AND api_token <> '';

SELECT COUNT(*) AS missing_hash
FROM customers c
WHERE c.api_token IS NOT NULL AND c.api_token <> ''
  AND NOT EXISTS (SELECT 1 FROM customer_tokens t WHERE t.token_hash = SHA2(c.api_token, 256));

SELECT COUNT(*) AS hashed_rows FROM customer_tokens;

SELECT COUNT(*) AS customers_with_sessions FROM (SELECT DISTINCT customer_id FROM customer_tokens) x;
