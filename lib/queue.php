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
            return (int)Db::pdo()->lastInsertId();
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
                 LIMIT ' . (int)$limit,
                ['pending']
            );

            $deadline = time() + $timeout;

            foreach ($jobs as $job) {
                if (time() > $deadline) {
                    $stats['skipped']++;
                    break;
                }

                // قفل کردن کار تا worker دیگری هم‌زمان برداشتش نکند
                $claimed = Db::q(
                    'UPDATE `queue` SET `status` = "processing", `started_at` = NOW()
                      WHERE `id` = ? AND `status` = "pending"',
                    [$job['id']]
                )->rowCount();
                if ($claimed === 0) continue; // کار را worker دیگری برداشت

                $data = json_decode((string)$job['data'], true);
                if (!is_array($data)) $data = [];
                $success = false;

                try {
                    $success = match ($job['type']) {
                        'check_site'     => self::handleCheckSite($data),
                        'export_report'  => self::handleExportReport($data),
                        'send_alert'     => self::handleSendAlert($data),
                        'webhook_event'  => self::handleWebhookEvent($data),
                        'cleanup'        => self::handleCleanup($data),
                        default => throw new RuntimeException('نوع کار ناشناخته: ' . $job['type']),
                    };

                    if ($success) {
                        Db::q(
                            'UPDATE `queue` SET `status` = ?, `completed_at` = NOW() WHERE `id` = ?',
                            ['completed', $job['id']]
                        );
                        $stats['processed']++;
                    } else {
                        throw new RuntimeException('handler returned false');
                    }
                } catch (Throwable $e) {
                    $attempts = (int)($job['attempts'] ?? 0);
                    if ($attempts < 3) {
                        // تلاش دوباره در دور بعدی
                        Db::q(
                            'UPDATE `queue` SET `status` = "pending", `attempts` = ?, `last_error` = ? WHERE `id` = ?',
                            [$attempts + 1, mb_substr($e->getMessage(), 0, 480), $job['id']]
                        );
                    } else {
                        Db::q(
                            'UPDATE `queue` SET `status` = ?, `error` = ? WHERE `id` = ?',
                            ['failed', mb_substr($e->getMessage(), 0, 480), $job['id']]
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
        // checkSite کلید 'result' برمی‌گرداند (نه 'ok')
        return isset($result['result']) && empty($result['result']['error']);
    }

    private static function handleExportReport(array $payload): bool
    {
        $reportId = (int)($payload['report_id'] ?? 0);
        $format = in_array($payload['format'] ?? 'csv', ['csv', 'html'], true)
            ? (string)$payload['format']
            : 'csv';

        $report = Db::one('SELECT * FROM `sla_reports` WHERE `id` = ?', [$reportId]);
        if (!$report) return false;

        $reportData = json_decode((string)$report['data_json'], true);
        if (!is_array($reportData)) return false;

        $content = $format === 'csv'
            ? SLAReport::exportCSV($reportData)
            : SLAReport::exportHTML((int)$report['user_id'], $reportData);

        $dir = UPTIME_ROOT . '/reports';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            uptimeLog('queue', 'cannot create reports dir');
            return false;
        }
        if (!is_writable($dir)) {
            uptimeLog('queue', 'reports dir not writable');
            return false;
        }

        $filename = 'report_' . $reportId . '.' . $format;
        $ok = @file_put_contents($dir . '/' . $filename, $content) !== false;
        if (!$ok) uptimeLog('queue', 'write failed: ' . $filename);

        return $ok;
    }

    private static function handleSendAlert(array $data): bool
    {
        $userId = (int)($data['user_id'] ?? 0);
        $message = $data['message'] ?? '';
        $keyboard = $data['keyboard'] ?? null;

        if ($userId <= 0 || $message === '') return false;

        $extra = [];
        if (!empty($keyboard)) $extra['reply_markup'] = $keyboard;
        $res = tgSend($userId, $message, $extra);

        return !empty($res['ok']);
    }

    private static function handleWebhookEvent(array $data): bool
    {
        $siteId = (int)($data['site_id'] ?? 0);
        $event = is_array($data['event'] ?? null) ? $data['event'] : [];

        if ($siteId <= 0 || empty($event['type'])) return false;

        // send() تعداد webhook‌های موفق را برمی‌گرداند؛ خطای شبکه داخلش
        // گرفته می‌شود پس خطای سخت محسوب نمی‌شود (وگرنه کار بی‌نهایت retry می‌شد)
        WebhookManager::send($siteId, $event);
        return true;
    }

    private static function handleCleanup(array $data): bool
    {
        // تمیزکاری دیتابیس
        Db::q('DELETE FROM `check_log` WHERE `ts` < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        Db::q('DELETE FROM `incident` WHERE `end_at` IS NOT NULL 
               AND `end_at` < DATE_SUB(NOW(), INTERVAL 180 DAY)');

        return true;
    }

    // ─────────────────────────────────────────────── Utilities

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
