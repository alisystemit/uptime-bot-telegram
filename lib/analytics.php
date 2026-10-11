<?php
/**
 * ===== تحلیل پیشرفتهٔ عملکرد =====
 *
 * Metrics:
 *   • P50, P95, P99 latency
 *   • Reliability score
 *   • Trending (↑ ↓ →)
 *   • Benchmarking
 *
 * نکتهٔ منبع داده:
 *   • check_log فقط ۲۴ ساعت نگهداری می‌شود ⇒ برای بازهٔ کوتاه
 *   • uptime_hour ساعتی تجمیع می‌شود ⇒ برای بازهٔ بلند (۷/۳۰/۹۰ روز)
 */

class Analytics
{
    /** روزهای بیشتر از این ⇒ باید از uptime_hour خواند (چون check_log پاک می‌شود) */
    private const CHECK_LOG_DAYS = 1;

    /**
     * گزارش تحلیلی یک سایت
     */
    public static function getSiteReport(int $siteId): array
    {
        $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$siteId]);
        if (!$site) return ['ok' => false, 'error' => 'سایت یافت نشد'];

        $uptime1d  = Stats::uptime($site, 1);
        $uptime7d  = Stats::uptime($site, 7);
        $uptime30d = Stats::uptime($site, 30);

        return [
            'ok' => true,
            'site' => [
                'id' => (int)$site['id'],
                'target' => $site['target'],
                'label' => $site['label'] ?: $site['target'],
                'status' => $site['status'] ?? 'unknown',
            ],
            'uptime' => [
                '24h' => self::pct($uptime1d),
                '7d'  => self::pct($uptime7d),
                '30d' => self::pct($uptime30d),
            ],
            'latency' => [
                // از ستون‌های نگهداری‌شدهٔ سایت (به‌روزرسانی دوره‌ای توسط monitor)
                'avg' => (int)($site['resp_avg'] ?? 0),
                'max' => (int)($site['resp_max'] ?? 0),
                'p95' => (int)($site['resp_p95'] ?? 0),
                // دقیق از ۲۴ ساعت اخیر
                'p50_24h' => self::percentile($siteId, 50),
                'p95_24h' => self::percentile($siteId, 95),
                'p99_24h' => self::percentile($siteId, 99),
            ],
            'incidents' => [
                'count_24h' => self::incidentCount($siteId, 1),
                'count_7d'  => self::incidentCount($siteId, 7),
                'count_30d' => self::incidentCount($siteId, 30),
                'total_downtime_sec' => self::totalDowntime($siteId),
                'avg_duration_sec'   => self::avgIncidentDuration($siteId),
            ],
            'trending' => [
                'latency' => self::latencyTrend($siteId),
                'reliability' => self::reliabilityTrend($siteId),
                'reliability_score' => self::reliabilityScore($siteId),
            ],
            'predictions' => [
                'next_incident_probability' => self::nextIncidentProbability($siteId),
                'forecast_uptime_30d' => self::forecast($siteId),
            ],
        ];
    }

    /** تبدیل خروجی Stats::uptime به عدد (pct می‌تواند null باشد) */
    private static function pct(array $uptime): float
    {
        return isset($uptime['pct']) && $uptime['pct'] !== null ? round((float)$uptime['pct'], 2) : 0.0;
    }

    /**
     * Percentile latency از ۲۴ ساعت اخیر (check_log)
     * با LIMIT کنترل‌شده تا حافظه‌ای لود نشود.
     */
    private static function percentile(int $siteId, int $p): int
    {
        $limit = 5000;
        try {
            $rows = Db::all(
                'SELECT `ms` FROM `check_log`
                  WHERE `site_id` = ? AND `ms` > 0
                    AND `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                  ORDER BY `ms` ASC
                  LIMIT ' . (int)$limit,
                [$siteId]
            );
        } catch (Throwable $e) {
            return 0;
        }
        if (!$rows) return 0;

        $values = array_map('intval', array_column($rows, 'ms'));
        $count = count($values);
        $index = (int)ceil(($p / 100) * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return $values[$index];
    }

    /**
     * تعداد قطعی در بازه (روز)
     */
    private static function incidentCount(int $siteId, int $days): int
    {
        try {
            $c = Db::val(
                'SELECT COUNT(*) FROM `incident`
                  WHERE `site_id` = ? AND `kind` = "down"
                    AND `start_at` >= DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$siteId, max(1, $days)]
            );
        } catch (Throwable $e) {
            return 0;
        }
        return (int)$c;
    }

    /**
     * کل زمان قطع (ثانیه)
     */
    private static function totalDowntime(int $siteId): int
    {
        try {
            $sum = Db::val(
                'SELECT COALESCE(SUM(`duration`),0) FROM `incident`
                  WHERE `site_id` = ? AND `kind` = "down" AND `duration` > 0',
                [$siteId]
            );
        } catch (Throwable $e) {
            return 0;
        }
        return (int)$sum;
    }

    /**
     * میانگین مدت قطعی (ثانیه)
     */
    private static function avgIncidentDuration(int $siteId): int
    {
        try {
            $avg = Db::val(
                'SELECT COALESCE(AVG(`duration`),0) FROM `incident`
                  WHERE `site_id` = ? AND `kind` = "down" AND `duration` > 0',
                [$siteId]
            );
        } catch (Throwable $e) {
            return 0;
        }
        return (int)round((float)$avg);
    }

    /**
     * Trending زمان پاسخ: ۶ ساعت اخیر در برابر ۶ ساعت قبل
     * ↓ یعنی بهتر شده، ↑ یعنی بدتر شده
     */
    private static function latencyTrend(int $siteId): string
    {
        try {
            $row = Db::one(
                'SELECT
                     COALESCE(AVG(CASE WHEN `ts` >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN `ms` END),0) AS recent,
                     COALESCE(AVG(CASE WHEN `ts` <  DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN `ms` END),0) AS older
                   FROM `check_log`
                  WHERE `site_id` = ? AND `ms` > 0
                    AND `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)',
                [$siteId]
            );
        } catch (Throwable $e) {
            return '→';
        }

        $recent = (float)($row['recent'] ?? 0);
        $older  = (float)($row['older'] ?? 0);
        if ($recent <= 0 || $older <= 0) return '→';

        $change = (($recent - $older) / $older) * 100;

        if ($change < -10) return '↓';
        if ($change > 10)  return '↑';
        return '→';
    }

    /**
     * Trending پایداری: ۶ ساعت اخیر در برابر ۶ ساعت قبل (درصد موفقیت)
     */
    private static function reliabilityTrend(int $siteId): string
    {
        try {
            $row = Db::one(
                'SELECT
                     COALESCE(AVG(CASE WHEN `ts` >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN `ok`*100 END),0) AS recent,
                     COALESCE(AVG(CASE WHEN `ts` <  DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN `ok`*100 END),0) AS older
                   FROM `check_log`
                  WHERE `site_id` = ?
                    AND `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)',
                [$siteId]
            );
        } catch (Throwable $e) {
            return '→';
        }

        $recent = (float)($row['recent'] ?? 0);
        $older  = (float)($row['older'] ?? 0);
        if ($recent <= 0 || $older <= 0) return '→';

        $diff = $recent - $older;
        if ($diff > 2)  return '↑';
        if ($diff < -2) return '↓';
        return '→';
    }

    /**
     * Reliability Score (0..100) بر پایهٔ آپتایم ۳۰ روزه
     *   100% ⇒ 100 ، 95% ⇒ 50 ، 90% ⇒ 0
     */
    private static function reliabilityScore(int $siteId): int
    {
        $uptime = self::pct(Stats::uptime(['id' => $siteId], 30));
        $score = ($uptime - 90) * 10;
        return (int)max(0, min(100, round($score)));
    }

    /**
     * احتمال قطعی بعدی (درصد) بر پایهٔ نرخ خرابی ۷ روز اخیر
     */
    private static function nextIncidentProbability(int $siteId): float
    {
        try {
            $row = Db::one(
                'SELECT COUNT(*) AS checks, COALESCE(SUM(`ok`),0) AS oks
                   FROM `check_log`
                  WHERE `site_id` = ?
                    AND `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)',
                [$siteId]
            );
        } catch (Throwable $e) {
            return 0.0;
        }

        $checks = (int)($row['checks'] ?? 0);
        $oks    = (int)($row['oks'] ?? 0);
        if ($checks < 10) return 0.0; // دادهٔ کافی نیست

        // نرخ خرابی هر چک × تعداد چک‌های ۷ روز آینده (با فاصلهٔ ۲۰ ثانیه ≈ ۳۰۲۴۰)
        $failRate = ($checks - $oks) / $checks;
        $futureChecks = 30240;
        $expected = $failRate * $futureChecks;

        // تبدیل «تعداد مورد انتظار» به احتمال دست‌کم یک رخداد
        $probability = $expected > 0 ? (1 - exp(-$expected)) * 100 : 0;

        return round(min(100, $probability), 1);
    }

    /**
     * پیش‌بینی آپتایم ۳۰ روز آینده (هموارسازی با prior خوش‌بینانه)
     */
    private static function forecast(int $siteId): float
    {
        $historical = self::pct(Stats::uptime(['id' => $siteId], 60));
        return round(($historical + 99.5) / 2, 2);
    }

    /**
     * مقایسهٔ سایت‌های یک کاربر
     */
    public static function compareUserSites(int $userId): array
    {
        $sites = Db::all(
            'SELECT * FROM `site` WHERE `user_id` = ? AND `chat_id` = 0
              ORDER BY `label`',
            [$userId]
        );

        $comparison = [];
        foreach ($sites as $site) {
            $uptime30 = self::pct(Stats::uptime($site, 30));
            $comparison[] = [
                'id' => (int)$site['id'],
                'label' => $site['label'] ?: $site['target'],
                'target' => $site['target'],
                'uptime_30d' => $uptime30,
                'incidents_30d' => self::incidentCount((int)$site['id'], 30),
                'avg_latency' => (int)($site['resp_avg'] ?? 0),
            ];
        }

        usort($comparison, fn($a, $b) => $b['uptime_30d'] <=> $a['uptime_30d']);

        return $comparison;
    }

    /**
     * Benchmarking: مقایسهٔ سایت با میانگین کل سایت‌های فعال
     */
    public static function benchmark(int $siteId): array
    {
        $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$siteId]);
        if (!$site) return ['ok' => false, 'error' => 'سایت یافت نشد'];

        $siteUptime = self::pct(Stats::uptime($site, 30));

        try {
            $row = Db::one(
                'SELECT AVG(pct) AS avg_pct, COUNT(*) AS cnt FROM (
                     SELECT s.`id`,
                            CASE WHEN SUM(u.`checks`) > 0
                                 THEN ROUND(SUM(u.`ok`) * 100 / SUM(u.`checks`), 4)
                                 ELSE NULL END AS pct
                       FROM `site` s
                       JOIN `uptime_hour` u ON u.`site_id` = s.`id`
                      WHERE u.`bucket` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      GROUP BY s.`id`
                     ) t WHERE t.pct IS NOT NULL'
            );
        } catch (Throwable $e) {
            $row = ['avg_pct' => null, 'cnt' => 0];
        }

        $avg = $row['avg_pct'] !== null ? round((float)$row['avg_pct'], 2) : null;
        $total = (int)($row['cnt'] ?? 0);

        return [
            'ok' => true,
            'your_uptime' => $siteUptime,
            'avg_uptime' => $avg,
            'sites_compared' => $total,
            'rank' => $total > 0 ? self::getSiteRank($siteId) : 0,
            'total_sites' => $total,
            'percentile' => $total > 0 ? self::getPercentile($siteId, $total) : 0,
            'verdict' => self::verdict($siteUptime, $avg),
        ];
    }

    /** رتبهٔ سایت بین سایت‌هایی که دادهٔ ۳۰ روزه دارند (۱ = بهترین) */
    private static function getSiteRank(int $siteId): int
    {
        try {
            $rank = Db::val(
                'SELECT COUNT(*) + 1 FROM (
                     SELECT s.`id`,
                            SUM(u.`ok`) * 100 / SUM(u.`checks`) AS pct
                       FROM `site` s
                       JOIN `uptime_hour` u ON u.`site_id` = s.`id`
                      WHERE u.`bucket` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      GROUP BY s.`id`
                     ) a, (
                     SELECT s2.`id`, SUM(u2.`ok`) * 100 / SUM(u2.`checks`) AS pct
                       FROM `site` s2
                       JOIN `uptime_hour` u2 ON u2.`site_id` = s2.`id`
                      WHERE u2.`bucket` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      GROUP BY s2.`id`
                     ) b
                  WHERE a.`id` = ? AND b.pct > a.pct',
                [$siteId]
            );
        } catch (Throwable $e) {
            return 0;
        }
        return (int)$rank;
    }

    private static function getPercentile(int $siteId, int $total): int
    {
        $rank = self::getSiteRank($siteId);
        if ($total <= 0 || $rank <= 0) return 0;
        return (int)max(0, min(100, round((($total - $rank + 1) / $total) * 100)));
    }

    private static function verdict(float $yours, ?float $avg): string
    {
        if ($avg === null || $avg <= 0) return 'نامشخص';
        $diff = $yours - $avg;
        if ($diff >= 1)  return 'بهتر از میانگین ✅';
        if ($diff <= -1) return 'پایین‌تر از میانگین ⚠️';
        return 'مشابه میانگین ➖';
    }
}