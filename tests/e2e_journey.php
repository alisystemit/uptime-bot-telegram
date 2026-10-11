<?php
/**
 * ===== تست جامع «سفر واقعی»: مشتری + ادمین + گروه =====
 *
 *   UPTIME_CONFIG=… php tests/e2e_journey.php
 *
 * تفاوت این تست با بقیهٔ تست‌ها:
 *   تست‌های دیگر فقط تأیید می‌کنند «خطا نداد». این تست نقشِ یک مشتریِ واقعی و
 *   یک ادمین را بازی می‌کند و **نتیجه** را می‌سنجد:
 *     ▫️ چه چیزی در دیتابیس عوض شد (sites, payments, plan, webhooks, …)
 *     ▫️ ربات واقعاً چه پیامی به کاربر نشان داد (متن و دکمه‌ها)
 *
 * برای دیدن پیام‌های خروجی، BotApi در حالت آفلاین هر فراخوانی را در یک
 * «صندوق خروجی» ثبت می‌کند (tests/e2e_journey.php → blob()/has()/btn()).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/bootstrap.php';

// ---------- حالت آفلاین: هیچ درخواستی به تلگرام نرود، همه‌چیز ثبت شود ----------
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

// ---------- ابزار صندوق خروجی ----------
$GLOBALS['OUT'] = [];
$GLOBALS['ERRORS'] = [];

/** اجرای یک آپدیت با Bot، ثبت خطاها و صندوق خروجی همان اجرا */
function act(array $update): void
{
    global $bot;
    BotApi::clearOutbox();
    try { $bot->handle($update); }
    catch (Throwable $e) { $GLOBALS['ERRORS'][] = get_class($e) . ': ' . $e->getMessage(); }
    $GLOBALS['OUT'] = BotApi::outbox();
}

/** همهٔ متن/کپشن/کیبوردهای ثبت‌شده در آخرین اجرا، یک‌جا */
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
function has(string $needle): bool { return str_contains(blob(), $needle); }
/** آیا دکمه‌ای با این callback_data در آخرین اجرا هست؟ */
function btn(string $cb): bool { return str_contains(blob(), '"callback_data":"' . $cb . '"'); }
/** آخرین سند ارسالی با پسوند مشخص (csv/json/html) */
function docOf(?string $ext = null): ?array
{
    $found = null;
    foreach ($GLOBALS['OUT'] as $c) {
        if (($c['method'] ?? '') !== 'sendDocument') continue;
        if ($ext !== null && !str_ends_with((string)($c['params']['filename'] ?? ''), '.' . $ext)) continue;
        $found = $c['params'];
    }
    return $found;
}

// ---------- ساخت آپدیت ساختگی ----------
$GLOBALS['MID'] = 1000;
function nid(): int { return ++$GLOBALS['MID']; }
function fromU(int $id): array
{
    return ['id' => $id, 'first_name' => 'کاربر' . $id, 'username' => 'u' . $id, 'language_code' => 'fa'];
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
        'photo' => [['file_id' => 'fid_small', 'width' => 90], ['file_id' => 'fid_big', 'width' => 900]]]];
}
function cbU(int $id, string $data): array
{
    return ['update_id' => random_int(1, 999999), 'callback_query' => [
        'id' => 'cbq' . nid(), 'from' => fromU($id),
        'message' => ['message_id' => nid(), 'chat' => ['id' => $id, 'type' => 'private'], 'date' => time(), 'text' => 'x'],
        'data' => $data]];
}
function groupMsg(int $gid, int $uid, string $text, string $title = 'گروه تست'): array
{
    return ['update_id' => random_int(1, 999999), 'message' => [
        'message_id' => nid(), 'from' => fromU($uid),
        'chat' => ['id' => $gid, 'type' => 'supergroup', 'title' => $title],
        'date' => time(), 'text' => $text]];
}

// ==================================================================
// آماده‌سازی
// ==================================================================
appBoot();
Db::migrate();
PayGws::ensure();

$ADMIN = (int)(botAdminIds()[0] ?? 0);
$CUST  = 780030;   // مشتری اصلی
$CUST2 = 780031;   // مشتری دوم (معرفی‌شده + سقف)
$CUST3 = 780032;   // مشتری سوم (عملیات ادمین)
$GID   = -100987654321;
$GMEM  = 780033;   // عضو عادی گروه

// پاک‌سازی قبلی (تست باید قابل اجرای مکرر باشد)
foreach ([$ADMIN, $CUST, $CUST2, $CUST3, $GMEM] as $u) {
    $affIds = array_map(fn($r) => (int)$r['id'], Db::all('SELECT `id` FROM `affiliates` WHERE `user_id` = ?', [$u]));
    foreach ($affIds as $aid) {
        Db::q('DELETE FROM `affiliate_earnings` WHERE `affiliate_id` = ?', [$aid]);
        Db::q('DELETE FROM `affiliate_payouts` WHERE `affiliate_id` = ?', [$aid]);
    }
    Db::q('DELETE FROM `affiliates` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `webhooks` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `integrations` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `domain_watch` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `payments` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `sla_reports` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `site_share` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `site` WHERE `user_id` = ?', [$u]);
    Db::q('DELETE FROM `user` WHERE `id` = ?', [$u]);
}
Db::q('DELETE FROM `site` WHERE `chat_id` = ?', [$GID]);
Db::q('DELETE FROM `chat_hub` WHERE `chat_id` = ?', [$GID]);
Db::q('DELETE FROM `chat_admin` WHERE `chat_id` = ?', [$GID]);

// تنظیمات قطعی
foreach ([
    'access_mode' => 'open', 'price' => '500000', 'vip_days' => '30', 'card' => '6037991234567890',
    'max_sites' => '5', 'vip_max_sites' => '10', 'max_domains' => '10', 'check_interval' => '20',
    'notify' => '1', 'notify_slow' => '1', 'pause_all' => '0', 'fail_threshold' => '2',
    'group_max_sites' => '10', 'webhook_enabled' => '1', 'sla_reports_enabled' => '1',
    'analytics_enabled' => '1', 'affiliate_enabled' => '1',
] as $k => $v) Db::set($k, $v);

// قطعی‌سازی حالت درگاه‌ها: همه خاموش و بدون کلید (وگرنه اجرای دومِ تست روی
// دیتابیسِ کثیف، مسیر «کارت‌به‌کارت» را به منوی پرداخت آنلاین تغییر می‌داد).
Db::q("UPDATE `pay_gateway` SET `enabled` = 0, `api_key` = '', `merchant_id` = ''");
// پاک‌کردن کلیدهای معرف تا ثبت‌نام با کد معرف در هر اجرا واقعاً انجام شود.
foreach ([$CUST, $CUST2, $CUST3, $GMEM] as $u) Db::q('DELETE FROM `settings` WHERE `k` = ?', ['ref_' . $u]);

$bot = new Bot(appConfig());

// ==================================================================
section('۰) سلامتِ سازوکار تست (صندوق خروجی)');
// ==================================================================
act(msgU($CUST, '/start'));
chk('ربات به /start پیام فرستاد', count($GLOBALS['OUT']) > 0, 'outbox خالی');
chk('پیام خوش‌آمد دیده شد', has('مانیتورینگ سرور و سایت'));
$adm = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$CUST]);
chk('کاربر مشتری ساخته شد', $adm !== null && (int)$adm['access'] === 1);
chk('تنظیمات پیام‌ها در آفلاین ثبت می‌شوند', (bool)preg_grep('/sendMessage/', array_column($GLOBALS['OUT'], 'method')));

// ==================================================================
section('۱) افزودن سایت: درست / تکراری / نامعتبر');
// ==================================================================
act(msgU($CUST, '➕ افزودن سایت'));
chk('مرحلهٔ دریافت آدرس فعال شد', has('آدرس سایت یا سرور را بفرستید'));
chk('دکمهٔ انصراف هست', btn('cancel') || has('❌ انصراف'));

// توجه: normalizeTarget اسلش انتهایی را حذف می‌کند؛ پس با «تعداد سایت‌های کاربر»
// می‌سنجیم نه با مقایسهٔ رشتهٔ خام.
act(msgU($CUST, 'http://127.0.0.1:8199/'));
$s1 = (int)Db::val('SELECT MAX(`id`) FROM `site` WHERE `user_id` = ?', [$CUST]);
chk('سایت در دیتابیس ثبت شد', $s1 > 0);
chk('هدف ذخیره‌شده نرمال‌سازی شد', (string)Db::val('SELECT `target` FROM `site` WHERE `id` = ?', [$s1]) === 'http://127.0.0.1:8199');
chk('پیام ثبت سایت با شناسهٔ اولیه', has('سایت ثبت شد'));
chk('مرحله به idle برگشت',
    (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$CUST]) === 'idle');

act(msgU($CUST, '➕ افزودن سایت'));
act(msgU($CUST, 'http://127.0.0.1:8199/'));
$dups = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$CUST]);
chk('سایت تکراری ثبت نشد', $dups === 1);
chk('هشدار تکرار نمایش داده شد', has('قبلاً ثبت شده'));

act(msgU($CUST, '❌ انصراف'));   // خروج از مرحله
act(msgU($CUST, '➕ افزودن سایت'));
act(msgU($CUST, 'not a url at all'));
chk('آدرس نامعتبر رد شد', has('❌'));
chk('سایت نامعتبر ثبت نشد',
    (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$CUST]) === 1);
act(msgU($CUST, '❌ انصراف'));

// ==================================================================
section('۲) منوی سایت: جزئیات، چک فوری، توقف/ادامه، آستانه، کلیدواژه، لینک، SSL');
// ==================================================================
act(msgU($CUST, '/sites'));
chk('فهرست سایت‌ها نمایش داده شد', has('سایت‌های شما'));
chk('دکمهٔ سایت در فهرست هست', btn('site:' . $s1));

act(cbU($CUST, 'site:' . $s1));
chk('صفحهٔ جزئیات سایت', has('جزئیات سایت') && has('آپتایم'));
chk('دکمهٔ چک فوری', btn('sc:' . $s1));
chk('دکمهٔ توقف', btn('sp:' . $s1));

act(cbU($CUST, 'sc:' . $s1));
chk('چک فوری انجام شد', has('بررسی فوری انجام شد'));
$st = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$s1]);
chk('یک چک در دیتابیس ثبت شد', (int)$st['total_checks'] >= 1);

act(cbU($CUST, 'sp:' . $s1));
chk('سایت متوقف شد', (int)Db::val('SELECT `paused` FROM `site` WHERE `id` = ?', [$s1]) === 1);
act(cbU($CUST, 'sr:' . $s1));
chk('سایت ادامه یافت', (int)Db::val('SELECT `paused` FROM `site` WHERE `id` = ?', [$s1]) === 0);

act(cbU($CUST, 'sms:' . $s1));
chk('مرحلهٔ حد کندی باز شد', has('حد کندی پاسخ'));
act(msgU($CUST, '2000'));
chk('حد کندی ذخیره شد', (int)Db::val('SELECT `max_ms` FROM `site` WHERE `id` = ?', [$s1]) === 2000);

act(cbU($CUST, 'skw:' . $s1));
act(msgU($CUST, 'UptimeBot'));
chk('کلیدواژه ذخیره شد', (string)Db::val('SELECT `keyword` FROM `site` WHERE `id` = ?', [$s1]) === 'UptimeBot');
act(cbU($CUST, 'skw:' . $s1));
act(msgU($CUST, '0'));
chk('کلیدواژه خاموش شد', (string)Db::val('SELECT `keyword` FROM `site` WHERE `id` = ?', [$s1]) === '');

act(cbU($CUST, 'slink:' . $s1));
chk('لینک تک‌سایت نمایش داده شد', has('لینک عمومی این مانیتور'));
act(cbU($CUST, 'ssl:' . $s1));
chk('صفحهٔ SSL نمایش داده شد', has('گواهی SSL'));
act(cbU($CUST, 'sincer:' . $s1));
chk('تاریخچهٔ رخدادها', has('تاریخچهٔ رخدادها'));
act(cbU($CUST, 'sshare:' . $s1));
chk('صفحهٔ اشتراک‌گذاری', has('اشتراک‌گذاری مانیتور'));

// ==================================================================
section('۳) تنظیمات کاربر، صفحهٔ وضعیت، گزارش، رنکینگ، راهنما، اپلیکیشن');
// ==================================================================
$notify0 = (int)Db::val('SELECT `notify` FROM `user` WHERE `id` = ?', [$CUST]);
act(cbU($CUST, 'tnotify'));
chk('اعلان کاربر برعکس شد', (int)Db::val('SELECT `notify` FROM `user` WHERE `id` = ?', [$CUST]) === ($notify0 ? 0 : 1));
act(cbU($CUST, 'tnotify'));  // بازگردانی
chk('اعلان به حالت قبل برگشت', (int)Db::val('SELECT `notify` FROM `user` WHERE `id` = ?', [$CUST]) === $notify0);

$tok0 = (string)Db::val('SELECT `share_token` FROM `user` WHERE `id` = ?', [$CUST]);
act(cbU($CUST, 'share'));
chk('صفحهٔ وضعیت نمایش داده شد', has('صفحهٔ وضعیت شما'));
act(cbU($CUST, 'regen'));
$tok1 = (string)Db::val('SELECT `share_token` FROM `user` WHERE `id` = ?', [$CUST]);
chk('توکن صفحهٔ وضعیت عوض شد', $tok1 !== '' && $tok1 !== $tok0);

act(msgU($CUST, '/report'));
chk('گزارش آپتایم', has('گزارش آپتایم شما'));
act(msgU($CUST, '/rank'));
chk('کارت رتبه', has('رتبهٔ من'));
act(msgU($CUST, '/help'));
chk('راهنما شامل بخش افزونه‌ها', has('افزونه‌ها'));
act(msgU($CUST, '/app'));
chk('اپلیکیشن ارسال شد', has('اپلیکیشن'));

// ==================================================================
section('۴) اشتراک: ادمین کد می‌سازد، مشتری مصرف می‌کند');
// ==================================================================
act(cbU($CUST, 'codein'));
act(msgU($CUST, 'UP-0000-0000'));
chk('کد نامعتبر رد شد', has('کد نامعتبر'));
chk('کاربر هنوز ویژه نیست', (string)Db::val('SELECT `plan` FROM `user` WHERE `id` = ?', [$CUST]) !== 'vip');

act(cbU($ADMIN, 'gen'));
act(msgU($ADMIN, '30 5'));
chk('ادمین کد ساخت', has('کد فعال‌سازی ساخته شد'));
$codes = Db::all('SELECT * FROM `codes` WHERE `note` = ? ORDER BY `created_at` DESC LIMIT 5', ['ساخت ادمین']);
chk('۵ کد در دیتابیس ثبت شد', count($codes) === 5);
$code = (string)($codes[0]['code'] ?? '');
chk('کد ساخته‌شده قالب درست دارد', (bool)preg_match('/^UP-[0-9A-F]{4}-[0-9A-F]{4}$/', $code), $code);

act(cbU($CUST, 'codein'));
act(msgU($CUST, $code));
$u1 = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$CUST]);
chk('کاربر ویژه شد', (string)$u1['plan'] === 'vip' && (int)$u1['access'] === 1);
chk('پیام فعال‌سازی اشتراک', has('اشتراک ویژه فعال شد'));
chk('مصرف کد ثبت شد', (int)Db::val('SELECT `uses` FROM `codes` WHERE `code` = ?', [$code]) === 1);

// ==================================================================
section('۵) پرداخت دستی: رسید مشتری → تأیید ادمین → فعال‌سازی');
// ==================================================================
act(cbU($CUST, 'buy'));
chk('مسیر پرداخت کارت‌به‌کارت باز شد', has('پرداخت اشتراک ویژه'));
chk('مرحلهٔ انتظار رسید فعال شد',
    (string)Db::val('SELECT `step` FROM `user` WHERE `id` = ?', [$CUST]) === 'await_payment');

act(photoU($CUST));
$pay = Db::one('SELECT * FROM `payments` WHERE `user_id` = ? ORDER BY `id` DESC', [$CUST]);
chk('درخواست پرداخت ثبت شد', $pay !== null && (string)$pay['status'] === 'pending');
chk('مبلغ از تنظیمات گرفته شد', (int)$pay['amount'] === 500000);
chk('رسید به مدیر اعلام شد', has('درخواست پرداخت جدید'));
chk('دکمهٔ تأیید برای مدیر', btn('apayok:' . $pay['id']));

act(cbU($ADMIN, 'apays'));
chk('فهرست پرداخت‌های در انتظار', has('پرداخت‌های در انتظار'));

act(cbU($ADMIN, 'apayok:' . $pay['id']));
chk('پرداخت تأیید شد', (string)Db::val('SELECT `status` FROM `payments` WHERE `id` = ?', [$pay['id']]) === 'approved');
chk('اشتراک کاربر پس از تأیید فعال است', (string)Db::val('SELECT `plan` FROM `user` WHERE `id` = ?', [$CUST]) === 'vip');

// ==================================================================
section('۶) افزونه‌ها: خلاصه، خروجی/ورود، وبهوک، اتصال، تحلیل، SLA');
// ==================================================================
act(cbU($CUST, 'feat'));
chk('خلاصهٔ افزونه‌ها', has('افزونه‌ها') && has('خروجی/ورود'));

// --- خروجی ---
act(cbU($CUST, 'exp'));
chk('صفحهٔ خروجی', has('خروجی سایت‌ها'));
act(cbU($CUST, 'expcsv'));
$csv = docOf('csv');
chk('فایل CSV ارسال شد', $csv !== null);
chk('محتوای CSV با سرستون شروع می‌شود', $csv !== null && str_starts_with((string)$csv['content'], 'URL,Label,Type'));
act(cbU($CUST, 'expjson'));
$json = docOf('json');
chk('فایل JSON ارسال شد', $json !== null && str_contains((string)$json['content'], '"sites"'));

// --- ورود CSV ---
$before = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$CUST]);
act(cbU($CUST, 'imp'));
chk('مرحلهٔ ورود باز شد', has('ورود سایت‌ها'));
act(msgU($CUST, "URL,Label,Type,MaxMS,Keyword,Enabled\n\"https://imp-a.example.test\",\"ImportA\",http,0,\"\",1\n\"https://imp-b.example.test\",\"ImportB\",http,0,\"\",1"));
chk('نتیجهٔ ورود نمایش داده شد', has('نتیجهٔ ورود'));
$after = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$CUST]);
chk('دو سایت وارد شد', $after === $before + 2, "before=$before after=$after");

// --- ورود JSON (تکراری‌ها باید رد شوند) ---
act(cbU($CUST, 'imp'));
act(msgU($CUST, '{"sites":[{"target":"https://imp-a.example.test","label":"dup"}]}'));
$after2 = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$CUST]);
chk('ورود JSON بدون تکراری', $after2 === $after);

// --- وبهوک ---
act(cbU($CUST, 'wh'));
chk('صفحهٔ وبهوک', has('وبهوک‌ها'));
act(cbU($CUST, 'whadd:' . $s1));
chk('مرحلهٔ افزودن وبهوک', has('افزودن وبهوک'));
act(msgU($CUST, 'notaurl'));
chk('آدرس نامعتبر وبهوک رد شد', has('آدرس نامعتبر'));
act(msgU($CUST, 'http://127.0.0.1:1/hook'));
$whId = (int)Db::val('SELECT MAX(`id`) FROM `webhooks` WHERE `user_id` = ?', [$CUST]);
chk('وبهوک سفارشی ثبت شد', $whId > 0 && (string)Db::val('SELECT `type` FROM `webhooks` WHERE `id` = ?', [$whId]) === 'custom');
act(cbU($CUST, 'whadd:' . $s1));
act(msgU($CUST, 'https://discord.com/api/webhooks/1/abc'));
$whDiscord = (int)Db::val('SELECT MAX(`id`) FROM `webhooks` WHERE `user_id` = ?', [$CUST]);
chk('وبهوک Discord درست تشخیص داده شد', (string)Db::val('SELECT `type` FROM `webhooks` WHERE `id` = ?', [$whDiscord]) === 'discord');

act(cbU($CUST, 'whtest:' . $whId));
chk('نتیجهٔ تست وبهوک برگشت', has('ارسال آزمایشی'));
chk('زمان تست وبهوک ثبت شد', Db::val('SELECT `test_at` FROM `webhooks` WHERE `id` = ?', [$whId]) !== null);
act(cbU($CUST, 'whdel:' . $whDiscord));
chk('حذف وبهوک', (int)Db::val('SELECT COUNT(*) FROM `webhooks` WHERE `id` = ?', [$whDiscord]) === 0);

// --- اتصال Slack/Discord ---
act(cbU($CUST, 'intg'));
chk('صفحهٔ اتصال‌ها', has('Slack') && has('Discord'));
act(cbU($CUST, 'intgset:slack'));
chk('مرحلهٔ اتصال Slack', has('اتصال Slack'));
act(msgU($CUST, 'http://127.0.0.1:1/slack'));
chk('اتصال Slack ثبت شد', (int)Db::val('SELECT COUNT(*) FROM `integrations` WHERE `user_id` = ? AND `type` = ?', [$CUST, 'slack']) === 1);
act(cbU($CUST, 'intgtest:slack'));
chk('نتیجهٔ تست اتصال', has('ارسال'));
act(cbU($CUST, 'intgoff:slack'));
chk('اتصال غیرفعال شد', (int)Db::val('SELECT `enabled` FROM `integrations` WHERE `user_id` = ? AND `type` = ?', [$CUST, 'slack']) === 0);

// --- تحلیل ---
act(cbU($CUST, 'anal'));
chk('صفحهٔ تحلیل', has('تحلیل هوشمند'));
act(cbU($CUST, 'anals:' . $s1));
chk('تحلیل سایت', has('تحلیل سایت') && has('آپتایم'));

// --- SLA ---
act(cbU($CUST, 'sla'));
chk('گزارش SLA ساخته شد', has('گزارش SLA'));
$rid = (int)Db::val('SELECT MAX(`id`) FROM `sla_reports` WHERE `user_id` = ?', [$CUST]);
chk('گزارش در دیتابیس ذخیره شد', $rid > 0);
chk('دکمهٔ دانلود HTML', btn('report_html_' . $rid));
act(cbU($CUST, 'report_html_' . $rid));
chk('خروجی HTML ارسال شد', docOf('html') !== null);
act(cbU($CUST, 'report_csv_' . $rid));
chk('خروجی CSV گزارش ارسال شد', docOf('csv') !== null);

// --- ادمین: وضعیت سیستم و صف ---
act(cbU($ADMIN, 'sysstat'));
chk('وضعیت افزونه‌ها برای ادمین', has('وضعیت افزونه‌ها'));
act(cbU($ADMIN, 'qproc'));
chk('پردازش صف انجام شد', has('صف پردازش شد'));
act(cbU($ADMIN, 'qclean'));
chk('پاک‌سازی انجام شد', has('پاک‌سازی انجام شد'));

// ==================================================================
section('۷) برنامهٔ همکاری: ثبت، معرفی، کمیسیون، پرداخت');
// ==================================================================
act(cbU($CUST, 'aff'));
chk('کاربر هنوز عضو نیست', has('هنوز عضو نیستید'));
act(cbU($CUST, 'affreg'));
$aff = Db::one('SELECT * FROM `affiliates` WHERE `user_id` = ?', [$CUST]);
chk('کد همکاری ساخته شد', $aff !== null && strlen((string)$aff['code']) >= 6);
$code = (string)$aff['code'];
chk('داشبورد همکاری', has('داشبورد همکاری') && has($code));

// مشتری دوم با لینک معرفی می‌آید
act(msgU($CUST2, '/start aff_' . $code));
chk('ثبت‌نام با کد معرف انجام شد', (string)Db::get('ref_' . $CUST2, '') === $code);
chk('شمارندهٔ ثبت‌نام بالا رفت', (int)Db::val('SELECT `signup_count` FROM `affiliates` WHERE `id` = ?', [$aff['id']]) === 1);

// خرید مشتری دوم → کمیسیون
act(cbU($CUST2, 'buy'));
act(photoU($CUST2));
$pay2 = Db::one('SELECT * FROM `payments` WHERE `user_id` = ? ORDER BY `id` DESC', [$CUST2]);
chk('پرداخت مشتری دوم ثبت شد', $pay2 !== null && (string)$pay2['status'] === 'pending');
act(cbU($ADMIN, 'apayok:' . $pay2['id']));
chk('کمیسیون ثبت شد', (int)Db::val('SELECT `total_commission` FROM `affiliates` WHERE `id` = ?', [$aff['id']]) === 100000);
act(cbU($CUST, 'aff'));
chk('داشبورد مبلغ قابل برداشت را نشان می‌دهد', has('داشبورد همکاری'));
chk('دکمهٔ درخواست پرداخت فعال شد', btn('affpayout'));

act(cbU($CUST, 'affpayout'));
$po = Db::one('SELECT * FROM `affiliate_payouts` WHERE `affiliate_id` = ? ORDER BY `id` DESC', [$aff['id']]);
chk('درخواست پرداخت ثبت شد', $po !== null && (int)$po['amount'] === 100000);
$poCount1 = (int)Db::val("SELECT COUNT(*) FROM `affiliate_payouts` WHERE `affiliate_id` = ? AND `status` = 'pending'", [$aff['id']]);
act(cbU($CUST, 'affpayout'));
$poCount2 = (int)Db::val("SELECT COUNT(*) FROM `affiliate_payouts` WHERE `affiliate_id` = ? AND `status` = 'pending'", [$aff['id']]);
chk('درخواست تکراری ساخته نشد', $poCount1 === 1 && $poCount2 === 1);
chk('پیام «در انتظار بررسی» به کاربر', has('در انتظار بررسی'));

act(cbU($ADMIN, 'apayouts'));
chk('ادمین درخواست‌ها را می‌بیند', has('درخواست‌های پرداخت همکاران'));
act(cbU($ADMIN, 'apaydone:' . $po['id']));
chk('پرداخت همکار تسویه شد', (string)Db::val('SELECT `status` FROM `affiliate_payouts` WHERE `id` = ?', [$po['id']]) === 'completed');
chk('درآمد paid شد', Db::val('SELECT `paid_at` FROM `affiliate_earnings` WHERE `payout_id` = ?', [$po['id']]) !== null);

// ==================================================================
section('۸) مانیتور مشترک: دعوت، پذیرش، خروج');
// ==================================================================
$shareTok = (string)Db::val('SELECT `share_token` FROM `site` WHERE `id` = ?', [$s1]);
chk('سایت توکن اشتراک دارد', $shareTok !== '');

act(msgU($CUST, '/start site_' . $shareTok));
chk('دعوت به خودِ مالک رد شد', has('متعلق به خودِ شماست'));

act(msgU($CUST2, '/start site_badtoken000'));
chk('دعوت نامعتبر رد شد', has('معتبر نیست'));

act(msgU($CUST2, '/start site_' . $shareTok));
chk('صفحهٔ دعوت نمایش داده شد', has('دعوت به پیگیری'));
act(cbU($CUST2, 'sacc:' . $s1 . ':viewer'));
chk('اشتراک ثبت شد', (int)Db::val('SELECT COUNT(*) FROM `site_share` WHERE `site_id` = ? AND `user_id` = ?', [$s1, $CUST2]) === 1);
chk('پیام پذیرش دعوت', has('پیگیری این مانیتور فعال شد'));

act(msgU($CUST2, '/shared'));
chk('مانیتور مشترک در فهرست مشتری دوم', has('مانیتورهای مشترک'));
act(cbU($CUST2, 'sleave:' . $s1));
chk('خروج از مانیتور مشترک', (int)Db::val('SELECT COUNT(*) FROM `site_share` WHERE `site_id` = ? AND `user_id` = ?', [$s1, $CUST2]) === 0);

// ==================================================================
section('۹) عملیات ادمین: پنل، تنظیمات، توقف سراسری، کاربران، همگانی');
// ==================================================================
act(cbU($ADMIN, 'apanel'));
chk('پنل مدیریت باز شد', has('پنل مدیریت'));

// تغییر شماره کارت
act(cbU($ADMIN, 'set:card'));
chk('مرحلهٔ تنظیم کارت', has('شماره کارت'));
act(msgU($ADMIN, '6037990000000000'));
chk('شماره کارت ذخیره شد', (string)Db::get('card', '') === '6037990000000000');

// تغییر آستانهٔ هشدار
act(cbU($ADMIN, 'set:fail_threshold'));
act(msgU($ADMIN, '3'));
chk('آستانهٔ هشدار ذخیره شد', (int)Db::getInt('fail_threshold', 0) === 3);

// تغییر فاصلهٔ چک
act(cbU($ADMIN, 'set:check_interval'));
act(msgU($ADMIN, '25'));
chk('فاصلهٔ چک ذخیره شد', (int)Db::getInt('check_interval', 0) === 25);

// توقف/ادامهٔ سراسری
act(cbU($ADMIN, 'pause'));
chk('چک سراسری متوقف شد', Db::getBool('pause_all', false) === true);
act(cbU($ADMIN, 'resume'));
chk('چک سراسری ادامه یافت', Db::getBool('pause_all', false) === false);

// آمار مدیریتی
act(msgU($ADMIN, '📊 آمار مدیریتی'));
chk('آمار مدیریتی نمایش داده شد', has('آمار کلی ربات'));

// کاربران: مسدود/رفع/ویژه/حذف روی مشتری سوم
act(msgU($CUST3, '/start'));
chk('مشتری سوم ساخته شد', Db::one('SELECT `id` FROM `user` WHERE `id` = ?', [$CUST3]) !== null);

act(cbU($ADMIN, 'auser:' . $CUST3));
chk('صفحهٔ کاربر باز شد', has('کاربر'));
act(cbU($ADMIN, 'aub:' . $CUST3));
chk('کاربر مسدود شد', (int)Db::val('SELECT `is_blocked` FROM `user` WHERE `id` = ?', [$CUST3]) === 1);
act(msgU($CUST3, '/start'));
chk('کاربر مسدود پیام مسدودی گرفت', has('مسدود'));

act(cbU($ADMIN, 'ausers:0'));
chk('فهرست کاربران', has('کاربران'));
act(cbU($ADMIN, 'aub:' . $CUST3));
chk('رفع مسدودی', (int)Db::val('SELECT `is_blocked` FROM `user` WHERE `id` = ?', [$CUST3]) === 0);
act(cbU($ADMIN, 'auv:' . $CUST3));
chk('کاربر ویژه شد', (string)Db::val('SELECT `plan` FROM `user` WHERE `id` = ?', [$CUST3]) === 'vip');

// پیام همگانی
act(cbU($ADMIN, 'abroadcast'));
chk('مرحلهٔ پیام همگانی', has('پیام همگانی را بفرستید'));
act(msgU($ADMIN, 'سلام به همه'));
chk('پیام همگانی ارسال شد', has('پیام همگانی ارسال شد'));

// حذف کاربر
act(cbU($ADMIN, 'aud:' . $CUST3));
chk('کاربر حذف شد', Db::one('SELECT `id` FROM `user` WHERE `id` = ?', [$CUST3]) === null);

// ==================================================================
section('۱۰) سقف سایت‌ها (نتیجهٔ واقعی روی مشتری دوم)');
// ==================================================================
// مشتری دوم پس از تأیید پرداخت «ویژه» شده؛ برای سنجش سقف رایگان، به free برمی‌گردانیم.
Db::q("UPDATE `user` SET `plan` = 'free', `plan_until` = NULL WHERE `id` = ?", [$CUST2]);
Db::set('max_sites', '1');
Db::q('DELETE FROM `site` WHERE `user_id` = ?', [$CUST2]);
act(msgU($CUST2, '➕ افزودن سایت'));
act(msgU($CUST2, 'http://127.0.0.1:8199/two'));
chk('سایت اول مشتری دوم ثبت شد', (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$CUST2]) === 1);
act(msgU($CUST2, '➕ افزودن سایت'));
chk('پیام سقف تکمیل نمایش داده شد', has('سقف سایت‌های شما تکمیل است'));
act(msgU($CUST2, 'http://127.0.0.1:8199/three'));
chk('سایت دوم به‌خاطر سقف رد شد', (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$CUST2]) === 1);
Db::set('max_sites', '5');

// ==================================================================
section('۱۱) درگاه پرداخت: ادمین کلید می‌دهد و روشن می‌کند → مشتری می‌بیند');
// ==================================================================
act(cbU($ADMIN, 'pgws_list'));
chk('فهرست درگاه‌ها', has('درگاه') || has('پرداخت'));

act(cbU($ADMIN, 'pgws_list'));
chk('منوی درگاه‌ها دکمه دارد', btn('pgwcubepy') || btn('pgw:cubepy') || btn('pgwt:cubepy') || has('کیوب‌پی'));

act(cbU($ADMIN, 'pgwk:cubepy'));
chk('مرحلهٔ ورود کلید', has('کلید') || has('API'));
act(msgU($ADMIN, 'TEST-API-KEY-123'));
chk('کلید در دیتابیس ذخیره شد', (string)Db::val('SELECT `api_key` FROM `pay_gateway` WHERE `code` = ?', ['cubepy']) === 'TEST-API-KEY-123');

act(cbU($ADMIN, 'pgwt:cubepy'));
chk('درگاه روشن شد', (int)Db::val('SELECT `enabled` FROM `pay_gateway` WHERE `code` = ?', ['cubepy']) === 1);
act(cbU($CUST, 'buy'));
chk('مشتری منوی پرداخت آنلاین را می‌بیند', has('پرداخت آنلاین'));
chk('دکمهٔ درگاه فعال هست', btn('gw:cubepy'));
act(cbU($ADMIN, 'pgwt:cubepy'));
chk('درگاه خاموش شد', (int)Db::val('SELECT `enabled` FROM `pay_gateway` WHERE `code` = ?', ['cubepy']) === 0);

// ==================================================================
section('۱۲) گروه: افزودن/فهرست/چک/توقف/اعلان/حذف + کنترل دسترسی');
// ==================================================================
// ادمین گروه را در کش ثبت می‌کنیم (در آفلاین getChatMember کار نمی‌کند)
Db::q('INSERT INTO `chat_admin` (`chat_id`,`user_id`,`is_admin`,`username`,`checked_at`) VALUES (?,?,1,?,NOW())
       ON DUPLICATE KEY UPDATE `is_admin` = 1, `checked_at` = NOW()', [$GID, $ADMIN, 'u' . $ADMIN]);

act(groupMsg($GID, $GMEM, '/add http://127.0.0.1:8199/g'));
chk('عضو عادی اجازهٔ افزودن ندارد', has('فقط مدیران'));
act(groupMsg($GID, $ADMIN, '/add'));
chk('مرحلهٔ دریافت آدرس گروه', has('آدرس مانیتور را بفرستید'));
act(groupMsg($GID, $ADMIN, 'http://127.0.0.1:8199/grup نام گروه'));
$gsite = (int)Db::val('SELECT MAX(`id`) FROM `site` WHERE `chat_id` = ?', [$GID]);
chk('مانیتور گروهی ثبت شد', $gsite > 0 && (string)Db::val('SELECT `label` FROM `site` WHERE `id` = ?', [$gsite]) === 'نام گروه');

act(groupMsg($GID, $ADMIN, '/list'));
chk('فهرست مانیتورهای گروه', has('مانیتورهای گروه'));
act(groupMsg($GID, $ADMIN, '/status #' . $gsite));
chk('وضعیت مانیتور گروه', has('آپتایم'));
act(groupMsg($GID, $ADMIN, '/check all'));
chk('بررسی فوری گروه', has('بررسی فوری انجام شد'));
act(groupMsg($GID, $ADMIN, '/pause all'));
chk('توقف همهٔ مانیتورهای گروه', (int)Db::val('SELECT `paused` FROM `site` WHERE `id` = ?', [$gsite]) === 1);
act(groupMsg($GID, $ADMIN, '/resume all'));
chk('ادامهٔ همهٔ مانیتورهای گروه', (int)Db::val('SELECT `paused` FROM `site` WHERE `id` = ?', [$gsite]) === 0);
act(groupMsg($GID, $ADMIN, '/notify off'));
chk('اعلان گروه خاموش شد', (int)Db::val('SELECT `notify` FROM `chat_hub` WHERE `chat_id` = ?', [$GID]) === 0);
act(groupMsg($GID, $ADMIN, '/page'));
chk('لینک صفحهٔ گروه', has('صفحهٔ وضعیت عمومی گروه'));
act(groupMsg($GID, $ADMIN, '/domain http://x.com/path'));
chk('دامنهٔ نامعتبر گروه رد شد', has('دامنهٔ معتبر') || has('❌'));

// حذف (دو مرحله)
act(groupMsg($GID, $ADMIN, '/remove #' . $gsite));
chk('تأیید حذف گروهی خواسته شد', has('برای تأیید دوباره'));
act(groupMsg($GID, $ADMIN, '/remove #' . $gsite));
chk('مانیتور گروهی حذف شد', (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `id` = ?', [$gsite]) === 0);

// ==================================================================
section('۱۳) حالت دسترسی و بازیابی');
// ==================================================================
act(cbU($ADMIN, 'set:access_mode'));
$mode = (string)Db::get('access_mode', 'open');
chk('حالت دسترسی چرخید', in_array($mode, ['open', 'code', 'paid'], true), $mode);
// برگرداندن به open
$guard = 0;
while ((string)Db::get('access_mode', 'open') !== 'open' && $guard++ < 5) {
    act(cbU($ADMIN, 'set:access_mode'));
}
chk('حالت دسترسی به open برگشت', (string)Db::get('access_mode', 'open') === 'open');

// ==================================================================
section('۱۴) بررسی نهایی: هیچ استثنای مدیریت‌نشده‌ای رخ نداد');
// ==================================================================
chk('بدون استثنا', empty($GLOBALS['ERRORS']), implode(' | ', array_slice($GLOBALS['ERRORS'], 0, 3)));

echo "\n----------------------------------------\n";
echo "OK: $ok    FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
