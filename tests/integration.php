<?php
/**
 * تست یکپارچه: مسیر HTTP درگاه + رگرسیون موتور و ربات
 *   UPTIME_CONFIG=... php tests/integration.php
 *
 * پیش‌نیاز:
 *   php -S 127.0.0.1:8321 tests/mock_gw.php    (سرور قلابی درگاه)
 *   php -S 127.0.0.1:8199 <project>            (وب‌سرور خود پروژه)
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/bootstrap.php';
BotApi::setProxy('http://127.0.0.1:1');
$r = new ReflectionClass('BotApi');
$p = $r->getProperty('maxRetries'); $p->setAccessible(true); $p->setValue(null, 0);
$p = $r->getProperty('baseDelay');  $p->setAccessible(true);  $p->setValue(null, 1);

$fail = 0; $ok = 0;
function chk(string $n, $c, string $x = ''): void
{
    global $fail, $ok;
    if ($c) { $ok++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ $n" . ($x !== '' ? " — $x" : '') . "\n"; }
}
function section(string $t): void { echo "\n== $t ==\n"; }

function http(string $method, string $url, $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    // CURLOPT_HTTPHEADER فهرست خطی «Name: value» می‌خواهد؛ آرایهٔ
    // کلید–مقدار هم پذیرفته می‌شود و اینجا به خط تبدیل می‌گردد
    // (در غیر این صورت curl سطرهای بدون دونقطه را بی‌صدا نادیده می‌گیرد).
    $h = [];
    foreach ($headers as $k => $v) $h[] = is_int($k) ? (string)$v : $k . ': ' . $v;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $h[] = 'Content-Type: application/json';
    }
    if ($h) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$out];
}

$mock = 'http://127.0.0.1:8321/mock';
$web  = 'http://127.0.0.1:8199';
appBoot();
Db::migrate();
PayGws::ensure();

$uid = 790002;
Db::q('INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`share_token`,`created_at`,`last_seen`)
       VALUES (?,?,?,1,1,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `access`=1',
    [$uid, 'کاربر وب', 'webuser', 'webtoken00000000001']);
Db::set('price', '25000');
Db::set('vip_days', '30');
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
Db::q('UPDATE `user` SET `plan` = ?, `plan_until` = NULL WHERE `id` = ?', ['free', $uid]);

// پیکربندی درگاه‌ها روی سرور قلابی
foreach (['zarinpal' => $mock . '/zarinpal', 'variza' => $mock . '/variza', 'cubepy' => $mock . '/cubepy',
          'tetrapay' => $mock . '/tetra', 'aban' => $mock . '/aban'] as $code => $url) {
    Db::q('UPDATE `pay_gateway` SET `api_key` = ?, `merchant_id` = ?, `base_url` = ?, `enabled` = 1 WHERE `code` = ?',
        ['TESTKEY-1234', 'TESTKEY-1234', $url, $code]);
}
Db::q('UPDATE `pay_gateway` SET `secret` = ? WHERE `code` = ?', ['whsec_http', 'variza']);

// ---------------------------------------------------------------- pay.php
section('pay.php روی HTTP');
$r = http('GET', $web . '/pay.php');
chk('بدون کد درگاه ⇒ ۴۰۰', $r['code'] === 400, (string)$r['code']);

$r = http('GET', $web . '/pay.php?g=nosuch');
chk('درگاه ناموجود ⇒ ۴۰۴', $r['code'] === 404, (string)$r['code']);

// ۱) واریزا: وب‌هوک امضا نشده ⇒ ۴۰۰
$c = Pay::createOrder($uid, 'variza');
$pid = (int)$c['id'];
$slug = (string)Db::val('SELECT ref_id FROM payments WHERE id = ?', [$pid]);
$raw = json_encode(['event' => 'payment.paid', 'slug' => $slug, 'amount' => 25000, 'status' => 'paid'], JSON_UNESCAPED_UNICODE);
$r = http('POST', $web . '/pay.php?g=variza', $raw, ['X-Webhook-Signature' => 'sha256=bad']);
chk('وب‌هوک جعلی ⇒ ۴۰۰', $r['code'] === 400, (string)$r['code']);
chk('  فعال نشد', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'free');
chk('  فاکتور pending ماند', (string)Db::val('SELECT status FROM payments WHERE id = ?', [$pid]) === 'pending');

// ۲) امضای درست ⇒ ۲۰۰ + فعال‌سازی + صفحهٔ فارسی
$sig = 'sha256=' . hash_hmac('sha256', $raw, 'whsec_http');
$r = http('POST', $web . '/pay.php?g=variza', $raw, ['X-Webhook-Signature' => $sig, 'X-Event' => 'payment.paid']);
chk('وب‌هوک معتبر ⇒ ۲۰۰', $r['code'] === 200, (string)$r['code']);
chk('  صفحهٔ فارسی برگشت', str_contains($r['body'], 'پرداخت موفق') && str_contains($r['body'], 'dir="rtl"'), mb_substr(strip_tags($r['body']), 0, 80));
chk('  اشتراک فعال شد', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'vip', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]));
chk('  فاکتور approved', (string)Db::val('SELECT status FROM payments WHERE id = ?', [$pid]) === 'approved');
chk('  لینک ربات در صفحه هست', str_contains($r['body'], 't.me/'));

// ۳) دوباره فرستادن همان وب‌هوک ⇒ سود مضاعف ندهد
$cntA = (int)Db::val("SELECT COUNT(*) FROM events WHERE kind = 'payment_ok' AND user_id = ?", [$uid]);
$r = http('POST', $web . '/pay.php?g=variza', $raw, ['X-Webhook-Signature' => $sig, 'X-Delivery-Id' => 'http-d-1']);
$cntB = (int)Db::val("SELECT COUNT(*) FROM events WHERE kind = 'payment_ok' AND user_id = ?", [$uid]);
chk('ارسال دوباره سود مضاعف نداد', $cntB === $cntA, "$cntA → $cntB");

// ۴) بازگشت کاربر از درگاه بانکی ⇒ ریدایرکت به ربات
$r = http('GET', $web . '/pay.php?g=zarinpal&Authority=X&Status=OK', null, []);
chk('بازگشت کاربر ⇒ ریدایرکت به ربات', $r['code'] === 302 || str_contains($r['body'], 't.me/'), (string)$r['code'] . ' ' . mb_substr($r['body'], 0, 60));

// ۵) زرین‌پال: verify از طریق pay.php
Db::q('UPDATE `user` SET `plan` = ?, `plan_until` = NULL WHERE `id` = ?', ['free', $uid]);
$c = Pay::createOrder($uid, 'zarinpal');
$pid = (int)$c['id'];
$auth = (string)Db::val('SELECT ref_id FROM payments WHERE id = ?', [$pid]);
$r = http('POST', $web . '/pay.php?g=zarinpal&payment_id=' . $pid, json_encode(['Authority' => $auth, 'Status' => 'OK']));
chk('زرین‌پال: verify و فعال‌سازی از طریق pay.php', $r['code'] === 200
    && (string)Db::val('SELECT status FROM payments WHERE id = ?', [$pid]) === 'approved', (string)$r['code'] . ' ' . mb_substr(strip_tags($r['body']), 0, 60));
chk('  اشتراک فعال شد', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'vip');

// ---------------------------------------------------------------- رگرسیون موتور
section('رگرسیون: موتور مانیتورینگ');
$n = normalizeTarget('http://127.0.0.1:8321/mock/zarinpal/payment/request.json');
chk('normalizeTarget', !empty($n['ok']) && $n['type'] === 'http', json_encode($n));
Db::q('INSERT INTO `site` (`user_id`,`chat_id`,`target`,`label`,`type`,`host`,`port`,`share_token`,`created_at`)
       VALUES (?,0,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE `target` = VALUES(`target`)',
    [$uid, 'http://127.0.0.1:8321/mock/variza/api/v1/pay', 'رگرسیون', 'http', '127.0.0.1', 8321, makeShareToken(20)]);
$sid = (int)Db::val('SELECT id FROM site WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
Db::q('UPDATE `site` SET `last_check_at` = NULL');
$st = Monitor::round();
chk('Monitor::round کار کرد', !isset($st['skipped']), json_encode($st, JSON_UNESCAPED_UNICODE));
$sr = Db::one('SELECT * FROM site WHERE id = ?', [$sid]);
chk('سایت تشخیص داده شد', in_array((string)$sr['status'], ['up', 'down'], true), (string)$sr['status']);
chk('last_check_at ثبت شد', !empty($sr['last_check_at']));
$sum = Stats::userSummary($uid);
chk('userSummary', $sum['total'] >= 1, (string)$sum['total']);
$m = Monitor::maintenance(true);
chk('maintenance اجرا شد', is_array($m) && array_key_exists('ssl', $m), json_encode($m));
chk('انقضای فاکتور در نگهداری', array_key_exists('payments', $m), json_encode(array_keys($m)));

// ---------- سیستم امتیازدهی: یک‌بار در روز، یک‌بار در ساعت ----------
// ماژول Ranking روی هر چک موفق صدا زده می‌شود؛ اگر تواتر نداشته باشد با
// فاصلهٔ چکِ ۱۰ ثانیه، روزی ۳۶۰ بار امتیاز می‌داد.
chk('ستون امتیاز در اسکیما هست', Db::hasColumn('user', 'user_points'));
Db::q("DELETE FROM `settings` WHERE `k` LIKE 'rank\\_%' OR `k` LIKE 'last\\_points\\_%' OR `k` LIKE 'points\\_bonus\\_%'");
Db::flushSettings();   // Ranking::already() از کش تنظیمات می‌خواند
Db::q("DELETE FROM `events` WHERE `kind` IN ('daily_points','uptime_bonus','rank_daily','rank_uptime')");
Db::q('UPDATE `user` SET `user_points` = 0, `user_rank` = 1, `site_uptime_count` = 0 WHERE `id` = ?', [$uid]);
Db::q('UPDATE `site` SET `last_check_at` = NULL');
Db::set('points_per_uptime_hour', '5');
Db::set('points_per_day', '1');
for ($i = 0; $i < 4; $i++) {
    Db::q('UPDATE `site` SET `last_check_at` = NULL WHERE `id` = ?', [$sid]);
    Monitor::round();
}
$evD = (int)Db::val("SELECT COUNT(*) FROM `events` WHERE `kind` = 'rank_daily' AND `user_id` = ?", [$uid]);
$evU = (int)Db::val("SELECT COUNT(*) FROM `events` WHERE `kind` = 'rank_uptime' AND `user_id` = ?", [$uid]);
$pts = (int)Db::val('SELECT `user_points` FROM `user` WHERE `id` = ?', [$uid]);
chk('امتیاز روزانه فقط یک‌بار در ۴ چک', $evD === 1, (string)$evD);
chk('پاداش ساعتی فقط یک‌بار در ۴ چک', $evU === 1, (string)$evU);
chk('مجموع امتیاز درست است', $pts === 1 + 5, (string)$pts);
$logHits = 0;
foreach (glob(UPTIME_ROOT . '/logs/*.log') ?: [] as $lf) {
    $logHits += substr_count((string)file_get_contents($lf), 'Ranking points system error')
             + substr_count((string)file_get_contents($lf), 'ranking: ');
}
chk('خطای امتیازدهی لاگ نشد', $logHits === 0, (string)$logHits);
// کلیدهای ساعتی نباید بی‌پایان رشد کنند
Db::set('points_per_uptime_hour', '5');
Db::q("INSERT INTO `settings` (`k`,`v`) VALUES ('rank_up_1_2000-01-01T00','1')
       ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)");
Monitor::maintenance(true);
$stale = (int)Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = 'rank_up_1_2000-01-01T00'");
chk('کلیدهای قدیمی امتیاز پاک شدند', $stale === 0, (string)$stale);

// ---------------------------------------------------------------- رگرسیون ربات
section('رگرسیون: ربات');
// کاربر ادمین (طبق admin_id تنظیمات تست) تا کالبک‌های مدیریتی هم آزمایش شوند
$uid = (int)botAdminIds()[0];
Db::q('INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`share_token`,`created_at`,`last_seen`)
       VALUES (?,?,?,1,1,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `access`=1,`is_admin`=1',
    [$uid, 'ادمین تست', 'admintest', 'admintoken000000001']);
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
$bot = new Bot(appConfig());
$from = ['id' => $uid, 'first_name' => 'وب', 'username' => 'admintest'];
$mk = static fn(string $t, int $mid = 1) => ['message_id' => $mid, 'chat' => ['id' => $uid, 'type' => 'private'],
    'from' => $from, 'text' => $t, 'date' => time()];
$cb = static function (string $data) use ($uid, $from) {
    return ['update_id' => random_int(1, 999999), 'callback_query' => ['id' => 'x', 'from' => $from,
        'message' => ['message_id' => 700, 'chat' => ['id' => $uid, 'type' => 'private'], 'text' => 'x'], 'data' => $data]];
};
$run = static function (callable $f, string $label) use (&$fail, &$ok) {
    try { $f(); $ok++; echo "  ✓ $label\n"; }
    catch (Throwable $e) { $fail++; echo "  ✗ $label — " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
};
// فرمان‌های قدیمی (رگرسیون)
foreach (['/start', '/help', '/sites', '/report', '/shared', '/domains', '/incidents', '🏠 منو',
          '➕ افزودن سایت', '🔗 صفحهٔ وضعیت من', '🛒 اشتراک ویژه', '⚙️ تنظیمات'] as $t) {
    $run(static fn() => $GLOBALS['bot']->handle(['update_id' => random_int(1, 999999), 'message' => $GLOBALS['mk']($t)]), "پیام: $t");
}
// کالبک‌های قدیمی
foreach (['menu', 'help', 'sites', 'report', 'share', 'settings', 'sub', 'shared', 'domains',
          'uincer', 'astats', 'apanel', 'cron', 'codes', "site:$sid", "sc:$sid", "slink:$sid",
          "sshare:$sid", "sincer:$sid", "ssl:$sid", "sms:$sid", "skw:$sid"] as $d) {
    $run(static fn() => $GLOBALS['bot']->handle($GLOBALS['cb']($d)), "کالبک: $d");
}
// کالبک‌های جدید پرداخت
foreach (['buy', 'gw:zarinpal', 'porder', 'pgws_list', "pgw:cubepy", "pgwx:aban", "pgw:generic"] as $d) {
    $run(static fn() => $GLOBALS['bot']->handle($GLOBALS['cb']($d)), "کالبک پرداخت: $d");
}
// مرحلهٔ تنظیم کلید (ادمین)
$run(static fn() => $GLOBALS['bot']->handle($GLOBALS['cb']('pgwk:cubepy')), 'شروع تنظیم کلید');
$run(static fn() => $GLOBALS['bot']->handle(['update_id' => 1, 'message' => $GLOBALS['mk']('MY-NEW-KEY')]), 'ثبت کلید جدید');
chk('کلید جدید ذخیره شد', (string)Db::val('SELECT api_key FROM pay_gateway WHERE code = ?', ['cubepy']) === 'MY-NEW-KEY');
$run(static fn() => $GLOBALS['bot']->handle($GLOBALS['cb']('pgwt:cubepy')), 'خاموش کردن درگاه');
chk('درگاه خاموش شد', (int)Db::val('SELECT enabled FROM pay_gateway WHERE code = ?', ['cubepy']) === 0);
$run(static fn() => $GLOBALS['bot']->handle($GLOBALS['cb']('pgwt:cubepy')), 'روشن کردن درگاه');
chk('درگاه روشن شد', (int)Db::val('SELECT enabled FROM pay_gateway WHERE code = ?', ['cubepy']) === 1);
$run(static fn() => $GLOBALS['bot']->handle($GLOBALS['cb']('pgwg')), 'شروع تنظیم JSON دلخواه');
$run(static fn() => $GLOBALS['bot']->handle(['update_id' => 1, 'message' => $GLOBALS['mk']('{bad json')]), 'JSON نامعتبر رد شد');
$run(static fn() => $GLOBALS['bot']->handle(['update_id' => 1, 'message' => $GLOBALS['mk']('{"create_path":"/p","verify_path":"/v"}')]), 'JSON معتبر ذخیره شد');
chk('تنظیمات دلخواه ذخیره شد', str_contains((string)Db::val('SELECT settings FROM pay_gateway WHERE code = ?', ['generic']), 'create_path'));
$run(static fn() => $GLOBALS['bot']->handle($GLOBALS['cb']('pgws:variza')), 'شروع تنظیم Webhook Secret');
$run(static fn() => $GLOBALS['bot']->handle(['update_id' => 1, 'message' => $GLOBALS['mk']('پاک')]), 'پاک کردن کلید');
chk('Webhook Secret پاک شد', (string)Db::val('SELECT secret FROM pay_gateway WHERE code = ?', ['variza']) === '',
    (string)Db::val('SELECT secret FROM pay_gateway WHERE code = ?', ['variza']));
$run(static fn() => $GLOBALS['bot']->handle($GLOBALS['cb']('pgws:variza')), 'شروع دوبارهٔ تنظیم امضا');
$run(static fn() => $GLOBALS['bot']->handle(['update_id' => 1, 'message' => $GLOBALS['mk']('❌ انصراف')]), 'انصراف از تنظیم');
chk('  انصراف چیزی را تغییر نداد', (string)Db::val('SELECT secret FROM pay_gateway WHERE code = ?', ['variza']) === '');
// /start paycheck
$run(static fn() => $GLOBALS['bot']->handle(['update_id' => 1, 'message' => $GLOBALS['mk']('/start paycheck')]), 'لینک عمیق paycheck');

// کاربر غیرادمین نباید به کالبک‌های مدیریتی درگاه دسترسی داشته باشد
$uid2 = 790003;
Db::q('INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`share_token`,`created_at`,`last_seen`)
       VALUES (?,?,?,0,1,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `access`=1',
    [$uid2, 'غیرادمین', 'plainuser', 'plaintoken000000001']);
$bot2 = new Bot(appConfig());
$from2 = ['id' => $uid2, 'first_name' => 'ع', 'username' => 'plainuser'];
$cb2 = static fn(string $d) => ['update_id' => random_int(1, 999999), 'callback_query' => ['id' => 'x', 'from' => $from2,
    'message' => ['message_id' => 700, 'chat' => ['id' => $uid2, 'type' => 'private'], 'text' => 'x'], 'data' => $d]];
foreach (['pgws_list', 'pgw:cubepy', 'pgwt:cubepy', 'pgwk:cubepy', 'pgwm:cubepy', 'pgws:cubepy',
          'pgwb:cubepy', 'pgwg', 'pgwx:cubepy'] as $d) {
    $run(static fn() => $GLOBALS['bot2']->handle($GLOBALS['cb2']($d)), "غیرادمین رد شد: $d");
}
chk('کلید درگاه دست‌نخورده ماند', (string)Db::val('SELECT api_key FROM pay_gateway WHERE code = ?', ['cubepy']) === 'MY-NEW-KEY');
chk('وضعیت درگاه دست‌نخورده ماند', (int)Db::val('SELECT enabled FROM pay_gateway WHERE code = ?', ['cubepy']) === 1);

echo "\n==== نتیجه: $ok موفق، $fail ناموفق ====\n";
exit($fail > 0 ? 1 : 0);
