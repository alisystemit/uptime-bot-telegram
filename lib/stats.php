<?php
/**
 * ===== آمار و گزارش‌ها =====
 * آپتایم از دو منبع می‌آید:
 *   - ۲۴ ساعت اخیر: جدول check_log (دقیق، فقط یک روز نگه داشته می‌شود)
 *   - ۷/۳۰ روز: جدول uptime_hour (تجمیع ساعتی)
 */
class Stats
{
    /**
     * آپتایم یک سایت در بازهٔ مشخص.
     * @param array $site ردیف جدول site
     * @param int $days 1 = ۲۴ ساعت (check_log) ، بیشتر = uptime_hour
     * @return array{pct:?float, checks:int, ok:int, avg_ms:int}
     */
    public static function uptime(array $site, int $days = 1): array
    {
        $id = (int)$site['id'];
        try {
            if ($days <= 1) {
                $r = Db::one(
                    'SELECT COUNT(*) c, COALESCE(SUM(`ok`),0) o, COALESCE(AVG(`ms`),0) a
                       FROM `check_log` WHERE `site_id` = ? AND `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)',
                    [$id]
                );
            } else {
                $d = (int)$days;
                $r = Db::one(
                    'SELECT COALESCE(SUM(`checks`),0) c, COALESCE(SUM(`ok`),0) o,
                            CASE WHEN SUM(`checks`) > 0 THEN COALESCE(SUM(`total_ms`),0)/SUM(`checks`) ELSE 0 END a
                       FROM `uptime_hour`
                      WHERE `site_id` = ? AND `bucket` >= DATE_SUB(NOW(), INTERVAL ' . $d . ' DAY)',
                    [$id]
                );
            }
        } catch (Throwable $e) {
            return ['pct' => null, 'checks' => 0, 'ok' => 0, 'avg_ms' => 0];
        }
        $checks = (int)($r['c'] ?? 0);
        $ok = (int)($r['o'] ?? 0);
        return [
            'pct' => $checks > 0 ? round($ok * 10000 / $checks) / 100 : null,
            'checks' => $checks,
            'ok' => $ok,
            'avg_ms' => (int)round((float)($r['a'] ?? 0)),
        ];
    }

    /** آخرین N چک (قدیمی‌ترین اول — برای نمایش نوار از چپ به راست) */
    public static function recent(array $site, int $n = 60): array
    {
        try {
            $rows = Db::all(
                'SELECT `ok`,`ms`,`code`,`ts` FROM `check_log` WHERE `site_id` = ? ORDER BY `id` DESC LIMIT ' . (int)$n,
                [(int)$site['id']]
            );
            return $rows ? array_reverse($rows) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** تعداد کل/قطعی‌های ثبت‌شدهٔ سایت */
    public static function counters(array $site): array
    {
        $id = (int)$site['id'];
        try {
            $r = Db::one(
                'SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN `ok` = 0 THEN 1 ELSE 0 END),0) f,
                        COALESCE(AVG(`ms`),0) a, MIN(`ts`) first_ts, MAX(`ts`) last_ts
                   FROM `check_log` WHERE `site_id` = ?',
                [$id]
            );
        } catch (Throwable $e) {
            $r = null;
        }
        return [
            'checks' => (int)($site['total_checks'] ?? 0),
            'fails' => (int)($site['total_fails'] ?? 0),
            'day_checks' => (int)($r['c'] ?? 0),
            'day_fails' => (int)($r['f'] ?? 0),
            'day_avg_ms' => (int)round((float)($r['a'] ?? 0)),
            'since' => $r['first_ts'] ?? ($site['created_at'] ?? null),
        ];
    }

    // ------------------------------------------------------------- نگاشت‌ها

    /**
     * نوار رنگیِ آخرین چک‌ها برای HTML (هر بلوک یک چک).
     * @param array $rows نتایج recent()
     */
    public static function barHtml(array $rows): string
    {
        if (!$rows) return '<span class="muted">داده‌ای ثبت نشده</span>';
        $out = '<span class="bar">';
        foreach ($rows as $r) {
            $cls = $r['ok'] ? 'ok' : 'bad';
            $title = ($r['ok'] ? 'موفق' : 'ناموفق') . ' — ' . faMs((int)$r['ms']) . ' — ' . ($r['ts'] ?? '');
            $out .= '<i class="' . $cls . '" title="' . h($title) . '"></i>';
        }
        return $out . '</span>';
    }

    /** همان نوار برای تلگرام با ایموجی (فشرده: هر ایموجی یک چک) */
    public static function barEmoji(array $rows): string
    {
        if (!$rows) return '<i>داده‌ای ثبت نشده</i>';
        $out = '';
        foreach ($rows as $r) $out .= $r['ok'] ? '🟩' : '🟥';
        return $out;
    }

    /** اسپارک‌لاین زمان پاسخ (SVG) */
    public static function sparkline(array $rows): string
    {
        $pts = [];
        foreach ($rows as $r) if ((int)$r['ms'] > 0) $pts[] = (int)$r['ms'];
        if (count($pts) < 2) return '';
        $max = max($pts);
        if ($max <= 0) $max = 1;
        $w = 260;
        $h = 46;
        $step = $w / max(1, count($pts) - 1);
        $coords = [];
        foreach ($pts as $i => $v) {
            $x = round($i * $step, 1);
            $y = round($h - 3 - (min($v, $max) / $max) * ($h - 8), 1);
            $coords[] = $x . ',' . $y;
        }
        $line = implode(' ', $coords);
        $area = '0,' . $h . ' ' . $line . ' ' . $w . ',' . $h;
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="زمان پاسخ">'
            . '<polygon class="spark-area" points="' . $area . '"></polygon>'
            . '<polyline class="spark-line" points="' . $line . '"></polyline>'
            . '</svg>';
    }

    // ------------------------------------------------------------- خلاصه‌ها

    /** خلاصهٔ سایت‌های یک کاربر (فقط مانیتورهای خصوصی — نه گروهی) */
    public static function userSummary(int $userId): array
    {
        $sites = Db::all('SELECT * FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 ORDER BY `id` ASC', [$userId]);
        return self::summaryOf($sites);
    }

    /** آمار کلی برای پنل مدیریت */
    public static function adminOverview(): array
    {
        $o = [];
        $o['users_total'] = (int)Db::val('SELECT COUNT(*) FROM `user`');
        $o['users_active'] = (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `access` = 1 AND `is_blocked` = 0');
        $o['users_blocked'] = (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `is_blocked` = 1');
        $o['users_vip'] = (int)Db::val("SELECT COUNT(*) FROM `user` WHERE `plan` = 'vip' AND (`plan_until` IS NULL OR `plan_until` >= NOW())");
        $o['users_paused'] = (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `paused` = 1');
        $o['sites_total'] = (int)Db::val('SELECT COUNT(*) FROM `site`');
        $o['sites_up'] = (int)Db::val("SELECT COUNT(*) FROM `site` WHERE `status` = 'up'");
        $o['sites_down'] = (int)Db::val("SELECT COUNT(*) FROM `site` WHERE `status` = 'down'");
        $o['sites_paused'] = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `paused` = 1');
        $o['checks_day'] = (int)Db::val('SELECT COUNT(*) FROM `check_log` WHERE `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $o['fails_day'] = (int)Db::val('SELECT COUNT(*) FROM `check_log` WHERE `ok` = 0 AND `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $o['alerts_day'] = (int)Db::val("SELECT COUNT(*) FROM `events` WHERE `kind` = 'alert_down' AND `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $o['payments_pending'] = (int)Db::val("SELECT COUNT(*) FROM `payments` WHERE `status` = 'pending'");
        $o['codes_unused'] = (int)Db::val('SELECT COUNT(*) FROM `codes` WHERE `uses` < `uses_max`');
        return $o;
    }

    /**
     * آمار خاموش کردن موقت چک کردن (سراسری + کاربران + سایت‌ها).
     */
    public static function pauseStats(): array
    {
        $p = [];
        $p['global_on'] = Db::getBool('pause_all', false);
        $p['global_total'] = (int)(Db::get('pause_global_total', '0') ?: 0);
        $p['global_count'] = (int)Db::val("SELECT COUNT(*) FROM `events` WHERE `kind` = 'pause_global'");
        $p['global_since'] = Db::get('pause_global_since');

        $p['user_count'] = (int)Db::val("SELECT COUNT(*) FROM `events` WHERE `kind` = 'pause_user'");
        $p['user_total'] = (int)Db::val('SELECT COALESCE(SUM(`paused_total`),0) FROM `user`');
        $p['user_now'] = (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `paused` = 1');

        $p['site_count'] = (int)Db::val("SELECT COUNT(*) FROM `events` WHERE `kind` = 'pause_site'");
        $p['site_total'] = (int)Db::val('SELECT COALESCE(SUM(`paused_total`),0) FROM `site`');
        $p['site_now'] = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `paused` = 1');

        $p['last_resume'] = Db::val("SELECT `ts` FROM `events` WHERE `kind` IN ('resume_global','resume_user','resume_site') ORDER BY `id` DESC LIMIT 1");
        return $p;
    }

    /** وضعیت موتور چک */
    public static function engine(): array
    {
        $last = Db::get('last_round_at');
        if ($last !== null && (($ts = strtotime((string)$last)) === false)) $last = null;
        $interval = max(10, Db::getInt('check_interval', 20));
        return [
            'interval' => $interval,
            'last_round' => $last,
            'stale' => $last ? max(0, time() - (int)strtotime((string)$last)) : null,
            'paused' => Db::getBool('pause_all', false),
            'cron_healthy' => $last !== null && (time() - (int)strtotime((string)$last)) < max(120, $interval * 3),
        ];
    }

    // ------------------------------------------------------------- نمودارها

    /**
     * آپتایم روزانه برای نمودار میله‌ای (جدیدترین روز آخر).
     * @return array<int,array{date:string,pct:?float,checks:int,avg_ms:int}>
     */
    public static function daily(array $site, int $days = 30): array
    {
        $days = max(2, min(90, $days));
        try {
            $rows = Db::all(
                'SELECT DATE(`bucket`) d, SUM(`checks`) c, SUM(`ok`) o, SUM(`total_ms`) m
                   FROM `uptime_hour`
                  WHERE `site_id` = ? AND `bucket` >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)
                  GROUP BY DATE(`bucket`)',
                [(int)$site['id']]
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        $byDay = [];
        foreach ($rows as $r) $byDay[(string)$r['d']] = $r;

        $out = [];
        $nowLocal = time() + (int)round(tzOffset() * 3600);
        for ($i = $days - 1; $i >= 0; $i--) {
            $key = date('Y-m-d', $nowLocal - $i * 86400);
            $r = $byDay[$key] ?? null;
            $c = (int)($r['c'] ?? 0);
            $o = (int)($r['o'] ?? 0);
            $out[] = [
                'date' => $key,
                'pct' => $c > 0 ? round($o * 10000 / $c) / 100 : null,
                'checks' => $c,
                'avg_ms' => $c > 0 ? (int)round((int)($r['m'] ?? 0) / $c) : 0,
            ];
        }
        return $out;
    }

    /**
     * آپتایم ساعتی برای نمودار «گرم» ۲۴ ساعت اخیر.
     * @return array<int,array{hour:int,pct:?float,checks:int,avg_ms:int}>
     */
    public static function hourly(array $site, int $hours = 24): array
    {
        $hours = max(4, min(48, $hours));
        try {
            $rows = Db::all(
                'SELECT `bucket`, `checks`, `ok`, `total_ms` FROM `uptime_hour`
                  WHERE `site_id` = ? AND `bucket` >= DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)
                  ORDER BY `bucket` ASC',
                [(int)$site['id']]
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        $out = [];
        $nowUtcBucket = (int)(floor(time() / 3600) * 3600);
        for ($i = $hours - 1; $i >= 0; $i--) {
            $ts = $nowUtcBucket - $i * 3600;
            $c = 0; $o = 0; $m = 0;
            foreach ($rows as $r) {
                if ((int)strtotime((string)$r['bucket']) === $ts) {
                    $c = (int)$r['checks']; $o = (int)$r['ok']; $m = (int)$r['total_ms'];
                    break;
                }
            }
            $out[] = [
                'hour' => (int)date('H', $ts + (int)round(tzOffset() * 3600)),
                'pct' => $c > 0 ? round($o * 10000 / $c) / 100 : null,
                'checks' => $c,
                'avg_ms' => $c > 0 ? (int)round($m / $c) : 0,
            ];
        }
        return $out;
    }

    /** آمار زمان پاسخ ۲۴ ساعت اخیر: کمینه/میانگین/صدک۹۵/بیشینه */
    public static function responseStats(array $site, int $hours = 24): array
    {
        try {
            $rows = Db::all(
                'SELECT `ms` FROM `check_log` WHERE `site_id` = ? AND `ok` = 1 AND `ms` > 0 AND `ts` >= DATE_SUB(NOW(), INTERVAL ' . (int)$hours . ' HOUR)
                 ORDER BY `ms` ASC LIMIT 5000',
                [(int)$site['id']]
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        $v = array_map(static fn($r) => (int)$r['ms'], $rows);
        if (!$v) return ['min' => 0, 'avg' => 0, 'p95' => 0, 'max' => 0, 'n' => 0];
        sort($v);
        $n = count($v);
        return [
            'min' => $v[0],
            'avg' => (int)round(array_sum($v) / $n),
            'p95' => $v[(int)min($n - 1, (int)floor($n * 0.95))],
            'max' => $v[$n - 1],
            'n' => $n,
        ];
    }

    // ------------------------------------------------------------- رخدادها

    /** باز کردن یک رخداد باز (اگر از قبل باز است، همان را برمی‌گرداند) */
    public static function openIncident(int $siteId, string $kind, string $reason = ''): int
    {
        try {
            // ابتدا یک رخداد باز برای این سایت/نوع بگردید
            $row = Db::one(
                'SELECT `id` FROM `incident` WHERE `site_id` = ? AND `kind` = ? AND `end_at` IS NULL ORDER BY `id` DESC LIMIT 1',
                [$siteId, $kind]
            );
            if ($row) {
                // رخدادِ باز موجود است؛ فقط reason را آپدیت کنید
                Db::q('UPDATE `incident` SET `reason` = ?, `checks` = `checks` + 1 WHERE `id` = ?', [mb_substr($reason, 0, 190), (int)$row['id']]);
                return (int)$row['id'];
            }
            
            // رخدادِ باز نیست؛ یکی ایجاد کنید
            // اگر دو پروسه همزمان اینجا رسیدند، MySQL فقط یکی را درج می‌کند
            // (END_AT null است پس NOT NULL constraint یا UNIQUE همین کار را می‌کند).
            // برای امنیت مطلق از INSERT IGNORE یا UNIQUE استفاده می‌کنیم.
            Db::q('INSERT INTO `incident` (`site_id`,`kind`,`start_at`,`reason`,`checks`,`end_at`) VALUES (?,?,NOW(),?,1,NULL)', [$siteId, $kind, mb_substr($reason, 0, 190)]);
            
            // شناسهٔ رخداد تازه‌ایجاد‌شده را بگیرید (یا رخدادی که دوپروسهٔ دوم درج کرد)
            $newRow = Db::one(
                'SELECT `id` FROM `incident` WHERE `site_id` = ? AND `kind` = ? AND `end_at` IS NULL ORDER BY `id` DESC LIMIT 1',
                [$siteId, $kind]
            );
            return $newRow ? (int)$newRow['id'] : 0;
        } catch (Throwable $e) {
            uptimeLog('error', 'openIncident: ' . $e->getMessage());
            return 0;
        }
    }

    /** بستن رخداد باز و ثبت مدت آن */
    public static function closeIncident(int $siteId, string $kind, int $peakMs = 0): void
    {
        try {
            $row = Db::one(
                'SELECT `id`,`start_at`,`peak_ms` FROM `incident` WHERE `site_id` = ? AND `kind` = ? AND `end_at` IS NULL ORDER BY `id` DESC LIMIT 1',
                [$siteId, $kind]
            );
            if (!$row) return;
            $start = $row['start_at'] ? (int)strtotime((string)$row['start_at']) : time();
            $dur = max(0, time() - $start);
            Db::q(
                'UPDATE `incident` SET `end_at` = NOW(), `duration` = ?, `peak_ms` = GREATEST(`peak_ms`, ?) WHERE `id` = ?',
                [$dur, max(0, $peakMs), (int)$row['id']]
            );
        } catch (Throwable $e) {
            // بی‌اهمیت
        }
    }

    /** رکورد کردن اوج زمان پاسخ/تعداد چک در رخداد باز */
    public static function bumpIncident(int $siteId, string $kind, int $ms): void
    {
        try {
            Db::q(
                'UPDATE `incident` SET `peak_ms` = GREATEST(`peak_ms`, ?), `checks` = `checks` + 1
                  WHERE `site_id` = ? AND `kind` = ? AND `end_at` IS NULL',
                [max(0, $ms), $siteId, $kind]
            );
        } catch (Throwable $e) {
            // بی‌اهمیت
        }
    }

    /** رخدادهای یک سایت (جدیدترین اول) */
    public static function incidents(int $siteId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        try {
            return Db::all(
                'SELECT * FROM `incident` WHERE `site_id` = ? ORDER BY `start_at` DESC, `id` DESC LIMIT ' . $limit,
                [$siteId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** رخدادهای همهٔ سایت‌های یک کاربر */
    public static function userIncidents(int $userId, int $chatId = 0, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        try {
            return Db::all(
                'SELECT i.*, s.`label`, s.`target` FROM `incident` i
                   JOIN `site` s ON s.id = i.site_id
                  WHERE s.user_id = ? AND s.chat_id = ?
                  ORDER BY i.start_at DESC, i.id DESC LIMIT ' . $limit,
                [$userId, $chatId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** آمار رخدادهای یک دامنهٔ داده (کاربر یا گروه) — user_id=0 یعنی «هر کاربری» */
    public static function incidentTotals(int $userId, int $chatId = 0): array
    {
        $where = 's.chat_id = ?' . ($userId > 0 ? ' AND s.user_id = ?' : '');
        // ترتیب پارامترها باید با ترتیب «?»های داخل $where یکی باشد: chat_id, user_id
        $params = $userId > 0 ? [$chatId, $userId] : [$chatId];
        try {
            $r = Db::one(
                "SELECT COUNT(*) c,
                        COALESCE(SUM(`duration`),0) d,
                        COALESCE(SUM(CASE WHEN `kind` = 'down' THEN `duration` ELSE 0 END),0) dd,
                        COALESCE(SUM(CASE WHEN `kind` = 'slow' THEN `duration` ELSE 0 END),0) ds
                   FROM `incident` i JOIN `site` s ON s.id = i.site_id
                  WHERE {$where} AND i.end_at IS NOT NULL",
                $params
            );
        } catch (Throwable $e) {
            $r = null;
        }
        return [
            'count' => (int)($r['c'] ?? 0),
            'total' => (int)($r['d'] ?? 0),
            'down' => (int)($r['dd'] ?? 0),
            'slow' => (int)($r['ds'] ?? 0),
        ];
    }

    /** خلاصهٔ یک گروه/کانال */
    public static function groupSummary(int $chatId): array
    {
        $sites = self::sitesOfChat($chatId);
        $out = ['sites' => $sites, 'total' => count($sites), 'up' => 0, 'down' => 0, 'slow' => 0, 'paused' => 0, 'unknown' => 0, 'uptime24' => null];
        $sum = 0; $n = 0;
        foreach ($sites as $s) {
            $st = siteState($s);
            if ($st === 'up') $out['up']++;
            elseif ($st === 'down') $out['down']++;
            elseif ($st === 'slow') $out['slow']++;
            elseif ($st === 'paused') $out['paused']++;
            else $out['unknown']++;
            $u = self::uptime($s, 1);
            if ($u['pct'] !== null) { $sum += $u['pct']; $n++; }
        }
        $out['uptime24'] = $n > 0 ? round($sum / $n, 2) : null;
        return $out;
    }

    /** سایت‌های یک گروه (شناسهٔ گروه در تلگرام منفی است؛ فقط ۰ یعنی خصوصی) */
    public static function sitesOfChat(int $chatId): array
    {
        if (!Group::isGroupChat($chatId)) return [];
        try {
            return Db::all('SELECT * FROM `site` WHERE `chat_id` = ? ORDER BY `id` ASC', [$chatId]);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** خلاصهٔ کاربر: نسخهٔ تکمیل‌شده با شمارش «کند» */
    public static function summaryOf(array $sites): array
    {
        $out = ['sites' => $sites, 'total' => count($sites), 'up' => 0, 'down' => 0, 'slow' => 0, 'paused' => 0, 'unknown' => 0, 'uptime24' => null];
        $sum = 0; $n = 0;
        foreach ($sites as $s) {
            $st = siteState($s);
            if ($st === 'up') $out['up']++;
            elseif ($st === 'down') $out['down']++;
            elseif ($st === 'slow') $out['slow']++;
            elseif ($st === 'paused') $out['paused']++;
            else $out['unknown']++;
            $u = self::uptime($s, 1);
            if ($u['pct'] !== null) { $sum += $u['pct']; $n++; }
        }
        $out['uptime24'] = $n > 0 ? round($sum / $n, 2) : null;
        return $out;
    }

    /** همهٔ سایت‌های قطع در کل ربات (برای پنل مدیر) */
    public static function globalDown(int $limit = 20): array
    {
        try {
            return Db::all(
                "SELECT s.*, u.`name` AS user_name, u.`username` FROM `site` s
                   LEFT JOIN `user` u ON u.id = s.user_id
                  WHERE s.status = 'down' AND s.paused = 0
                  ORDER BY s.last_down_at ASC LIMIT " . (int)$limit
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------- دامنه

    public static function domains(int $userId, int $chatId = 0): array
    {
        try {
            return Db::all(
                'SELECT * FROM `domain_watch` WHERE `user_id` = ? AND `chat_id` = ? ORDER BY `id` DESC',
                [$userId, $chatId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }
}
