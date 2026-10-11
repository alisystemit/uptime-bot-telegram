<?php
/**
 * تست سه مسیر انتقال initData (هدر / query / POST)
 * نیازمند سرور تست روی پورت 8324
 * اجرا: php tools/test_transport.php
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
appBoot();

$probe = @fsockopen('127.0.0.1', 8324, $e, $s, 1);
if (!$probe) {
    echo "SKIP: سرور تست روی 127.0.0.1:8324 نیست\n";
    echo "اجرا: node tools/_srv.js\n";
    exit(0);
}
fclose($probe);

$BASE = 'http://127.0.0.1:8324/appapi.php';

// ---------- initData معتبر ----------
$fields = [
    'auth_date' => (string)time(),
    'query_id'  => 'AAHdF6IQAAAAAN0XohDhrOrc',
    'user'      => json_encode([
        'id' => 777000001, 'first_name' => 'Ali',
        'username' => 'ali_test', 'language_code' => 'fa',
    ], JSON_UNESCAPED_UNICODE),
];
ksort($fields, SORT_STRING);
$pairs = [];
foreach ($fields as $k => $v) $pairs[] = "$k=$v";
$secret = hash_hmac('sha256', botToken(), 'WebAppData', true);
$hash = hash_hmac('sha256', implode("\n", $pairs), $secret);
$enc = [];
foreach ($pairs as $p) { [$k, $v] = explode('=', $p, 2); $enc[] = rawurlencode($k) . '=' . rawurlencode($v); }
$enc[] = 'hash=' . $hash;
$initData = implode('&', $enc);

// ---------- کاربر ----------
$uid = 777000001;
if (!Db::one('SELECT `id` FROM `user` WHERE `id` = ?', [$uid])) {
    Db::q('INSERT INTO `user` (`id`,`name`,`username`,`access`,`share_token`,`plan`,`created_at`,`last_seen`)
           VALUES (?,?,?,1,?,"vip",NOW(),NOW())',
        [$uid, 'Ali', 'ali_test', 'sim' . substr(md5('sim'), 0, 10)]);
}

$pass = 0; $fail = 0;
function ok($c, $m)
{
    global $pass, $fail;
    if ($c) { $pass++; print("  OK   $m\n"); } else { $fail++; print("  FAIL $m\n"); }
}

function req(string $url, string $method, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($method === 'POST') {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = $body ?? '';
    }
    curl_setopt_array($ch, $opt);
    $out = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$code, json_decode($out, true)];
}

echo "═══ 1. مسیر هدر (روش فعلی قبل) ═══\n";
[$c, $j] = req($BASE . '?a=overview', 'GET', ['X-Telegram-Init-Data: ' . $initData]);
ok($c === 200 && !empty($j['ok']), "header → 200 (got $c " . ($j['error'] ?? '') . ")");

echo "\n═══ 2. مسیر query string (روش ربات مرجع) ═══\n";
[$c, $j] = req($BASE . '?a=overview&initData=' . urlencode($initData), 'GET');
ok($c === 200 && !empty($j['ok']), "query → 200 (got $c " . ($j['error'] ?? '') . ")");

echo "\n═══ 3. مسیر POST body ═══\n";
[$c, $j] = req($BASE . '?a=overview', 'POST',
    ['Content-Type: application/x-www-form-urlencoded'],
    'initData=' . urlencode($initData));
ok($c === 200 && !empty($j['ok']), "POST → 200 (got $c " . ($j['error'] ?? '') . ")");

echo "\n═══ 4. هدر خراب + query سالم (شبیه‌سازی WAF) ═══\n";
[$c, $j] = req($BASE . '?a=overview&initData=' . urlencode($initData), 'GET',
    ['X-Telegram-Init-Data: CORRUPTED-BY-WAF']);
ok($c === 200 && !empty($j['ok']), "waf-broken header → query rescues (got $c " . ($j['error'] ?? '') . ")");

echo "\n═══ 5. هر سه خراب ═══\n";
[$c, $j] = req($BASE . '?a=overview&initData=' . urlencode('hash=deadbeef&auth_date=' . time()), 'GET',
    ['X-Telegram-Init-Data: also-broken']);
ok($c === 401, "all broken → 401 (got $c)");

echo "\n═══ 6. هیچ‌کدام ارسال نشده ═══\n";
[$c, $j] = req($BASE . '?a=overview', 'GET');
ok($c === 401, "none → 401 (got $c)");

echo "\n═══ 7. عملیات نوشتنی با POST body ═══\n";
$siteId = (int)Db::val('SELECT `id` FROM `site` WHERE `user_id` = ? LIMIT 1', [$uid]);
if ($siteId === 0) {
    $n = normalizeTarget('example.com');
    Db::q('INSERT INTO `site` (`user_id`,`chat_id`,`target`,`label`,`type`,`host`,`port`,`max_ms`,`keyword`,`share_token`,`status`,`last_ms`,`resp_avg`,`last_check_at`)
           VALUES (?,0,?,?,"http",?,0,0,"",?,"up",240,250,NOW())',
        [$uid, $n['target'], 'سایت تست', $n['host'], 'sim' . substr(md5('s'), 0, 15)]);
    $siteId = (int)Db::val('SELECT `id` FROM `site` WHERE `user_id` = ? LIMIT 1', [$uid]);
}
[$c, $j] = req($BASE . '?a=check&id=' . $siteId, 'POST',
    ['Content-Type: application/x-www-form-urlencoded', 'X-Telegram-Init-Data: BROKEN'],
    'initData=' . urlencode($initData));
ok($c === 200 && !empty($j['ok']), "POST check with broken header → body rescues (got $c " . ($j['error'] ?? '') . ")");

echo "\n" . str_repeat('=', 42) . "\n";
echo "PASS: $pass   FAIL: $fail\n";
echo str_repeat('=', 42) . "\n";
exit($fail > 0 ? 1 : 0);