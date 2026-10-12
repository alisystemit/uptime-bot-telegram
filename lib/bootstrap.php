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
    require_once __DIR__ . '/miniapp.php';
    require_once __DIR__ . '/groupbot.php';
    require_once __DIR__ . '/paybot.php';
    require_once __DIR__ . '/addons.php';          // افزونه‌ها (class Addons + trait AddonsUi)
    require_once __DIR__ . '/errors.php';          // گزارش خطای داخلی (class ErrorReport)
    require_once __DIR__ . '/bot.php';
    
    // ===== ماژول‌های جدید =====
    require_once __DIR__ . '/webhooks.php';        // Webhook‌های شخصی
    require_once __DIR__ . '/reports.php';         // گزارش SLA
    require_once __DIR__ . '/queue.php';           // صف کارها
    require_once __DIR__ . '/anomaly.php';         // تشخیص ناهنجاری
    require_once __DIR__ . '/integrations.php';    // Slack/Discord
    require_once __DIR__ . '/import_export.php';   // Export/Import
    require_once __DIR__ . '/analytics.php';       // Analytics پیشرفته
    require_once __DIR__ . '/affiliate.php';       // برنامهٔ همکاری
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

/**
 * نرمال‌سازی آدرس پایه: scheme + بدون اسلش انتهایی.
 * ورودی خام کاربر ممکن است «example.com/x» یا «https://example.com/x/» باشد.
 */
function normalizeBaseUrl(string $raw): string
{
    $u = trim($raw);
    if ($u === '') return '';
    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
    $u = rtrim($u, '/');
    $hostpart = preg_replace('#^https?://#i', '', $u) ?? '';
    if (trim($hostpart) === '') return '';
    return $u;
}

/**
 * آدرس پایهٔ مؤثر — «اولویت با تنظیم پنل مدیریت، بعد کانفیگ».
 *
 * چرا این تابع؟ قبلاً miniAppUrl()/statusUrl()/siteUrl() فقط config.php را
 * می‌خواندند، پس تغییر «آدرس اپلیکیشن» یا «دامنهٔ ربات» از پنل مدیریت هیچ
 * اثری نداشت (کاربر پیام «ذخیره شد» می‌گرفت ولی لینک‌ها همان قدیمی می‌ماندند).
 * حالا هرچه در جدول settings (set:base_url / set:domain) ذخیره شده بر
 * config.php مقدم است.
 */
function effectiveBaseUrl(): string
{
    $cfg = appConfig();
    $dbUrl = '';
    $dbDomain = '';
    try {
        $dbUrl = (string)(Db::get('base_url') ?? '');
        $dbDomain = (string)(Db::get('domain') ?? '');
    } catch (Throwable $e) {
        // اگر دیتابیس در دسترس نبود، به config.php بسنده می‌کنیم
    }
    $cfgDomain = trim((string)($cfg['domain'] ?? ''));
    $candidates = [
        $dbUrl,
        $dbDomain !== '' ? 'https://' . $dbDomain : '',
        (string)($cfg['base_url'] ?? ''),
        $cfgDomain !== '' ? 'https://' . $cfgDomain : '',
    ];
    foreach ($candidates as $c) {
        $n = normalizeBaseUrl($c);
        if ($n !== '') return $n;
    }
    return '';
}

/** آماده‌سازی دیتابیس + جدول‌ها */
function appBoot(bool $migrate = true): array
{
    $cfg = appConfig();
    Db::setConfig($cfg);

    // آدرس API تلگرام می‌تواند در کانفیگ عوض شود. در محیط تست این کار لازم است،
    // چون وب‌سرورِ داخلی PHP هم فرایندِ جداست و «حالت آفلاین» کلاینت روی آن
    // اثری ندارد؛ بدون این، هر پیامِ تست به api.telegram.org واقعی می‌رفت و
    // تا ۳۰ ثانیه معطل می‌ماند (و چون سرور تک‌کاره است، بقیهٔ تست‌ها را هم قفل می‌کرد).
    $tgBase = trim((string)($cfg['telegram_api_base'] ?? ''));
    if ($tgBase !== '') BotApi::setBaseUrl($tgBase);

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

/**
 * URL اپلیکیشن تلگرام (Mini App).
 *
 * منبعِ واحد: effectiveBaseUrl() که اولویت را به تنظیم پنل مدیریت
 * (جدول settings) می‌دهد و بعد سراغ config.php می‌رود.
 * قبلاً پنج «قدم» با ترتیب اشتباه بود: config.php قبل از دیتابیس خوانده
 * می‌شد، برای همین تغییر «آدرس اپلیکیشن» از پنل هیچ اثری نداشت.
 */
function miniAppUrl(): string
{
    $base = effectiveBaseUrl();
    if ($base !== '') return $base . '/webapp/index.html';

    // آخرین تلاش: حدس از روی خودِ درخواست (وقتی نه config و نه دیتابیس چیزی ندارند)
    $host = (string)($_SERVER['SERVER_NAME'] ?? '');
    if ($host !== '') {
        $port = (string)($_SERVER['SERVER_PORT'] ?? '');
        if ($port !== '' && $port !== '80' && $port !== '443') $host .= ':' . $port;
        // تلگرام برای WebApp فقط https را قبول می‌کند
        $guess = normalizeBaseUrl('https://' . $host);
        if ($guess !== '') return $guess . '/webapp/index.html';
    }

    return '';
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

/** جداکنندهٔ بصری برای پیام‌های چندبخشی */
function tgSep(): string
{
    return '━━━━━━━━━━━━━━━━━━━━━━';
}

/**
 * پاک‌سازی پیام خطای دیتابیس.
 *
 * پیامِ PDOException معمولاً کل کوئریِ شامل نام جدول‌ها، نام کاربرِ دیتابیس و
 * حتی بخشی از رمز را لو می‌دهد. این‌ها نباید به کاربرِ عادی برسد؛ برای ادمین
 * هم بهتر است خلاصه و قابل‌خواندن باشد. جزئیات کامل در لاگ می‌ماند.
 */
function sanitizeDbError(string $msg): string
{
    // رشته‌های اتصال (user:pass@host) ⇒ فقط نوع خطا
    if (preg_match('/SQLSTATE\[(\w+)\]/', $msg, $m)) {
        $code = $m[1];
        $head = match ($code) {
            '42S02', '42S22', '42S21', '23000' => 'ساختار دیتابیس ناسازگار است',
            'HY000'  => 'خطای عمومی دیتابیس',
            '28000'  => 'دسترسی دیتابیس رد شد',
            default => 'خطای دیتابیس',
        };
        // نام جدول/ستون برای ادمین مفید است، اما نه کوئری و نه رمز
        if (preg_match('/(?:Table|table) [\'"]?([\w]+)[\'"]?/', $msg, $t)) {
            return $head . ' — جدول/ستون: ' . $t[1];
        }
        return $head . ' (' . $code . ')';
    }
    // کوئری‌ها و رمزها را حذف کن
    $msg = preg_replace('/(password|passwd|pwd)\s*=\s*\S+/i', '$1=***', $msg) ?? $msg;
    $msg = preg_replace('/\bSELECT\b.*$/is', 'SQL', $msg) ?? $msg;
    return mb_substr($msg, 0, 200);
}
