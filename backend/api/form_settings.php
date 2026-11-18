<?php
declare(strict_types=1);
/**
 * S35.T35.1 — per-company on/off switches for the professional purchase/sale form features: form_settings.get | form_settings.save.
 * Stored in app_settings (per customer, k = 'form.<key>', v = '0'|'1'). Both editions. Every feature is OFF by default (the forms and
 * PDFs behave exactly as before). Read: sales.view (every form user needs it); write: settings.manage (permissions.php).
 */
require_once __DIR__ . '/money_unit.php';
require_once __DIR__ . '/pb_fmt.php'; // S35.T35.10/11
require_once __DIR__ . '/weigh_block.php';
require_once __DIR__ . '/grade_lines.php';

/** key => one-line Persian explanation shown beside the switch. */
const PB_FORM_FEATURES = [
    'weigh' => 'توزین: وزن ناخالص، تارا، کسر کیفیت، شمارهٔ قبض باسکول، پلاک و راننده',
    'grades' => 'ردیف درجه‌ها: قیمت جداگانه برای هر درجه (خندان، دهان‌بسته، کله‌قوچی، پوک…) در یک سند',
    'price_table' => 'جدول قیمت روز و «قیمت پیشنهادی روز» کنار قیمت (با دکمهٔ فعال‌سازی)',
    'fin_block' => 'بلوک مالی: تخفیف، مالیات و جمع نهایی سند',
    'pay_terms' => 'شرایط پرداخت: سررسید و پرداخت‌کنندهٔ هزینه‌های جانبی',
    'profit_hint' => 'نمایش سود تقریبی هنگام ثبت فروش',
    'approval' => 'تأیید دوم مالک برای اسناد کارمندان بالاتر از سقف مبلغ',
    'draft' => 'پیش‌نویس: ذخیرهٔ سند بدون قطعی‌شدن',
    'confirm_summary' => 'خلاصهٔ تأیید پیش از ثبت نهایی',
];

function pb_form_settings_get(PDO $pdo, int $cid): array {
    $o = [];
    foreach (PB_FORM_FEATURES as $k => $help) {
        $o[] = ['key' => $k, 'on' => pb_app_setting_get($pdo, $cid, 'form.' . $k, '0') === '1', 'help' => $help];
    }
    [$sm, $lg] = pb_weigh_sack_kg($pdo, $cid);
    return ['features' => $o, 'grades' => pb_grades_list($pdo, $cid), 'weigh' => ['small_kg' => $sm, 'large_kg' => $lg], // S35.T35.2: sack weights (kg)
        'format' => pb_fmt_settings_get($pdo, $cid)]; // S35.T35.11: display unit + digits
}

/** S35.T35.11: universal formatting settings. display_unit rial|toman (default = stored money unit), digits fa|en (default fa). Display only: storage never changes. */
function pb_fmt_settings_get(PDO $pdo, int $cid): array {
    $d = pb_app_setting_get($pdo, $cid, 'digits', 'fa');
    return ['display_unit' => pb_fmt_display_unit($pdo, $cid), 'digits' => $d === 'en' ? 'en' : 'fa', 'stored_unit' => pb_money_unit($pdo, $cid)];
}

function pb_fmt_settings_save(PDO $pdo, int $cid, array $f): void {
    if (isset($f['display_unit'])) {
        if (!in_array($f['display_unit'], ['rial', 'toman'], true)) bad('واحد نمایش نامعتبر است');
        pb_app_setting_set($pdo, $cid, 'display_unit', (string)$f['display_unit']);
    }
    if (isset($f['digits'])) {
        if (!in_array($f['digits'], ['fa', 'en'], true)) bad('نوع ارقام نامعتبر است');
        pb_app_setting_set($pdo, $cid, 'digits', (string)$f['digits']);
    }
}

/** payload: {settings: {weigh: bool, grades: bool, ...}} — unknown keys are rejected; missing keys are left unchanged. */
function pb_form_settings_save(PDO $pdo, int $cid, array $p): array {
    $s = $p['settings'] ?? null;
    if (isset($p['weigh']) && is_array($p['weigh'])) pb_weigh_sack_save($pdo, $cid, $p['weigh']); // S35.T35.2
    if (isset($p['grades'])) pb_grades_save($pdo, $cid, $p['grades']); // S35.T35.3
    if (isset($p['format']) && is_array($p['format'])) pb_fmt_settings_save($pdo, $cid, $p['format']); // S35.T35.11
    if ((!is_array($s) || !$s) && (isset($p['weigh']) || isset($p['grades']) || isset($p['format']))) return pb_form_settings_get($pdo, $cid);
    if (!is_array($s) || !$s) bad('تنظیمی ارسال نشده است');
    foreach ($s as $k => $v) {
        if (!is_string($k) || !isset(PB_FORM_FEATURES[$k])) bad('تنظیم نامعتبر: ' . (is_string($k) ? $k : '?'));
    }
    foreach ($s as $k => $v) {
        $on = $v === true || $v === 1 || $v === '1';
        pb_app_setting_set($pdo, $cid, 'form.' . $k, $on ? '1' : '0');
    }
    return pb_form_settings_get($pdo, $cid);
}

/** Convenience for later tasks (T35.2+): is a form feature on for this company? */
function pb_form_on(PDO $pdo, int $cid, string $key): bool {
    return pb_app_setting_get($pdo, $cid, 'form.' . $key, '0') === '1';
}
