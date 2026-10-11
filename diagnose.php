<?php
/**
 * ===== فایل تشخیص مشکل ربات =====
 * استفاده: http://localhost/uptime-bot-telegram/diagnose.php
 */

require_once __DIR__ . '/lib/bootstrap.php';

$cfg = appConfig();

echo "<pre style='font-family: monospace; background: #f5f5f5; padding: 20px; direction: rtl; text-align: right;'>";
echo "🤖 <b>تشخیص ربات مانیتورینگ</b>\n\n";

// ===== ۱. اطلاعات ربات =====
echo "📋 <b>1. اطلاعات ربات:</b>\n";
echo "   Bot Token: " . substr($cfg['bot_token'], 0, 20) . "...\n";
echo "   Admin ID: " . $cfg['admin_id'] . "\n";
echo "   Bot Username: " . $cfg['bot_username'] . "\n";
echo "   Domain: " . $cfg['domain'] . "\n";
echo "   Base URL: " . $cfg['base_url'] . "\n\n";

// ===== ۲. تنظیمات دیتابیس =====
echo "📊 <b>2. تنظیمات دیتابیس:</b>\n";
echo "   Host: " . $cfg['db']['host'] . "\n";
echo "   Port: " . $cfg['db']['port'] . "\n";
echo "   Database: " . $cfg['db']['name'] . "\n";
echo "   User: " . $cfg['db']['user'] . "\n\n";

// ===== ۳. تست اتصال دیتابیس =====
echo "🔌 <b>3. اتصال دیتابیس:</b>\n";
try {
    $pdo = Db::pdo();
    echo "   ✅ متصل است\n\n";
    
    // تعداد جداول
    $tableCount = (int)Db::val(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?",
        [$cfg['db']['name']]
    );
    echo "   📁 تعداد جداول: " . $tableCount . "\n\n";
    
} catch (Throwable $e) {
    echo "   ❌ خطا: " . $e->getMessage() . "\n\n";
    die("مشکل دیتابیس رفع کن و دوباره تلاش کن.");
}

// ===== ۴. بررسی جداول ضروری =====
echo "🗄️  <b>4. جداول ضروری:</b>\n";
$requiredTables = ['user', 'site', 'check_log', 'settings', 'incident'];

foreach ($requiredTables as $table) {
    try {
        $exists = (int)Db::val(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$cfg['db']['name'], $table]
        );
        
        if ($exists) {
            $rowCount = (int)Db::val("SELECT COUNT(*) FROM `$table`");
            echo "   ✅ `$table` ($rowCount rows)\n";
        } else {
            echo "   ❌ `$table` (موجود نیست)\n";
        }
    } catch (Throwable $e) {
        echo "   ⚠️  `$table` (خطا: " . $e->getMessage() . ")\n";
    }
}
echo "\n";

// ===== ۵. فایل‌های ضروری =====
echo "📁 <b>5. فایل‌های ضروری:</b>\n";
$files = [
    'index.php' => 'وب‌هوک تلگرام',
    'table.php' => 'ساخت جداول',
    'api.php' => 'JSON API',
    'status.php' => 'صفحهٔ وضعیت',
    'lib/bootstrap.php' => 'بوت‌استرپ',
    'lib/db.php' => 'دیتابیس',
    'lib/bot.php' => 'منطق ربات',
    'lib/webhooks.php' => 'Webhooks (جدید)',
    'lib/cache.php' => 'Cache (جدید)',
    'botapi.php' => 'Telegram API',
];

foreach ($files as $file => $desc) {
    $exists = file_exists(__DIR__ . '/' . $file) ? '✅' : '❌';
    echo "   $exists $file ($desc)\n";
}
echo "\n";

// ===== ۶. بررسی Permission =====
echo "🔐 <b>6. دسترسی‌های فایل:</b>\n";
$dirs = [
    'logs' => 'برای لاگ‌ها',
    'reports' => 'برای گزارش‌ها (جدید)',
];

foreach ($dirs as $dir => $desc) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    
    $isWritable = is_writable($path) ? '✅' : '❌';
    echo "   $isWritable /$dir ($desc)\n";
}
echo "\n";

// ===== ۷. تست تلگرام API =====
echo "🤖 <b>7. تست Telegram API:</b>\n";
try {
    $result = file_get_contents(
        'https://api.telegram.org/bot' . $cfg['bot_token'] . '/getMe',
        false,
        stream_context_create(['http' => ['timeout' => 5]])
    );
    
    $data = json_decode($result, true);
    
    if ($data['ok'] ?? false) {
        echo "   ✅ ربات متصل است\n";
        echo "   ID: " . $data['result']['id'] . "\n";
        echo "   Username: @" . $data['result']['username'] . "\n\n";
    } else {
        echo "   ❌ خطا: " . ($data['description'] ?? 'نامشخص') . "\n\n";
    }
} catch (Throwable $e) {
    echo "   ⚠️  اتصال اینترنت موجود نیست یا Telegram API در دسترس نیست\n";
    echo "   خطا: " . $e->getMessage() . "\n\n";
}

// ===== ۸. وضعیت کرون =====
echo "⏰ <b>8. وضعیت کرون:</b>\n";
try {
    $lastRound = Db::get('last_round_at');
    if ($lastRound) {
        $time = strtotime($lastRound);
        $ago = time() - $time;
        echo "   ✅ آخرین چک: " . ($ago < 60 ? "$ago ثانیه پیش" : (intdiv($ago, 60) . " دقیقه پیش")) . "\n";
    } else {
        echo "   ⚠️  هنوز کرون اجرا نشده است\n";
    }
} catch (Throwable $e) {
    echo "   ⚠️  خطا: " . $e->getMessage() . "\n";
}
echo "\n";

// ===== ۹. تنظیمات =====
echo "⚙️  <b>9. تنظیمات:</b>\n";
try {
    $checkInterval = Db::get('check_interval', '20');
    $maxSites = Db::get('max_sites', '5');
    $pauseAll = Db::get('pause_all', '0');
    
    echo "   فاصلهٔ چک: " . $checkInterval . " ثانیه\n";
    echo "   سقف سایت: " . $maxSites . "\n";
    echo "   موتور: " . ($pauseAll == '0' ? '✅ فعال' : '❌ متوقف') . "\n";
} catch (Throwable $e) {
    echo "   ⚠️  خطا: " . $e->getMessage() . "\n";
}
echo "\n";

// ===== ۱۰. نتیجهٔ نهایی =====
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📝 <b>نتیجه:</b>\n\n";

$issues = [];

// بررسی دیتابیس
if ($tableCount < 10) {
    $issues[] = "❌ جداول ناکافی هستند. باید table.php را اجرا کنید.";
}

// بررسی فایل‌ها
$missingFiles = [];
foreach ($files as $file => $desc) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        $missingFiles[] = $file;
    }
}

if (!empty($missingFiles)) {
    $issues[] = "❌ فایل‌های گم: " . implode(", ", $missingFiles);
}

// بررسی فایل‌های جدید
$newFiles = ['lib/webhooks.php', 'lib/cache.php', 'lib/queue.php', 'lib/anomaly.php'];
$missingNew = array_filter($newFiles, fn($f) => !file_exists(__DIR__ . '/' . $f));

if (!empty($missingNew)) {
    $issues[] = "⚠️  فایل‌های جدید موجود نیستند (اختیاری): " . implode(", ", $missingNew);
}

if (empty($issues)) {
    echo "✅ <b>ربات آماده است!</b>\n\n";
    echo "اگر وب‌هوک تلگرام تنظیم شده باشد، ربات باید جواب دهد.\n\n";
    echo "برای تست:\n";
    echo "1️⃣  /start را به ربات بفرستید\n";
    echo "2️⃣  باید پیام خوشامدگویی دریافت کنید\n";
    echo "3️⃣  اگر نه، بررسی کنید:\n";
    echo "   • آیا Webhook URL صحیح تنظیم شده است؟\n";
    echo "   • آیا کرون اجرا می‌شود؟\n";
    echo "   • آیا فایل logs/uptime.log لاگ ثبت می‌کند؟\n";
} else {
    echo "مشکلات:\n";
    foreach ($issues as $issue) {
        echo "   " . $issue . "\n";
    }
    echo "\n💡 <b>راه‌حل‌ها:</b>\n";
    echo "1️⃣  اگر جداول نیستند:\n";
    echo "    php table.php\n";
    echo "\n2️⃣  برای نصب ویژگی‌های جدید:\n";
    echo "    php cron/install_features.php\n";
    echo "\n3️⃣  تنظیم وب‌هوک تلگرام:\n";
    echo "    https://api.telegram.org/bot{TOKEN}/setWebhook?url=" . urlencode($cfg['base_url'] . '/index.php') . "\n";
}

echo "</pre>";
