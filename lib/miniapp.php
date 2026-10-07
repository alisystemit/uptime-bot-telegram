<?php
/**
 * ===== منطق مینی‌اپ تلگرام (Telegram Mini App) =====
 *
 * احراز هویت:
 *   کلاینت (app.php) رشتهٔ Telegram.WebApp.initData را می‌فرستد؛ اینجا طبق
 *   مستندات رسمی تلگرام اعتبارسنجی می‌شود:
 *     secret = HMAC_SHA256(bot_token, key="WebAppData")
 *     check  = HMAC_SHA256(data_check_string, secret)
 *   data_check_string = همهٔ فیلدها (به‌جز hash) به ترتیب الفبا، با «\n».
 *   بدون امضای معتبر هیچ داده‌ای برگردانده نمی‌شود (fail-closed).
 *
 * داده‌ها:
 *   همهٔ متدها فقط مانیتورهای «خصوصی» کاربر (chat_id = 0) را برمی‌گردانند و
 *   مالکیت هر سایت پیش از هر خواندن/نوشتن بررسی می‌شود. هیچ اطلاعات کاربران
 *   دیگر (نام، آیدی، توکن) در خروجی نیست.
 */
class MiniApp
{
    /** حداکثر سن مجاز initData (۷ روز) — با بستن/بازکردن اپ تازه می‌شود */
    private const AUTH_TTL = 604800;

    // ================================================================ احراز

    /**
     * اعتبارسنجی initData تلگرام.
     * @return array|null کاربر تلگرام (فیلد user) یا null در صورت شکست
     */
    public static function validateInitData(string $initData, string $botToken): ?array
    {
        $initData = trim($initData);
        if ($initData === '' || $botToken === '' || strlen($initData) > 8192) return null;

        $fields = [];
        parse_str($initData, $fields);
        if (!is_array($fields) || !$fields) return null;
        $hash = $fields['hash'] ?? null;
        if (!is_string($hash) || !ctype_xdigit($hash)) return null;
        unset($fields['hash']);

        ksort($fields, SORT_STRING);
        $pairs = [];
        foreach ($fields as $k => $v) {
            if (!is_string($v)) return null; // مقدار آرایه‌ای یعنی ورودی دست‌کاری‌شده
            $pairs[] = $k . '=' . $v;
        }
        $dataCheck = implode("\n", $pairs);
        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calc = hash_hmac('sha256', $dataCheck, $secret);
        if (!hash_equals($calc, $hash)) return null;

        $authDate = isset($fields['auth_date']) ? (int)$fields['auth_date'] : 0;
        if ($authDate <= 0 || $authDate < time() - self::AUTH_TTL || $authDate > time() + 300) return null;

        $user = json_decode((string)($fields['user'] ?? ''), true);
        if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) return null;
        return $user;
    }

    /**
     * یافتن/ساخت ردیف کاربر در دیتابیس — موازی Bot::ensureUser تا کاربری که
     * اول از مینی‌اپ می‌آید هم ثبت شود.
     * @return array|null ردیف user؛ null یعنی ظرفیت تکمیل است
     */
    public static function ensureUser(array $tgUser): ?array
    {
        $uid = (int)($tgUser['id'] ?? 0);
        if ($uid <= 0) return null;
        $name = trim((string)($tgUser['first_name'] ?? '') . ' ' . (string)($tgUser['last_name'] ?? ''));
        $username = (string)($tgUser['username'] ?? '');

        $row = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$uid]);
        if ($row) {
            Db::q('UPDATE `user` SET `name` = ?, `username` = ?, `last_seen` = NOW() WHERE `id` = ?', [$name, $username, $uid]);
            $row['name'] = $name;
            $row['username'] = $username;
            return $row;
        }

        $isAdmin = isBotAdmin($uid);
        $maxUsers = Db::getInt('max_users', 0);
        if (!$isAdmin && $maxUsers > 0) {
            $total = (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `is_admin` = 0');
            if ($total >= $maxUsers) return null;
        }
        $mode = Db::get('access_mode', 'open');
        $access = ($isAdmin || $mode === 'open') ? 1 : 0;
        $token = uniqueToken(20, static fn(string $t): bool => (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `share_token` = ?', [$t]) > 0);

        try {
            Db::q(
                'INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`share_token`,`step`,`temp`,`created_at`,`last_seen`)
                 VALUES (?,?,?,?,?,?,\'idle\',NULL,NOW(),NOW())',
                [$uid, $name, $username, $isAdmin ? 1 : 0, $access, $token]
            );
            Db::logEvent($uid, 'register', 'miniapp');
        } catch (Throwable $e) {
            return null;
        }
        return Db::one('SELECT * FROM `user` WHERE `id` = ?', [$uid]);
    }

    /** دسترسی کامل (همان منطق Bot::allowed) */
    public static function allowed(array $u): bool
    {
        if ((int)($u['is_blocked'] ?? 0) === 1) return false;
        if (isBotAdmin((int)$u['id'])) return true;
        return (int)($u['access'] ?? 0) === 1;
    }

    /** اشتراک ویژهٔ فعال (همان منطق Bot::isVip) */
    public static function isVip(array $u): bool
    {
        if (($u['plan'] ?? '') !== 'vip') return false;
        if (empty($u['plan_until'])) return true;
        $t = strtotime((string)$u['plan_until']);
        return $t !== false && $t >= time();
    }

    private static function maxSites(array $u): int
    {
        return self::isVip($u) ? Db::getInt('vip_max_sites', 10) : Db::getInt('max_sites', 5);
    }

    /** مانیتور خصوصیِ متعلق به همین کاربر */
    private static function ownSite(array $u, int $id): ?array
    {
        if ($id <= 0) return null;
        return Db::one('SELECT * FROM `site` WHERE `id` = ? AND `user_id` = ? AND `chat_id` = 0', [$id, (int)$u['id']]);
    }

    // ================================================================ کارت کاربر

    public static function userCard(array $u): array
    {
        $uid = (int)$u['id'];
        $points = (int)($u['user_points'] ?? 0);
        $level = Ranking::level($points);
        $vip = self::isVip($u);
        return [
            'name'       => trim((string)($u['name'] ?? '')) !== '' ? trim((string)$u['name']) : 'کاربر',
            'username'   => (string)($u['username'] ?? ''),
            'plan'       => $vip ? 'vip' : 'free',
            'plan_until' => $vip && !empty($u['plan_until']) ? faDateTime((string)$u['plan_until'], tzOffset()) : null,
            'points'     => $points,
            'level'      => $level,
            'to_next'    => Ranking::pointsToNext($points, $level),
            'max_sites'  => self::maxSites($u),
            'sites_used' => (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ? AND `chat_id` = 0', [$uid]),
            'paused'     => (int)($u['paused'] ?? 0) === 1,
            'allowed'    => self::allowed($u),
            'status_url' => !empty($u['share_token']) ? statusUrl(appConfig(), (string)$u['share_token']) : '',
        ];
    }

    // ================================================================ ردیف سبک سایت

    /**
     * ردیف استاندارد نمایش سایت برای لیست و جزئیات (سبک).
     */
    public static function lightRow(array $s, string $base = ''): array
    {
        $tz = tzOffset();
        $state = siteState($s);
        $u1 = Stats::uptime($s, 1);
        $u7 = Stats::uptime($s, 7);
        $u30 = Stats::uptime($s, 30);

        $bars = [];
        $spark = [];
        foreach (Stats::recent($s, 60) as $r) {
            $bars[] = ['ok' => (int)$r['ok'], 'ms' => (int)$r['ms'], 'ts' => (string)($r['ts'] ?? '')];
            if ((int)$r['ms'] > 0) $spark[] = (int)$r['ms'];
        }

        $share = (string)($s['share_token'] ?? '');
        if ($base === '') {
            $base = rtrim((string)(appConfig()['base_url'] ?? ''), '/');
            if ($base === '' && !empty(appConfig()['domain'])) $base = 'https://' . appConfig()['domain'];
        }

        return [
            'id'           => (int)$s['id'],
            'label'        => (string)(($s['label'] ?? '') !== '' ? $s['label'] : $s['target']),
            'target'       => (string)$s['target'],
            'type'         => (string)$s['type'],
            'state'        => $state,
            'status_label' => ['up' => 'فعال', 'down' => 'قطع', 'slow' => 'کند', 'paused' => 'متوقف', 'unknown' => 'نامشخص'][$state] ?? $state,
            'ms'           => (int)($s['last_ms'] ?? 0),
            'code'         => (int)($s['last_code'] ?? 0),
            'error'        => trim((string)($s['last_error'] ?? '')),
            'ago'          => timeAgo((string)($s['last_check_at'] ?? ''), $tz),
            'u1'           => ['pct' => $u1['pct'], 'checks' => (int)$u1['checks']],
            'u7'           => ['pct' => $u7['pct'], 'checks' => (int)$u7['checks']],
            'u30'          => ['pct' => $u30['pct'], 'checks' => (int)$u30['checks']],
            'bars'         => $bars,
            'spark'        => array_slice($spark, -60),
            'total_checks' => (int)($s['total_checks'] ?? 0),
            'total_fails'  => (int)($s['total_fails'] ?? 0),
            'share_url'    => $share !== '' && $base !== '' ? $base . '/status.php?s=' . rawurlencode($share) : '',
        ];
    }

    // ================================================================ نماها

    /** داشبورد: خلاصه + موتور + فهرست سایت‌ها */
    public static function overview(array $u): array
    {
        $uid = (int)$u['id'];
        $sites = Db::all('SELECT * FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 ORDER BY `id` ASC', [$uid]);
        $summary = Stats::summaryOf($sites);
        $engine = Stats::engine();

        $rows = [];
        foreach ($sites as $s) $rows[] = self::lightRow($s);

        return [
            'user'    => self::userCard($u),
            'summary' => [
                'total'  => (int)$summary['total'],
                'up'     => (int)$summary['up'],
                'down'   => (int)$summary['down'],
                'slow'   => (int)$summary['slow'],
                'paused' => (int)$summary['paused'],
                'avg'    => $summary['uptime24'],
            ],
            'engine'  => [
                'interval'     => (int)$engine['interval'],
                'paused'       => (bool)$engine['paused'],
                'cron_healthy' => (bool)$engine['cron_healthy'],
                'stale_sec'    => $engine['stale'],
            ],
            'sites'   => $rows,
        ];
    }

    /** جزئیات کامل یک مانیتور (نمودارها، SSL، رخدادها) */
    public static function siteDetail(array $u, int $id): ?array
    {
        $site = self::ownSite($u, $id);
        if (!$site) return null;

        $row = self::lightRow($site);
        $tz = tzOffset();

        $daily = [];
        foreach (Stats::daily($site, 30) as $d) {
            $daily[] = ['d' => faDay($d['date'] . ' 00:00:00', $tz), 'p' => $d['pct'], 'c' => (int)$d['checks'], 'm' => (int)$d['avg_ms']];
        }
        $hourly = [];
        foreach (Stats::hourly($site, 24) as $h) {
            $hourly[] = ['h' => (int)$h['hour'], 'p' => $h['pct'], 'c' => (int)$h['checks'], 'm' => (int)$h['avg_ms']];
        }

        $resp = Stats::responseStats($site);
        $row['resp'] = [
            'min' => (int)($resp['min'] ?? 0),
            'avg' => (int)($site['resp_avg'] ?? 0) > 0 ? (int)$site['resp_avg'] : (int)($resp['avg'] ?? 0),
            'p95' => (int)($site['resp_p95'] ?? 0),
            'max' => (int)($site['resp_max'] ?? 0),
        ];
        $row['max_ms'] = (int)($site['max_ms'] ?? 0);
        $row['keyword'] = (string)($site['keyword'] ?? '');
        $row['daily'] = $daily;
        $row['hourly'] = $hourly;
        $row['ssl'] = [
            'checked' => (string)($site['ssl_check_at'] ?? '') !== '',
            'days'    => (int)($site['ssl_days'] ?? -1),
            'expires' => !empty($site['ssl_expires_at']) ? faDay((string)$site['ssl_expires_at'], $tz) : null,
            'issuer'  => (string)($site['ssl_issuer'] ?? ''),
            'error'   => (string)($site['ssl_error'] ?? ''),
            'ago'     => timeAgo((string)($site['ssl_check_at'] ?? ''), $tz),
        ];
        $row['incidents'] = Page::incidentsFor($site, 8);
        return $row;
    }

    /** آخرین رخدادهای همهٔ مانیتورهای کاربر */
    public static function incidents(array $u): array
    {
        $tz = tzOffset();
        $items = [];
        foreach (Stats::userIncidents((int)$u['id'], 0, 20) as $i) {
            $open = empty($i['end_at']);
            $items[] = [
                'site'   => (string)(($i['label'] ?? '') !== '' ? $i['label'] : $i['target']),
                'kind'   => (string)$i['kind'],
                'title'  => (string)$i['kind'] === 'down' ? 'قطعی' : 'کندی',
                'start'  => faDateTime((string)$i['start_at'], $tz),
                'ago'    => timeAgo((string)$i['start_at'], $tz),
                'dur'    => faDuration($open ? max(0, time() - (int)strtotime((string)$i['start_at'])) : (int)$i['duration']),
                'open'   => $open,
                'reason' => (string)($i['reason'] ?? ''),
            ];
        }
        return ['items' => $items];
    }

    /** دامنه‌های پایش‌شدهٔ کاربر */
    public static function domains(array $u): array
    {
        $tz = tzOffset();
        $items = [];
        foreach (Stats::domains((int)$u['id'], 0) as $d) {
            $exp = (string)($d['expires_at'] ?? '');
            $left = $exp !== '' ? (int)floor(((int)strtotime($exp) - time()) / 86400) : null;
            $warn = (int)($d['warn_days'] ?? 14);
            $state = $left === null ? 'unknown' : ($left < 0 ? 'expired' : ($left <= $warn ? 'warn' : 'ok'));
            $items[] = [
                'id'        => (int)$d['id'],
                'domain'    => (string)$d['domain'],
                'state'     => $state,
                'left_days' => $left,
                'left'      => $left === null ? '—' : ($left < 0 ? 'منقضی شده' : faLeft($left * 86400)),
                'expires'   => $exp !== '' ? faDay($exp, $tz) : '—',
                'registrar' => (string)($d['registrar'] ?? ''),
                'ago'       => timeAgo((string)($d['last_check'] ?? ''), $tz),
                'error'     => (string)($d['last_error'] ?? ''),
            ];
        }
        return ['items' => $items];
    }

    /** رنکینگ: وضعیت خود کاربر + جدول برترین‌ها */
    public static function rank(array $u): array
    {
        $points = (int)($u['user_points'] ?? 0);
        $level = Ranking::level($points);
        $position = (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `user_points` > ?', [$points]) + 1;

        $top = [];
        foreach (Ranking::top(20) as $i => $r) {
            $name = trim((string)$r['name']);
            $top[] = [
                'rank'   => $i + 1,
                'medal'  => (string)Ranking::medal($i + 1),
                'name'   => $name !== '' ? $name : ($r['username'] !== '' ? '@' . $r['username'] : 'کاربر'),
                'points' => (int)$r['points'],
                'level'  => (int)$r['level'],
                'uptime' => (int)$r['uptime'],
                'me'     => (int)$r['id'] === (int)$u['id'],
            ];
        }

        return [
            'me' => [
                'points'   => $points,
                'level'    => $level,
                'to_next'  => Ranking::pointsToNext($points, $level),
                'position' => $position,
                'uptime'   => (int)($u['site_uptime_count'] ?? 0),
                'per_day'  => Db::getInt('points_per_day', 1),
                'per_hour' => Db::getInt('points_per_uptime_hour', 5),
            ],
            'top' => $top,
        ];
    }

    // ================================================================ گزارش

    /** گزارش تلفیقی — معادل «📈 گزارش آپتایم شما» ربات */
    public static function report(array $u): array
    {
        $uid = (int)$u['id'];
        $sites = Db::all('SELECT * FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 ORDER BY `id` ASC', [$uid]);
        $summary = Stats::summaryOf($sites);

        $rows = [];
        foreach ($sites as $s) $rows[] = self::lightRow($s);

        $t = Stats::incidentTotals($uid, 0);
        $pausedTotal = (int)($u['paused_total'] ?? 0);
        if ((int)($u['paused'] ?? 0) === 1 && !empty($u['paused_since'])) {
            $pausedTotal += max(0, time() - (int)strtotime((string)$u['paused_since']));
        }

        return [
            'summary' => [
                'total'  => (int)$summary['total'],
                'up'     => (int)$summary['up'],
                'down'   => (int)$summary['down'],
                'slow'   => (int)$summary['slow'],
                'paused' => (int)$summary['paused'],
                'avg'    => $summary['uptime24'],
            ],
            'sites'    => $rows,
            'incidents' => [
                'count' => (int)$t['count'],
                'down'  => faDuration((int)$t['down']),
                'slow'  => faDuration((int)$t['slow']),
            ],
            'user' => [
                'notify'       => (int)($u['notify'] ?? 1) === 1,
                'paused'       => (int)($u['paused'] ?? 0) === 1,
                'pause_events' => (int)Db::val('SELECT COUNT(*) FROM `events` WHERE `user_id` = ? AND `kind` = \'pause_user\'', [$uid]),
                'paused_total' => faDuration($pausedTotal),
            ],
        ];
    }

    /** تنظیمات و حساب — برای صفحهٔ «تنظیمات» مینی‌اپ */
    public static function settings(array $u): array
    {
        $mode = Db::get('access_mode', 'open');
        return [
            'user'           => self::userCard($u),
            'notify'         => (int)($u['notify'] ?? 1) === 1,
            'paused'         => (int)($u['paused'] ?? 0) === 1,
            'paused_at'      => (int)($u['paused'] ?? 0) === 1 && !empty($u['paused_since']) ? timeAgo((string)$u['paused_since'], tzOffset()) : null,
            'paused_total'   => faDuration((int)($u['paused_total'] ?? 0)),
            'check_interval' => max(10, Db::getInt('check_interval', 20)),
            'access_mode'    => $mode,
            'access_label'   => ['open' => 'باز', 'code' => 'نیازمند کد', 'paid' => 'نیازمند پرداخت'][$mode] ?? '—',
            'status_url'     => !empty($u['share_token']) ? statusUrl(appConfig(), (string)$u['share_token']) : '',
            'bot_username'   => botUsername(),
            'engine'         => Stats::engine(),
        ];
    }

    /**
     * تغییر تنظیمات کاربر از مینی‌اپ.
     * @return array{ok:bool,error?:string,...}
     */
    public static function setSetting(array $u, string $key, string $val): array
    {
        $uid = (int)$u['id'];
        switch ($key) {
            case 'notify':
                $v = $val === '1' ? 1 : 0;
                Db::q('UPDATE `user` SET `notify` = ? WHERE `id` = ?', [$v, $uid]);
                Db::logEvent($uid, 'set_notify', (string)$v . ' (miniapp)');
                return ['ok' => true, 'notify' => $v === 1];

            case 'pause':
                if ($val === '1') {
                    if ((int)($u['paused'] ?? 0) === 1) return ['ok' => true, 'paused' => true, 'already' => true];
                    Db::q('UPDATE `user` SET `paused` = 1, `paused_since` = NOW() WHERE `id` = ?', [$uid]);
                    Db::logEvent($uid, 'pause_user', 'miniapp');
                    return ['ok' => true, 'paused' => true];
                }
                if ((int)($u['paused'] ?? 0) !== 1) return ['ok' => true, 'paused' => false, 'already' => true];
                $since = !empty($u['paused_since']) ? (int)strtotime((string)$u['paused_since']) : time();
                if ($since <= 0) $since = time();
                $dur = max(0, time() - $since);
                Db::q('UPDATE `user` SET `paused` = 0, `paused_since` = NULL, `paused_total` = `paused_total` + ? WHERE `id` = ?', [$dur, $uid]);
                Db::logEvent($uid, 'resume_user', (string)$dur . ' (miniapp)');
                return ['ok' => true, 'paused' => false, 'pause_dur' => $dur];

            default:
                return ['ok' => false, 'error' => 'unknown_setting'];
        }
    }

    /** مانیتورهایی که دیگران با کاربر به‌اشتراک گذاشته‌اند (فقط نمایش) */
    public static function shared(array $u): array
    {
        $uid = (int)$u['id'];
        try {
            $rows = Db::all(
                'SELECT s.*, sh.`role` AS share_role, own.`name` AS owner_name
                   FROM `site_share` sh
                   JOIN `site` s ON s.id = sh.site_id
                   LEFT JOIN `user` own ON own.id = s.user_id
                  WHERE sh.user_id = ? ORDER BY s.id ASC',
                [$uid]
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        $items = [];
        foreach ($rows as $s) {
            $row = self::lightRow($s);
            $row['role']       = (string)($s['share_role'] ?? 'viewer');
            $row['role_label'] = ((string)($s['share_role'] ?? '') === 'manager') ? 'مدیر' : 'ناظر';
            $row['owner']      = trim((string)($s['owner_name'] ?? ''));
            $items[] = $row;
        }
        return ['items' => $items];
    }

    // ================================================================ عملیات

    /**
     * افزودن مانیتور — موازی Bot::stepAddSite.
     * @return array{ok:bool,error?:string,site_id?:int,state?:string}
     */
    public static function addSite(array $u, string $input): array
    {
        $uid = (int)$u['id'];
        $norm = normalizeTarget($input);
        if (empty($norm['ok']) || empty($norm['target']) || empty($norm['type']) || empty($norm['host'])) {
            return ['ok' => false, 'error' => (string)($norm['error'] ?? 'آدرس معتبر نیست.')];
        }
        $count = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ? AND `chat_id` = 0', [$uid]);
        if ($count >= self::maxSites($u)) {
            return ['ok' => false, 'error' => 'سقف سایت‌های شما (' . faNum(self::maxSites($u)) . ') تکمیل است.'];
        }
        $dup = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 AND `target` = ?', [$uid, $norm['target']]);
        if ($dup > 0) {
            return ['ok' => false, 'error' => 'این سایت قبلاً ثبت شده است.'];
        }
        try {
            $shareToken = uniqueToken(20, static fn(string $t): bool => (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `share_token` = ?', [$t]) > 0);
            Db::q(
                'INSERT INTO `site` (`user_id`,`chat_id`,`target`,`label`,`type`,`host`,`port`,`share_token`,`created_at`) VALUES (?,0,?,?,?,?,?,?,NOW())',
                [$uid, $norm['target'], mb_substr((string)$norm['label'], 0, 200), $norm['type'], $norm['host'], (int)$norm['port'], $shareToken]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'خطا در ثبت سایت؛ دوباره تلاش کنید.'];
        }
        $siteId = (int)Db::val('SELECT `id` FROM `site` WHERE `user_id` = ? AND `target` = ?', [$uid, $norm['target']]);
        if ($siteId <= 0) return ['ok' => false, 'error' => 'ثبت سایت ناموفق بود.'];
        Db::logEvent($uid, 'add_site', (string)$norm['target'] . ' (miniapp)');

        // اولین چک همان لحظه
        $res = Monitor::checkSite($siteId);
        $r = $res['result'] ?? ['ok' => false, 'error' => '—'];
        return [
            'ok'      => true,
            'site_id' => $siteId,
            'state'   => !empty($r['ok']) ? 'up' : 'down',
            'ms'      => (int)($r['ms'] ?? 0),
            'error'   => (string)($r['error'] ?? ''),
        ];
    }

    /** چک فوری (دکمهٔ «چک الآن») */
    public static function checkNow(array $u, int $id): array
    {
        $site = self::ownSite($u, $id);
        if (!$site) return ['ok' => false, 'error' => 'سایت پیدا نشد.'];
        if ((int)$site['paused'] === 1) return ['ok' => false, 'error' => 'این سایت متوقف است؛ اول ادامه را بزنید.'];
        $res = Monitor::checkSite((int)$site['id']);
        $r = $res['result'] ?? [];
        $fresh = Db::one('SELECT * FROM `site` WHERE `id` = ?', [(int)$site['id']]);
        return [
            'ok'     => true,
            'up'     => !empty($r['ok']),
            'ms'     => (int)($r['ms'] ?? 0),
            'error'  => (string)($r['error'] ?? ''),
            'detail' => $fresh ? self::siteDetail($u, (int)$site['id']) : null,
        ];
    }

    /** توقف/ادامهٔ چک یک سایت — موازی دکمه‌های sp/sr ربات */
    public static function setPaused(array $u, int $id, bool $paused): array
    {
        $site = self::ownSite($u, $id);
        if (!$site) return ['ok' => false, 'error' => 'سایت پیدا نشد.'];
        if ($paused) {
            if ((int)$site['paused'] === 1) return ['ok' => true, 'state' => 'paused'];
            Db::q('UPDATE `site` SET `paused` = 1, `paused_since` = NOW() WHERE `id` = ?', [(int)$site['id']]);
            Db::logEvent((int)$u['id'], 'pause_site', (string)$site['target']);
            return ['ok' => true, 'state' => 'paused'];
        }
        if ((int)$site['paused'] !== 1) return ['ok' => true, 'state' => siteState($site)];
        $since = !empty($site['paused_since']) ? (int)strtotime((string)$site['paused_since']) : time();
        $dur = max(0, time() - $since);
        Db::q('UPDATE `site` SET `paused` = 0, `paused_since` = NULL, `paused_total` = `paused_total` + ? WHERE `id` = ?', [$dur, (int)$site['id']]);
        Db::logEvent((int)$u['id'], 'resume_site', (string)$dur);
        return ['ok' => true, 'state' => 'unknown', 'pause_dur' => $dur];
    }

    /** حذف سایت با کل تاریخچه‌اش */
    public static function deleteSite(array $u, int $id): array
    {
        $site = self::ownSite($u, $id);
        if (!$site) return ['ok' => false, 'error' => 'سایت پیدا نشد.'];
        GroupBot::purgeSite((int)$site['id']);
        Db::logEvent((int)$u['id'], 'delete_site', (string)$site['target'] . ' (miniapp)');
        return ['ok' => true];
    }
}
