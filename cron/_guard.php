<?php
/**
 * ===== گارد ورود اسکریپت‌های کرون مانیتورینگ =====
 *
 *  * اجرای CLI (crontab با php یا cron_dispatcher) همیشه مجاز است.
 *  * دسترسی HTTP فقط با secret مشتق‌شده از توکن ربات مجاز است:
 *    https://domain/bots/<slug>/cron/checker.php?secret=sha256(token + "_uptime_cron_secret")
 *
 * نکته: توکن را از روی متن config.php می‌خوانیم (بدون require کردن آن) تا
 * موقع گارد، نه دیتابیس وصل شود و نه کلاس‌ها بارگذاری شوند.
 */

// ۱) مسیر کاری را به پوشه cron ببر تا require_once های نسبی درست حل شوند
chdir(__DIR__);

// ۲) از CLI کاری به گارد نداریم
if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    return;
}

// ۳) توکن ربات را بدون اجرای کانفیگ بخوان
$uptimeGuardCfg  = dirname(__DIR__) . '/config.php';
$uptimeGuardRaw  = is_readable($uptimeGuardCfg) ? (string)@file_get_contents($uptimeGuardCfg) : '';
$uptimeGuardTok  = '';
if (preg_match('/[\'"]bot_token[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $uptimeGuardRaw, $uptimeGuardM)) {
    $uptimeGuardTok = $uptimeGuardM[1];
}
$uptimeGuardSecret = ($uptimeGuardTok !== '' && $uptimeGuardTok !== '{BOT_TOKEN}')
    ? hash('sha256', $uptimeGuardTok . '_uptime_cron_secret')
    : '';
$uptimeGuardProvided = isset($_GET['secret']) && is_string($_GET['secret']) ? $_GET['secret'] : '';

if ($uptimeGuardSecret === '' || $uptimeGuardProvided === '' || !hash_equals($uptimeGuardSecret, $uptimeGuardProvided)) {
    http_response_code(403);
    exit('Forbidden');
}
