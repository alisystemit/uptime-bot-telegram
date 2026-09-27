<?php
/**
 * ===== وبهوک تلگرام — نقطهٔ ورود ربات مانیتورینگ سرور =====
 *
 * احراز هویت: هدر X-Telegram-Bot-Api-Secret-Token که تلگرام هنگام setWebhook
 * با secret_token ارسال می‌کند (فرمول: sha256(token . '_uptime_webhook_secret')).
 * برای تست دستی، پارامتر ?secret= هم پذیرفته می‌شود.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
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
    $bot = new Bot($cfg);
    $bot->handle($update);
} catch (Throwable $e) {
    uptimeLog('error', 'handle failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    // 500 ⇒ تلگرام همان آپدیت را دوباره می‌فرستد
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'processing']);
    exit;
}

// ---------- 6) علامت‌گذاری فقط پس از موفقیت ----------
if ($updateId !== null) {
    try {
        Db::q('INSERT IGNORE INTO `seen_update` (`update_id`,`ts`) VALUES (?,NOW())', [$updateId]);
        // نگه‌داشتن جدول کوچک
        if (random_int(1, 50) === 1) Db::exec('DELETE FROM `seen_update` WHERE `ts` < DATE_SUB(NOW(), INTERVAL 3 DAY)');
    } catch (Throwable $e) { /* لاگ نکن تا هر آپدیت خطای لاگ ندهد */ }
}

echo json_encode(['ok' => true]);
