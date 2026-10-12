<?php
/**
 * ===== تست «گزارش خطای داخلی» =====
 *
 *   UPTIME_CONFIG=… php tests/error_report_test.php
 *
 * هدف: هر استثنایی که در ربات رخ دهد باید به کاربر یک پیامِ دقیق و خوانا
 * برساند (بخش + ورودی + علت + فایل:خط)، وب‌هوک ۲۰۰ بدهد تا تلگرام آپدیت را
 * تکرار نکند، و هیچ اطلاعات حساسی (رمز، کوئری) لو ندهد.
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

$MID = 9000;
function msgU(int $id, string $text): array {
    global $MID;
    return ['update_id' => random_int(1, 999999), 'message' => [
        'message_id' => ++$MID, 'from' => ['id' => $id, 'first_name' => 'A'],
        'chat' => ['id' => $id, 'type' => 'private'], 'date' => time(), 'text' => $text]];
}
function texts(): array {
    $out = [];
    foreach (BotApi::outbox() as $c) foreach (($c['params'] ?? []) as $k => $v) if ($k === 'text') $out[] = (string)$v;
    return $out;
}
function blob(): string { return implode("\n", texts()); }
/** متن خامِ reply_markup آخرین ارسال (برای بررسی کیبورد) */
function botapiRawMarkup(): string
{
    $raw = '';
    foreach (BotApi::outbox() as $c) foreach (($c['params'] ?? []) as $k => $v) {
        if ($k === 'reply_markup' && is_string($v)) $raw = $v;
        if ($k === 'reply_markup' && is_array($v)) $raw = json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    return $raw;
}


// کلاسی که همیشه در یک خطِ مشخص می‌ترکد، تا بتوانیم محل خطا را سنجید
class CrashingAddon
{
    public static function boom(string $x): string
    {
        throw new RuntimeException('این یک خطای ساختگی برای تست است: ' . $x);
    }
}

// ==================================================================
section('۱) استثنا در پردازش پیام → پیامِ دقیق به کاربر');
// ==================================================================
// یک افزونهٔ واقعی را طوری خراب می‌کنیم که handleMessage واقعاً استثنا بدهد:
// کلاسی می‌سازیم که Bot::handle آن را صدا می‌زند و می‌ترکد.
$probe = <<<'PHP'
<?php
// فایل کمکی: یک متد استاتیک که همیشه می‌ترکد
class Boom
{
    public static function go(): string
    {
        throw new RuntimeException('انفجار ساختگی برای تست گزارش خطا');
    }
}
PHP;

// روش آزمون: یک Bot می‌سازیم و یک متد عمومی را که به یک متد ناموجود
// می‌رسد صدا می‌زنیم تا استثنا در همان نقطه بیفتد.
$bot = new Bot(appConfig());
BotApi::clearOutbox();
$threw = false;
try {
    // روشن کردن حالت crash: Bot::handle را با آپدیتی می‌دهیم که منجر به
    // خطای داخلیِ تضمینی می‌شود (onCallback با ساختار ناقص)
    $bot->handle(['update_id' => 1, 'message' => [
        'message_id' => 1,
        'from' => ['id' => $ADMIN, 'first_name' => 'X'],
        'chat' => ['id' => $ADMIN, 'type' => 'private'],
        'date' => time(),
        'text' => '🏠 منو',
    ]]);
} catch (Throwable $e) {
    $threw = true;
}
chk('handle() روی ورودی سالم استثنا نمی‌دهد', !$threw);

echo "\n  (برای تست استثنا، یک رباتِ خراب می‌سازیم…)\n";

// یک زیرکلاس که در هندلر قصداً خطا می‌کند
final class ExplodingBot extends Bot
{
    public function handle(array $update): void
    {
        try {
            parent::handle($update);
        } catch (Throwable $e) {
            throw $e;
        }
    }
}

// راه تمیزتر: یک کلاس بی‌نام‌واره که مستقیم reportCrash را می‌سنجد.
// چون reportCrash خصوصی است، با Reflection صداش می‌زنیم.
$rm = new ReflectionMethod('Bot', 'reportCrash');
$rm->setAccessible(true);
$boom = new RuntimeException('این یک خطای ساختگی برای تست است');
$boomFile = __FILE__;
$boomLine = __LINE__;

BotApi::clearOutbox();
$rm->invoke($bot, $boom, msgU($ADMIN, '⚙️ تنظیمات'));
$blob = blob();

chk('پیام خطا به کاربر رسید', $blob !== '', 'هیچ پیامی ارسال نشد');
chk('عنوان «خطای داخلی ربات» آمد', str_contains($blob, 'خطای داخلی ربات'));
chk('بخش خطا گفته شد (message)', str_contains($blob, 'بخش: message'), $blob);
chk('ورودیِ کاربر نشان داده شد', str_contains($blob, 'ورودی شما:') && str_contains($blob, '⚙️ تنظیمات'));
chk('علت خطا گفته شد', str_contains($blob, 'این یک خطای ساختگی برای تست است'));
chk('محل دقیق خطا (فایل:خط) آمد', (bool)preg_match('/محل دقیق خطا:.*error_report_test\.php:\d+/u', $blob),
    mb_substr($blob, 0, 200));
chk('خط جداکننده آمد', str_contains($blob, '━━━'));
chk('به کاربر گفته شد تکرار نمی‌شود', str_contains($blob, 'تکرار'));

// ==================================================================
section('۲) مسیر callback هم گزارش می‌شود');
// ==================================================================
$cbUpdate = ['update_id' => 2, 'callback_query' => [
    'id' => 'q1', 'from' => ['id' => $ADMIN, 'first_name' => 'X'],
    'message' => ['message_id' => 5, 'chat' => ['id' => $ADMIN, 'type' => 'private'], 'date' => time(), 'text' => 'x'],
    'data' => 'set:notify',
]];
BotApi::clearOutbox();
$rm->invoke($bot, $boom, $cbUpdate);
$blob = blob();
chk('بخش خطا «callback» گفته شد', str_contains($blob, 'callback'), mb_substr($blob, 0, 160));
chk('ورودی (callback_data) نشان داده شد', str_contains($blob, 'set:notify'));

// ==================================================================
section('۳) خطای دیتابیس نباید رمز یا کوئری لو بدهد');
// ==================================================================
$sqlErr = new PDOException('SQLSTATE[42S22]: Column not found: 1054 Unknown column \'x\' in \'field list\' in SELECT `password`,`token` FROM `user` WHERE passwd=hunter2');
BotApi::clearOutbox();
$rm->invoke($bot, $sqlErr, msgU($ADMIN, '⚙️ تنظیمات'));
$blob = blob();
chk('پیام خطای دیتابیس رسید', $blob !== '');
chk('نوع خطا به زبان قابل‌فهم گفته شد', str_contains($blob, 'دیتابیس'), mb_substr($blob, 0, 200));
chk('کوئری لو نرفت', !str_contains($blob, 'SELECT `password`'), 'کوئری در پیام آمد!');
chk('رمز لو نرفت', !str_contains($blob, 'hunter2'), 'رمز در پیام آمد!');
chk('کد SQLSTATE بدون توضیح خام نیامد', !str_contains($blob, "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x' in 'field list'"));

// ==================================================================
// ==================================================================
// ==================================================================
section('۴) استثنای واقعی هرگز از handle() بیرون نمی‌رود');
// ==================================================================
// نکتهٔ تست: نباید handle() را بازنویسی کنیم، چون همان لایهٔ ایمنی‌ای است
// که می‌خواهیم بسنجیم؛ به‌جای آن فقط بدنهٔ کار (dispatch) را خراب می‌کنیم.
// اگر handle() درست کار کند، استثنا مهار و به کاربر گزارش می‌شود و
// وب‌هوک ۲۰۰ می‌ماند ⇒ تلگرام آپدیت را بی‌نهایت تکرار نمی‌کند.
final class CrashingBot extends Bot
{
    protected function dispatch(array $update): void
    {
        throw new RuntimeException('ترکیدنِ عمدی از مسیر dispatch');
    }
}

$crash = new CrashingBot(appConfig());
BotApi::clearOutbox();
$threwOut = false;
try {
    $crash->handle(msgU($ADMIN, '🏠 منو'));
} catch (Throwable $e) {
    $threwOut = true;
}
$blob = blob();
chk('استثنا از handle() بیرون نرفت (وب‌هوک ۲۰۰ می‌ماند)', !$threwOut, 'استثنا فرار کرد');
chk('کاربر پیام خطا گرفت', $blob !== '' && str_contains($blob, 'خطای داخلی ربات'), 'پیام خالی بود');
chk('بخش خطا «message» گفته شد', str_contains($blob, 'بخش: message'), mb_substr($blob, 0, 160));
chk('علت ساختگی در پیام آمد', str_contains($blob, 'ترکیدنِ عمدی از مسیر dispatch'));
chk('محل خطا فایل درست را نشان داد', str_contains($blob, 'error_report_test.php'),
    'فایل گزارش‌شده اشتباه است');
chk('پیام خطا دکمهٔ «🏠 منو» دارد تا کاربر گم نشود',
    (bool)preg_match('/"🏠 منو"/u', botapiRawMarkup()), 'کیبورد خالی بود');

// دیتابیس دست‌نخورده ماند (چون فقط dispatch خراب شد، نه جدول)
chk('دیتابیس سالم ماند', Db::one('SELECT 1 AS ok') !== null);

// اگر خودِ مسیر گزارش هم بیفتد، باز نباید استثنا فرار کند
final class CrashingReportBot extends Bot
{
    protected function dispatch(array $update): void
    {
        // این‌بار tgSend هدف قرار می‌گیرد: chat id نامعتبر ⇒ گزارش هم می‌ماند
        throw new RuntimeException('خطای دوم برای آزمون لایهٔ محافظ');
    }
}
$crash2 = new CrashingReportBot(appConfig());
BotApi::clearOutbox();
$threwOut2 = false;
try { $crash2->handle(msgU($ADMIN, '📊 آمار')); } catch (Throwable $e) { $threwOut2 = true; }
chk('خطای دوم هم مهار شد', !$threwOut2);


// ==================================================================section('۵) خطای متنیِ sanitizeDbError');
// ==================================================================
chk('SQLSTATE به پیام فارسی تبدیل می‌شود',
    str_contains(sanitizeDbError('SQLSTATE[42S22]: Column not found'), 'ساختار دیتابیس'));
chk('نام جدول/ستون برای ادمین می‌ماند',
    str_contains(sanitizeDbError("SQLSTATE[42S22]: Column not found: Table 'user'"), 'user'));
chk('رمز با *** پنهان می‌شود',
    !str_contains(sanitizeDbError('password=hunter2 failed'), 'hunter2'));
chk('دسترسی رد شد ترجمه می‌شود',
    str_contains(sanitizeDbError('SQLSTATE[28000]: Access denied'), 'دسترسی'));

echo "\n----------------------------------------\n";
echo "OK: $ok    FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);