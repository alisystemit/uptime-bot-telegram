<?php
/**
 * داده‌ی نمونه برای تست و بنچمارک (بدون نیاز به اینترنت/تلگرام)
 * اجرا:  php tests/seed.php
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$port = (int)(getenv('BENCH_PORT') ?: 8111);
$base = 'http://127.0.0.1:' . $port;
$httpUp = $base . '/';
$httpDown = 'http://127.0.0.1:1/';       // همیشه قطع
$httpSlow = $base . '/slow.php';          // ۲ ثانیه تأخیر

appBoot();
Db::migrate();
foreach (['check_log', 'uptime_hour', 'incident', 'site_share', 'domain_watch', 'chat_admin', 'chat_hub', 'site', 'events', 'codes', 'payments', 'seen_update', 'user', 'settings'] as $t) {
    try { Db::exec("TRUNCATE TABLE `$t`"); } catch (Throwable $e) { /* ignore */ }
}
Db::migrate();

$uid = 777000;
Db::q('INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`share_token`,`created_at`,`last_seen`) VALUES (?,?,?,1,1,?,NOW(),NOW())',
    [$uid, 'کاربر تست', 'tester', 'usertoken00000000001']);
$uid2 = 777001;
Db::q('INSERT INTO `user` (`id`,`name`,`username`,`access`,`share_token`,`created_at`,`last_seen`) VALUES (?,?,?,1,?,NOW(),NOW())',
    [$uid2, 'دوست', 'friend', 'usertoken00000000002']);

$mk = static function (array $url, string $label, array $extra = []) use ($uid) {
    $n = normalizeTarget($url);
    $tok = makeShareToken(20);
    $cols = ['user_id' => $uid, 'chat_id' => 0, 'target' => $n['target'], 'label' => $label,
        'type' => $n['type'], 'host' => $n['host'], 'port' => (int)$n['port'], 'share_token' => $tok];
    foreach ($extra as $k => $v) $cols[$k] = $v;
    $k = array_keys($cols);
    $ph = implode(',', array_fill(0, count($k), '?'));
    Db::q('INSERT INTO `site` (`' . implode('`,`', $k) . '`,`created_at`) VALUES (' . $ph . ',NOW())', array_values($cols));
    return (int)Db::val('SELECT MAX(`id`) FROM `site`');
};

$sites = [
    $mk($httpUp, 'سایت اصلی'),
    $mk($httpUp . 'health', 'صفحهٔ سلامت'),
    $mk('127.0.0.1:59999', 'پورت بسته'),
    $mk($httpDown, 'قطع'),
    $mk('127.0.0.1', 'پینگ لوکال'),
];

// چند سایت بیشتر برای بنچمارک
for ($i = 0; $i < 8; $i++) $mk($httpUp . 'bench' . $i, 'bench ' . $i);

$gid = -1001234567890;
Group::touch($gid, 'supergroup', 'تیم عملیات');
Db::q('INSERT INTO `chat_admin` (`chat_id`,`user_id`,`is_admin`,`checked_at`) VALUES (?,?,1,NOW())', [$gid, $uid]);
$g = normalizeTarget($httpUp . 'group');
Db::q('INSERT INTO `site` (`user_id`,`chat_id`,`chat_title`,`target`,`label`,`type`,`host`,`port`,`share_token`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,NOW())',
    [$uid, $gid, 'تیم عملیات', $g['target'], 'مانیتور گروهی', $g['type'], $g['host'], (int)$g['port'], makeShareToken(20)]);

// آستانهٔ کندی + کلیدواژه
Db::q('UPDATE `site` SET `max_ms` = 300, `keyword` = ? WHERE `id` = ?', ['SENTINEL_OK', $sites[0]]);

// اشتراک با دوست
Db::q('INSERT INTO `site_share` (`site_id`,`user_id`,`role`,`notify`,`created_at`) VALUES (?,?,?,1,NOW())', [$sites[0], $uid2, 'manager']);

// ۷ روز دادهٔ مصنوعی برای اینکه نمودارها و آپتایم ۳۰ روزه خالی نباشند
$ids = array_map(static fn($r) => (int)$r['id'], Db::all('SELECT `id` FROM `site`'));
$now = time();
foreach ($ids as $sid) {
    for ($h = 0; $h < 24 * 8; $h++) {
        $bucket = gmdate('Y-m-d H:00:00', $now - $h * 3600);
        $checks = 180;
        $fails = ($h % 37 === 0) ? 12 : (random_int(0, 20) === 0 ? 3 : 0);
        $ok = $checks - $fails;
        $ms = random_int(40, 900);
        Db::q('INSERT INTO `check_log` (`site_id`,`ts`,`ok`,`ms`,`code`,`error`) VALUES (?,?,?,?,?,?)',
            [$sid, gmdate('Y-m-d H:i:s', $now - $h * 3600), 1, $ms, 200, '']);
        if ($fails > 0) {
            Db::q('INSERT INTO `check_log` (`site_id`,`ts`,`ok`,`ms`,`code`,`error`) VALUES (?,?,0,0,0,?)',
                [$sid, gmdate('Y-m-d H:i:s', $now - $h * 3600), 'timeout']);
        }
        Db::q('INSERT INTO `uptime_hour` (`site_id`,`bucket`,`checks`,`ok`,`total_ms`) VALUES (?,?,?,?,?)
               ON DUPLICATE KEY UPDATE `checks`=`checks`+VALUES(`checks`), `ok`=`ok`+VALUES(`ok`), `total_ms`=`total_ms`+VALUES(`total_ms`)',
            [$sid, $bucket, $checks, $ok, $ms * $checks]);
    }
    // چند رخداد بسته‌شده
    for ($d = 1; $d <= 4; $d++) {
        $st = gmdate('Y-m-d H:i:s', $now - $d * 86400 - 3600);
        $dur = 300 + $d * 120;
        Db::q('INSERT INTO `incident` (`site_id`,`kind`,`start_at`,`end_at`,`duration`,`reason`,`peak_ms`,`checks`) VALUES (?,?,?,?,?,?,?,?)',
            [$sid, $d % 3 === 0 ? 'slow' : 'down', $st, gmdate('Y-m-d H:i:s', strtotime($st) + $dur), $dur, 'پاسخی دریافت نشد (Timeout)', 2400, 12]);
    }
    $r = Monitor::checkSite($sid);
    Db::q('UPDATE `site` SET `resp_avg` = 320, `resp_p95` = 780, `resp_max` = 1900 WHERE `id` = ?', [$sid]);
}
Db::set('last_round_at', gmdate('Y-m-d H:i:s'));
Db::set('last_maint', (string)time());

echo "seeded: sites=" . Db::val('SELECT COUNT(*) FROM `site`') . " checks=" . Db::val('SELECT COUNT(*) FROM `check_log`') . "\n";
echo "user token: " . Db::val('SELECT `share_token` FROM `user` WHERE id = ?', [$uid]) . "\n";
