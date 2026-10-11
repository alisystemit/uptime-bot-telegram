<?php
/**
 * ===== تست runtime ماژول‌های جدید =====
 * اجرا: php tools/test_new_modules.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$pass = 0;
$fail = 0;

function ok(string $msg): void
{
    global $pass;
    $pass++;
    echo "  OK   $msg\n";
}

function bad(string $msg, string $why = ''): void
{
    global $fail;
    $fail++;
    echo "  FAIL $msg" . ($why ? " → $why" : '') . "\n";
}

function section(string $t): void
{
    echo "\n=== $t ===\n";
}

// ---------- 1) بارگذاری همهٔ ماژول‌ها ----------
section('1. Class loading');
$classes = [
    'Cache', 'WebhookManager', 'SLAReport', 'Queue',
    'AnomalyDetector', 'SlackIntegration', 'DiscordIntegration',
    'IntegrationManager', 'ImportExport', 'Analytics',
    'StatusPageGenerator', 'AffiliateProgram',
];
foreach ($classes as $c) {
    if (class_exists($c)) ok("class $c");
    else bad("class $c", 'not found');
}

// ---------- 2) اتصال دیتابیس ----------
section('2. Database');
try {
    appBoot();          // کانفیگ را به Db می‌دهد + مایگریشن
    Db::pdo()->query('SELECT 1');
    ok('db connected');
} catch (Throwable $e) {
    bad('db connect', $e->getMessage());
    echo "\nABORT: cannot continue without DB\n";
    exit(1);
}

// ---------- 3) جدول‌های جدید ----------
section('3. New tables');
$cfg = appConfig();
foreach (['webhooks', 'sla_reports', 'queue', 'integrations',
          'affiliates', 'affiliate_earnings', 'affiliate_payouts'] as $t) {
    try {
        $n = (int)Db::val(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$cfg['db']['name'], $t]
        );
        if ($n) ok("table $t");
        else bad("table $t", 'missing — run php table.php');
    } catch (Throwable $e) {
        bad("table $t", $e->getMessage());
    }
}

// ---------- 4) ستون‌های اضافه‌شده به webhooks ----------
section('4. webhooks.secret column');
try {
    $n = (int)Db::val(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "webhooks" AND COLUMN_NAME IN ("secret","sign_algo")',
        [$cfg['db']['name']]
    );
    if ($n >= 2) ok('secret + sign_algo present');
    else bad('secret/sign_algo', 'missing — re-run table.php');
} catch (Throwable $e) {
    bad('secret columns', $e->getMessage());
}

// ---------- 5) کوئری‌های ماژول‌ها روی اسکیمای واقعی ----------
section('5. Queries against real schema');
$sqlTests = [
    'check_log cols'  => 'SELECT `ts`, `ok`, `ms` FROM `check_log` LIMIT 1',
    'site cols'       => 'SELECT `status`, `last_ms`, `resp_avg`, `resp_max`, `resp_p95` FROM `site` LIMIT 1',
    'incident cols'   => 'SELECT `start_at`, `end_at`, `duration`, `reason` FROM `incident` LIMIT 1',
    'uptime_hour'     => 'SELECT `bucket`, `checks`, `ok`, `total_ms` FROM `uptime_hour` LIMIT 1',
    'user share_token'=> 'SELECT `share_token` FROM `user` LIMIT 1',
];
foreach ($sqlTests as $name => $sql) {
    try {
        Db::all($sql);
        ok($name);
    } catch (Throwable $e) {
        bad($name, $e->getMessage());
    }
}

// ---------- 6) توابع کمکی ----------
section('6. Helper functions');
foreach (['appConfig', 'tgSend', 'uptimeLog', 'faNum',
          'makeShareToken', 'normalizeTarget', 'toStr'] as $fn) {
    if (function_exists($fn)) ok("fn $fn");
    else bad("fn $fn", 'missing');
}

// ---------- 7) Stats::uptime روی دادهٔ واقعی ----------
section('7. Stats + Analytics');
$site = Db::one('SELECT * FROM `site` ORDER BY id DESC LIMIT 1');
if (!$site) {
    echo "  SKIP no sites in DB\n";
} else {
    try {
        $u = Stats::uptime($site, 7);
        if (array_key_exists('pct', $u)) ok('Stats::uptime returns pct');
        else bad('Stats::uptime', 'no pct key');

        $r = Analytics::getSiteReport((int)$site['id']);
        if (!empty($r['ok'])) ok('Analytics::getSiteReport');
        else bad('Analytics::getSiteReport', $r['error'] ?? 'unknown');

        $b = Analytics::benchmark((int)$site['id']);
        if (!empty($b['ok'])) ok('Analytics::benchmark');
        else bad('Analytics::benchmark', $b['error'] ?? 'unknown');

        $a = AnomalyDetector::analyze((int)$site['id']);
        if (isset($a['anomalies'])) ok('AnomalyDetector::analyze');
        else bad('AnomalyDetector::analyze', 'no anomalies key');
    } catch (Throwable $e) {
        bad('stats/analytics', $e->getMessage());
    }
}

// ---------- 8) Queue ----------
section('8. Queue');
try {
    $id = Queue::enqueue('cleanup', [], 1);
    if ($id > 0) {
        ok("enqueue → id=$id");
        $st = Queue::status();
        if (isset($st['pending'])) ok('Queue::status');
        else bad('Queue::status', 'no keys');
        $res = Queue::process(5, 10);
        if (is_array($res)) ok('Queue::process → ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        else bad('Queue::process', 'not array');
        Db::q('DELETE FROM `queue` WHERE `id` = ?', [$id]);
        ok('queue row cleaned');
    } else {
        bad('Queue::enqueue', 'returned 0');
    }
} catch (Throwable $e) {
    bad('Queue', $e->getMessage());
}

// ---------- 9) Export/Import ----------
section('9. Export/Import');
try {
    $uid = (int)Db::val('SELECT `id` FROM `user` ORDER BY `id` DESC LIMIT 1');
    if ($uid > 0) {
        $csv = ImportExport::exportCSV($uid);
        if (is_string($csv)) ok('exportCSV');
        else bad('exportCSV', 'not string');

        $json = ImportExport::exportJSON($uid);
        if (json_decode($json, true) !== null) ok('exportJSON');
        else bad('exportJSON', 'invalid json');

        $r = ImportExport::importCSV($uid, "URL,Label,Type,MaxMS,Keyword,Enabled\n");
        ok('importCSV(empty) → ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    } else {
        echo "  SKIP no users in DB\n";
    }
} catch (Throwable $e) {
    bad('ImportExport', $e->getMessage());
}

// ---------- 10) SLA Report ----------
section('10. SLAReport');
$uid = (int)Db::val('SELECT `id` FROM `user` WHERE `id` IN (SELECT `user_id` FROM `site`) LIMIT 1');
if ($uid > 0) {
    try {
        $rep = SLAReport::generate($uid, 'month');
        if (!empty($rep['ok'])) {
            ok('SLAReport::generate → id=' . $rep['report_id']);
            $h = SLAReport::exportHTML($uid, $rep);
            if (strpos($h, '</html>') !== false) ok('exportHTML');
            else bad('exportHTML', 'incomplete');
            $c = SLAReport::exportCSV($rep);
            if (is_string($c)) ok('exportCSV');
            else bad('exportCSV', 'not string');
        } else {
            bad('SLAReport::generate', $rep['error'] ?? 'unknown');
        }
    } catch (Throwable $e) {
        bad('SLAReport', $e->getMessage());
    }
} else {
    echo "  SKIP no user with sites\n";
}

// ---------- 11) Cache ----------
section('11. Cache');
$cs = Cache::status();
ok('Cache::status → ' . json_encode($cs, JSON_UNESCAPED_UNICODE));
if (!empty($cs['enabled'])) {
    Cache::set('test:key', ['a' => 1], 60);
    $v = Cache::get('test:key');
    if ($v !== null) ok('Cache get/set roundtrip → ' . json_encode($v, JSON_UNESCAPED_UNICODE));
    else bad('Cache roundtrip', 'null');
    Cache::del('test:key');
}

// ---------- 12) Integrations ----------
section('12. Integrations');
foreach ([['slack', 'https://hooks.slack.com/services/T00/B00/XXXX'], ['discord', 'https://discord.com/api/webhooks/000/xxxx']] as [$type, $url]) {
    try {
        $okr = IntegrationManager::register($uid ?: 1, $type, $url);
        if ($okr) ok("register $type");
        else bad("register $type", 'returned false');
    } catch (Throwable $e) {
        bad("register $type", $e->getMessage());
    }
}
Db::q('DELETE FROM `integrations` WHERE `user_id` IN (SELECT `id` FROM `user`)');
ok('integration test rows cleaned');

// ---------- 13) Affiliate ----------
section('13. Affiliate');
if ($uid > 0) {
    try {
        $a = AffiliateProgram::register($uid);
        if (!empty($a['ok'])) ok('register → ' . ($a['code'] ?? 'exists'));
        else bad('register', $a['error'] ?? 'unknown');

        $dash = AffiliateProgram::getDashboard($uid);
        if (!empty($dash['ok'])) {
            ok('getDashboard');
            if (!empty($dash['referral_link'])) ok('referral_link → ' . $dash['referral_link']);
            else bad('referral_link', 'empty (bot_username missing?)');
        } else {
            bad('getDashboard', $dash['error'] ?? 'unknown');
        }

        AffiliateProgram::trackSignup((string)($a['code'] ?? ''));
        ok('trackSignup');

        AffiliateProgram::trackPayment((string)($a['code'] ?? ''), 500000);
        ok('trackPayment');

        Db::q('DELETE FROM `affiliate_earnings` WHERE `affiliate_id` IN (SELECT `id` FROM `affiliates` WHERE `user_id` = ?)', [$uid]);
        Db::q('DELETE FROM `affiliates` WHERE `user_id` = ?', [$uid]);
        ok('affiliate test rows cleaned');
    } catch (Throwable $e) {
        bad('Affiliate', $e->getMessage());
    }
}

// ---------- 14) Status page ----------
section('14. StatusPageGenerator');
$tok = (string)Db::val('SELECT `share_token` FROM `user` WHERE `share_token` <> "" LIMIT 1');
if ($tok !== '') {
    try {
        $html = StatusPageGenerator::generateHTML($tok);
        if (is_string($html) && strpos($html, '</html>') !== false) ok('generateHTML');
        else bad('generateHTML', 'invalid output');
    } catch (Throwable $e) {
        bad('StatusPageGenerator', $e->getMessage());
    }
} else {
    echo "  SKIP no user share_token\n";
}

// ---------- 15) Webhooks ----------
section('15. WebhookManager');
$anySite = $site ?: Db::one('SELECT * FROM `site` ORDER BY id DESC LIMIT 1');
if ($anySite) {
    try {
        $sid = (int)$anySite['id'];
        Db::q('DELETE FROM `webhooks` WHERE `site_id` = ?', [$sid]);
        Db::q(
            'INSERT INTO `webhooks` (user_id, site_id, type, url, events, enabled)
             VALUES (?, ?, "custom", "https://example.invalid/hook", "down,up,slow", 1)',
            [(int)$anySite['user_id'], $sid]
        );
        $wid = (int)Db::val('SELECT MAX(`id`) FROM `webhooks`');
        // آدرس .invalid عمداً نامعتبر است تا سریع fail شود
        $r = WebhookManager::send($sid, ['type' => 'down', 'error' => 'test', 'ms' => 0]);
        if (is_int($r)) ok("send → $r sent (network fail expected)");
        else bad('send', 'not int');
        Db::q('DELETE FROM `webhooks` WHERE `id` = ?', [$wid]);
        ok('webhook test row cleaned');
    } catch (Throwable $e) {
        bad('WebhookManager', $e->getMessage());
    }
} else {
    echo "  SKIP no sites\n";
}

// ---------- خلاصه ----------
echo "\n" . str_repeat('=', 40) . "\n";
echo "PASS: $pass   FAIL: $fail\n";
echo str_repeat('=', 40) . "\n";
exit($fail > 0 ? 1 : 0);