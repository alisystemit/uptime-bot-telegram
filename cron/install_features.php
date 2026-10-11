<?php
/**
 * ===== نصب/به‌روزرسانی جدول‌های ویژگی‌های جدید =====
 *
 * CLI :  php cron/install_features.php
 * وب  :  .../cron/install_features.php?secret=<cron secret>
 *
 * مایگریشن‌ها idempotent هستند ⇒ اجرای دوباره بی‌خطر است.
 */

if (PHP_SAPI !== 'cli') {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
    $cfg = appConfig();
    $expected = hash('sha256', (string)($cfg['bot_token'] ?? '') . '_uptime_webhook_secret');
    $given = (string)($_GET['secret'] ?? ($_SERVER['HTTP_X_SECRET'] ?? ''));
    if ($given === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
$nl = $isCli ? "\n" : '<br>';

if (!$isCli) header('Content-Type: text/html; charset=utf-8');

echo $isCli ? "نصب ویژگی‌های جدید" : '<h2>نصب ویژگی‌های جدید</h2>';
echo $nl . $nl;

$cfg = appConfig();
$ok = true;

// ---------- ۱) اتصال ----------
try {
    appBoot();   // کانفیگ + مایگریشن کامل
    echo "✅ دیتابیس متصل و جدول‌ها ساخته/به‌روزرسانی شدند" . $nl;
} catch (Throwable $e) {
    echo "❌ خطای دیتابیس: " . htmlspecialchars($e->getMessage()) . $nl;
    exit(1);
}

// ---------- ۲) بررسی جدول‌های ویژگی‌ها ----------
$featureTables = ['webhooks', 'sla_reports', 'queue', 'integrations', 'affiliates', 'affiliate_earnings', 'affiliate_payouts'];
echo $nl . "جدول‌های ویژگی‌ها:" . $nl;
foreach ($featureTables as $t) {
    try {
        $n = (int)Db::val(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$cfg['db']['name'], $t]
        );
        if ($n) {
            echo "  ✅ $t" . $nl;
        } else {
            echo "  ❌ $t (ساخته نشد)" . $nl;
            $ok = false;
        }
    } catch (Throwable $e) {
        echo "  ❌ $t → " . htmlspecialchars($e->getMessage()) . $nl;
        $ok = false;
    }
}

// ---------- ۳) ستون‌های اضافه‌شده ----------
try {
    $n = (int)Db::val(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "webhooks" AND COLUMN_NAME = "secret"',
        [$cfg['db']['name']]
    );
    echo $nl . ($n ? "  ✅ ستون‌های امضای webhook موجود است" : "  ⚠️  ستون secret در webhooks نیست") . $nl;
} catch (Throwable $e) {
    // بی‌صدا
}

// ---------- ۴) پوشه‌های لازم ----------
echo $nl . "پوشه‌ها:" . $nl;
foreach (['logs' => 'لاگ‌ها', 'reports' => 'گزارش‌ها'] as $dir => $desc) {
    $path = UPTIME_ROOT . '/' . $dir;
    if (!is_dir($path)) @mkdir($path, 0755, true);
    $w = is_dir($path) && is_writable($path);
    echo "  " . ($w ? '✅' : '⚠️ ') . " /$dir ($desc)" . $nl;
    if (!$w) $ok = false;
}

// ---------- ۵) Redis (اختیاری) ----------
echo $nl . "کش Redis:" . $nl;
$st = Cache::status();
if (!empty($st['enabled'])) {
    echo "  ✅ متصل — " . ($st['used_memory'] ?? '?') . $nl;
} else {
    echo "  ⚪ تنظیم نشده یا در دسترس نیست (اختیاری — ربات بدون آن هم کار می‌کند)" . $nl;
}

// ---------- ۶) صف کارها ----------
try {
    $pending = Queue::count();
    echo $nl . "صف کارها: " . $pending . " کار در انتظار" . $nl;
} catch (Throwable $e) {
    echo $nl . "صف کارها: در دسترس نیست" . $nl;
}

// ---------- ۷) تنظیمات پیش‌فرض ----------
echo $nl . "تنظیمات:" . $nl;
$newSettings = [
    'webhook_enabled'    => '1',
    'sla_reports_enabled'=> '1',
    'analytics_enabled'  => '1',
    'affiliate_enabled'  => '1',
];
foreach ($newSettings as $k => $v) {
    try {
        Db::q('INSERT IGNORE INTO `settings` (`k`,`v`) VALUES (?,?)', [$k, $v]);
        echo "  ✅ $k" . $nl;
    } catch (Throwable $e) {
        echo "  ⚠️  $k → " . htmlspecialchars($e->getMessage()) . $nl;
    }
}

// ---------- نتیجه ----------
echo $nl . str_repeat('=', 30) . $nl;
if ($ok) {
    echo ($isCli ? "✅ " : "<b style='color:#34d399'>✅ ") . "نصب کامل شد" . ($isCli ? "" : "</b>") . $nl . $nl;
    echo "گام بعدی: کرون را تنظیم کنید" . $nl;
    if ($isCli) {
        echo "  php cron/checker.php --daemon" . $nl;
        echo "  یا: php cron/checker.php (هر دقیقه با crontab)" . $nl;
    }
} else {
    echo ($isCli ? "❌ " : "<b style='color:#f87171'>❌ ") . "چند مورد نیاز به بررسی دارد"
        . ($isCli ? "" : "</b>") . $nl;
}

exit($ok ? 0 : 1);