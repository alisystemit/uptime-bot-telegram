<?php
/**
 * ===== بوت‌استرپ ربات مانیتورینگ سرور =====
 * همهٔ endpointها (index.php، status.php، api.php، table.php، cron/*) از همین فایل بالا می‌آیند.
 */

if (!defined('UPTIME_ROOT')) {
    define('UPTIME_ROOT', dirname(__DIR__));

    /**
     * ذخیره‌سازی زمان همه‌جا UTC است (هم در PHP، هم در دیتابیس — نگاه کن به
     * Db::pdo که time_zone جلسه را +00:00 می‌کند) تا strtotime() روی رشته‌های
     * دیتابیس همیشه درست باشد. نمایشِ ساعتِ محلی با tz_offset کانفیگ انجام
     * می‌شود (timeAgo / faDateTime).
     */
    date_default_timezone_set('UTC');

    require_once __DIR__ . '/util.php';
    require_once __DIR__ . '/nav.php';
    require_once __DIR__ . '/db.php';
    require_once UPTIME_ROOT . '/botapi.php';
    require_once __DIR__ . '/ssl.php';
    require_once __DIR__ . '/group.php';
    require_once __DIR__ . '/monitor.php';
    require_once __DIR__ . '/stats.php';
    require_once __DIR__ . '/gateways.php';
    require_once __DIR__ . '/pay.php';
    require_once __DIR__ . '/ranking.php';
    require_once __DIR__ . '/page.php';
    require_once __DIR__ . '/groupbot.php';
    require_once __DIR__ . '/paybot.php';
    require_once __DIR__ . '/bot.php';
}

/** کانفیگ (یک‌بار بارگذاری) */
function appConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        // امکان تست/اجرای محلی بدون دست‌زدن به قالب: UPTIME_CONFIG=path/to/config.php
        $alt = getenv('UPTIME_CONFIG');
        $file = (is_string($alt) && $alt !== '' && is_file($alt)) ? $alt : UPTIME_ROOT . '/config.php';
        $cfg = require $file;
        if (!is_array($cfg)) $cfg = [];
        $cfg += ['defaults' => []];
    }
    return $cfg;
}

/** آماده‌سازی دیتابیس + جدول‌ها */
function appBoot(bool $migrate = true): array
{
    $cfg = appConfig();
    Db::setConfig($cfg);
    if ($migrate) Db::boot();
    return $cfg;
}

function botToken(): string
{
    return (string)(appConfig()['bot_token'] ?? '');
}

function botUsername(): string
{
    return trim((string)(appConfig()['bot_username'] ?? ''), '@');
}

/** آیدی ادمین(ها) — آیدی اصلی از کانفیگ + لیست جداگانهٔ ادمین‌ها اگر ست شده باشد */
function botAdminIds(): array
{
    $cfg = appConfig();
    if (!$cfg) return [];
    $ids = [];
    $single = $cfg['admin_id'] ?? null;
    if (is_array($single)) { foreach ($single as $v) { if (is_numeric($v) && (int)$v > 0) $ids[] = (int)$v; } }
    elseif (is_numeric($single) && (int)$single > 0) $ids[] = (int)$single;
    foreach ((array)($cfg['admin_ids'] ?? []) as $v) {
        if (is_numeric($v) && (int)$v > 0) $ids[] = (int)$v;
    }
    return array_values(array_unique($ids));
}

function isBotAdmin(int $uid): bool
{
    return in_array($uid, botAdminIds(), true);
}

/** ارسال پیام با ایمنی در برابر خرابی API */
function tgSend($chatId, string $text, array $extra = []): array
{
    $t = botToken();
    if ($t === '') return ['ok' => false, 'description' => 'empty token'];
    return BotApi::send($t, $chatId, $text, $extra);
}

/** ایموجی/متن امن برای HTML تلگرام */
function tgH(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** ساعت محلی نمایشی (بر اساس tz_offset کانفیگ) */
function tzOffset(): float
{
    return (float)(appConfig()['tz_offset'] ?? 3.5);
}

/** لاگ فایلی داخل logs/ (پوشه توسط .htaccess مسدود است) */
function uptimeLog(string $level, string $msg): void
{
    $dir = UPTIME_ROOT . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $msg);
    @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}
