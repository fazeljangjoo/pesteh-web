<?php
declare(strict_types=1);
/**
 * S1 (ماژول اتاق انس) — کد پسته: <حرف نوع: KH خندان / D دهنبست / G سایر؛ T1> + yyyMMdd شمسی (۷ رقم، بدون رقم هزارهٔ سال) + کد پرسنل (۲ رقم) + ترتیب روز (حداقل ۳ رقم).
 * مثال: KH 4050710 02 001 = KH405071002001  (۱۴۰۵/۰۷/۱۰، پرسنل ۰۲، اولین پستهٔ آن روز).
 *
 * - شماره‌دهی فقط سمت سرور و اتمیک است (جدول lot_code_seq)؛ دو دستگاه یک پرسنل هرگز کد تکراری نمی‌گیرند.
 * - تاریخ از ساعت سرور (منطقهٔ Asia/Tehran) و تقویم شمسی jdate.php است، نه ساعت دستگاه.
 * - اگر ترتیب روز از ۹۹۹ بگذرد، رقم‌ها بیشتر می‌شود (۱۰۰۰ …) ولی یکتایی حفظ می‌شود.
 * - کد مالک (staff_id = 0) = «00».
 * - کدهای قدیمی (KH00045 / MX…) دست‌نخورده‌اند.
 */
require_once __DIR__ . '/ddl_guard.php';
require_once __DIR__ . '/jdate.php';

/** جدول شمارندهٔ کد پسته (idempotent). */
function pb_lotcode_ensure(PDO $pdo): void {
    pb_ddl_exec($pdo, "CREATE TABLE IF NOT EXISTS lot_code_seq (
        customer_id INT UNSIGNED NOT NULL,
        day CHAR(8) NOT NULL,
        staff_code CHAR(2) NOT NULL,
        last_seq INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (customer_id, day, staff_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** تاریخ شمسی «امروز» به‌صورت [jy, jm, jd] با منطقهٔ زمانی تهران. $now برای تست قابل تزریق است. */
function pb_lotcode_jtoday(?DateTimeInterface $now = null): array {
    $d = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $d = $d->setTimezone(new DateTimeZone('Asia/Tehran'));
    return pb_g2j((int)$d->format('Y'), (int)$d->format('n'), (int)$d->format('j'));
}

/** بخش تاریخ کد: ۷ رقم (yyy MM dd). ۱۴۰۵/۰۷/۱۰ → '4050710'. */
function pb_lotcode_date_part(array $j): string {
    return sprintf('%03d%02d%02d', $j[0] % 1000, $j[1], $j[2]);
}

/** ساخت متن نهایی کد. */
function pb_lotcode_format(array $j, string $staffCode, int $seq, string $letter = 'KH'): string {
    return $letter . pb_lotcode_date_part($j) . $staffCode . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/** کد دو رقمی پرسنل (مالک = '00'). اگر کارکن کد نداشت (مهاجرت اجرا نشده) خودش تخصیص می‌دهد. */
function pb_staff_code_of(PDO $pdo, int $cid, int $staffId): string {
    if ($staffId <= 0) return '00';
    $st = $pdo->prepare('SELECT staff_code FROM staff_users WHERE id = ? AND customer_id = ?');
    $st->execute([$staffId, $cid]);
    $c = $st->fetchColumn();
    if (is_string($c) && preg_match('/^\d{2}$/', $c)) return $c;
    return pb_staff_code_assign($pdo, $cid, $staffId);
}

/** کوچک‌ترین کد آزاد ۰۱..۹۹ را به کارکن می‌دهد (در صورت داشتن، همان را برمی‌گرداند). */
function pb_staff_code_assign(PDO $pdo, int $cid, int $staffId): string {
    $cur = $pdo->prepare('SELECT staff_code FROM staff_users WHERE id = ? AND customer_id = ?');
    $cur->execute([$staffId, $cid]);
    $have = $cur->fetchColumn();
    if (is_string($have) && preg_match('/^\d{2}$/', $have)) return $have;
    for ($attempt = 0; $attempt < 5; $attempt++) { // برخورد هم‌زمان روی کلید یکتا → دوباره
        $used = $pdo->prepare("SELECT staff_code FROM staff_users WHERE customer_id = ? AND staff_code IS NOT NULL");
        $used->execute([$cid]);
        $taken = array_flip(array_map('strval', $used->fetchAll(PDO::FETCH_COLUMN)));
        $pick = null;
        for ($i = 1; $i <= 99; $i++) {
            $c = sprintf('%02d', $i);
            if (!isset($taken[$c])) { $pick = $c; break; }
        }
        if ($pick === null) throw new RuntimeException('سقف کد پرسنل (۹۹) پر شده است');
        try {
            $pdo->prepare('UPDATE staff_users SET staff_code = ? WHERE id = ? AND customer_id = ? AND staff_code IS NULL')
                ->execute([$pick, $staffId, $cid]);
            $chk = $pdo->prepare('SELECT staff_code FROM staff_users WHERE id = ? AND customer_id = ?');
            $chk->execute([$staffId, $cid]);
            $v = $chk->fetchColumn();
            if (is_string($v) && $v !== '') return $v;
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') throw $e; // 23000 = کد همزمان گرفته شد
        }
    }
    throw new RuntimeException('تخصیص کد پرسنل ناموفق بود');
}

/**
 * کد یکتای پستهٔ جدید. اتمیک (یک دستور INSERT … ON DUPLICATE KEY UPDATE با LAST_INSERT_ID(expr)).
 * @param int $staffId 0 = مالک
 * @return string مثل KH405071002001
 */
function pb_alloc_lot_code(PDO $pdo, int $cid, int $staffId, ?DateTimeInterface $now = null, string $letter = 'KH'): string {
    pb_lotcode_ensure($pdo);
    $j = pb_lotcode_jtoday($now);
    $day = sprintf('%04d%02d%02d', $j[0], $j[1], $j[2]);
    $sc = pb_staff_code_of($pdo, $cid, $staffId);
    $pdo->prepare('INSERT INTO lot_code_seq (customer_id, day, staff_code, last_seq) VALUES (?,?,?, LAST_INSERT_ID(1))
        ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)')->execute([$cid, $day, $sc]);
    $seq = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($seq <= 0) throw new RuntimeException('شمارندهٔ کد پسته ناموفق بود');
    return pb_lotcode_format($j, $sc, $seq, $letter);
}
