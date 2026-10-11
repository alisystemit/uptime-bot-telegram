<?php
/**
 * ===== تست بارگذاری بدون دیتابیس =====
 * هدف: اطمینان از نبود fatal error/parse error در ماژول‌های جدید
 * اجرا: php tools/test_load.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__) . '/lib/bootstrap.php';

echo "✅ bootstrap loaded (no fatal)\n";

$pass = 0;
$fail = 0;

function check(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $msg\n"; }
    else       { $fail++; echo "  FAIL $msg\n"; }
}

// ---------- کلاس‌ها ----------
echo "\n--- classes ---\n";
foreach ([
    'Cache', 'WebhookManager', 'SLAReport', 'Queue',
    'AnomalyDetector', 'SlackIntegration', 'DiscordIntegration',
    'IntegrationManager', 'ImportExport', 'Analytics',
    'StatusPageGenerator', 'AffiliateProgram',
] as $c) {
    check(class_exists($c), "class $c");
}

// ---------- توابع کمکی ----------
echo "\n--- helper functions ---\n";
foreach (['appConfig', 'tgSend', 'uptimeLog', 'faNum',
          'makeShareToken', 'normalizeTarget', 'toStr', 'botUsername'] as $fn) {
    check(function_exists($fn), "fn $fn");
}

// ---------- کانفیگ ----------
echo "\n--- config sanity ---\n";
$cfg = appConfig();
$placeholders = ['{BOT_TOKEN}', '{ADMIN_#ID}', '{BOT_USERNAME}',
                 '{DOMAIN.COM/PATH/BOT}', '{BASE_URL}',
                 '{DB_HOST}', '{DB_PORT}', '{DATABASE_NAME}',
                 '{DATABASE_USERNAME}', '{DATABASE_PASSWORD}'];
foreach ($placeholders as $ph) {
    check(!str_contains(json_encode($cfg, JSON_UNESCAPED_UNICODE), $ph), "no placeholder $ph");
}
check(($cfg['bot_token'] ?? '') !== '', 'bot_token set');
check(preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', (string)($cfg['bot_token'] ?? '')) === 1, 'bot_token format valid');
check(ctype_digit((string)($cfg['admin_id'] ?? '')), 'admin_id numeric');
check(!str_contains((string)($cfg['domain'] ?? ''), '{'), 'domain filled');
check(str_starts_with((string)($cfg['base_url'] ?? ''), 'http'), 'base_url absolute');

// ---------- توابع خالص (بدون DB) ----------
echo "\n--- pure functions ---\n";
$t = normalizeTarget('https://example.com/path');
check(!empty($t['ok']), 'normalizeTarget https → ok');
check(($t['host'] ?? '') === 'example.com', 'normalizeTarget host');

$t2 = normalizeTarget('example.com:8080');
check(!empty($t2['ok']), 'normalizeTarget host:port → ok');
check((int)($t2['port'] ?? 0) === 8080, 'normalizeTarget port');

$t3 = normalizeTarget('http://1.1.1.1');
check(!empty($t3['ok']), 'normalizeTarget IP → ok');

$t4 = normalizeTarget('not a url at all!!');
check(empty($t4['ok']), 'normalizeTarget garbage → rejected');

$t5 = normalizeTarget('');
check(empty($t5['ok']), 'normalizeTarget empty → rejected');

check(strlen(makeShareToken(20)) === 20, 'makeShareToken length');
check(makeShareToken(20) !== makeShareToken(20), 'makeShareToken unique');

check(faNum(1234567) !== '', 'faNum works');

// Cache (بدون Redis باید graceful باشد)
echo "\n--- cache fallback ---\n";
$cs = Cache::status();
check(is_array($cs), 'Cache::status returns array');
check(isset($cs['enabled']), 'Cache::status has enabled key');
check(Cache::get('no-such-key', 'fallback') === 'fallback' || Cache::status()['enabled'], 'Cache::get default honored');

// ---------- static analysis: فراخوانی متدهای گم‌شده ----------
echo "\n--- cross-module method existence ---\n";
$expect = [
    ['SLAReport', ['generate', 'exportCSV', 'exportHTML', 'sendMonthly']],
    ['Queue', ['enqueue', 'process', 'status', 'count', 'cleanup']],
    ['Analytics', ['getSiteReport', 'compareUserSites', 'benchmark']],
    ['AnomalyDetector', ['analyze', 'analyzeUser', 'reportToUser']],
    ['ImportExport', ['exportCSV', 'exportJSON', 'importCSV', 'importJSON', 'backupUser']],
    ['WebhookManager', ['send', 'test', 'cleanupFailed']],
    ['IntegrationManager', ['register', 'test', 'disable']],
    ['AffiliateProgram', ['register', 'trackSignup', 'trackPayment', 'getDashboard', 'requestPayout', 'completePayout', 'getLeaderboard']],
    ['StatusPageGenerator', ['generateHTML']],
    ['Cache', ['init', 'get', 'set', 'del', 'delPattern', 'status']],
];
foreach ($expect as [$class, $methods]) {
    foreach ($methods as $m) {
        check(method_exists($class, $m), "$class::$m");
    }
}

// ---------- methods of Stats/Monitor used by new code ----------
echo "\n--- existing APIs used by new modules ---\n";
check(method_exists('Stats', 'uptime'), 'Stats::uptime');
check(method_exists('Monitor', 'checkSite'), 'Monitor::checkSite');
check(method_exists('PayHttp', 'call'), 'PayHttp::call');
foreach (['q','all','one','val','get','logEvent','migrate'] as $m) {
    check(method_exists('Db', $m), "Db::$m");
}

echo "\n" . str_repeat('=', 40) . "\n";
echo "PASS: $pass   FAIL: $fail\n";
echo str_repeat('=', 40) . "\n";
exit($fail > 0 ? 1 : 0);