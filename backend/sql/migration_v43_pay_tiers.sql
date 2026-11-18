-- v43 (S22.T22.1) mirror of pb_mig_43_pay_tiers
-- Runtime step is authoritative (checks existence first). Tables pay_orders / discount_codes are created in private/config.php.
ALTER TABLE pay_orders ADD COLUMN IF NOT EXISTS tier VARCHAR(8) NOT NULL DEFAULT 'green';
ALTER TABLE discount_codes ADD COLUMN IF NOT EXISTS tiers VARCHAR(40) NULL DEFAULT NULL;
