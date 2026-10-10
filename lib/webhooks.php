<?php
/**
 * ===== مدیریت Webhookهای شخصی‌سازی =====
 *
 * انواع: discord, slack, custom, teams
 * رویدادها: down, up, slow, ssl_warning, domain_warning
 *
 * استفاده:
 *   WebhookManager::send($siteId, ['type' => 'down', 'error' => '...'])
 */

class WebhookManager
{
    /**
     * ارسال رویداد به تمام webhook‌های فعال
     */
    public static function send(int $siteId, array $event): int
    {
        try {
            $site = Db::one('SELECT * FROM site WHERE id = ?', [$siteId]);
            if (!$site) return 0;

            $hooks = Db::all(
                'SELECT * FROM webhooks 
                 WHERE site_id = ? AND enabled = 1',
                [$siteId]
            );

            $sent = 0;
            foreach ($hooks as $hook) {
                $eventTypes = explode(',', $hook['events'] ?? '');
                if (!in_array($event['type'], $eventTypes, true)) continue;

                if (self::dispatch($hook, $event, $site)) {
                    $sent++;
                    Db::q(
                        'UPDATE webhooks SET last_used = NOW(), fail_count = 0 WHERE id = ?',
                        [$hook['id']]
                    );
                } else {
                    Db::q(
                        'UPDATE webhooks SET fail_count = fail_count + 1 WHERE id = ?',
                        [$hook['id']]
                    );
                }
            }
            return $sent;
        } catch (Throwable $e) {
            uptimeLog('webhook_error', $e->getMessage());
            return 0;
        }
    }

    /**
     * ارسال به یک webhook
     */
    private static function dispatch(array $hook, array $event, array $site): bool
    {
        $payload = self::format($hook['type'], $event, $site);
        if (!$payload) return false;

        $headers = ['Content-Type: application/json'];
        if ($hook['type'] === 'slack' || $hook['type'] === 'discord') {
            // Slack/Discord خودشان JSON می‌خواهند
        } elseif ($hook['type'] === 'custom') {
            // Custom: سیگنال بررسی کن
            $signAlgo = $hook['settings']['sign_algo'] ?? null;
            if ($signAlgo) {
                $sig = hash_hmac($signAlgo, json_encode($payload), $hook['settings']['secret'] ?? '');
                $headers[] = 'X-Signature: ' . $sig;
            }
        }

        $resp = PayHttp::call($hook['url'], $payload, $headers, 'POST', 10);
        return $resp['status'] >= 200 && $resp['status'] < 300;
    }

    /**
     * فرمت‌بندی payload برای هر نوع
     */
    private static function format(string $type, array $event, array $site): ?array
    {
        return match ($type) {
            'discord' => self::formatDiscord($event, $site),
            'slack' => self::formatSlack($event, $site),
            'custom' => $event,
            'teams' => self::formatTeams($event, $site),
            default => null,
        };
    }

    private static function formatDiscord(array $event, array $site): array
    {
        $color = match ($event['type']) {
            'down' => 0xFF0000,    // قرمز
            'up' => 0x00FF00,      // سبز
            'slow' => 0xFFFF00,    // زرد
            default => 0x888888,
        };

        $title = match ($event['type']) {
            'down' => '🔴 سایت قطع شد',
            'up' => '🟢 سایت برگشت',
            'slow' => '🟠 سایت کند است',
            default => '⚪ رویداد',
        };

        return [
            'embeds' => [[
                'title' => $title,
                'description' => $site['label'] ?: $site['target'],
                'color' => $color,
                'fields' => [
                    ['name' => 'آدرس', 'value' => $site['target'], 'inline' => true],
                    ['name' => 'خطا', 'value' => $event['error'] ?? '—', 'inline' => false],
                    ['name' => 'زمان', 'value' => date('Y-m-d H:i:s'), 'inline' => true],
                    ['name' => 'زمان پاسخ', 'value' => ($event['ms'] ?? 0) . 'ms', 'inline' => true],
                ],
                'timestamp' => date('c'),
            ]],
        ];
    }

    private static function formatSlack(array $event, array $site): array
    {
        $emoji = match ($event['type']) {
            'down' => '🔴',
            'up' => '🟢',
            'slow' => '🟠',
            default => '⚪',
        };

        $status = match ($event['type']) {
            'down' => 'قطع',
            'up' => 'برقرار',
            'slow' => 'کند',
            default => 'نامشخص',
        };

        return [
            'text' => "$emoji سایت *{$site['label']}* $status شد",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => "$emoji " . ($event['type'] === 'down' ? 'هشدار قطعی!' : 'اطلاع'),
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => '*سایت:*\n' . $site['target']],
                        ['type' => 'mrkdwn', 'text' => '*وضعیت:*\n' . $status],
                        ['type' => 'mrkdwn', 'text' => '*زمان:*\n' . date('H:i')],
                        ['type' => 'mrkdwn', 'text' => '*زمان پاسخ:*\n' . ($event['ms'] ?? 0) . 'ms'],
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => '*دلیل:*\n' . ($event['error'] ?? 'نامشخص'),
                    ],
                ],
            ],
        ];
    }

    private static function formatTeams(array $event, array $site): array
    {
        $color = match ($event['type']) {
            'down' => 'FF0000',
            'up' => '00FF00',
            'slow' => 'FFFF00',
            default => '888888',
        };

        return [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => $site['label'] ?: $site['target'],
            'themeColor' => $color,
            'sections' => [
                [
                    'activityTitle' => match ($event['type']) {
                        'down' => '🔴 سایت قطع شد',
                        'up' => '🟢 سایت برقرار شد',
                        default => '⚪ رویداد',
                    },
                    'facts' => [
                        ['name' => 'سایت', 'value' => $site['label'] ?: $site['target']],
                        ['name' => 'آدرس', 'value' => $site['target']],
                        ['name' => 'خطا', 'value' => $event['error'] ?? '—'],
                        ['name' => 'زمان پاسخ', 'value' => ($event['ms'] ?? 0) . 'ms'],
                    ],
                ],
            ],
        ];
    }

    /**
     * تست اتصال webhook
     */
    public static function test(int $webhookId): bool
    {
        $hook = Db::one('SELECT * FROM webhooks WHERE id = ?', [$webhookId]);
        if (!$hook) return false;

        $site = Db::one('SELECT * FROM site WHERE id = ?', [$hook['site_id']]);
        if (!$site) return false;

        $event = [
            'type' => 'up',
            'error' => '',
            'ms' => 250,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $success = self::dispatch($hook, $event, $site);

        Db::q(
            'UPDATE webhooks SET test_at = NOW() WHERE id = ?',
            [$webhookId]
        );

        return $success;
    }

    /**
     * حذف webhookهای ناموفق (۱۰+ بار)
     */
    public static function cleanupFailed(): int
    {
        $result = Db::q(
            'DELETE FROM webhooks WHERE fail_count >= 10 AND last_used < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );
        return $result->rowCount();
    }
}
