<?php
/**
 * ===== ابزارهای دیباگ (Debug Utilities) =====
 * فقط برای توسعه و عیب‌یابی - در محیط پروداکشن می‌توان غیرفعال کرد
 */

class Debug
{
    /** فعال/غیرفعال کردن دیباگ */
    private static bool $enabled = false;
    
    /** ذخیره لاگ‌های دیباگ */
    private static array $logs = [];
    
    /** ماکزیمم تعداد لاگ در حافظه */
    private const MAX_MEMORY_LOGS = 1000;
    
    public static function enable(bool $enabled = true): void
    {
        self::$enabled = $enabled;
    }
    
    public static function isEnabled(): bool
    {
        return self::$enabled || (bool)($_ENV['UPTIME_DEBUG'] ?? getenv('UPTIME_DEBUG') ?? false);
    }
    
    /**
     * لاگ کردن دیباگ با سطح و متن
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }
        
        $entry = [
            'ts' => microtime(true),
            'datetime' => date('Y-m-d H:i:s.u'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
            'pid' => getmypid(),
            'memory' => memory_get_usage(true),
            'mem_peak' => memory_get_peak_usage(true),
        ];
        
        self::$logs[] = $entry;
        
        if (count(self::$logs) > self::MAX_MEMORY_LOGS) {
            array_shift(self::$logs);
        }
        
        // لاگ به فایل هم در صورت نیاز
        if (self::isEnabled()) {
            $logLine = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            @file_put_contents(
                UPTIME_ROOT . '/logs/debug_' . date('Y-m-d') . '.log',
                $logLine . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }
    
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }
    
    public static function warn(string $message, array $context = []): void
    {
        self::log('warn', $message, $context);
    }
    
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }
    
    /**
     * دیباگ پروب HTTP
     */
    public static function probeHttp(int $siteId, string $url, array $info = []): void
    {
        self::info("probe_http", [
            'site_id' => $siteId,
            'url' => $url,
            'curl_info' => $info,
        ]);
    }
    
    /**
     * دیباگ اجرای کرون
     */
    public static function cron(string $action, array $stats = []): void
    {
        self::info("cron_$action", $stats);
    }
    
    /**
     * گرفتن لاگ‌های حافظه
     */
    public static function getLogs(int $limit = 100): array
    {
        return array_slice(array_reverse(self::$logs), 0, $limit);
    }
    
    /**
     * پاک کردن لاگ‌های حافظه
     */
    public static function clear(): void
    {
        self::$logs = [];
    }
    
    /**
     * گرفتن وضعیت سیستم
     */
    public static function systemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'extensions' => [
                'curl' => extension_loaded('curl'),
                'pdo' => extension_loaded('pdo'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'openssl' => extension_loaded('openssl'),
                'json' => extension_loaded('json'),
                'mbstring' => extension_loaded('mbstring'),
            ],
            'functions' => [
                'proc_open' => function_exists('proc_open'),
                'stream_socket_client' => function_exists('stream_socket_client'),
                'curl_multi_init' => function_exists('curl_multi_init'),
            ],
            'disk' => [
                'free' => @disk_free_space(UPTIME_ROOT),
                'total' => @disk_total_space(UPTIME_ROOT),
            ],
            'uptime_root' => UPTIME_ROOT,
            'debug_enabled' => self::isEnabled(),
        ];
    }
}
