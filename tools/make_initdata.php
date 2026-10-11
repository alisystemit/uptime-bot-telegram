<?php
/**
 * ساخت initData معتبر برای تست (بر پایهٔ الگوریتم رسمی HMAC)
 * اجرا: php tools/make_initdata.php [--signature] [--age=3600]
 * خروجی: رشتهٔ initData روی stdout
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$opts = getopt('', ['signature', 'age::']);
$withSig = isset($opts['signature']);
$age = (int)($opts['age'] ?? 0);

$fields = [
    'auth_date' => (string)(time() - $age),
    'query_id'  => 'AAHdF6IQAAAAAN0XohDhrOrc',
    'user'      => json_encode([
        'id' => 777000001,
        'first_name' => 'Ali',
        'last_name' => 'Test',
        'username' => 'ali_test',
        'language_code' => 'fa',
    ], JSON_UNESCAPED_UNICODE),
];
if ($withSig) {
    // امضای Ed25519 واقعی ۶۴ بایت است؛ برای تست کافی است
    $fields['signature'] = base64_encode(random_bytes(64));
}

ksort($fields, SORT_STRING);
$pairs = [];
foreach ($fields as $k => $v) $pairs[] = "$k=$v";

$secret = hash_hmac('sha256', botToken(), 'WebAppData', true);
$hash = hash_hmac('sha256', implode("\n", $pairs), $secret);

$enc = [];
foreach ($pairs as $p) {
    [$k, $v] = explode('=', $p, 2);
    $enc[] = rawurlencode($k) . '=' . rawurlencode($v);
}
$enc[] = 'hash=' . $hash;

// نکته: BOM یا فاصله‌های ابتدا/انتها نباید وارد initData شوند
echo implode('&', $enc) . "\n";