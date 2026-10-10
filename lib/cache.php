<?php
/**
 * ===== کش Redis توزیع‌شده =====
 *
 * استفاده:
 *   Cache::get('user:123') ← دریافت
 *   Cache::set('user:123', $data, 3600) ← ذخیره
 *   Cache::del('user:123') ← حذف
 */

class Cache
{
    private static $redis = null;
    private static $enabled = false;

    /**
     * شروع Redis
     */
    public static function init(): void
    {
        $config = appConfig()['redis'] ?? null;
        if (!$config) {
            self::$enabled = false;
            return;
        }

        try {
            self::$redis = new Redis();
            self::$redis->connect(
                $config['host'] ?? 'localhost',
                (int)($config['port'] ?? 6379),
                2  // timeout
            );

            // تست اتصال
            if (self::$redis->ping() === true) {
                self::$enabled = true;
                self::$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
            }
        } catch (Throwable $e) {
            self::$enabled = false;
            uptimeLog('cache', 'Redis connection failed: ' . $e->getMessage());
        }
    }

    /**
     * دریافت از کش
     */
    public static function get(string $key, $default = null)
    {
        if (!self::$enabled) return $default;

        try {
            $val = self::$redis->get($key);
            return $val === false ? $default : $val;
        } catch (Throwable $e) {
            uptimeLog('cache_error', 'get: ' . $e->getMessage());
            return $default;
        }
    }

    /**
     * ذخیره در کش
     */
    public static function set(string $key, $value, int $ttl = 3600): bool
    {
        if (!self::$enabled) return false;

        try {
            return self::$redis->setex($key, $ttl, $value);
        } catch (Throwable $e) {
            uptimeLog('cache_error', 'set: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * افزایش مقدار (counter)
     */
    public static function increment(string $key, int $delta = 1): int
    {
        if (!self::$enabled) return 0;

        try {
            return self::$redis->incrBy($key, $delta);
        } catch (Throwable $e) {
            uptimeLog('cache_error', 'increment: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * حذف از کش
     */
    public static function del(string $key): bool
    {
        if (!self::$enabled) return false;

        try {
            return self::$redis->del($key) > 0;
        } catch (Throwable $e) {
            uptimeLog('cache_error', 'del: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * حذف تمام کلیدهای شروع‌کننده با pattern
     */
    public static function delPattern(string $pattern): int
    {
        if (!self::$enabled) return 0;

        try {
            $keys = self::$redis->keys($pattern);
            if (empty($keys)) return 0;
            return self::$redis->del(...$keys);
        } catch (Throwable $e) {
            uptimeLog('cache_error', 'delPattern: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * پاک‌کردن تمام کش
     */
    public static function flush(): bool
    {
        if (!self::$enabled) return false;

        try {
            return self::$redis->flushDB();
        } catch (Throwable $e) {
            uptimeLog('cache_error', 'flush: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * وضعیت کش
     */
    public static function status(): array
    {
        if (!self::$enabled) {
            return ['enabled' => false, 'status' => 'disconnected'];
        }

        try {
            $info = self::$redis->info();
            return [
                'enabled' => true,
                'status' => 'connected',
                'used_memory' => $info['used_memory_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 0,
            ];
        } catch (Throwable $e) {
            return ['enabled' => false, 'status' => 'error', 'error' => $e->getMessage()];
        }
    }
}

// شروع خودکار
Cache::init();
