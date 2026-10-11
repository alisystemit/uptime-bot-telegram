<?php
/**
 * تست end-to-end مینی‌اپ از طریق HTTP واقعی
 *
 * پیش‌نیاز: یک وب‌سرور که appapi.php را اجرا کند. مثلاً:
 *   php -S 127.0.0.1:8323 -t .        (ولی CLI server متغیرهای CGI را
 *                                       کامل نمی‌سازد؛ بهتر است وب‌سرور
 *                                       واقعی مثل Apache/nginx باشد)
 * سپس:
 *   UPTIME_CONFIG=tests/config.local.php php tools/test_miniapp_http.php
 *
 * اگر سرور در دسترس نباشد، تست skip می‌شود (نه fail).
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
appBoot();

$BASE = 'http://127.0.0.1:8323/appapi.php';

// ---------- بررسی در دسترس بودن سرور ----------
$probe = @fsockopen('127.0.0.1', 8323, $errno, $errstr, 1);
if (!$probe) {
    echo "SKIP: سرور 127.0.0.1:8323 در دسترس نیست ($errstr)\n";
    echo "برای اجرا یک وب‌سرور روی این پورت بالا بیاورید.\n";
    exit(0);
}
fclose($probe);

$pass = 0; $fail = 0;
function ok($c, $m)
{
    global $pass, $fail;
    if ($c) { $pass++; print("  OK   $m\n"); } else { $fail++; print("  FAIL $m\n"); }
}

// ---------- ساخت initData معتبر ----------
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
ksort($fields, SORT_STRING);
$pairs = [];
foreach ($fields as $k => $v) $pairs[] = "$k=$v";
$secret = hash_hmac('sha256', botToken(), 'WebAppData', true);
$hash = hash_hmac('sha256', implode("\n", $pairs), $secret);
$enc = [];
foreach ($pairs as $p) { [$k, $v] = explode('=', $p, 2); $enc[] = urlencode($k) . '=' . urlencode($v); }
$enc[] = 'hash=' . $hash;
$initData = implode('&', $enc);

// ---------- کاربر و سایت ----------
$uid = 777000001;
if (!Db::one('SELECT `id` FROM `user` WHERE `id` = ?', [$uid])) {
    Db::q('INSERT INTO `user` (`id`,`name`,`username`,`access`,`share_token`,`plan`,`created_at`,`last_seen`)
           VALUES (?,?,?,1,?,"vip",NOW(),NOW())',
        [$uid, 'تست مینی', 'mini_test_user', 'sim' . substr(md5('sim'), 0, 10)]);
}
if (!Db::one('SELECT `id` FROM `site` WHERE `user_id` = ? LIMIT 1', [$uid])) {
    $n = normalizeTarget('example.com');
    Db::q('INSERT INTO `site` (`user_id`,`chat_id`,`target`,`label`,`type`,`host`,`port`,`max_ms`,`keyword`,`share_token`,`status`,`last_ms`,`resp_avg`,`last_check_at`)
           VALUES (?,0,?,?,"http",?,0,0,"",?,"up",240,250,NOW())',
        [$uid, $n['target'], 'سایت تست', $n['host'], 'sim' . substr(md5('s'), 0, 15)]);
}

// ---------- درخواست HTTP ----------
$base = $BASE;

function httpGet(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$code, json_decode($body, true), $body];
}

function httpPost(string $url, array $fields, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$code, json_decode($body, true), $body];
}

$H = ['X-Telegram-Init-Data: ' . $initData];

// ---------- تست‌ها ----------
echo "=== 1. Auth: no initData ===\n";
[$c, $j] = httpGet($base . '?a=overview');
ok($c === 401, "no initData → 401 (got $c)");
ok(($j['error'] ?? '') === 'auth', 'error=auth');

echo "\n=== 2. Auth: wrong initData ===\n";
[$c, $j] = httpGet($base . '?a=overview', ['X-Telegram-Init-Data: hash=deadbeef&auth_date=' . time()]);
ok($c === 401, "forged initData → 401 (got $c)");

echo "\n=== 3. Valid initData → overview ===\n";
[$c, $j, $raw] = httpGet($base . '?a=overview', $H);
ok($c === 200, "overview → 200 (got $c)");
if ($c !== 200) echo "  raw: " . substr($raw, 0, 300) . "\n";
ok(!empty($j['ok']), 'json.ok = true');
ok(isset($j['data']['user']), 'data.user present');
ok(isset($j['data']['sites']), 'data.sites present');
ok(isset($j['tz']), 'tz present');
if (isset($j['data']['sites'])) echo "      sites: " . count($j['data']['sites']) . "\n";

echo "\n=== 4. All GET endpoints ===\n";
foreach (['incidents', 'domains', 'rank', 'report', 'shared', 'settings'] as $a) {
    [$c, $j] = httpGet($base . '?a=' . $a, $H);
    ok($c === 200 && !empty($j['ok']), "a=$a → 200 ok (got $c " . ($j['error'] ?? '') . ")");
}

echo "\n=== 5. site detail ===\n";
$siteId = (int)Db::val('SELECT `id` FROM `site` WHERE `user_id` = ? LIMIT 1', [$uid]);
[$c, $j] = httpGet($base . '?a=site&id=' . $siteId, $H);
ok($c === 200 && !empty($j['ok']), "site detail → 200 (got $c)");
ok(isset($j['data']['bars']), 'has bars');

[$c, $j] = httpGet($base . '?a=site&id=999999', $H);
ok($c === 404, "unknown site → 404 (got $c)");

echo "\n=== 6. Write ops require POST ===\n";
[$c, $j] = httpGet($base . '?a=check&id=' . $siteId, $H);
ok($c === 405, "GET a=check → 405 (got $c)");
ok(($j['error'] ?? '') === 'method', 'error=method');

[$c, $j] = httpGet($base . '?a=add', $H);
ok($c === 405, "GET a=add → 405 (got $c)");

echo "\n=== 7. POST check ===\n";
[$c, $j] = httpPost($base . '?a=check&id=' . $siteId, [], $H);
ok($c === 200 && !empty($j['ok']), "POST check → 200 (got $c " . ($j['error'] ?? '') . ")");

echo "\n=== 8. POST add + delete ===\n";
[$c, $j] = httpPost($base . '?a=add', ['target' => 'example.net'], $H);
ok($c === 200 && !empty($j['ok']), 'POST add example.net (got ' . $c . ' ' . ($j['error'] ?? '') . ')');
$newId = (int)($j['data']['site_id'] ?? 0);

if ($newId > 0) {
    [$c, $j] = httpPost($base . '?a=delete&id=' . $newId, [], $H);
    ok($c === 200 && !empty($j['ok']), "POST delete → 200 (got $c)");
}

echo "\n=== 9. Unknown action ===\n";
[$c, $j] = httpGet($base . '?a=nope', $H);
ok($c === 404, "unknown action → 404 (got $c)");

echo "\n=== 10. CORS / headers ===\n";
$ch = curl_init($base . '?a=overview');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $H,
    CURLOPT_HEADER         => true,
    CURLOPT_NOBODY         => false,
]);
$full = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
ok($code === 200, "header fetch 200");
ok(stripos($full, 'Content-Type: application/json') !== false, 'JSON content-type');
ok(stripos($full, 'X-Content-Type-Options: nosniff') !== false, 'nosniff header');

echo "\n" . str_repeat('=', 40) . "\n";
echo "PASS: $pass   FAIL: $fail\n";
echo str_repeat('=', 40) . "\n";
exit($fail > 0 ? 1 : 0);