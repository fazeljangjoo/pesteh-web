<?php
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex, nofollow');
if (!pb_authed()) pb_json(['error' => 'auth'], 401);

$a = $_GET['a'] ?? '';
if ($a === 'load') {
    pb_json(['config' => pb_read('config', pb_default_config()), 'now' => time(), 'csrf' => pb_csrf()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pb_json(['error' => 'method'], 405);
if (!hash_equals(pb_csrf(), $_SERVER['HTTP_X_CSRF'] ?? '')) pb_json(['error' => 'csrf'], 403);
$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) pb_json(['error' => 'bad'], 400);

if ($a === 'save') {
    $clean = pb_sanitize($body);
    // ویرایشگرِ قدیمی tiers را نمی‌فرستد: قیمت‌های طلایی ذخیره‌شده نباید با هر ذخیره پاک شوند
    if (!isset($body['tiers'])) {
        $prev = pb_read('config', pb_default_config());
        $clean['tiers'] = $prev['tiers'] ?? pb_default_config()['tiers'];
    }
    if (!pb_write('config', $clean)) pb_json(['error' => 'write'], 500);
    pb_json(['ok' => true, 'config' => $clean, 'now' => time()]);
}

if ($a === 'password') {
    $adm = pb_read('admin', []);
    $old = (string) ($body['old'] ?? ''); $new = (string) ($body['new'] ?? '');
    if (empty($adm['hash']) || !password_verify($old, $adm['hash'])) pb_json(['error' => 'old'], 400);
    if (mb_strlen($new) < 8) pb_json(['error' => 'short'], 400);
    pb_write('admin', ['hash' => password_hash($new, PASSWORD_DEFAULT)]);
    pb_json(['ok' => true]);
}
pb_json(['error' => 'unknown'], 404);
