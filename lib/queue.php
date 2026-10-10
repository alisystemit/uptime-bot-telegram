<?php
/**
 * ===== سیستم Queue برای پردازش موازی =====
 *
 * انواع کار:
 *   • check_site → بررسی سایت
 *   • export_report → صادرات گزارش
 *   • send_alert → ارسال هشدار
 *   • webhook_event → ارسال webhook
 */

class Queue
{
    /**
     * اضافه کردن کار به صف
     */
    public static function enqueue(string $type, array $data, int $priority = 5): int
    {
        try {
            $result = Db::q(
                'INSERT INTO `queue` (`type`, `data`, `priority`, `status`, `created_at`)
                 VALUES (?, ?, ?, ?, NOW())',
                [$type, json_encode($data, JSON_UNESCAPED_UNICODE), $priority, 'pending']
            );
            return $result->lastInsertId();
        } catch (Throwable $e) {
            uptimeLog('queue', 'enqueue error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * پردازش کارهای صف
     */
    public static function process(int $limit = 50, int $timeout = 30): array
    {
        $stats = ['processed' => 0, 'failed' => 0, 'skipped' => 0];

        try {
            $jobs = Db::all(
                'SELECT * FROM `queue` WHERE `status` = ? 
                 ORDER BY `priority` DESC, `created_at` ASC 
                 LIMIT ?',
                ['pending', $limit]
            );

            $deadline = time() + $timeout;

            foreach ($jobs as $job) {
                if (time() > $deadline) {
                    $stats['skipped']++;
                    break;
                }

                $data = json_decode($job['data'], true);
                $success = false;

                try {
                    $success = match ($job['type']) {
                        'check_site' => self::handleCheckSite($data),
                        'export_report' => self::handleExportReport($data),
                        'send_alert' => self::handleSendAlert($data),
                        'webhook_event' => self::handleWebhookEvent($data),
                        'cleanup' => self::handleCleanup($data),
                        default => false,
                    };

                    if ($success) {
                        Db::q(
                            'UPDATE `queue` SET `status` = ?, `completed_at` = NOW() WHERE `id` = ?',
                            ['completed', $job['id']]
                        );
                        $stats['processed']++;
                    } else {
                        throw new Exception('Handler returned false');
                    }
                } catch (Throwable $e) {
                    $attempts = (int)($job['attempts'] ?? 0);
                    if ($attempts < 3) {
                        // تلاش دوباره
                        Db::q(
                            'UPDATE `queue` SET `attempts` = ?, `last_error` = ? WHERE `id` = ?',
                            [$attempts + 1, $e->getMessage(), $job['id']]
                        );
                    } else {
                        // ناموفق نهایی
                        Db::q(
                            'UPDATE `queue` SET `status` = ?, `error` = ? WHERE `id` = ?',
                            ['failed', $e->getMessage(), $job['id']]
                        );
                        $stats['failed']++;
                    }
                }
            }
        } catch (Throwable $e) {
            uptimeLog('queue', 'process error: ' . $e->getMessage());
        }

        return $stats;
    }

    // ─────────────────────────────────────────────── Handlers

    private static function handleCheckSite(array $data): bool
    {
        $siteId = (int)($data['site_id'] ?? 0);
        if ($siteId <= 0) return false;

        $result = Monitor::checkSite($siteId);
        return $result['ok'] !== null;
    }

    private static function handleExportReport(array $data): bool
    {
        $reportId = (int)($data['report_id'] ?? 0);
        $format = $data['format'] ?? 'csv';  // csv, html, pdf

        $report = Db::one('SELECT * FROM `sla_reports` WHERE `id` = ?', [$reportId]);
        if (!$report) return false;

        $data = json_decode($report['data_json'], true);

        $content = match ($format) {
            'csv' => self::exportCSV($data),
            'html' => self::exportHTML($data),
            default => '',
        };

        // ذخیره فایل
        $filename = 'report_' . $reportId . '.' . $format;
        @mkdir(UPTIME_ROOT . '/reports', 0755, true);
        file_put_contents(UPTIME_ROOT . '/reports/' . $filename, $content);

        return true;
    }

    private static function handleSendAlert(array $data): bool
    {
        $userId = (int)($data['user_id'] ?? 0);
        $message = $data['message'] ?? '';
        $keyboard = $data['keyboard'] ?? null;

        if ($userId <= 0 || $message === '') return false;

        tgSend($userId, $message, $keyboard ? ['reply_markup' => $keyboard] : []);
        return true;
    }

    private static function handleWebhookEvent(array $data): bool
    {
        $siteId = (int)($data['site_id'] ?? 0);
        $event = $data['event'] ?? [];

        if ($siteId <= 0) return false;

        return WebhookManager::send($siteId, $event) > 0;
    }

    private static function handleCleanup(array $data): bool
    {
        // تمیزکاری دیتابیس
        Db::q('DELETE FROM `check_log` WHERE `checked_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        Db::q('DELETE FROM `incident` WHERE `end_at` IS NOT NULL 
               AND `end_at` < DATE_SUB(NOW(), INTERVAL 180 DAY)');

        return true;
    }

    // ─────────────────────────────────────────────── Utilities

    private static function exportCSV(array $report): string
    {
        return SLAReport::exportCSV($report);
    }

    private static function exportHTML(array $report): string
    {
        return SLAReport::exportHTML(0, $report);
    }

    /**
     * تعداد کارهای در انتظار
     */
    public static function count(): int
    {
        $count = Db::val('SELECT COUNT(*) FROM `queue` WHERE `status` = ?', ['pending']);
        return (int)$count;
    }

    /**
     * وضعیت صف
     */
    public static function status(): array
    {
        $pending = (int)Db::val('SELECT COUNT(*) FROM `queue` WHERE `status` = ?', ['pending']);
        $processing = (int)Db::val('SELECT COUNT(*) FROM `queue` WHERE `status` = ?', ['processing']);
        $failed = (int)Db::val('SELECT COUNT(*) FROM `queue` WHERE `status` = ?', ['failed']);

        return [
            'pending' => $pending,
            'processing' => $processing,
            'failed' => $failed,
            'total' => $pending + $processing + $failed,
        ];
    }

    /**
     * پاک‌کردن کارهای قدیمی
     */
    public static function cleanup(): int
    {
        $result = Db::q(
            'DELETE FROM `queue` WHERE `status` IN (?, ?) 
             AND `created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY)',
            ['completed', 'failed']
        );
        return $result->rowCount();
    }
}
