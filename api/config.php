<?php
// خروجی عمومی: قیمت‌ها، تخفیف‌های فعال و پیام‌های فعال برای نمایش در سایت
require __DIR__ . '/../admin/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

$cfg = pb_read('config', pb_default_config());
$now = time();

$cfg['campaigns'] = array_values(array_filter($cfg['campaigns'] ?? [], fn($c) => !empty($c['active']) && (empty($c['end']) || $c['end'] > $now)));
$msgs = array_values(array_filter($cfg['messages'] ?? [], fn($m) => !empty($m['active']) && (empty($m['end']) || $m['end'] > $now)));
usort($msgs, fn($a, $b) => (($b['pinned'] ?? 0) <=> ($a['pinned'] ?? 0)) ?: (($b['created'] ?? 0) <=> ($a['created'] ?? 0)));
$cfg['messages'] = $msgs;
$cfg['now'] = $now;

echo json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
