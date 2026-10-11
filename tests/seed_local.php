<?php
/**
 * ===== دادهٔ نمونه برای تست =====
 * یک کاربر + ۲ سایت + ۴۸ ساعت چک‌لاگ + uptime_hour + یک رخداد قطعی
 * اجرا: php tests/seed_local.php
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
appBoot();

$uid = 999999001;

Db::q('DELETE FROM `check_log`    WHERE `site_id` IN (SELECT `id` FROM `site` WHERE `user_id` = ?)', [$uid]);
Db::q('DELETE FROM `uptime_hour`  WHERE `site_id` IN (SELECT `id` FROM `site` WHERE `user_id` = ?)', [$uid]);
Db::q('DELETE FROM `incident`     WHERE `site_id` IN (SELECT `id` FROM `site` WHERE `user_id` = ?)', [$uid]);
Db::q('DELETE FROM `webhooks`     WHERE `user_id` = ?', [$uid]);
Db::q('DELETE FROM `site`         WHERE `user_id` = ?', [$uid]);
Db::q('DELETE FROM `user`         WHERE `id` = ?', [$uid]);

Db::q(
    'INSERT INTO `user` (`id`,`name`,`username`,`share_token`,`access`,`plan`)
     VALUES (?,?,?,?,1,"vip")',
    [$uid, 'کاربر تست', 'test_user', 'testshare' . substr(md5((string)$uid), 0, 8)]
);

$sites = [];
foreach ([
    ['https://example.com', 'سایت اول'],
    ['example.org:8080', 'سایت دوم'],
] as $i => [$target, $label]) {
    $norm = normalizeTarget($target);
    if (empty($norm['ok'])) {
        echo "skip $target: " . ($norm['error'] ?? '?') . "\n";
        continue;
    }
    Db::q(
        'INSERT INTO `site`
           (`user_id`,`chat_id`,`target`,`label`,`type`,`host`,`port`,`max_ms`,`keyword`,`share_token`,`status`,`last_ms`,`resp_avg`,`resp_max`,`resp_p95`)
         VALUES (?,0,?,?,?,?,?,0,"",?,?,?,?,?,?)',
        [
            $uid,
            $norm['target'],
            $label,
            $norm['type'],
            $norm['host'],
            (int)($norm['port'] ?? 0),
            'seed' . substr(md5($target . $uid), 0, 12),
            $i === 0 ? 'down' : 'up',
            $i === 0 ? 0 : 240,
            $i === 0 ? 0 : 250,
            $i === 0 ? 0 : 900,
            $i === 0 ? 0 : 480,
        ]
    );
    $sites[] = (int)Db::pdo()->lastInsertId();
}

if (!$sites) {
    echo "no sites seeded\n";
    exit(1);
}
echo "sites: " . implode(',', $sites) . "\n";

// ---------- چک‌لاگ ۴۸ ساعت، هر ۵ دقیقه ----------
$rows = [];
$args = [];
for ($h = 48; $h >= 0; $h--) {
    for ($m = 0; $m < 60; $m += 5) {
        $ts = date('Y-m-d H:i:s', time() - ($h * 3600 + $m * 60));
        // سایت اول: ۱۰٪ خرابی + یک بازهٔ خرابی پیوسته در ۳ ساعت آخر
        $down = ($h < 3) || (mt_rand(1, 100) <= 8);
        $ms = $down ? 0 : mt_rand(120, 400);
        $rows[] = '(?,?,?,?,?,?)';
        array_push($args, $sites[0], $ts, $down ? 0 : 1, $ms, $down ? 0 : 200, $down ? 'timeout' : '');
    }
}
foreach (array_chunk($args, 900) as $chunk) {
    $vals = array_chunk($chunk, 6);
    $ph = implode(',', array_fill(0, count($vals), '(?,?,?,?,?,?)'));
    Db::q('INSERT INTO `check_log` (`site_id`,`ts`,`ok`,`ms`,`code`,`error`) VALUES ' . $ph, $chunk);
}

// سایت دوم: تقریباً همیشه سالم
$args2 = [];
for ($h = 48; $h >= 0; $h--) {
    for ($m = 0; $m < 60; $m += 5) {
        $down = mt_rand(1, 100) <= 2;
        $args2[] = $sites[1];
        $args2[] = date('Y-m-d H:i:s', time() - ($h * 3600 + $m * 60));
        $args2[] = $down ? 0 : 1;
        $args2[] = $down ? 0 : mt_rand(150, 350);
        $args2[] = $down ? 0 : 200;
        $args2[] = '';
    }
}
foreach (array_chunk($args2, 900) as $chunk) {
    $vals = array_chunk($chunk, 6);
    $ph = implode(',', array_fill(0, count($vals), '(?,?,?,?,?,?)'));
    Db::q('INSERT INTO `check_log` (`site_id`,`ts`,`ok`,`ms`,`code`,`error`) VALUES ' . $ph, $chunk);
}

// ---------- uptime_hour (۴۸ ساعت) ----------
foreach ($sites as $si => $sid) {
    $args3 = [];
    for ($h = 47; $h >= 0; $h--) {
        $checks = 300;
        $fails  = $sid === $sites[0] ? mt_rand(10, 40) : mt_rand(0, 6);
        $args3[] = $sid;
        $args3[] = date('Y-m-d H:00:00', time() - ($h * 3600));
        $args3[] = $checks;
        $args3[] = $checks - $fails;
        $args3[] = ($checks - $fails) * mt_rand(180, 350);
    }
    $vals = array_chunk($args3, 5);
    $ph = implode(',', array_fill(0, count($vals), '(?,?,?,?,?)'));
    Db::q(
        'INSERT INTO `uptime_hour` (`site_id`,`bucket`,`checks`,`ok`,`total_ms`) VALUES ' . $ph . '
         ON DUPLICATE KEY UPDATE `checks`=VALUES(`checks`), `ok`=VALUES(`ok`), `total_ms`=VALUES(`total_ms`)',
        $args3
    );
}

// ---------- یک رخداد قطعی بسته‌شده + یک باز ----------
Db::q(
    'INSERT INTO `incident` (`site_id`,`kind`,`start_at`,`end_at`,`duration`,`reason`,`checks`)
     VALUES (?,"down", DATE_SUB(NOW(), INTERVAL 20 HOUR), DATE_SUB(NOW(), INTERVAL 19 HOUR), 3600, "timeout", 300)',
    [$sites[0]]
);
Db::q(
    'INSERT INTO `incident` (`site_id`,`kind`,`start_at`,`end_at`,`duration`,`reason`,`checks`)
     VALUES (?,"slow", DATE_SUB(NOW(), INTERVAL 5 HOUR), DATE_SUB(NOW(), INTERVAL 4 HOUR), 600, "پاسخ کند", 60)',
    [$sites[1]]
);

echo "check_log   : " . (int)Db::val('SELECT COUNT(*) FROM `check_log`') . "\n";
echo "uptime_hour : " . (int)Db::val('SELECT COUNT(*) FROM `uptime_hour`') . "\n";
echo "incidents   : " . (int)Db::val('SELECT COUNT(*) FROM `incident`') . "\n";
echo "seed OK\n";