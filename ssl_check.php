<?php
/**
 * SSL Certificate Checker Service
 * Checks SSL certificate expiration and sends warnings 3 days before expiry
 * 
 * Usage: php ssl_check.php [--once] [--daemon] [--json] [--quiet]
 * 
 * This service integrates with the uptime monitoring bot:
 * - Checks all users' sites for SSL certificate status
 * - Sends warnings to users when certificates expire within 3 days
 * - Tracks warnings in the database to prevent duplicates
 * - Runs as a cron job or daemon
 */

// ---------- گارد دسترسی ----------
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    // Allow execution via CLI only; direct HTTP access is restricted
    $scriptPath = __FILE__;
    $upTableScript = isset($_SERVER['SCRIPT_FILENAME']) ? @realpath($_SERVER['SCRIPT_FILENAME']) : false;
    if ($upTableScript !== false && $upTableScript === @realpath(__FILE__)) {
        // If accessed directly via HTTP, require secret (same as table.php pattern)
        $upTableCfgRaw = is_readable(__DIR__ . '/config.php') ? (string)@file_get_contents(__DIR__ . '/config.php') : '';
        $upTableToken = '';
        if (preg_match('/[\'"]bot_token[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $upTableCfgRaw, $upTableM)) {
            $upTableToken = (string)$upTableM[1];
        }
        $upTableSecret = ($upTableToken !== '' && $upTableToken !== '{BOT_TOKEN}')
            ? hash('sha256', $upTableToken . '_uptime_table_secret')
            : '';
        $upTableProvided = isset($_GET['secret']) && is_string($_GET['secret']) ? $_GET['secret'] : '';
        if ($upTableSecret === '' || $upTableProvided === '' || !hash_equals($upTableSecret, $upTableProvided)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

require_once __DIR__ . '/lib/bootstrap.php';

@set_time_limit(0);
@ini_set('memory_limit', '256M');
ignore_user_abort(true);

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');

$argv = ($isCli && isset($_SERVER['argv'])) ? array_slice($_SERVER['argv'], 1) : [];
$optOnce    = false;
$optDaemon  = false;
$optJson    = false;
$optQuiet   = false;

foreach ($argv as $a) {
    if ($a === '--once')          $optOnce = true;
    elseif ($a === '--daemon')    $optDaemon = true;
    elseif ($a === '--json')      $optJson = true;
    elseif ($a === '--quiet' || $a === '-q') $optQuiet = true;
}

// ---------- قفل کل اجرا ----------
$lockDir = UPTIME_ROOT . '/logs';
if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
$lockFh = @fopen($lockDir . '/ssl_checker.lock', 'c');
if ($lockFh === false) $lockFh = null;
if ($lockFh !== null && !@flock($lockFh, LOCK_EX | LOCK_NB)) {
    @fclose($lockFh);
    $lockFh = null;
}
if ($lockFh !== null) {
    @ftruncate($lockFh, 0);
    @fwrite($lockFh, getmypid() . '|' . date('Y-m-d H:i:s'));
    @fflush($lockFh);
    register_shutdown_function(static function () use ($lockFh): void {
        if (is_resource($lockFh)) {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
        }
    });
}

// ---------- پیام emitting ----------
$emit = static function (string $text) use ($optQuiet, $optJson): void {
    if ($optQuiet || $optJson) return;
    echo $text . "\n";
};

// ---------- Functions ----------
if (!function_exists('checkSslCertificate')) {
    /**
     * Check SSL certificate for a given host:port
     * 
     * @param string $host Domain or IP address
     * @param int $port Port (default 443)
     * @param int $timeout Connection timeout in seconds
     * @return array ['ok' => bool, 'days_remaining' => int, 'not_before' => string, 'not_after' => string, 'subject' => string, 'issuer' => string, 'error' => string]
     */
    function checkSslCertificate(string $host, int $port = 443, int $timeout = 10): array
    {
        $result = [
            'ok' => false,
            'days_remaining' => 0,
            'not_before' => '',
            'not_after' => '',
            'subject' => '',
            'issuer' => '',
            'error' => ''
        ];
        
        $errno = 0;
        $error = '';
        
        // Try to connect via SSL stream
        $uri = "ssl://{$host}:{$port}";
        $context = stream_context_create([
            'ssl' => [
                'allow_self_signed' => false,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'session_timeout' => 5,
            ],
            'tcp' => [
                'flush_all' => true,
            ]
        ]);
        
        $connection = @stream_socket_client($uri, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        
        if (!$connection) {
            $result['error'] = "Could not connect to {$host}:{$port} - {$error} (errno: {$errno})";
            return $result;
        }
        
        // Get the peer certificate
        $cert = @stream_socket_get_meta_data($connection, true);
        
        if ($cert && isset($cert['stream']) && isset($cert['stream']['crypto']) && isset($cert['stream']['crypto']['peer_certificate'])) {
            $x509 = $cert['stream']['crypto']['peer_certificate'];
            
            // Parse the certificate
            $x509Parsed = @openssl_x509_parse($x509, true);
            
            if ($x509Parsed !== false) {
                $result['ok'] = true;
                
                // Subject
                if (isset($x509Parsed['subject'])) {
                    $subjectParts = [];
                    foreach ($x509Parsed['subject'] as $key => $value) {
                        $subjectParts[] = "{$key}={$value}";
                    }
                    $result['subject'] = implode(', ', $subjectParts);
                }
                
                // Issuer
                if (isset($x509Parsed['issuer'])) {
                    $issuerParts = [];
                    foreach ($x509Parsed['issuer'] as $key => $value) {
                        $issuerParts[] = "{$key}={$value}";
                    }
                    $result['issuer'] = implode(', ', $issuerParts);
                }
                
                // Validity dates
                if (isset($x509Parsed['validFrom_time_t'])) {
                    $result['not_before'] = date('Y-m-d H:i:s', $x509Parsed['validFrom_time_t']);
                }
                
                if (isset($x509Parsed['validTo_time_t'])) {
                    $result['not_after'] = date('Y-m-d H:i:s', $x509Parsed['validTo_time_t']);
                }
                
                // Calculate days remaining
                $now = time();
                $notAfterTimestamp = strtotime($result['not_after']);
                
                if ($notAfterTimestamp !== false) {
                    $daysRemaining = floor(($notAfterTimestamp - $now) / 86400);
                    $result['days_remaining'] = max(0, $daysRemaining);
                }
            } else {
                $result['error'] = "Failed to parse SSL certificate from {$host}";
            }
        } else {
            $result['error'] = "No certificate available from {$host}";
        }
        
        // Close connection
        @fclose($connection);
        
        return $result;
    }
}

// ---------- Main Logic ----------
$emit('SSL Certificate Checker Starting...');

try {
    appBoot();
} catch (Throwable $e) {
    $emit('❌ Database boot failed: ' . $e->getMessage());
    if ($optJson) echo json_encode(['ok' => false, 'error' => 'database: ' . $e->getMessage()]);
    exit(1);
}

// Get all users with their sites (active, not blocked, not paused)
$users = Db::all('SELECT u.id, u.username, u.name, u.is_admin, u.access, u.plan, u.plan_until, u.paused FROM `user` u WHERE u.access = 1 AND u.is_blocked = 0');

$totalChecked = 0;
$totalWarning = 0;
$totalExpired = 0;
$results = [];

foreach ($users as $user) {
    $uid = (int)$user['id'];
    
    // Skip paused users (except admin)
    if ((int)$user['paused'] === 1 && !$user['is_admin']) {
        $emit("⏸ User {$uid} has paused checks, skipping SSL check");
        continue;
    }
    
    // Get user's sites
    $sites = Db::all('SELECT * FROM `site` WHERE `user_id` = ? ORDER BY `id` ASC', [$uid]);
    
    if (empty($sites)) {
        continue;
    }
    
    $userWarnings = 0;
    $userExpired = 0;
    
    foreach ($sites as $site) {
        $target = (string)$site['target'];
        $siteId = (int)$site['id'];
        
        // Parse host from target
        $host = $target;
        $port = 443;
        
        // Handle different target formats
        if (preg_match('#^https?://#i', $target)) {
            $p = parse_url($target);
            if ($p && $p['host']) {
                $host = $p['host'];
                $port = ($p['port'] ?? 443);
                if ($p['scheme'] === 'http') {
                    // For HTTP, we can't check SSL, skip
                    $emit("⏭ Skipping {$target} - HTTP scheme, not SSL");
                    continue; // Skip HTTP for SSL check
                }
            }
        } elseif (preg_match('/^\[([^\]]+)\](?::(\d{1,5}))?$/', $target, $m)) {
            $host = $m[1];
            $port = ($m[2] ?? 443);
        } elseif (substr_count($target, ':') === 1) {
            [$host, $p2] = explode(':', $target, 2);
            $port = ($p2 ?? 443);
        }
        
        // Check SSL certificate
        $certInfo = checkSslCertificate($host, (int)$port);
        $totalChecked++;
        
        if ($certInfo['ok']) {
            $daysRemaining = $certInfo['days_remaining'];
            $label = $site['label'] ?: $host;
            
            // Check if certificate expires within 3 days (but not already expired)
            if ($daysRemaining > 0 && $daysRemaining <= 3) {
                // Check if warning already sent today
                $today = date('Y-m-d');
                $existing = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = 'ssl_warning_{$siteId}_{$today}'");
                
                if ((int)$existing === 0) {
                    // Send warning to user
                    $warningText = "⚠️ <b>هشدار ssl Certificate</b>\n\n"
                        . "🔗 سایت: <code>{$label}</code> ({$host})\n"
                        . "⏰ تاریخ انقضا: {$certInfo['not_after']}\n"
                        . "📅 روزهای باقی‌مانده: {$daysRemaining}\n"
                        . "⚠️ هشدار داده شده: ۳ روز قبل انقضا\n\n"
                        . "لطفاً ssl Certificate را تمدید کنید تا مانیتورینگ قطع نشود.";
                    
                    try {
                        tgSend($uid, $warningText);
                        $userWarnings++;
                        $totalWarning++;
                    } catch (Throwable $e) {
                        uptimeLog('error', "SSL warning send failed for user {$uid}: " . $e->getMessage());
                    }
                    
                    // Store in database that warning was sent today
                    Db::q(
                        'INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)',
                        ['ssl_warning_' . $siteId . '_' . $today, 'sent']
                    );
                }
            }
            
            // Also check if certificate is already expired
            if ($daysRemaining <= 0) {
                $expiredText = "🔴 <b>ssl Certificate منقضی شده</b>\n\n"
                    . "🔗 سایت: <code>{$label}</code> ({$host})\n"
                    . "📅 تاریخ انقضا: {$certInfo['not_after']}\n"
                    . "❌ وضعیت: منقضی شده\n\n"
                    . "ssl Certificate این سایت منقضی شده است و حتماً باید تمدید شود.";
                
                try {
                    tgSend($uid, $expiredText);
                    $userExpired++;
                    $totalExpired++;
                } catch (Throwable $e) {
                    uptimeLog('error', "SSL expired send failed for user {$uid}: " . $e->getMessage());
                }
                
                // Store in database that expiry was notified
                $today = date('Y-m-d');
                Db::q(
                    'INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)',
                    ['ssl_expired_{$siteId}_{$today}', 'notified']
                );
            }
            
            $emit("✅ {$host}:{$port} - Valid until {$certInfo['not_after']} ({$daysRemaining} days remaining)");
        } else {
            $emit("⚠️ Could not check SSL for {$host}:{$port} - {$certInfo['error']}");
        }
    }
    
    $emit("✅ User {$uid} ({$user['username'] ?? 'no username'}) checked: {$sites->count} sites, warnings:{$userWarnings}, expired:{$userExpired}");
}

// ---------- Summary ----------
$summary = "📊 <b>SSL Check Summary</b>\n"
    . "𔂿 کل سایت‌های چک شده: {$totalChecked}\n"
    . "⚠️ هشدار ۳ روز قبل ارسال شده: {$totalWarning}\n"
    . "🔴 Certificate منقضی شده: {$totalExpired}\n"
    . "⏰ زمان انجام: " . date('Y-m-d H:i:s');

if (!$optQuiet) {
    echo $summary . "\n";
}

if ($optJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'total_checked' => $totalChecked,
        'total_warnings' => $totalWarning,
        'total_expired' => $totalExpired,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}

// Remove lock on exit
if (is_resource($lockFh)) {
    @flock($lockFh, LOCK_UN);
    @fclose($lockFh);
}

exit(0);