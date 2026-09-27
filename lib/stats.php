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

    /** خلاصهٔ سایت‌های یک کاربر */
    public static function userSummary(int $userId): array
    {
        $sites = Db::all('SELECT * FROM `site` WHERE `user_id` = ? ORDER BY `id` ASC', [$userId]);
        $out = ['sites' => $sites, 'total' => count($sites), 'up' => 0, 'down' => 0, 'paused' => 0, 'unknown' => 0, 'uptime24' => null];
        $sum = 0; $sumN = 0;
        foreach ($sites as $s) {
            if ((int)$s['paused'] === 1) $out['paused']++;
            elseif ($s['status'] === 'up') $out['up']++;
            elseif ($s['status'] === 'down') $out['down']++;
            else $out['unknown']++;
            $u = self::uptime($s, 1);
            if ($u['pct'] !== null) { $sum += $u['pct']; $sumN++; }
        }
        $out['uptime24'] = $sumN > 0 ? round($sum / $sumN, 2) : null;
        return $out;
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
        $interval = max(10, Db::getInt('check_interval', 20));
        return [
            'interval' => $interval,
            'last_round' => $last,
            'stale' => $last ? (time() - strtotime($last)) : null,
            'paused' => Db::getBool('pause_all', false),
            'cron_healthy' => $last !== null && (time() - strtotime($last)) < max(120, $interval * 3),
        ];
    }
}
