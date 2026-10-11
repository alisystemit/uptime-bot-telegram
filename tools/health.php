<?php
/**
 * ===== بررسی سلامت ربات (CLI یا وب) =====
 *
 * CLI :  php tools/health.php [--full] [--json]
 * وب  :  https://domain/bots/paishin/tools/health.php?secret=<cron secret>
 *
 * توجه: فقط با secret یا از خط فرمان باز می‌شود.
 */

if (PHP_SAPI !== 'cli') {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
    $cfg = appConfig();
    $expected = hash('sha256', (string)($cfg['bot_token'] ?? '') . '_uptime_webhook_secret');
    $given = (string)($_GET['secret'] ?? ($_SERVER['HTTP_X_SECRET'] ?? ''));
    if ($given === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// نکته: UPTIME_ROOT را اینجا define نکن — وگرنه بلوک if (!defined(...))
// در bootstrap.php همهٔ require ها را رد می‌کند و کلاس Db/Stats/... بارگذاری نمی‌شود.
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$options = getopt('', ['json', 'full']);

$full = isset($options['full']);
$json = isset($options['json']);

$results = [
    'status' => 'ok',
    'checks' => [],
    'summary' => [],
];

function check(string $name, bool $ok, string $message = '', array $details = []): array
{
    global $results, $full;
    $status = $ok ? 'ok' : 'fail';
    if ($status === 'fail') {
        $results['status'] = 'fail';
    }

    $item = [
        'name' => $name,
        'status' => $status,
        'message' => $message,
    ];
    if ($full && $details) {
        $item['details'] = $details;
    }
    $results['checks'][] = $item;
    return $item;
}

// ---------- افزونه‌ها ----------
check('PHP Version', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION);
check('curl', extension_loaded('curl'), extension_loaded('curl') ? 'ok' : 'cURL extension missing');
check('pdo', extension_loaded('pdo'), extension_loaded('pdo') ? 'ok' : 'PDO extension missing');
check('pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'ok' : 'pdo_mysql missing');
check('openssl', extension_loaded('openssl'), extension_loaded('openssl') ? 'ok' : 'openssl missing');
check('mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'ok' : 'mbstring missing');
check('json', function_exists('json_encode'), function_exists('json_encode') ? 'ok' : 'json missing');
check('proc_open', function_exists('proc_open'),
    function_exists('proc_open') ? 'Available (for ping)' : 'unavailable — ICMP ping disabled');
check('stream_socket_client', function_exists('stream_socket_client'),
    function_exists('stream_socket_client') ? 'ok' : 'missing — tcp/udp checks disabled');

// ---------- فایل‌ها ----------
$root = dirname(__DIR__);
check('config.php exists', is_file($root . '/config.php'), 'ok');

$logsDir = $root . '/logs';
if (!is_dir($logsDir)) @mkdir($logsDir, 0755, true);
check('logs directory', is_dir($logsDir), is_dir($logsDir) ? 'Exists' : 'cannot create');
check('logs writable', is_writable($logsDir), is_writable($logsDir) ? 'ok' : 'not writable');

$reportsDir = $root . '/reports';
if (!is_dir($reportsDir)) @mkdir($reportsDir, 0755, true);
check('reports writable', is_dir($reportsDir) && is_writable($reportsDir),
    (is_dir($reportsDir) && is_writable($reportsDir)) ? 'ok' : 'not writable');

// ---------- کانفیگ ----------
$cfg = appConfig();
$unfilled = [];
foreach (['bot_token', 'admin_id', 'bot_username', 'domain', 'base_url'] as $k) {
    $v = (string)($cfg[$k] ?? '');
    if ($v === '' || $v[0] === '{') $unfilled[] = $k;
}
check('config filled', !$unfilled, $unfilled ? 'placeholders left: ' . implode(', ', $unfilled) : 'ok');

$dbCfg = $cfg['db'] ?? [];
$dbUnfilled = [];
foreach (['host', 'name', 'user'] as $k) {
    $v = (string)($dbCfg[$k] ?? '');
    if ($v === '' || $v[0] === '{') $dbUnfilled[] = $k;
}
check('db config filled', !$dbUnfilled, $dbUnfilled ? 'placeholders left: ' . implode(', ', $dbUnfilled) : 'ok');

// ---------- دیتابیس ----------
$dbOk = false;
$dbMsg = '';
try {
    Db::setConfig($cfg);
    Db::pdo()->query('SELECT 1');
    $dbOk = true;
    $dbMsg = 'ok';
} catch (Throwable $e) {
    $dbMsg = $e->getMessage();
}
check('DB Connection', $dbOk, $dbMsg);

if ($dbOk) {
    // جدول‌های پایه
    foreach (['user', 'site', 'check_log', 'settings', 'incident', 'uptime_hour', 'seen_update'] as $t) {
        try {
            $n = (int)Db::val('SELECT COUNT(*) FROM information_schema.TABLES
                                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$dbCfg['name'], $t]);
            check("table: $t", $n > 0, $n > 0 ? 'ok' : 'missing');
        } catch (Throwable $e) {
            check("table: $t", false, $e->getMessage());
        }
    }

    // جدول‌های ویژگی‌های جدید (اختیاری)
    foreach (['webhooks', 'sla_reports', 'queue', 'integrations', 'affiliates'] as $t) {
        try {
            $n = (int)Db::val('SELECT COUNT(*) FROM information_schema.TABLES
                                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$dbCfg['name'], $t]);
            if ($n > 0) $results['checks'][] = ['name' => "feature table: $t", 'status' => 'ok', 'message' => 'ok'];
        } catch (Throwable $e) {
            // بی‌صدا رد شو — ویژگی اختیاری است
        }
    }

    // وضعیت کرون
    try {
        $last = (string)Db::get('last_round_at', '');
        if ($last !== '') {
            $age = time() - strtotime($last);
            check('Cron Status', $age < 300, 'last round ' . round($age) . 's ago',
                ['last_round_at' => $last]);
        } else {
            check('Cron Status', false, 'cron has never run — set up cron/checker.php');
        }
    } catch (Throwable $e) {
        check('Cron Status', false, $e->getMessage());
    }

    // آمار
    try {
        $results['summary'] = [
            'users'   => (int)Db::val('SELECT COUNT(*) FROM `user`'),
            'sites'   => (int)Db::val('SELECT COUNT(*) FROM `site`'),
            'incidents' => (int)Db::val('SELECT COUNT(*) FROM `incident`'),
        ];
    } catch (Throwable $e) {
        // بی‌صدا
    }
}

// ---------- منابع ----------
$free = @disk_free_space($root);
$total = @disk_total_space($root);
if ($free && $total) {
    $pct = round($free / $total * 100, 1);
    check('Disk space', $pct > 5, $pct . '% free (' . round($free / 1073741824, 2) . ' GB)');
}
check('Memory usage', true, round(memory_get_usage() / 1048576, 2) . ' MB / peak '
    . round(memory_get_peak_usage() / 1048576, 2) . ' MB');

// ---------- خروجی ----------
$passed = 0;
$failed = 0;
foreach ($results['checks'] as $c) {
    $c['status'] === 'ok' ? $passed++ : $failed++;
}

if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'   => $results['status'],
        'passed'   => $passed,
        'failed'   => $failed,
        'checks'   => $results['checks'],
        'summary'  => $results['summary'],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit($failed > 0 ? 1 : 0);
}

$icon = fn(string $s): string => $s === 'ok' ? '[✓]' : '[✗]';

echo "=== System Health Check ===\n";
echo 'Status: ' . ($results['status'] === 'ok' ? '✓ OK' : '✗ FAIL') . "\n";
echo "Passed: $passed / " . ($passed + $failed) . "\n\n";

foreach ($results['checks'] as $c) {
    echo $icon($c['status']) . ' ' . $c['name'] . ': ' . $c['message'] . "\n";
    if ($full && isset($c['details'])) {
        foreach ($c['details'] as $k => $v) {
            echo "        $k: " . (is_scalar($v) ? $v : json_encode($v)) . "\n";
        }
    }
}

if (!empty($results['summary'])) {
    echo "\n--- summary ---\n";
    foreach ($results['summary'] as $k => $v) {
        echo "  $k: $v\n";
    }
}

echo "\n=== End ===\n";
exit($failed > 0 ? 1 : 0);