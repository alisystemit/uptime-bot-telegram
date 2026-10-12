<?php
/**
 * ===== تست «حروف هم‌شکل» =====
 *
 *   UPTIME_CONFIG=… php tests/homoglyph_test.php
 *
 * ریشهٔ یکی از باگ‌های واقعی پنل مدیریت: کلید نگاشت دکمه شامل حرفی بود که
 * ظاهراً «ت» فارسی است ولی در واقع «т» سیلیک بود (U+0442). دکمه با «ت» عربی
 * ساخته می‌شد، پس با کلید نگاشت برابر نمی‌شد و آن دکمه «دستور نامشخص» می‌داد
 * — یعنی دکمه‌ای که روی صفحه دیده می‌شود ولی هیچ کاری نمی‌کند.
 *
 * این تست سه کار می‌کند:
 *   ۱) کل سورس را برای حروف سیلیک/یونانیِ شبیه عربی-فارسی می‌گردد.
 *   ۲) هر برچسبی که کد می‌سازد باید به یک اکشن وصل باشد (و برعکس).
 *   ۳) برچسب‌های قدیمی (روی کیبوردِ از قبل نصب‌شدهٔ کاربر) هنوز کار کنند.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

$fail = 0; $ok = 0;
function chk(string $n, $c, string $x = ''): void
{
    global $fail, $ok;
    if ($c) { $ok++; echo "  [OK]   $n\n"; }
    else { $fail++; echo "  [FAIL] $n" . ($x !== '' ? " — $x" : '') . "\n"; }
}

$ZWNJ = "\u{200C}";   // نیم‌فاصله

// ---------------------------------------------------------------- ۱) اسکن سورس
echo "\n== 1) اسکن سورس برای حروف هم‌شکل ==\n";

$root = dirname(__DIR__);
$self = 'tests/homoglyph_test.php';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (strtolower($f->getExtension()) !== 'php') continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if (str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'node_modules/')) continue;
    $files[] = $rel;
}
sort($files);

/** حروفی که در نمایش «فارسی» به نظر می‌رسند ولی کدِ متفاوتی دارند. */
$lookalike = [
    "\u{0442}" => 'т U+0442 — شبیه «ت»',
    "\u{043F}" => 'п U+043F — شبیه «پ»',
    "\u{0438}" => 'и U+0438 — شبیه «ی»',
    "\u{0439}" => 'й U+0439 — شبیه «ی»',
    "\u{043A}" => 'к U+043A — شبیه «ک»',
    "\u{0443}" => 'у U+0443 — شبیه «و»',
    "\u{0433}" => 'г U+0433 — شبیه «ر»',
    "\u{0434}" => 'д U+0434 — شبیه «د»',
    "\u{0437}" => 'з U+0437 — شبیه «ز»',
    "\u{043B}" => 'л U+043B — شبیه «ل»',
    "\u{043C}" => 'м U+043C — شبیه «م»',
    "\u{043D}" => 'н U+043D — شبیه «ن»',
    "\u{043E}" => 'о U+043E — شبیه «ه»',
    "\u{0440}" => 'р U+0440 — شبیه «ر»',
    "\u{0441}" => 'с U+0441 — شبیه «س»',
    "\u{0435}" => 'е U+0435 — شبیه «ع»',
    "\u{0430}" => 'а U+0430 — شبیه «ا»',
    "\u{03BF}" => 'ο U+03BF — شبیه «ه»',
    "\u{03C1}" => 'ρ U+03C1 — شبیه «ر»',
    "\u{03B1}" => 'α U+03B1 — شبیه «ل»',
    "\u{03F2}" => 'ϲ U+03F2 — شبیه «س»',
    "\u{0455}" => 'ѕ U+0455 — شبیه «س»',
    "\u{0458}" => 'ј U+0458 — شبیه «ج»',
    "\u{0456}" => 'і U+0456 — شبیه «ی»',
    "\u{04BB}" => 'һ U+04BB — شبیه «ھ»',
];

$hits = [];
foreach ($files as $rel) {
    if ($rel === $self) continue;   // این تست خودش این حروف را نام می‌برد
    $src = @file_get_contents($root . '/' . $rel);
    if ($src === false) continue;
    foreach (explode("\n", $src) as $ln => $line) {
        foreach ($lookalike as $ch => $desc) {
            if (str_contains($line, $ch)) {
                $hits[] = "$rel:" . ($ln + 1) . "  [$desc]  " . trim(mb_substr($line, 0, 90));
            }
        }
    }
}
chk('سورس هیچ حرف هم‌شکلِ عربی ندارد', $hits === [], implode("\n        ", array_slice($hits, 0, 8)));

// ---------------------------------------------------------------- ۲) برچسب ⊆ نگاشت
echo "\n== 2) هر برچسبِ ساخته‌شده باید به یک اکشن وصل باشد ==\n";

require_once $root . '/lib/bootstrap.php';
BotApi::setProxy('http://127.0.0.1:1');
BotApi::offline(true);
$rp = new ReflectionClass('BotApi');
$pp = $rp->getProperty('maxRetries');
$pp->setAccessible(true);
if ($pp->isStatic()) $pp->setValue(null, 0);

appBoot();
Db::migrate();

$admin = (int)(botAdminIds()[0] ?? 0);
Db::q('DELETE FROM `user` WHERE `id` = ?', [$admin]);
$bot = new Bot(appConfig());

$rowsM = new ReflectionMethod('Bot', 'adminPanelRows');
$rowsM->setAccessible(true);
$actM = new ReflectionMethod('Bot', 'adminPanelAction');
$actM->setAccessible(true);

// برچسب‌هایی که در switch خودِ handleCommand هستند و لازم نیست در نگاشت باشند
$handledElsewhere = ['🏠 منو', '⚙️ پنل مدیریت', '📊 آمار مدیریتی', '👥 کاربران',
    '📣 همگانی', '💳 پرداخت‌ها', '⏰ کرون چک', '🎟 کدهای فعال‌سازی',
    '📊 آمار', '🏦 درگاه‌های پرداخت', '💳 پرداخت‌های در انتظار',
    '🧩 وضعیت افزونه‌ها', '💰 پرداخت همکاران'];

$labels = [];
foreach ($rowsM->invoke(null) as $r) foreach ($r as $b) $labels[] = (string)$b['text'];
$labels = array_values(array_unique($labels));

$unwired = [];
foreach ($labels as $label) {
    if (in_array($label, $handledElsewhere, true)) continue;
    if ($actM->invoke($bot, $label) === null) $unwired[] = $label;
}
chk('همهٔ برچسب‌های پنل به اکشن وصل‌اند (' . count($labels) . ' برچسب)',
    $unwired === [], implode(' | ', $unwired));

// ---------------------------------------------------------------- ۳) نگاشت ⊆ برچسب
echo "\n== 3) هر کلید نگاشتِ ثابت باید واقعاً روی کیبورد باشد ==\n";
$src = file_get_contents($root . '/lib/bot.php');
$i = strpos($src, 'static $map = [');
$seg = $i === false ? '' : substr($src, $i, 4200);
preg_match_all("/'([^']*)'\s*=>\s*'(set:[a-z_]+|pause|resume|codes|astats|pgws_list|cron|abroadcast|apays|sysstat|apayouts)'/u",
    $seg, $mm, PREG_SET_ORDER);
$mapKeys = [];
foreach ($mm as $one) $mapKeys[$one[1]] = $one[2];

// کلیدهای «قدیمی» عمداً نگه داشته شده‌اند تا پیام‌های درجا روی کیبورد کار کنند
$legacyOf = ['pause', 'resume'];
$orphans = [];
foreach ($mapKeys as $k => $act) {
    $isLegacy = in_array($act, $legacyOf, true)
        || str_contains($k, 'خاموش کردن') || str_contains($k, 'روشن کردن')
        || str_contains($k, '🔕 اعلان کندی') || str_contains($k, '🔔 اعلان کندی')
        || str_contains($k, 'حالت دسترسی') || str_contains($k, 'منشن در گروه')
        || str_contains($k, 'base_url');
    if ($isLegacy) continue;
    if (!in_array($k, $labels, true)) $orphans[] = "$k (→ $act)";
}
chk('کلید نگاشتِ یتیم وجود ندارد (' . count($mapKeys) . ' کلید)', $orphans === [], implode(' | ', $orphans));

// ---------------------------------------------------------------- ۴) برچسب‌های کهنه
echo "\n== 4) برچسب‌های قدیمی هنوز کار می‌کنند ==\n";
$legacyTests = [
    ['🔕 اعلان کندی', 'notify_slow'],
    ['🔔 اعلان کندی', 'notify_slow'],
    ['🔓 حالت دسترسی', 'access_mode'],
    ['🗣 منشن در گروه', 'group_mention'],
    ['🔕 خاموش کردن اعلان‌ها', 'notify'],
    ['🔔 روشن کردن اعلان‌ها', 'notify'],
];
foreach ($legacyTests as [$label, $key]) {
    $got = $actM->invoke($bot, $label);
    chk("«{$label}» → set:{$key}", $got === "set:$key", 'گرفت: ' . var_export($got, true));
}

echo "\n----------------------------------------\n";
echo "OK: $ok    FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);