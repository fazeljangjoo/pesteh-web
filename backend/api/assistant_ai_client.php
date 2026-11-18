<?php
declare(strict_types=1);
/**
 * پسته‌کاو — کلاینت HTTP برای هر API سازگار با OpenAI (لیارا، گپ‌جی‌پی‌تی، ...).
 * فقط ارتباط با مدل؛ هیچ دسترسی به دیتابیس ندارد.
 * تنظیمات و کلید: /home/<user>/private/ai_config.php (بیرون از public_html)
 */
if (!defined('PB_ASSISTANT') && !defined('PB_AI_ADMIN')) { http_response_code(403); exit; }

/** سقف اندازهٔ پاسخ ارائه‌دهنده (بایت) */
const AI_MAX_RESPONSE_BYTES = 262144;

/**
 * تنظیمات اعتبارسنجی‌شده. اگر فایل نبود/نامعتبر بود: ['enabled'=>false,'config_error'=>'...'].
 * کلید API فقط همین‌جا در حافظه می‌ماند و هرگز لاگ یا نمایش داده نمی‌شود.
 */
function ai_cfg(): array {
    static $cache = null;
    $testRaw = (defined('PB_AI_TEST') && isset($GLOBALS['PB_AI_CFG']) && is_array($GLOBALS['PB_AI_CFG'])) ? $GLOBALS['PB_AI_CFG'] : null;
    if ($cache !== null && $testRaw === null) return $cache;

    $off = static function (string $why): array { return ['enabled' => false, 'config_error' => $why]; };

    if ($testRaw !== null) {
        $raw = $testRaw;
    } else {
        $f = dirname(__DIR__, 3) . '/private/ai_config.php';
        if (!is_file($f)) return $cache = $off('missing_file');
        try { $raw = require $f; } catch (Throwable $e) { error_log('[ai] config load failed'); return $cache = $off('load_failed'); }
        if (!is_array($raw)) return $cache = $off('not_array');
    }

    $c = $raw + [
        'enabled' => false, 'base_url' => '', 'api_key' => '', 'model' => '',
        'timeout' => 45, 'connect_timeout' => 10, 'max_output_tokens' => 600,
        'max_tokens_param' => 'max_tokens', 'temperature' => 0.2,
        'allowed_hosts' => [], 'price_in_usd_per_m' => 0.0, 'price_out_usd_per_m' => 0.0, 'usd_toman' => 275000,
    ];
    $c['enabled'] = ($c['enabled'] === true);
    $c['base_url'] = rtrim((string)$c['base_url'], '/');
    $c['api_key'] = (string)$c['api_key'];
    $c['model'] = (string)$c['model'];
    $c['timeout'] = max(5, min(120, (int)$c['timeout']));
    $c['connect_timeout'] = max(2, min(30, (int)$c['connect_timeout']));
    $c['max_output_tokens'] = max(50, min(4000, (int)$c['max_output_tokens']));
    $c['max_tokens_param'] = ($c['max_tokens_param'] === 'max_completion_tokens') ? 'max_completion_tokens' : 'max_tokens';
    $c['allowed_hosts'] = array_values(array_filter(array_map('strtolower', array_map('strval', (array)$c['allowed_hosts']))));

    if ($c['api_key'] === '' || $c['model'] === '' || $c['base_url'] === '') return $cache = $off('incomplete');
    $p = parse_url($c['base_url']);
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host = strtolower((string)($p['host'] ?? ''));
    $testLocal = defined('PB_AI_TEST') && $scheme === 'http' && ($host === '127.0.0.1' || $host === 'localhost');
    if ($scheme !== 'https' && !$testLocal) return $cache = $off('not_https');
    if ($host === '' || !in_array($host, $c['allowed_hosts'], true)) return $cache = $off('host_not_allowed');
    $c['_host'] = $host;
    return $cache = $c;
}

/** میزبان پیکربندی‌شده (برای نمایش در صفحهٔ تست؛ بدون مسیر و بدون کلید) */
function ai_cfg_host(): string {
    $c = ai_cfg();
    return (string)($c['_host'] ?? '');
}

/**
 * یک نوبت گفتگو با مدل.
 * @return array{ok:bool,message:array,finish:string,usage:array{in:int,out:int},ms:int,error:string,err_code:string,status:int,detail:string}
 *   error: پیام عمومی فارسی (قابل نمایش به کاربر). err_code/status/detail فقط برای ادمین/لاگ‌اند.
 */
function ai_chat(array $messages, array $tools = [], array $opts = []): array {
    $res = ['ok' => false, 'message' => [], 'finish' => '', 'usage' => ['in' => 0, 'out' => 0], 'ms' => 0,
            'error' => 'ارتباط با پسته‌کاو برقرار نشد', 'err_code' => '', 'status' => 0, 'detail' => ''];

    if (defined('PB_AI_TEST') && isset($GLOBALS['PB_AI_CHAT_STUB']) && is_callable($GLOBALS['PB_AI_CHAT_STUB'])) {
        $r = ($GLOBALS['PB_AI_CHAT_STUB'])($messages, $tools, $opts);
        return is_array($r) ? ($r + $res) : $res;
    }

    $cfg = ai_cfg();
    if (empty($cfg['enabled'])) { $res['err_code'] = 'config_' . (string)($cfg['config_error'] ?? 'disabled'); return $res; }
    if (!function_exists('curl_init')) { $res['err_code'] = 'no_curl'; return $res; }

    $payload = [
        'model' => (string)($opts['model'] ?? $cfg['model']),
        'messages' => array_values($messages),
        $cfg['max_tokens_param'] => (int)($opts['max_output_tokens'] ?? $cfg['max_output_tokens']),
    ];
    if ($cfg['temperature'] !== null && $cfg['temperature'] !== false) $payload['temperature'] = (float)($opts['temperature'] ?? $cfg['temperature']);
    if ($tools) { $payload['tools'] = array_values($tools); $payload['tool_choice'] = 'auto'; }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($body === false) { $res['err_code'] = 'encode'; return $res; }

    $url = $cfg['base_url'] . '/chat/completions';
    $t0 = microtime(true);
    $attempts = 0;
    $raw = '';
    $status = 0;
    $cerr = 0;
    do {
        $attempts++;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $cfg['api_key']],
            CURLOPT_TIMEOUT => $cfg['timeout'],
            CURLOPT_CONNECTTIMEOUT => $cfg['connect_timeout'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => (defined('PB_AI_TEST') ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS),
            CURLOPT_WRITEFUNCTION => static function ($c, $chunk) use (&$buf) {
                $buf .= $chunk;
                return strlen($buf) > AI_MAX_RESPONSE_BYTES ? -1 : strlen($chunk); // سقف اندازه
            },
        ]);
        curl_exec($ch);
        $cerr = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $raw = $buf;
        $retry = ($cerr !== 0 || $status === 429 || $status >= 500);
        if ($retry && $attempts < 2) sleep(1);
    } while ($retry && $attempts < 2);

    $res['ms'] = (int)round((microtime(true) - $t0) * 1000);
    $res['status'] = $status;
    // لاگ فقط متادیتا؛ بدون کلید و بدون متن پیام‌ها
    error_log(sprintf('[ai] status=%d curl=%d ms=%d model=%s tries=%d', $status, $cerr, $res['ms'], (string)$payload['model'], $attempts));

    if ($cerr !== 0) { $res['err_code'] = 'network_' . $cerr; return $res; }
    $j = json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $res['err_code'] = $status === 429 ? 'http_429' : ($status >= 500 ? 'http_5xx' : 'http_' . $status);
        if (is_array($j)) {
            $m = $j['error']['message'] ?? ($j['message'] ?? '');
            $res['detail'] = mb_substr(is_string($m) ? $m : '', 0, 160); // فقط برای ادمین؛ به کاربر نشان داده نشود
        }
        return $res;
    }
    if (!is_array($j) || !isset($j['choices'][0]['message']) || !is_array($j['choices'][0]['message'])) { $res['err_code'] = 'bad_json'; return $res; }

    $m = $j['choices'][0]['message'];
    $msg = ['role' => 'assistant', 'content' => isset($m['content']) && is_string($m['content']) ? $m['content'] : ''];
    if (!empty($m['tool_calls']) && is_array($m['tool_calls'])) $msg['tool_calls'] = $m['tool_calls'];
    if ($msg['content'] === '' && empty($msg['tool_calls'])) { $res['err_code'] = 'empty'; return $res; }

    $res['ok'] = true;
    $res['error'] = '';
    $res['message'] = $msg;
    $res['finish'] = (string)($j['choices'][0]['finish_reason'] ?? '');
    $res['usage'] = ['in' => (int)($j['usage']['prompt_tokens'] ?? 0), 'out' => (int)($j['usage']['completion_tokens'] ?? 0)];
    return $res;
}

/** برآورد هزینه به تومان از قیمت‌های کانفیگ (دلار به‌ازای یک میلیون توکن) */
function ai_estimate_cost_toman(int $in, int $out): float {
    $c = ai_cfg();
    $usd = ($in * (float)($c['price_in_usd_per_m'] ?? 0) + $out * (float)($c['price_out_usd_per_m'] ?? 0)) / 1000000.0;
    return $usd * (float)($c['usd_toman'] ?? 275000);
}
