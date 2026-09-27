<?php
/**
 * Initialize Ranking System
 * 
 * This script:
 * 1. Adds ranking columns to the user table
 * 2. Adds default settings for the ranking system
 * 3. Can be run once after installation
 */

require_once __DIR__ . '/lib/bootstrap.php';

echo "=== khởi tạo سیستم رنکینگuptime-bot ===\n\n";

try {
    appBoot();
    
    $pdo = Db::pdo();
    
    // 1. Add columns to user table
    echo "1. افزودن ستون‌ها به جدول کاربران...\n";
    
    // Check current columns
    $columns = $pdo->query("SHOW COLUMNS FROM `user`")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'Field');
    
    // Add user_rank
    if (!in_array('user_rank', $columnNames)) {
        $pdo->exec("ALTER TABLE `user` ADD `user_rank` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `is_blocked`");
        echo "   ✅ user_rank added\n";
    } else {
        echo "   ⏭ user_rank already exists\n";
    }
    
    // Add user_points
    if (!in_array('user_points', $columnNames)) {
        $pdo->exec("ALTER TABLE `user` ADD `user_points` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `user_rank`");
        echo "   ✅ user_points added\n";
    } else {
        echo "   ⏭ user_points already exists\n";
    }
    
    // Add site_uptime_count
    if (!in_array('site_uptime_count', $columnNames)) {
        $pdo->exec("ALTER TABLE `user` ADD `site_uptime_count` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `plan_until`");
        echo "   ✅ site_uptime_count added\n";
    } else {
        echo "   ⏭ site_uptime_count already exists\n";
    }
    
    // 2. Add ranking settings
    echo "\n2. افزودن تنظیمات Ranking...\n";
    
    $settings = [
        'ranking_interval' => '60',      // minutes
        'points_per_day' => '1',         // points per day
        'points_per_uptime_hour' => '5', // points per hour of uptime
    ];
    
    foreach ($settings as $key => $value) {
        $existing = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$key]);
        if ((int)$existing === 0) {
            Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?)", [$key, $value]);
            echo "   ✅ {$key} = {$value} added\n";
        } else {
            echo "   ⏭ {$key} already exists\n";
        }
    }
    
    // 3. Initialize user ranks and points (set to 1 and 0 for all existing users)
    echo "\n3.	initialize existing users...\n";
    
    $users = Db::all('SELECT id FROM `user`');
    foreach ($users as $user) {
        $uid = (int)$user['id'];
        // Set default rank 1 and 0 points if not set
        $currentPoints = Db::val("SELECT `user_points` FROM `user` WHERE `id` = ?", [$uid]);
        $currentRank = Db::val("SELECT `user_rank` FROM `user` WHERE `id` = ?", [$uid]);
        
        if ($currentPoints === null || $currentPoints === '') {
            Db::q("UPDATE `user` SET `user_points` = 0 WHERE `id` = ?", [$uid]);
        }
        if ($currentRank === null || $currentRank === '') {
            Db::q("UPDATE `user` SET `user_rank` = 1 WHERE `id` = ?", [$uid]);
        }
    }
    echo "   ✅ {$count(users)} users initialized\n";
    
    echo "\n✅ سیستم رنکینگuptime-bot با موفقیت راه‌اندازی شد!\n";
    echo "\n📋 دستورالعمل:\n";
    echo "1. ربات را باز restart کنید\n";
    echo "2. دستور /rank را ارسال کنید برای بررسی امتیاز خود\n";
    echo "3. سیستم به صورت خودکار برای کاربران فعال می‌شود\n";
    echo "4. ادمین می‌تواند از منوی مدیریت تنظیمات را تغییر دهد\n";
    
} catch (Throwable $e) {
    uptimeLog('error', 'Ranking initialization failed: ' . $e->getMessage());
    echo "❌ Init failed: " . $e->getMessage() . "\n";
}

echo "\n";