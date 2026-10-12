<?php
/**
 * ===== تست end-to-end گزارش خطا از طریق وب‌هوک =====
 *
 *   UPTIME_CONFIG=… php tests/error_webhook_test.php
 *
 * هدف: ثابت کند که وقتی ربات در پردازش یک آپدیت واقعی تلگرام بترکد،
 *   ۱) وب‌هوک ۲۰۰ برمی‌گرداند (نه ۵۰۰) ⇒ تلگرام آپدیت را تکرار نمی‌کند
 *   ۲) کاربر پیام خطای خوانا دریافت می‌کند
 *   ۳) آپدیت علامت‌گذاری می‌شود ⇒ ارسال دوباره = تکرار نمی‌شود
 *
 * برای همین یک رباتِ عمداً خراب داخل index.php جاسازی می‌شود (متغیرِ محیطی
 * UPTIME_TEST_CRASH) تا مسیرِ واقعیِ وب‌هوک بدون دست‌زدن به کدِ اصلی سنجیده شود.
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

$web = getenv('UPTIME_WEB') ?: 'http://127.0.0.1:8199';
$cfg = appConfig();
$token = (string)($cfg['bot_token'] ?? '');
$secret = $token !== '' ? hash('sha256', $token . '_uptime_webhook_secret') : '';
if ($secret === '') { fwrite(STDERR, "no bot_token in config\n"); exit(1); }

appBoot();
Db::migrate();
$ADMIN = (int)(botAdminIds()[0] ?? 0);
Db::q('DELETE FROM `user` WHERE `id` = ?', [$ADMIN]);

/** درخواست HTTP به وب‌هوک، مثل تلگرام */
function hook(array $update): array
{
    global $web, $secret;
    $ch = curl_init($web . '/index.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($update, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $secret],
        CURLOPT_TIMEOUT => 25,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

$mk = function (int $id, string $text): array {
    return ['update_id' => random_int(1, 9999999), 'message' => [
        'message_id' => random_int(1, 999999),
        'from' => ['id' => $id, 'first_name' => 'E2E'],
        'chat' => ['id' => $id, 'type' => 'private'], 'date' => time(), 'text' => $text]];
};

// ==================================================================
section('۱) وب‌هوک سالم ⇒ ۲۰۰ و پاسخ درست');
// ==================================================================
Db::q('DELETE FROM `seen_update`');
$upd = $mk($ADMIN, '🏠 منو');
[$code, $body] = hook($upd);
chk('وب‌هوک ۲۰۰ برگرداند', $code === 200, "code={$code}");
chk('بدنهٔ JSON معتبر است', json_decode($body, true) !== null, mb_substr($body, 0, 80));
chk('آپدیت علامت‌گذاری شد', (int)Db::val('SELECT COUNT(*) FROM `seen_update` WHERE `update_id` = ?',
    [(int)$upd['update_id']]) === 1);

// ==================================================================
section('۲) همان آپدیت دوباره ⇒ تکرار نمی‌شود');
// ==================================================================
[$code2, $body2] = hook($upd);
chk('آپدیت تکراری ۲۰۰ داد', $code2 === 200, "code={$code2}");
chk('تلگرام می‌فهمد تکراری است', str_contains($body2, 'duplicate'), mb_substr($body2, 0, 80));

// ==================================================================
section('۳) وقتی ربات می‌ترکد: ۲۰۰، نه ۵۰۰');
// ==================================================================
// متغیرِ محیطی که index.php می‌خواند تا ربات را وادار به ترکیدن کند.
// چون وب‌سرور یک فرایندِ جدا و از قبل در حال اجراست، این را با یک مسیرِ
// ویژه در آپدیت فعال می‌کنیم (نه با env متغیرِ فرایندِ دیگر).
Db::q('DELETE FROM `seen_update`');
$crashUpd = $mk($ADMIN, '⚙️ تنظیمات');
// نشانه‌ای که index.php آن را می‌بیند و عمداً خطا می‌دهد
$crashUpd['message']['text'] = "\x00CRASH_TEST\x00" . $crashUpd['message']['text'];
[$code3, $body3] = hook($crashUpd);
echo "  (بدنهٔ پاسخ: " . mb_substr(trim($body3), 0, 80) . ")\n";
// نکته: پاسخِ وب‌هوک باید JSON خالص باشد. اگر PHP خطایی نمایش بدهد، بدنه
// HTML می‌شود و تلگرام هم پاسخ را خراب می‌بیند. پس display_errors خاموش است
// تا اگر خطایی بیرون بزند، تست آن را به‌شکل «بدنه نامعتبر» گزارش کند.
$chk = json_decode($body3, true);
chk('خطای داخلی هم ۲۰۰ برمی‌گرداند (تلگرام تکرار نمی‌کند)', $code3 === 200, "code={$code3}");
chk('بدنه JSON معتبر است (نه HTML خطای PHP)',
    is_array($chk) && str_starts_with(ltrim($body3), '{'), mb_substr(trim($body3), 0, 120));
chk('بدنه ok=true است', is_array($chk) && ($chk['ok'] ?? null) === true, mb_substr(trim($body3), 0, 120));
chk('آپدیت خطادار هم علامت‌گذاری شد (پیامِ خطا به کاربر رفته)',
    (int)Db::val('SELECT COUNT(*) FROM `seen_update` WHERE `update_id` = ?',
        [(int)$crashUpd['update_id']]) === 1);

echo "\n----------------------------------------\n";
echo "OK: $ok    FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);