<?php
/**
 * ===== تست ماژول رنکینگ (lib/ranking.php) =====
 *
 * چرا تست جدا لازم دارد: این ماژول «پول» رفتاریِ ربات است — اگر idempotent
 * نباشد، هر راند چک امتیازِ تکراری توزیع می‌کند و جدول امتیاز بی‌اعتبار
 * می‌شود. اینجا هم منطقِ خالص (سطوح/پیشرفت) و هم رفتارِ دیتابیسی‌اش سنجیده
 * می‌شود.
 *
 * پیش‌نیاز: یک دیتابیس خالیِ تست + UPTIME_CONFIG
 * اجرا:     UPTIME_CONFIG=… php tests/ranking_test.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__) . '/lib/bootstrap.php';
appBoot();

$passed = 0;
$failed = 0;

function ok(string $name, bool $cond, string $info = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "✅ {$name}\n";
        $passed++;
    } else {
        echo "❌ {$name}" . ($info !== '' ? "  ← {$info}" : '') . "\n";
        $failed++;
    }
}

function section(string $t): void
{
    echo "\n== {$t} ==\n";
}

// ---------------------------------------------------------------- آماده‌سازی

section('آماده‌سازی');

// کاربر آزمایشی — ستون‌ها باید از قبل توسط boot()/syncColumns ساخته شده باشند
Db::q("INSERT INTO `user` (`id`,`name`,`username`,`notify`,`access`,`user_rank`,`user_points`,`site_uptime_count`)
       VALUES (?,?,?,?,?,?,?,?) 
       ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)",
    [990001, 'کاربر تست رنکینگ', 'rank_test_user', 1, 1, 1, 0, 0]);
$uid = 990001;

Db::q("INSERT INTO `site` (`user_id`,`type`,`target`,`label`,`status`,`paused`)
       VALUES (?,?,?,?,?,0) ON DUPLICATE KEY UPDATE `label` = VALUES(`label`)",
    [$uid, 'http', 'https://example.com', 'سایت تست رنکینگ', 'up']);
$siteId = (int)Db::val('SELECT `id` FROM `site` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1', [$uid]);
ok('کاربر و سایت آزمایشی ساخته شد', $siteId > 0, 'siteId=' . $siteId);

// صفرکردن وضعیت اولیه
Ranking::reset($uid);
$row = Db::one('SELECT `user_points`,`user_rank`,`site_uptime_count` FROM `user` WHERE `id` = ?', [$uid]);
ok('reset امتیاز را صفر کرد', (int)$row['user_points'] === 0, json_encode($row));
ok('reset سطح را ۱ کرد', (int)$row['user_rank'] === 1);

// ---------------------------------------------------------------- سطوح (خالص)

section('محاسبهٔ سطح و پیشرفت');

$cases = [
    [0, 1], [1, 1], [99, 1],
    [100, 2], [199, 2],
    [900, 10], [999, 10], [1000, 10], [99999, 10],
];
foreach ($cases as [$pts, $lvl]) {
    ok("سطحِ امتیاز {$pts} = {$lvl}", Ranking::level($pts) === (int)$lvl, (string)Ranking::level($pts));
}
ok('امتیاز منفی سطحش ۱ می‌ماند', Ranking::level(-50) === 1, (string)Ranking::level(-50));

ok('پیشرفت در سطح ۱ با امتیاز ۰ = ۱۰۰', Ranking::pointsToNext(0, 1) === 100);
ok('پیشرفت در سطح ۱ با امتیاز ۳۰ = ۷۰', Ranking::pointsToNext(30, 1) === 70);
ok('پیشرفت در بالاترین سطح = ۰', Ranking::pointsToNext(1500, Ranking::MAX_LEVEL) === 0);

// ---------------------------------------------------------------- امتیاز روزانه

section('امتیاز روزانه (idempotent)');

$perDay = max(0, Db::getInt('points_per_day', 1));
$a1 = Ranking::awardDaily($uid);
ok("نخستین امتیازِ روزانه داده شد", $a1 === $perDay, "داده={$a1} انتظار={$perDay}");

$a2 = Ranking::awardDaily($uid);
ok('دومین فراخوانی در همان روز چیزی نمی‌دهد', $a2 === 0, "داده={$a2}");

$a3 = Ranking::awardDaily($uid);
ok('سومین فراخوانی هم چیزی نمی‌دهد', $a3 === 0, "داده={$a3}");

$pts = (int)Db::val('SELECT `user_points` FROM `user` WHERE `id` = ?', [$uid]);
ok('امتیاز فقط یک‌بار اضافه شد (نه سه بار)', $pts === $perDay, "امتیاز={$pts}");

$evD = (int)Db::val("SELECT COUNT(*) FROM `events` WHERE `user_id` = ? AND `kind` = 'rank_daily'", [$uid]);
ok('رویداد روزانه فقط یک‌بار ثبت شد', $evD === 1, "رویداد={$evD}");

// onCheck با ok=false نباید چیزی بدهد
$before = $pts;
Ranking::onCheck(['id' => $siteId, 'user_id' => $uid, 'status' => 'down'], false);
$after = (int)Db::val('SELECT `user_points` FROM `user` WHERE `id` = ?', [$uid]);
ok('onCheck با چکِ ناموفق امتیازی نمی‌دهد', $after === $before, "{$before} → {$after}");

// ---------------------------------------------------------------- پاداش ساعتی

section('پاداش آپتایم (idempotent)');

$perHour = max(0, Db::getInt('points_per_uptime_hour', 5));
$siteRow = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$siteId]);
$beforePts = (int)Db::val('SELECT `user_points` FROM `user` WHERE `id` = ?', [$uid]);

$u1 = Ranking::awardUptime($siteRow);
ok("نخستین پاداش ساعتی داده شد", $u1 === $perHour, "داده={$u1} انتظار={$perHour}");

$u2 = Ranking::awardUptime($siteRow);
ok('دومین پاداش در همان ساعت صفر است', $u2 === 0, "داده={$u2}");

Ranking::onCheck($siteRow, true);
$u3 = Ranking::awardUptime($siteRow);
ok('onCheck هم در همان ساعت چیزی اضافه نمی‌کند', $u3 === 0, "داده={$u3}");

$nowPts = (int)Db::val('SELECT `user_points` FROM `user` WHERE `id` = ?', [$uid]);
ok('جمع امتیاز = روزانه + یک پاداش ساعتی',
    $nowPts === $beforePts + $perHour, "{$beforePts}+{$perHour} ≠ {$nowPts}");

$uptime = (int)Db::val('SELECT `site_uptime_count` FROM `user` WHERE `id` = ?', [$uid]);
ok('شمارندهٔ ساعت آپتایم فقط ۱ واحد رفت', $uptime === 1, "شمارنده={$uptime}");

// ---------------------------------------------------------------- سطح در همان UPDATE

section('بازمحاسبهٔ سطح');

Ranking::reset($uid);
Ranking::grant($uid, 250, 'تست سطح');
$row = Db::one('SELECT `user_points`,`user_rank` FROM `user` WHERE `id` = ?', [$uid]);
ok('grant امتیاز ۲۵۰ ثبت شد', (int)$row['user_points'] === 250, json_encode($row));
ok('سطح هم در همان UPDATE به ۳ رسید', (int)$row['user_rank'] === 3, 'سطح=' . $row['user_rank']);

Ranking::grant($uid, -1000, 'تست کاهش');
$row = Db::one('SELECT `user_points`,`user_rank` FROM `user` WHERE `id` = ?', [$uid]);
ok('امتیاز منفی زیر صفر نمی‌رود', (int)$row['user_points'] === 0, 'امتیاز=' . $row['user_points']);
ok('سطح بعد از کاهش دوباره ۱ شد', (int)$row['user_rank'] === 1, 'سطح=' . $row['user_rank']);

ok('grant روی کاربرِ ناموجود ناموفق است', Ranking::grant(999999999, 10) === false);

// ---------------------------------------------------------------- نماها

section('نماها و متن‌ها');

ok('leaderboardText رشته است', is_string(Ranking::leaderboardText(5, false, $uid)));
ok('leaderboardText حاوی «جدول امتیاز» است', str_contains(Ranking::leaderboardText(5), 'جدول امتیاز'));
ok('leaderboardText برای مدیر دستور نشان می‌دهد', str_contains(Ranking::leaderboardText(5, true), '/rankgrant'));
ok('leaderboardText برای غیرمدیر دستور نشان نمی‌دهد', !str_contains(Ranking::leaderboardText(5, false), '/rankgrant'));
ok('مدال رتبهٔ ۱ طلایی است', Ranking::medal(1) === '🥇');
ok('مدال رتبهٔ ۹ خنثی است', Ranking::medal(9) === '▫️');

ok('howText رشته است', is_string(Ranking::howText()));
ok('cfgText رشته است', is_string(Ranking::cfgText()));
ok('benefits رشته است', is_string(Ranking::benefits(5)));

$menu = Ranking::menu(false);
ok('منوی عادی رشته است', is_string($menu));
ok('منوی عادی دکمهٔ مدیریتی ندارد', !str_contains($menu, 'rank:cfg'));
ok('منوی مدیر دکمهٔ تنظیمات دارد', str_contains(Ranking::menu(true), 'rank:cfg'));

// متن «رتبهٔ من» باید خطای دیتابیس ندهد
$u = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$uid]);
try {
    $me = Ranking::meText($u, $uid);
    ok('meText بدون استثنا ساخته شد', is_string($me) && $me !== '');
    ok('meText عنوان «رتبهٔ من» دارد', str_contains($me, 'رتبهٔ من'));
    ok('meText امتیاز روزانه را گزارش می‌کند', str_contains($me, 'امتیاز روزانه'));
    // نباید متنِ خارجیِ قاطی‌شدهٔ نسخهٔ قدیمی را داشته باشد.
    // با فرارِ \x{...} نوشته شده تا خودِ این کاراکترها در سورس نباشند
    // (وگرنه اسکنر tools/scan_text.php این خط را هم گزارش می‌کرد).
    ok('meText متن خارجیِ قدیمی ندارد', !preg_match('/[\x{00E1}\x{00ED}\x{00F3}\x{00FA}\x{00E9}]/u', $me));
} catch (Throwable $e) {
    ok('meText بدون استثنا ساخته شد', false, $e->getMessage());
}

// ---------------------------------------------------------------- کش تنظیمات

section('کشِ تنظیمات');

Db::set('rank_test_key', 'اول');
ok('get بعد از set مقدار تازه را می‌دهد', Db::get('rank_test_key') === 'اول');
Db::set('rank_test_key', 'دوم');
ok('setِ دوم از کشِ کهنه رد نمی‌شود', Db::get('rank_test_key') === 'دوم', (string)Db::get('rank_test_key'));
ok('getInt کلیدِ ناموجود مقدار پیش‌فرض می‌دهد', Db::getInt('rank_missing_key', 7) === 7);
ok('getBool کلیدِ ناموجود مقدار پیش‌فرض می‌دهد', Db::getBool('rank_missing_key', true) === true);

// ---------------------------------------------------------------- بازنشانی کامل

section('reset کامل');

// اول امتیازی بگذار تا reset واقعاً کاری برای انجام‌دادن داشته باشد؛
// وگرنه rowCount صفر است و «هیچ سطری تغییر نکرد» با «reset خراب است» قاطی می‌شود.
Ranking::grant($uid, 75, 'آماده‌سازی reset');
$beforeReset = (int)Db::val('SELECT `user_points` FROM `user` WHERE `id` = ?', [$uid]);
ok('امتیاز قبل از reset صفر نبود', $beforeReset > 0, "امتیاز={$beforeReset}");

$n = Ranking::reset();
ok('reset کلی تعداد کاربرانِ تغییرکرده را گزارش می‌کند', $n > 0, "تعداد={$n}");
$row = Db::one('SELECT `user_points`,`user_rank` FROM `user` WHERE `id` = ?', [$uid]);
ok('امتیاز کاربر تست بعد از reset کلی صفر شد', (int)$row['user_points'] === 0);
ok('سطح کاربر تست بعد از reset کلی ۱ شد', (int)$row['user_rank'] === 1);

$n2 = Ranking::reset();
ok('resetِ دوم وقتی همه صفرند چیزی تغییر نمی‌دهد', $n2 === 0, "تعداد={$n2}");

$leftover = (int)Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` LIKE 'rank\\_day\\_%' OR `k` LIKE 'rank\\_up\\_%'");
ok('کلیدهای جایزه هم پاک شدند', $leftover === 0, "باقی‌مانده={$leftover}");

// ---------------------------------------------------------------- مهاجرتِ چندباره

section('مهاجرتِ امن');

try {
    Db::migrate();   // دومین بار روی همان دیتابیس
    ok('migrate دومین بار هم بدون استثنا اجرا شد', true);
} catch (Throwable $e) {
    ok('migrate دومین بار هم بدون استثنا اجرا شد', false, $e->getMessage());
}

try {
    Db::boot();
    ok('boot دومین بار هم بدون استثنا اجرا شد', true);
} catch (Throwable $e) {
    ok('boot دومین بار هم بدون استثنا اجرا شد', false, $e->getMessage());
}

$hasRank = Db::hasColumn('user', 'user_points');
ok('ستون‌های رنکینگ بعد از migrate سر جایشان‌اند', $hasRank);

// ---------------------------------------------------------------- پاک‌سازی

Db::q('DELETE FROM `site` WHERE `user_id` = ?', [$uid]);
Db::q('DELETE FROM `events` WHERE `user_id` = ?', [$uid]);
Db::q('DELETE FROM `user` WHERE `id` = ?', [$uid]);
Db::q("DELETE FROM `settings` WHERE `k` LIKE 'rank\\_%' OR `k` = 'rank_test_key'");

echo "\n==== نتیجه: {$passed} موفق، {$failed} ناموفق ====\n";
exit($failed === 0 ? 0 : 1);
