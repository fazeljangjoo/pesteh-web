<?php
declare(strict_types=1);
/** یک‌بار در مرورگر باز کنید تا مطمئن شوید امضای اشتراک روی هاست کار می‌کند؛ بعد این فایل را حذف کنید. */
require dirname(__DIR__, 3) . '/private/config.php';
require __DIR__ . '/license_lib.php';
header('Content-Type: application/json; charset=utf-8');
$out = ['sodium' => function_exists('sodium_crypto_sign_detached'), 'key' => lic_seed() !== null, 'signed' => false];
if ($out['sodium'] && $out['key']) {
    $tok = license_issue('989000000000', ['active' => 1, 'expiry_date' => date('Y-m-d'), 'plan' => 'rial'], str_repeat('a', 64));
    $out['signed'] = is_string($tok) && substr_count($tok, '.') === 1;
}
$out['ok'] = $out['sodium'] && $out['key'] && $out['signed'];
echo json_encode($out);
