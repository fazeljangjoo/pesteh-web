<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require_once __DIR__ . '/../api/auth_token.php'; // STAGE14
require_once __DIR__ . '/../api/notify_sms.php'; // SMS
require_once __DIR__ . '/../api/auth_limits.php'; // LIMITS
require_once __DIR__ . '/../api/features.php'; // S00: edition model
require_once __DIR__ . '/../api/plan_upgrade.php'; // S20.T20.2
require_once __DIR__ . '/../api/accounts.php'; // fx_diff role backfill for gold+fx
require_once __DIR__ . '/../api/entities.php'; // S00: entity registry hash (pb_migrate)
require_once __DIR__ . '/../api/migrations.php'; // S00: ensures customers.tier exists
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
check_csrf();

$pdo = db();
$action = $_POST['action'] ?? '';
$msg = '';
$redirect = 'index.php';

// ستون has_password (ورود با رمز عبور) در صورت نبودن ساخته می‌شود
try { ensure_password_column($pdo); } catch (Throwable $e) { error_log('actions.php ensure_password_column: ' . $e->getMessage()); }
try { ensure_plan_column($pdo); } catch (Throwable $e) { error_log('actions.php ensure_plan_column: ' . $e->getMessage()); }
try { pb_migrate($pdo); } catch (Throwable $e) { error_log('actions.php pb_migrate: ' . $e->getMessage()); }

try {
    switch ($action) {

        case 'add_customer': {
            $phone = normalize_phone((string)($_POST['phone'] ?? ''));
            $name  = clean_person_name((string)($_POST['name'] ?? ''));
            $days  = min(3650, max(1, (int)($_POST['days'] ?? TRIAL_DAYS)));
            $pw    = (string)($_POST['password'] ?? ''); // اختیاری: برای ورود بدون پیامک
            $plan  = (($_POST['plan'] ?? '') === PLAN_FX) ? PLAN_FX : PLAN_RIAL;
            if ($name !== '' && (mb_strlen($name) < 2 || mb_strlen($name) > 60)) {
                $msg = 'نام باید بین ۲ تا ۶۰ حرف باشد.';
                break;
            }
            if ($pw !== '') {
                $pwErr = password_policy_error($pw);
                if ($pwErr !== null) { $msg = $pwErr . '.'; break; }
            }

            if (strlen($phone) !== 12) {
                $msg = 'شماره موبایل نامعتبر است.';
                break;
            }

            $exists = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
            $exists->execute([$phone]);
            if ($exists->fetch()) {
                $msg = 'این شماره قبلاً در سیستم ثبت شده. برای تمدید از دکمه‌های تمدید همان ردیف استفاده کنید.';
                break;
            }

            // بدون رمز: ورود فقط با پیامک (password_hash یک مقدار تصادفی بی‌استفاده می‌گیرد)
            $hash = password_hash($pw !== '' ? $pw : bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $expiry = (new DateTime())->modify("+{$days} days")->format('Y-m-d');

            $stmt = $pdo->prepare(
                'INSERT INTO customers (phone, name, password_hash, has_password, active, expiry_date, plan)
                 VALUES (?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->execute([$phone, $name ?: null, $hash, $pw !== '' ? 1 : 0, $expiry, $plan]);

            $msg = 'مشتری ثبت شد. شماره: ' . display_phone($phone) . ' — اشتراک تا ' . $expiry . '.';
            if ($pw !== '') {
                $msg .= ' رمز عبور: ' . $pw . ' — این رمز فقط همین یک بار نمایش داده می‌شود؛ آن را به مشتری اطلاع دهید.'
                      . ' مشتری می‌تواند با «ورود با رمز عبور» یا با کد پیامکی وارد شود.';
            } else {
                $msg .= ' مشتری با همین شماره و کد پیامکی وارد می‌شود.';
            }
            break;
        }

        case 'set_plan': {
            // ارتقا/بازگشت پلن؛ داده‌های مشتری دست‌نخورده می‌ماند (اطلاعات دلاری فقط در پلن ارزی دیده می‌شود)
            $id = (int)($_POST['id'] ?? 0);
            $newPlan = (($_POST['plan'] ?? '') === PLAN_FX) ? PLAN_FX : PLAN_RIAL;
            $stmt = $pdo->prepare('UPDATE customers SET plan = ? WHERE id = ?');
            $stmt->execute([$newPlan, $id]);
            if ($newPlan === PLAN_FX) pb_plan_upgrade_fx($pdo, $id); // S20.T20.2 additive fx setup (idempotent)
            $msg = $newPlan === PLAN_FX
                ? 'پلن مشتری به «ارزی» ارتقا یافت. با باز شدن بعدی برنامه (یا همگام‌سازی) فعال می‌شود.'
                : 'پلن مشتری به «ریالی» برگشت.';
            break;
        }

        case 'set_edition': {
            // S00.T00.5: سطح (سبز/طلایی) و پلن (ریالی/ارزی) مشتری. فقط پرچم است؛ مهاجرت دادهٔ ارتقا در S20 می‌آید.
            $id = (int)($_POST['id'] ?? 0);
            $newTier = (($_POST['tier'] ?? '') === PB_TIER_GOLD) ? PB_TIER_GOLD : PB_TIER_GREEN;
            $newPlan = (($_POST['plan'] ?? '') === 'fx') ? 'fx' : 'rial';
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
            $stmt->execute([$id]);
            $cur = $stmt->fetch();
            if (!$cur) { $msg = 'مشتری یافت نشد.'; break; }
            $oldTier = pb_tier_of($cur);
            $oldPlan = pb_plan_of($cur);
            if ($oldTier === $newTier && $oldPlan === $newPlan) { $msg = 'نسخهٔ مشتری تغییری نکرد.'; break; }
            $pdo->prepare('UPDATE customers SET tier = ?, plan = ? WHERE id = ?')->execute([$newTier, $newPlan, $id]);
            admin_audit($pdo, $id, 'edition', $oldTier . '_' . $oldPlan, $newTier . '_' . $newPlan);
            if ($newPlan === 'fx') pb_plan_upgrade_fx($pdo, $id); // S20.T20.2
            $msg = 'نسخهٔ مشتری شد «' . edition_name($newTier, $newPlan) . '». با باز شدن بعدی برنامه (یا همگام‌سازی) فعال می‌شود.';
            break;
        }

        case 'renew': {
            $id = (int)($_POST['id'] ?? 0);
            $days = (int)($_POST['days'] ?? 0);
            $customDate = trim((string)($_POST['custom_date'] ?? ''));

            $stmt = $pdo->prepare('SELECT expiry_date FROM customers WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) { $msg = 'مشتری یافت نشد.'; break; }

            if ($customDate !== '') {
                // یک تاریخ نامعتبر این‌جا یعنی مشتری برای همیشه «منقضی» بماند (app.php آن را قابل‌مقایسه نمی‌بیند)
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $customDate, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                    $msg = 'تاریخ وارد شده نامعتبر است (فرمت درست: 1404-07-10).';
                    break;
                }
                $newExpiry = $customDate;
            } else {
                if ($days < 1 || $days > 3650) { $msg = 'تعداد روز نامعتبر است.'; break; }
                // از امروز یا از تاریخ انقضای فعلی (هرکدام دیرتر بود) تمدید می‌کند
                $base = max(new DateTime($row['expiry_date']), new DateTime());
                $newExpiry = $base->modify("+{$days} days")->format('Y-m-d');
            }

            $upd = $pdo->prepare('UPDATE customers SET expiry_date = ?, active = 1 WHERE id = ?');
            $upd->execute([$newExpiry, $id]);
            $msg = 'اشتراک تمدید شد. تاریخ انقضای جدید: ' . $newExpiry;
            break;
        }

        case 'set_password': {
            $id = (int)($_POST['id'] ?? 0);
            $pw = (string)($_POST['password'] ?? '');
            $pwErr = password_policy_error($pw);
            if ($pwErr !== null) { $msg = $pwErr . '.'; break; }
            $stmt = $pdo->prepare('SELECT phone FROM customers WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) { $msg = 'مشتری یافت نشد.'; break; }
            // توکن پاک می‌شود: همهٔ دستگاه‌هایی که وارد بوده‌اند خارج می‌شوند و باید دوباره وارد شوند
            $pdo->prepare('UPDATE customers SET password_hash = ?, has_password = 1 WHERE id = ?')
                ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
            tok_revoke_all($pdo, (int)$id); // STAGE14: ردیف‌های نشست + ستون قدیمی
            pb_notify_password_changed_by_id($pdo, (int)$id, 'پشتیبانی'); // SMS
            $msg = 'رمز ' . display_phone((string)$row['phone']) . ' تعیین شد: ' . $pw
                 . ' — فقط همین یک بار نمایش داده می‌شود. همهٔ دستگاه‌های قبلیِ این مشتری خارج شدند. '
                 . 'لطفاً رمز جدید را به خود مشتری اطلاع دهید.';
            break;
        }

        case 'set_name': {
            $id = (int)($_POST['id'] ?? 0);
            $name = clean_person_name((string)($_POST['name'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 60) { $msg = 'نام باید بین ۲ تا ۶۰ حرف باشد.'; break; }
            $stmt = $pdo->prepare('UPDATE customers SET name = ? WHERE id = ?');
            $stmt->execute([$name, $id]);
            $msg = 'نام ذخیره شد.';
            break;
        }

        case 'unblock': {
            // LIMITS: رفع مسدودی ورود/تغییر رمز و شمارنده‌ها (فقط از پنل ادمین)
            $id = (int)($_POST['id'] ?? 0);
            pb_unblock_all($pdo, $id);
            $msg = 'مسدودی این مشتری برداشته شد (ورود و تغییر رمز).';
            break;
        }

        case 'toggle_active': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE customers SET active = NOT active WHERE id = ?');
            $stmt->execute([$id]);
            $msg = 'وضعیت فعال/غیرفعال تغییر کرد.';
            break;
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT phone FROM customers WHERE id = ?');
            $st->execute([$id]);
            $cust = $st->fetch();
            if (!$cust) { $msg = 'مشتری یافت نشد.'; break; }

            // همهٔ جدول‌های دادهٔ مشتری (ترتیب: فرزند ← والد). همه ستون customer_id دارند.
            $dataTables = [
                'receipt_cheque_inst', 'receipt_credit_inst', 'receipt_lump_tx', 'receipts', 'sale_items', 'sales',
                'cheque_inst', 'credit_inst', 'lump_tx', 'payments', 'mix_sources', 'purchases', 'types', 'categories',
                'loan_dues', 'loan_entries', 'parties',
            ];
            // جدول‌های قرض/طرف‌حساب ممکن است هنوز ساخته نشده باشند (قبل از اولین استفاده)
            $pdo->exec('CREATE TABLE IF NOT EXISTS parties (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL, UNIQUE KEY uq_party (customer_id, name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $pdo->exec('CREATE TABLE IF NOT EXISTS loan_dues (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, loan_uid VARCHAR(40) NOT NULL, seq TINYINT UNSIGNED NOT NULL, due_date DATE NOT NULL, amount DECIMAL(14,2) NOT NULL, note VARCHAR(500) NULL, paid TINYINT(1) NOT NULL DEFAULT 0, UNIQUE KEY uq_loan_due (customer_id, loan_uid, seq), KEY idx_due_customer (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $pdo->exec('CREATE TABLE IF NOT EXISTS loan_entries (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, uid VARCHAR(40) NOT NULL, party VARCHAR(150) NOT NULL, kind VARCHAR(12) NOT NULL, amount DECIMAL(14,2) NOT NULL, date DATE NOT NULL, note VARCHAR(500) NULL, created_at DATETIME NULL, UNIQUE KEY uq_loan_uid (customer_id, uid), KEY idx_loan_customer (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            // STAGE10: بقیهٔ جدول‌هایی که ستون customer_id دارند (customer_tokens, vouchers, audit_log, staff_users, stock_moves, ...)
            // از information_schema پیدا و در همان تراکنش پاک می‌شوند. طبق تصمیم مالک (مشتری‌های فعلی تستی‌اند)
            // سوابق سفارش/اشتراک/تخفیف/قرارداد/ممیزی هم پاک می‌شوند؛ اگر بعداً لازم شد چیزی بماند، نامش را در $keepTables بگذارید.
            $keepTables = [];
            $extraTables = [];
            try {
                $q = $pdo->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'customer_id'");
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $tn) {
                    $tn = (string)$tn;
                    if (!preg_match('/^[A-Za-z0-9_]+$/', $tn)) continue;
                    if ($tn === 'customers' || in_array($tn, $dataTables, true) || in_array($tn, $keepTables, true)) continue;
                    $extraTables[] = $tn;
                }
            } catch (Throwable $e) {
                error_log('[admin delete] information_schema: ' . $e->getMessage());
                $msg = 'فهرست جدول‌های داده خوانده نشد؛ برای جلوگیری از حذف ناقص، هیچ‌چیز حذف نشد.';
                break;
            }
            // همه‌چیز در یک تراکنش: یا کل دادهٔ مشتری و خودِ مشتری حذف می‌شود، یا هیچ‌چیز
            $pdo->beginTransaction();
            try {
                foreach ($dataTables as $t) {
                    $pdo->prepare("DELETE FROM `$t` WHERE customer_id = ?")->execute([$id]);
                }
                foreach ($extraTables as $t) {
                    $pdo->prepare("DELETE FROM `$t` WHERE customer_id = ?")->execute([$id]);
                }
                $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            // پاک‌سازی بقیهٔ آثار مشتری (بهترین تلاش؛ این جدول‌ها ممکن است هنوز ساخته نشده باشند)
            try { $pdo->prepare('DELETE FROM idempotency_keys WHERE customer_id = ?')->execute([$id]); } catch (Throwable $e) {}
            try { $pdo->prepare('DELETE FROM customer_blocks WHERE customer_id = ?')->execute([$id]); $pdo->prepare('DELETE FROM customer_auth_events WHERE customer_id = ?')->execute([$id]); } catch (Throwable $e) {} // LIMITS
            try { $pdo->prepare('DELETE FROM announcement_reads WHERE customer_id = ?')->execute([$id]); $pdo->prepare('DELETE FROM announcements WHERE customer_id = ?')->execute([$id]); } catch (Throwable $e) {}
            try { $pdo->prepare('DELETE FROM otp_codes WHERE phone = ?')->execute([(string)$cust['phone']]); } catch (Throwable $e) {}

            // فایل‌های پشتیبان SQLite این مشتری (backups/<id>/)
            $bdir = dirname(__DIR__, 3) . '/private/backups/' . $id;
            if ($id > 0 && is_dir($bdir)) {
                foreach (glob($bdir . '/*') ?: [] as $f) {
                    if (is_file($f)) @unlink($f);
                }
                @rmdir($bdir);
            }

            $msg = 'مشتری و داده‌هایش (خرید، فروش، پرداخت‌ها، حساب‌ها، سندها، توکن‌ها، کارکنان و پشتیبان‌ها) حذف شد.';
            break;
        }

        // ---- پیام‌ها (نمایش در تب خانهٔ برنامه‌ها) ----
        case 'send_message': {
            $redirect = 'index.php?tab=messages';
            ensure_message_tables($pdo);
            $target = (string)($_POST['target'] ?? 'all');
            $title  = trim((string)($_POST['title'] ?? ''));
            $body   = trim((string)($_POST['body'] ?? ''));
            $link   = trim((string)($_POST['link_url'] ?? ''));
            $label  = trim((string)($_POST['link_label'] ?? ''));
            $kind   = in_array(($_POST['kind'] ?? ''), ['info', 'update', 'greeting'], true) ? (string)$_POST['kind'] : 'info';
            $days   = (int)($_POST['expires_days'] ?? 0);
            if (mb_strlen($title) < 2 || mb_strlen($title) > 120) { $msg = 'عنوان پیام باید بین ۲ تا ۱۲۰ حرف باشد.'; break; }
            if (mb_strlen($body) > 2000) { $msg = 'متن پیام حداکثر ۲۰۰۰ حرف باشد.'; break; }
            if ($link !== '') {
                if (mb_strlen($link) > 500 || !preg_match('#^https?://[^\s]+$#i', $link)) { $msg = 'لینک باید با http:// یا https:// شروع شود.'; break; }
                if (mb_strlen($label) > 60) { $msg = 'متن دکمهٔ لینک حداکثر ۶۰ حرف باشد.'; break; }
            } else {
                $label = '';
            }
            $customerId = null;
            if ($target === 'customer') {
                $phone = normalize_phone((string)($_POST['phone'] ?? ''));
                $st = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
                $st->execute([$phone]);
                $row = $st->fetch();
                if (!$row) { $msg = 'مشتری‌ای با این شماره پیدا نشد.'; break; }
                $customerId = (int)$row['id'];
            }
            $expires = $days > 0 ? (new DateTime())->modify('+' . min(365, $days) . ' days')->format('Y-m-d H:i:s') : null;
            $pdo->prepare('INSERT INTO announcements (customer_id, title, body, link_url, link_label, kind, created_at, expires_at, active) VALUES (?,?,?,?,?,?,NOW(),?,1)')
                ->execute([$customerId, $title, $body, $link !== '' ? $link : null, $label !== '' ? $label : null, $kind, $expires]);
            $msg = $customerId === null ? 'پیام برای همهٔ مشتری‌ها ارسال شد (در تب خانه نمایش داده می‌شود).' : 'پیام برای این مشتری ارسال شد.';
            break;
        }

        case 'toggle_message': {
            $redirect = 'index.php?tab=messages';
            ensure_message_tables($pdo);
            $pdo->prepare('UPDATE announcements SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'وضعیت پیام تغییر کرد.';
            break;
        }

        case 'delete_message': {
            $redirect = 'index.php?tab=messages';
            ensure_message_tables($pdo);
            $mid = (int)($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM announcement_reads WHERE announcement_id = ?')->execute([$mid]);
            $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$mid]);
            $msg = 'پیام حذف شد.';
            break;
        }

        default:
            $msg = 'عملیات نامعتبر.';
    }
} catch (Throwable $e) {
    error_log('actions.php [' . $action . ']: ' . $e->getMessage());
    $msg = 'خطایی رخ داد؛ دوباره امتحان کنید. اگر ادامه داشت، لاگ سرور را بررسی کنید.';
}

$_SESSION['flash'] = $msg;
header('Location: ' . $redirect);
exit;
