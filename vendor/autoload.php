<?php
/**
 * ===== autoload سبک قالب uptime =====
 *
 * این قالب به هیچ پکیج Composer وابسته نیست (cURL و PDO هر دو بومی‌اند)،
 * پس این فایل فقط یک autoloader حداقلی برمی‌گرداند تا:
 *   - `Manager::checkBuildPrerequisites()` هنگام ساخت ربات پاس شود
 *   - `require 'vendor/autoload.php'` در هر endpoint خطایی ندهد
 *
 * اگر بعداً پکیجی اضافه کردید، همین فایل را با خروجی `composer dump-autoload`
 * جایگزین کنید (ساختار پوشه را حفظ کنید).
 */

if (!function_exists('uptime_mini_autoload')) {
    /**
     * بارگذاری تنبلِ کلاس‌های خودِ قالب از lib/ (برای آینده؛ الان همه از
     * bootstrap به‌صورت صریح require می‌شوند).
     */
    function uptime_mini_autoload(string $class): bool
    {
        $map = [
            'Db'      => __DIR__ . '/../lib/db.php',
            'BotApi'  => __DIR__ . '/../botapi.php',
            'Monitor' => __DIR__ . '/../lib/monitor.php',
            'Stats'   => __DIR__ . '/../lib/stats.php',
            'Bot'     => __DIR__ . '/../lib/bot.php',
        ];
        if (isset($map[$class]) && is_file($map[$class])) {
            require_once $map[$class];
            return true;
        }
        return false;
    }
    spl_autoload_register('uptime_mini_autoload');
}

// امضای سازگار با Composer: فراخواننده می‌تواند این را به عنوان loader نگه دارد.
return true;
