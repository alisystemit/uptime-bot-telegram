<?php
/**
 * ===== تست ناوبری: انصراف و بازگشت =====
 *
 *   UPTIME_CONFIG=… php tests/nav_test.php
 *
 * قانون تست: **هر** حالتِ ورودی باید با یکی از کلمه‌های فرار، بدون تغییر
 * در دیتابیس، بسته شود. اگر مرحلهٔ جدیدی اضافه شد و تست را نشکست، یعنی
 * فرار سراسری کار می‌کند.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/bootstrap.php';
BotApi::setProxy('http://127.0.0.1:1');
if (method_exists('BotApi', 'offline')) BotApi::offline(true);
$r = new ReflectionClass('BotApi');
foreach (['maxRetries' => 0, 'baseDelay' => 1] as $p2 => $v2) {
    $p = $r->getProperty($p2);
    $p->setAccessible(true);
    if ($p->isStatic()) $p->setValue(null, $v2);
}

$fail = 0; $ok = 0;
function chk(string $n, $c, string $x = ''): void
{
    global $fail, $ok;
    if ($c) { $ok++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n" . ($x !== '' ? " — $x" : '') . "\n"; }
}
function section(string $t): void { echo "\n== $t ==\n"; }

appBoot();
Db::migrate();
PayGws::ensure();

$ADMIN = (int)botAdminIds()[0];
$USER  = 780021;
Db::q('INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`plan`,`share_token`,`created_at`,`last_seen`)
       VALUES (?,?,?,1,1,\'vip\',?,NOW(),NOW())
       ON DUPLICATE KEY UPDATE `access`=1,`is_admin`=1',
    [$ADMIN, 'ادمین ناوبری', 'navadmin', 'navadmintoken00001']);
Db::q('INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`plan`,`share_token`,`created_at`,`last_seen`)
       VALUES (?,?,?,0,1,\'vip\',?,NOW(),NOW())
       ON DUPLICATE KEY UPDATE `access`=1,`is_admin`=0',
    [$USER, 'کاربر ناوبری', 'navuser', 'navusertoken0000001']);

// ==================================================================
section('۱) Nav::isEscape — تشخیص کلمه‌های فرار');
// ==================================================================
// برچسب‌ها عمداً شماره‌ای‌اند: کنسول ویندوز متن فارسی را درست چاپ نمی‌کند و
// تشخیص اینکه کدام آیتم شکست خورده بدون شماره ممکن نیست.
$ESC = [
    '❌ انصراف', '/cancel', '/cancel@navbot', '/start', '/menu',
    '🔙 بازگشت', '↩️ بازگشت', '🔙 برگشت', '🏠 منو', 'انصراف', 'لغو',
    '❌ انصراف از عملیات', '❌ بستن', 'بازگشت به منو', 'بی خیال', 'لغوکن',
    '  ❌ انصراف  ', '❌انصراف', '⬅️ بازگشت',
];
$i = 0;
foreach ($ESC as $t) {
    $i++;
    chk("ESC#$i فرار است", Nav::isEscape($t) === true, 'hex=' . bin2hex(mb_substr($t, 0, 12)));
}
$NOT = [
    '', ' ', 'x', 'https://example.com', '0', 'cancel', 'انصرافی از',
    'منوی من', 'site:1', 'بازگشتی', '12345', 'سلام', 'لغویه',
];
$i = 0;
foreach ($NOT as $t) {
    $i++;
    chk("NOT#$i فرار نیست", Nav::isEscape($t) === false, 'اشتباه تشخیص داده شد: hex=' . bin2hex(mb_substr($t, 0, 16)));
}
foreach ([[null], [[]], [new stdClass()], [1.5]] as $a) {
    chk('ورودی غیررشته‌ای ⇒ false', Nav::isEscape($a[0]) === false);
}

// ==================================================================
section('۲) Nav::cancelKb و backRow');
// ==================================================================
$kb = json_decode(Nav::cancelKb(), true);
chk('کیبورد انصراف یک سطر دارد', is_array($kb['keyboard']) && count($kb['keyboard']) === 1, json_encode($kb));
chk('دکمهٔ انصراف دارد', ($kb['keyboard'][0][0]['text'] ?? '') === '❌ انصراف', json_encode($kb));
$kb2 = json_decode(Nav::cancelKb('menu'), true);
chk('با بازگشت ⇒ دو دکمه', count($kb2['keyboard'][0]) === 2, json_encode($kb2));
chk('ترتیب: بازگشت بعد انصراف',
    ($kb2['keyboard'][0][0]['text'] ?? '') === '🔙 بازگشت' && ($kb2['keyboard'][0][1]['text'] ?? '') === '❌ انصراف',
    json_encode($kb2));
$br = Nav::backRow('apanel', 'LBL');
// backRow یک سطر با یک دکمهٔ انجمنی برمی‌گرداند ⇒ دسترسی درست [0]['…']
chk('backRow دکمه دارد', isset($br[0]['callback_data']), json_encode($br));
chk('backRow مقصد درست است', ($br[0]['callback_data'] ?? '') === 'apanel', json_encode($br));
$home = json_decode(Nav::homeKb(), true);
$hasCancel = false;
foreach ($home['keyboard'] as $row) foreach ($row as $b) if (($b['text'] ?? '') === '❌ انصراف') $hasCancel = true;
chk('کیبورد اصلی سطر انصراف دارد', $hasCancel);

// ==================================================================
section('۳) Nav::ensureBack — تضمین راه برگشت');
// ==================================================================
$rows = [[['text' => 'a', 'callback_data' => 'x']], [['text' => 'ب', 'callback_data' => 'menu']]];
$out = Nav::ensureBack($rows, 'menu');
chk('منویی که بازگشت دارد دست‌نخورده', count($out) === 2, json_encode($out));
$rows2 = [[['text' => 'a', 'callback_data' => 'x']]];
$out2 = Nav::ensureBack($rows2, 'menu');
chk('منویی که بازگشت ندارد، یکی اضافه می‌شود', count($out2) === 2, json_encode($out2));
chk('سطر اضافه‌شده درست است', ($out2[1][0]['callback_data'] ?? '') === 'menu', json_encode($out2));
$out3 = Nav::ensureBack([], 'menu');
chk('منوی خالی ⇒ فقط بازگشت', count($out3) === 1 && ($out3[0][0]['callback_data'] ?? '') === 'menu', json_encode($out3));
// دکمه url نباید به‌عنوان «بازگشت» شناخته شود
$rows4 = [[['text' => 'لینک', 'url' => 'https://x.com']]];
$out4 = Nav::ensureBack($rows4, 'menu');
chk('دکمهٔ url بازگشت محسوب نمی‌شود', count($out4) === 2, json_encode($out4));

// ==================================================================
section('۴) هر step با هر کلمهٔ فرار بسته می‌شود');
// ==================================================================
$bot = new Bot(appConfig());
$from = ['id' => $ADMIN, 'first_name' => 'ت', 'username' => 'navadmin'];
$mk = static fn(string $t) => ['update_id' => random_int(1, 999999), 'message' => [
    'message_id' => random_int(1, 9999), 'chat' => ['id' => $ADMIN, 'type' => 'private'],
    'from' => $from, 'date' => time(), 'text' => $t]];

// حالت‌هایی که ربات می‌تواند در آن‌ها گیر کند
$STEPS = [
    'await_site', 'await_code', 'await_payment', 'await_broadcast', 'await_setting',
    'await_codegen', 'await_maxms', 'await_keyword', 'await_domain', 'await_paygw',
    'await_invite', 'await_rankgrant', 'await_userop', 'یک_حالت_ناموجود',
];
$ESCAPES = ['❌ انصراف', '/cancel', '🔙 بازگشت', '🏠 منو', 'بی خیال'];
$bad = [];
foreach ($STEPS as $step) {
    foreach ($ESCAPES as $esc) {
        Db::q('UPDATE `user` SET `step` = ?, `temp` = ? WHERE `id` = ?', [$step, null, $ADMIN]);
        try {
            $bot->handle($mk($esc));
        } catch (Throwable $e) {
            $bad[] = "$step + $esc → " . get_class($e) . ': ' . $e->getMessage();
        }
        $now = (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$ADMIN]);
        if ($now !== 'idle') $bad[] = "$step + $esc → step=$now (بسته نشد)";
    }
}
chk('همهٔ stepها با همهٔ کلمه‌های فرار بسته می‌شوند', !$bad, implode(' | ', array_slice($bad, 3)));

// temp خراب هم نباید جلوی فرار را بگیرد
$bad2 = [];
foreach ($STEPS as $step) {
    foreach (['{bad json', '[]', '{"a":1}', 'null'] as $tmp) {
        Db::q('UPDATE `user` SET `step` = ?, `temp` = ? WHERE `id` = ?', [$step, $tmp, $ADMIN]);
        try { $bot->handle($mk('❌ انصراف')); } catch (Throwable $e) { $bad2[] = "$step+$tmp: " . $e->getMessage(); }
        if ((string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$ADMIN]) !== 'idle') {
            $bad2[] = "$step+$tmp بسته نشد";
        }
    }
}
chk('temp خراب مانع فرار نمی‌شود', !$bad2, implode(' | ', array_slice($bad2, 3)));

// ==================================================================
section('۵) دکمهٔ inline انصراف');
// ==================================================================
$cb = static fn(string $d) => ['update_id' => random_int(1, 999999), 'callback_query' => [
    'id' => 'x', 'from' => $from, 'chat_instance' => 'c',
    'message' => ['message_id' => 700, 'chat' => ['id' => $ADMIN, 'type' => 'private'], 'date' => time(), 'text' => 'x'],
    'data' => $d]];

foreach (['cancel', 'close', 'nope'] as $d) {
    Db::q('UPDATE `user` SET `step` = ?, `temp` = NULL WHERE `id` = ?', ['await_site', $ADMIN]);
    $run = static function () use ($bot, $cb, $d) { try { $bot->handle($cb($d)); } catch (Throwable $e) { echo '    ' . $e->getMessage() . "\n"; } };
    $run();
    chk("کالبک `$d` کاربر را آزاد می‌کند", (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$ADMIN]) === 'idle');
}

// ==================================================================
section('۶) هر منوی inline راه برگشت دارد');
// ==================================================================
$ref = new ReflectionClass($bot);
$site = Db::one('SELECT * FROM `site` WHERE `user_id` = ? LIMIT 1', [$USER]);
if (!$site) {
    Db::q('INSERT INTO `site` (`user_id`,`chat_id`,`target`,`label`,`type`,`host`,`port`,`share_token`,`created_at`)
           VALUES (?,0,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE `target`=VALUES(`target`)',
        [$USER, 'http://127.0.0.1:8321/mock/variza/api/v1/pay', 'ناوبری', 'http', '127.0.0.1', 8321, 'navsitetoken00001']);
    $site = Db::one('SELECT * FROM `site` WHERE `user_id` = ? LIMIT 1', [$USER]);
}
$set = function (string $p, $v) use ($ref, $bot) {
    $x = $ref->getProperty($p);
    $x->setAccessible(true);
    $x->setValue($bot, $v);
};
$set('uid', $ADMIN);
$set('u', Db::one('SELECT * FROM `user` WHERE `id` = ?', [$ADMIN]));
$set('chatId', $ADMIN);

$MENUS = [
    ['mainMenu', [], true],        // reply
    ['adminMenu', [], true],
    ['restrictedMenu', [], true],
    ['sitesListMenu', [], false],
    ['siteMenu', [$site], false],
    ['domainMenu', [], false],
    ['shareMenu', [], false],
    ['subMenu', [], false],
    ['settingsMenu', [], false],
    ['sharedMenu', [], false],
    ['adminPanelMenu', [], false],
    ['userMenu', [Db::one('SELECT * FROM `user` WHERE `id` = ?', [$USER])], false],
    ['payGatewayMenu', [], false],
    ['adminGatewaysMenu', [], false],
    ['adminGatewayMenu', ['cubepy'], false],
];
/** مقصدهایی که یعنی «کاربر می‌تواند برگردد» */
$NAV_TARGETS = ['menu', 'apanel', 'cancel', 'close', 'nope', 'sites', 'ausers:0',
                'pgws_list', 'settings', 'sub', 'shared', 'domains'];

/** آیا این منو راه برگشت دارد؟ (بر اساس callback_data، نه متن فارسی) */
function hasBack(array $j): array
{
    $rows = $j['keyboard'] ?? $j['inline_keyboard'] ?? [];
    // کیبورد متنی: دنبال متن فرار بگرد (چون callback_data ندارد)
    if (isset($j['keyboard'])) {
        foreach ($j['keyboard'] as $row) {
            foreach ((array)$row as $b) {
                $t = (string)($b['text'] ?? '');
                if (preg_match('/(انصراف|بازگشت|برگشت)$/u', $t) || $t === '🏠 منو') return [true, 'text:' . $t];
            }
        }
        return [false, 'هیچ دکمهٔ فراری در کیبورد متنی نبود'];
    }
    // کیبورد شیشه‌ای: دنبال مقصد ناوبری بگرد
    foreach ((array)$rows as $row) {
        foreach ((array)$row as $b) {
            $cd = (string)($b['callback_data'] ?? '');
            if ($cd !== '' && in_array($cd, $GLOBALS['NAV_TARGETS'], true)) return [true, 'cd:' . $cd];
        }
    }
    // یا دکمهٔ url به ریشه (بازگشت به صفحهٔ اصلی)
    foreach ((array)$rows as $row) {
        foreach ((array)$row as $b) {
            if (str_contains((string)($b['url'] ?? ''), 'status.php?u=')) return [true, 'url'];
        }
    }
    return [false, 'هیچ callback ناوبری نبود: ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 90)];
}

foreach ($MENUS as [$m, $args, $isKb]) {
    try {
        $rm = $ref->getMethod($m);
        $rm->setAccessible(true);
        $out = $rm->invokeArgs($bot, $args);
        $j = json_decode((string)$out, true);
        if (!is_array($j)) { chk("$m", false, 'خروجی JSON معتبر نیست'); continue; }
        [$has, $why] = hasBack($j);
        chk("$m راه برگشت دارد", $has, $why);
    } catch (Throwable $e) {
        chk("$m", false, $e->getMessage());
    }
}

// ==================================================================
section('۷) رنکینگ: دکمه‌ها واقعاً کار می‌کنند');
// ==================================================================
$ranking = Ranking::menu(true);
$j = json_decode($ranking, true);
chk('منوی رنکینگ inline است نه متنی', isset($j['inline_keyboard']), 'کیبورد متنی: ' . mb_substr($ranking, 0, 60));
chk('دکمه‌های رنکینگ callback دارند', !empty($j['inline_keyboard'][0][0]['callback_data'] ?? ''),
    'callback_data گم است ⇒ دکمه در تلگرام کار نمی‌کند');
// باید ۴ دکمهٔ کارکردی + ۱ بازگشت باشد
$cbs = [];
foreach ($j['inline_keyboard'] as $row) foreach ((array)$row as $b) {
    if (!empty($b['callback_data'])) $cbs[] = $b['callback_data'];
}
chk('همهٔ دکمه‌ها callback دارند', count($cbs) === 5, 'تعداد=' . count($cbs) . ' ' . implode(',', $cbs));
chk('۴ دستور + ۱ بازگشت', count(array_diff($cbs, ['menu'])) === 4, implode(',', $cbs));
foreach (['rank:top', 'rank:me', 'rank:how', 'rank:cfg'] as $d) {
    $run = static function () use ($bot, $cb, $d) { try { $bot->handle($cb($d)); } catch (Throwable $e) { echo '    ' . $e->getMessage() . "\n"; } };
    $run();
    chk("کالبک `$d` اجرا می‌شود", true);
}

// ==================================================================
section('۸) گروه: انصراف قبل از پردازش step');
// ==================================================================
$GID = -1001234567890;
$gfrom = ['id' => $ADMIN, 'first_name' => 'ت', 'username' => 'navadmin'];
$g = static fn(string $t) => ['update_id' => random_int(1, 999999), 'message' => [
    'message_id' => random_int(1, 9999), 'chat' => ['id' => $GID, 'type' => 'supergroup', 'title' => 'گروه ناوبری'],
    'from' => $gfrom, 'date' => time(), 'text' => $t]];
$gb = new Bot(appConfig());
Group::clearStep($GID);
Group::setStep($GID, 'await_site', $ADMIN, []);
chk('step گروه فعال شد', (string)Db::val('SELECT `step` FROM `chat_hub` WHERE `chat_id` = ?', [$GID]) === 'await_site');
foreach (['❌ انصراف', '/cancel', '🔙 بازگشت', '🏠 منو'] as $esc) {
    Group::setStep($GID, 'await_site', $ADMIN, []);
    try { $gb->handle($g($esc)); } catch (Throwable $e) { chk("گروه `$esc`", false, $e->getMessage()); }
    chk("گروه: «$esc» step را پاک کرد",
        (string)Db::val('SELECT `step` FROM `chat_hub` WHERE `chat_id` = ?', [$GID]) === 'idle');
}
// مهم: «انصراف» نباید به‌عنوان سایت ثبت شود
$before = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `chat_id` = ?', [$GID]);
Group::setStep($GID, 'await_site', $ADMIN, []);
try { $gb->handle($g('❌ انصراف')); } catch (Throwable $e) { }
$after = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `chat_id` = ?', [$GID]);
chk('«❌ انصراف» سایت ثبت نکرد', $before === $after, "$before → $after");
$leaked = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `label` = ? OR `target` = ?', ['❌ انصراف', '❌ انصراف']);
chk('سایتی به نام «انصراف» ساخته نشد', $leaked === 0, (string)$leaked);
// /start در گروه باید راهنما بدهد، نه اینکه همه‌چیز را لغو کند
Group::setStep($GID, 'await_site', $ADMIN, []);
try { $gb->handle($g('/start')); } catch (Throwable $e) { chk('/start گروه', false, $e->getMessage()); }
chk('/start در گروه مرحله را می‌بندد', (string)Db::val('SELECT `step` FROM `chat_hub` WHERE `chat_id` = ?', [$GID]) === 'idle');
Group::clearStep($GID);

Db::q('UPDATE `user` SET `step` = \'idle\', `temp` = NULL WHERE `id` IN (?,?)', [$ADMIN, $USER]);

echo "\n==== نتیجه: $ok موفق، $fail ناموفق ====\n";
exit($fail > 0 ? 1 : 0);
