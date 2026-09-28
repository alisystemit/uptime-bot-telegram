<?php
/**
 * بنچمارک ربات مانیتورینگ — اندازه‌گیری تعداد کوئری و زمان اجرا
 * اجرا:  UPTIME_DEBUG=1 php tests/bench.php
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/bootstrap.php';

appBoot();
Db::migrate();

$uid = (int)Db::val('SELECT `id` FROM `user` LIMIT 1');
if (!$uid) {
    echo "no user\n";
    exit(1);
}
$gid = (int)Db::val('SELECT `chat_id` FROM `chat_hub` LIMIT 1');
$uTok = (string)Db::val('SELECT `share_token` FROM `user` WHERE `id` = ?', [$uid]);
$gTok = $gid ? (string)Db::val('SELECT `share_token` FROM `chat_hub` WHERE `chat_id` = ?', [$gid]) : '';
$sTok = (string)Db::val('SELECT `share_token` FROM `site` LIMIT 1');

function bench(string $label, callable $fn, int $times = 3): array
{
    $bestQ = PHP_INT_MAX; $bestMs = PHP_INT_MAX; $out = null;
    for ($i = 0; $i < $times; $i++) {
        Db::debug(true);
        $t0 = microtime(true);
        $out = $fn();
        $ms = (microtime(true) - $t0) * 1000;
        $st = Db::stats();
        $bestQ = min($bestQ, $st['queries']);
        $bestMs = min($bestMs, $ms);
    }
    Db::debug(false);
    printf("  %-34s %5d کوئری  %8.1f میلی‌ثانیه\n", $label, $bestQ, $bestMs);
    return [$bestQ, $bestMs, $out];
}

echo "=== بنچمارک (بهترین از ۳ اجرا) ===\n";
$t = [];

// ---------- ۱) رندر صفحهٔ وضعیت کاربر ----------
$ctx = Page::resolve($uTok, '', '');
$html = static function () use ($ctx) {
    ob_start();
    // رندر داخلی: همان کاری که status.php می‌کند
    $f = function (array $site, float $tz) {
        $recent = Stats::recent($site, 60);
        $c = Stats::counters($site);
        Stats::daily($site, 30);
        Stats::hourly($site, 24);
        Db::val('SELECT MIN(`ms`) FROM `check_log` WHERE `site_id` = ? AND `ok` = 1 AND `ms` > 0 AND `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)', [(int)$site['id']]);
        Stats::incidents((int)$site['id'], 4);
        return strlen(Stats::barHtml($recent)) + (int)$c['checks'] + (int)$c['fails'];
    };
    $n = 0;
    foreach ($ctx['sites'] as $s) $n += $f($s, 3.5);
    ob_end_clean();
    return $n;
};
$t['page_render'] = bench('رندر داده‌های صفحهٔ وضعیت', $html);

bench('Page::payload (سبک، هر ۲۵ ثانیه)', static fn() => Page::payload($ctx, true));
bench('Page::payload (کامل)', static fn() => Page::payload($ctx, false));
bench('Stats::userSummary', static fn() => Stats::userSummary($uid));
bench('Stats::adminOverview', static fn() => Stats::adminOverview());
bench('Stats::pauseStats', static fn() => Stats::pauseStats());
bench('Stats::engine', static fn() => Stats::engine());
bench('Site::detail (single)', static function () use ($uid) {
    $s = Db::one('SELECT * FROM `site` WHERE `user_id` = ? AND chat_id = 0 LIMIT 1', [$uid]);
    Stats::uptime($s, 1); Stats::uptime($s, 7); Stats::uptime($s, 30);
    Stats::counters($s); Stats::recent($s, 60); Stats::incidents($s['id'], 12);
    return 1;
});

// ---------- ۲) موتور چک ----------
Db::q('UPDATE `site` SET `last_check_at` = NULL');
$r = bench('Monitor::round (یک راند کامل)', static fn() => Monitor::round(), 1);
$t['round'] = $r;
bench('Monitor::checkSite (تکی)', static function () use ($uid) {
    $id = (int)Db::val('SELECT `id` FROM `site` WHERE `user_id` = ? AND chat_id = 0 LIMIT 1', [$uid]);
    return Monitor::checkSite($id);
});

// ---------- ۳) موتور چک با حجم بالا ----------
$sitesN = (int)Db::val('SELECT COUNT(*) FROM `site`');
if ($sitesN < 5) {
    for ($i = $i ?? 0; $i < 8; $i++) {
        $n = normalizeTarget('http://127.0.0.1:8111/bench' . $i);
        Db::q('INSERT INTO `site` (`user_id`,`chat_id`,`target`,`label`,`type`,`host`,`port`,`share_token`,`created_at`) VALUES (?,0,?,?,?,?,?,?,NOW())',
            [$uid, $n['target'], 'bench' . $i, $n['type'], $n['host'], (int)$n['port'], makeShareToken(20)]);
    }
    Db::q('UPDATE `site` SET `last_check_at` = NULL');
}
$r2 = bench('Monitor::round با ' . Db::val('SELECT COUNT(*) FROM `site`') . ' سایت', static fn() => Monitor::round(), 1);
$t['round_big'] = $r2;

echo "\n=== پرکاربردترین کوئری‌ها (N+1 detector) ===\n";
Db::debug(true);
$ctx2 = Page::resolve($uTok, '', '');
foreach ($ctx2['sites'] as $s) { Stats::daily($s, 30); Stats::hourly($s, 24); Stats::uptime($s, 1); }
foreach (Db::statsTop(8) as $sql => $info) {
    printf("  %4d×  %6.1fms  %s\n", $info['n'], $info['ms'], $sql);
}
Db::debug(false);

echo "\n";
printf("خلاصه: رندر صفحه %d کوئری | راند کوچک %d | راند بزرگ %d\n",
    $t['page_render'][0], $t['round'][0], $t['round_big'][0]);
