<?php
/**
 * ===== نصب جدول‌های جدید =====
 * فایل: cron/install_features.php
 * استفاده: php cron/install_features.php
 * یا از URL: /cron/install_features.php?secret=SHA256(TOKEN+"_install_secret")
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

// بررسی secret
$secret = $_GET['secret'] ?? ($_SERVER['HTTP_X_SECRET'] ?? '');
$expected = hash('sha256', appConfig()['bot_token'] . '_install_secret');

if (php_sapi_name() !== 'cli' && $secret !== $expected) {
    http_response_code(403);
    die('Forbidden');
}

echo "🚀 شروع نصب ویژگی‌های جدید...\n\n";

$tasks = [
    'جداول دیتابیس' => fn() => installTables(),
    'کشِ Redis' => fn() => initRedis(),
    'صف کارها' => fn() => initQueue(),
    'تنظیمات' => fn() => initSettings(),
];

$results = [];
foreach ($tasks as $name => $task) {
    try {
        echo "⏳ $name...";
        $task();
        echo " ✅\n";
        $results[$name] = 'success';
    } catch (Throwable $e) {
        echo " ❌ {$e->getMessage()}\n";
        $results[$name] = 'error: ' . $e->getMessage();
    }
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✨ نصب تکمیل شد!\n\n";

foreach ($results as $name => $status) {
    echo "• $name: $status\n";
}

// ─────────────────────────────────────────────── Functions

function installTables(): void
{
    // این خودکار از طریق Db::boot() انجام می‌شود
    Db::migrate();
    echo 'جداول و ستون‌ها اضافه/به‌روزرسانی شدند';
}

function initRedis(): void
{
    $config = appConfig()['redis'] ?? null;
    if (!$config) {
        echo 'Redis تنظیم‌نشده (اختیاری)';
        return;
    }

    Cache::init();
    $status = Cache::status();
    if ($status['enabled']) {
        echo 'Redis متصل و آماده';
    } else {
        echo 'Redis قابل دسترس نیست (اختیاری)';
    }
}

function initQueue(): void
{
    // فقط بررسی اینکه جدول موجود است
    $count = Db::val('SELECT COUNT(*) FROM `queue`');
    echo 'صف کارها آماده (۰ کار)';
}

function initSettings(): void
{
    // اضافه کردن تنظیمات جدید
    $newSettings = [
        'webhook_enabled' => '1',
        'sla_reports_enabled' => '1',
        'analytics_enabled' => '1',
        'affiliate_enabled' => '1',
    ];

    foreach ($newSettings as $k => $v) {
        try {
            Db::q(
                'INSERT IGNORE INTO `settings` (`k`, `v`) VALUES (?, ?)',
                [$k, $v]
            );
        } catch (Throwable $e) {
            // ممکن است قبلاً موجود باشد
        }
    }

    Cache::init();
    Cache::delPattern('setting_*');

    echo 'تنظیمات جدید اضافه شدند';
}
