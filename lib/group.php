<?php
/**
 * ===== گروه‌ها و کانال‌های متصل به ربات =====
 *
 * هر گروه/کانال یک ردیف در جدول chat_hub دارد:
 *   - وضعیت مرحله‌ای (برای /add بدون آرگومان)
 *   - توکن لینک وضعیت عمومی
 *   - قواعد اعلان (اعلان روشن/خاموش، منشن ادمین‌ها)
 *   - سقف اختصاصی مانیتور (۰ یعنی از تنظیمات سراسری group_max_sites)
 *
 * دسترسی مدیریت فقط برای ادمین‌های همان گروه است. برای اینکه با هر پیام
 * یک درخواست به API تلگرام نرود، نتیجهٔ getChatMember یک ساعت کش می‌شود
 * (جدول chat_admin).
 */
class Group
{
    /** طول عمر کش عضویت ادمین (ثانیه) */
    private const ADMIN_TTL = 3600;

    public const TYPES = ['group', 'supergroup', 'channel'];

    /**
     * آیا این chat_id یک گروه/کانال است؟
     *
     * نکتهٔ مهم: شناسهٔ گروه و کانال در تلگرام <b>منفی</b> است (مثل -100123…)؛
     * بنابراین معیار درست «مخالف صفر بودن» است، نه «بزرگ‌تر از صفر».
     * فقط chat_id = 0 مانیتور خصوصیِ کاربر است.
     */
    public static function isGroupChat($chatId): bool
    {
        return (int)$chatId !== 0;
    }

    // ---------------------------------------------------------------- رجیستر

    /** ثبت/به‌روزرسانی گروه یا کانال و برگرداندن ردیف آن */
    public static function touch(int $chatId, string $type, string $title): array
    {
        if (!in_array($type, self::TYPES, true)) $type = 'group';
        $title = truncateFa($title, 150);
        $row = Db::one('SELECT * FROM `chat_hub` WHERE `chat_id` = ?', [$chatId]);
        if ($row) {
            Db::q('UPDATE `chat_hub` SET `chat_type` = ?, `title` = ?, `last_active` = NOW() WHERE `chat_id` = ?', [$type, $title, $chatId]);
            $row['chat_type'] = $type;
            $row['title'] = $title !== '' ? $title : (string)$row['title'];
            $row['last_active'] = date('Y-m-d H:i:s');
            return $row;
        }
        $token = uniqueToken(20, static fn(string $t): bool => (int)Db::val('SELECT COUNT(*) FROM `chat_hub` WHERE `share_token` = ?', [$t]) > 0);
        Db::q(
            'INSERT INTO `chat_hub` (`chat_id`,`chat_type`,`title`,`share_token`,`created_at`,`last_active`) VALUES (?,?,?,?,NOW(),NOW())',
            [$chatId, $type, $title, $token]
        );
        return (array)Db::one('SELECT * FROM `chat_hub` WHERE `chat_id` = ?', [$chatId]);
    }

    public static function get(int $chatId): ?array
    {
        if ($chatId === 0) return null;
        return Db::one('SELECT * FROM `chat_hub` WHERE `chat_id` = ?', [$chatId]);
    }

    public static function byToken(string $token): ?array
    {
        if ($token === '') return null;
        return Db::one('SELECT * FROM `chat_hub` WHERE `share_token` = ?', [$token]);
    }

    /** لینک صفحهٔ وضعیت عمومی یک گروه/کانال */
    public static function statusUrl(array $cfg, array $hub): string
    {
        $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
        if ($base === '') $base = 'https://' . trim((string)($cfg['domain'] ?? ''));
        return $base . '/status.php?g=' . rawurlencode((string)$hub['share_token']);
    }

    // ---------------------------------------------------------------- سقف

    public static function maxSites(array $hub): int
    {
        $own = (int)($hub['max_sites'] ?? 0);
        if ($own > 0) return $own;
        return max(1, Db::getInt('group_max_sites', 10));
    }

    public static function count(int $chatId): int
    {
        return (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `chat_id` = ?', [$chatId]);
    }

    public static function sites(int $chatId): array
    {
        return Db::all('SELECT * FROM `site` WHERE `chat_id` = ? ORDER BY `id` ASC', [$chatId]);
    }

    public static function site(int $chatId, int $siteId): ?array
    {
        return Db::one('SELECT * FROM `site` WHERE `chat_id` = ? AND `id` = ?', [$chatId, $siteId]);
    }

    // ---------------------------------------------------------------- وضعیت مرحله‌ای

    public static function setStep(int $chatId, string $step, int $userId = 0, array $temp = []): void
    {
        // upsert لازم است: قبلاً فقط UPDATE بود و اگر ردیف گروه هنوز ساخته
        // نشده بود (مثلاً اولین پیام بعد از /add)، مرحله بی‌صدا گم می‌شد و ربات
        // هرگز ورودی کاربر را نمی‌خواست.
        Db::q(
            'INSERT INTO `chat_hub` (`chat_id`,`chat_type`,`title`,`step`,`step_user`,`temp`,`created_at`)
             VALUES (?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE `step` = VALUES(`step`),
                                     `step_user` = VALUES(`step_user`),
                                     `temp` = VALUES(`temp`)',
            [$chatId, $chatId < 0 ? 'supergroup' : 'group', '', $step, $userId,
             $temp ? json_encode($temp, JSON_UNESCAPED_UNICODE) : null]
        );
    }

    public static function step(int $chatId): array
    {
        $row = self::get($chatId);
        if (!$row) return ['step' => 'idle', 'step_user' => 0, 'temp' => []];
        $t = json_decode((string)($row['temp'] ?? ''), true);
        return [
            'step' => (string)($row['step'] ?? 'idle'),
            'step_user' => (int)($row['step_user'] ?? 0),
            'temp' => is_array($t) ? $t : [],
        ];
    }

    public static function clearStep(int $chatId): void
    {
        self::setStep($chatId, 'idle', 0, []);
    }

    // ---------------------------------------------------------------- ادمین‌ها

    /**
     * آیا کاربر ادمین گروه است؟ (با کش یک‌ساعته)
     */
    public static function isAdmin(int $chatId, int $userId): bool
    {
        if ($userId <= 0) return false;
        $row = Db::one('SELECT `is_admin`,`checked_at` FROM `chat_admin` WHERE `chat_id` = ? AND `user_id` = ?', [$chatId, $userId]);
        $fresh = $row && (time() - strtotime((string)$row['checked_at']) < self::ADMIN_TTL);
        if ($fresh) return (int)$row['is_admin'] === 1;

        $isAdmin = self::queryIsAdmin($chatId, $userId);
        try {
            Db::q(
                'INSERT INTO `chat_admin` (`chat_id`,`user_id`,`is_admin`,`checked_at`) VALUES (?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE `is_admin` = VALUES(`is_admin`), `checked_at` = NOW()',
                [$chatId, $userId, $isAdmin ? 1 : 0]
            );
        } catch (Throwable $e) {
            // بی‌اهمیت
        }
        return $isAdmin;
    }

    private static function queryIsAdmin(int $chatId, int $userId): bool
    {
        $hub = self::get($chatId);
        if ($hub && (string)$hub['chat_type'] === 'channel') {
            // در کانال فقط «مدیر کانال» می‌تواند مانیتور اضافه کند
            return $userId === (int)Db::get('channel_owner_' . $chatId, '0')
                || $userId > 0 && self::isBotAdmin($userId);
        }
        $r = BotApi::call(botToken(), 'getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
        if (empty($r['ok'])) {
            // API در دسترس نیست ⇒ کش معتبر نداریم ⇒ اجازه نده (fail-closed)
            return false;
        }
        $st = (string)($r['result']['status'] ?? '');
        return in_array($st, ['creator', 'administrator'], true);
    }

    /** فهرست نام‌های ادمین‌ها برای منشن (تا ۵ نفر) */
    public static function mentionAdmins(int $chatId, string $hubMention): string
    {
        $mode = $hubMention !== '' ? $hubMention : Db::get('group_mention', 'admins');
        if ($mode === 'none') return '';
        $out = '';
        $rows = Db::all(
            'SELECT `user_id`,`username` FROM `chat_admin` WHERE `chat_id` = ? AND `is_admin` = 1 ORDER BY `user_id` ASC LIMIT 5',
            [$chatId]
        );
        foreach ($rows as $r) {
            if (!empty($r['username'])) { $out .= '@' . $r['username'] . ' '; continue; }
            $link = tgH((string)Db::val('SELECT `name` FROM `user` WHERE `id` = ?', [(int)$r['user_id']]));
            $out .= '<a href="tg://user?id=' . (int)$r['user_id'] . '">' . ($link !== '' ? $link : 'مدیر') . '</a> ';
        }
        return trim($out);
    }

    /** حذف کش ادمین‌ها (وقتی کسی ارتقا/تنزل گرفت) */
    public static function forgetAdmins(int $chatId): void
    {
        try { Db::exec('DELETE FROM `chat_admin` WHERE `chat_id` = ?', [$chatId]); } catch (Throwable $e) { /* بی‌اهمیت */ }
    }

    // ---------------------------------------------------------------- اعلان

    /**
     * آیا اعلان‌های این گروه روشن است؟
     */
    public static function notifyOn(array $hub): bool
    {
        return (int)($hub['notify'] ?? 1) === 1;
    }

    /** حذف مانیتورهای یک گروه هنگامی که ربات از آن خارج/حذف شد */
    public static function purge(int $chatId): void
    {
        $ids = array_map(static fn($r) => (int)$r['id'], Db::all('SELECT `id` FROM `site` WHERE `chat_id` = ?', [$chatId]));
        foreach ($ids as $id) {
            try {
                Db::exec('DELETE FROM `check_log` WHERE `site_id` = ?', [$id]);
                Db::exec('DELETE FROM `uptime_hour` WHERE `site_id` = ?', [$id]);
                Db::exec('DELETE FROM `incident` WHERE `site_id` = ?', [$id]);
                Db::exec('DELETE FROM `site_share` WHERE `site_id` = ?', [$id]);
            } catch (Throwable $e) { /* بی‌اهمیت */ }
        }
        try {
            Db::exec('DELETE FROM `site` WHERE `chat_id` = ?', [$chatId]);
            Db::exec('DELETE FROM `domain_watch` WHERE `chat_id` = ?', [$chatId]);
            Db::exec('DELETE FROM `chat_admin` WHERE `chat_id` = ?', [$chatId]);
            Db::exec('DELETE FROM `chat_hub` WHERE `chat_id` = ?', [$chatId]);
        } catch (Throwable $e) { /* بی‌اهمیت */ }
    }
}
