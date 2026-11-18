-- v70 (CP1: استخر هزینهٔ خط شکست) — مستندات دستی. اجرای واقعی: pb_mig_70_crack_pool (PHP، idempotent) که pb_cr_ensure + pb_cp_backfill را اجرا می‌کند.
-- مبلغ استخر = source_cost_rial + extra_cost_rial (ریال صحیح). consume_mode: lots_only | full. input_g = گرم مصرف‌شده از لات مبدأ.
-- هیچ ستون قیمتی روی crack_runs/crack_run_lots اضافه نشد؛ اپراتور کارگاه هرگز مبلغ نمی‌بیند.
CREATE TABLE IF NOT EXISTS crack_pools (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        run_id INT UNSIGNED NOT NULL,
        source_lot_id INT UNSIGNED NOT NULL,
        consume_mode VARCHAR(10) NOT NULL DEFAULT 'lots_only',
        input_g INT UNSIGNED NOT NULL DEFAULT 0,
        source_cost_rial BIGINT NOT NULL DEFAULT 0,
        extra_cost_rial BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        closed_at DATETIME NULL,
        closed_by INT UNSIGNED NULL,
        backfilled TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cpool_run (customer_id, run_id),
        KEY idx_cpool_src (customer_id, source_lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- بک‌فیل (PHP): برای هر (customer_id, run_id) دارای crack_run_lots و بدون استخر: lots_only، input_g = جمع kg لات‌ها،
-- source_cost_rial = pb_pl_lot_value(لات مبدأ، input_g)، backfilled=1.
