<?php
/**
 * ===== Export و Import سایت‌ها =====
 *
 * فرمت‌های پشتیبانی: CSV, JSON
 * استفاده: ImportExport::exportCSV() / importCSV()
 */

class ImportExport
{
    /**
     * صادرات تمام سایت‌ها به CSV
     */
    public static function exportCSV(int $userId): string
    {
        $sites = Db::all(
            'SELECT * FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 ORDER BY `id`',
            [$userId]
        );

        $csv = "URL,Label,Type,MaxMS,Keyword,Enabled\n";

        foreach ($sites as $s) {
            $csv .= sprintf(
                '"%s","%s",%s,%d,"%s",%d' . "\n",
                str_replace('"', '""', $s['target']),
                str_replace('"', '""', $s['label'] ?? ''),
                $s['type'],
                (int)$s['max_ms'],
                str_replace('"', '""', $s['keyword'] ?? ''),
                ($s['paused'] ? 0 : 1)
            );
        }

        return $csv;
    }

    /**
     * صادرات تمام سایت‌ها به JSON
     */
    public static function exportJSON(int $userId): string
    {
        $sites = Db::all(
            'SELECT `target`, `label`, `type`, `max_ms`, `keyword`, `paused` 
             FROM `site` WHERE `user_id` = ? AND `chat_id` = 0',
            [$userId]
        );

        return json_encode(
            [
                'exported_at' => date('Y-m-d H:i:s'),
                'version' => '1.0',
                'sites' => $sites,
            ],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    /**
     * واردات سایت‌ها از CSV
     */
    public static function importCSV(int $userId, string $content): array
    {
        $lines = explode("\n", $content);
        $imported = 0;
        $errors = [];
        $skipped = 0;

        // پرش سطر اول (header)
        array_shift($lines);

        foreach ($lines as $lineNum => $line) {
            if (!trim($line)) continue;

            try {
                $parts = str_getcsv($line);
                if (count($parts) < 2) {
                    $errors[] = "سطر " . ($lineNum + 1) . ": ستون‌های ناکافی";
                    continue;
                }

                [$target, $label, $type, $maxMs, $keyword, $enabled] = array_pad($parts, 6, null);

                $target = trim($target);
                if (!$target) {
                    $errors[] = "سطر " . ($lineNum + 1) . ": URL خالی";
                    continue;
                }

                // تعیین نوع اگر خالی باشد
                $type = $type ?: 'http';

                // تطبیع هدف
                $norm = normalizeTarget($target);
                if (!$norm['ok']) {
                    $errors[] = "سطر " . ($lineNum + 1) . ": " . $norm['error'];
                    continue;
                }

                // بررسی تکراری
                $exists = Db::val(
                    'SELECT `id` FROM `site` 
                     WHERE `user_id` = ? AND `target` = ?',
                    [$userId, $norm['target']]
                );

                if ($exists) {
                    $skipped++;
                    continue;
                }

                // درج سایت جدید
                $token = makeShareToken(20);
                Db::q(
                    'INSERT INTO `site` 
                     (`user_id`, `target`, `label`, `type`, `host`, `port`, 
                      `max_ms`, `keyword`, `share_token`, `paused`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $userId,
                        $norm['target'],
                        trim((string)$label),
                        $norm['type'],
                        $norm['host'],
                        (int)$norm['port'],
                        (int)$maxMs ?: 0,
                        trim((string)$keyword),
                        $token,
                        ($enabled === '0' ? 1 : 0),
                    ]
                );

                $imported++;
                Db::logEvent($userId, 'import_site', $norm['target']);
            } catch (Throwable $e) {
                $errors[] = "سطر " . ($lineNum + 1) . ": " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'success' => $imported > 0,
        ];
    }

    /**
     * واردات سایت‌ها از JSON
     */
    public static function importJSON(int $userId, string $content): array
    {
        try {
            $data = json_decode($content, true);
            if (!isset($data['sites'])) {
                return ['imported' => 0, 'errors' => ['فرمت JSON نامعتبر'], 'success' => false];
            }

            $imported = 0;
            $errors = [];

            foreach ($data['sites'] as $index => $site) {
                try {
                    $target = $site['target'] ?? '';
                    if (!$target) {
                        $errors[] = "آیتم " . ($index + 1) . ": URL خالی";
                        continue;
                    }

                    $norm = normalizeTarget($target);
                    if (!$norm['ok']) {
                        $errors[] = "آیتم " . ($index + 1) . ": " . $norm['error'];
                        continue;
                    }

                    $token = makeShareToken(20);
                    Db::q(
                        'INSERT INTO `site` 
                         (`user_id`, `target`, `label`, `type`, `host`, `port`, 
                          `max_ms`, `keyword`, `share_token`, `paused`)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $userId,
                            $norm['target'],
                            trim((string)($site['label'] ?? '')),
                            $norm['type'],
                            $norm['host'],
                            (int)($norm['port'] ?? 0),
                            (int)($site['max_ms'] ?? 0),
                            trim((string)($site['keyword'] ?? '')),
                            $token,
                            ((int)($site['paused'] ?? 0) ? 1 : 0),
                        ]
                    );

                    $imported++;
                } catch (Throwable $e) {
                    $errors[] = "آیتم " . ($index + 1) . ": " . $e->getMessage();
                }
            }

            return [
                'imported' => $imported,
                'errors' => $errors,
                'success' => $imported > 0,
            ];
        } catch (Throwable $e) {
            return ['imported' => 0, 'errors' => [$e->getMessage()], 'success' => false];
        }
    }

    /**
     * صادرات گزارش‌ها (SLA)
     */
    public static function exportReports(int $userId, string $format = 'csv'): string
    {
        $reports = Db::all(
            'SELECT * FROM `sla_reports` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT 12',
            [$userId]
        );

        if ($format === 'csv') {
            $csv = "تاریخ,دوره,آپتایم %,مدت قطع\n";
            foreach ($reports as $r) {
                $data = json_decode($r['data_json'], true);
                $csv .= sprintf(
                    '"%s",%s,%.2f%%,%d' . "\n",
                    $r['created_at'],
                    $r['type'],
                    $data['totals']['uptime_pct'],
                    $data['totals']['downtime_sec']
                );
            }
            return $csv;
        }

        return json_encode($reports, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Backup تمام داده‌های کاربر
     */
    public static function backupUser(int $userId): string
    {
        $backup = [
            'backup_date' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'sites' => Db::all(
                'SELECT * FROM `site` WHERE `user_id` = ? AND `chat_id` = 0',
                [$userId]
            ),
            'reports' => Db::all(
                'SELECT * FROM `sla_reports` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT 12',
                [$userId]
            ),
            'incidents' => Db::all(
                'SELECT i.* FROM `incident` i 
                 JOIN `site` s ON s.id = i.site_id 
                 WHERE s.user_id = ? AND i.start_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)',
                [$userId]
            ),
        ];

        return json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
