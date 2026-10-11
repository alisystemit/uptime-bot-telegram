<?php
/**
 * ===== لایهٔ ارتباط با API تلگرام (خودمختار؛ وابستگی به پوشهٔ src/ ربات‌ساز ندارد) =====
 * ویژگی‌ها: retry با backoff، مدیریت 429، پشتیبانی از proxy، لاگ خطاهای API.
 */
class BotApi
{
    private static ?string $proxy = null;
    private static int $maxRetries = 3;
    private static int $baseDelay = 200000; // 200ms
    /**
     * حالت آفلاین: هیچ درخواستی به تلگرام ارسال نمی‌شود.
     * برای dry-run و تست خودکار (جایی که فقط منطق کد مهم است).
     * با فعال‌شدن، call() بلافاصله پاسخ ساختگی موفق برمی‌گرداند.
     */
    private static bool $offline = false;

    public static function offline(bool $on = true): void
    {
        self::$offline = $on;
    }

    /** لاگ شکست API — هرگز توکن را در پیام نمیاورد */
    private static function logFail(string $method, string $detail): void
    {
        $line = sprintf("[%s] [ERROR] [telegram] %s failed: %s\n", date('Y-m-d H:i:s'), $method, $detail);
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function setProxy(?string $url): void
    {
        self::$proxy = $url;
    }

    public static function call(string $token, string $method, array $params = []): array
    {
        if ($token === '' || $method === '') return ['ok' => false, 'description' => 'empty token/method'];
        if (self::$offline) {
            return ['ok' => true, 'offline' => true, 'result' => ['message_id' => 0]];
        }
        if (!function_exists('curl_init')) { self::logFail($method, 'curl missing'); return ['ok' => false, 'description' => 'curl missing']; }
        $url = "https://api.telegram.org/bot{$token}/{$method}";
        $attempt = 0;
        $lastErr = '';

        $flat = [];
        foreach ($params as $k => $v) {
            if ($v === null) continue;
            if (is_array($v)) { $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($j === false) continue; $flat[$k] = $j; }
            elseif (is_bool($v)) $flat[$k] = $v ? 'true' : 'false';
            elseif (is_scalar($v)) $flat[$k] = $v;
        }
        $body = http_build_query($flat, '', '&');

        while ($attempt <= self::$maxRetries) {
            if ($attempt > 0) usleep(self::$baseDelay * (2 ** ($attempt - 1)));

            $ch = curl_init($url);
            if ($ch === false) { $lastErr = 'curl_init failed'; $attempt++; continue; }
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ];
            if (self::$proxy) $opts[CURLOPT_PROXY] = self::$proxy;
            curl_setopt_array($ch, $opts);
            $out = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // curl_close() از PHP 8.0 بی‌اثر (GC) و در 8.5 deprecated است
            unset($ch);

            if ($out === false) {
                $lastErr = $err;
                $attempt++;
                continue;
            }
            if ($httpCode === 429) {
                $j = json_decode($out, true) ?: [];
                $retry = (int)($j['parameters']['retry_after'] ?? 0);
                usleep($retry > 0 ? $retry * 1000000 : self::$baseDelay * (2 ** $attempt));
                $attempt++;
                continue;
            }
            $j = json_decode($out, true);
            if (!is_array($j)) {
                $lastErr = 'Invalid JSON response';
                $attempt++;
                continue;
            }
            if (!empty($j['ok'])) return $j;

            $description = (string)($j['description'] ?? 'Unknown error');
            if (stripos($description, 'retry') !== false) {
                $attempt++;
                continue;
            }
            self::logFail($method, 'error_code=' . ($j['error_code'] ?? '-') . ' — ' . $description);
            return $j;
        }

        self::logFail($method, 'Max retries exceeded — ' . $lastErr);
        return ['ok' => false, 'description' => 'Max retries exceeded: ' . $lastErr];
    }

    public static function getMe(string $token): array
    {
        return self::call($token, 'getMe');
    }

    public static function getWebhookInfo(string $token): array
    {
        return self::call($token, 'getWebhookInfo');
    }

    public static function setWebhook(string $token, string $url, ?string $secretToken = null): array
    {
        $p = ['url' => $url];
        if ($secretToken !== null && $secretToken !== '') $p['secret_token'] = $secretToken;
        return self::call($token, 'setWebhook', $p);
    }

    public static function deleteWebhook(string $token): array
    {
        return self::call($token, 'deleteWebhook', ['drop_pending_updates' => true]);
    }

    public static function send(string $token, $chatId, string $text, array $extra = []): array
    {
        if ((int)$chatId === 0 || trim($text) === '') return ['ok' => false, 'description' => 'empty chat/text'];
        $r = self::call($token, 'sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $extra));
        if (!is_array($r) || empty($r['ok'])) {
            self::logFail('sendMessage', 'chat=' . $chatId . ' — ' . (($r['description'] ?? '') ?: 'no response'));
        }
        return $r;
    }

    public static function answerCb(string $token, string $cbId, string $text = ''): void
    {
        if ($cbId === '') return;
        $r = self::call($token, 'answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => $text]);
        if (!is_array($r) || empty($r['ok'])) {
            self::logFail('answerCallbackQuery', (($r['description'] ?? '') ?: 'no response'));
        }
    }

    public static function edit(string $token, $chatId, $msgId, string $text, array $extra = []): array
    {
        if ((int)$chatId === 0 || (int)$msgId <= 0) return ['ok' => false, 'description' => 'empty chat/msg'];
        if (trim($text) === '') $text = '…';
        $r = self::call($token, 'editMessageText', array_merge([
            'chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text, 'parse_mode' => 'HTML',
        ], $extra));
        if (!is_array($r) || empty($r['ok'])) {
            self::logFail('editMessageText', (($r['description'] ?? '') ?: 'no response'));
        }
        return is_array($r) ? $r : ['ok' => false];
    }

    public static function setMyCommands(string $token, array $commands): array
    {
        return self::call($token, 'setMyCommands', [
            'commands' => array_map(fn($c) => ['command' => $c['command'], 'description' => $c['description'] ?? ''], $commands),
        ]);
    }

    public static function kb(array $rows, bool $oneTime = false): string
    {
        if (!$rows) return json_encode(['keyboard' => [], 'resize_keyboard' => true], JSON_UNESCAPED_UNICODE) ?: '{}';
        $j = json_encode(['keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => $oneTime], JSON_UNESCAPED_UNICODE);
        return $j === false ? '{}' : $j;
    }

    public static function ikb(array $rows): string
    {
        if (!$rows) return json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE) ?: '{}';
        $j = json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
        return $j === false ? '{}' : $j;
    }

    public static function removeKb(): string
    {
        return json_encode(['remove_keyboard' => true]);
    }

    /** ارسال تصویر/سند (multipart) — برای فیش پرداخت */
    public static function sendPhoto(string $token, $chatId, string $fileId, string $caption = '', ?string $replyMarkup = null): array
    {
        return self::call($token, 'sendPhoto', [
            'chat_id' => $chatId, 'photo' => $fileId,
            'caption' => $caption !== '' ? mb_substr($caption, 0, 1000) : null,
            'parse_mode' => $caption !== '' ? 'HTML' : null,
            'reply_markup' => $replyMarkup,
        ]);
    }
}
