<?php
/**
 * ===== ماژول رنکینگ و امتیازدهی (Ranking) =====
 *
 * منبع واحدِ «امتیاز» در کل ربات. هر سه اسکریپت پراکندهٔ قبلی
 * (add_ranking.php / init_ranking.php / ranking_command.php) به این ماژول ادغام شدند
 * تا منطق امتیازدهی فقط یک‌جا زندگی کند و با هم تداخل نکند.
 *
 * ── چهار نوع امتیاز ────────────────────────────────────────────────
 *  ۱) امتیاز روزانه   : به‌ازای هر روز فعال → points_per_day   (یک‌بار در روز)
 *  ۲) پاداش آپتایم   : به‌ازای هر ساعتِ بالابودنِ یک سایت
 *                       → points_per_uptime_hour               (یک‌بار در ساعت)
 *  ۳) سطح (رتبه)      : هر ۱۰۰ امتیاز یک سطح، سقف ۱۰
 *  ۴) شمارش ساعت آپتایم → site_uptime_count (برای نمایش)
 *
 * ── اصول طراحی ─────────────────────────────────────────────────────
 *  • **اتمیک**: همهٔ افزایش‌ها با یک UPDATE ریاضی انجام می‌شود
 *    (`user_points = user_points + ?`)، نه خواندن-نوشتنِ جداگانه؛ بنابراین
 *    دو اجرای همزمان هرگز امتیاز گم نمی‌کنند.
 *  • **بدون N+1**: به‌جای SELECTِ کاربر و چند کوئری settings در هر چک،
 *    حداکثر یک کوئری در هر روز/ساعتِ تازه اجرا می‌شود؛ بقیه از کشِ
 *    تنظیماتِ درون‌پروسه‌ای (Db::$settingsCache) رد می‌شوند.
 *  • **idempotent**: کلید هر جایزه شامل تاریخ/ساعت است؛ تکرارِ همان جایزه
 *    با یک «خواندنِ کش‌شده» رد می‌شود.
 */
class Ranking
{
    // ─────────────────────────────────────────────── سطوح و آستانه‌ها

    /** هر چند امتیاز یک سطح بالاتر؟ */
    public const POINTS_PER_LEVEL = 100;

    /** بیشینهٔ سطح (رتبه) */
    public const MAX_LEVEL = 10;

    /**
     * سطح (رتبه)ِ یک مقدار امتیاز.
     * سطح ۱ = پایه، سطح ۱۰ = بالاترین.
     */
    public static function level(int $points): int
    {
        if ($points < 0) $points = 0;
        return min(self::MAX_LEVEL, 1 + intdiv($points, self::POINTS_PER_LEVEL));
    }

    /**
     * چند امتیاز دیگر لازم است تا به سطح بعد برسیم؟
     * اگر در بالاترین سطح باشیم، ۰ برمی‌گرداند.
     */
    public static function pointsToNext(int $points, int $level): int
    {
        if ($level >= self::MAX_LEVEL) return 0;
        // سطح n با امتیاز n×POINTS_PER_LEVEL شروع می‌شود؛ پس تا سطحِ بعد
        // همان n×POINTS_PER_LEVEL کافی است — نه (n+1) که دو برابرِ سقفِ سطح
        // جاری را نشان می‌داد (با امتیاز ۰ می‌گفت «۲۰۰ امتیاز تا سطح ۲»).
        return max(0, ($level * self::POINTS_PER_LEVEL) - $points);
    }

    // ─────────────────────────────────────────────── پیکربندی

    /**
     * مقادیر پیش‌فرض تنظیمات رنکینگ.
     * این‌ها هم در seedDefaults (config.php) و هم اینجا تعریف شده‌اند تا
     * اگر نصبی کلیدی را از settings پاک کرد، رنکینگ بی‌صدا از کار نیفتد.
     */
    public static function defaults(): array
    {
        return [
            'points_per_day'         => 1,    // امتیاز روزانهٔ پایه
            'points_per_uptime_hour' => 5,    // امتیاز هر ساعت آپتایمِ سایتِ بالا
        ];
    }

    private static function cfg(string $k): int
    {
        $d = self::defaults();
        return Db::getInt($k, (int)($d[$k] ?? 0));
    }

    // ─────────────────────────────────────────────── ابزار جایزه

    /**
     * آیا این جایزه برای همین بازه (روز/ساعت) قبلاً داده شده؟
     * نتیجه در کشِ درون‌پروسه‌ای می‌ماند تا هر چکِ بعدی در همان ساعت
     * هیچ کوئری نزند.
     */
    private static function already(string $key): bool
    {
        return Db::get($key) !== null;
    }

    /** علامت‌زدن یک جایزهٔ داده‌شده (idempotency guard) */
    private static function mark(string $key, int $points): void
    {
        Db::set($key, (string)$points);
    }

    /**
     * افزایش اتمیک امتیاز + بازمحاسبهٔ سطح در همان UPDATE.
     *
     * دو نکتهٔ مهم:*
     *  ۱) MySQL تخصیص‌های SET را **از چپ به راست** ارزیابی می‌کند (مستند رسمی)،
     *     پس تخصیص دوم `user_points` را در حالتِ **قبلاً به‌روزشده** می‌بیند.
     *     برای همین عبارت سطح باید فقط `user_points` باشد؛ اگر دوباره `+ ?`
     *     اضافه می‌کردیم، امتیاز دوبار شمرده می‌شد (۲۵۰+۲۵۰ → سطحِ ۶ به‌جای ۳).
     *  ۲) ستون `UNSIGNED` است؛ تخصیص مقدارِ منفی در حالت strict خطا می‌دهد
     *     (پس «امتیازِ منفی» بی‌صدا رد می‌شد). `GREATEST(0, …)` این را قبل از
     *     نوشتن می‌بندد، و `CAST(… AS SIGNED)` جلوی پیچیده‌شدنِ عبارتِ منفی در
     *     آریتمتیکِ بدون‌علامت را می‌گیرد.
     *
     * پروژه فقط MySQL است (کل DDL و مستندات MySQL)؛ پس تکیه بر این رفتارِ
     * مستندشده امن است و تست tests/ranking_test.php آن را قفل می‌کند.
     */
    private static function addPoints(int $userId, int $points): bool
    {
        if ($userId <= 0 || $points === 0) return false;
        $level = self::POINTS_PER_LEVEL;
        $max = self::MAX_LEVEL;
        $st = Db::q(
            "UPDATE `user`
                SET `user_points` = GREATEST(0, CAST(`user_points` AS SIGNED) + ?),
                    `user_rank` = LEAST({$max}, GREATEST(1, 1 + FLOOR(`user_points` / {$level}))),
                    `last_seen` = NOW()
              WHERE `id` = ?",
            [$points, $userId]
        );
        return $st->rowCount() > 0;
    }

    /**
     * افزایش شمارندهٔ کلِ ساعت آپتایم کاربر (برای نمایش).
     * با یک UPDATE ریاضی انجام می‌شود تا در برابر همزمانی ایمن باشد.
     */
    private static function addUptimeHours(int $userId, float $hours): void
    {
        if ($userId <= 0 || $hours <= 0) return;
        Db::q('UPDATE `user` SET `site_uptime_count` = `site_uptime_count` + ? WHERE `id` = ?',
            [(int)round($hours), $userId]);
    }

    // ─────────────────────────────────────────────── اعطای امتیاز

    /**
     * امتیازِ روزانهٔ یک کاربر — حداکثر یک‌بار در هر روز (UTC).
     *
     * @return int امتیازی که واقعاً داده شد (۰ یعنی قبلاً گرفته بود)
     */
    public static function awardDaily(int $userId): int
    {
        if ($userId <= 0) return 0;
        $key = 'rank_day_' . $userId . '_' . date('Y-m-d');
        if (self::already($key)) return 0;

        $points = max(0, self::cfg('points_per_day'));
        self::addPoints($userId, $points);
        self::mark($key, $points);
        Db::logEvent($userId, 'rank_daily', $points . ' امتیاز روزانه');
        return $points;
    }

    /**
     * پاداش آپتایمِ یک سایت — حداکثر یک‌بار در هر ساعت (UTC).
     *
     * مسئولیتِ تشخیص «بالا بودن» با فراخواننده است (Ranking::onCheck آن را با
     * پارامتر $ok انجام می‌دهد)؛ اینجا فقط تضمین می‌شود هر سایت در هر ساعتِ
     * تقویمی حداکثر یک‌بار پاداش بگیرد — وگرنه هر راندِ چک دوباره جایزه می‌داد.
     *
     * @param array $site ردیف جدول site (باید user_id داشته باشد)
     * @return int امتیاز داده‌شده
     */
    public static function awardUptime(array $site): int
    {
        $userId = (int)($site['user_id'] ?? 0);
        if ($userId <= 0) return 0;

        $hour = date('Y-m-d\TH');
        $key = 'rank_up_' . (int)($site['id'] ?? 0) . '_' . $hour;
        if (self::already($key)) return 0;

        $perHour = max(0, self::cfg('points_per_uptime_hour'));
        self::addPoints($userId, $perHour);
        self::addUptimeHours($userId, 1);
        self::mark($key, $perHour);
        if ($perHour > 0) Db::logEvent($userId, 'rank_uptime', $perHour . ' امتیاز آپتایم سایت #' . (int)$site['id']);
        return $perHour;
    }

    /**
     * نقطهٔ ورودِ موتور چک — بعد از هر چک صدا زده می‌شود.
     *
     * همهٔ کارها best-effort است؛ هیچ خطایی نباید راندِ چک را بشکند.
     *
     * @param array $site ردیف جدول site
     * @param bool  $ok   نتیجهٔ همین چک (true = پاسخ سالم)
     */
    public static function onCheck(array $site, bool $ok): void
    {
        if (!$ok) return;
        try {
            self::awardDaily((int)($site['user_id'] ?? 0));
            self::awardUptime($site);
        } catch (Throwable $e) {
            uptimeLog('error', 'ranking: ' . $e->getMessage());
        }
    }

    /**
     * امتیازِ یک‌باره (برای مدیر: جایزهٔ دستی) — خارج از چرخهٔ خودکار.
     *
     * @return bool فقط وقتی که کاربر واقعاً وجود داشت و UPDATE خورد
     */
    public static function grant(int $userId, int $points, string $reason = 'دستی'): bool
    {
        if ($userId <= 0 || $points === 0) return false;
        try {
            // UPDATE که ۰ سطر بگیرد خطا نیست؛ بدون این بررسی، شناسهٔ ناموجود
            // هم «موفق» گزارش می‌شد. rowCount کافی نیست چون ممکن است امتیازِ
            // منفی به صفر برسد و مقدارِ ستون عوض نشود — پس وجود سطر جدا چک می‌شود.
            if (Db::val('SELECT `id` FROM `user` WHERE `id` = ?', [$userId]) === null) return false;
            self::addPoints($userId, $points);
            Db::logEvent($userId, 'rank_grant', $points . ' امتیاز — ' . $reason);
            return true;
        } catch (Throwable $e) {
            uptimeLog('error', 'ranking grant: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────── بازنشانی

    /**
     * صفر کردن امتیاز همه (یا یک کاربر) — برای فصل جدید رنکینگ.
     *
     * @return int تعداد سطرهایی که واقعاً عوض شدند (کاربرانی که از قبل صفر
     *              بودند شمرده نمی‌شوند — rowCount در MySQL سطرهای «تغییرکرده»
     *              را برمی‌گرداند نه سطرهای «مطابق»)
     */
    public static function reset(int $userId = 0): int
    {
        try {
            $sql = 'UPDATE `user` SET `user_points` = 0, `user_rank` = 1'
                 . ($userId > 0 ? ' WHERE `id` = ?' : '');
            $n = Db::q($sql, $userId > 0 ? [$userId] : [])->rowCount();
            // کلیدهای جایزهٔ روز را هم پاک کن تا بلافاصله دوباره توزیع شود
            Db::q("DELETE FROM `settings` WHERE `k` LIKE 'rank_day_%' OR `k` LIKE 'rank_up_%'");
            Db::flushSettings();
            return (int)$n;
        } catch (Throwable $e) {
            uptimeLog('error', 'ranking reset: ' . $e->getMessage());
            return 0;
        }
    }

    // ─────────────────────────────────────────────── نمایش

    /** @return array{id:int,name:string,username:string,points:int,level:int,uptime:int,plan:string} */
    public static function top(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        try {
            $rows = Db::all(
                "SELECT `id`, `name`, `username`, `user_points`, `user_rank`, `site_uptime_count`, `plan`
                   FROM `user`
                  ORDER BY `user_points` DESC, `user_rank` DESC, `id` ASC
                  LIMIT {$limit}"
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'      => (int)$r['id'],
                'name'    => (string)($r['name'] ?? ''),
                'username'=> (string)($r['username'] ?? ''),
                'points'  => (int)($r['user_points'] ?? 0),
                'level'   => (int)($r['user_rank'] ?? 1),
                'uptime'  => (int)($r['site_uptime_count'] ?? 0),
                'plan'    => (string)($r['plan'] ?? 'free'),
            ];
        }
        return $out;
    }

    /**
     * مدالِ رتبه برای ردیف‌های جدول امتیاز.
     * @return string
     */
    public static function medal(int $rank)
    {
        return match ($rank) {
            1       => '🥇',
            2       => '🥈',
            3       => '🥉',
            default => '▫️',
        };
    }

    /**
     * جدول امتیاز به متن تلگرام (HTML).
     * @param bool $isAdmin آیا باید خطوط مخصوص مدیر هم دیده شود
     */
    public static function leaderboardText(int $limit = 20, bool $isAdmin = false, int $selfId = 0): string
    {
        $rows = self::top($limit);
        $perDay = self::cfg('points_per_day');
        $perHour = self::cfg('points_per_uptime_hour');

        if (!$rows) {
            return "📊 <b>جدول امتیاز</b>\n\nهنوز امتیازی ثبت نشده است.\n"
                . "امتیاز با چکِ خودکار سایت‌ها به‌صورت خودکار جمع می‌شود.";
        }

        $txt = "📊 <b>جدول امتیاز برترین‌ها</b>\n\n";
        foreach ($rows as $i => $r) {
            $rank = $i + 1;
            $who = $r['username'] !== '' ? '@' . $r['username']
                 : ($r['name'] !== '' ? $r['name'] : ('کاربر ' . faNum($r['id'])));
            $vip = $r['plan'] === 'vip' ? ' 💎' : '';
            $me  = ($selfId > 0 && $r['id'] === $selfId) ? ' ← شما' : '';
            $txt .= self::medal($rank) . ' <b>' . faNum($rank) . '.</b> ' . tgH($who) . $vip
                 . ' — ' . faNum($r['points']) . ' امتیاز (سطح ' . faNum($r['level']) . ')'
                 . ' — ' . faNum($r['uptime']) . ' ساعت آپتایم' . $me . "\n";
        }

        $txt .= "\n▫️ امتیاز روزانه: " . faNum($perDay)
             . " • پاداش هر ساعت آپتایم: " . faNum($perHour) . "\n"
             . "▫️ هر " . faNum(self::POINTS_PER_LEVEL) . " امتیاز = یک سطح بالاتر (بیشینه سطح "
             . faNum(self::MAX_LEVEL) . ")";

        if ($isAdmin) {
            $txt .= "\n\n👑 <b>دسترسی مدیر</b>\n▫️ جایزهٔ دستی: <code>/rankgrant &lt;id&gt; &lt;امتیاز&gt;</code>\n"
                 . "▫️ بازنشانی فصل: <code>/rankreset</code>";
        }
        return $txt;
    }

    /**
     * متنِ «کارت رتبهٔ من» — نمای شخصی کاربر.
     * @param array $user ردیف کاربر (باید user_points/user_rank داشته باشد)
     */
    public static function meText(?array $user, int $uid): string
    {
        if (!$user || !is_array($user) || $uid <= 0) return "📊 <b>رتبهٔ من</b>\n\nحسابی پیدا نشد.";
        $points = (int)($user['user_points'] ?? 0);
        $level = (int)($user['user_rank'] ?? 1);
        $level = min($level, self::MAX_LEVEL);

        $sites = [];
        try { $sites = Db::all('SELECT `id`,`status` FROM `site` WHERE `user_id` = ? AND `paused` = 0', [$uid]); } catch (Throwable $e) { $sites = []; }
        $total = count($sites);
        $up = count(array_filter($sites, fn($s) => ($s['status'] ?? '') === 'up'));
        $down = $total - $up;

        $perDay = self::cfg('points_per_day');
        $perHour = self::cfg('points_per_uptime_hour');
        $toNext = self::pointsToNext($points, $level);

        $today = date('Y-m-d');
        $gotToday = Db::get('rank_day_' . $uid . '_' . $today) !== null;
        $lastLevel = self::level(max(0, $points - 1));

        $txt = "📊 <b>رتبهٔ من</b>\n\n"
            // ---- امتیاز ----
            . "🏅 <b>امتیاز شما</b>\n"
            . "▫️ امتیاز جاری: <b>" . faNum($points) . "</b>\n"
            . "▫️ سطح (رتبه): <b>" . faNum($level) . "</b>" . self::levelIcon($level) . "\n"
            . "▫️ سایت‌های فعال: " . faNum($up) . " از " . faNum($total) . "\n\n";

        // ---- پیشرفت ----
        if ($level >= self::MAX_LEVEL) {
            $txt .= "📈 <b>پیشرفت:</b> به بالاترین سطح رسیده‌اید 🏆\n\n";
        } else {
            $pct = (int)min(100, round(($points % self::POINTS_PER_LEVEL) / self::POINTS_PER_LEVEL * 100));
            $txt .= "📈 <b>پیشرفت تا سطح " . faNum($level + 1) . ":</b>\n"
                 . progressBar($pct) . "\n"
                 . "▫️ " . faNum($toNext) . " امتیاز دیگر لازم دارید\n\n";
        }

        // ---- وضعیت سایت‌ها ----
        $txt .= "🌐 <b>سایت‌های شما</b>\n"
            . "▫️ کل: " . faNum($total)
            . " • 🟢 بالا: " . faNum($up)
            . " • 🔴 قطع: " . faNum($down) . "\n\n";

        // ---- وضعیت جایزهٔ روزانه ----
        $txt .= $gotToday
            ? "✅ <b>امتیاز روزانهٔ امروز</b> دریافت شد (+" . faNum($perDay) . ")\n"
            : "⏳ <b>امتیاز روزانهٔ امروز</b> هنوز داده نشده؛ با اولین چکِ موفقِ امروز دریافت می‌کنید.\n";
        $txt .= "▫️ پاداش هر ساعت سایتِ بالا: +" . faNum($perHour) . "\n\n";

        // ---- مزایا ----
        $txt .= "🎖 <b>مزایای سطح " . faNum($level) . "</b>\n" . self::benefits($level);

        if ($lastLevel !== $level) {
            $txt .= "\n🆙 سطح شما از " . faNum($lastLevel) . " به " . faNum($level) . " رسید!";
        }
        return $txt;
    }

    /** آیکون سطح */
    private static function levelIcon(int $level): string
    {
        return match (true) {
            $level >= 10 => ' 👑',
            $level >= 7  => ' 🏆',
            $level >= 4  => ' 🥇',
            default      => ' 🌱',
        };
    }

    /** فهرست مزایای هر سطح (فارسی، بدون اعداد لاتین) */
    public static function benefits(int $level): string
    {
        $lines = [];
        if ($level >= 8) $lines[] = '▫️ اولویت اول پشتیبانی و بررسی دستی';
        if ($level >= 6) $lines[] = '▫️ سقف بیشتر سایت‌ها (طبق سقف اشتراک ویژه)';
        if ($level >= 4) $lines[] = '▫️ نمایش «برتر» در جدول امتیاز';
        if ($level >= 2) $lines[] = '▫️ نشان سطح در کارت وضعیت';
        $lines[] = '▫️ شرکت در جدول امتیاز ماهانه';
        return implode("\n", $lines) . "\n";
    }

    /**
     * منوی دکمه‌های رنکینگ.
     * @param bool $isAdmin
     */
    public static function menu(bool $isAdmin = false): string
    {
        $rows = [
            [['text' => '🏆 جدول امتیاز', 'callback_data' => 'rank:top']],
            [['text' => '🏅 رتبهٔ من', 'callback_data' => 'rank:me']],
            [['text' => '💡 نحوهٔ امتیازدهی', 'callback_data' => 'rank:how']],
        ];
        if ($isAdmin) {
            $rows[] = [['text' => '⚙️ تنظیمات رنکینگ', 'callback_data' => 'rank:cfg']];
        }
        $rows[] = [['text' => '🔙 منو', 'callback_data' => 'menu']];
        return BotApi::kb($rows);
    }

    /** متن توضیحیِ «نحوهٔ امتیازدهی» */
    public static function howText(): string
    {
        $perDay = self::cfg('points_per_day');
        $perHour = self::cfg('points_per_uptime_hour');
        return "💡 <b>نحوهٔ امتیازدهی</b>\n\n"
            . "▫️ <b>امتیاز روزانه:</b> هر روز که حسابتان فعال باشد " . faNum($perDay) . " امتیاز می‌گیرید.\n"
            . "▫️ <b>پاداش آپتایم:</b> هر ساعتی که یکی از سایت‌هایتان بالا باشد "
            . faNum($perHour) . " امتیاز می‌گیرید؛ هرچه سایت بیشتر بالا بماند، بیشتر.\n"
            . "▫️ <b>سطح:</b> هر " . faNum(self::POINTS_PER_LEVEL) . " امتیاز یک سطح بالاتر می‌روید "
            . "(بیشینه " . faNum(self::MAX_LEVEL) . " سطح).\n"
            . "▫️ <b>امتیاز منفی نداریم؛</b> قطعیِ سایت امتیازی کم نمی‌کند، فقط پاداش نمی‌دهد.\n\n"
            . "🏆 سطح بالاتر = نمایش بهتر در جدول امتیاز و مزایای بیشتر.";
    }

    /** متن تنظیمات رنکینگ (برای پنل مدیر) */
    public static function cfgText(): string
    {
        return "⚙️ <b>تنظیمات رنکینگ</b>\n\n"
            . "▫️ امتیاز روزانه: " . faNum(self::cfg('points_per_day')) . "\n"
            . "▫️ پاداش هر ساعت آپتایم: " . faNum(self::cfg('points_per_uptime_hour')) . "\n"
            . "▫️ هر سطح: " . faNum(self::POINTS_PER_LEVEL) . " امتیاز\n"
            . "▫️ بیشینهٔ سطح: " . faNum(self::MAX_LEVEL) . "\n\n"
            . "برای تغییر از «⚙️ پنل مدیریت → 🎖 رنکینگ» اقدام کنید.";
    }
}
