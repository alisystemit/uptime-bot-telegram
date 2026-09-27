<?php
/**
 * Add domain expiry field to site table
 * This script adds a domain_expiry column to track domain expiration dates
 */

require_once __DIR__ . '/lib/bootstrap.php';

echo "=== Adding domain expiry field to site table ===\n\n";

try {
    appBoot();
    
    $pdo = Db::pdo();
    
    // Check current columns
    $columns = $pdo->query("SHOW COLUMNS FROM `site`")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'Field');
    
    // Add domain_expiry column
    if (!in_array('domain_expiry', $columnNames)) {
        $pdo->exec("ALTER TABLE `site` ADD `domain_expiry` DATETIME NULL DEFAULT NULL AFTER `last_down_at`");
        echo "✅ domain_expiry column added to site table\n";
    } else {
        echo "⏭ domain_expiry column already exists\n";
    }
    
    // Add domain_warn_sent setting tracking
    $settings = ['domain_warn_30d' => '0', 'domain_warn_7d' => '0'];
    
    foreach ($settings as $key => $value) {
        $existing = Db::val("SELECT COUNT(*) FROM `settings` WHERE `k` = ?", [$key]);
        if ((int)$existing === 0) {
            Db::q("INSERT INTO `settings` (`k`,`v`) VALUES (?,?)", [$key, $value]);
            echo "✅ Setting added: {$key} = {$value}\n";
        } else {
            echo "⏭ Setting already exists: {$key}\n";
        }
    }
    
    // Initialize domain_expiry for existing sites (set to 1 year from creation as default)
    $sites = Db::all('SELECT * FROM `site` WHERE `domain_expiry` IS NULL');
    foreach ($sites as $site) {
        $creationDate = strtotime($site['created_at']);
        $oneYearLater = date('Y-m-d H:i:s', $creationDate + 365 * 86400);
        Db::q("UPDATE `site` SET `domain_expiry` = ? WHERE `id` = ?", [$oneYearLater, $site['id']]);
    }
    echo "✅ {$count($sites)} existing sites initialized with default expiry dates\n";
    
    echo "\n✅ Domain expiry system added successfully!\n";
    
} catch (Throwable $e) {
    uptimeLog('error', 'Domain expiry migration failed: ' . $e->getMessage());
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}