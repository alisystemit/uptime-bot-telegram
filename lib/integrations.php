<?php
/**
 * ===== Integration با Slack و Discord =====
 *
 * استفاده:
 *   SlackIntegration::send($userId, 'down', $site)
 *   DiscordIntegration::send($userId, 'down', $site)
 */

class SlackIntegration
{
    public static function send(int $userId, string $eventType, array $site): bool
    {
        $webhook = Db::val(
            'SELECT webhook_url FROM `integrations` 
             WHERE user_id = ? AND type = "slack" AND enabled = 1',
            [$userId]
        );

        if (!$webhook) return false;

        $payload = self::buildPayload($eventType, $site);
        $resp = PayHttp::call($webhook, $payload);
        return $resp['status'] === 200;
    }

    private static function buildPayload(string $type, array $site): array
    {
        $emoji = match ($type) {
            'down' => '🔴',
            'up' => '🟢',
            'slow' => '🟠',
            default => '⚪',
        };

        return [
            'text' => "$emoji سایت {$site['label']} — $type",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => "$emoji " . ucfirst($type)],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => '*سایت:*\n' . $site['target']],
                        ['type' => 'mrkdwn', 'text' => '*زمان:*\n' . date('H:i')],
                    ],
                ],
            ],
        ];
    }
}

class DiscordIntegration
{
    public static function send(int $userId, string $eventType, array $site): bool
    {
        $webhook = Db::val(
            'SELECT webhook_url FROM `integrations` 
             WHERE user_id = ? AND type = "discord" AND enabled = 1',
            [$userId]
        );

        if (!$webhook) return false;

        $payload = self::buildPayload($eventType, $site);
        $resp = PayHttp::call($webhook, $payload);
        return $resp['status'] === 204 || $resp['status'] === 200;
    }

    private static function buildPayload(string $type, array $site): array
    {
        $color = match ($type) {
            'down' => 0xFF0000,
            'up' => 0x00FF00,
            'slow' => 0xFFFF00,
            default => 0x888888,
        };

        return [
            'embeds' => [[
                'title' => match ($type) {
                    'down' => '🔴 سایت قطع شد',
                    'up' => '🟢 سایت برقرار شد',
                    'slow' => '🟠 سایت کند است',
                    default => '⚪ رویداد',
                },
                'description' => $site['label'] ?: $site['target'],
                'color' => $color,
                'fields' => [
                    ['name' => 'آدرس', 'value' => $site['target']],
                    ['name' => 'زمان', 'value' => date('Y-m-d H:i:s')],
                ],
                'timestamp' => date('c'),
            ]],
        ];
    }
}

class IntegrationManager
{
    public static function register(int $userId, string $type, string $webhookUrl): bool
    {
        try {
            Db::q(
                'INSERT INTO `integrations` (user_id, type, webhook_url, enabled)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE webhook_url = ?, enabled = 1',
                [$userId, $type, $webhookUrl, $webhookUrl]
            );
            return true;
        } catch (Throwable $e) {
            uptimeLog('error', 'register integration: ' . $e->getMessage());
            return false;
        }
    }

    public static function test(int $userId, string $type): bool
    {
        $integrations = match ($type) {
            'slack' => new SlackIntegration(),
            'discord' => new DiscordIntegration(),
            default => null,
        };

        if (!$integrations) return false;

        $site = ['target' => 'test.example.com', 'label' => 'تست'];
        return $integrations->send($userId, 'up', $site);
    }

    public static function disable(int $userId, string $type): bool
    {
        Db::q(
            'UPDATE `integrations` SET enabled = 0 
             WHERE user_id = ? AND type = ?',
            [$userId, $type]
        );
        return true;
    }
}
