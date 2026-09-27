<?php
/**
 * ===== ساخت دادهٔ صفحهٔ وضعیت (مشترک بین status.php و api.php) =====
 *
 * یک صفحهٔ وضعیت می‌تواند سه حالت داشته باشد:
 *   u=<share_token>  → همهٔ مانیتورهای خصوصی یک کاربر
 *   g=<share_token>  → همهٔ مانیتورهای یک گروه یا کانال
 *   s=<share_token>  → فقط یک مانیتور (لینک جداگانه)
 *
 * Page::resolve() هر سه حالت را به یک ساختار مشترک تبدیل می‌کند و
 * Page::payload() همان JSON را برای به‌روزرسانی زنده می‌سازد.
 */
class Page
{
    public const KIND_USER  = 'user';
    public const KIND_GROUP = 'group';
    public const KIND_SITE  = 'site';

    /**
     * @return array{kind:string, title:string, owner:string, token:string, sites:array, extra:array}|null
     */
    public static function resolve(string $u, string $g, string $s): ?array
    {
        $s = trim($s);
        $g = trim($g);
        $u = trim($u);

        if ($s !== '') {
            $site = Db::one('SELECT * FROM `site` WHERE `share_token` = ?', [$s]);
            if (!$site) return null;
            return [
                'kind'  => self::KIND_SITE,
                'title' => (string)($site['label'] !== '' ? $site['label'] : $site['target']),
                'owner' => self::ownerOf((int)$site['user_id'], (int)$site['chat_id']),
                'token' => $s,
                'sites' => [$site],
                'extra' => [],
            ];
        }

        if ($g !== '') {
            $hub = Group::byToken($g);
            if (!$hub) return null;
            $sites = Stats::sitesOfChat((int)$hub['chat_id']);
            return [
                'kind'  => self::KIND_GROUP,
                'title' => (string)$hub['title'] !== '' ? (string)$hub['title'] : 'مانیتورینگ گروه',
                'owner' => (string)$hub['chat_type'] === 'channel' ? 'کانال' : 'گروه',
                'token' => $g,
                'sites' => $sites,
                'extra' => ['chat_id' => (int)$hub['chat_id']],
            ];
        }

        if ($u !== '') {
            $user = Db::one('SELECT `id`,`name`,`username` FROM `user` WHERE `share_token` = ?', [$u]);
            if (!$user) return null;
            $sites = Stats::userSummary((int)$user['id'])['sites'];
            return [
                'kind'  => self::KIND_USER,
                'title' => trim((string)$user['name']) !== '' ? (string)$user['name'] : 'کاربر',
                'owner' => $user['username'] ? '@' . $user['username'] : 'ربات مانیتورینگ',
                'token' => $u,
                'sites' => $sites,
                'extra' => ['user_id' => (int)$user['id']],
            ];
        }

        return null;
    }

    private static function ownerOf(int $userId, int $chatId): string
    {
        if (Group::isGroupChat($chatId)) {
            $hub = Group::get($chatId);
            if ($hub) return (string)$hub['title'] !== '' ? (string)$hub['title'] : 'گروه';
        }
        $u = Db::one('SELECT `name`,`username` FROM `user` WHERE `id` = ?', [$userId]);
        if (!$u) return 'ربات مانیتورینگ';
        if (!empty($u['username'])) return '@' . $u['username'];
        return (string)$u['name'] !== '' ? (string)$u['name'] : 'ربات مانیتورینگ';
    }

    /**
     * ساخت JSON کامل برای صفحهٔ وضعیت.
     * @return array<string,mixed>
     */
    public static function payload(array $ctx, bool $light = true): array
    {
        $tz = tzOffset();
        $summary = Stats::summaryOf($ctx['sites']);
        $engine = Stats::engine();

        $sites = [];
        foreach ($summary['sites'] as $s) {
            $state = siteState($s);
            $st = $state === 'paused' ? 'paused' : (string)$s['status'];
            $u1 = Stats::uptime($s, 1);
            $u7 = Stats::uptime($s, 7);
            $u30 = Stats::uptime($s, 30);
            $cnt = Stats::counters($s);

            $mk = static function (array $u): array {
                $p = $u['pct'];
                $cls = $p === null ? 'na' : ($p >= 99.5 ? 'great' : ($p >= 95 ? 'good' : ($p >= 80 ? 'warn' : 'bad')));
                return ['pct' => $p, 'cls' => $cls, 'checks' => (int)$u['checks']];
            };

            $err = trim((string)$s['last_error']);
            if ($st === 'down' && $err !== '') {
                $note = '<div class="errline">⚠️ ' . h($err) . '</div>';
            } elseif ($state === 'slow') {
                $note = '<div class="slowline">🟠 پاسخ کند: ' . faMs((int)$s['last_ms']) . ' (حد ' . faMs((int)$s['max_ms']) . ')</div>';
            } elseif ($st === 'up' && (int)$s['last_code'] > 0) {
                $note = '<div class="okline">کد پاسخ: ' . faNum((int)$s['last_code']) . ' • زمان پاسخ: ' . faMs((int)$s['last_ms']) . '</div>';
            } elseif ($st === 'paused') {
                $note = '<div class="pauseline">این سایت موقتاً از چک خارج شده است.</div>';
            } else {
                $note = '';
            }

            $row = [
                'id'           => (int)$s['id'],
                'label'        => (string)($s['label'] !== '' ? $s['label'] : $s['target']),
                'target'       => (string)$s['target'],
                'type'         => (string)$s['type'],
                'state'        => $state,
                'cls'          => stateClass($state),
                'status_label' => ['up' => 'فعال', 'down' => 'قطع', 'slow' => 'کند', 'paused' => 'متوقف', 'unknown' => 'نامشخص'][$state] ?? $state,
                'status'       => $st,
                'ms'           => (int)$s['last_ms'],
                'code'         => (int)$s['last_code'],
                'error'        => $err,
                'last_check'   => $s['last_check_at'],
                'last_check_ago' => timeAgo($s['last_check_at'], $tz),
                'total_checks' => (int)$cnt['checks'],
                'total_fails'  => (int)$cnt['fails'],
                'u1'           => $mk($u1),
                'u7'           => $mk($u7),
                'u30'          => $mk($u30),
                'bar_html'     => Stats::barHtml(Stats::recent($s, 60)),
                'note_html'    => $note,
            ];

            if (!$light) {
                // نسخهٔ کامل: نمودارها، رخدادها، SSL و آستانه‌ها
                $row['daily'] = self::dailyFor($s);
                $row['hourly'] = self::hourlyFor($s);
                $row['sparkline'] = Stats::sparkline(Stats::recent($s, 60));
                $row['resp'] = [
                    'min' => (int)$s['resp_avg'] > 0 ? Stats::responseStats($s)['min'] : 0,
                    'avg' => (int)$s['resp_avg'],
                    'p95' => (int)$s['resp_p95'],
                    'max' => (int)$s['resp_max'],
                ];
                $row['max_ms'] = (int)$s['max_ms'];
                $row['keyword'] = (string)$s['keyword'];
                $row['ssl'] = self::sslFor($s);
                $row['incidents'] = self::incidentsFor($s);
            }
            $sites[] = $row;
        }

        $out = [
            'ok'      => true,
            'kind'    => $ctx['kind'],
            'title'   => $ctx['title'],
            'owner'   => $ctx['owner'],
            'updated' => faNum(date('Y/m/d H:i:s', time() + (int)round($tz * 3600))),
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
                'last_round'   => $engine['last_round'],
                'stale_sec'    => $engine['stale'],
            ],
            'sites'   => $sites,
        ];

        if (!$light) {
            if ($ctx['kind'] === self::KIND_USER) {
                $out['totals'] = Stats::incidentTotals((int)($ctx['extra']['user_id'] ?? 0), 0);
            } elseif ($ctx['kind'] === self::KIND_GROUP) {
                $out['totals'] = Stats::incidentTotals(0, (int)($ctx['extra']['chat_id'] ?? 0));
            }
        }

        return $out;
    }

    /** میله‌های روزانه به شکل سبک: [0..۱۰۰ یا null] + تاریخ */
    public static function dailyFor(array $s): array
    {
        $out = [];
        foreach (Stats::daily($s, 30) as $d) $out[] = ['d' => $d['date'], 'p' => $d['pct'], 'c' => $d['checks'], 'm' => $d['avg_ms']];
        return $out;
    }

    public static function hourlyFor(array $s): array
    {
        $out = [];
        foreach (Stats::hourly($s, 24) as $d) $out[] = ['h' => $d['hour'], 'p' => $d['pct'], 'c' => $d['checks'], 'm' => $d['avg_ms']];
        return $out;
    }

    public static function sslFor(array $s): array
    {
        return [
            'checked'  => (string)($s['ssl_check_at'] ?? '') !== '',
            'days'     => (int)$s['ssl_days'],
            'expires'  => $s['ssl_expires_at'] ?? null,
            'issuer'   => (string)$s['ssl_issuer'],
            'error'    => (string)$s['ssl_error'],
            'ago'      => timeAgo($s['ssl_check_at'], tzOffset()),
        ];
    }

    public static function incidentsFor(array $s, int $limit = 5): array
    {
        $out = [];
        foreach (Stats::incidents((int)$s['id'], $limit) as $i) {
            $open = empty($i['end_at']);
            $out[] = [
                'kind'   => (string)$i['kind'],
                'icon'   => ((string)$i['kind'] === 'down' ? '🔴' : '🟠'),
                'title'  => ((string)$i['kind'] === 'down' ? 'قطعی' : 'کندی'),
                'start'  => faDateTime((string)$i['start_at'], tzOffset()),
                'ago'    => timeAgo((string)$i['start_at'], tzOffset()),
                'dur'    => faDuration($open ? max(0, time() - (int)strtotime((string)$i['start_at'])) : (int)$i['duration']),
                'open'   => $open,
                'reason' => (string)$i['reason'],
            ];
        }
        return $out;
    }
}
