<?php
declare(strict_types=1);
/**
 * S22.T22.3c — admin side of the green->gold upgrade: list upgrade_runs, retry (resume) and rollback.
 * Reuses api/tier_upgrade.php. Its helpers out()/bad() are app.php functions that end the request with JSON;
 * here they are replaced by exceptions (UgAdminOut) so the admin page can show the message.
 * BACKUP_TABLES is only used for the informational "operational data changed since snapshot" list; it is empty here.
 */
final class UgAdminOut extends RuntimeException {
    /** @var array<string,mixed> */
    public array $data = [];
}

if (!function_exists('out')) {
    function out($data, int $code = 200): void {
        // same contract as app.php: inside an upgrade step a failing document is isolated through PbOutError
        if ($code >= 400 && !empty($GLOBALS['pb_out_throw']) && class_exists('PbOutError')) throw new PbOutError($data, $code);
        $e = new UgAdminOut(is_array($data) ? (string)($data['message'] ?? $data['error'] ?? 'خطا') : 'خطا', $code);
        $e->data = is_array($data) ? $data : [];
        throw $e;
    }
}
if (!function_exists('bad')) {
    function bad(string $msg, int $code = 400): void { out(['error' => $msg, 'message' => $msg], $code); }
}
if (!defined('BACKUP_TABLES')) define('BACKUP_TABLES', []);

function ug_admin_load_lib(): void {
    require_once __DIR__ . '/../api/features.php';
    require_once __DIR__ . '/../api/audit.php';
    require_once __DIR__ . '/../api/money_unit.php'; // STAGE11.2b: pb_unit_mult_bind
    require_once __DIR__ . '/../api/tier_upgrade.php';
}

function ug_status_label(string $s): string {
    $m = ['running' => 'در حال اجرا', 'failed' => 'ناموفق', 'done' => 'کامل', 'rolled_back' => 'بازگردانده شد'];
    return $m[$s] ?? $s;
}

/** @return array{runs:array<int,array<string,mixed>>,grants:array<int,array<string,mixed>>,candidates:array<int,array<string,mixed>>} */
function ug_admin_data(PDO $pdo): array {
    $runs = $pdo->query("SELECT r.id, r.customer_id, r.status, r.step, r.error, r.started_at, r.finished_at, r.snapshot_file,
            c.name AS cname, c.phone AS cphone, c.tier AS ctier
        FROM upgrade_runs r LEFT JOIN customers c ON c.id = r.customer_id ORDER BY r.id DESC LIMIT 100")->fetchAll();
    $grants = $pdo->query("SELECT o.id, o.public_id, o.customer_id, o.method, o.status, o.total, o.activated_at, c.name AS cname, c.phone AS cphone
        FROM pay_orders o LEFT JOIN customers c ON c.id = o.customer_id
        WHERE o.order_type = 'tier_upgrade' ORDER BY o.id DESC LIMIT 50")->fetchAll();
    $cand = $pdo->query("SELECT id, name, phone FROM customers WHERE (tier IS NULL OR tier <> 'gold') AND active = 1 AND expiry_date >= '" . date('Y-m-d') . "' ORDER BY id DESC LIMIT 300")->fetchAll();
    return ['runs' => $runs, 'grants' => $grants, 'candidates' => $cand];
}

/** Resume a running|failed run with its stored params (no new entitlement needed). Returns a Persian message. */
function ug_admin_retry(PDO $pdo, int $runId): string {
    $st = $pdo->prepare('SELECT * FROM upgrade_runs WHERE id = ?');
    $st->execute([$runId]);
    $run = $st->fetch();
    if (!$run) return 'اجرای ارتقا پیدا نشد.';
    if (!in_array((string)$run['status'], ['running', 'failed'], true)) return 'فقط اجرای «ناموفق» یا «در حال اجرا» قابل ادامه است.';
    $cs = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $cs->execute([(int)$run['customer_id']]);
    $customer = $cs->fetch();
    if (!$customer) return 'مشتری پیدا نشد.';
    $latest = pb_ug_latest($pdo, (int)$customer['id'], ['running', 'failed']);
    if (!$latest || (int)$latest['id'] !== $runId) return 'این اجرا آخرین اجرای فعال مشتری نیست.';
    // STAGE11.2: در پنل ادمین BACKUP_TABLES خالی است؛ اگر مرحلهٔ snapshot هنوز انجام نشده باشد، ادامه از اینجا یک نسخهٔ پشتیبان «خالی» می‌سازد و ارتقا بدون نسخهٔ واقعی جلو می‌رود.
    $stp = json_decode((string)$run['steps'], true);
    if (empty($stp['snapshot']['done'])) return 'ادامه از پنل ممکن نیست: نسخهٔ پشتیبان این ارتقا هنوز ساخته نشده و پنل ادمین فهرست جدول‌های پشتیبان را ندارد؛ مالک باید ارتقا را از برنامه ادامه دهد.';
    ug_admin_load_lib();
    // STAGE11.2b: ضریب واحد پول (ریال=۱، تومان=۱۰) در app.php برای هر درخواست بسته می‌شود؛ اینجا بسته نمی‌شد و پیش‌فرض ×۱۰ می‌ماند
    // ⇒ ادامهٔ ارتقای مشتری ریالی از پنل ادمین، سند افتتاحیه/اسناد backfill را ۱۰ برابر ثبت می‌کرد.
    pb_unit_mult_bind($pdo, (int)$customer['id']);
    try {
        $res = pb_ug_apply($pdo, $customer, ['confirm' => 1]);
    } catch (UgAdminOut $e) {
        return 'ادامه ممکن نشد: ' . $e->getMessage();
    }
    admin_audit($pdo, (int)$customer['id'], 'tier_upgrade_retry', 'run ' . $runId, (string)($res['status'] ?? '?'));
    $s = (string)($res['status'] ?? '');
    if ($s === 'done') return 'ارتقا کامل شد.';
    if ($s === 'failed') return 'مرحلهٔ «' . (string)($res['failed_step'] ?? '?') . '» دوباره ناموفق بود؛ جزئیات خطا در جدول است.';
    return 'اجرا ادامه دارد (بخشی انجام شد)؛ دوباره «ادامه» را بزنید.';
}

/** Rollback the derived gold layer. $dry = only report blockers. */
function ug_admin_rollback(PDO $pdo, int $runId, bool $dry): string {
    $st = $pdo->prepare('SELECT * FROM upgrade_runs WHERE id = ?');
    $st->execute([$runId]);
    $run = $st->fetch();
    if (!$run) return 'اجرای ارتقا پیدا نشد.';
    $cs = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $cs->execute([(int)$run['customer_id']]);
    $customer = $cs->fetch();
    if (!$customer) return 'مشتری پیدا نشد.';
    $latest = pb_ug_latest($pdo, (int)$customer['id'], ['running', 'failed', 'done']);
    if (!$latest || (int)$latest['id'] !== $runId) return 'فقط آخرین اجرای مشتری قابل بازگردانی است.';
    ug_admin_load_lib();
    try {
        $res = pb_ug_rollback($pdo, $customer, $dry ? ['dry' => 1] : []);
    } catch (UgAdminOut $e) {
        $msg = 'بازگشت ممکن نیست: ' . $e->getMessage();
        foreach ((array)($e->data['blockers'] ?? []) as $b) $msg .= ' — ' . (string)($b['detail'] ?? $b['code'] ?? '');
        return $msg;
    }
    if ($dry) {
        $w = (array)($res['would_remove'] ?? []);
        return 'بررسی بازگشت: ممکن است. حذف می‌شود → سند: ' . (int)($w['vouchers'] ?? 0) . '، حساب: ' . (int)($w['accounts'] ?? 0) . '، تفصیلی: ' . (int)($w['tafsili'] ?? 0) . '، سال مالی: ' . (int)($w['fiscal_years'] ?? 0) . '. اسناد عملیاتی دست‌نخورده می‌مانند.';
    }
    admin_audit($pdo, (int)$customer['id'], 'tier_upgrade_rollback', 'gold', 'green (run ' . $runId . ')');
    return 'بازگشت انجام شد؛ حساب دوباره سبز است. (برای ارتقای دوباره، حق ارتقای جدید لازم است.)';
}
