<?php
/**
 * ===== توابع کمکی ربات مانیتورینگ سرور (Uptime) =====
 * فقط توابع خالص: بدون دیتابیس، بدون تلگرام — تا هم در ربات، هم در صفحهٔ وب قابل استفاده باشد.
 */

/** خروجی HTML امن */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** عدد → رقم فارسی */
function faNum($n): string
{
    $n = (string)$n;
    return strtr($n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

/** درصد با یک رقم اعشار و جداکنندهٔ فارسی: 99.8 → ۹۹٫۸٪ */
function faPct(?float $p): string
{
    if ($p === null) return '—';
    return faNum(number_format($p, 1, '.', '')) . '٪';
}

/** نمایش زمان پاسخ: 234 → «۲۳۴ میلی‌ثانیه» ، 1234 → «۱٫۲ ثانیه» */
function faMs(int $ms): string
{
    if ($ms <= 0) return '—';
    if ($ms < 1000) return faNum($ms) . ' میلی‌ثانیه';
    return faNum(number_format($ms / 1000, 1, '.', '')) . ' ثانیه';
}

/** «۱۲ ثانیه پیش» / «۳ دقیقه پیش» / «۲ ساعت پیش» / «۴ روز پیش» */
function timeAgo(?string $datetime, float $tzOffset = 3.5): string
{
    if (!$datetime) return 'هرگز';
    $ts = strtotime($datetime);
    if ($ts === false) return '—';
    $diff = time() - $ts;
    if ($diff < 0) $diff = 0;
    if ($diff < 5) return 'همین حالا';
    if ($diff < 60) return faNum($diff) . ' ثانیه پیش';
    if ($diff < 3600) return faNum(intdiv($diff, 60)) . ' دقیقه پیش';
    if ($diff < 86400) return faNum(intdiv($diff, 3600)) . ' ساعت پیش';
    if ($diff < 86400 * 30) return faNum(intdiv($diff, 86400)) . ' روز پیش';
    return faNum(date('Y/m/d', $ts + (int)round($tzOffset * 3600)));
}

/** نمایش مدت به ثانیه: 95 → «۱ دقیقه و ۳۵ ثانیه» */
function faDuration(int $sec): string
{
    if ($sec <= 0) return '۰ ثانیه';
    $parts = [];
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($d) $parts[] = faNum($d) . ' روز';
    if ($h) $parts[] = faNum($h) . ' ساعت';
    if ($m) $parts[] = faNum($m) . ' دقیقه';
    if ($s && !$d) $parts[] = faNum($s) . ' ثانیه';
    return implode(' و ', $parts);
}

/** «۲۰۲۶/۰۹/۲۷ ۱۲:۳۴:۵۶» */
function faDateTime(?string $datetime, float $tzOffset = 3.5): string
{
    if (!$datetime) return '—';
    $ts = strtotime($datetime);
    if ($ts === false) return '—';
    return faNum(date('Y/m/d H:i', $ts + (int)round($tzOffset * 3600)));
}

/** توکن تصادفی امن برای لینک اشتراکی */
function makeShareToken(int $len = 20): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

/** اعتبارسنجی هاست/دامنه/IP (پذیرش localhost و نام‌های تک‌بخشی هم هست) */
function validHost(string $host): bool
{
    if ($host === '' || strlen($host) > 253) return false;
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) return true;
    if (strcasecmp($host, 'localhost') === 0) return true;
    return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host);
}

/**
 * نرمال‌سازی ورودی کاربر → آرایهٔ سایت.
 *
 * ورودی‌های مجاز:
 *   https://example.com/path     → type=http
 *   example.com                  → type=ping
 *   example.com:8080             → type=tcp (پورت مشخص)
 *   1.2.3.4  /  1.2.3.4:22       → type=ping / tcp
 *
 * @return array{ok:bool, error?:string, type?:string, host?:string, port?:int, url?:string, label?:string, target?:string}
 */
function normalizeTarget(string $input): array
{
    $raw = trim($input);
    $raw = preg_replace('/[\x{00A0}\s]+/u', '', $raw) ?? $raw;
    $raw = rtrim($raw, '/');
    if ($raw === '') return ['ok' => false, 'error' => 'آدرس خالی است.'];

    // حذف اموجی/کاراکترهای اضافی که کاربر همراه لینک می‌فرستد
    $raw = preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\s]+/u', '', $raw) ?? $raw;

    if (preg_match('#^https?://#i', $raw)) {
        $p = parse_url($raw);
        if ($p === false || empty($p['host'])) return ['ok' => false, 'error' => 'لینک معتبر نیست.'];
        $host = strtolower($p['host']);
        if (!validHost($host)) return ['ok' => false, 'error' => 'هاست/دامنهٔ لینک معتبر نیست: ' . $host];
        $port = (int)($p['port'] ?? 0);
        $scheme = strtolower($p['scheme']);
        $url = $scheme . '://' . $host . ($port ? ':' . $port : '') . ($p['path'] ?? '');
        if (!empty($p['query'])) $url .= '?' . $p['query'];
        return [
            'ok' => true, 'type' => 'http', 'host' => $host, 'port' => $port ?: ($scheme === 'https' ? 443 : 80),
            'url' => $url, 'label' => $host . (('' === ($p['path'] ?? '') || ($p['path'] ?? '') === '/') ? '' : ($p['path'] ?? '')), 'target' => $url,
        ];
    }

    // host یا host:port یا [ipv6]:port
    $host = $raw;
    $port = 0;
    if (preg_match('/^\[([^\]]+)\](?::(\d{1,5}))?$/', $raw, $m)) {
        $host = $m[1];
        $port = (int)($m[2] ?? 0);
    } elseif (substr_count($raw, ':') === 1) {
        [$host, $p2] = explode(':', $raw, 2);
        if (!ctype_digit($p2)) return ['ok' => false, 'error' => 'پورت باید عدد باشد (مثال: example.com:8080).'];
        $port = (int)$p2;
    } elseif (substr_count($raw, ':') > 1) {
        // IPv6 بدون پرانتز → فقط اگر معتبر باشد بپذیر
        $host = $raw;
        $port = 0;
    }

    $host = strtolower(trim($host));
    if (!validHost($host)) return ['ok' => false, 'error' => 'آدرس واردشده معتبر نیست. نمونه: example.com یا 1.2.3.4 یا https://example.com'];

    if ($port < 0 || $port > 65535) return ['ok' => false, 'error' => 'پورت نامعتبر است.'];

    if ($port > 0) {
        return ['ok' => true, 'type' => 'tcp', 'host' => $host, 'port' => $port, 'url' => '', 'label' => $host . ':' . $port, 'target' => $host . ':' . $port];
    }
    return ['ok' => true, 'type' => 'ping', 'host' => $host, 'port' => 0, 'url' => '', 'label' => $host, 'target' => $host];
}

/** نام نمایشی نوع چک */
function typeName(string $type): string
{
    return ['http' => '🌐 HTTP/HTTPS', 'ping' => '📡 پینگ (ICMP)', 'tcp' => '🔌 اتصال پورت (TCP)'][$type] ?? $type;
}

/** نام فارسی وضعیت */
function statusName(string $status): string
{
    return ['up' => 'فعال', 'down' => 'قطع', 'unknown' => 'نامشخص'][$status] ?? $status;
}

/** ایموجی وضعیت */
function statusEmoji(string $status): string
{
    return ['up' => '🟢', 'down' => '🔴', 'slow' => '🟠', 'unknown' => '🟡'][$status] ?? '⚪️';
}

/** نام فارسی وضعیت (شامل حالت «کند» که وضعیتِ ذخیره‌شده نیست) */
function statusLabel(string $status): string
{
    return ['up' => 'فعال', 'down' => 'قطع', 'slow' => 'کند', 'unknown' => 'نامشخص'][$status] ?? $status;
}

/**
 * وضعیت نمایشی یک سایت.
 * «کند» یک لایهٔ روی «فعال» است: سایت جواب می‌دهد ولی از آستانهٔ max_ms
 * کندتر است. برای همهٔ محاسبات آپتایم همچنان «فعال» شمرده می‌شود.
 */
function siteState(array $s): string
{
    if ((int)($s['paused'] ?? 0) === 1) return 'paused';
    $st = (string)($s['status'] ?? 'unknown');
    if ($st === 'up' && (int)($s['slow'] ?? 0) === 1) return 'slow';
    return $st;
}

/** کلاس رنگی نمایشی برای صفحهٔ وب */
function stateClass(string $st): string
{
    return ['up' => 'up', 'down' => 'down', 'slow' => 'slow', 'paused' => 'paused', 'unknown' => 'unknown'][$st] ?? 'unknown';
}

/** نوار رنگیِ آخرین N چک (برای ربات) — هر کاراکتر یک چک */
function historyBarAscii(array $history): string
{
    if (!$history) return '<i>داده‌ای ثبت نشده</i>';
    $out = '';
    foreach ($history as $h) {
        $out .= $h['ok'] ? '🟩' : '🟥';
    }
    return $out;
}

/** URL صفحهٔ وضعیت عمومی یک کاربر */
function statusUrl(array $cfg, string $shareToken): string
{
    $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
    if ($base === '') $base = 'https://' . trim((string)($cfg['domain'] ?? ''));
    return $base . '/status.php?u=' . rawurlencode($shareToken);
}

/** فرمت شماره/مبلغ فارسی */
function faMoney($n): string
{
    return faNum(number_format((int)$n, 0, '.', ',')) . ' تومان';
}

/** بریدن متن با حفظ ابتدا/انتها */
function truncateFa(string $s, int $max = 60, string $tail = '…'): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    if ($max <= 0 || mb_strlen($s) <= $max) return $s;
    return mb_substr($s, 0, $max - 1) . $tail;
}

/** «۱۲ روز و ۳ ساعت» برای انقضا — ورودی: ثانیهٔ باقی‌مانده */
function faLeft(int $sec): string
{
    if ($sec < 0) return 'منقضی شده';
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    if ($d > 0) return faNum($d) . ' روز' . ($h > 0 ? ' و ' . faNum($h) . ' ساعت' : '');
    if ($h > 0) return faNum($h) . ' ساعت';
    $m = intdiv($sec % 3600, 60);
    return faNum(max(1, $m)) . ' دقیقه';
}

/** تاریخ شمسی نیست؛ فقط «۲۰۲۶/۰۱/۰۵» از یک رشتهٔ تاریخ MySQL */
function faDay(?string $datetime, float $tzOffset = 3.5): string
{
    if (!$datetime) return '—';
    $ts = strtotime($datetime);
    if ($ts === false) return '—';
    return faNum(date('Y/m/d', $ts + (int)round($tzOffset * 3600)));
}

/** نوار درصد متنی برای تلگرام: 100 → ██████████ */
function progressBar(?float $pct, int $len = 10): string
{
    $len = max(3, $len);
    if ($pct === null) return str_repeat('░', $len);
    $p = max(0.0, min(100.0, (float)$pct));
    $fill = (int)round($p / 100 * $len);
    $out = $fill <= 0 ? '' : ($fill >= $len ? str_repeat('█', $len) : str_repeat('█', $fill - 1) . '▌');
    $used = function_exists('mb_strlen') ? mb_strlen($out) : strlen($out);
    return $out . str_repeat('░', max(0, $len - $used));
}

/** تبدیل ارقام فارسی/عربی به لاتین و حذف جداکننده‌ها — برای ورودی عددی کاربر */
function faToLatin(string $s): string
{
    $s = strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    $s = strtr($s, ['٫' => '.', '،' => ',', '٬' => ',']);
    return $s;
}

/**
 * تجزیهٔ دستور تلگرام: «/add@MyBot https://x» → ['cmd' => 'add', 'rest' => 'https://x']
 */
function parseCommand(string $text): array
{
    $text = trim($text);
    if ($text === '' || $text[0] !== '/') return ['cmd' => '', 'rest' => '', 'args' => []];
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $parts = explode(' ', $text, 2);
    $head = (string)preg_replace('/@[A-Za-z0-9_]+$/', '', $parts[0]);
    $rest = trim($parts[1] ?? '');
    return [
        'cmd' => strtolower(mb_substr($head, 1)),
        'rest' => $rest,
        'args' => $rest === '' ? [] : (preg_split('/\s+/u', $rest) ?: []),
    ];
}

/**
 * ساخت توکن یکتا در یک ستون (بدون نیاز به rand برخوردی).
 * @param callable(string):bool $exists
 */
function uniqueToken(int $len, callable $exists): string
{
    for ($i = 0; $i < 12; $i++) {
        $t = makeShareToken($len);
        if (!$exists($t)) return $t;
    }
    return makeShareToken($len + 10);
}

/** نام کوتاه دامنه برای نمایش */
function baseDomain(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/^\[|\]$/', '', $host) ?? $host;
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) return $host;
    $parts = explode('.', $host);
    $n = count($parts);
    if ($n <= 2) return $host;
    // دامنه‌های چندسطحی مثل co.uk / com.ir
    $two = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'or', 'ne', 'go'];
    if (in_array($parts[$n - 2], $two, true) && $n >= 3) return $parts[$n - 3] . '.' . $parts[$n - 2] . '.' . $parts[$n - 1];
    return $parts[$n - 2] . '.' . $parts[$n - 1];
}
