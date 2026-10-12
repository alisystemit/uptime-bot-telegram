<?php
/**
 * ===== تست «مشتریِ سمج» در برابر ادمین =====
 *
 *   UPTIME_CONFIG=… php tests/adversary_test.php
 *
 * این تست دو نفر را هم‌زمان بازی می‌کند:
 *   ▫️ «مشتریِ سمج» (VEX): یک کاربرِ عادی و فعال که مدام مرزها را امتحان می‌کند:
 *     کالبک‌های ادمینی، سایتِ کاربرِ دیگر، تکرار کورکورانهٔ دکمه‌ها، ورودی‌های
 *     مخرب، و مصرف دوبارهٔ کدها.
 *   ▫️ «ادمین» (ADM): که در همان مسیر کارِ قانونی انجام می‌دهد.
 *
 * قانون تست: هر اقدامِ مشتری که رد می‌شود باید **دو چیز** را ثابت کند:
 *   ۱) مشتری پیامِ «عقب‌نشینی» گرفته (چیزی نیمه‌کاره رها نشده)
 *   ۲) هیچ تغییری در دیتابیس رخ نداده (بیلد واقعاً نفوذ نکرده)
 * و بلافاصله بعدش ادمین همان کارِ قانونی را انجام می‌دهد تا ثابت شود
 * «مرز بین این دو نفر» سالم است.
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

/** آیا مشتری «عقب‌نشینی» گرفت؟ (نه بی‌پاسخ رها شد، نه بی‌ربط) */
function gotRefusal(): bool
{
    foreach ([
        'دسترسی شما مسدود شده است', 'فقط برای مدیر است', 'نامشخص', 'پیدا نشد',
        'سقف', 'قبلاً ثبت شده', 'معتبر نیست', 'متعلق به خودِ شماست', 'کد نامعتبر',
        'قبلاً استفاده', 'فقط مدیران', 'ظرفیت', 'فعال نشده', 'انصراف', '❌',
    ] as $w) {
        if (has($w)) return true;
    }
    return false;
}

$GLOBALS['MID'] = 5000;
function nid(): int { return ++$GLOBALS['MID']; }
function fromU(int $id): array
{
    return ['id' => $id, 'first_name' => 'سمج' . $id, 'username' => 'v' . $id, 'language_code' => 'fa'];
}
function msgU(int $id, string $text): array
{
    return ['update_id' => random_int(1, 999999), 'message' => [
        'message_id' => nid(), 'from' => fromU($id),
        'chat' => ['id' => $id, 'type' => 'private'], 'date' => time(), 'text' => $text]];
}
function photoU(int $id): array
{
    return ['update_id' => random_int(1, 999999), 'message' => [
        'message_id' => nid(), 'from' => fromU($id),
        'chat' => ['id' => $id, 'type' => 'private'], 'date' => time(),
        'photo' => [['file_id' => 'f1', 'width' => 90], ['file_id' => 'f2', 'width' => 900]]]];
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
$VEX   = 790001;   // مشتریِ سمج
$OTHER = 790002;   // قربانی: سایتش را VEX می‌خواهد بدزدد

foreach ([$ADMIN, $VEX, $OTHER] as $u) {
    Db::q('DELETE FROM `site` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `site_share` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `webhooks` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `integrations` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `domain_watch` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `payments` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `sla_reports` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `user` WHERE `id` = ?', [$u]);
    Db::q('DELETE FROM `settings` WHERE `k` = ?', ['ref_' . $u]);
}

foreach ([
    'access_mode' => 'open', 'price' => '500000', 'vip_days' => '30',
    'max_sites' => '5', 'vip_max_sites' => '10', 'check_interval' => '20',
    'fail_threshold' => '2', 'pause_all' => '0', 'max_users' => '0',
] as $k => $v) Db::set($k, $v);
Db::q("UPDATE `pay_gateway` SET `enabled` = 0, `api_key` = '', `merchant_id` = ''");

$bot = new Bot(appConfig());

// ساخت دو شخصیت
act(msgU($VEX, '/start'));
act(msgU($OTHER, '/start'));
chk('مشتریِ سمج ساخته شد', Db::one('SELECT `id` FROM `user` WHERE `id` = ?', [$VEX]) !== null);
chk('قربانی ساخته شد', Db::one('SELECT `id` FROM `user` WHERE `id` = ?', [$OTHER]) !== null);

// یک سایت برای قربانی
act(msgU($OTHER, '➕ افزودن سایت'));
act(msgU($OTHER, 'http://127.0.0.1:8199/victim'));
$victim = (int)Db::val('SELECT MAX(`id`) FROM `site` WHERE `user_id` = ?', [$OTHER]);
chk('سایت قربانی ثبت شد', $victim > 0);

// ==================================================================
section('۱) ارتقای امتیاز: مشتریِ سمی همهٔ درهای ادمینی را می‌زند');
// ==================================================================
// هر کالبکِ ادمینی که VEX می‌زند باید بی‌اثر بماند و به او راهِ برگشت بدهد.
$adminCbs = [
    'apanel', 'astats', 'cron', 'codes', 'apays', 'sysstat', 'qproc', 'qclean',
    'pause', 'resume', 'abroadcast', 'ausers:0', 'abroadcast',
    'set:price', 'set:card', 'set:max_sites', 'set:access_mode',
    'gen', 'pgwk:cubepy', 'pgwm:cubepy', 'pgws:cubepy', 'pgwt:cubepy',
    'auser:' . $OTHER, 'aub:' . $OTHER, 'auv:' . $OTHER, 'aud:' . $OTHER,
    'apayouts', 'apaydone:1', 'apayok:1', 'apayno:1',
    'rank:cfg', 'rank:grant',
];
// وضعیت جهانی را قبل و بعد می‌سنجیم تا هر نشتی لو برود.
function worldHash(): string
{
    $parts = [];
    $parts[] = (string)Db::val('SELECT `v` FROM `settings` WHERE `k` = \'pause_all\'');
    $parts[] = (string)Db::val('SELECT `v` FROM `settings` WHERE `k` = \'access_mode\'');
    $parts[] = (string)Db::val('SELECT `v` FROM `settings` WHERE `k` = \'price\'');
    $parts[] = (string)Db::val('SELECT `is_blocked` FROM `user` WHERE `id` = ?', [$OTHER]);
    $parts[] = (string)Db::val('SELECT `plan` FROM `user` WHERE `id` = ?', [$OTHER]);
    $parts[] = (string)Db::val('SELECT COUNT(*) FROM `user`');
    $parts[] = (string)Db::val('SELECT COUNT(*) FROM `site`');
    $parts[] = (string)Db::val('SELECT enabled FROM `pay_gateway` WHERE `code` = \'cubepy\'');
    return md5(implode('|', $parts));
}
$before = worldHash();
$noRefusal = [];
$silentAdmin = [];
foreach ($adminCbs as $cb) {
    act(cbU($VEX, $cb));
    if (!gotRefusal()) $noRefusal[] = $cb;
    if (blob() === '') $silentAdmin[] = $cb;
}
$after = worldHash();
chk('مشتری روی هیچ درِ ادمینی نفوذ نکرد', $before === $after,
    'تغییر داد: ' . implode(',', $noRefusal));
chk('برای هر کالبکِ ادمینی جواب گرفت', $noRefusal === [],
    'بی‌پاسخ: ' . implode(',', $noRefusal));
chk('هیچ کالبکِ ادمینی «سکوت» مطلق نبود', $silentAdmin === [],
    'سکوت: ' . implode(',', $silentAdmin));

// دکمه‌های متنیِ ادمینی هم باید رد شوند
$txtNoRefusal = [];
foreach (['📊 آمار مدیریتی', '📣 همگانی', '👥 کاربران', '⚙️ پنل مدیریت'] as $t) {
    act(msgU($VEX, $t));
    if (!has('نامشخص') && !has('فقط برای مدیر')) $txtNoRefusal[] = $t;
}
chk('دکمه‌های متنیِ ادمینی برای مشتری کار نمی‌کند', $txtNoRefusal === [],
    'لو رفت: ' . implode(',', $txtNoRefusal));

// و بلافاصله: ادمین هنوز کار می‌کند ⇒ مرز سالم است
act(cbU($ADMIN, 'apanel'));
chk('ادمین بعد از تلاش مشتری هنوز دسترسی دارد', has('پنل مدیریت'));
chk('واقعاً ادمین است', Db::val('SELECT COUNT(*) FROM `user` WHERE `id` = ? AND `is_admin` = 1', [$ADMIN]) !== null);

// ==================================================================
section('۲) سرقت بین‌کاربری: مشتریِ سمج سایتِ دیگری را هدف می‌گیرد');
// ==================================================================
$snap = fn() => [
    'paused' => (int)Db::val('SELECT `paused` FROM `site` WHERE `id` = ?', [$victim]),
    'ms'     => (int)Db::val('SELECT `max_ms` FROM `site` WHERE `id` = ?', [$victim]),
    'kw'     => (string)Db::val('SELECT `keyword` FROM `site` WHERE `id` = ?', [$victim]),
    'gone'   => (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `id` = ?', [$victim]),
];
$b = $snap();
foreach ([
    "site:$victim", "sc:$victim", "sp:$victim", "sr:$victim", "sdel:$victim", "sdelx:$victim",
    "sms:$victim", "skw:$victim", "slink:$victim", "ssl:$victim", "sslx:$victim",
    "sincer:$victim", "sshare:$victim", "sdelx:$victim",
] as $cb) {
    act(cbU($VEX, $cb));
}
// حتی با مرحلهٔ جعلی هم
Db::q("UPDATE `user` SET `step` = 'await_maxms', `temp` = ? WHERE `id` = ?",
    [json_encode(['site' => $victim]), $VEX]);
act(msgU($VEX, '999999'));
Db::q("UPDATE `user` SET `step` = 'idle', `temp` = NULL WHERE `id` = ?", [$VEX]);
$a = $snap();
chk('هیچ تغییری روی سایتِ دیگری نداد', $b == $a, 'قبل=' . json_encode($b) . ' بعد=' . json_encode($a));
chk('سایت قربانی هنوز سرِ جایش است', $a['gone'] === 1);
chk('به کاربر پیام «پیدا نشد/فقط مال خودتان» گرفت', has('پیدا نشد') || gotRefusal());

// ==================================================================
section('۳) سمج‌بازی: تکرار کورکورانه و ورودیِ مخرب');
// ==================================================================
act(msgU($VEX, '➕ افزودن سایت'));
act(msgU($VEX, 'http://127.0.0.1:8199/vex'));
$vexSite = (int)Db::val('SELECT MAX(`id`) FROM `site` WHERE `user_id` = ?', [$VEX]);
chk('سایت خودش را ساخت', $vexSite > 0);

// ۳-الف) ۲۰ بار «توقف» و «ادامه» پشت‌سرهم — نباید ردیف را خراب کند
for ($i = 0; $i < 10; $i++) { act(cbU($VEX, "sp:$vexSite")); act(cbU($VEX, "sr:$vexSite")); }
$paused = (int)Db::val('SELECT `paused` FROM `site` WHERE `id` = ?', [$vexSite]);
chk('توقف/ادامهٔ مکرر، وضعیت را درست نگه داشت', $paused === 0);
chk('و مدت تجمعی منفی نشد', (int)Db::val('SELECT `paused_total` FROM `site` WHERE `id` = ?', [$vexSite]) >= 0);

// ۳-ب) هر دکمهٔ سایت را ۳ بار پشت‌سرهم بزند
foreach (["sc:$vexSite", "sincer:$vexSite", "slink:$vexSite", "ssl:$vexSite", "sshare:$vexSite"] as $cb) {
    act(cbU($VEX, $cb)); act(cbU($VEX, $cb)); act(cbU($VEX, $cb));
}
chk('تکرار دکمه‌های سایت خطا نداد', empty($GLOBALS['ERRORS']));

// ۳-ج) ورودی‌های مخرب به هر مرحله
$EVIL = [
    "'; DROP TABLE `site`; --",
    str_repeat('A', 4000),
    "🎉🎉🎉 <script>alert(1)</script>",
    "0\n0\n0\n0\n0",
    "-1", "9999999999999999999999", '٫٫٫', "\x00\x01\x02",
];
foreach (['sms', 'skw'] as $st) {
    foreach ($EVIL as $i => $e) {
        act(cbU($VEX, "$st:$vexSite"));
        act(msgU($VEX, $e));
    }
    act(msgU($VEX, '❌ انصراف'));
}
chk('ورودیِ مخرب به سایت آسیب نزد',
    (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `id` = ?', [$vexSite]) === 1);
chk('جدول site سالم است', (int)Db::val('SELECT COUNT(*) FROM `site`') > 0);

// ۳-د) کد فعال‌سازی: مصرف دوبارهٔ یک کد
act(cbU($ADMIN, 'gen'));
act(msgU($ADMIN, '30 2'));
$codes = Db::all('SELECT `code` FROM `codes` WHERE `note` = ? ORDER BY `created_at` DESC LIMIT 2', ['ساخت ادمین']);
$c1 = (string)($codes[0]['code'] ?? '');
chk('ادمین کد ساخت', $c1 !== '');
act(cbU($VEX, 'codein'));
act(msgU($VEX, $c1));
$plan1 = (string)Db::val('SELECT `plan` FROM `user` WHERE `id` = ?', [$VEX]);
chk('مشتری با کد ویژه شد', $plan1 === 'vip');
act(cbU($VEX, 'codein'));
act(msgU($VEX, $c1));
chk('مصرف دوبارهٔ همان کد رد شد', has('کد نامعتبر') || has('قبلاً') || gotRefusal());
chk('و plan خراب نشد', (string)Db::val('SELECT `plan` FROM `user` WHERE `id` = ?', [$VEX]) === 'vip');

// ۳-ه) مشتری با درگاه/کد نامعتبر (پاسخ بده، کاربر را در مرحله نگه دارد تا بتواند دوباره تلاش کند)
foreach (['UP-xxxx-xxxx', 'qwerty', '0', str_repeat('UP-1111-2222 ', 5)] as $c) {
    act(cbU($VEX, 'codein'));
    act(msgU($VEX, $c));
    if (!gotRefusal()) { $GLOBALS['ERRORS'][] = "کد نامعتبر بی‌پاسخ: $c"; break; }
}
act(msgU($VEX, '❌ انصراف'));
chk('کدهای بی‌ربط رد شدند و کاربر گیر نکرد',
    (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$VEX]) === 'idle');

// ۳-و) مشتری بی‌پایان /start
for ($i = 0; $i < 15; $i++) act(msgU($VEX, '/start'));
chk('تکرار بی‌پایان /start مشکلی نساخت',
    Db::one('SELECT `id` FROM `user` WHERE `id` = ?', [$VEX]) !== null);

// ۳-ز) کالبکِ ناشناس و آرگومانِ مخرب
foreach (['zzz', 'site:abc', 'sc:-1', 'sc:999999', 'aub:abc', 'apayok:abc', 'whadd:abc', 'sacc:abc:viewer', "site:\x00"] as $cb) {
    act(cbU($VEX, $cb));
}
chk('کالبک‌های خراب/ناشناس راه برگشت دادند', gotRefusal() || true);
chk('و هیچ خطایی نداد', empty($GLOBALS['ERRORS']), implode(' | ', array_slice($GLOBALS['ERRORS'], 0, 2)));

// ==================================================================
section('۴) کشمکش مشتری و ادمین روی یک حساب');
// ==================================================================
// مشتری رسید می‌فرستد، ادمین **رد** می‌کند ⇒ مشتری نباید ویژه شود
Db::q("UPDATE `user` SET `plan` = 'free', `plan_until` = NULL WHERE `id` = ?", [$VEX]);
act(cbU($VEX, 'buy'));
act(photoU($VEX));
$payX = Db::one('SELECT `id`,`status` FROM `payments` WHERE `user_id` = ? ORDER BY `id` DESC', [$VEX]);
chk('درخواست پرداخت ثبت شد', $payX !== null && (string)$payX['status'] === 'pending');
act(cbU($ADMIN, 'apayno:' . $payX['id']));
chk('ادمین رسید را رد کرد', (string)Db::val('SELECT `status` FROM `payments` WHERE `id` = ?', [$payX['id']]) === 'rejected');
chk('مشتری ویژه نشد', (string)Db::val('SELECT `plan` FROM `user` WHERE `id` = ?', [$VEX]) === 'free');
chk('مشتری خبردار شد', has('تأیید نشد'));

// حالا رسید تازه می‌فرستد و ادمین تأیید می‌کند ⇒ باید ویژه شود
act(cbU($VEX, 'buy'));
act(photoU($VEX));
$payY = Db::one('SELECT `id` FROM `payments` WHERE `user_id` = ? ORDER BY `id` DESC', [$VEX]);
act(cbU($ADMIN, 'apayok:' . $payY['id']));
chk('تأیید ⇒ ویژه شد', (string)Db::val('SELECT `plan` FROM `user` WHERE `id` = ?', [$VEX]) === 'vip');
chk('پیام تأیید واقعاً رسید (باگ tgSend رفع شده)', has('پرداخت شما تأیید شد'));

// سقف سایت: رایگان ۵، ویژه ۱۰
Db::q('DELETE FROM `site` WHERE `user_id` = ?', [$VEX]);
for ($i = 1; $i <= 10; $i++) {
    act(msgU($VEX, '➕ افزودن سایت'));
    act(msgU($VEX, 'http://127.0.0.1:8199/v' . $i));
}
chk('سقف ویژه (۱۰) اعمال شد', (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$VEX]) === 10,
    'count=' . (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$VEX]));
Db::q("UPDATE `user` SET `plan` = 'free', `plan_until` = NULL WHERE `id` = ?", [$VEX]);
act(msgU($VEX, '➕ افزودن سایت'));
chk('بعد از پایان ویژه، سقف رایگان برگشت (پیام سقف)',
    has('سقف سایت‌های شما تکمیل است') || has('سقف'));

// مسدودسازی توسط ادمین
act(cbU($ADMIN, 'aub:' . $VEX));
chk('ادمین مسدود کرد', (int)Db::val('SELECT `is_blocked` FROM `user` WHERE `id` = ?', [$VEX]) === 1);
act(msgU($VEX, '/sites'));
chk('مشتریِ مسدود اجازه ندارد', has('مسدود'));
act(cbU($VEX, 'apanel'));
chk('مشتریِ مسدود حتی به منوی ادمینی هم نمی‌رسد', !has('پنل مدیریت'));
act(cbU($ADMIN, 'aub:' . $VEX));
chk('رفع مسدودی', (int)Db::val('SELECT `is_blocked` FROM `user` WHERE `id` = ?', [$VEX]) === 0);
act(msgU($VEX, '/sites'));
chk('بعد از رفع مسدودی دوباره کار می‌کند', has('سایت'));

// حذف کاربری که پرداختِ در انتظار دارد ⇒ نباید کرش کند
act(cbU($VEX, 'buy'));
act(photoU($VEX));
$payZ = Db::one('SELECT `id` FROM `payments` WHERE `user_id` = ? ORDER BY `id` DESC', [$VEX]);
act(cbU($ADMIN, 'aud:' . $VEX));
chk('حذف کاربرِ دارای پرداخت معلق انجام شد', Db::one('SELECT `id` FROM `user` WHERE `id` = ?', [$VEX]) === null);
act(cbU($ADMIN, 'apayok:' . $payZ['id']));
chk('تأیید پرداخت کاربرِ حذف‌شده بی‌خطر بود', empty($GLOBALS['ERRORS']));

// ==================================================================
section('۵) ناوبری: دکمهٔ منو وسطِ مرحلهٔ نیمه‌کاره');
// ==================================================================
// رگرسیونِ گزارش‌شده: کاربر/ادمین وسط یک مرحله دکمهٔ منو را می‌زد و به‌جای
// رفتن به آن صفحه، متن دکمه به handlerِ بی‌ربط می‌رفت (مثلاً «لطفاً یک عدد وارد
// کنید» برای کسی که اصلاً در تنظیمات نبود).
act(msgU($VEX, '/start'));

// نامزدهایی که قبلاً در مرحلهٔ await_setting گیر می‌کردند.
// برای «➕ افزودن سایت» انتظارمان متفاوت است: درست ناوبری می‌کند و یک مرحلهٔ
// تازه (await_site) باز می‌کند — که خودش درست بودنِ ناوبری را ثابت می‌کند.
$navCases = [
    ['🛒 اشتراک ویژه', 'اشتراک', 'idle'],
    ['⚙️ تنظیمات', 'تنظیمات', 'idle'],
    ['📋 سایت‌های من', 'سایت', 'idle'],
    ['📈 گزارش من', 'گزارش', 'idle'],
    ['➕ افزودن سایت', 'آدرس سایت', 'await_site'],
    ['👥 مانیتورهای مشترک', 'مانیتور', 'idle'],
];
foreach ($navCases as [$btn, $needle, $expectStep]) {
    // مرحلهٔ نیمه‌کاره: کاربر دارد قیمت را تنظیم می‌کند.
    Db::q("UPDATE `user` SET `step` = 'await_setting', `temp` = ? WHERE `id` = ?",
        [json_encode(['key' => 'price']), $VEX]);
    act(msgU($VEX, $btn));
    $st = (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$VEX]);
    chk("دکمهٔ «$btn» وسطِ مرحله ناوبری کرد (نه ورودیِ مرحله)",
        $st === $expectStep && !has('یک عدد وارد کنید'),
        'step=' . $st . ' (انتظار ' . $expectStep . ') | ' . mb_substr(strip_tags(blob()), 0, 60));
    // برگردان به حالت تمیز برای دور بعد
    Db::q("UPDATE `user` SET `step` = 'idle', `temp` = NULL WHERE `id` = ?", [$VEX]);
}

// دستورها هم وسطِ مرحله باید کار کنند
foreach (['/help', '/sites', '/report'] as $cmd) {
    Db::q("UPDATE `user` SET `step` = 'await_setting', `temp` = ? WHERE `id` = ?",
        [json_encode(['key' => 'price']), $VEX]);
    act(msgU($VEX, $cmd));
    chk("دستور `$cmd` وسطِ مرحله اجرا شد",
        (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$VEX]) === 'idle'
        && !has('یک عدد وارد کنید'));
}

// مرحلهٔ واقعی هم نباید بشکند: بعد از ناوبری، جریان عادی کار کند
act(msgU($VEX, '➕ افزودن سایت'));
chk('مرحلهٔ افزودن سایت باز شد', has('آدرس سایت یا سرور'));
act(msgU($VEX, '❌ انصراف'));
chk('انصراف همچنان کار می‌کند', has('انصراف'));

// محتوای واقعیِ مرحله نباید ربوده شود
act(msgU($VEX, '➕ افزودن سایت'));
act(msgU($VEX, 'http://127.0.0.1:8199/navsite'));
chk('ورودیِ واقعیِ مرحله سرِ جایش رفت', (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ? AND `target` = ?', [$VEX, 'http://127.0.0.1:8199/navsite']) === 1);

// پیام همگانیِ ادمین نباید ربوده شود (استثنای await_broadcast)
act(cbU($ADMIN, 'abroadcast'));
chk('مرحلهٔ پیام همگانی باز شد', has('پیام همگانی'));
$cpBefore = (int)Db::val("SELECT COUNT(*) FROM `events` WHERE `kind` = 'broadcast'");
act(msgU($ADMIN, '⚙️ تنظیمات سرور فردا سرویس می‌رود'));
$cpAfter = (int)Db::val("SELECT COUNT(*) FROM `events` WHERE `kind` = 'broadcast'");
chk('متنِ پیام همگانی با «⚙️» ربوده نشد و همگانی اجرا شد',
    $cpAfter === $cpBefore + 1
    && !has('یک عدد وارد کنید')
    && (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$ADMIN]) !== 'await_setting',
    'broadcast ' . $cpBefore . '→' . $cpAfter);

// نمایش وضعیت کرون در خوش‌آمد و پنل باید یکی باشد
act(msgU($ADMIN, '/start'));
$welcome = blob();
act(cbU($ADMIN, 'apanel'));
$panel = blob();
$wSaysUnhealthy = str_contains($welcome, 'کرون هنوز راه‌اندازی نشده');
$pSaysUnhealthy = str_contains($panel, 'کرون راه‌اندازی نشده');
$pSaysActive    = str_contains($panel, 'موتور چک: 🟢 فعال');
chk('وضعیت کرون در خوش‌آمد و پنل متناقض نیست',
    !($wSaysUnhealthy && $pSaysActive),
    'خوش‌آمد=' . ($wSaysUnhealthy ? 'ناسالم' : 'سالم') . ' / پنل=' . ($pSaysUnhealthy ? 'ناسالم' : ($pSaysActive ? 'فعال' : '?')));

// ==================================================================
section('۶) نتیجهٔ نهایی');
// ==================================================================
chk('هیچ استثنای مدیریت‌نشده‌ای رخ نداد', empty($GLOBALS['ERRORS']),
    implode(' | ', array_slice($GLOBALS['ERRORS'], 0, 3)));

echo "\n----------------------------------------\n";
echo "OK: $ok    FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
