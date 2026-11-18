<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require __DIR__ . '/pay_lib.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: pay.php'); exit; }
check_csrf();

$pdo = db();
$action = (string)($_POST['action'] ?? '');
$tabIn = (string)($_POST['tab'] ?? 'orders');
$tab = in_array($tabIn, ['orders', 'all', 'codes', 'settings', 'contract', 'upgrades'], true) ? $tabIn : 'orders';
$redirect = 'pay.php?tab=' . $tab;
$msg = '';

try {
    switch ($action) {

        case 'approve_order': {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM pay_orders WHERE id = ?');
            $st->execute([$id]);
            $o = $st->fetch();
            if (!$o) { $msg = 'سفارش پیدا نشد.'; break; }
            if ($o['status'] === 'paid') { $msg = 'این سفارش قبلاً تأیید شده بود.'; break; }
            if ($o['status'] !== 'awaiting_review') { $msg = 'این سفارش در وضعیت «در انتظار بررسی» نیست.'; break; }
            $method = in_array($o['method'], ['card', 'sheba', 'gateway'], true) ? (string)$o['method'] : 'card';
            $r = pay_activate_order($pdo, $id, $method);
            if (empty($r['ok'])) {
                $errs = [
                    'order_closed' => 'این سفارش بسته شده است.',
                    'code_exhausted' => 'ظرفیت کد تخفیف این سفارش تمام شده است. سفارش را رد کنید (و بازگشت وجه) یا ظرفیت کد را از بخش کدها افزایش دهید و دوباره تأیید کنید.',
                    'customer_not_found' => 'مشتری این سفارش پیدا نشد.',
                    'fx_to_rial_blocked' => 'مشتری هم‌اکنون پلن ارزی فعال دارد؛ سفارش ریالی قابل فعال‌سازی نیست. آن را رد کنید و دلیل را بنویسید.',
                ];
                $e = (string)($r['error'] ?? '');
                $msg = $errs[$e] ?? ('فعال‌سازی انجام نشد (' . $e . '). لاگ سرور را ببینید.');
                break;
            }
            if (empty($r['already'])) {
                $exp = pay_jdate((string)($r['new_expiry'] ?? ''));
                pay_notify($pdo, (int)$o['customer_id'], 'پرداخت شما تأیید شد ✅',
                    'اشتراک «' . pay_plan_label((string)($r['new_plan'] ?? $o['plan'])) . '» شما تا تاریخ ' . $exp . ' فعال است. شمارهٔ سفارش: ' . pay_fa((string)$o['public_id']) . '. ممنون از اعتماد شما.');
                $msg = 'سفارش تأیید و اشتراک فعال شد (تا ' . $exp . '). پیام برای مشتری ارسال شد.';
            } else {
                $msg = 'این سفارش قبلاً فعال شده بود.';
            }
            break;
        }

        case 'reject_order': {
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) { $msg = 'دلیل رد باید بین ۳ تا ۲۵۵ حرف باشد.'; break; }
            $st = $pdo->prepare('SELECT customer_id, public_id FROM pay_orders WHERE id = ?');
            $st->execute([$id]);
            $o = $st->fetch();
            if (!$o) { $msg = 'سفارش پیدا نشد.'; break; }
            $u = $pdo->prepare("UPDATE pay_orders SET status = 'rejected', reject_reason = ? WHERE id = ? AND status = 'awaiting_review'");
            $u->execute([$reason, $id]);
            if ($u->rowCount() < 1) { $msg = 'این سفارش دیگر در انتظار بررسی نیست (شاید قبلاً بررسی شده).'; break; }
            pay_notify($pdo, (int)$o['customer_id'], 'پرداخت شما تأیید نشد',
                'سفارش ' . pay_fa((string)$o['public_id']) . ' تأیید نشد. دلیل: ' . $reason . ' — اگر پرداخت انجام داده‌اید، با پشتیبانی تماس بگیرید.');
            $msg = 'سفارش رد شد و دلیل برای مشتری ارسال شد.';
            break;
        }

        case 'save_settings': {
            $redirect = 'pay.php?tab=settings';
            $vat = (float)pay_en(str_replace('٫', '.', trim((string)($_POST['vat_percent'] ?? '0'))));
            if ($vat < 0 || $vat > 100) { $msg = 'درصد مالیات باید بین ۰ تا ۱۰۰ باشد.'; break; }
            pay_setting_set($pdo, 'vat_percent', rtrim(rtrim(number_format($vat, 2, '.', ''), '0'), '.') ?: '0');
            foreach (['discount_enabled', 'gateway_enabled', 'card_enabled', 'sheba_enabled'] as $k) {
                pay_setting_set($pdo, $k, !empty($_POST[$k]) ? '1' : '0');
            }
            $msg = 'تنظیمات ذخیره شد.';
            break;
        }

        case 'add_bank':
        case 'update_bank': {
            $redirect = 'pay.php?tab=settings';
            $bank = trim((string)($_POST['bank_name'] ?? ''));
            $owner = trim((string)($_POST['owner_name'] ?? ''));
            $card = pay_clean_card((string)($_POST['card_number'] ?? ''));
            $sheba = pay_clean_sheba((string)($_POST['sheba'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            if (mb_strlen($bank) < 2 || mb_strlen($bank) > 60) { $msg = 'نام بانک را وارد کنید.'; break; }
            if (mb_strlen($owner) < 2 || mb_strlen($owner) > 100) { $msg = 'نام صاحب حساب را وارد کنید.'; break; }
            if ($card === '' && $sheba === '') { $msg = 'حداقل شمارهٔ کارت یا شبا را وارد کنید.'; break; }
            if ($card !== '' && !preg_match('/^\d{16}$/', $card)) { $msg = 'شمارهٔ کارت باید ۱۶ رقم باشد.'; break; }
            if ($sheba !== '' && !preg_match('/^IR\d{24}$/', $sheba)) { $msg = 'شبا باید به شکل IR و ۲۴ رقم باشد.'; break; }
            if (mb_strlen($note) > 255) { $msg = 'توضیح حداکثر ۲۵۵ حرف باشد.'; break; }
            if ($action === 'add_bank') {
                $pdo->prepare('INSERT INTO bank_accounts (bank_name, card_number, sheba, owner_name, note, active, sort_order) VALUES (?,?,?,?,?,1,?)')
                    ->execute([$bank, $card ?: null, $sheba ?: null, $owner, $note ?: null, $sort]);
                $msg = 'حساب بانکی اضافه شد.';
            } else {
                $pdo->prepare('UPDATE bank_accounts SET bank_name=?, card_number=?, sheba=?, owner_name=?, note=?, sort_order=? WHERE id=?')
                    ->execute([$bank, $card ?: null, $sheba ?: null, $owner, $note ?: null, $sort, (int)($_POST['id'] ?? 0)]);
                $msg = 'حساب بانکی ذخیره شد.';
            }
            break;
        }

        case 'toggle_bank': {
            $redirect = 'pay.php?tab=settings';
            $pdo->prepare('UPDATE bank_accounts SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'وضعیت حساب تغییر کرد.';
            break;
        }

        case 'delete_bank': {
            $redirect = 'pay.php?tab=settings';
            $pdo->prepare('DELETE FROM bank_accounts WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'حساب بانکی حذف شد.';
            break;
        }

        case 'add_code': {
            $redirect = 'pay.php?tab=codes';
            $code = strtoupper(trim(pay_en((string)($_POST['code'] ?? ''))));
            $type = (string)($_POST['type'] ?? '');
            $value = (int)pay_en((string)($_POST['value'] ?? '0'));
            if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) { $msg = 'کد فقط حروف انگلیسی، عدد، - و _ باشد (۳ تا ۴۰ نویسه).'; break; }
            if (!in_array($type, ['percent', 'fixed', 'free_upgrade'], true)) { $msg = 'نوع کد نامعتبر است.'; break; }
            if ($type === 'percent' && ($value < 1 || $value > 100)) { $msg = 'درصد باید بین ۱ تا ۱۰۰ باشد.'; break; }
            if ($type === 'fixed' && $value < 1) { $msg = 'مبلغ تخفیف (' . pay_unit_label() . ') را وارد کنید.'; break; }
            if ($type === 'free_upgrade') $value = 0;
            $csv = function (string $key, array $allowed): ?string {
                $v = array_values(array_intersect($allowed, array_map('strval', (array)($_POST[$key] ?? []))));
                return $v ? implode(',', $v) : null;
            };
            $plans = $csv('plans', ['rial', 'fx']);
            $months = $csv('months', ['3', '6', '12']);
            $otypes = $csv('order_types', ['renew', 'upgrade', 'upgrade_renew']);
            $days = (int)pay_en((string)($_POST['expires_days'] ?? '0'));
            $expires = $days > 0 ? (new DateTime('today'))->modify('+' . min(3650, $days) . ' days')->format('Y-m-d 23:59:59') : null;
            $maxTotal = (int)pay_en((string)($_POST['max_total'] ?? '0'));
            $maxUser = (int)pay_en((string)($_POST['max_per_user'] ?? '0'));
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
            try {
                $pdo->prepare('INSERT INTO discount_codes (code, type, value, plans, months, order_types, expires_at, max_total, max_per_user, active, note) VALUES (?,?,?,?,?,?,?,?,?,1,?)')
                    ->execute([$code, $type, $value, $plans, $months, $otypes, $expires, $maxTotal > 0 ? $maxTotal : null, $maxUser > 0 ? $maxUser : null, $note ?: null]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') { $msg = 'این کد قبلاً ساخته شده است.'; break; }
                throw $e;
            }
            $msg = 'کد تخفیف «' . $code . '» ساخته شد.';
            break;
        }

        case 'toggle_code': {
            $redirect = 'pay.php?tab=codes';
            $pdo->prepare('UPDATE discount_codes SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'وضعیت کد تغییر کرد.';
            break;
        }

        case 'delete_code': {
            $redirect = 'pay.php?tab=codes';
            $id = (int)($_POST['id'] ?? 0);
            $n = $pdo->prepare('SELECT (SELECT COUNT(*) FROM discount_redemptions WHERE code_id = ?) + (SELECT COUNT(*) FROM pay_orders WHERE code_id = ?)');
            $n->execute([$id, $id]);
            if ((int)$n->fetchColumn() > 0) { $msg = 'این کد در سفارش‌ها استفاده شده و حذف نمی‌شود؛ فقط غیرفعالش کنید.'; break; }
            $pdo->prepare('DELETE FROM discount_codes WHERE id = ?')->execute([$id]);
            $msg = 'کد حذف شد.';
            break;
        }

        case 'save_contract': {   // نسخهٔ جدید (پیش‌نویس یا منتشرشده)
            $redirect = 'pay.php?tab=contract';
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $publish = (($_POST['mode'] ?? '') === 'publish') ? 1 : 0;
            if (mb_strlen($title) < 3 || mb_strlen($title) > 120) { $msg = 'عنوان قرارداد باید بین ۳ تا ۱۲۰ حرف باشد.'; break; }
            if (mb_strlen($body) < 50) { $msg = 'متن قرارداد خیلی کوتاه است.'; break; }
            if (mb_strlen($body) > 60000) { $msg = 'متن قرارداد خیلی بلند است.'; break; }
            $ver = (int)$pdo->query('SELECT COALESCE(MAX(version), 0) + 1 FROM contract_versions')->fetchColumn();
            $pdo->prepare('INSERT INTO contract_versions (version, title, body, published) VALUES (?,?,?,?)')->execute([$ver, $title, $body, $publish]);
            $msg = $publish
                ? 'نسخهٔ ' . pay_fa((string)$ver) . ' منتشر شد؛ همهٔ کاربران در ورود بعدی باید آن را تأیید کنند.'
                : 'نسخهٔ ' . pay_fa((string)$ver) . ' به‌عنوان پیش‌نویس ذخیره شد (هنوز منتشر نشده).';
            break;
        }

        case 'update_contract': {  // فقط پیش‌نویس قابل ویرایش است
            $redirect = 'pay.php?tab=contract';
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            if (mb_strlen($title) < 3 || mb_strlen($title) > 120 || mb_strlen($body) < 50 || mb_strlen($body) > 60000) { $msg = 'عنوان یا متن نامعتبر است.'; break; }
            $u = $pdo->prepare('UPDATE contract_versions SET title = ?, body = ? WHERE id = ? AND published = 0');
            $u->execute([$title, $body, (int)($_POST['id'] ?? 0)]);
            $msg = $u->rowCount() > 0 ? 'پیش‌نویس ذخیره شد.' : 'تغییری ذخیره نشد (نسخهٔ منتشرشده قابل ویرایش نیست).';
            break;
        }

        case 'publish_contract': {
            $redirect = 'pay.php?tab=contract';
            $u = $pdo->prepare('UPDATE contract_versions SET published = 1 WHERE id = ? AND published = 0');
            $u->execute([(int)($_POST['id'] ?? 0)]);
            $msg = $u->rowCount() > 0 ? 'نسخه منتشر شد؛ کاربران در ورود بعدی باید آن را تأیید کنند.' : 'این نسخه قبلاً منتشر شده است.';
            break;
        }

        case 'delete_contract': {   // فقط پیش‌نویس
            $redirect = 'pay.php?tab=contract';
            $pdo->prepare('DELETE FROM contract_versions WHERE id = ? AND published = 0')->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'پیش‌نویس حذف شد.';
            break;
        }

        // S22.T22.3b — ارتقای دستی به طلایی (توافق حضوری/فیش خارج از درگاه): همان «حق ارتقا» را به‌صورت سفارش paid/manual ثبت می‌کند.
        // سطح را خودش عوض نمی‌کند؛ مالک حساب ویزارد ارتقا را اجرا می‌کند (tier.upgrade_apply).
        case 'manual_tier_upgrade': {
            $redirect = 'pay.php?tab=upgrades';
            $cid = (int)($_POST['customer_id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($note === '') { $msg = 'برای ارتقای دستی شرح (مثلاً شمارهٔ فیش یا توافق) الزامی است.'; break; }
            $st = $pdo->prepare('SELECT id, plan, expiry_date, tier, active FROM customers WHERE id = ?');
            $st->execute([$cid]);
            $c = $st->fetch();
            if (!$c) { $msg = 'مشتری پیدا نشد.'; break; }
            $exp = !empty($c['expiry_date']) ? (string)$c['expiry_date'] : null; // (payc_account در پوشهٔ api است؛ اینجا محلی حساب می‌کنیم)
            $active = $exp !== null && $exp >= pay_today(time()) && !empty($c['active']);
            if (pay_tier_norm($c['tier'] ?? 'green') === 'gold') { $msg = 'این حساب هم‌اکنون طلایی است.'; break; }
            if (!$active) { $msg = 'ارتقا فقط برای اشتراک فعال ممکن است.'; break; }
            $planCode = ($c['plan'] ?? 'rial') === 'fx' ? 'fx' : 'rial';
            $pid = 'PB' . date('ymd') . strtoupper(bin2hex(random_bytes(6)));
            $pdo->prepare("INSERT INTO pay_orders (public_id, customer_id, idem, order_type, plan, tier, months, status, method,
                    upgrade_amount, renew_normal, site_discount, code_id, code_text, code_discount, vat_percent, vat_amount, total, snapshot, activated_at)
                VALUES (?,?,?,?,?,?,0,'paid','manual',0,0,0,NULL,NULL,0,0,0,0,?,NOW())")
                ->execute([$pid, $cid, 'admin-' . bin2hex(random_bytes(8)), 'tier_upgrade', $planCode, 'gold',
                    json_encode(['manual' => true, 'note' => $note], JSON_UNESCAPED_UNICODE)]);
            admin_audit($pdo, $cid, 'tier_manual_upgrade_entitle', 'green', 'gold: ' . $note);
            pay_notify($pdo, $cid, 'حق ارتقا به طلایی ثبت شد ✅', 'حق ارتقای حساب شما به نسخهٔ طلایی ثبت شد. ارتقا را از بخش «ارتقا» در برنامه (با حساب مالک) انجام دهید.');
            $msg = 'حق ارتقا به طلایی ثبت شد (سفارش دستی ' . $pid . '). مشتری اطلاع‌رسانی شد.';
            break;
        }

        // S22.T22.3c — upgrade_runs: ادامه (resume) و بازگشت از نسخهٔ طلایی (منطق در ug_admin.php / api/tier_upgrade.php)
        case 'ug_retry':
        case 'ug_rollback_check':
        case 'ug_rollback': {
            $redirect = 'pay.php?tab=upgrades';
            require_once __DIR__ . '/ug_admin.php';
            $rid = (int)($_POST['run_id'] ?? 0);
            if ($action === 'ug_retry') $msg = ug_admin_retry($pdo, $rid);
            elseif ($action === 'ug_rollback_check') $msg = ug_admin_rollback($pdo, $rid, true);
            else $msg = ug_admin_rollback($pdo, $rid, false);
            break;
        }

        default:
            $msg = 'عملیات نامعتبر.';
    }
} catch (Throwable $e) {
    error_log('pay_actions.php [' . $action . ']: ' . $e->getMessage());
    $msg = 'خطایی رخ داد؛ دوباره امتحان کنید. اگر ادامه داشت، لاگ سرور را بررسی کنید.';
}

$_SESSION['flash'] = $msg;
header('Location: ' . $redirect);
exit;
