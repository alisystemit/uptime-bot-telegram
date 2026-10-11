<?php
/**
 * ===== صفحهٔ وضعیت عمومی =====
 *
 * فایل: status_page.php
 * استفاده: /status_page.php?token=USER_TOKEN
 */

class StatusPageGenerator
{
    public static function generateHTML(string $token): ?string
    {
        // احراز هویت
        $user = Db::one('SELECT * FROM `user` WHERE `share_token` = ?', [$token]);
        if (!$user) return null;

        $sites = Db::all(
            'SELECT * FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 ORDER BY `label`',
            [$user['id']]
        );

        $incidents30d = Db::all(
            'SELECT i.* FROM `incident` i 
             JOIN `site` s ON s.id = i.site_id 
             WHERE s.user_id = ? AND i.start_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY i.start_at DESC LIMIT 100',
            [$user['id']]
        );

        $pageTitle = ($user['name'] ?: 'Status') . ' — Status Page';

        // کاربر ممکن است هنوز سایتی ثبت نکرده باشد ⇒ تقسیم بر صفر رخ می‌داد
        $avgUptime = 0.0;
        if ($sites) {
            $sum = 0.0;
            foreach ($sites as $s) $sum += (float)(Stats::uptime($s, 30)['pct'] ?? 0);
            $avgUptime = $sum / count($sites);
        }

        $sitesCount     = count($sites);
        $incidentsCount = count($incidents30d);
        $avgUptimeVal   = round($avgUptime, 1);
        $nowTime        = date('H:i');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, follow">
    <title>$pageTitle</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Vazir', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
        }

        .header-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .stat-box {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-top: 5px;
        }

        .sites-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .site-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .site-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }

        .site-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .status-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .status-up {
            background: #22c55e;
        }

        .status-down {
            background: #ef4444;
        }

        .status-slow {
            background: #f59e0b;
        }

        .site-name {
            font-weight: 600;
            color: #333;
            flex: 1;
            word-break: break-all;
        }

        .site-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 12px;
            font-size: 13px;
        }

        .site-stat {
            background: #f9f9f9;
            padding: 8px;
            border-radius: 6px;
        }

        .site-stat-label {
            color: #666;
            font-size: 11px;
        }

        .site-stat-value {
            color: #333;
            font-weight: 600;
            margin-top: 2px;
        }

        .incidents-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .incidents-section h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
        }

        .incident-item {
            border-left: 4px solid #fbbf24;
            background: #fffbeb;
            padding: 15px;
            margin-bottom: 12px;
            border-radius: 6px;
        }

        .incident-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .incident-site {
            font-weight: 600;
            color: #333;
        }

        .incident-time {
            color: #666;
            font-size: 12px;
        }

        .incident-reason {
            color: #666;
            font-size: 13px;
        }

        .incident-duration {
            color: #888;
            font-size: 12px;
            margin-top: 8px;
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            color: white;
            font-size: 12px;
        }

        .refresh-info {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .uptime-percentage {
            display: inline-block;
            padding: 4px 8px;
            background: #e8f5e9;
            color: #2e7d32;
            border-radius: 4px;
            font-weight: 600;
            font-size: 14px;
        }

        .uptime-percentage.low {
            background: #ffebee;
            color: #c62828;
        }

        @media (max-width: 768px) {
            .sites-grid {
                grid-template-columns: 1fr;
            }

            .header {
                padding: 20px;
            }

            .header h1 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="refresh-info">
            ⏱ این صفحه هر ۶۰ ثانیه خودکار تازه می‌شود
        </div>

        <div class="header">
            <h1>📊 صفحهٔ وضعیت</h1>
            <p style="color: #666; margin-top: 5px;">کاربر: {$user['name']}</p>

            <div class="header-stats">
                <div class="stat-box">
                    <div class="stat-label">سایت‌ها</div>
                    <div class="stat-value">$sitesCount</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">آپتایم ۳۰ روز</div>
                    <div class="stat-value">$avgUptimeVal%</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">رویدادها</div>
                    <div class="stat-value">$incidentsCount</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">وضعیت</div>
                    <div class="stat-value" style="color: #22c55e;">✓ فعال</div>
                </div>
            </div>
        </div>

        <div class="sites-grid">
HTML;

        if (!$sites) {
            $html .= '<div class="site-card" style="text-align:center;color:#666;">'
                . 'هنوز سایتی برای نمایش ثبت نشده است.</div>';
        }

        foreach ($sites as $site) {
            $uptime30 = Stats::uptime($site, 30);
            $status = $site['status'] ?? 'unknown';
            $statusIcon = match ($status) {
                'up' => '🟢',
                'down' => '🔴',
                'slow' => '🟠',
                default => '⚪',
            };
            $statusClass = match ($status) {
                'up' => 'status-up',
                'down' => 'status-down',
                'slow' => 'status-slow',
                default => '',
            };
            $uptimeClass = $uptime30['pct'] >= 99 ? '' : 'low';
            $siteName = $site['label'] ?: $site['target'];
            $uptimeVal = round($uptime30['pct'], 1);

            $html .= <<<HTML
        <div class="site-card">
            <div class="site-header">
                <div class="status-icon $statusClass">$statusIcon</div>
                <div class="site-name">$siteName</div>
            </div>

            <div class="site-stats">
                <div class="site-stat">
                    <div class="site-stat-label">آپتایم ۳۰ روز</div>
                    <div class="site-stat-value"><span class="uptime-percentage $uptimeClass">{$uptimeVal}%</span></div>
                </div>
                <div class="site-stat">
                    <div class="site-stat-label">زمان پاسخ</div>
                    <div class="site-stat-value">{$site['last_ms']}ms</div>
                </div>
                <div class="site-stat">
                    <div class="site-stat-label">آخرین بررسی</div>
                    <div class="site-stat-value">$nowTime</div>
                </div>
                <div class="site-stat">
                    <div class="site-stat-label">وضعیت</div>
                    <div class="site-stat-value">
HTML;

            $html .= match ($status) {
                'up' => 'فعال ✓',
                'down' => 'قطع ✗',
                'slow' => 'کند ⚠',
                default => 'نامشخص',
            };

            $html .= <<<HTML
                    </div>
                </div>
            </div>
        </div>
HTML;
        }

        $html .= <<<HTML
        </div>

        <div class="incidents-section">
            <h2>📋 رویدادهای ۳۰ روز اخیر</h2>

HTML;

        if (empty($incidents30d)) {
            $html .= '<p style="color: #666;">بدون رویداد 🎉</p>';
        } else {
            foreach ($incidents30d as $inc) {
                $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$inc['site_id']]);
                $duration = $inc['duration'] ? (int)($inc['duration'] / 60) . ' دقیقه' : '—';
                $endTime = $inc['end_at'] ? date('H:i', strtotime($inc['end_at'])) : 'درحال';
                $siteName = ($site['label'] ?: $site['target']);
                $incReason = $inc['reason'] ?: 'بدون دلیل ثبت‌شده';
                $incStart  = date('Y-m-d H:i', strtotime((string)$inc['start_at']));

                $html .= <<<HTML
            <div class="incident-item">
                <div class="incident-header">
                    <span class="incident-site">$siteName</span>
                    <span class="incident-time">{$incStart} → $endTime</span>
                </div>
                <div class="incident-reason">$incReason</div>
                <div class="incident-duration">مدت: $duration</div>
            </div>
HTML;
            }
        }

        $html .= <<<HTML
        </div>

        <div class="footer">
            <p>صفحهٔ وضعیت عمومی | بدون لاگین | noindex</p>
            <p style="margin-top: 10px; opacity: 0.8;">تازه‌سازی خودکار هر ۶۰ ثانیه</p>
        </div>
    </div>

    <script>
        // تازه‌سازی خودکار
        setTimeout(() => location.reload(), 60000);
    </script>
</body>
</html>
HTML;

        return $html;
    }
}
