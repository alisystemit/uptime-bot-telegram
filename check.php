<?php
/**
 * ===== تشخیص سریع مشکل ربات =====
 * استفاده: از مرورگر یا CLI
 */

// suppression خطاها برای نمایش تمیز
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/lib/bootstrap.php';

$cfg = appConfig();
$issues = [];
$success = [];

echo "\n════════════════════════════════════════\n";
echo "🤖 تشخیص ربات مانیتورینگ\n";
echo "════════════════════════════════════════\n\n";

// ===== ۱. Config =====
echo "1️⃣  اطلاعات Config:\n";
echo "   Bot Token: " . substr($cfg['bot_token'], 0, 15) . "...\n";
echo "   Admin ID: " . $cfg['admin_id'] . "\n";
echo "   Domain: " . $cfg['domain'] . "\n";
echo "   Base URL: " . $cfg['base_url'] . "\n\n";

// ===== ۲. دیتابیس =====
echo "2️⃣  تست دیتابیس:\n";
try {
    $pdo = Db::pdo();
    $pdo->query("SELECT 1");
    
    $count = (int)Db::val(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?",
        [$cfg['db']['name']]
    );
    
    echo "   ✅ دیتابیس متصل\n";
    echo "   📊 تعداد جداول: " . $count . "\n";
    $success[] = "db";
    
    if ($count < 5) {
        $issues[] = "جداول ناکافی - باید table.php اجرا شود";
    } else {
        $success[] = "tables";
    }
} catch (Throwable $e) {
    echo "   ❌ خطا: " . $e->getMessage() . "\n";
    $issues[] = "اتصال دیتابیس ناموفق";
}
echo "\n";

// ===== ۳. جداول ضروری =====
echo "3️⃣  جداول ضروری:\n";
$tables = ['user', 'site', 'check_log', 'settings'];
foreach ($tables as $t) {
    try {
        $exists = (int)Db::val(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$cfg['db']['name'], $t]
        );
        
        if ($exists) {
            echo "   ✅ $t\n";
            $success[] = $t;
        } else {
            echo "   ❌ $t (موجود نیست)\n";
            $issues[] = "جدول $t موجود نیست";
        }
    } catch (Throwable $e) {
        echo "   ⚠️  $t (خطا)\n";
    }
}
echo "\n";

// ===== ۴. فایل‌های مهم =====
echo "4️⃣  فایل‌های مهم:\n";
$files = ['index.php', 'api.php', 'status.php', 'table.php', 'lib/bot.php', 'lib/db.php'];
$missing = [];
foreach ($files as $f) {
    if (file_exists($f)) {
        echo "   ✅ $f\n";
    } else {
        echo "   ❌ $f\n";
        $missing[] = $f;
    }
}
if (empty($missing)) $success[] = "files";
echo "\n";

// ===== ۵. لاگ‌ها =====
echo "5️⃣  فایل‌های لاگ:\n";
$logFile = 'logs/uptime.log';
if (file_exists($logFile)) {
    $size = filesize($logFile);
    $lines = substr_count(file_get_contents($logFile), "\n");
    echo "   ✅ logs/uptime.log ($lines سطر)\n";
} else {
    echo "   ⚠️  logs/uptime.log (هنوز ساخته نشده)\n";
}
echo "\n";

// ===== ۶. وب‌هوک =====
echo "6️⃣  وب‌هوک تلگرام:\n";
$secret = hash('sha256', $cfg['bot_token'] . '_uptime_webhook_secret');
$webhookUrl = $cfg['base_url'] . '/index.php';
echo "   URL: " . $webhookUrl . "\n";
echo "   Secret: " . substr($secret, 0, 10) . "...\n";
echo "   💡 برای تنظیم:\n";
echo "      https://api.telegram.org/bot" . $cfg['bot_token'] . "/setWebhook?url=" . urlencode($webhookUrl) . "\n\n";

// ===== ۷. خلاصه =====
echo "════════════════════════════════════════\n";
echo "📊 خلاصه:\n\n";

if (empty($issues)) {
    echo "✅ <b>همه‌چیز آماده است!</b>\n\n";
    echo "بعدی:\n";
    echo "1. اطمینان حاصل کنید وب‌هوک تنظیم شده است\n";
    echo "2. /start را به ربات بفرستید\n";
    echo "3. اگر جواب ندهد، بررسی کنید:\n";
    echo "   - آیا کرون اجرا می‌شود؟ (cron/checker.php)\n";
    echo "   - آیا لاگ‌ها ثبت می‌شوند؟\n";
} else {
    echo "❌ مشکلات:\n";
    foreach ($issues as $i => $issue) {
        echo "   " . ($i + 1) . ". $issue\n";
    }
    echo "\n💡 راه‌حل‌ها:\n";
    echo "1. اگر جداول نیستند:\n";
    echo "   • برو به: https://froshbot.api-system.top/bots/paishin/table.php\n";
    echo "   • یا اجرا کن: php table.php\n\n";
    echo "2. اگر config خالی است:\n";
    echo "   • config.php را بررسی کن\n";
    echo "   • تمام {BOT_TOKEN} و غیره باید جایگزین شده باشند\n\n";
    echo "3. اگر دیتابیس متصل نیست:\n";
    echo "   • MySQL شروع شده؟\n";
    echo "   • اطلاعات config صحیح؟\n";
}

echo "\n════════════════════════════════════════\n";
