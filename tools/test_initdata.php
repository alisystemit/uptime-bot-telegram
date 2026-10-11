<?php
/**
 * تست جامع validateInitData در برابر سناریوهای واقعی تلگرام v8+
 * اجرا: php tools/test_initdata.php
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$pass = 0; $fail = 0;
function ok($c, $m)
{
    global $pass, $fail;
    if ($c) { $pass++; print("  OK   $m\n"); } else { $fail++; print("  FAIL $m\n"); }
}

$token = '123456:TEST_TOKEN_abcdefghijklmnopqrstuvwxyz';

/** ساخت initData دقیقاً مثل تلگرام */
function buildInitData(array $fields, string $token, bool $urlencodeValues = true): string
{
    ksort($fields, SORT_STRING);
    $pairs = [];
    foreach ($fields as $k => $v) $pairs[] = "$k=$v";
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    $hash = hash_hmac('sha256', implode("\n", $pairs), $secret);

    $enc = [];
    foreach ($pairs as $p) {
        [$k, $v] = explode('=', $p, 2);
        $enc[] = rawurlencode($k) . '=' . ($urlencodeValues ? rawurlencode($v) : $v);
    }
    $enc[] = 'hash=' . $hash;
    return implode('&', $enc);
}

$user = ['id' => 777000001, 'first_name' => 'علی', 'username' => 'ali'];

echo "=== 1. پایه (بدون signature) ===\n";
$basic = buildInitData(['auth_date' => (string)time(), 'query_id' => 'AAA', 'user' => json_encode($user, JSON_UNESCAPED_UNICODE)], $token);
ok(MiniApp::validateInitData($basic, $token) !== null, 'initData بدون signature معتبر است');

echo "\n=== 2. با signature (تلگرام v8+) ===\n";
$sig = base64_encode(random_bytes(64));   // Ed25519 امضا معمولاً ۶۴ بایتی
$withSig = buildInitData([
    'auth_date' => (string)time(),
    'query_id'  => 'AAA',
    'user'      => json_encode($user, JSON_UNESCAPED_UNICODE),
    'signature' => $sig,
], $token);
ok(MiniApp::validateInitData($withSig, $token) !== null, 'initData با signature معتبر است');

echo "\n=== 3. signature حاوی کاراکترهای خاص base64 ===\n";
// یک base64 که حتماً + و / دارد
$special = base64_encode(random_bytes(48));
$hasPlus = str_contains($special, '+');
echo "  (base64 شامل '+': " . ($hasPlus ? 'بله' : 'خیر') . ")\n";

// با urlencode صحیح (تلگرام این کار را می‌کند)
$s3 = buildInitData([
    'auth_date' => (string)time(),
    'query_id'  => 'AAA',
    'user'      => json_encode($user, JSON_UNESCAPED_UNICODE),
    'signature' => $special,
], $token);
ok(MiniApp::validateInitData($s3, $token) !== null, 'signature با urlencode صحیح');

echo "\n=== 4. signature بدون urlencode (تلگرام اینطور نمی‌فرستد، ولی بررسی می‌کنیم) ===\n";
$s4 = buildInitData([
    'auth_date' => (string)time(),
    'query_id'  => 'AAA',
    'user'      => json_encode($user, JSON_UNESCAPED_UNICODE),
    'signature' => $special,
], $token, false);
$res4 = MiniApp::validateInitData($s4, $token);
echo "  نتیجه: " . ($res4 ? 'معتبر' : 'نامعتبر (parse_str ممکن است + را خراب کند)') . "\n";

echo "\n=== 5. سایر فیلدهای اختیاری تلگرام ===\n";
$all = [
    'auth_date'     => (string)time(),
    'query_id'      => 'AAHdF6IQAAAAAN0XohDhrOrc',
    'user'          => json_encode($user, JSON_UNESCAPED_UNICODE),
    'chat_type'     => 'sender',
    'chat_instance' => '-1234567890',
    'start_param'   => 'myref_123',
    'can_send_after' => '60',
    'signature'     => base64_encode(random_bytes(64)),
];
$s5 = buildInitData($all, $token);
ok(MiniApp::validateInitData($s5, $token) !== null, 'همهٔ فیلدهای تلگرام با هم');

echo "\n=== 6. موارد رد شدنی ===\n";
ok(MiniApp::validateInitData($withSig, 'other-token') === null, 'توکن اشتباه رد می‌شود');
ok(MiniApp::validateInitData('', $token) === null, 'خالی رد می‌شود');

$old = $all; $old['auth_date'] = (string)(time() - 8 * 86400);
ok(MiniApp::validateInitData(buildInitData($old, $token), $token) === null, 'منقضی (۸ روز) رد می‌شود');

// دستکاری: تغییر user بعد از امضا
$parts = [];
foreach (explode('&', $withSig) as $p) $parts[] = $p;
$parts = array_map(function ($p) {
    if (str_starts_with($p, 'user=')) {
        return 'user=' . rawurlencode(json_encode(['id' => 999, 'first_name' => 'HACKER'], JSON_UNESCAPED_UNICODE));
    }
    return $p;
}, $parts);
ok(MiniApp::validateInitData(implode('&', $parts), $token) === null, 'دستکاری user رد می‌شود');

// حذف signature از دادهٔ معتبر
$noSig = $all; unset($noSig['signature']);
$parts2 = [];
foreach (explode('&', buildInitData($all, $token)) as $p) {
    if (!str_starts_with($p, 'signature=')) $parts2[] = $p;
}
ok(MiniApp::validateInitData(implode('&', $parts2), $token) === null, 'حذف signature از دادهٔ معتبر رد می‌شود');

echo "\n=== 7. اندازهٔ initData ===\n";
$big = buildInitData([
    'auth_date' => (string)time(),
    'user'      => json_encode(array_merge($user, ['bio' => str_repeat('x', 9000)]), JSON_UNESCAPED_UNICODE),
], $token);
ok(MiniApp::validateInitData($big, $token) === null, 'initData بیش از 8192 بایت رد می‌شود');

echo "\n" . str_repeat('=', 40) . "\n";
echo "PASS: $pass   FAIL: $fail\n";
echo str_repeat('=', 40) . "\n";
exit($fail > 0 ? 1 : 0);