<?php
/**
 * ===== تست «تک‌تکِ دکمه‌ها» =====
 *
 *   UPTIME_CONFIG=… php tests/buttons_test.php
 *
 * هدف: هیچ دکمه‌ای نباید «بی‌کار» بماند. تست همهٔ دکمه‌های پنل مدیریت،
 * منوهای کاربر، منوهای افزونه و دکمه‌های متنی را **واقعاً فشار می‌دهد**
 * و برای هرکدام بررسی می‌کند:
 *   ۱) خطا/استثنا نداد
 *   ۲) پاسخی برگرداند (سکوت مطلق نبود)
 *   ۳) پاسخش «دستور نامشخص» نبود یعنی دکمه به جایی وصل نیست
 *   ۴) اگر دکمه یک مرحلهٔ ورودی باز می‌کند، از آن مرحله هم بتوان خارج شد
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/bootstrap.php';

BotApi::setProxy('http://127.0.0.1:1');
BotApi::offline(true);
$rf = new ReflectionClass('BotApi');
foreach (['maxRetries' => 0, 'baseDelay' => 1] as $pn => $pv) {
    $p = $rf->getProperty($pn);
    $p->setAccessible(true);
    if ($p->isStatic()) $p->setValue(null, $pv);
}

$fail = 0; $ok = 0;
$dead = [];
function chk(string $n, $c, string $x = ''): void
{
    global $fail, $ok;
    if ($c) { $ok++; echo "  ✓  $n\n"; }
    else { $fail++; echo "  ✗  $n" . ($x !== '' ? " — $x" : '') . "\n"; }
}
function section(string $t): void { echo "\n== $t ==\n"; }

$GLOBALS['OUT'] = [];
$GLOBALS['ERRORS'] = [];

function act(array $update): void
{
    global $bot;
    BotApi::clearOutbox();
    try { $bot->handle($update); }
    catch (Throwable $e) { $GLOBALS['ERRORS'][] = get_class($e) . ': ' . $e->getMessage(); }
    $GLOBALS['OUT'] = BotApi::outbox();
}
function blob(): string
{
    $s = '';
    foreach ($GLOBALS['OUT'] as $c) {
        foreach (($c['params'] ?? []) as $v) {
            if (is_string($v)) $s .= $v . "\n";
            elseif (is_array($v)) { $j = json_encode($v, JSON_UNESCAPED_UNICODE); if ($j !== false) $s .= $j . "\n"; }
            elseif (is_scalar($v)) $s .= (string)$v . "\n";
        }
    }
    return $s;
}
function has(string $n): bool { return str_contains(blob(), $n); }

/** همهٔ callback_dataهای یک پیام اخیر را بیرون می‌کشد */
function cbs(): array
{
    $out = [];
    foreach ($GLOBALS['OUT'] as $c) {
        foreach (($c['params'] ?? []) as $v) {
            if (!is_string($v) || !str_contains($v, '"callback_data"')) continue;
            $j = json_decode($v, true);
            if (!is_array($j)) continue;
            $rows = $j['inline_keyboard'] ?? [];
            foreach ($rows as $row) {
                foreach ((array)$row as $b) {
                    if (is_array($b) && isset($b['callback_data'])) $out[] = (string)$b['callback_data'];
                }
            }
        }
    }
    return array_values(array_unique($out));
}

$GLOBALS['MID'] = 9000;
function nid(): int { return ++$GLOBALS['MID']; }
function fromU(int $id): array
{
    return ['id' => $id, 'first_name' => 'Admin' . $id, 'username' => 'a' . $id, 'language_code' => 'fa'];
}
function msgU(int $id, string $text): array
{
    return ['update_id' => random_int(1, 999999), 'message' => [
        'message_id' => nid(), 'from' => fromU($id),
        'chat' => ['id' => $id, 'type' => 'private'], 'date' => time(), 'text' => $text]];
}
function cbU(int $id, string $data): array
{
    return ['update_id' => random_int(1, 999999), 'callback_query' => [
        'id' => 'q' . nid(), 'from' => fromU($id),
        'message' => ['message_id' => nid(), 'chat' => ['id' => $id, 'type' => 'private'], 'date' => time(), 'text' => 'x'],
        'data' => $data]];
}

// ==================================================================
appBoot();
Db::migrate();
PayGws::ensure();

$ADMIN = (int)(botAdminIds()[0] ?? 0);
foreach ([$ADMIN] as $u) {
    Db::q('DELETE FROM `site` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `user` WHERE `id` = ?', [$u]);
}
Db::set('access_mode', 'open');
Db::set('base_url', '');
Db::set('domain', '');
Db::q("UPDATE `pay_gateway` SET `enabled` = 0, `api_key` = '', `merchant_id` = ''");

$bot = new Bot(appConfig());
act(msgU($ADMIN, '/start'));

// یک سایت و یک کاربر تا دکمه‌های وابسته به داده هم قابل تست باشند
act(msgU($ADMIN, '➕ افزودن سایت'));
act(msgU($ADMIN, 'http://127.0.0.1:8199/btn'));
$siteId = (int)Db::val('SELECT MAX(`id`) FROM `site` WHERE `user_id` = ?', [$ADMIN]);
$victim = 799900;
Db::q('INSERT INTO `user` (`id`,`name`,`username`,`access`,`share_token`,`created_at`,`last_seen`)
       VALUES (?,?,?,1,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `access`=1', [$victim, 'کاربر تست', 'btnuser', 'btntoken0000000001']);
chk('آماده‌سازی انجام شد', $siteId > 0);

/** کلیک روی یک دکمه و ارزیابی نتیجه */
function click(string $cb, string $label, bool $expectStepOk = false): void
{
    global $dead, $ADMIN;
    $errBefore = count($GLOBALS['ERRORS']);
    $stepBefore = (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$ADMIN]);

    act(cbU($ADMIN, $cb));
    $txt = strip_tags(blob());
    $problems = [];

    if (count($GLOBALS['ERRORS']) > $errBefore) $problems[] = 'استثنا';
    if (trim($txt) === '') $problems[] = 'پاسخ خالی';
    if (has('دستور نامشخص')) $problems[] = 'دستور نامشخص (دکمه وصل نیست)';

    if ($problems) {
        $dead[] = $cb . ' [' . $label . '] → ' . implode('، ', $problems);
        chk("دکمهٔ `$cb` ($label)", false, implode('، ', $problems));
        // از مرحله گیر نکنیم
        Db::q("UPDATE `user` SET `step` = 'idle', `temp` = NULL WHERE `id` = ?", [$ADMIN]);
        return;
    }

    // اگر وارد مرحله شد، باید بتوان از آن خارج شد (راه فرار)
    $stepAfter = (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$ADMIN]);
    if ($stepAfter !== 'idle') {
        act(msgU($ADMIN, '❌ انصراف'));
        $esc = (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$ADMIN]);
        if ($esc !== 'idle') {
            $dead[] = $cb . ' [' . $label . '] → مرحلهٔ ' . $stepAfter . ' بدون راه فرار';
            chk("دکمهٔ `$cb` ($label) راه فرار دارد", false, 'در مرحلهٔ ' . $stepAfter . ' گیر کرد');
            Db::q("UPDATE `user` SET `step` = 'idle', `temp` = NULL WHERE `id` = ?", [$ADMIN]);
            return;
        }
    }
    chk("دکمهٔ `$cb` ($label)", true);
}

/** پیمایش بازگشتی: هر پیام را باز می‌کند و همهٔ دکمه‌هایش را می‌کلیک می‌کند */
function crawl(array $rootCbs, array $skipPrefix = [], int $depth = 0): void
{
    if ($depth > 2) return;
    foreach ($rootCbs as $cb) {
        $skip = false;
        foreach ($skipPrefix as $p) if (str_starts_with($cb, $p)) $skip = true;
        if ($skip) continue;
        click($cb, 'crawl');
        // دکمه‌های تازهٔ همین صفحه را هم یک‌بار امتحان کن
        $fresh = array_values(array_diff(cbs(), $rootCbs, $skipPrefix));
        if ($fresh && $depth < 2) {
            foreach (array_slice($fresh, 0, 6) as $f) {
                click($f, 'crawl2');
            }
        }
    }
}

/** مثل click() ولی برای دکمهٔ متنیِ کیبورد معمولی (بدون callback_data) */
function clickText(string $text, string $label): void
{
    global $dead, $ADMIN;
    $errBefore = count($GLOBALS['ERRORS']);
    act(msgU($ADMIN, $text));
    $txt = strip_tags(blob());
    $problems = [];
    if (count($GLOBALS['ERRORS']) > $errBefore) $problems[] = 'استثنا';
    if (trim($txt) === '') $problems[] = 'پاسخ خالی';
    if (has('دستور نامشخص')) $problems[] = 'دستور نامشخص (دکمه وصل نیست)';

    if ($problems) {
        $dead[] = $text . ' [' . $label . '] → ' . implode('، ', $problems);
        chk("دکمهٔ متنی «$text» ($label)", false, implode('، ', $problems));
        Db::q("UPDATE `user` SET `step` = 'idle', `temp` = NULL WHERE `id` = ?", [$ADMIN]);
        return;
    }

    $stepAfter = (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$ADMIN]);
    if ($stepAfter !== 'idle') {
        act(msgU($ADMIN, '❌ انصراف'));
        if ((string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$ADMIN]) !== 'idle') {
            $dead[] = $text . ' [' . $label . '] → مرحلهٔ ' . $stepAfter . ' بدون راه فرار';
            chk("دکمهٔ متنی «$text» راه فرار دارد", false, 'در مرحلهٔ ' . $stepAfter . ' گیر کرد');
            Db::q("UPDATE `user` SET `step` = 'idle', `temp` = NULL WHERE `id` = ?", [$ADMIN]);
            return;
        }
    }
    chk("دکمهٔ متنی «$text» ($label)", true);
}

// ==================================================================
section('۱) پنل مدیریت — تک‌تک دکمه‌ها');
// ==================================================================
// پنل ادمین حالا «کیبورد معمولی» است (دکمهٔ متنی، نه شیشه‌ای). پس باید متنِ
// دکمه‌ها را کلیک کنیم، نه callback_data را.
$panelTexts = [
    '🔢 سقف کاربران', '🌐 سقف سایت', '💎 سقف سایت ویژه', '⏱ فاصلهٔ چک',
    '🚨 آستانهٔ هشدار', '🔓 حالت دسترسی', '🛒 قیمت اشتراک', '💳 شماره کارت',
    '📆 مدت اشتراک (روز)', '👥 سقف مانیتور گروه', '🔐 هشدار گواهی (روز)',
    '🌐 هشدار دامنه (روز)', '📇 سقف دامنه', '🔕 اعلان کندی',
    '🌐 آدرس اپلیکیشن', '🌐 دامنه ربات',
    '🗣 منشن در گروه', '🎟 کدهای فعال‌سازی', '📊 آمار',
    '🏦 درگاه‌های پرداخت', '⏰ کرون چک', '📣 همگانی',
    '💳 پرداخت‌های در انتظار', '🧩 وضعیت افزونه‌ها', '💰 پرداخت همکاران',
    // دکمه‌های وضعیت‌محور: هر دو حالتِ ممکن را امتحان می‌کنیم
    '🔕 خاموش کردن اعلان‌ها', '🔔 روشن کردن اعلان‌ها',
    '⏸ توقف سراسری چک', '▶️ ادامهٔ چک سراسری',
    '🏠 منو',
];
act(msgU($ADMIN, '⚙️ پنل مدیریت'));
chk('پنل مدیریت باز شد', has('پنل مدیریت'));
foreach ($panelTexts as $t) clickText($t, 'پنل');

// برچسب‌هایی که واقعاً روی کیبورد پنل چاپ می‌شوند را هم کلیک می‌کنیم —
// این‌ها «همان چیزی» هستند که ادمین روی صفحه می‌بیند و می‌زند.
act(msgU($ADMIN, '⚙️ پنل مدیریت'));
$realLabels = [];
foreach ($GLOBALS['OUT'] as $c) {
    foreach (($c['params'] ?? []) as $k => $v) {
        if ($k !== 'reply_markup' || !is_string($v)) continue;
        $d = json_decode($v, true);
        foreach (($d['keyboard'] ?? []) as $row) foreach ((array)$row as $b) $realLabels[] = (string)$b['text'];
    }
}
$realLabels = array_values(array_unique($realLabels));
chk('پنل برچسب‌های واقعی دارد', count($realLabels) > 10, 'تعداد=' . count($realLabels));
foreach ($realLabels as $t) {
    if (str_contains($t, 'تغییر') || str_contains($t, '‹')) continue; // در بخش ۱-ب امتحان می‌شوند
    clickText($t, 'برچسب واقعی');
}

// ==================================================================
section('1-B) dynamic-status buttons: live label + real effect');
// ==================================================================
// دکمه‌هایی مثل «🔕 اعلان کندی» یا «🔓 حالت دسترسی» وضعیت فعلی را در
// برچسبشان نشان می‌دهند. اگر برچسب و مقدارِ دیتابیس از هم جدا باشند، ادمین
// فکر می‌کند دکمه کار نمی‌کند. اینجا هر دو را با هم می‌سنجیم.
function kbTexts(): array
{
    $out = [];
    foreach ($GLOBALS['OUT'] as $c) {
        foreach (($c['params'] ?? []) as $k => $v) {
            if ($k !== 'reply_markup' || !is_string($v)) continue;
            $d = json_decode($v, true);
            foreach (($d['keyboard'] ?? []) as $row) {
                foreach ((array)$row as $b) if (isset($b['text'])) $out[] = (string)$b['text'];
            }
        }
    }
    return $out;
}
function panelButton(string $needle): ?string
{
    foreach (kbTexts() as $t) if (str_contains($t, $needle)) return $t;
    return null;
}

// [مقدار اولیه در دیتابیس, بخشی از برچسب, عنوان پیام, مقداری که باید بعدش باشد]
$pre = [
    'notify_slow'   => ['1',   'کندی:',     '🐢 <b>اعلان کندی</b>',         '0'],
    'access_mode'   => ['paid', 'حالت دسترسی', '🔓 <b>حالت دسترسی</b>',   'code'],
    'group_mention' => ['all',  'منشن گروه',   '🗣 <b>منشن در گروه</b>',   'none'],
    'notify'        => ['1',   'اعلان‌ها:', '🔔 <b>اعلان‌های سراسری</b>', '0'],
];

foreach ($pre as $key => [$initVal, $needle, $title, $wantAfter]) {
    Db::set($key, $initVal);
    act(msgU($ADMIN, '⚙️ پنل مدیریت'));

    $label = panelButton($needle);
    if ($label === null) {
        chk("دکمهٔ «{$needle}» روی کیبورد پنل هست", false, 'پیدا نشد');
        continue;
    }
    // برچسب باید وضعیت واقعی دیتابیس را نشان دهد، نه یک متن ثابت
    static $labelNeedle = [
        'notify_slow'   => 'روشن',   // مقدار اولیه ۱ است
        'notify'        => 'روشن',   // مقدار اولیه ۱ است
        'access_mode'   => 'پرداخت', // مقدار اولیه paid است
        'group_mention' => 'همه',    // مقدار اولیه all است
    ];
    chk("برچسب «{$label}» وضعیت {$key} را نشان می‌دهد",
        str_contains($label, $labelNeedle[$key]), "برچسب={$label}");

    $before = Db::get($key, '(unset)');
    // بررسی پاسخ باید داخل callback باشد، چون بعد از برگشت outbox مصرف شده است
    clickText($label, 'وضعیت‌محور', function (string $reply) use ($key, $wantAfter, $label, $title) {
        $plain = strip_tags($reply);
        chk("«{$label}» فقط پیامِ خودش را داد (نه کل پنل)",
            !str_contains($plain, 'سقف مانیتور گروه:') && !str_contains($plain, 'آدرس‌های فعال'),
            'کل متن پنل دوباره فرستاده شده');
        chk("«{$label}» عنوانِ خودش را داشت",
            str_contains($plain, strip_tags($title)), mb_substr($plain, 0, 70));
        chk("«{$label}» «قبل/بعد» را نشان داد",
            str_contains($plain, 'قبل:') && str_contains($plain, 'بعد:'),
            mb_substr($plain, 0, 70));
        $newVal = ['0' => 'خاموش', '1' => 'روشن'][$wantAfter] ?? $wantAfter;
        chk("«{$label}» مقدار تازه «{$newVal}» را گفت",
            str_contains($plain, $newVal), mb_substr($plain, 0, 70));
    });
    $after = Db::get($key, '(unset)');
    chk("«{$label}» مقدار {$key} را عوض کرد", $before !== $after, "{$before}→{$after}");
}

// برچسب‌های قدیمی (روی کیبوردِ از قبل نصب‌شدهٔ کاربر) هم باید کار کنند
$legacy = [
    ['🔕 اعلان کندی', 'notify_slow'],
    ['🔔 اعلان کندی', 'notify_slow'],
    ['🔓 حالت دسترسی', 'access_mode'],
    ['🗣 منشن در گروه', 'group_mention'],
    ['🔕 خاموش کردن اعلان‌ها', 'notify'],
    ['🔔 روشن کردن اعلان‌ها', 'notify'],
];
foreach ($legacy as [$old, $key]) {
    $before = Db::get($key, '(unset)');
    act(msgU($ADMIN, $old));
    $after = Db::get($key, '(unset)');
    chk("برچسب قدیمی «{$old}» هنوز کار می‌کند", $before !== $after, "{$before}→{$after}");
    chk("برچسب قدیمی «{$old}» پاسخ داد", trim(strip_tags(blob())) !== '', 'بی‌پاسخ');
    Db::q("UPDATE `user` SET `step` = 'idle', `temp` = NULL WHERE `id` = ?", [$ADMIN]);
}

// موجودیت HTML نباید به‌صورت خام (`&lt;`) به ادمین نشان داده شود
Db::set('base_url', 'https://x.example/bots/y');
act(msgU($ADMIN, '⚙️ پنل مدیریت'));
$panelBlob = '';
foreach ($GLOBALS['OUT'] as $c) foreach (($c['params'] ?? []) as $k => $v) if ($k === 'text') $panelBlob .= (string)$v;
chk('پنل متن خام &lt; نشان نمی‌دهد', !str_contains($panelBlob, '&lt;'), 'توکن لو رفت');
chk('پنل آدرس صفحهٔ وضعیت را نشان می‌دهد', str_contains(strip_tags($panelBlob), 'status.php?u='));
Db::set('base_url', '');

// ==================================================================// ==================================================================
section('۲) منوی ادمین، آمار، کرون، کدها');
// ==================================================================
foreach (['astats', 'cron', 'codes'] as $entry) {
    act(cbU($ADMIN, $entry));
    $list = cbs();
    foreach ($list as $cb) click($cb, $entry);
}
act(msgU($ADMIN, '📊 آمار مدیریتی'));
chk('دکمهٔ متنی «آمار مدیریتی» کار کرد', !has('نامشخص'));
act(msgU($ADMIN, '⚙️ پنل مدیریت'));
chk('دکمهٔ متنی «پنل مدیریت» کار کرد', !has('نامشخص'));
act(msgU($ADMIN, '👥 کاربران'));
chk('دکمهٔ متنی «کاربران» کار کرد', !has('نامشخص'));

// ==================================================================
section('۳) درگاه‌های پرداخت');
// ==================================================================
act(cbU($ADMIN, 'pgws_list'));
$gwCbs = cbs();
chk('فهرست درگاه‌ها دکمه دارد', count($gwCbs) > 0, 'تعداد=' . count($gwCbs));
foreach ($gwCbs as $cb) click($cb, 'درگاه');

// ==================================================================
section('۴) منوهای کاربر و افزونه‌ها');
// ==================================================================
foreach ([
    'feat', 'exp', 'wh', 'intg', 'anal', 'sla', 'aff',
    'sites', 'shared', 'domains', 'rank', 'settings', 'sub', 'help',
] as $entry) {
    act(cbU($ADMIN, $entry));
    $list = cbs();
    foreach ($list as $cb) click($cb, $entry);
    if (in_array($entry, ['sites', 'shared', 'domains', 'rank', 'settings', 'sub', 'feat', 'exp', 'wh', 'intg', 'anal', 'sla', 'aff'], true)) {
        chk("منوی «$entry» باز شد و دکمه دارد", count($list) > 0, 'دکمه‌ای ندارد');
    }
}

// ==================================================================
section('۵) دکمه‌های متنیِ منوی اصلی');
// ==================================================================
$textButtons = [
    '➕ افزودن سایت', '📋 سایت‌های من', '📊 رنکینگ', '📈 گزارش من',
    '🔗 صفحهٔ وضعیت من', '👥 مانیتورهای مشترک', '⏸ توقف چک‌ها', '🌐 دامنه‌های من',
    '⚙️ تنظیمات', '🛒 اشتراک ویژه', 'ℹ️ راهنما', '📊 آمار مدیریتی',
    '📣 همگانی', '👥 کاربران', '⚙️ پنل مدیریت', '🏠 منو',
];
$badText = [];
foreach ($textButtons as $t) {
    $before = count($GLOBALS['ERRORS']);
    act(msgU($ADMIN, $t));
    if (count($GLOBALS['ERRORS']) > $before || trim(strip_tags(blob())) === '') {
        $badText[] = $t;
        chk("دکمهٔ متنی «$t»", false, 'بی‌پاسخ یا استثنا');
    }
    Db::q("UPDATE `user` SET `step` = 'idle', `temp` = NULL WHERE `id` = ?", [$ADMIN]);
}
chk('همهٔ دکمه‌های متنی جواب دادند', $badText === [], 'بدون جواب: ' . implode(' | ', $badText));

// ==================================================================
// ==================================================================
section('۵-ب) توقف/ادامهٔ سراسری: اثر واقعی روی دیتابیس');
// ==================================================================
// این دکمه‌ها وضعیت سراسری ربات را عوض می‌کنند. تست باید مطمئن شود که
// «فقط پیام نمی‌دهند» بلکه واقعاً pause_all را روشن/خاموش می‌کنند، و اینکه
// دکمهٔ «stop» وقتی از قبل متوقف است، بی‌خطر (idempotent) باشد.

Db::set('pause_all', '0');
Db::set('pause_global_since', '');

// ۱) «ادامهٔ چک» وقتی از قبل فعال است → نباید چیزی را بشکند
act(msgU($ADMIN, '▶️ ادامهٔ چک سراسری'));
chk('ادامهٔ چک وقتی فعال است، pause_all را ۰ نگه داشت',
    !Db::getBool('pause_all', false));
chk('ادامهٔ چک وقتی فعال است، «همین حالا هم فعال است» گفت',
    has('همین حالا هم فعال است'));

// ۲) «توقف سراسری» → باید واقعاً روشن کند
act(msgU($ADMIN, '⏸ توقف سراسری چک'));
chk('توقف سراسری، pause_all را ۱ کرد', Db::getBool('pause_all', false));
chk('توقف سراسری، زمان شروع را ثبت کرد', (string)Db::get('pause_global_since') !== '');
chk('توقف سراسری تأیید داد', has('متوقف شد'));
chk('توقف سراسری فقط پیام خودش را داد (نه کل پنل)',
    !str_contains(strip_tags(blob()), 'سقف مانیتور گروه:'));

// ۳) زدن دوباره در حالت «متوقف» → بی‌خطر
act(msgU($ADMIN, '⏸ توقف سراسری چک'));
chk('توقف دوباره، «هم متوقف است» گفت', has('همین حالا هم متوقف است'));
chk('توقف دوباره، همچنان متوقف است', Db::getBool('pause_all', false));

// ۴) «ادامهٔ چک» → باید خاموش کند و مدت را جمع بزند
act(msgU($ADMIN, '▶️ ادامهٔ چک سراسری'));
chk('ادامهٔ چک، pause_all را ۰ کرد', !Db::getBool('pause_all', false));
chk('ادامهٔ چک، زمان شروع را پاک کرد', (string)Db::get('pause_global_since') === '');
chk('ادامهٔ چک تأیید داد', has('ادامه یافت'));
chk('ادامهٔ چک فقط پیام خودش را داد', !str_contains(strip_tags(blob()), 'سقف مانیتور گروه:'));

Db::set('pause_all', '0');
Db::set('pause_global_since', '');
Db::set('pause_global_total', '0');

// ==================================================================
section('۶) آدرس پایه: اعتبارسنجی و اثرگذاری واقعی');
// ==================================================================
// ورودی خراب باید رد شود
act(cbU($ADMIN, 'set:base_url'));
act(msgU($ADMIN, 'ht!tp:/bad url with spaces'));
chk('آدرس خراب رد شد', has('آدرس معتبر نیست'));
// ورودی بدون scheme باید نرمال شود و واقعاً اثر کند
act(cbU($ADMIN, 'set:base_url'));
act(msgU($ADMIN, 'my-new-host.example/bots/paishin'));
chk('آدرس بدون https پذیرفته و نرمال شد', has('https://my-new-host.example/bots/paishin'));
chk('نمونهٔ لینک اپ هم نشان داده شد', has('webapp/index.html'));
$eff = effectiveBaseUrl();
chk('آدرس مؤثر از پنل اعمال شد (نه config)', $eff === 'https://my-new-host.example/bots/paishin', 'eff=' . $eff);
chk('miniAppUrl از تنظیم پنل ساخته شد', miniAppUrl() === 'https://my-new-host.example/bots/paishin/webapp/index.html', miniAppUrl());

// لینک‌های دیگر هم باید از همین آدرس بیایند
act(cbU($ADMIN, 'share'));
chk('لینک صفحهٔ وضعیت از آدرس پنل ساخته شد', has('my-new-host.example'));
act(cbU($ADMIN, "slink:$siteId"));
chk('لینک تک‌سایت از آدرس پنل ساخته شد', has('my-new-host.example'));
$su = statusUrl(appConfig(), 'abc');
chk('statusUrl از آدرس پنل ساخته شد', str_contains($su, 'my-new-host.example'), $su);

// دامنه هم همین‌طور
act(cbU($ADMIN, 'set:domain'));
act(msgU($ADMIN, 'https://dom.example/bots/x/'));
chk('دامنه نرمال شد (scheme و اسلش حذف شد)', has('dom.example/bots/x') && !has('https://dom.example'));
act(cbU($ADMIN, 'set:domain'));
act(msgU($ADMIN, '!!! not a domain !!!'));
chk('دامنهٔ خراب رد شد', has('دامنه معتبر نیست'));

// پنل باید آدرس فعال را نشان دهد
Db::set('base_url', 'https://panel-shows-this.example/x');
act(cbU($ADMIN, 'apanel'));
chk('پنل آدرس فعال را نشان می‌دهد', has('panel-shows-this.example'));

// بازگرداندن
Db::set('base_url', '');
Db::set('domain', '');

// ==================================================================
section('۷) نتیجه');
// ==================================================================
chk('هیچ استثنای مدیریت‌نشده‌ای رخ نداد', empty($GLOBALS['ERRORS']),
    implode(' | ', array_slice($GLOBALS['ERRORS'], 0, 3)));

if ($dead) {
    echo "\n  دکمه‌های بی‌کار:\n";
    foreach (array_unique($dead) as $d) echo "    - $d\n";
}

echo "\n----------------------------------------\n";
echo "OK: $ok    FAIL: $fail" . ($dead ? "    DEAD BUTTONS: " . count(array_unique($dead)) : '') . "\n";
exit($fail > 0 ? 1 : 0);
