<?php
/**
 * ===== تشخیص Pattern و ناهنجاری =====
 *
 * تشخیص:
 *   • قطع‌های زمانی منظم
 *   • Latency spike
 *   • تغییرات رفتار ناگهانی
 *   • Pattern پیش‌بینی
 */

class AnomalyDetector
{
    /**
     * تحلیل یک سایت برای ناهنجاری
     */
    public static function analyze(int $siteId): array
    {
        $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$siteId]);
        if (!$site) return ['anomalies' => []];

        $anomalies = [];

        // گردآوری داده‌های ۷ روز اخیر
        $checks = Db::all(
            'SELECT `checked_at`, `is_up`, `response_ms` FROM `check_log` 
             WHERE `site_id` = ? AND `checked_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY `checked_at`',
            [$siteId]
        );

        if (count($checks) < 20) {
            return ['anomalies' => [], 'reason' => 'insufficient_data'];
        }

        // ۱. تشخیص قطع‌های زمانی منظم
        $hourCounts = array_fill(0, 24, ['down' => 0, 'total' => 0]);
        foreach ($checks as $check) {
            $hour = (int)date('H', strtotime($check['checked_at']));
            $hourCounts[$hour]['total']++;
            if (!$check['is_up']) {
                $hourCounts[$hour]['down']++;
            }
        }

        foreach ($hourCounts as $hour => $data) {
            if ($data['total'] > 0) {
                $downPercent = ($data['down'] / $data['total']) * 100;
                if ($downPercent >= 50) {
                    $anomalies[] = [
                        'type' => 'scheduled_downtime',
                        'hour' => str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00',
                        'downtime_percent' => round($downPercent, 1),
                        'occurrences' => $data['down'],
                        'severity' => 'info',
                        'suggestion' => 'ممکن است نگهداری برنامه‌ریزی شده یا مشکل منظم باشد',
                    ];
                }
            }
        }

        // ۲. تشخیص Latency Spike
        $latencies = array_column($checks, 'response_ms');
        $latencies = array_filter($latencies, fn($x) => $x > 0);

        if (!empty($latencies)) {
            $mean = array_sum($latencies) / count($latencies);
            $stdDev = self::calculateStdDev($latencies, $mean);

            foreach ($checks as $check) {
                if ($check['is_up'] && $check['response_ms'] > 0) {
                    if ($check['response_ms'] > ($mean + 3 * $stdDev)) {
                        $anomalies[] = [
                            'type' => 'latency_spike',
                            'time' => $check['checked_at'],
                            'latency_ms' => $check['response_ms'],
                            'expected_ms' => (int)$mean,
                            'std_dev' => (int)$stdDev,
                            'severity' => 'warning',
                            'suggestion' => 'سایت سالم است ولی غیرمعمول کند بود',
                        ];
                    }
                }
            }
        }

        // ۳. تشخیص Pattern تغییر
        $recentDowns = array_slice($checks, -20);
        $downCount = count(array_filter($recentDowns, fn($x) => !$x['is_up']));

        if ($downCount > 10) {
            $anomalies[] = [
                'type' => 'frequent_failures',
                'count_last_20_checks' => $downCount,
                'percentage' => round(($downCount / count($recentDowns)) * 100, 1),
                'severity' => 'critical',
                'suggestion' => 'سایت اخیراً بسیار ناپایدار است',
            ];
        }

        return ['anomalies' => $anomalies];
    }

    /**
     * تحلیل تمام سایت‌های یک کاربر
     */
    public static function analyzeUser(int $userId): array
    {
        $sites = Db::all(
            'SELECT `id` FROM `site` WHERE `user_id` = ? AND `chat_id` = 0',
            [$userId]
        );

        $results = [];
        foreach ($sites as $site) {
            $analysis = self::analyze($site['id']);
            if (!empty($analysis['anomalies'])) {
                $results[$site['id']] = $analysis;
            }
        }

        return $results;
    }

    /**
     * ارسال گزارش ناهنجاری برای کاربر
     */
    public static function reportToUser(int $userId): bool
    {
        try {
            $anomalies = self::analyzeUser($userId);
            if (empty($anomalies)) return true;

            $message = "⚠️ <b>تشخیص ناهنجاری</b>\n\n";

            foreach ($anomalies as $siteId => $analysis) {
                $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$siteId]);
                $message .= "<b>" . htmlspecialchars($site['label'] ?: $site['target']) . "</b>\n";

                foreach ($analysis['anomalies'] as $a) {
                    $icon = match ($a['severity']) {
                        'critical' => '🔴',
                        'warning' => '🟠',
                        'info' => '🔵',
                        default => '⚪',
                    };

                    $message .= match ($a['type']) {
                        'scheduled_downtime' => "  $icon قطع منظم ساعت {$a['hour']} ({$a['downtime_percent']}%)\n",
                        'latency_spike' => "  $icon Latency spike: {$a['latency_ms']}ms (متوسط: {$a['expected_ms']}ms)\n",
                        'frequent_failures' => "  $icon {$a['percentage']}% قطعی در ۲۰ چک اخیر\n",
                        default => "  $icon {$a['type']}\n",
                    };
                }
                $message .= "\n";
            }

            $message .= "💡 <i>برای اطلاعات بیشتر به جزئیات سایت مراجعه کنید</i>";

            tgSend($userId, $message);
            Db::logEvent($userId, 'anomaly_report', count($anomalies) . ' سایت');
            return true;
        } catch (Throwable $e) {
            uptimeLog('error', 'reportToUser: ' . $e->getMessage());
            return false;
        }
    }

    private static function calculateStdDev(array $values, float $mean): float
    {
        if (count($values) < 2) return 0;

        $sum = 0;
        foreach ($values as $val) {
            $sum += pow($val - $mean, 2);
        }

        return sqrt($sum / count($values));
    }
}
