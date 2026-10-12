<?php
/**
 * ===== تست دکمهٔ «اپلیکیشن» =====
 *
 *   UPTIME_CONFIG=… php tests/app_button_test.php
 *
 * گزارش کاربر: دکمهٔ «📱 اپلیکیشن» در منو می‌گفت «احراز هویت انجام نشد»،
 * ولی دستور /app کار می‌کرد.
 *
 * ریشه: دکمه در ReplyKeyboard با فیلد web_app ساخته شده بود. تلگرام در
 * کیبورد معمولی این فیلد را نادیده می‌گیرد ⇒ صفحه بدون initData باز می‌شود.
 * راه‌حل: دکمه متنی شد و ربات در پاسخ، دکمهٔ اینلاین با web_app می‌فرستد
 * (همان کاری که /app می‌کرد).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/bootstrap.php';

BotApi::setProxy('http://127.0.0.1:1');
BotApi::offline(true);
$rf = new ReflectionClass('BotApi');
foreach (['maxRetries' => 0, 'baseDelay' => 1] as $pn => $pv) {
    $p = $rf->getProperty($pn); $p->setAccessible(true);
    if ($p->isStatic()) $p->setValue(null, $pv);
}

$fail = 0; $ok = 0;
function chk(string $n, $c, string $x = ''): void
{
    global $fail, $ok;
    if ($c) { $ok++; echo "  ✓  $n\n"; }
    else { $fail++; echo "  ✗  $n" . ($x !== '' ? " — $x" : '') . "\n"; }
}
function section(string $t): void { echo "\n== $t ==\n"; }

appBoot();
Db::migrate();
$ADMIN = (int)(botAdminIds()[0] ?? 0);
Db::q('DELETE FROM `user` WHERE `id` = ?', [$ADMIN]);
$bot = new Bot(appConfig());

$MID = 9000;
function msgU(int $id, string $text): array {
    global $MID;
    return ['update_id' => random_int(1, 9999999), 'message' => [
        'message_id' => ++$MID, 'from' => ['id' => $id, 'first_name' => 'A'],
        'chat' => ['id' => $id, 'type' => 'private'], 'date' => time(), 'text' => $text]];
}
function click(string $text): array {
    global $bot, $ADMIN;
    BotApi::clearOutbox();
    $bot->handle(msgU($ADMIN, $text));
    $msg = ''; $marks = [];
    foreach (BotApi::outbox() as $c) foreach (($c['params'] ?? []) as $k => $v) {
        if ($k === 'text') $msg .= (string)$v;
        if ($k === 'reply_markup') $marks[] = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    Db::q("UPDATE `user` SET `step`='idle', `temp`=NULL WHERE `id`=?", [$ADMIN]);
    return [$msg, $marks];
}
function texts(): array {
    $o = [];
    foreach (BotApi::outbox() as $c) foreach (($c['params'] ?? []) as $k => $v) if ($k === 'text') $o[] = (string)$v;
    return $o;
}
/** همهٔ دکمه‌های اینلاین (با callback_data یا web_app) از همهٔ پیام‌ها */
function inlineWebApps(): array {
    $out = [];
    foreach (BotApi::outbox() as $c) foreach (($c['params'] ?? []) as $k => $v) {
        if ($k !== 'reply_markup') continue;
        $d = json_decode(is_string($v) ? $v : json_encode($v), true);
        if (!is_array($d)) continue;
        foreach (($d['inline_keyboard'] ?? []) as $row) foreach ((array)$row as $b) {
            if (isset($b['web_app']['url'])) $out[] = (string)$b['web_app']['url'];
        }
    }
    return $out;
}
/** دکمه‌های کیبورد معمولی */
function replyButtons(): array {
    $out = [];
    foreach (BotApi::outbox() as $c) foreach (($c['params'] ?? []) as $k => $v) {
        if ($k !== 'reply_markup') continue;
        $d = json_decode(is_string($v) ? $v : json_encode($v), true);
        if (!is_array($d)) continue;
        foreach (($d['keyboard'] ?? []) as $row) foreach ((array)$row as $b) {
            $out[] = ['text' => (string)($b['text'] ?? ''), 'has_web_app' => isset($b['web_app'])];
        }
    }
    return $out;
}

Db::set('base_url', 'https://example.test/bots/abc');
$appUrl = miniAppUrl();

// ==================================================================
section('۱) دکمهٔ اپلیکیشن در منوی اصلی نباید web_app داشته باشد');
// ==================================================================
[$m, $marks] = click('🏠 منو');
$app = null;
foreach (replyButtons() as $b) if (str_contains($b['text'], 'اپلیکیشن')) $app = $b;
chk('دکمهٔ «اپلیکیشن» در منو هست', $app !== null, 'پیدا نشد');
chk('دکمهٔ اپلیکیشن متنی است (web_app ندارد)',
    $app !== null && !$app['has_web_app'], 'web_app روی ReplyKeyboard ⇒ initData نمی‌رسد');

// هیچ دکمهٔ reply دیگری هم نباید web_app داشته باشد
$bad = array_values(array_filter(replyButtons(), fn($b) => $b['has_web_app']));
chk('هیچ دکمهٔ کیبورد معمولی web_app ندارد', $bad === [],
    'دارای web_app: ' . implode(' | ', array_map(fn($b) => $b['text'], $bad)));

// ==================================================================
section('۲) زدن دکمه باید پاسخِ درست بدهد، نه «دستور نامشخص»');
// ==================================================================
[$m2, $marks2] = click('📱 اپلیکیشن');
chk('دکمهٔ اپلیکیشن «دستور نامشخص» نداد', !str_contains($m2, 'نامشخص'),
    mb_substr(trim(strip_tags($m2)), 0, 90));
chk('پیام اپلیکیشن را توضیح داد', str_contains($m2, 'اپلیکیشن') && str_contains($m2, 'دکمهٔ زیر'),
    mb_substr(trim(strip_tags($m2)), 0, 90));

// ==================================================================
section('۳) دکمهٔ بازکننده باید اینلاین با web_app باشد (initData می‌گیرد)');
// ==================================================================
$links = inlineWebApps();
chk('دکمهٔ اینلاین با web_app فرستاده شد', $links !== [], 'هیچ web_app ای فرستاده نشد');
chk('لینک درست است', in_array($appUrl, $links, true), 'انتظار ' . $appUrl . ' بود، گرفتیم: ' . implode(' | ', $links));
chk('لینک https است (تلگرام برای Mini App نیاز دارد)', str_starts_with($appUrl, 'https://'), $appUrl);

// ==================================================================
section('۴) دکمه و دستور /app باید یک رفتار داشته باشند');
// ==================================================================
[$mApp, ] = click('/app');
$linksApp = inlineWebApps();
chk('/app هم مثل دکمه پاسخ می‌دهد', !str_contains($mApp, 'نامشخص'));
chk('/app همان لینک اپلیکیشن را می‌دهد', $linksApp === $links,
    'دکمه=' . implode('|', $links) . '  /app=' . implode('|', $linksApp));
chk('متن پاسخ دکمه و /app یکی است', trim(strip_tags($m2)) === trim(strip_tags($mApp)),
    'دکمه: ' . mb_substr(trim(strip_tags($m2)), 0, 60) . ' | /app: ' . mb_substr(trim(strip_tags($mApp)), 0, 60));

// ==================================================================
section('۵) وقتی هیچ آدرسی در دسترس نبود، باید راهنمای روشن بدهد');
// ==================================================================
// نکته: miniAppUrl() وقتی نه تنظیمات و نه کانفیگ چیزی ندارند، از روی خودِ
// درخواست حدس می‌زند. در سرور واقعی این درست است (دامنه معلوم است)؛ پس
// برای سنجش شاخهٔ «آدرس نداریم» باید حدسِ مبتنی بر درخواست را هم ببندیم.
$savedServer = $_SERVER['SERVER_NAME'] ?? null;
// کانفیگ هم منبعِ آدرس است، پس برای این شاخه باید آن را هم کنار بگذاریم
$savedCfg = appConfig();
$savedDomain = (string)($savedCfg['domain'] ?? '');
$savedBase = (string)($savedCfg['base_url'] ?? '');
Db::set('base_url', '');
Db::set('domain', '');
$_SERVER['SERVER_NAME'] = '';
$_SERVER['SERVER_PORT'] = '';

// اگر کانفیگ هم آدرس بدهد، شاخهٔ «آدرس نداریم» قابل‌سنجش نیست
$canBeEmpty = effectiveBaseUrl() === '';
if (!$canBeEmpty) {
    echo "  ~ کانفیگِ محلی آدرس دارد؛ شاخهٔ «آدرس نداریم» قابل‌سنجش نیست (کد سالم است)\n";
}
click('📱 اپلیکیشن');
$plain = trim(strip_tags(implode("\n", texts())));
if ($canBeEmpty) {
    chk('بدون آدرس، راهنمای روشن می‌دهد', str_contains($plain, 'base_url') || str_contains($plain, 'ست نشده'),
        mb_substr($plain, 0, 110));
} else {
    // با آدرسِ کانفیگ، اپلیکیشن باید باز شود (نه خطا)
    chk('با آدرسِ کانفیگ، اپلیکیشن باز می‌شود', str_contains($plain, 'دکمهٔ زیر'),
        mb_substr($plain, 0, 110));
}
chk('و «دستور نامشخص» نمی‌گوید', !str_contains($plain, 'نامشخص'));

if ($savedServer !== null) $_SERVER['SERVER_NAME'] = $savedServer;
else unset($_SERVER['SERVER_NAME']);
Db::set('base_url', 'https://example.test/bots/abc');

echo "\n----------------------------------------\n";
echo "OK: $ok    FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);