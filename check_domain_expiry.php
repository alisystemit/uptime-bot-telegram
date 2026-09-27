<?php
/**
 * Domain Expiry Checker
 * Checks all sites for upcoming domain expiry and sends warnings
 * Warns 30 days and 7 days before expiry
 */

require_once __DIR__ . '/lib/bootstrap.php';

echo "=== Domain Expiry Checker Starting ===\n\n";

try {
    appBoot();
    
    $emit = function(string $text) { echo $text . "\n"; };
    
    // Get configuration
    $sslWarnDays = Db::getInt('ssl_warn_days', 3); // Default 3 days for SSL
    $domainWarn30Days = Db::getInt('domain_warn_30d', '0'); // Will be checked
    $domainWarn7Days = Db::getInt('domain_warn_7d', '0');
    
    $emit "⚙️ تنظیمات هشدار:\n";
    $emit "   - هشدار SSL: {$sslWarnDays} روز قبل\n";
    $emit "   - هشدار_domain ۳۰ روز: " . ($domainWarn30Days ? ' ارسال شده' : 'وهمه') . "\n";
    $emit "   - هشدار_domain ۷ روز: " . ($domainWarn7Days ? ' ارسال شده' : 'وهمه') . "\n\n";
    
    // Get all active users
    $users = Db::all('SELECT u.id, u.username, u.name, u.is_admin, u.access, u.paused FROM `user` u WHERE u.access = 1 AND u.is_blocked = 0');
    
    $totalSites = 0;
    $totalDomainWarnings = 0;
    $totalSslWarnings = 0;
    
    foreach ($users as $user) {
        $uid = (int)$user['id'];
        
        // Skip paused users
        if ((int)$user['paused'] === 1 && !$user['is_admin']) {
            continue;
        }
        
        // Get user's sites
        $sites = Db::all('SELECT * FROM `site` WHERE `user_id` = ? AND `paused` = 0', [$uid]);
        
        foreach ($sites as $site) {
            $totalSites++;
            $target = (string)$site['target'];
            $label = $site['label'] ?: $target;
            
            // Check domain expiry
            $domainExpiry = $site['domain_expiry'] ?? null;
            
            if ($domainExpiry) {
                $expiryTimestamp = strtotime($domainExpiry);
                $now = time();
                $daysUntilExpiry = floor(($expiryTimestamp - $now) / 86400);
                
                if ($daysUntilExpiry > 0) {
                    // Check 30-day warning
                    if ($daysUntilExpiry <= 30 && $daysUntilExpiry > 0) {
                        $warnKey = "domain_warn_30d_{$site['id']}_{$daysUntilExpiry}";
                        $alreadyWarned = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$warnKey]);
                        
                        if ((int)$alreadyWarned === 0) {
                            // Get days left message
                            $daysText = $daysUntilExpiry === 1 ? '1 روز' : "{$daysUntilExpiry} روز";
                            
                            $warningText = "🔔 <b>هشدار انقضای_domain</b>\n\n"
                                . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                                . "📅 تاریخ انقضا: {$domainExpiry}\n"
                                . "⏰ روزهای باقی‌مانده: {$daysText}\n"
                                . "⚠️_domain این سایت {$daysText} دیگر معتبر خواهد بود.\n\n"
                                . "لطفاً_domain را قبل از انقضا تمدید کنید تا مانیتورینگ ادامه یابد.";
                            
                            try {
                                tgSend($uid, $warningText);
                                $totalDomainWarnings++;
                                // Mark as warned
                                Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", [$warnKey, 'sent']);
                                $emit "📢 Domain expiry warning sent to user {$uid} for site {$site['id']}\n";
                            } catch (Throwable $e) {
                                uptimeLog('error', "Domain warning send failed for user {$uid}: " . $e->getMessage());
                            }
                        }
                    }
                    
                    // Check 7-day warning
                    if ($daysUntilExpiry <= 7 && $daysUntilExpiry > 0) {
                        $warnKey = "domain_warn_7d_{$site['id']}_{$daysUntilExpiry}";
                        $alreadyWarned = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$warnKey]);
                        
                        if ((int)$alreadyWarned === 0) {
                            $daysText = $daysUntilExpiry === 1 ? '1 روز' : "{$daysUntilExpiry} روز";
                            
                            $warningText = "🚨 <b>هشدار immédiate_domain</b>\n\n"
                                . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                                . "📅 تاریخ انقضا: {$domainExpiry}\n"
                                . "⏰ روزهای باقی‌مانده: {$daysText}\n"
                                . "⚠️_domain این سایت {$daysText} دیگر معتبر خواهد بود -_ACTION فوری لازم!\n\n"
                                . "-domain_site را azonse تمدید کنید!";
                            
                            try {
                                tgSend($uid, $warningText);
                                $totalDomainWarnings++;
                                Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", [$warnKey, 'sent']);
                                $emit "🚨 Immediate domain expiry warning sent to user {$uid} for site {$site['id']}\n";
                            } catch (Throwable $e) {
                                uptimeLog('error', "Immediate domain warning send failed for user {$uid}: " . $e->getMessage());
                            }
                        }
                    }
                    
                    // Check if already expired
                    if ($daysUntilExpiry <= 0) {
                        $expiredText = "🔴 <b>_domain منقضی شده</b>\n\n"
                            . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                            . "📅 تاریخ انقضا: {$domainExpiry}\n"
                            . "❌ وضعیت:_domain منقضی شده\n\n"
                            . "_domain_site این سایت منقضی شده است و مانیتورینگ دیگر کار نخواهد کرد.\n"
                            . "بسیار سریع_domain را تمدید کنید.";
                        
                        try {
                            tgSend($uid, $expiredText);
                            $totalDomainWarnings++;
                        } catch (Throwable $e) {
                            uptimeLog('error', "Domain expired send failed for user {$uid}: " . $e->getMessage());
                        }
                    }
                }
            }
            
            // Check SSL certificate
            if (preg_match('#^https?://#i', $target)) {
                // Parse to get host
                $p = parse_url($target);
                $host = $p['host'] ?? '';
                
                if ($host) {
                    // Check SSL
                    $errno = 0;
                    $error = '';
                    $connection = @@fsockopen("ssl://{$host}", 443, $errno, $error, 5);
                    
                    if ($connection) {
                        $cert = @stream_socket_get_meta_data($connection, true);
                        @fclose($connection);
                        
                        if ($cert && isset($cert['stream']['crypto']['peer_certificate'])) {
                            $x509 = $cert['stream']['crypto']['peer_certificate'];
                            $x509Parsed = @openssl_x509_parse($x509, true);
                            
                            if ($x509Parsed && isset($x509Parsed['validTo_time_t'])) {
                                $notAfter = $x509Parsed['validTo_time_t'];
                                $daysRemaining = floor(($notAfter - time()) / 86400);
                                
                                if ($daysRemaining > 0 && $daysRemaining <= $sslWarnDays) {
                                    $sslWarnKey = "ssl_warn_{$site['id']}_{$daysRemaining}";
                                    $alreadySslWarned = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$sslWarnKey]);
                                    
                                    if ((int)$alreadySslWarned === 0) {
                                        $label = $site['label'] ?: $host;
                                        $warningText = "⚠️ <b>هشدار SSL Certificate</b>\n\n"
                                            . "🔗 سایت: <code>{$label}</code> ({$host})\n"
                                            . "⏰ تاریخ انقضای SSL: {$domainExpiry ?: 'not set'} {$domainExpiry ? '' : ''}\n"
                                            . "📅 روزهای باقی‌مانده: {$daysRemaining}\n"
                                            . "⚠️ SSL Certificate {$daysRemaining} روز دیگر منقضی خواهد بود.\n\n"
                                            . "لطفاً SSL Certificate را تمدید کنید.";
                                        
                                        try {
                                            tgSend($uid, $warningText);
                                            $totalSslWarnings++;
                                            Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", [$sslWarnKey, 'sent']);
                                            $emit "🔒 SSL warning sent to user {$uid} for site {$site['id']}\n";
                                        } catch (Throwable $e) {
                                            uptimeLog('error', "SSL warning send failed for user {$uid}: " . $e->getMessage());
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Summary
    $emit "\n=== گزارش نهایی ===\n";
    $emit "📊 کل سایت‌های بررسی شده: {$totalSites}\n";
    $emit "⚠️ هشدار_domain ارسال شده: {$totalDomainWarnings}\n";
    $emit "🔒 هشدار SSL ارسال شده: {$totalSslWarnings}\n";
    $emit "⏰ زمان انجام: " . date('Y-m-d H:i:s') . "\n";
    
    echo "\n✅ Domain expiry check completed!\n";
    
} catch (Throwable $e) {
    uptimeLog('error', 'Domain expiry check failed: ' . $e->getMessage());
    echo "❌ Check failed: " . $e->getMessage() . "\n";
}