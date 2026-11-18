<?php
declare(strict_types=1);
/**
 * T1 (ردیابی پسته) — حرف کد پسته؛ «منبع حقیقت» برای سرور. آینه در Dart/JS در مرحلهٔ T2؛ تست مشترک: tests/code_kind_cases.json.
 *
 * کد جدید همیشه با حرف نوعش شروع می‌شود:
 *   KH خندان · D دهنبست · G سایر دسته‌ها · M مخلوط · S جداسازی · W کارگاه داخلی · B کارگاه بیرونی
 *   C امانی · R دریافتی برای دیگران · H باغ
 * کدهای قدیمی (KH+عدد کوتاه، MX، K-/K<id>، PR، OR) هرگز تغییر نمی‌کنند؛ فقط خوانده و نمایش داده می‌شوند.
 * هیچ مبلغی در این فایل نیست. توابع «خالص» (بدون PDO) برای تست مستقل از دیتابیس‌اند.
 */

/** نوع → حرف (کدهای جدید). */
const PB_CODE_LETTERS = [
    'khandan'     => 'KH',
    'dombest'     => 'D',
    'other'       => 'G',
    'mix'         => 'M',
    'separation'  => 'S',
    'workshop'    => 'W',
    'outside'     => 'B',
    'consignment' => 'C',
    'received'    => 'R',
    'orchard'     => 'H',
];
/** پیشوند قدیمی → نوع (فقط خواندن). KH قدیمی جدا بررسی می‌شود (KH + حداکثر ۱۱ رقم). */
const PB_CODE_LEGACY_PREFIX = ['MX' => 'mix', 'K' => 'separation', 'PR' => 'outside', 'OR' => 'orchard'];
const PB_CODE_MAXLEN = 40;       // BACKUP_MAXLEN
const PB_CODE_CHAIN_MAX = 34;    // زنجیرهٔ طولانی‌تر ⇒ <حرف><id>
const PB_CODE_PREFIX_KEY = 'lot_prefix_map'; // app_settings: JSON {categoryId: letter}

/** نام دسته → حرف (خالص). «خندان» → KH، «دهنبست/دهن‌بست/دهن بست» → D، غیر از این → G. */
function pb_code_letter_for_name(string $name): string {
    $n = str_replace(["\u{200C}", "\u{200D}", ' ', "\u{00A0}"], '', $name);
    $n = str_replace(['ي', 'ك', 'ە'], ['ی', 'ک', 'ه'], $n);
    if (mb_strpos($n, 'خندان') !== false) return PB_CODE_LETTERS['khandan'];
    if (mb_strpos($n, 'دهنبست') !== false) return PB_CODE_LETTERS['dombest'];
    return PB_CODE_LETTERS['other'];
}

/** حرف معتبر برای override: فقط ۱ تا ۲ حرف بزرگ لاتین. */
function pb_code_letter_valid(string $l): bool {
    return (bool)preg_match('/^[A-Z]{1,2}$/', $l);
}

/** نقشهٔ override ذخیره‌شده (دستهٔ → حرف). ورودی خراب = نقشهٔ خالی. */
function pb_code_prefix_map(PDO $pdo, int $cid): array {
    require_once __DIR__ . '/money_unit.php';
    $raw = pb_app_setting_get($pdo, $cid, PB_CODE_PREFIX_KEY, '');
    $m = $raw === '' ? null : json_decode($raw, true);
    $out = [];
    if (is_array($m)) {
        foreach ($m as $k => $v) {
            if (is_string($v) && (string)(int)$k === (string)$k && (int)$k > 0 && pb_code_letter_valid($v)) $out[(string)(int)$k] = $v;
        }
    }
    return $out;
}

/** حرف کد برای یک دسته: اول override در app_settings، بعد نام دسته (خندان/دهنبست/سایر). */
function pb_code_letter_for_category(PDO $pdo, int $cid, int $catId): string {
    $map = pb_code_prefix_map($pdo, $cid);
    if (isset($map[(string)$catId])) return $map[(string)$catId];
    $st = $pdo->prepare('SELECT name FROM categories WHERE id = ? AND customer_id = ?');
    $st->execute([$catId, $cid]);
    $name = $st->fetchColumn();
    return pb_code_letter_for_name(is_string($name) ? $name : '');
}

/**
 * تشخیص نوع از روی خودِ کد (خالص).
 * @return array{kind:string,letter:string,legacy:bool}
 *   kind: khandan|dombest|other|mix|separation|workshop|outside|consignment|received|orchard|custom|unknown
 *   legacy=true یعنی قالب قدیمی (MX…, K-…, PR…, OR…, KH + حداکثر ۱۱ رقم). KH+id نو هم هم‌شکل قدیمی است ⇒ legacy می‌ماند.
 */
function pb_code_kind_of(string $code): array {
    $unknown = ['kind' => 'unknown', 'letter' => '', 'legacy' => false];
    $c = strtoupper(trim($code));
    if ($c === '' || !preg_match('/^([A-Z]{1,2})(-|\d)/', $c, $m)) return $unknown;
    $L = $m[1];
    $rest = substr($c, strlen($L));
    if (isset(PB_CODE_LEGACY_PREFIX[$L])) return ['kind' => PB_CODE_LEGACY_PREFIX[$L], 'letter' => $L, 'legacy' => true];
    if ($L === 'KH') return ['kind' => 'khandan', 'letter' => 'KH', 'legacy' => (bool)preg_match('/^\d{1,11}$/', $rest)];
    $kind = array_search($L, PB_CODE_LETTERS, true);
    if ($kind !== false) return ['kind' => (string)$kind, 'letter' => $L, 'legacy' => false];
    return ['kind' => 'custom', 'letter' => $L, 'legacy' => false];
}

/** «<حرف><id ۵رقمی>» — شکل پایهٔ کدهای جدید که id دارند (خرید/مخلوط/امانی/دریافتی/باغ). */
function pb_code_with_id(string $letter, int $id): string {
    return $letter . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/**
 * ریشهٔ زنجیرهٔ خروجی‌ها (خالص). بعد از آن «-n» اضافه می‌شود.
 * والد از قبل با S-/W-/B-/K- (یا S<id> …) شروع شده ⇒ همان زنجیره ادامه می‌یابد؛ وگرنه «<حرف>-<کد والد>».
 * طول > ۳۴ ⇒ «<حرف><id>» (رفتار قبلی با K).
 */
function pb_code_chain(string $letter, string $parentCode, int $id = 0): string {
    $base = preg_match('/^(?:(?:K|S|W)(?:-|\d)|B-)/', $parentCode) ? $parentCode : $letter . '-' . $parentCode; // B<id> کد کارگاه بیرونی است نه زنجیره ⇒ S-B00007
    if (strlen($base) > PB_CODE_CHAIN_MAX) $base = $letter . $id;
    return $base;
}

/**
 * حرف کد هنگام بازگردانی پشتیبان (idها عوض می‌شوند و کد از نو ساخته می‌شود).
 * اولویت: مخلوط → M؛ حرف خودِ کد قدیمیِ پشتیبان (S/W/B/C/R/H، K→S، PR→B/R، OR→H)؛ بعد is_mix (۲→B، ۳→C)؛ در پایان دستهٔ لات.
 */
function pb_code_letter_for_restore(?PDO $pdo, int $cid, array $row, int $newCatId): string {
    $isMix = (int)($row['is_mix'] ?? 0);
    if ($isMix === 1) return PB_CODE_LETTERS['mix'];
    $k = pb_code_kind_of((string)($row['code'] ?? ''));
    if ($k['legacy']) {
        if ($k['kind'] === 'separation') return PB_CODE_LETTERS['separation'];
        if ($k['kind'] === 'outside') return $isMix === 3 ? PB_CODE_LETTERS['received'] : PB_CODE_LETTERS['outside'];
        if ($k['kind'] === 'orchard') return PB_CODE_LETTERS['orchard'];
    } elseif (in_array($k['kind'], ['separation', 'workshop', 'outside', 'consignment', 'received', 'orchard'], true)) {
        return $k['letter'];
    }
    if ($isMix === 2) return PB_CODE_LETTERS['outside'];
    if ($isMix === 3) return PB_CODE_LETTERS['consignment'];
    return ($pdo !== null && $newCatId > 0) ? pb_code_letter_for_category($pdo, $cid, $newCatId) : PB_CODE_LETTERS['other'];
}

/** خواندن/نوشتن نقشهٔ override (code_prefix.get / code_prefix.set). set: فقط حرف‌های معتبر؛ دستهٔ نامتعلق رد می‌شود. */
function pb_code_prefix_get(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT id, name FROM categories WHERE customer_id = ? ORDER BY id');
    $st->execute([$cid]);
    $map = pb_code_prefix_map($pdo, $cid);
    $cats = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int)$r['id'];
        $auto = pb_code_letter_for_name((string)$r['name']);
        $cats[] = ['category_id' => $id, 'name' => (string)$r['name'], 'auto' => $auto, 'letter' => $map[(string)$id] ?? $auto, 'custom' => isset($map[(string)$id])];
    }
    return ['categories' => $cats, 'map' => (object)$map, 'letters' => PB_CODE_LETTERS];
}

function pb_code_prefix_set(PDO $pdo, int $cid, array $p): array {
    $in = $p['map'] ?? null;
    if (!is_array($in)) bad('نقشهٔ حرف‌ها ارسال نشده است');
    $own = $pdo->prepare('SELECT id FROM categories WHERE customer_id = ?');
    $own->execute([$cid]);
    $owned = array_flip(array_map('strval', $own->fetchAll(PDO::FETCH_COLUMN)));
    $clean = [];
    foreach ($in as $k => $v) {
        $ks = (string)$k;
        if ((string)(int)$ks !== $ks || !isset($owned[$ks])) bad('دستهٔ نامعتبر: ' . $ks);
        if ($v === null || $v === '') continue; // خالی = برگشت به حالت خودکار
        $l = is_string($v) ? strtoupper(trim($v)) : '';
        if (!pb_code_letter_valid($l)) bad('حرف کد فقط یک یا دو حرف لاتین بزرگ است');
        $clean[$ks] = $l;
    }
    $json = json_encode((object)$clean, JSON_UNESCAPED_UNICODE);
    if (strlen((string)$json) > 255) bad('تعداد تنظیمات حرف بیش از حد است');
    require_once __DIR__ . '/money_unit.php';
    pb_app_setting_set($pdo, $cid, PB_CODE_PREFIX_KEY, (string)$json);
    return pb_code_prefix_get($pdo, $cid);
}
