-- v68 (کارگاه مرحلهٔ ۵-الف: موجودی پستهٔ خرجی) — مستندات دستی. اجرای واقعی: pb_mig_68_feed_stock (PHP، idempotent).
-- جدای از موجودی انبار اصلی؛ بدون هیچ ستون قیمتی. مجوز مشاهده workshop.feed (مالک + «اپراتور کارگاه» + هر نقش دارای stock.view)؛
-- تعدیل دستی/حذف = workshop.manage. منابع خودکار: proc_orders (direction='paid') و crack_runs (جدول جدید ندارند).
CREATE TABLE IF NOT EXISTS feed_adjust (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        uid CHAR(36) NULL,
        adj_date DATE NOT NULL,
        kind VARCHAR(3) NOT NULL DEFAULT 'in',
        owner VARCHAR(150) NULL,
        workshop VARCHAR(150) NULL,
        receipt_no VARCHAR(60) NULL,
        kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        reason VARCHAR(300) NULL,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_by_name VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_feedadj_uid (customer_id, uid),
        KEY idx_feedadj_cust (customer_id, adj_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
