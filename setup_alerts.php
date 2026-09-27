<?php
/**
 * Alert System Setup Script
 * Adds all configuration settings for the comprehensive alert system
 */

require_once __DIR__ . '/lib/bootstrap.php';

echo "=== تنظیمات سیستم هشدار جامع ===\n\n";

try {
    appBoot();
    
    $settings = [
        // General notification settings
        'notify' => Db::getBool('notify', true) ? '1' : '0',
        
        // High ping threshold (ms)
        'high_ping_threshold' => '500',
        
        // High ping warning days (how long ping must be above threshold before warning)
        'high_ping_warn_days' => '7',
        
        // Fail threshold (consecutive fails before down alert)
        'fail_threshold' => '2',
        
        // SSL warning days
        'ssl_warn_days' => '3',
        
        // Domain warning settings (tracked in check scripts, not directly in bot UI)
        'domain_warn_30d' => '0',  # Tracked per-site
        'domain_warn_7d' => '0',   # Tracked per-site
        
        // Ranking system settings (already added)
        'ranking_interval' => '60',
        'points_per_day' => '1',
        'points_per_uptime_hour' => '5',
    ];
    
    foreach ($settings as $key => $value) {
        $existing = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$key]);
        if ((int)$existing === 0) {
            Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?)", [$key, $value]);
            echo "✅ Setting added: {$key} = {$value}\n";
        } else {
            // Update value if different
            $current = Db::val("SELECT `v` FROM `settings` WHERE `k` = ?", [$key]);
            if ($current !== $value) {
                Db::q("UPDATE `settings` SET `v` = ? WHERE `k` = ?", [$value, $key]);
                echo "✅ Setting updated: {$key} = {$value}\n";
            } else {
                echo "⏭ Setting already correct: {$key} = {$value}\n";
            }
        }
    }
    
    echo "\n✅ Alert system settings configured successfully!\n";
    echo "\n📋 تنظیمات جدید:\n";
    echo "• high_ping_threshold: آستانه پینگ بالا (میل‌ثانیه)\n";
    echo "• high_ping_warn_days: مدتcontinuos بالا قبل از هشدار\n";
    echo "• fail_threshold: چک‌های ناموفق پیاپی قبل قطعی\n";
    echo "• ssl_warn_days: روزهایقبل از انقضایSSL هشدار دهید\n\n";
    
    echo "✅ تمام تنظیمات با موفقیت اعمال شدند!\n";
    
} catch (Throwable $e) {
    uptimeLog('error', 'Alert system setup failed: ' . $e->getMessage());
    echo "❌ Setup failed: " . $e->getMessage() . "\n";
}