<?php
// توابع مشترک پنل مدیریت پسته بازار
declare(strict_types=1);

const PB_DATA = __DIR__ . '/../data';
const PB_GUARD = "<?php http_response_code(403); exit; ?>\n";

function pb_read(string $name, $default) {
    $f = PB_DATA . '/' . $name . '.php';
    if (!is_file($f)) return $default;
    $raw = file_get_contents($f);
    if ($raw === false) return $default;
    $raw = substr($raw, strlen(PB_GUARD));
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $default;
}

function pb_write(string $name, array $data): bool {
    $f = PB_DATA . '/' . $name . '.php';
    $tmp = $f . '.tmp' . getmypid();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    if (is_file($f)) @copy($f, $f . '.bak');
    if (file_put_contents($tmp, PB_GUARD . $json, LOCK_EX) === false) return false;
    return rename($tmp, $f);
}

function pb_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('pbadmin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function pb_authed(): bool { pb_session(); return !empty($_SESSION['ok']); }
function pb_csrf(): string { pb_session(); if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }

function pb_ip(): string { return preg_replace('/[^0-9a-fA-F:.]/', '', $_SERVER['REMOTE_ADDR'] ?? 'x') ?: 'x'; }

// محدودیت تلاش ناموفق: ۵ بار در ۱۵ دقیقه
function pb_blocked(): int {
    $a = pb_read('attempts', []);
    $ip = pb_ip(); $now = time();
    $list = array_values(array_filter($a[$ip] ?? [], fn($t) => $t > $now - 900));
    return count($list) >= 5 ? ($list[0] + 900 - $now) : 0;
}
function pb_fail(): void {
    $a = pb_read('attempts', []); $ip = pb_ip(); $now = time();
    foreach ($a as $k => $l) { $a[$k] = array_values(array_filter($l, fn($t) => $t > $now - 900)); if (!$a[$k]) unset($a[$k]); }
    $a[$ip][] = $now;
    pb_write('attempts', $a);
}
function pb_clear_fails(): void {
    $a = pb_read('attempts', []); unset($a[pb_ip()]); pb_write('attempts', $a);
}

function pb_default_config(): array {
    return [
        'plans' => [
            'rial' => ['base' => 2890000, 'special' => ['3' => null, '6' => 15000000, '12' => 27000000]],
            'fx'   => ['base' => null,    'special' => ['3' => null, '6' => null,     '12' => null]],
        ],
        // S22.T22.1: سطح طلایی (سبز همان plans است). base خالی = SKU فعال نیست.
        'tiers' => [
            'gold' => [
                'rial' => ['base' => null, 'special' => ['3' => null, '6' => null, '12' => null]],
                'fx'   => ['base' => null, 'special' => ['3' => null, '6' => null, '12' => null]],
            ],
        ],
        'campaigns' => [],
        'messages' => [],
        'updated' => time(),
    ];
}

function pb_str($v, int $max): string {
    $v = is_string($v) ? $v : '';
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v));
    return mb_substr($v, 0, $max);
}
function pb_int($v, int $min, int $max): ?int {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    $n = (int) round((float) $v);
    return ($n < $min || $n > $max) ? null : $n;
}
function pb_url($v): string {
    $v = pb_str($v, 300);
    if ($v === '') return '';
    if (preg_match('~^(https?://|tel:|mailto:|/|#)~i', $v)) return $v;
    if (preg_match('~^[\w.-]+\.[a-z]{2,}~i', $v)) return 'https://' . $v;
    return '';
}

// پاک‌سازی و اعتبارسنجی کل تنظیمات قبل از ذخیره
function pb_sanitize(array $in): array {
    $out = pb_default_config();
    foreach (['rial', 'fx'] as $pl) {
        $p = $in['plans'][$pl] ?? [];
        $out['plans'][$pl]['base'] = pb_int($p['base'] ?? null, 1000, 100000000000);
        foreach (['3', '6', '12'] as $m) {
            $out['plans'][$pl]['special'][$m] = pb_int($p['special'][$m] ?? null, 1000, 1000000000000);
        }
    }
    // tiers: فقط اگر در ورودی بود؛ وگرنه api.php مقدار ذخیره‌شده را نگه می‌دارد
    if (isset($in['tiers']) && is_array($in['tiers'])) {
        $out['tiers'] = [];
        foreach (['gold'] as $t) {
            foreach (['rial', 'fx'] as $pl) {
                $p = $in['tiers'][$t][$pl] ?? [];
                $out['tiers'][$t][$pl]['base'] = pb_int($p['base'] ?? null, 1000, 100000000000);
                foreach (['3', '6', '12'] as $m) {
                    $out['tiers'][$t][$pl]['special'][$m] = pb_int($p['special'][$m] ?? null, 1000, 1000000000000);
                }
            }
        }
    }
    $out['campaigns'] = [];
    foreach (array_slice($in['campaigns'] ?? [], 0, 30) as $c) {
        if (!is_array($c)) continue;
        $pct = pb_int($c['percent'] ?? null, 1, 95);
        $title = pb_str($c['title'] ?? '', 40);
        if (!$pct || $title === '') continue;
        $plans = array_values(array_intersect(['rial', 'fx'], (array) ($c['plans'] ?? [])));
        // کمپین قدیمی (بدون tiers) فقط سبز است؛ همان معنای موتور پرداخت
        $tiers = isset($c['tiers']) ? array_values(array_intersect(['green', 'gold'], (array) $c['tiers'])) : ['green'];
        $durs = array_values(array_intersect([3, 6, 12], array_map('intval', (array) ($c['durations'] ?? []))));
        $out['campaigns'][] = [
            'id' => pb_str($c['id'] ?? '', 24) ?: bin2hex(random_bytes(4)),
            'title' => $title, 'percent' => $pct, 'plans' => $plans, 'tiers' => $tiers, 'durations' => $durs,
            'active' => !empty($c['active']),
            'end' => pb_int($c['end'] ?? null, 1, 4102444800),
        ];
    }
    $types = ['greeting', 'update', 'notice', 'warning'];
    $out['messages'] = [];
    foreach (array_slice($in['messages'] ?? [], 0, 60) as $m) {
        if (!is_array($m)) continue;
        $title = pb_str($m['title'] ?? '', 80);
        if ($title === '') continue;
        $out['messages'][] = [
            'id' => pb_str($m['id'] ?? '', 24) ?: bin2hex(random_bytes(4)),
            'type' => in_array($m['type'] ?? '', $types, true) ? $m['type'] : 'notice',
            'title' => $title,
            'body' => pb_str($m['body'] ?? '', 600),
            'link' => pb_url($m['link'] ?? ''),
            'linkLabel' => pb_str($m['linkLabel'] ?? '', 30),
            'active' => !empty($m['active']),
            'pinned' => !empty($m['pinned']),
            'end' => pb_int($m['end'] ?? null, 1, 4102444800),
            'created' => pb_int($m['created'] ?? null, 1, 4102444800) ?: time(),
        ];
    }
    $out['updated'] = time();
    return $out;
}

function pb_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
