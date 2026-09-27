<?php
/**
 * Ranking System Implementation
 * Awards points for uptime and displays user rankings
 */

require_once __DIR__ . '/lib/bootstrap.php';

try {
    appBoot();
    
    $emit = function(string $text) { echo $text . "\n"; };
    
    $emit("=== سیستم رنکینگ و امتیازNosانی ===\n");
    
    // 1. Get ranking configuration
    $rankingInterval = Db::getInt('ranking_interval', 60); // minutes
    $pointsPerDay = Db::getInt('points_per_day', 1);
    $pointsPerUptimeHour = Db::getInt('points_per_uptime_hour', 5);
    
    $emit("⚙️ تنظیمات رنکینگ:\n");
    $emit("   - بازه امتیازNosانی: {$rankingInterval} دقیقه\n");
    $emit("   - امتیاز روزانه_base: {$pointsPerDay} امتیاز\n");
    $emit("   - امتیاز هر ساعت آپتایم: {$pointsPerUptimeHour} امتیاز\n\n");
    
    // 2. Calculate rankings for all users
    $emit("=== رتبه‌بندی کاربران ===\n");
    
    $users = Db::all('SELECT u.id, u.name, u.username, u.is_admin, u.access, u.plan, u.paused, u.user_rank, u.user_points, u.site_uptime_count FROM `user` u ORDER BY u.user_points DESC, u.user_rank ASC');
    
    // Display top 10 rankings
    $emit("🏆 <b>تاپ 10 کاربران برتر:</b>\n");
    
    $rank = 1;
    $displayCount = min(10, count($users));
    
    foreach ($users as $i => $user) {
        if ($i >= $displayCount) break;
        
        $rankStr = $rank . '.';
        $name = $user['name'] ?? '';
        $username = $user['username'] ? '@' . $user['username'] : 'ندار';
        $points = $user['user_points'] ?? 0;
        $rankLevel = $user['user_rank'] ?? 1;
        $uptimeCount = $user['site_uptime_count'] ?? 0;
        
        // Determine rank badge
        $rankBadges = [
            1 => '🥇', 2 => '🥈', 3 => '🥉',
            4 => '4️⃣', 5 => '5️⃣', 6 => '6️⃣',
            7 => '7️⃣', 8 => '8️⃣', 9 => '9️⃣', 10 => '🔟'
        ];
        
        $badge = $rankBadges[$rank] ?? '#';
        $emit "{$badge} {$rankStr} {$username} - {$points} امتیاز (سطح {$rankLevel}) - {faNum($uptimeCount)} ساعتuptime\n";
        
        $rank++;
    }
    
    // Show total users
    $emit "\n📊 مجموع کاربران: " . count($users) . "\n";
    $emit "👑 کاربران ویژه: " . (count(Db::all("SELECT * FROM `user` WHERE `plan` = 'vip'"))) . "\n";
    $emit "⏸ کاربران متوقف: " . (count(Db::all("SELECT * FROM `user` WHERE `paused` = 1"))) . "\n";
    
    // 3. Award points to active users
    $emit "\n=== توزيع امتیازات روزانه ===\n";
    
    $awarded = 0;
    foreach ($users as $user) {
        $uid = (int)$user['id'];
        
        // Skip paused users (except admins)
        if ((int)$user['paused'] === 1 && !$user['is_admin']) {
            continue;
        }
        
        // Check if points already awarded today
        $today = date('Y-m-d');
        $lastAwarded = Db::val("SELECT `v` FROM `settings` WHERE `k` = 'last_points_{$uid}_{$today}'");
        
        if ($lastAwarded !== date('Y-m-d')) {
            // Award points
            $newPoints = ($user['user_points'] ?? 0) + $pointsPerDay;
            $newRank = min(10, max(1, 1 + floor($newPoints / 100))); // Rank every 100 points
            
            Db::q("UPDATE `user` SET `user_points` = ?, `user_rank` = ? WHERE `id` = ?", [$newPoints, $newRank, $uid]);
            Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)", ['last_points_{$uid}_{$today}', date('Y-m-d')]);
            
            $awarded++;
            $emit "✅ کاربر {$username = $user['username'] ?? 'unknown'} ({$uid}): +{$pointsPerDay} امتیاز (کل: {$newPoints}, رتبه: {$newRank})\n";
        }
    }
    
    $emit "📈 مجموع امتیازات mới vergeben: {$awarded} کاربر\n";
    
    // 4. Calculate uptime bonuses from sites
    $emit "\n=== بونوس آپتایم از سایت‌ها ===\n";
    
    $totalBonus = 0;
    foreach ($users as $user) {
        $uid = (int)$user['id'];
        $sites = Db::all('SELECT * FROM `site` WHERE `user_id` = AND `paused` = 0', [$uid]);
        
        $userBonus = 0;
        foreach ($sites as $site) {
            // Calculate uptime hours for this site
            $uptimeHours = Db::val("SELECT COALESCE(SUM(`checks`),0)/24 FROM `uptime_hour` WHERE `site_id` = ? AND `bucket` >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [$site['id']]);
            // Simplified: each site gives points based on status
            $status = $site['status'] ?? 'unknown';
            if ($status === 'up') {
                $userBonus += $pointsPerUptimeHour;
            }
        }
        
        if ($userBonus > 0) {
            $newPoints = (($user['user_points'] ?? 0) + $userBonus);
            $newRank = min(10, max(1, 1 + floor($newPoints / 100)));
            Db::q("UPDATE `user` SET `user_points` = ?, `user_rank` = ? WHERE `id` = ?", [$newPoints, $newRank, $uid]);
            $totalBonus += $userBonus;
            $emit "🌐 کاربر {$user['username'] ?? 'unknown'} ({$uid}): +{$userBonus} بونوس uptime (رتبه: {$newRank})\n";
        }
    }
    
    $emit "💰 مجموع بونוס آپتایم asignated: {$totalBonus} امتیاز\n";
    
    // 5. Display rank benefits
    $emit "\n=== مزایا بر اساس رتبه ===\n";
    
    $rankBenefits = [
        1 => ['name': 'بسیار ویژه', 'points': '1000+', 'benefits': ['حداکثر 20 سایت', 'اختیاری دسترسی پیش‌کلیدی', ' گزارش ویژه']],
        5 => ['name': ' speciale', 'points': '500-999', 'benefits': ['حداکثر 10 سایت', ' اولویت پشتیبانی', ' آمار részیلی']],
        3 => ['name': ' متوسط', 'points': '200-499', 'benefits': ['حداکثر 5 سایت', ' پشتیبانی معمولی']],
        1 => ['name': ' پایه', 'points': '0-199', 'benefits': ['حداکثر 3 سایت', ' پشتیبانی básicos']],
    ];
    
    foreach ($rankBenefits as $level => $benefit) {
        $emit "📍 رتبه {$benefit['name']} ( {$benefit['points']} امتیاز): {$benefit['benefits'][implode(', ', $benefit['benefits']])}\n";
    }
    
    echo "\n✅ Ranking system check completed!\n";
    
} catch (Throwable $e) {
    uptimeLog('error', 'Ranking system failed: ' . $e->getMessage());
    echo "❌ Ranking system error: " . $e->getMessage() . "\n";
}