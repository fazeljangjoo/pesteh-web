-- مهاجرت نسخه ۲: افزودن ستون عکس رسید خرید
-- فقط لازم است اگر قبلاً schema_app.sql (نسخه اول) را اجرا کرده‌اید.
-- این فایل را یک‌بار از تب Import در phpMyAdmin اجرا کنید.
-- اگر ستون از قبل وجود داشته باشد، این دستور خطا می‌دهد که بی‌خطر است (یعنی قبلاً اجرا شده).

ALTER TABLE purchases ADD COLUMN photo MEDIUMTEXT NULL AFTER custom_specs;
