<?php
<?php
/**
 * ===== چک سلامت سیستم (System Health Check) =====
 * اجرای: php tools/health.php [--json] [--full]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('UPTIME_ROOT', dirname(__DIR__));
require_once UPTIME_ROOT . '/lib/bootstrap.php';

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
    global $results;
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

// 1. PHP Extensions
check('PHP Version', version_compare(PHP_VERSION, '8.1', '>='), 'PHP ' . PHP_VERSION);
check('curl', extension_loaded('curl'));
check('pdo', extension_loaded('pdo'));
check('pdo_mysql', extension_loaded('pdo_mysql'));
check('openssl', extension_loaded('openssl'));
check('mbstring', extension_loaded('mbstring'));
check('json', extension_loaded('json'));
check('proc_open', function_exists('proc_open'), function_exists('proc_open') ? 'Available (for ping)' : 'Not available - ping will fallback to TCP');
check('stream_socket_client', function_exists('stream_socket_client'));

// 2. Files & Permissions
check('config.php exists', file_exists(UPTIME_ROOT . '/config.php'));
check('logs directory', is_dir(UPTIME_ROOT . '/logs'), is_dir(UPTIME_ROOT . '/logs') ? 'Exists' : 'Missing - will be created');
if (is_dir(UPTIME_ROOT . '/logs')) {
    check('logs writable', is_writable(UPTIME_ROOT . '/logs'));
}

// 3. Database
try {
    appBoot(false);
    $pdo = Db::pdo();
    check('DB Connection', true, 'Connected');
    
    // Check tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['user', 'site', 'check_log', 'uptime_hour', 'incident', 'settings'];
    foreach ($required as $t) {
        check("Table $t", in_array($t, $tables, true));
    }
} catch (Throwable $e) {
    check('DB Connection', false, $e->getMessage());
}

// 4. Cron Status
try {
    $lastRound = Db::get('last_round_at');
    $lastMaint = Db::get('last_maint');
    $interval = max(10, Db::getInt('check_interval', 20));
    
    if ($lastRound) {
        $diff = time() - strtotime($lastRound);
        $ok = $diff < ($interval * 3 + 60); // Allow 3 intervals + 1 min grace
        check('Cron - Last round', $ok, 
            $ok ? sprintf('%.0f seconds ago', $diff) : sprintf('STALE (%.0f seconds ago)', $diff),
            ['last_round' => $lastRound, 'diff_sec' => $diff, 'interval' => $interval]
        );
    } else {
        check('Cron - Last round', false, 'Never run');
    }
    
    if ($lastMaint) {
        $diff = time() - strtotime($lastMaint);
        check('Maintenance - Last run', true, sprintf('%.0f minutes ago', $diff/60));
    }
} catch (Throwable $e) {
    check('Cron Status', false, $e->getMessage());
}

// 5. Settings
try {
    $pauseAll = Db::getBool('pause_all', false);
    check('pause_all', !$pauseAll, $pauseAll ? 'PAUSED - checks disabled' : 'Active');
    check('check_interval', true, Db::getInt('check_interval', 20) . 's');
} catch (Throwable $e) {
    // ignore
}

// 6. System Resources
$free = @disk_free_space(UPTIME_ROOT);
$total = @disk_total_space(UPTIME_ROOT);
if ($free !== false && $total !== false) {
    $pct = round(($free / $total) * 100, 2);
    check('Disk space', $pct > 10, sprintf('%.1f%% free (%.2f GB)', $pct, $free / 1073741824), [
        'free_bytes' => $free,
        'total_bytes' => $total,
        'pct_free' => $pct,
    ]);
}

check('Memory usage', true, sprintf('%.2f MB / peak %.2f MB', 
    memory_get_usage(true)/1048576, 
    memory_get_peak_usage(true)/1048576
));

// Summary
$results['summary'] = [
    'total' => count($results['checks']),
    'passed' => count(array_filter($results['checks'], fn($c) => $c['status'] === 'ok')),
    'failed' => count(array_filter($results['checks'], fn($c) => $c['status'] === 'fail')),
];

if ($json) {
    header('Content-Type: application/json');
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "=== System Health Check ===\n";
    echo "Status: " . ($results['status'] === 'ok' ? '✓ OK' : '✗ FAIL') . "\n";
    echo sprintf("Passed: %d / %d\n\n", $results['summary']['passed'], $results['summary']['total']);
    
    foreach ($results['checks'] as $c) {
        $icon = $c['status'] === 'ok' ? '✓' : '✗';
        echo sprintf("[%s] %s: %s\n", $icon, $c['name'], $c['message'] ?: $c['status']);
        if ($full && isset($c['details'])) {
            echo "  " . json_encode($c['details'], JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    echo "\n=== End ===\n";
}

exit($results['status'] === 'ok' ? 0 : 1);
