<?php
/**
 * ===== تست end-to-end مینی‌اپ =====
 * شبیه‌سازی درخواست‌های appapi.php با initData معتبر
 * اجرا: php tools/test_miniapp.php
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
appBoot();

$pass = 0; $fail = 0;
function ok($c, $m)
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        print("  OK   $m\n");
    } else {
        $fail++;
        print("  FAIL $m\n");
    }
}

$token = botToken();
echo "bot: @" . botUsername() . "\n";
echo "token len: " . strlen($token) . "\n\n";

// ---------- ۱) ساخت initData معتبر ----------
/**
 * ساخت initData معتبر برای تست.
 * طبق مستندات تلگرام:
 *   secret = HMAC_SHA256(key="WebAppData", message=bot_token)
 * که در PHP یعنی hash_hmac('sha256', $botToken, 'WebAppData', true)
 *   hash   = HMAC_SHA256(key=secret, message=dataCheckString)
 */
function makeInitData2(array $fields, string $botToken): string
{
    ksort($fields, SORT_STRING);
    $pairs = [];
    foreach ($fields as $k => $v) $pairs[] = $k . '=' . $v;
    $dataCheck = implode("\n", $pairs);
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $hash = hash_hmac('sha256', $dataCheck, $secret);

    $enc = [];
    foreach ($pairs as $p) {
        [$k, $v] = explode('=', $p, 2);
        $enc[] = urlencode($k) . '=' . urlencode($v);
    }
    $enc[] = 'hash=' . $hash;
    return implode('&', $enc);
}

echo "=== 1. validateInitData ===\n";

$fields = [
    'auth_date' => (string)time(),
    'query_id'  => 'AAHdF6IQAAAAAN0XohDhrOrc',
    'user'      => json_encode([
        'id' => 777000001,
        'first_name' => 'تست',
        'last_name' => 'مینی',
        'username' => 'mini_test_user',
        'language_code' => 'fa',
    ], JSON_UNESCAPED_UNICODE),
];

$initData = makeInitData2($fields, $token);
$user = MiniApp::validateInitData($initData, $token);
ok($user !== null, 'valid initData accepted');
if ($user) {
    ok((int)$user['id'] === 777000001, 'user id = ' . $user['id']);
    ok(($user['first_name'] ?? '') === 'تست', 'first_name preserved (UTF-8)');
}

// ---------- ۲) رد کردن داده‌های دست‌کاری‌شده ----------
echo "\n=== 2. Reject tampered ===\n";
$bad = $initData;
ok(MiniApp::validateInitData($bad . 'x', $token) === null, 'modified string rejected');
ok(MiniApp::validateInitData('', $token) === null, 'empty rejected');
ok(MiniApp::validateInitData('hash=abc', $token) === null, 'garbage rejected');
ok(MiniApp::validateInitData($initData, 'wrong-token') === null, 'wrong bot token rejected');

// hash دست‌کاری‌شده
$parts = explode('&', $initData);
foreach ($parts as &$p) {
    if (str_starts_with($p, 'hash=')) $p = 'hash=' . str_repeat('0', 64);
}
ok(MiniApp::validateInitData(implode('&', $parts), $token) === null, 'forged hash rejected');

// ---------- ۳) منقضی شدن ----------
echo "\n=== 3. TTL ===\n";
$old = $fields;
$old['auth_date'] = (string)(time() - 8 * 86400); // ۸ روز پیش
ok(MiniApp::validateInitData(makeInitData2($old, $token), $token) === null, '8-day-old initData rejected');

$future = $fields;
$future['auth_date'] = (string)(time() + 3600);
ok(MiniApp::validateInitData(makeInitData2($future, $token), $token) === null, 'far-future auth_date rejected');

// ---------- ۴) ensureUser ----------
echo "\n=== 4. ensureUser ===\n";
$u = $user ? MiniApp::ensureUser($user) : null;
ok($u !== null, 'ensureUser returned row');
if ($u) {
    ok((int)$u['id'] === 777000001, 'db user id correct');
    ok(($u['username'] ?? '') === 'mini_test_user', 'username saved');
    ok(($u['share_token'] ?? '') !== '', 'share_token generated');
    ok(!empty($u['access']), 'access granted (mode=open)');
}

// بار دوم: باید همان ردیف را برگرداند
$u2 = $u ? MiniApp::ensureUser($user) : null;
ok($u2 !== null && (int)$u2['id'] === 777000001, 'idempotent on second call');

// ---------- ۵) متدهای داده ----------
echo "\n=== 5. Data methods ===\n";
try {
    $ov = MiniApp::overview($u);
    ok(is_array($ov), 'overview returns array');
    ok(array_key_exists('sites', $ov) || array_key_exists('user', $ov), 'overview has expected keys');
    echo "      overview keys: " . implode(', ', array_keys($ov)) . "\n";
} catch (Throwable $e) {
    ok(false, 'overview threw: ' . $e->getMessage());
}

try {
    $st = MiniApp::settings($u);
    ok(is_array($st), 'settings returns array');
    echo "      settings keys: " . implode(', ', array_keys($st)) . "\n";
} catch (Throwable $e) {
    ok(false, 'settings threw: ' . $e->getMessage());
}

foreach (['incidents' => 'incidents', 'domains' => 'domains', 'rank' => 'rank',
          'report' => 'report', 'shared' => 'shared'] as $fn => $label) {
    try {
        $r = MiniApp::$fn($u);
        ok(is_array($r), "$label() returns array");
    } catch (Throwable $e) {
        ok(false, "$label() threw: " . $e->getMessage());
    }
}

// ---------- ۶) ساخت سایت و siteDetail ----------
echo "\n=== 6. addSite + siteDetail ===\n";
$add = MiniApp::addSite($u, 'example.com');
ok(!empty($add['ok']), 'addSite example.com → ' . json_encode($add, JSON_UNESCAPED_UNICODE));

if (!empty($add['ok']) && !empty($add['site_id'])) {
    $sid = (int)$add['site_id'];
    $det = MiniApp::siteDetail($u, $sid);
    ok(is_array($det) && !empty($det), 'siteDetail returns data');
    if (is_array($det)) echo "      detail keys: " . implode(', ', array_keys($det)) . "\n";

    // مالکیت: کاربر دیگری نباید ببیند
    $other = ['id' => 777000999, 'access' => 1, 'is_admin' => 0, 'plan' => 'free', 'notify' => 1];
    $steal = MiniApp::siteDetail($other, $sid);
    ok(empty($steal), 'other user blocked from siteDetail');

    // حذف
    $del = MiniApp::deleteSite($u, $sid);
    ok(!empty($del['ok']), 'deleteSite ok');
}

// ---------- ۷) پاکسازی ----------
echo "\n=== 7. Cleanup ===\n";
Db::q('DELETE FROM `site` WHERE `user_id` = 777000001');
Db::q('DELETE FROM `user` WHERE `id` = 777000001');
ok(true, 'test user removed');

echo "\n" . str_repeat('=', 40) . "\n";
echo "PASS: $pass   FAIL: $fail\n";
echo str_repeat('=', 40) . "\n";
exit($fail > 0 ? 1 : 0);