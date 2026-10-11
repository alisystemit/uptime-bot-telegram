<?php
/**
 * ===== لایهٔ دیتابیس ربات مانیتورینگ (MySQL/PDO) =====
 *
 * طراحی:
 *  - boot() با یک کوئری کوچک می‌فهمد جدول‌ها هستند؛ اگر نبودند migrate() اجرا می‌شود.
 *  - migrate() کاملاً idempotent است (CREATE TABLE IF NOT EXISTS) و هم از طریق
 *    table.php (نصب صریح) و هم خودکار در اولین درخواست قابل اجراست.
 *  - همهٔ کوئری‌ها prepared می‌شوند؛ ورودی کاربر هرگز داخل SQL نمی‌نشیند.
 */
class Db
{
    private static ?PDO $pdo = null;
    private static bool $booted = false;
    private static bool $traceOn = false;

    /** کانفیگ بارگذاری‌شده (توسط bootstrap تنظیم می‌شود) */
    private static array $cfg = [];

    public static function setConfig(array $cfg): void
    {
        self::$cfg = $cfg;
        self::$pdo = null;
        self::$booted = false;
    }

    public static function config(): array
    {
        return self::$cfg;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        // UPTIME_DEBUG=1 → شمارش کوئری‌ها برای سنجش کارایی
        if (!self::$traceOn) {
            $dbg = getenv('UPTIME_DEBUG');
            self::$traceOn = ($dbg !== false && $dbg !== '' && $dbg !== '0');
        }
        $db = self::$cfg['db'] ?? [];
        $host = (string)($db['host'] ?? '127.0.0.1');
        $port = (int)($db['port'] ?? 3306);
        $name = (string)($db['name'] ?? '');
        $dsn = "mysql:host={$host};port={$port}" . ($name !== '' ? ";dbname={$name}" : '') . ';charset=utf8mb4';
        self::$pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ]);
        // ساعت جلسه را UTC کن تا NOW()/CURRENT_TIMESTAMP دیتابیس با
        // date() خودِ PHP (UTC) یکی باشد؛ وگرگ‌شدن ۳٫۵ ساعته پیش می‌آید.
        try {
            self::$pdo->exec("SET time_zone = '+00:00'");
        } catch (Throwable $e) {
            // بعضی هاست‌ها اجازه نمی‌دهند؛ فقط لاگ کن و ادامه بده
            @error_log('[uptime] SET time_zone failed: ' . $e->getMessage());
        }
        return self::$pdo;
    }

    /**
     * آماده‌سازی اتصال + جدول‌ها.
     *
     * اگر جدول settings نبود → migrate کامل (ساخت همهٔ جدول‌ها).
     * اگر جدول بود → فقط ارتقای ستون‌ها و کاشت تنظیمات پیش‌فرض انجام می‌شود؛
     * چون نصب‌های قدیمی جدول را دارند ولی ستون/تنظیم جدید را نه.
     * هر دو مسیر idempotent و کم‌هزینه‌اند (یک SHOW COLUMNS و چند INSERT IGNORE).
     */
    public static function boot(): void
    {
        if (self::$booted) return;
        self::$settingsCache = [];
        $pdo = self::pdo();
        try {
            $pdo->query('SELECT 1 FROM `settings` LIMIT 1');
            // جدول از قبل هست → فقط ارتقاها (ستون‌های جدید + تنظیمات پیش‌فرض)
            self::syncColumns();
            self::seedDefaults();
        } catch (PDOException $e) {
            self::migrate();
        }
        self::$booted = true;
    }

    /** ساخت جدول‌ها — امن برای اجرای چندباره */
    public static function migrate(): void
    {
        $pdo = self::pdo();
        foreach (self::schema() as $sql) $pdo->exec($sql);
        self::syncColumns();
        self::seedDefaults();
        self::$booted = true;
    }

    // ---------- ارتقای ستون‌ها/ایندکس‌ها ----------

    /** ستون‌های موجود یک جدول: نام کوچک‌شدهٔ ستون => نوع */
    private static function columns(string $table): array
    {
        $out = [];
        try {
            $st = self::pdo()->query('SHOW COLUMNS FROM `' . $table . '`');
            foreach ($st === false ? [] : $st->fetchAll() as $r) {
                $out[strtolower((string)$r['Field'])] = (string)($r['Type'] ?? '');
            }
        } catch (Throwable $e) {
            // جدول هنوز وجود ندارد؛ نتیجهٔ خالی یعنی «ساخته نشده»
        }
        return $out;
    }

    /**
     * آیا ستونی وجود دارد؟ نتیجه در هر پروسه یک‌بار پرس‌وجو می‌شود.
     * برای ویژگی‌های اختیاری (که ممکن است روی نصب قدیمی ساخته نشده باشند)
     * تا هزینهٔ یک کوئری در هر بار اجرا نداشته باشند.
     */
    public static function hasColumn(string $table, string $col): bool
    {
        static $cache = [];
        $key = $table . '.' . $col;
        if (!isset($cache[$key])) {
            $cols = self::columns($table);
            $cache[$key] = (bool)$cols && isset($cols[strtolower($col)]);
        }
        return $cache[$key];
    }

    /**
     * افزودن یک ستون در صورت نبودن (idempotent).
     * برای نصب‌های قدیمی که جدول از قبل ساخته شده استفاده می‌شود.
     */
    public static function ensureColumn(string $table, string $col, string $ddl): bool
    {
        static $done = [];
        $key = $table . '.' . $col;
        if (isset($done[$key])) return false;
        $have = self::columns($table);
        if (!$have) { return false; }               // جدول نیست؛ CREATE TABLE خودش کامل است
        if (isset($have[strtolower($col)])) { $done[$key] = true; return false; }
        try {
            self::pdo()->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $col . '` ' . $ddl);
            $done[$key] = true;
            return true;
        } catch (Throwable $e) {
            @error_log('[uptime] ADD COLUMN ' . $key . ' failed: ' . $e->getMessage());
            $done[$key] = true;
            return false;
        }
    }

    /** ایندکس‌های یک جدول: نام => تعداد ستون */
    private static function indexes(string $table): array
    {
        $out = [];
        try {
            $st = self::pdo()->query('SHOW INDEX FROM `' . $table . '`');
            foreach ($st === false ? [] : $st->fetchAll() as $r) {
                $k = strtolower((string)$r['Key_name']);
                $out[$k] = ($out[$k] ?? 0) + 1;
            }
        } catch (Throwable $e) {
            // بی‌اهمیت
        }
        return $out;
    }

    /**
     * ستون‌ها و ایندکس‌های نسخه‌های جدیدتر روی جدول‌های قدیمی.
     * چون CREATE TABLE IF NOT EXISTS روی جدول موجود هیچ کاری نمی‌کند،
     * این متد بعد از schema اجرا می‌شود.
     */
    private static function syncColumns(): void
    {
        // ---------- user: ستون‌های رنکینگ/امتیاز ----------
        // قبلاً فقط init_ranking.php این‌ها را می‌ساخت؛ آن فایل با مایگریشن
        // ادغام شد تا نصب تازه بدون هیچ اسکریپت دستی، امتیازدهی داشته باشد.
        $userCols = [
            'user_rank'         => 'INT UNSIGNED NOT NULL DEFAULT 1',
            'user_points'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'site_uptime_count' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        ];
        foreach ($userCols as $c => $ddl) self::ensureColumn('user', $c, $ddl);

        // ---------- site: مانیتور گروهی، اشتراک، کلیدواژه، آستانهٔ کندی، SSL ----------
        $siteCols = [
            'chat_id'        => 'BIGINT NOT NULL DEFAULT 0',
            'chat_title'     => "VARCHAR(160) NOT NULL DEFAULT ''",
            'notify_chat'    => 'TINYINT(1) NOT NULL DEFAULT 1',
            'share_token'    => "VARCHAR(32) NOT NULL DEFAULT ''",
            'keyword'        => "VARCHAR(190) NOT NULL DEFAULT ''",
            'max_ms'         => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'slow'           => 'TINYINT(1) NOT NULL DEFAULT 0',
            'slow_alerted'   => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ssl_check_at'   => 'DATETIME NULL DEFAULT NULL',
            'ssl_days'       => 'INT NOT NULL DEFAULT -1',
            'ssl_expires_at' => 'DATETIME NULL DEFAULT NULL',
            'ssl_issuer'     => "VARCHAR(160) NOT NULL DEFAULT ''",
            'ssl_error'      => "VARCHAR(190) NOT NULL DEFAULT ''",
            'ssl_warn_days'  => 'INT UNSIGNED NOT NULL DEFAULT 14',
            'ssl_notified'   => "VARCHAR(40) NOT NULL DEFAULT ''",
            'resp_avg'       => 'INT NOT NULL DEFAULT 0',
            'resp_max'       => 'INT NOT NULL DEFAULT 0',
            'resp_p95'       => 'INT NOT NULL DEFAULT 0',
            // مکان‌یاب دستی انقضای دامنه (مکمل domain_watch؛ WHOIS منبع اصلی است)
            'domain_expiry'   => 'DATETIME NULL DEFAULT NULL',
        ];
        foreach ($siteCols as $c => $ddl) self::ensureColumn('site', $c, $ddl);

        // یکتایی هدف باید «کاربر + چت» باشد تا یک سایت در گروه و به‌طور خصوصی
        // برای یک نفر تکراری محسوب نشود.
        try {
            $idx = self::indexes('site');
            if (isset($idx['uniq_user_target']) && $idx['uniq_user_target'] < 3) {
                self::pdo()->exec('ALTER TABLE `site` DROP INDEX `uniq_user_target`');
                $idx = self::indexes('site');
            }
            if (!isset($idx['uniq_user_target'])) {
                self::pdo()->exec('ALTER TABLE `site` ADD UNIQUE KEY `uniq_user_target` (`user_id`, `chat_id`, `target`(120))');
            }
            if (!isset($idx['idx_chat'])) {
                self::pdo()->exec('ALTER TABLE `site` ADD KEY `idx_chat` (`chat_id`, `paused`)');
            }
        } catch (Throwable $e) {
            @error_log('[uptime] site index upgrade failed: ' . $e->getMessage());
        }

        // ---------- payments: ستون‌های درگاه پرداخت ----------
        // روی نصب‌های قدیمی که جدول payments از قبل ساخته شده اضافه می‌شود.
        $payCols = [
            'gateway'     => "VARCHAR(24) NOT NULL DEFAULT ''",
            'channel'     => "VARCHAR(10) NOT NULL DEFAULT ''",   // card | crypto | bank | manual
            'ref_id'      => "VARCHAR(120) NOT NULL DEFAULT ''",  // authority / invoice_id / slug
            'pay_url'     => "VARCHAR(500) NOT NULL DEFAULT ''",
            'card_number' => "VARCHAR(32) NOT NULL DEFAULT ''",
            'card_holder' => "VARCHAR(80) NOT NULL DEFAULT ''",
            'pay_amount'  => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'expires_at'  => 'DATETIME NULL DEFAULT NULL',
            'verified_at' => 'DATETIME NULL DEFAULT NULL',
            'raw'         => 'TEXT NULL DEFAULT NULL',
        ];
        foreach ($payCols as $c => $ddl) self::ensureColumn('payments', $c, $ddl);
        
        // pay_url را به 1000 کاراکتر بسط بده (برای URL‌های پرداخت طولانی)
        try {
            $cols = self::pdo()->query("SHOW COLUMNS FROM `payments` WHERE `Field` = 'pay_url'")->fetchAll(PDO::FETCH_ASSOC);
            if ($cols && isset($cols[0]['Type'])) {
                $type = $cols[0]['Type'];
                if (!str_contains($type, '1000')) {
                    self::pdo()->exec("ALTER TABLE `payments` MODIFY COLUMN `pay_url` VARCHAR(1000) NOT NULL DEFAULT ''");
                }
            }
        } catch (Throwable $e) {
            @error_log('[uptime] payments pay_url upgrade failed: ' . $e->getMessage());
        }
        
        // ---------- pay_gateway: ستون‌های کلید API ----------
        $gwCols = [
            'api_key' => 'VARCHAR(500) NOT NULL DEFAULT \'\'',
            'secret'  => 'VARCHAR(500) NOT NULL DEFAULT \'\'',
        ];
        foreach ($gwCols as $c => $ddl) self::ensureColumn('pay_gateway', $c, $ddl);
        self::ensureColumn('pay_gateway', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        try {
            $pidx = self::indexes('payments');
            if (!isset($pidx['idx_gateway_ref'])) {
                self::pdo()->exec('ALTER TABLE `payments` ADD KEY `idx_gateway_ref` (`gateway`, `ref_id`)');
            }
        } catch (Throwable $e) {
            @error_log('[uptime] payments index upgrade failed: ' . $e->getMessage());
        }
    }

    /** آیا جدول‌ها ساخته شده‌اند؟ (برای table.php) */
    public static function ready(): bool
    {
        try {
            self::pdo()->query('SELECT 1 FROM `settings` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function schema(): array
    {
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci';
        return [
            // ===== کاربران =====
            // نام جدول «user» عمداً با ربات‌ساز یکی است تا «پیام همگانی» و «آمار»
            // ربات‌ساز (childUsers/childCount) روی این قالب هم کار کند.
            "CREATE TABLE IF NOT EXISTS `user` (
                `id` BIGINT UNSIGNED NOT NULL,
                `name` VARCHAR(120) NOT NULL DEFAULT '',
                `username` VARCHAR(64) NOT NULL DEFAULT '',
                `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
                `access` TINYINT(1) NOT NULL DEFAULT 1,
                `plan` VARCHAR(10) NOT NULL DEFAULT 'free',
                `plan_until` DATETIME NULL DEFAULT NULL,
                `notify` TINYINT(1) NOT NULL DEFAULT 1,
                `paused` TINYINT(1) NOT NULL DEFAULT 0,
                `paused_since` DATETIME NULL DEFAULT NULL,
                `paused_total` INT UNSIGNED NOT NULL DEFAULT 0,
                `share_token` VARCHAR(32) NOT NULL DEFAULT '',
                `step` VARCHAR(40) NOT NULL DEFAULT 'idle',
                `temp` TEXT NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_share` (`share_token`),
                KEY `idx_access` (`access`)
            )" . $engine,

            // ===== سایت‌های تحت نظر =====
            // chat_id = 0 یعنی مانیتور خصوصیِ کاربر؛ هر مقدار دیگری یعنی
            // مانیتورِ مشترک در گروه/کانال (سقف جداگانه، جدا از max_sites).
            // توجه: شناسهٔ گروه/کانال در تلگرام منفی است.
            "CREATE TABLE IF NOT EXISTS `site` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `chat_id` BIGINT NOT NULL DEFAULT 0,
                `chat_title` VARCHAR(160) NOT NULL DEFAULT '',
                `target` VARCHAR(255) NOT NULL,
                `label` VARCHAR(200) NOT NULL DEFAULT '',
                `type` VARCHAR(8) NOT NULL DEFAULT 'http',
                `host` VARCHAR(253) NOT NULL DEFAULT '',
                `port` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `paused` TINYINT(1) NOT NULL DEFAULT 0,
                `paused_since` DATETIME NULL DEFAULT NULL,
                `paused_total` INT UNSIGNED NOT NULL DEFAULT 0,
                `notify_chat` TINYINT(1) NOT NULL DEFAULT 1,
                `status` VARCHAR(10) NOT NULL DEFAULT 'unknown',
                `last_check_at` DATETIME NULL DEFAULT NULL,
                `last_ms` INT NOT NULL DEFAULT 0,
                `last_code` SMALLINT NOT NULL DEFAULT 0,
                `last_error` VARCHAR(190) NOT NULL DEFAULT '',
                `consecutive_fail` INT NOT NULL DEFAULT 0,
                `down_alerted` TINYINT(1) NOT NULL DEFAULT 0,
                `last_alert_at` DATETIME NULL DEFAULT NULL,
                `last_up_at` DATETIME NULL DEFAULT NULL,
                `last_down_at` DATETIME NULL DEFAULT NULL,
                `last_down_duration` INT UNSIGNED NOT NULL DEFAULT 0,
                `total_checks` INT UNSIGNED NOT NULL DEFAULT 0,
                `total_fails` INT UNSIGNED NOT NULL DEFAULT 0,
                `slow` TINYINT(1) NOT NULL DEFAULT 0,
                `slow_alerted` TINYINT(1) NOT NULL DEFAULT 0,
                `max_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `keyword` VARCHAR(190) NOT NULL DEFAULT '',
                `share_token` VARCHAR(32) NOT NULL DEFAULT '',
                `ssl_check_at` DATETIME NULL DEFAULT NULL,
                `ssl_days` INT NOT NULL DEFAULT -1,
                `ssl_expires_at` DATETIME NULL DEFAULT NULL,
                `ssl_issuer` VARCHAR(160) NOT NULL DEFAULT '',
                `ssl_error` VARCHAR(190) NOT NULL DEFAULT '',
                `ssl_warn_days` INT UNSIGNED NOT NULL DEFAULT 14,
                `ssl_notified` VARCHAR(40) NOT NULL DEFAULT '',
                `resp_avg` INT NOT NULL DEFAULT 0,
                `resp_max` INT NOT NULL DEFAULT 0,
                `resp_p95` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_target` (`user_id`, `chat_id`, `target`(120)),
                KEY `idx_due` (`paused`, `last_check_at`),
                KEY `idx_user` (`user_id`),
                KEY `idx_chat` (`chat_id`, `paused`)
            )" . $engine,

            // ===== لاگ چک‌ها (فقط ۲۴ ساعت اخیر؛ برای نوار تاریخچه و اسپارک‌لاین) =====
            "CREATE TABLE IF NOT EXISTS `check_log` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `ts` DATETIME NOT NULL,
                `ok` TINYINT(1) NOT NULL DEFAULT 0,
                `ms` INT NOT NULL DEFAULT 0,
                `code` SMALLINT NOT NULL DEFAULT 0,
                `error` VARCHAR(190) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `idx_site_ts` (`site_id`, `ts`)
            )" . $engine,

            // ===== آپتایم تجمیعی ساعتی (۷/۳۰ روز) =====
            "CREATE TABLE IF NOT EXISTS `uptime_hour` (
                `site_id` INT UNSIGNED NOT NULL,
                `bucket` DATETIME NOT NULL,
                `checks` INT UNSIGNED NOT NULL DEFAULT 0,
                `ok` INT UNSIGNED NOT NULL DEFAULT 0,
                `total_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`site_id`, `bucket`),
                KEY `idx_bucket` (`bucket`)
            )" . $engine,

            // ===== تنظیمات کلید/مقدار =====
            "CREATE TABLE IF NOT EXISTS `settings` (
                `k` VARCHAR(64) NOT NULL,
                `v` TEXT NULL DEFAULT NULL,
                PRIMARY KEY (`k`)
            )" . $engine,

            // ===== کدهای فعال‌سازی =====
            "CREATE TABLE IF NOT EXISTS `codes` (
                `code` VARCHAR(32) NOT NULL,
                `days` INT UNSIGNED NOT NULL DEFAULT 30,
                `uses` INT UNSIGNED NOT NULL DEFAULT 0,
                `uses_max` INT UNSIGNED NOT NULL DEFAULT 1,
                `note` VARCHAR(120) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`code`)
            )" . $engine,

            // ===== رویدادها (توقف/ادامه، قطعی، پرداخت…) برای آمار =====
            "CREATE TABLE IF NOT EXISTS `events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `kind` VARCHAR(32) NOT NULL,
                `detail` VARCHAR(255) NOT NULL DEFAULT '',
                `ts` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_kind_ts` (`kind`, `ts`),
                KEY `idx_user` (`user_id`)
            )" . $engine,

            // ===== پرداخت‌ها / درخواست‌های اشتراک =====
            "CREATE TABLE IF NOT EXISTS `payments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
                `note` VARCHAR(255) NOT NULL DEFAULT '',
                `media_file_id` VARCHAR(160) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `decided_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_status` (`status`),
                KEY `idx_user` (`user_id`)
            )" . $engine,

            // ===== درگاه‌های پرداخت =====
            // هر درگاه یک ردیف دارد؛ کلید/توکن‌ها فقط اینجا نگهداری می‌شوند.
            "CREATE TABLE IF NOT EXISTS `pay_gateway` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(24) NOT NULL,
                `title` VARCHAR(60) NOT NULL DEFAULT '',
                `enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `kind` VARCHAR(10) NOT NULL DEFAULT 'card',
                `api_key` VARCHAR(500) NOT NULL DEFAULT '',
                `secret` VARCHAR(500) NOT NULL DEFAULT '',
                `merchant_id` VARCHAR(120) NOT NULL DEFAULT '',
                `base_url` VARCHAR(190) NOT NULL DEFAULT '',
                `settings` TEXT NULL DEFAULT NULL,
                `sort` INT NOT NULL DEFAULT 0,
                `last_error` VARCHAR(190) NOT NULL DEFAULT '',
                `last_used` DATETIME NULL DEFAULT NULL,
                `ok_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `fail_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_code` (`code`),
                KEY `idx_enabled` (`enabled`, `sort`)
            )" . $engine,

            // ===== آپدیت‌های پردازش‌شده (جلوگیری از دوباره‌کاری تلگرام) =====
            "CREATE TABLE IF NOT EXISTS `seen_update` (
                `update_id` BIGINT UNSIGNED NOT NULL,
                `ts` DATETIME NOT NULL,
                PRIMARY KEY (`update_id`)
            )" . $engine,

            // ===== گروه‌ها و کانال‌های متصل به ربات =====
            "CREATE TABLE IF NOT EXISTS `chat_hub` (
                `chat_id` BIGINT NOT NULL,
                `chat_type` VARCHAR(12) NOT NULL DEFAULT 'group',
                `title` VARCHAR(160) NOT NULL DEFAULT '',
                `share_token` VARCHAR(32) NOT NULL DEFAULT '',
                `notify` TINYINT(1) NOT NULL DEFAULT 1,
                `mention` VARCHAR(10) NOT NULL DEFAULT 'admins',
                `max_sites` INT UNSIGNED NOT NULL DEFAULT 0,
                `step` VARCHAR(40) NOT NULL DEFAULT 'idle',
                `step_user` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `temp` TEXT NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_active` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`chat_id`),
                KEY `idx_share` (`share_token`)
            )" . $engine,

            // ===== اعضای ادمینِ گروه (کش برای تشخیص دسترسی بدون تماس با API) =====
            "CREATE TABLE IF NOT EXISTS `chat_admin` (
                `chat_id` BIGINT NOT NULL,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `username` VARCHAR(64) NOT NULL DEFAULT '',
                `checked_at` DATETIME NOT NULL,
                PRIMARY KEY (`chat_id`, `user_id`)
            )" . $engine,

            // ===== رخدادها (قطعی/کندی/SSL) با علت و مدت =====
            "CREATE TABLE IF NOT EXISTS `incident` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `kind` VARCHAR(12) NOT NULL DEFAULT 'down',
                `start_at` DATETIME NOT NULL,
                `end_at` DATETIME NULL DEFAULT NULL,
                `duration` INT UNSIGNED NOT NULL DEFAULT 0,
                `reason` VARCHAR(190) NOT NULL DEFAULT '',
                `peak_ms` INT NOT NULL DEFAULT 0,
                `checks` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_site_start` (`site_id`, `start_at`),
                KEY `idx_open` (`site_id`, `kind`, `end_at`),
                KEY `idx_start` (`start_at`)
            )" . $engine,

            // ===== اشتراک یک مانیتور با افراد دیگر (دعوت) =====
            "CREATE TABLE IF NOT EXISTS `site_share` (
                `site_id` INT UNSIGNED NOT NULL,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `role` VARCHAR(10) NOT NULL DEFAULT 'viewer',
                `notify` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`site_id`, `user_id`),
                KEY `idx_user` (`user_id`)
            )" . $engine,

            // ===== پایش انقضای دامنه (WHOIS) =====
            "CREATE TABLE IF NOT EXISTS `domain_watch` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `chat_id` BIGINT NOT NULL DEFAULT 0,
                `domain` VARCHAR(253) NOT NULL,
                `warn_days` INT UNSIGNED NOT NULL DEFAULT 14,
                `expires_at` DATETIME NULL DEFAULT NULL,
                `registrar` VARCHAR(160) NOT NULL DEFAULT '',
                `status` VARCHAR(10) NOT NULL DEFAULT 'unknown',
                `last_check` DATETIME NULL DEFAULT NULL,
                `last_error` VARCHAR(190) NOT NULL DEFAULT '',
                `notified_at` DATETIME NULL DEFAULT NULL,
                `notified_exp` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_scope` (`user_id`, `chat_id`, `domain`(120)),
                KEY `idx_domain` (`domain`)
            )" . $engine,

            // ===== Webhook‌های شخصی‌سازی =====
            "CREATE TABLE IF NOT EXISTS `webhooks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `site_id` INT UNSIGNED NOT NULL,
                `type` VARCHAR(24) NOT NULL,
                `url` VARCHAR(1000) NOT NULL,
                `events` VARCHAR(255) NOT NULL DEFAULT 'down,up,slow',
                `secret` VARCHAR(190) NOT NULL DEFAULT '',
                `sign_algo` VARCHAR(20) NOT NULL DEFAULT 'sha256',
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `test_at` DATETIME NULL,
                `last_used` DATETIME NULL,
                `fail_count` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_site` (`site_id`)
            )" . $engine,

            // ===== گزارش‌های SLA =====
            "CREATE TABLE IF NOT EXISTS `sla_reports` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `period_start` DATE NOT NULL,
                `period_end` DATE NOT NULL,
                `type` VARCHAR(10) NOT NULL,
                `data_json` LONGTEXT NOT NULL,
                `sent_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`, `created_at`)
            )" . $engine,

            // ===== صف کارها (Queue) =====
            "CREATE TABLE IF NOT EXISTS `queue` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type` VARCHAR(40) NOT NULL,
                `data` LONGTEXT NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `result` LONGTEXT NULL,
                `error` VARCHAR(500) NULL,
                `attempts` INT NOT NULL DEFAULT 0,
                `last_error` VARCHAR(500) NULL,
                `priority` INT NOT NULL DEFAULT 5,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `started_at` DATETIME NULL,
                `completed_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_status` (`status`, `priority`, `created_at`)
            )" . $engine,

            // ===== Integration‌های خارجی (Slack, Discord) =====
            "CREATE TABLE IF NOT EXISTS `integrations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `type` VARCHAR(20) NOT NULL,
                `webhook_url` VARCHAR(1000) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `settings` JSON NULL,
                `test_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_type` (`user_id`, `type`)
            )" . $engine,

            // ===== برنامهٔ همکاری =====
            "CREATE TABLE IF NOT EXISTS `affiliates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `code` VARCHAR(10) UNIQUE NOT NULL,
                `commission_rate` INT NOT NULL DEFAULT 20,
                `signup_count` INT NOT NULL DEFAULT 0,
                `total_commission` BIGINT NOT NULL DEFAULT 0,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `last_signup` DATETIME NULL,
                `payout_method` VARCHAR(20) NULL,
                `payout_info` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user` (`user_id`),
                KEY `idx_code` (`code`)
            )" . $engine,

            // ===== درآمدهای affiliate =====
            "CREATE TABLE IF NOT EXISTS `affiliate_earnings` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `affiliate_id` INT UNSIGNED NOT NULL,
                `amount` BIGINT NOT NULL,
                `commission` BIGINT NOT NULL,
                `type` VARCHAR(20) NOT NULL DEFAULT 'referral_payment',
                `payment_id` INT UNSIGNED NOT NULL,
                `payout_id` INT UNSIGNED NULL,
                `paid_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_affiliate` (`affiliate_id`, `paid_at`)
            )" . $engine,

            // ===== درخواست‌های پرداخت affiliate =====
            "CREATE TABLE IF NOT EXISTS `affiliate_payouts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `affiliate_id` INT UNSIGNED NOT NULL,
                `amount` BIGINT NOT NULL,
                `method` VARCHAR(20) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `reference` VARCHAR(255) NULL,
                `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `completed_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_affiliate` (`affiliate_id`, `status`)
            )" . $engine,
        ];
    }

    /** مقادیر پیش‌فرض از config['defaults'] — فقط اگر وجود نداشته باشند */
    private static function seedDefaults(): void
    {
        $defaults = self::$cfg['defaults'] ?? [];
        if (!$defaults) return;
        try {
            $st = self::pdo()->prepare('INSERT IGNORE INTO `settings` (`k`,`v`) VALUES (?,?)');
            foreach ($defaults as $k => $v) $st->execute([(string)$k, (string)$v]);
        } catch (Throwable $e) {
            @error_log('[uptime] seedDefaults failed: ' . $e->getMessage());
        }
        // کشِ تنظیمات ممکن است قبل از کاشت پر شده باشد
        self::$settingsCache = [];
    }

    // ---------- ابزارهای کوئری ----------

    /**
     * شمارندهٔ کوئری — فقط وقتی فعال است که UPTIME_DEBUG=1 باشد.
     * برای سنجش کارایی و پیدا کردن N+1 استفاده می‌شود.
     * @var array{queries:int, time:float, log:array<int,array{0:string,1:float}>}
     */
    private static array $stats = ['queries' => 0, 'time' => 0.0, 'log' => []];

    public static function debug(bool $on = true): void
    {
        self::$traceOn = $on;
        self::$stats = ['queries' => 0, 'time' => 0.0, 'log' => $on ? [] : self::$stats['log']];
    }

    public static function stats(): array
    {
        return self::$stats;
    }

    /** خلاصهٔ کوئری‌های تکراری (برای یافتن N+1) */
    public static function statsTop(int $limit = 12): array
    {
        $agg = [];
        foreach (self::$stats['log'] as [$sql, $ms]) {
            $k = preg_replace('/\s+/', ' ', $sql);
            $k = mb_substr((string)$k, 0, 110);
            if (!isset($agg[$k])) $agg[$k] = ['n' => 0, 'ms' => 0.0];
            $agg[$k]['n']++;
            $agg[$k]['ms'] += $ms;
        }
        uasort($agg, static fn($a, $b) => $b['n'] <=> $a['n']);
        return array_slice($agg, 0, $limit, true);
    }

    private static function trace(string $sql, float $ms): void
    {
        self::$stats['queries']++;
        self::$stats['time'] += $ms;
        if (count(self::$stats['log']) < 3000) self::$stats['log'][] = [$sql, $ms];
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $t = self::$traceOn ? microtime(true) : 0.0;
        try {
            $st = self::pdo()->prepare($sql);
            $st->execute($params);
        } finally {
            if (self::$traceOn) self::trace($sql, microtime(true) - $t);
        }
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::q($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = [], $default = null)
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    // ---------- تنظیمات ----------

    /**
     * کشِ درون‌پروسه‌ای تنظیمات.
     *
     * قبل از این، هر فراخوانی Db::get()/getInt()/getBool() یک SELECT جدا می‌زد؛
     * چون Monitor::record() برای هر سایت چند بار همین تنظیمات را می‌خواند،
     * یک راند با ۵۰ سایت صدها کوئری تکراری می‌زد. حالا هر کلید یک‌بار
     * خوانده می‌شود و Db::set() همان کلید را از کش بیرون می‌اندازد.
     * @var array<string,?string>
     */
    private static array $settingsCache = [];

    /** بیرون انداختن کل کش (بعد از migrate یا تغییر گسترده) */
    public static function flushSettings(): void
    {
        self::$settingsCache = [];
    }

    public static function get(string $k, ?string $default = null): ?string
    {
        if (array_key_exists($k, self::$settingsCache)) {
            return self::$settingsCache[$k] ?? $default;
        }
        $v = self::val('SELECT `v` FROM `settings` WHERE `k` = ?', [$k]);
        self::$settingsCache[$k] = $v === null ? null : (string)$v;
        return $v === null ? $default : $v;
    }

    public static function getInt(string $k, int $default = 0): int
    {
        $v = self::get($k);
        return $v === null || $v === '' ? $default : (int)$v;
    }

    public static function getBool(string $k, bool $default = false): bool
    {
        $v = self::get($k);
        if ($v === null) return $default;
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $k, string $v): void
    {
        self::q('INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)', [$k, $v]);
        self::$settingsCache[$k] = $v;
    }

    public static function allSettings(): array
    {
        $out = [];
        foreach (self::all('SELECT `k`,`v` FROM `settings`') as $r) {
            $out[$r['k']] = $r['v'];
            self::$settingsCache[(string)$r['k']] = (string)$r['v'];
        }
        return $out;
    }

    // ---------- رویدادها ----------

    public static function logEvent(int $userId, string $kind, string $detail = ''): void
    {
        try {
            self::q('INSERT INTO `events` (`user_id`,`kind`,`detail`,`ts`) VALUES (?,?,?,NOW())', [$userId, $kind, mb_substr($detail, 0, 255)]);
        } catch (Throwable $e) {
            // ثبت رویداد هرگز نباید مسیر اصلی را بشکند
        }
    }
}
