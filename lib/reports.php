<?php
/**
 * ===== گزارش SLA و Performance =====
 *
 * امکانات:
 *   • گزارش ماهانه/سالانه
 *   • Export PDF/Excel
 *   • Metrics: uptime%, downtime, incidents, latency
 *   • Trending و comparison
 */

class SLAReport
{
    /**
     * تولید گزارش SLA برای کاربر
     * @param string $period 'month' | 'quarter' | 'year'
     */
    public static function generate(int $userId, string $period = 'month'): array
    {
        $sites = Db::all(
            'SELECT * FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 ORDER BY `label`',
            [$userId]
        );

        if (!$sites) {
            return ['ok' => false, 'error' => 'هیچ سایتی ثبت نشده است'];
        }

        $days = self::periodDays($period);
        $siteReports = [];
        $totals = [
            'uptime_pct' => 0,
            'downtime_sec' => 0,
            'incidents' => 0,
            'avg_latency' => 0,
        ];

        foreach ($sites as $site) {
            $uptime = Stats::uptime($site, $days);
            $incidents = Db::all(
                'SELECT * FROM `incident` WHERE `site_id` = ? 
                 AND `start_at` >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 ORDER BY `start_at` DESC',
                [$site['id'], $days]
            );

            $latencies = Db::all(
                'SELECT `ms` FROM `check_log` 
                 WHERE `site_id` = ? AND `ts` >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 AND `ms` > 0',
                [$site['id'], $days]
            );

            $avgLatency = !empty($latencies)
                ? (int)array_sum(array_column($latencies, 'ms')) / count($latencies)
                : 0;

            // مدت قطع از رخدادها (منبع واحد: جدول incident)
            $downtimeSec = (int)Db::val(
                'SELECT COALESCE(SUM(`duration`),0) FROM `incident`
                 WHERE `site_id` = ? AND `kind` = "down"
                 AND `start_at` >= DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$site['id'], $days]
            );

            $report = [
                'site_id' => $site['id'],
                'target' => $site['target'],
                'label' => $site['label'] ?: $site['target'],
                'uptime_pct' => (float)($uptime['pct'] ?? 0),
                'uptime_hours' => (int)round((($uptime['pct'] ?? 0) * $days * 24) / 100),
                'downtime_sec' => $downtimeSec,
                'downtime_formatted' => self::formatSeconds($downtimeSec),
                'incidents_count' => count($incidents),
                'avg_response_ms' => $avgLatency,
                'min_response_ms' => (int)($site['resp_avg'] ?? 0),
                'max_response_ms' => (int)($site['resp_max'] ?? 0),
                'p95_response_ms' => (int)($site['resp_p95'] ?? 0),
                'check_count' => (int)Db::val(
                    'SELECT COUNT(*) FROM `check_log` 
                     WHERE `site_id` = ? AND `ts` >= DATE_SUB(NOW(), INTERVAL ? DAY)',
                    [$site['id'], $days]
                ),
                'incidents' => array_map(function ($i) {
                    return [
                        'kind' => $i['kind'],
                        'start' => $i['start_at'],
                        'end' => $i['end_at'],
                        'duration_sec' => (int)$i['duration'],
                        'reason' => $i['reason'],
                    ];
                }, $incidents),
            ];

            $siteReports[] = $report;
            $totals['uptime_pct'] += $report['uptime_pct'];
            $totals['downtime_sec'] += $report['downtime_sec'];
            $totals['incidents'] += $report['incidents_count'];
            $totals['avg_latency'] += $report['avg_response_ms'];
        }

        // میانگین‌ها
        $count = count($siteReports);
        $totals['uptime_pct'] = round($totals['uptime_pct'] / $count, 2);
        $totals['avg_latency'] = (int)($totals['avg_latency'] / $count);

        // ذخیره در دیتابیس
        $reportId = Db::q(
            'INSERT INTO `sla_reports` 
             (`user_id`, `period_start`, `period_end`, `type`, `data_json`)
             VALUES (?, DATE_SUB(NOW(), INTERVAL ? DAY), NOW(), ?, ?)',
            [
                $userId,
                $days,
                $period,
                json_encode(['sites' => $siteReports, 'totals' => $totals]),
            ]
        );
        $reportId = (int)Db::pdo()->lastInsertId();

        return [
            'ok' => true,
            'report_id' => $reportId,
            'period' => $period,
            'generated_at' => date('Y-m-d H:i:s'),
            'sites' => $siteReports,
            'totals' => $totals,
        ];
    }

    /**
     * Export به CSV
     */
    public static function exportCSV(array $report): string
    {
        $csv = "سایت,آپتایم %,مدت قطع,تعداد قطعی,میانگین latency\n";

        foreach ($report['sites'] as $site) {
            $csv .= sprintf(
                '"%s",%.2f%%,%s,%d,%dms' . "\n",
                $site['label'],
                $site['uptime_pct'],
                $site['downtime_formatted'],
                $site['incidents_count'],
                $site['avg_response_ms']
            );
        }

        return $csv;
    }

    /**
     * Export به HTML برای چاپ/PDF
     */
    public static function exportHTML(int $userId, array $report): string
    {
        $user = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$userId]);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>گزارش SLA</title>
    <style>
        * { margin: 0; padding: 0; }
        body { font-family: 'Tahoma', sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 40px; }
        .header { border-bottom: 3px solid #667eea; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { color: #333; font-size: 28px; }
        .header p { color: #666; margin-top: 5px; }
        .summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .metric { background: #f9f9f9; padding: 20px; border-radius: 8px; border-left: 4px solid #667eea; }
        .metric-label { color: #666; font-size: 12px; }
        .metric-value { font-size: 24px; font-weight: bold; color: #333; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #667eea; color: white; padding: 12px; text-align: right; }
        td { padding: 12px; border-bottom: 1px solid #e0e0e0; }
        tr:hover { background: #f9f9f9; }
        .incidents { margin-top: 30px; }
        .incident { background: #fff3cd; padding: 15px; margin-bottom: 10px; border-left: 4px solid #ffc107; }
        .footer { margin-top: 50px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 گزارش SLA</h1>
            <p>کاربر: {$user['name']} | تاریخ: {$report['generated_at']}</p>
        </div>

        <div class="summary">
            <div class="metric">
                <div class="metric-label">آپتایم متوسط</div>
                <div class="metric-value">{$report['totals']['uptime_pct']}%</div>
            </div>
            <div class="metric">
                <div class="metric-label">مدت قطع کل</div>
                <div class="metric-value">{$report['totals']['downtime_sec']}s</div>
            </div>
            <div class="metric">
                <div class="metric-label">تعداد قطعی‌ها</div>
                <div class="metric-value">{$report['totals']['incidents']}</div>
            </div>
            <div class="metric">
                <div class="metric-label">میانگین latency</div>
                <div class="metric-value">{$report['totals']['avg_latency']}ms</div>
            </div>
        </div>

        <h2>تفصیل سایت‌ها</h2>
        <table>
            <thead>
                <tr>
                    <th>سایت</th>
                    <th>آپتایم</th>
                    <th>مدت قطع</th>
                    <th>تعداد قطعی</th>
                    <th>میانگین latency</th>
                </tr>
            </thead>
            <tbody>
HTML;

        foreach ($report['sites'] as $site) {
            $html .= sprintf(
                '<tr><td>%s</td><td>%.2f%%</td><td>%s</td><td>%d</td><td>%dms</td></tr>',
                htmlspecialchars($site['label']),
                $site['uptime_pct'],
                $site['downtime_formatted'],
                $site['incidents_count'],
                $site['avg_response_ms']
            );
        }

        $html .= <<<HTML
            </tbody>
        </table>

        <div class="incidents">
            <h2>رویدادهای ثبت‌شده</h2>
HTML;

        foreach ($report['sites'] as $site) {
            if (!empty($site['incidents'])) {
                $html .= '<h3>' . htmlspecialchars($site['label']) . '</h3>';
                foreach ($site['incidents'] as $inc) {
                    $html .= sprintf(
                        '<div class="incident">
                            <strong>%s</strong> | %s → %s (%ds)
                            <br>دلیل: %s
                        </div>',
                        $inc['kind'],
                        date('H:i', strtotime($inc['start'])),
                        $inc['end'] ? date('H:i', strtotime($inc['end'])) : '—',
                        $inc['duration_sec'],
                        htmlspecialchars($inc['reason'])
                    );
                }
            }
        }

        $html .= <<<HTML
        </div>

        <div class="footer">
            <p>این گزارش به صورت خودکار تولید شده است</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    private static function periodDays(string $period): int
    {
        return match ($period) {
            'month' => 30,
            'quarter' => 90,
            'year' => 365,
            default => 30,
        };
    }

    private static function formatSeconds(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) return sprintf('%dh %dm', $hours, $minutes);
        if ($minutes > 0) return sprintf('%dm %ds', $minutes, $secs);
        return sprintf('%ds', $secs);
    }

    /**
     * فرستادن گزارش خودکار ماهانه
     */
    public static function sendMonthly(int $userId): bool
    {
        try {
            $report = self::generate($userId, 'month');
            if (!$report['ok']) return false;

            $html = self::exportHTML($userId, $report);
            $csv = self::exportCSV($report);

            // ذخیره فایل‌ها
            $filename = 'sla_' . date('Y-m') . '_' . $userId;
            file_put_contents(UPTIME_ROOT . '/reports/' . $filename . '.html', $html);
            file_put_contents(UPTIME_ROOT . '/reports/' . $filename . '.csv', $csv);

            // ارسال به کاربر
            $user = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$userId]);
            tgSend(
                $userId,
                "📊 <b>گزارش ماهانهٔ SLA برای " . date('F Y', strtotime('-1 month')) . "</b>\n\n"
                . "📈 آپتایم متوسط: " . $report['totals']['uptime_pct'] . "%\n"
                . "🔴 تعداد قطعی: " . $report['totals']['incidents'] . "\n"
                . "⏱ مدت قطع کل: " . self::formatSeconds($report['totals']['downtime_sec']) . "\n\n"
                . "📥 دانلود گزارش کامل از منو",
                ['reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => '📥 دانلود HTML', 'callback_data' => 'report_html_' . $report['report_id']],
                        ['text' => '📊 دانلود CSV', 'callback_data' => 'report_csv_' . $report['report_id']],
                    ]],
                ])]
            );

            Db::logEvent($userId, 'monthly_report', $report['report_id']);
            return true;
        } catch (Throwable $e) {
            uptimeLog('error', 'sendMonthly: ' . $e->getMessage());
            return false;
        }
    }
}
