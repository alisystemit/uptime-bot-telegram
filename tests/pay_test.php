<?php
/**
 * تست دودی درگاه‌های پرداخت
 *   UPTIME_DEBUG=1 php tests/pay_test.php
 *
 * نیازمند: سرور قلابی  php -S 127.0.0.1:8321 tests/mock_gw.php
 * و دیتابیس تست (UPTIME_CONFIG + UPTIME_DEBUG=1)
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/bootstrap.php';
BotApi::setProxy('http://127.0.0.1:1');   // تلگرام نباید واقعاً صدا زده شود
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

$mock = 'http://127.0.0.1:8321/mock';
appBoot();
Db::migrate();
PayGws::ensure();
// تست باید هر بار از حالتِ یکسان شروع شود. قبلاً اینجا فقط «خاموش‌بودن»
// فرض می‌شد در حالی که اجرای قبلی درگاه‌ها را روشن گذاشته بود (خط ۵۱) و
// دومین بارِ اجرا، چک «همه خاموش‌اند» شکست می‌خورد.
Db::q('UPDATE `pay_gateway` SET `enabled` = 0');
Db::set('price', '25000');
Db::set('vip_days', '30');

$uid = 790001;
Db::q('INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`share_token`,`created_at`,`last_seen`)
       VALUES (?,?,?,1,1,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `access`=1,`plan`=?,`plan_until`=NULL',
    [$uid, 'پرداخت تست', 'paytest', 'paytoken0000000001', 'free']);
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);

// ---------------------------------------------------------------- تنظیم درگاه‌ها
section('PayGws');
$all = PayGws::all(false);
chk('۶ درگاه ثبت شد', count($all) === 6, (string)count($all));
$codes = array_map(static fn($g) => (string)$g['code'], $all);
foreach (['zarinpal', 'variza', 'cubepy', 'tetrapay', 'aban', 'generic'] as $c) {
    chk("درگاه $c", in_array($c, $codes, true));
}
chk('همه خاموش‌اند به‌صورت پیش‌فرض', count(PayGws::all(true)) === 0);
foreach (['zarinpal' => $mock . '/zarinpal', 'variza' => $mock . '/variza', 'cubepy' => $mock . '/cubepy',
          'tetrapay' => $mock . '/tetra', 'aban' => $mock . '/aban', 'generic' => $mock . '/generic'] as $code => $url) {
    Db::q('UPDATE `pay_gateway` SET `api_key` = ?, `merchant_id` = ?, `base_url` = ? WHERE `code` = ?',
        ['TESTKEY-1234', 'TESTKEY-1234', $url, $code]);
    chk("فعال‌سازی $code", PayGws::toggle($code, true));
}
Db::q('UPDATE `pay_gateway` SET `secret` = ? WHERE `code` = ?', ['whsec_test', 'variza']);
PayGws::setSetting('generic', 'create_path', '/pay');
PayGws::setSetting('generic', 'verify_path', '/verify');
PayGws::setSetting('generic', 'ref_path', 'id');
PayGws::setSetting('generic', 'url_path', 'pay_url');
PayGws::setSetting('generic', 'field_map', ['amount' => 'amount', 'order_id' => 'order', 'callback_url' => 'callback']);
PayGws::setSetting('variza', 'card_last_4', '');
chk('۶ درگاه فعال', count(PayGws::all(true)) === 6, (string)count(PayGws::all(true)));
chk('درایورها بارگذاری شدند', PayGws::driver('cubepy') === 'PayCubepy' && PayGws::driver('nope') === null);

// ---------------------------------------------------------------- ساخت فاکتور
section('ساخت فاکتور (create)');
// مبلغ دقیقِ قابل واریز که خود درگاه برمی‌گرداند (چند ریال برای تشخیص واریز)
$expectPay = ['zarinpal' => 250000, 'variza' => 25000, 'cubepy' => 250000 + 72,
              'tetrapay' => 250000, 'aban' => 250000 + 10, 'generic' => 250000];
foreach (['zarinpal', 'variza', 'cubepy', 'tetrapay', 'aban', 'generic'] as $code) {
    Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid . " AND `status` <> 'approved'");
    $r = Pay::createOrder($uid, $code);
    chk("ساخت فاکتور $code", !empty($r['ok']), (string)($r['error'] ?? ''));
    if (empty($r['ok'])) continue;
    chk("  $code لینک پرداخت دارد", (string)$r['pay_url'] !== '', (string)$r['pay_url']);
    $row = Db::one('SELECT * FROM `payments` WHERE `id` = ?', [(int)$r['id']]);
    chk("  $code ref_id ذخیره شد", (string)$row['ref_id'] !== '');
    chk("  $code مبلغ تومانی درست", (int)$row['amount'] === 25000, (string)$row['amount']);
    chk("  $code واحد ارسالی درست", (int)$row['pay_amount'] === $expectPay[$code],
        (string)$row['pay_amount'] . ' ≠ ' . (string)$expectPay[$code]);
    if ($code === 'cubepy' || $code === 'aban') {
        chk("  $code کارت برگشت", (string)$row['card_number'] !== '', (string)$row['card_number']);
        chk("  $code صاحب کارت برگشت", (string)$row['card_holder'] !== '');
    }
}

// ---------------------------------------------------------------- سقف فاکتور باز
section('محدودیت فاکتور');
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
$made = 0;
for ($i = 0; $i < 4; $i++) {
    $r = Pay::createOrder($uid, 'zarinpal');
    if (!empty($r['ok'])) $made++;
}
chk('سقف ۳ فاکتور باز', $made === Pay::MAX_PENDING, (string)$made);
chk('پیام سقف فاکتور', str_contains((string)(Pay::createOrder($uid, 'zarinpal')['error'] ?? ''), 'فاکتور باز'), (string)(Pay::createOrder($uid, 'zarinpal')['error'] ?? ''));

// ---------------------------------------------------------------- تأیید
section('verify و فعال‌سازی');
// واریزا endpoint تأیید ندارد ⇒ مسیرش وب‌هوکِ امضاشده است (در بخش امنیت تست می‌شود)
$withVerify = ['zarinpal', 'cubepy', 'tetrapay', 'aban', 'generic'];
foreach ($withVerify as $code) {
    Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
    $c = Pay::createOrder($uid, $code);
    $id = (int)($c['id'] ?? 0);
    Db::q('UPDATE `user` SET `plan` = ?, `plan_until` = NULL WHERE `id` = ?', ['free', $uid]);

    $ref0 = (string)Db::val('SELECT ref_id FROM payments WHERE id = ?', [$id]);
    // شبیه‌سازی «واریز انجام شد» فقط برای کیوب‌پی (که verify از سرویس می‌پرسد)
    if ($code === 'cubepy') PayHttp::call($mock . '/_pay/cubepy', ['authority' => $ref0], [], 'POST', 5);

    $v = Pay::verifyOrder($id);
    chk("verify $code", !empty($v['ok']), (string)($v['error'] ?? ''));
    $st = (string)Db::val('SELECT status FROM payments WHERE id = ?', [$id]);
    chk("  $code وضعیت approved", $st === 'approved', $st);
    chk("  $code اشتراک ویژه فعال شد", (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'vip');
    $until = (string)Db::val('SELECT plan_until FROM user WHERE id = ?', [$uid]);
    chk("  $code تاریخ انقضا ۳۰ روزه", $until !== '' && strtotime($until) > time() + 29 * 86400, $until);
    $v2 = Pay::verifyOrder($id);   // دوبار verify ⇒ بی‌خطر
    chk("  $code verify دوباره بی‌خطر", !empty($v2['ok']));
}
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
$c = Pay::createOrder($uid, 'variza');
$v = Pay::verifyOrder((int)$c['id']);
chk('واریزا verify ندارد و پیام راهنما می‌دهد', empty($v['ok']) && str_contains((string)$v['error'], 'وب‌هوک'), (string)$v['error']);
chk('  فاکتور واریزا هنوز pending است', (string)Db::val('SELECT status FROM payments WHERE id = ?', [(int)$c['id']]) === 'pending');

// ---------------------------------------------------------------- تمدید زنجیره‌ای
section('تمدید');
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
$u1 = (string)Db::val('SELECT plan_until FROM user WHERE id = ?', [$uid]);
$c1 = Pay::createOrder($uid, 'zarinpal');
$ok1 = !empty(Pay::verifyOrder((int)$c1['id'])['ok']);
$u2 = (string)Db::val('SELECT plan_until FROM user WHERE id = ?', [$uid]);
chk('تمدید روی اشتراک فعال جمع شد', $ok1 && strtotime($u2) > strtotime($u1) + 29 * 86400, "$u1 → $u2");

// ---------------------------------------------------------------- وب‌هوک جعلی
section('امنیت وب‌هوک');
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
Db::q('UPDATE `user` SET `plan` = ?, `plan_until` = NULL WHERE `id` = ?', ['free', $uid]);

// ۱) واریزا بدون امضا ⇒ رد
$c = Pay::createOrder($uid, 'variza');
$id = (int)$c['id'];
$ref = (string)Db::one('SELECT ref_id FROM payments WHERE id = ?', [$id])['ref_id'];
$raw = json_encode(['event' => 'payment.paid', 'slug' => $ref, 'amount' => 25000, 'status' => 'paid'], JSON_UNESCAPED_UNICODE);
$r = Pay::callback('variza', [], $raw, ['X-Webhook-Signature' => 'sha256=deadbeef']);
chk('وب‌هوک بدون امضای درست رد شد', empty($r['ok']) && (int)$r['status'] === 400, json_encode($r, JSON_UNESCAPED_UNICODE));
chk('  کاربر هنوز فعال نشده', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'free');

// ۲) امضای نادرست ⇒ رد
$sig = 'sha256=' . hash_hmac('sha256', $raw, 'wrong-secret');
$r = Pay::callback('variza', [], $raw, ['X-Webhook-Signature' => $sig]);
chk('امضای نادرست رد شد', (int)$r['status'] === 400);
chk('  کاربر هنوز فعال نشده', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'free');

// ۳) امضای درست ⇒ فعال
$sig = 'sha256=' . hash_hmac('sha256', $raw, 'whsec_test');
$r = Pay::callback('variza', [], $raw, ['X-Webhook-Signature' => $sig, 'X-Event' => 'payment.paid']);
chk('امضای درست پذیرفته شد', !empty($r['ok']), json_encode($r, JSON_UNESCAPED_UNICODE));
chk('  اشتراک فعال شد', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'vip');

// ۴) تکرار (X-Delivery-Id) ⇒ دوباره فعال نکند
$cntBefore = (int)Db::val("SELECT COUNT(*) FROM events WHERE kind = 'payment_ok' AND user_id = ?", [$uid]);
$r = Pay::callback('variza', [], $raw, ['X-Webhook-Signature' => $sig, 'X-Delivery-Id' => 'd-1']);
$cntAfter = (int)Db::val("SELECT COUNT(*) FROM events WHERE kind = 'payment_ok' AND user_id = ?", [$uid]);
chk('وب‌هوک تکراری دوباره فعال نکرد', $cntAfter === $cntBefore, "$cntBefore → $cntAfter");

// ۵) ref ناشناخته ⇒ چیزی تحویل نمی‌شود ولی ۲۰۰
$rawU = json_encode(['slug' => 'nope', 'amount' => 25000, 'status' => 'paid'], JSON_UNESCAPED_UNICODE);
$r = Pay::callback('variza', [], $rawU, ['X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $rawU, 'whsec_test')]);
chk('slug ناشناخته ⇒ ۲۰۰ بدون تحویل', (int)$r['status'] === 200 && !isset($r['payment_id']), json_encode($r, JSON_UNESCAPED_UNICODE));

// ۶) کیوب‌پی (بدون امضا) باید verify را صدا بزند، نه اینکه کورکورانه فعال کند
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
Db::q('UPDATE `user` SET `plan` = ?, `plan_until` = NULL WHERE `id` = ?', ['free', $uid]);
$c = Pay::createOrder($uid, 'cubepy');
$id = (int)$c['id'];
$ref = (string)Db::one('SELECT ref_id FROM payments WHERE id = ?', [$id])['ref_id'];
// ۶الف) callback جعلی بدون واریز ⇒ نباید فعال کند
$r = Pay::callback('cubepy', [], json_encode(['success' => true, 'status' => 'paid', 'authority' => $ref, 'amount' => 250072]), []);
chk('callback جعلی بدون واریز فعال نکرد', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'free',
    (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]));
// ۶ب) بعد از واریز واقعی ⇒ verify موفق و فعال می‌شود
PayHttp::call($mock . '/_pay/cubepy', ['authority' => $ref], [], 'POST', 5);
$r = Pay::callback('cubepy', [], json_encode(['success' => true, 'status' => 'paid', 'authority' => $ref, 'amount' => 250072]), []);
chk('کیوب‌پی: callback ⇒ verify ⇒ فعال', !empty($r['ok']) && (string)Db::val('SELECT status FROM payments WHERE id = ?', [$id]) === 'approved', json_encode($r, JSON_UNESCAPED_UNICODE));
chk('  اشتراک فعال شد', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'vip');

// ۷) مبلغ نامطابق ⇒ رد (حملهٔ «فاکتور ارزان را با فاکتور گران عوض کنیم»)
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
Db::q('UPDATE `user` SET `plan` = ?, `plan_until` = NULL WHERE `id` = ?', ['free', $uid]);
$c = Pay::createOrder($uid, 'zarinpal');
$id = (int)$c['id'];
Db::q('UPDATE `payments` SET `pay_amount` = 999999 WHERE `id` = ?', [$id]);
$v = Pay::verifyOrder($id);
chk('مبلغ نامطابق رد شد', empty($v['ok']) && str_contains((string)$v['error'], 'مطابقت ندارد'), (string)$v['error']);
chk('  اشتراک فعال نشد', (string)Db::val('SELECT plan FROM user WHERE id = ?', [$uid]) === 'free');

// ---------------------------------------------------------------- خطاها
section('مدیریت خطا');
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
Db::q("UPDATE `pay_gateway` SET `base_url` = ? WHERE `code` = 'broken'", [$mock . '/broken']);
$gw = PayGws::get('cubepy');
Db::q('UPDATE `pay_gateway` SET `base_url` = ? WHERE `code` = ?', [$mock . '/broken', 'cubepy']);
$before = (int)Db::one('SELECT fail_count FROM pay_gateway WHERE code = ?', ['cubepy'])['fail_count'];
$r = Pay::createOrder($uid, 'cubepy');
chk('خطای درگاه ⇒ پیام فارسی', empty($r['ok']) && str_contains((string)$r['error'], 'مبلغ'), (string)($r['error'] ?? ''));
$after = (int)Db::one('SELECT fail_count FROM pay_gateway WHERE code = ?', ['cubepy'])['fail_count'];
chk('شمارندهٔ خطا زیاد شد', $after === $before + 1, "$before → $after");
$lastErr = (string)Db::one('SELECT last_error FROM pay_gateway WHERE code = ?', ['cubepy'])['last_error'];
chk('آخرین خطا ذخیره شد', $lastErr !== '');

// درگاه خاموش
Db::q('UPDATE `pay_gateway` SET `enabled` = 0 WHERE `code` = ?', ['cubepy']);
$r = Pay::createOrder($uid, 'cubepy');
chk('درگاه خاموش ⇒ رد', empty($r['ok']) && str_contains((string)$r['error'], 'غیرفعال'), (string)($r['error'] ?? ''));
// فعال‌سازی بدون کلید ⇒ نباید باشد
Db::q('UPDATE `pay_gateway` SET `api_key` = \'\', `merchant_id` = \'\', `enabled` = 0 WHERE `code` = ?', ['aban']);
chk('فعال‌سازی بدون کلید رد شد', PayGws::toggle('aban', true) === false);
chk('  درگاه خاموش ماند', (int)Db::one('SELECT enabled FROM pay_gateway WHERE code = ?', ['aban'])['enabled'] === 0);
Db::q('UPDATE `pay_gateway` SET `api_key` = ?, `merchant_id` = ?, `base_url` = ?, `enabled` = 1 WHERE `code` = ?',
    ['K', 'K', $mock . '/aban', 'aban']);

// ---------------------------------------------------------------- انقضا
section('انقضای فاکتور');
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
Db::q('UPDATE `pay_gateway` SET `api_key` = ?, `enabled` = 1, `base_url` = ? WHERE `code` = ?', ['K', $mock . '/zarinpal', 'zarinpal']);
Db::q("INSERT INTO `payments` (`user_id`,`amount`,`status`,`gateway`,`ref_id`,`expires_at`,`created_at`) VALUES (?,1,'pending','zarinpal','OLD',DATE_SUB(NOW(), INTERVAL 1 HOUR),NOW())", [$uid]);
$n = Pay::expireOld(true);
chk('فاکتور منقضی علامت خورد', $n >= 1 && (string)Db::val("SELECT status FROM payments WHERE user_id = ? AND ref_id = 'OLD'", [$uid]) === 'expired', (string)$n);
$v = Pay::verifyOrder((int)Db::val("SELECT id FROM payments WHERE user_id = ? AND ref_id = 'OLD'", [$uid]));
chk('تأیید فاکتور منقضی رد شد', empty($v['ok']));

// ---------------------------------------------------------------- آمار و UI
section('آمار و رابط کاربری');
// یک فاکتور تأییدشده بساز تا آمار فروش معنی‌دار شود
Db::exec("DELETE FROM `payments` WHERE `user_id` = " . $uid);
$c = Pay::createOrder($uid, 'zarinpal');
Pay::verifyOrder((int)$c['id']);
$s = Pay::stats();
chk('آمار فروش محاسبه شد', $s['total'] >= 1 && $s['ok'] >= 1 && $s['revenue'] >= 25000, json_encode($s));
$bot = new Bot(appConfig());
$refl = new ReflectionClass($bot);
$set = function (string $p, $v) use ($refl, $bot) { $x = $refl->getProperty($p); $x->setAccessible(true); $x->setValue($bot, $v); };
$set('uid', $uid);
$set('u', Db::one('SELECT * FROM user WHERE id = ?', [$uid]));
$set('chatId', $uid);
foreach (['payBuyText', 'payGatewayMenu', 'payOrdersText', 'adminGatewaysText', 'adminGatewaysMenu'] as $m) {
    try {
        $rm = $refl->getMethod($m); $rm->setAccessible(true);
        $out = $rm->invoke($bot);
        chk("متد $m", is_string($out) && $out !== '', mb_substr((string)$out, 0, 60));
    } catch (Throwable $e) { chk("متد $m", false, $e->getMessage()); }
}
foreach (['zarinpal', 'variza', 'cubepy', 'tetrapay', 'aban', 'generic'] as $code) {
    try {
        $rm = $refl->getMethod('adminGatewayText'); $rm->setAccessible(true);
        $out = $rm->invoke($bot, $code);
        chk("adminGatewayText $code", is_string($out) && str_contains($out, 'pay.php'), mb_substr((string)$out, 0, 50));
    } catch (Throwable $e) { chk("adminGatewayText $code", false, $e->getMessage()); }
}
// کلید هرگز کامل نمایش داده نمی‌شود
$rm = $refl->getMethod('adminGatewayText'); $rm->setAccessible(true);
$out = $rm->invoke($bot, 'cubepy');
chk('کلید API در متن نمایش داده نمی‌شود', !str_contains((string)$out, 'TESTKEY-1234'), (string)$out);

echo "\n==== نتیجه: $ok موفق، $fail ناموفق ====\n";
exit($fail > 0 ? 1 : 0);
