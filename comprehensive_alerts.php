<?php
/**
 * Comprehensive Alert System
 * Centralized alert/warning system for all important events
 * Integrates with existing monitoring, SSL checks, and domain tracking
 */

require_once __DIR__ . '/lib/bootstrap.php';

echo "=== Comprehensive Alert System ===\n\n";

try {
    appBoot();
    
    $emit = function(string $text) { echo $text . "\n"; };
    
    // Get alert configuration
    $notify = Db::getBool('notify', true); // Global notification setting
    $adminIds = botAdminIds();
    
    $emit "⚙️ تنظیمات هشدار centralized:\n";
    $emit "   - اطلاع‌رسانی_global: " . ($notify ? '🔔 فعال' : '🔕 خاموش') . "\n";
    $emit "   - ایدل_admin IDs: " . count($adminIds) . " نفر\n\n";
    
    // Get all active users
    $users = Db::all('SELECT u.id, u.username, u.name, u.is_admin, u.access, u.paused, u.notify FROM `user` u WHERE u.access = 1 AND u.is_blocked = 0');
    
    $totalAlerts = 0;
    $alertsByType = [];
    
    foreach ($users as $user) {
        $uid = (int)$user['id'];
        $userNotify = (int)($user['notify'] ?? 1);
        
        // Skip if user has disabled notifications (except admins)
        if (!$user['is_admin'] && (int)$userNotify === 0) {
            $emit "⏸ کاربر {$uid} ({$user['username'] ?? 'no username'}) هشدارهای غیرفعال است\n";
            continue;
        }
        
        // Get user's sites
        $sites = Db::all('SELECT * FROM `site` WHERE `user_id` = ? AND `paused` = 0', [$uid]);
        
        foreach ($sites as $site) {
            $target = (string)$site['target'];
            $label = $site['label'] ?: $target;
            $siteId = $site['id'];
            $type = $site['type'] ?? 'ping';
            
            // 1. Check site status from database
            $status = $site['status'] ?? 'unknown';
            $consecutiveFails = (int)($site['consecutive_fail'] ?? 0);
            $threshold = max(1, Db::getInt('fail_threshold', 2));
            
            // Check if site just went down (new alert)
            if ($status === 'down' && $consecutiveFails >= $threshold) {
                $alertKey = "alert_down_{$siteId}_{$uid}";
                $alreadyAlerted = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$alertKey]);
                
                if ((int)$alreadyAlerted === 0 && $notify) {
                    // Get last check time
                    $lastCheck = $site['last_check_at'] ?? '';
                    $lastCheckAgo = $lastCheck ? timeAgo($lastCheck, tzOffset()) : 'نامشخص';
                    
                    $alertText = "🔴 <b>هشدار قطعی سرویس</b>\n\n"
                        . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                        . "🧾 نوع: " . typeName($type) . "\n"
                        . "⏰ زمان قطعی: {$lastCheckAgo}\n"
                        . "❌ وضعیت: قطع\n"
                        . "🔁 چک‌های ناموفق پیاپی: {$consecutiveFails}\n\n"
                        . "برای جزئیات: «📋 سایت‌های من» → انتخاب سایت";
                    
                    // Send to user
                    try {
                        tgSend($uid, $alertText);
                        $totalAlerts++;
                        $alertsByType['down'] = ($alertsByType['down'] ?? 0) + 1;
                        Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", [$alertKey, 'sent']);
                        $emit "🔗 Down alert sent to user {$uid}\n";
                    } catch (Throwable $e) {
                        uptimeLog('error', "Down alert send failed for user {$uid}: " . $e->getMessage());
                    }
                    
                    // Send to admins
                    foreach ($adminIds as $adminId) {
                        try {
                            $adminText = "🔴 <b>هشدار قطعی سرویس</b>\n\n"
                                . "👤 کاربر: " . ($user['name'] ?? 'unknown') . " ({$uid})\n"
                                . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                                . "📅 زمان: " . date('Y-m-d H:i:s') . "\n"
                                . "❌ وضعیت: قطع\n\n"
                                . "برای مشاهده جزئیات: /sites";
                            
                            BotApi::call(botToken(), 'sendMessage', [
                                'chat_id' => $adminId,
                                'text' => $adminText,
                                'parse_mode' => 'HTML',
                            ]);
                            $emit "👑 Admin alert sent to admin {$adminId}\n";
                        } catch (Throwable $e) {
                            uptimeLog('error', "Admin down alert failed for admin {$adminId}: " . $e->getMessage());
                        }
                    }
                }
            }
            
            // 2. Check site recovery (just came back up)
            if ($status !== 'down' && $consecutiveFails < $threshold) {
                $wasDownKey = "was_down_{$siteId}_{$uid}";
                $wasRecentlyDown = Db::val("SELECT COUNT(*) FROM `events` WHERE `user_id` = ? AND `kind` = 'alert_down' AND `ts` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", [$uid]);
                
                // If site was down recently and now is up
                if ($wasRecentlyDown > 0) {
                    $recoveryKey = "recovery_{$siteId}_{$uid}";
                    $alreadyRecovered = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$recoveryKey]);
                    
                    if ((int)$alreadyRecovered === 0) {
                        $alertText = "🟢 <b>سرویس دوباره برقرار شد</b>\n\n"
                            . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                            . "⌛️ مدت قطعی: تخمین زده شده\n"
                            . "⚡️ زمان پاسخ جدید: بررسی می‌شود\n"
                            . "✅ وضعیت: حالاً فعال\n\n"
                            . "آپتایم جدید": faDuration(time() - strtotime($site['last_down_at'] ?? '0')) ?? 'نشناخته';
                        
                        try {
                            tgSend($uid, $alertText);
                            $totalAlerts++;
                            $alertsByType['up'] = ($alertsByType['up'] ?? 0) + 1;
                            Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", [$recoveryKey, 'sent']);
                            $emit "🟢 Up recovery alert sent to user {$uid}\n";
                        } catch (Throwable $e) {
                            uptimeLog('error', "Up recovery alert send failed for user {$uid}: " . $e->getMessage());
                        }
                        
                        // Send to admins
                        foreach ($adminIds as $adminId) {
                            try {
                                $adminText = "🟢 <b>سرویس بازیابی شد</b>\n\n"
                                    . "👤 کاربر: " . ($user['name'] ?? 'unknown') . " ({$uid})\n"
                                    . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                                    . "✅ وضعیت: حالاً فعال\n\n"
                                    . "برای جزئیات: /sites";
                                
                                BotApi::call(botToken(), 'sendMessage', [
                                    'chat_id' => $adminId,
                                    'text' => $adminText,
                                    'parse_mode' => 'HTML',
                                ]);
                                $emit "👑 Admin recovery alert sent to admin {$adminId}\n";
                            } catch (Throwable $e) {
                                uptimeLog('error', "Admin recovery alert failed for admin {$adminId}: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
            
            // 3. High ping alert (already handled separately, but tracking here)
            $pingThreshold = Db::getInt('high_ping_threshold', 500);
            $pingVal = (int)($site['last_ms'] ?? 0);
            
            if ($pingVal > $pingThreshold) {
                $highPingKey = "high_ping_alert_{$siteId}_{$uid}";
                $alreadyHighPingAlerted = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$highPingKey]);
                
                if ((int)$alreadyHighPingAlerted === 0 && $notify) {
                    $alertText = "📊 <b>هشدار پینگ بالا</b>\n\n"
                        . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                        . "📈 پینگ đo شده: {$pingVal} میلی‌ثانیه\n"
                        . "🎯 آستانه>: {$pingThreshold} میلی‌ثانیه\n"
                        . "⚠️ پینگ بالای norma - ممکن است شبکه مشکل داشته باشد";
                    
                    try {
                        tgSend($uid, $alertText);
                        $totalAlerts++;
                        $alertsByType['high_ping'] = ($alertsByType['high_ping'] ?? 0) + 1;
                        Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", [$highPingKey, 'sent']);
                        $emit "📊 High ping alert sent to user {$uid}\n";
                    } catch (Throwable $e) {
                        uptimeLog('error', "High ping alert send failed for user {$uid}: " . $e->getMessage());
                    }
                    
                    // Also alert admins
                    foreach ($adminIds as $adminId) {
                        try {
                            $adminText = "📊 <b>هشدار پینگ بالا</b>\n\n"
                                . "👤 کاربر: " . ($user['name'] ?? 'unknown') . " ({$uid})\n"
                                . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                                . "📈 پینگ: {$pingVal}ms\n"
                                . "🎯 آستانه>: {$pingThreshold}ms\n\n"
                                . "برای مشاهده: /sites";
                            
                            BotApi::call(botToken(), 'sendMessage', [
                                'chat_id' => $adminId,
                                'text' => $adminText,
                                'parse_mode' => 'HTML',
                            ]);
                            $emit "👑 Admin high ping alert sent to admin {$adminId}\n";
                        } catch (Throwable $e) {
                            uptimeLog('error', "Admin high ping alert failed for admin {$adminId}: " . $e->getMessage());
                        }
                    }
                }
            }
            
            // 4. Domain expiry alert (integrated)
            $domainExpiry = $site['domain_expiry'] ?? null;
            if ($domainExpiry) {
                $expiryTimestamp = strtotime($domainExpiry);
                $now = time();
                $daysUntilExpiry = floor(($expiryTimestamp - $now) / 86400);
                
                if ($daysUntilExpiry > 0 && $daysUntilExpiry <= 30) {
                    $domainKey = "domain_expiry_alert_{$siteId}_{$daysUntilExpiry}";
                    $alreadyDomainAlerted = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$domainKey]);
                    
                    if ((int)$alreadyDomainAlerted === 0 && $notify) {
                        $daysText = $daysUntilExpiry === 1 ? '1 روز' : "{$daysUntilExpiry} روز";
                        
                        $alertText = "🔔 <b>هشدار_domain نزدیک امضا</b>\n\n"
                            . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                            . "📅 تاریخ انقضا: {$domainExpiry}\n"
                            . "⏰ روزهای باقی‌مانده: {$daysText}\n"
                            . "⚠️_domain imminently expiry - Action Required";
                        
                        try {
                            tgSend($uid, $alertText);
                            $totalAlerts++;
                            $alertsByType['domain'] = ($alertsByType['domain'] ?? 0) + 1;
                            Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", [$domainKey, 'sent']);
                            $emit "📅 Domain expiry alert sent to user {$uid}\n";
                        } catch (Throwable $e) {
                            uptimeLog('error', "Domain expiry alert send failed for user {$uid}: " . $e->getMessage());
                        }
                        
                        // Admin alert
                        foreach ($adminIds as $adminId) {
                            try {
                                $adminText = "🔔 <b>هشدار_domain نزدیک امضا</b>\n\n"
                                    . "👤 کاربر: " . ($user['name'] ?? 'unknown') . " ({$uid})\n"
                                    . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                                    . "📅 تاریخ انقضا: {$domainExpiry}\n"
                                    . "⏰ روزها: {$daysText}\n\n"
                                    . "برای تمدید: /admin → دامنه";
                                
                                BotApi::call(botToken(), 'sendMessage', [
                                    'chat_id' => $adminId,
                                    'text' => $adminText,
                                    'parse_mode' => 'HTML',
                                ]);
                                $emit "👑 Admin domain alert sent to admin {$adminId}\n";
                            } catch (Throwable $e) {
                                uptimeLog('error', "Admin domain alert failed for admin {$adminId}: " . $e->getMessage());
                            }
                        }
                    }
                }
                
                if ($daysUntilExpiry <= 0) {
                    $domainExpiredKey = "domain_expired_{$siteId}_{$uid}";
                    $alreadyDomainExpiredAlerted = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$domainExpiredKey]);
                    
                    if ((int)$alreadyDomainExpiredAlerted === 0 && $notify) {
                        $alertText = "🔴 <b>_domain منقضی شده</b>\n\n"
                            . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                            . "📅 تاریخ انقضا: {$domainExpiry}\n"
                            . "❌_status_: منقضی شده\n\n"
                            . "_domain_site_site دیگر کار نمی‌کند - باید تمدید شود!";
                        
                        try {
                            tgSend($uid, $alertText);
                            $totalAlerts++;
                            $alertsByType['domain_expired'] = ($alertsByType['domain_expired'] ?? 0) + 1;
                            Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", [$domainExpiredKey, 'sent']);
                            $emit "🔴 Domain expired alert sent to user {$uid}\n";
                        } catch (Throwable $e) {
                            uptimeLog('error', "Domain expired alert send failed for user {$uid}: " . $e->getMessage());
                        }
                        
                        // Admin alert
                        foreach ($adminIds as $adminId) {
                            try {
                                $adminText = "🔴 <b>_domain منقضی شده</b>\n\n"
                                    . "👤 کاربر: " . ($user['name'] ?? 'unknown') . " ({$uid})\n"
                                    . "🔗 سایت: <code>{$label}</code> ({$target})\n"
                                    . "📅 تاریخ انقضا: {$domainExpiry}\n\n"
                                    . "برای تمدید فوری: /admin → دامنه";
                                
                                BotApi::call(botToken(), 'sendMessage', [
                                    'chat_id' => $adminId,
                                    'text' => $adminText,
                                    'parse_mode' => 'HTML',
                                ]);
                                $emit "👑 Admin domain expired alert sent to admin {$adminId}\n";
                            } catch (Throwable $e) {
                                uptimeLog('error', "Admin domain expired alert failed for admin {$adminId}: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
            
            // 5. SSL Certificate expiry alert (integrated)
            if (preg_match('#^https?://#i', $target)) {
                $p = parse_url($target);
                $host = $p['host'] ?? '';
                
                if ($host) {
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
                                
                                if ($daysRemaining > 0 && $daysRemaining <= 30) {
                    $sslKey = "ssl_cert_expiry_{$siteId}_{$daysRemaining}";
                    $alreadySslAlerted = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$sslKey]);
                    
                    if ((int)$alreadySslAlerted === 0 && $notify) {
                        $alertText = "⚠️ <b>هشدارSSL Certificate</b>\n\n"
                            . "🔗 سایت: <code>{$label}</code> ({$host})\n"
                            . "⏰ تاریخ انقضا SSL: {$daysRemaining} روز\n"
                            . "⚠️ SSL Certificate {$daysRemaining} روز دیگر منقضی خواهد بود.\n\n"
                            . "لطفاً SSL Certificate را تمدید کنید.";
                        
                        try {
                            tgSend($uid, $alertText);
                            $totalAlerts++;
                            $alertsByType['ssl'] = ($alertsByType['ssl'] ?? 0) + 1;
                            Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", [$sslKey, 'sent']);
                            $emit "🔒 SSL alert sent to user {$uid}\n";
                        } catch (Throwable $e) {
                            uptimeLog('error', "SSL alert send failed for user {$uid}: " . $e->getMessage());
                        }
                        
                        // Admin alert
                        foreach ($adminIds as $adminId) {
                            try {
                                $adminText = "⚠️ <b>هشدارSSL Certificate</b>\n\n"
                                    . "👤 کاربر: " . ($user['name'] ?? 'unknown') . " ({$uid})\n"
                                    . "🔗 سایت: <code>{$label}</code> ({$host})\n"
                                    . "📅 روزهای باقی‌مانده: {$daysRemaining}\n\n"
                                    . "برای تمدید: /admin → SSL";
                                
                                BotApi::call(botToken(), 'sendMessage', [
                                    'chat_id' => $adminId,
                                    'text' => $adminText,
                                    'parse_mode' => 'HTML',
                                ]);
                                $emit "👑 Admin SSL alert sent to admin {$adminId}\n";
                            } catch (Throwable $e) {
                                uptimeLog('error', "Admin SSL alert failed for admin {$adminId}: " . $e->getMessage());
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
    }
    
    // Final summary
    $emit "\n=== خلاصه کلی هشدار‌ها ===\n";
    $emit "📊 کل هشدارهای ارسال شده: {$totalAlerts}\n";
    
    foreach ($alertsByType as $type => $count) {
        $emoji = match($type) {
            'down' => '🔴',
            'up' => '🟢',
            'high_ping' => '📊',
            'domain' => '🔔',
            'domain_expired' => '🔴',
            'ssl' => '⚠️',
            default => 'ℹ️',
        };
        $emit "{$emoji} {$type}: {$count}\n";
    }
    
    $emit "⏰ زمان انجام: " . date('Y-m-d H:i:s') . "\n";
    $emit "👥 اطلاع‌رسانی به: " . count($users) . " کاربر فعال\n";
    $emit "👑 اطلاع‌رسانی به ادمین: " . count($adminIds) . " مدیر\n\n";
    
    echo "\n✅ Comprehensive alert system check completed!\n";
    
} catch (Throwable $e) {
    uptimeLog('error', 'Comprehensive alert system failed: ' . $e->getMessage());
    echo "❌ Alert system check failed: " . $e->getMessage() . "\n";
}