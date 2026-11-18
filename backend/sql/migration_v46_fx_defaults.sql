-- v46 (S25.T25.1) mirror of pb_mig_46_fx_defaults: data seed only (no DDL).
-- Seeds the default currencies (see PB_FX_DEFAULTS in api/fx_currencies.php) for every customer on the fx plan.
-- Idempotent: INSERT IGNORE on uq_ccy (customer_id, code). Fresh installs get them lazily on first fx.* call.
INSERT IGNORE INTO currencies (customer_id, code, name, symbol, decimals, is_base, active, created_at)
SELECT c.id, d.code, d.name, d.symbol, d.decimals, d.is_base, 1, NOW()
FROM customers c
JOIN (
    SELECT 'IRT' AS code, 'ریال (پایه)' AS name, 'ریال' AS symbol, 0 AS decimals, 1 AS is_base
    UNION ALL SELECT 'USD', 'دلار آمریکا', '$', 2, 0
    UNION ALL SELECT 'EUR', 'یورو', '€', 2, 0
    UNION ALL SELECT 'AED', 'درهم امارات', 'د.إ', 2, 0
    UNION ALL SELECT 'GBP', 'پوند انگلیس', '£', 2, 0
    UNION ALL SELECT 'IQD', 'دینار عراق', 'ع.د', 0, 0
    UNION ALL SELECT 'TRY', 'لیر ترکیه', '₺', 2, 0
    UNION ALL SELECT 'CNY', 'یوان چین', '¥', 2, 0
) d
WHERE c.plan = 'fx';
