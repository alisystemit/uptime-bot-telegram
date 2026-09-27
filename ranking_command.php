<?php
/**
 * Ranking Command - Handles /rank and !rank commands
 * Displays user ranking and points information
 */

require_once __DIR__ . '/lib/bootstrap.php';

try {
    appBoot();
    
    $botToken = botToken();
    $isAdmin = isBotAdmin(0); // Will be set by the caller
    
    $emit = function(string $text) { echo $text . "\n"; };
    
    // Handle different contexts
    if (PHP_SAPI === 'cli') {
        // CLI mode - just display general ranking
        $emit("=== سیستم رنکینگuptime-bot ===\n\n");
        
        // Get ranking config
        $rankingInterval = Db::getInt('ranking_interval', 60);
        $pointsPerDay = Db::getInt('points_per_day', 1);
        $pointsPerUptimeHour = Db::getInt('points_per_uptime_hour', 5);
        
        $emit("⚙️ تنظیمات رنکینگ:\n");
        $emit "   - بازه امتیازNosانی: {$rankingInterval} دقیقه\n";
        $emit "   - امتیاز روزانه_base: {$pointsPerDay} امتیاز\n";
        $emit "   - امتیاز هر ساعت آپتایم: {$pointsPerUptimeHour} امتیاز\n\n";
        
        // Get all users sorted by points
        $users = Db::all('SELECT u.id, u.name, u.username, u.is_admin, u.user_rank, u.user_points, u.site_uptime_count, u.plan, u.paused FROM `user` u ORDER BY u.user_points DESC LIMIT 20');
        
        $emit("🏆 <b>تاپ 20 رنکینگ:</b>\n");
        
        $rankBadges = ['1' => '🥇', '2' => '🥈', '3' => '🥉'];
        
        foreach ($users as $i => $user) {
            $rank = $i + 1;
            $badge = ($rank <= 3) ? $rankBadges[$rank] ?? '#' : "#{$rank}";
            $username = $user['username'] ? '@' . $user['username'] : 'عضو';
            $points = $user['user_points'] ?? 0;
            $rankLevel = $user['user_rank'] ?? 1;
            $uptime = $user['site_uptime_count'] ?? 0;
            $isVip = $user['plan'] === 'vip';
            
            $emit "{$badge} {$rank}. {$username} - {$points} امتیاز (سطح {$rankLevel}) - {faNum($uptime)} ساعت uptime" . ($isVip ? ' ⚡ ویژه' : '') . "\n";
        }
        
        $emit "\n📊 توضیحات:\n";
        $emit "• امتیازات به صورت روزانه awarded می‌شوند\n";
        $emit "• هر رای uptime ساعت {$pointsPerUptimeHour} امتیاز اضافه می‌کند\n";
        $emit "• سطوح هر {$pointsPerDay} امتیاز افزایش می‌یابد\n";
        $emit "• کاربران ویژه امتیاز{doubles} speed earn\n\n";
        
        // Show admin options if called by admin
        $emit "برای مدیریت رنکینگ از منوی مدیریت (/admin) استفاده کنید.\n";
        
    } else {
        // Telegram bot mode - would be handled in bot.php
        $emit "Ranking command executed from bot context\n";
    }
    
} catch (Throwable $e) {
    uptimeLog('error', 'Ranking command failed: ' . $e->getMessage());
    echo "❌ Ranking command error: " . $e->getMessage() . "\n";
}