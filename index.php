<?php
/**
 * ===== وبهوک تلگرام — نقطهٔ ورود ربات مانیتورینگ سرور =====
 *
 * احراز هویت: هدر X-Telegram-Bot-Api-Secret-Token که تلگرام هنگام setWebhook
 * با secret_token ارسال می‌کند (فرمول: sha256(token . '_uptime_webhook_secret')).
 * برای تست دستی، پارامتر ?secret= هم پذیرفته می‌شود.
 */
// display_errors خاموش: پاسخِ وب‌هوک باید JSON خالص باشد. اگر PHP خطا را
// داخل بدنه چاپ کند، تلگرام یک HTML به‌جای JSON می‌گیرد و کل به‌روزرسانی
// بی‌اثر می‌شود. خطاها از راه لاگ گزارش می‌شوند، نه از راه صفحه.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/bootstrap.php';

$cfg = appConfig();

// ---------- 1) احراز هویت (fail-closed) ----------
$token = (string)($cfg['bot_token'] ?? '');
$expected = $token !== '' ? hash('sha256', $token . '_uptime_webhook_secret') : '';
$provided = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ($_GET['secret'] ?? ''));
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// ---------- 2) خواندن آپدیت ----------
$update = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($update) || !$update) {
    echo json_encode(['ok' => true]);
    exit;
}
$updateId = isset($update['update_id']) ? (int)$update['update_id'] : null;

// ---------- 3) دیتابیس ----------
try {
    appBoot();
} catch (Throwable $e) {
    uptimeLog('error', 'boot failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'database']);
    exit;
}

// ---------- 4) جلوگیری از پردازش تکراری ----------
if ($updateId !== null) {
    try {
        if ((int)Db::val('SELECT COUNT(*) FROM `seen_update` WHERE `update_id` = ?', [$updateId]) > 0) {
            echo json_encode(['ok' => true, 'duplicate' => true]);
            exit;
        }
    } catch (Throwable $e) { /* اگر جدول نبود، ادامه بده */ }
}

// ---------- 5) پردازش ----------
try {
    // نقطهٔ آزمونِ عمدی: فقط وقتی متغیر محیطیِ تست روشن باشد و متنِ پیام این
    // نشانه را داشته باشد. در محیط واقعی هرگز فعال نمی‌شود.
    if (getenv('UPTIME_TEST_CRASH') === '1'
        && str_starts_with((string)($update['message']['text'] ?? ''), "\x00CRASH_TEST\x00")) {
        // خطایی در مسیر dispatch (نه در گزارش): لایهٔ ایمنیِ Bot باید آن را بگیرد
        throw new RuntimeException('خطای آزمایشی از مسیر dispatch');
    }

    $bot = new Bot($cfg);
    $bot->handle($update);
} catch (Throwable $e) {
    // شبکهٔ ایمنی: Bot::handle خودش خطا را به کاربر گزارش می‌کند و برمی‌گردد،
    // پس رسیدن به این‌جا یعنی خودِ آن لایه هم شکست خده است.
    uptimeLog('error', 'handle failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    try {
        ErrorReport::handlerError(
            'index.php',
            (string)($update['message']['text'] ?? $update['callback_query']['data'] ?? ''),
            $e,
            (int)($update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? 0) ?: null
        );
    } catch (Throwable $ignored) {
        // حتی گزارش هم شکست خورد ⇒ فقط لاگ خام
    }
}

// ---------- 6) علامت‌گذاری (حتی بعد از خطا) ----------
// نکتهٔ کلیدی: علامت‌گذاری «همیشه» انجام می‌شود، نه فقط بعد از موفقیت.
// دلیل: کاربر پیامِ خطا را گرفته و می‌داند چه شد؛ اگر اینجا علامت نخورد،
// تلگرام همان آپدیت را بی‌نهایت دوباره می‌فرستد و کاربر پیامِ خطای تکراری
// می‌بیند (حلقهٔ آزاردهنده) و سرور هم زیر بار می‌رود.
// خطاهای گذرا (مشکل شبکهٔ دیتابیس) با دستور /reset قابل تکرارند.
if ($updateId !== null) {
    try {
        Db::q('INSERT IGNORE INTO `seen_update` (`update_id`,`ts`) VALUES (?,NOW())', [$updateId]);
        // نگه‌داشتن جدول کوچک
        if (random_int(1, 50) === 1) Db::exec('DELETE FROM `seen_update` WHERE `ts` < DATE_SUB(NOW(), INTERVAL 3 DAY)');
    } catch (Throwable $e) { /* لاگ نکن تا هر آپدیت خطای لاگ ندهد */ }
}

echo json_encode(['ok' => true]);
