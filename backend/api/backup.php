<?php
/**
 * پشتیبان‌گیری فایل دیتابیس اپ (SQLite) روی سرور — هر مشتری پوشهٔ جدا دارد.
 *
 * ورودی (POST، فرم یا multipart):
 *   phone, token  : همان مقادیری که اپ بعد از ورود دارد
 *   action        : upload | list | download
 *   file          : (فقط upload) فایل دیتابیس
 *   name          : (فقط download) نام فایل پشتیبان از خروجی list
 */
declare(strict_types=1);
require dirname(__DIR__, 3) . '/private/config.php';
require_once __DIR__ . '/auth_token.php'; // STAGE14

const BACKUP_MAX_BYTES = 30 * 1024 * 1024; // حداکثر حجم هر پشتیبان
const KEEP_NEWEST      = 20;               // ۲۰ پشتیبان آخر همیشه نگه داشته می‌شود
const KEEP_DAILY_DAYS  = 60;               // از قدیمی‌ترها، آخرین پشتیبان هر روز تا ۶۰ روز

function fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}

/** پشتیبان‌های قدیمی را پاک می‌کند (نام فایل‌ها شامل تاریخ است) */
function prune_backups(string $dir): void {
    $files = glob($dir . '/b_*.db') ?: [];
    rsort($files); // جدیدترین اول
    $keep = [];
    $seenDays = [];
    foreach ($files as $i => $p) {
        $day = substr(basename($p), 2, 8); // YYYYMMDD
        if ($i < KEEP_NEWEST) {
            $keep[$p] = true;
            $seenDays[$day] = true;
            continue;
        }
        $ts = strtotime($day);
        if ($ts !== false && $ts >= time() - KEEP_DAILY_DAYS * 86400 && !isset($seenDays[$day])) {
            $keep[$p] = true;
            $seenDays[$day] = true;
        }
    }
    foreach ($files as $p) {
        if (!isset($keep[$p])) @unlink($p);
    }
}

$phoneRaw = (string)($_POST['phone'] ?? '');
$token    = (string)($_POST['token'] ?? '');
$action   = (string)($_POST['action'] ?? '');
if ($phoneRaw === '' || $token === '' || $action === '') fail(400, 'invalid_request');

try {
    $customer = tok_auth(db(), normalize_phone($phoneRaw), $token); // STAGE14
} catch (Throwable $e) {
    fail(500, 'server_error');
}
if (!$customer) fail(401, 'invalid_token');

$root = dirname(__DIR__, 3) . '/private/backups';
if (!is_dir($root)) @mkdir($root, 0755, true);
$ht = $root . '/.htaccess';
if (!is_file($ht)) {
    @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}
$dir = $root . '/' . (int)$customer['id'];
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail(500, 'storage_unavailable');

if ($action === 'upload') {
    if (!isset($_FILES['file'])) fail(400, 'no_file');
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) fail(400, 'upload_error_' . $f['error']);
    if ($f['size'] <= 0 || $f['size'] > BACKUP_MAX_BYTES) fail(413, 'bad_size');

    $fh = fopen($f['tmp_name'], 'rb');
    $head = $fh ? fread($fh, 16) : '';
    if ($fh) fclose($fh);
    if ($head !== "SQLite format 3\0") fail(400, 'not_sqlite');

    $name = 'b_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.db';
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) fail(500, 'save_failed');
    prune_backups($dir);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'name' => $name, 'time' => time()]);
    exit;
}

if ($action === 'list') {
    $files = glob($dir . '/b_*.db') ?: [];
    rsort($files);
    $out = [];
    foreach (array_slice($files, 0, 60) as $p) {
        $out[] = ['name' => basename($p), 'size' => filesize($p), 'time' => filemtime($p)];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['backups' => $out]);
    exit;
}

if ($action === 'download') {
    $name = (string)($_POST['name'] ?? '');
    if (!preg_match('/^b_\d{8}_\d{6}_[a-f0-9]{16}\.db$/', $name)) fail(400, 'bad_name');
    $p = $dir . '/' . $name;
    if (!is_file($p)) fail(404, 'not_found');
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}

fail(400, 'unknown_action');
