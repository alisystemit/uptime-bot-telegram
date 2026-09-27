<?php
/**
 * Modified checker.php integration
 * Add calls to the new checking functions
 */

// In the main checker.php loop, add these calls after each round:

// After $stat = Monitor::round(); 
// Add:
/*
// Check high ping warnings
require_once __DIR__ . '/check_high_ping.php';

// Check domain expiry warnings  
require_once __DIR__ . '/check_domain_expiry.php';

// Run comprehensive alerts
require_once __DIR__ . '/comprehensive_alerts.php';
*/

// Note: These are standalone scripts that can be run separately or 
// integrated into the cron job. For production, uncomment and integrate.

echo "=== Checker Integration Notes ===\n\n";
echo "To integrate the new checking functions into your cron job:\n\n";
echo "1. Add these lines to cron/checker.php after the Monitor::round() call:\n";
echo "   require_once __DIR__ . '/check_high_ping.php';\n";
echo "   require_once __DIR__ . '/check_domain_expiry.php';\n";
echo "   require_once __DIR__ . '/comprehensive_alerts.php';\n\n";
echo "2. Or run them as separate cron jobs:\n";
echo "   * * * * * php /path/to/uptime-bot/check_high_ping.php\n";
echo "   * * * * * php /path/to/uptime-bot/check_domain_expiry.php\n";
echo "   * * * * * php /path/to/uptime-bot/comprehensive_alerts.php\n\n";
echo "3. For minimal impact, run comprehensive_alerts.php less frequently:\n";
echo "   - Every 15 minutes for alerts\n";
echo "   - Every 30 minutes for ping/domain checks\n\n";
echo "⚠️ Note: These scripts connect to external services (SSL checks, pings)\n";
echo "   which may add time to your cron execution. Consider increasing the\n";
echo "   budget or running them separately.\n";