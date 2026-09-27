<?php
/**
 * ===== موتور مانیتورینگ (Probe + Round + اطلاع‌رسانی) =====
 *
 * سه نوع بررسی:
 *   http → درخواست واقعی به URL (کد وضعیت + زمان پاسخ)
 *   tcp  → اتصال به پورت (غیرهمزمان با stream_select)
 *   ping → ICMP با دستور ping (پروسه‌های موازی با proc_open)
 *
 * هر «راند» همهٔ سایت‌های موعددار را به‌صورت دسته‌ای بررسی می‌کند تا در همان
 * بازهٔ ۲۰ ثانیه‌ای تمام شود. اجرای همزمان دو راند با flock رد می‌شود.
 */
class Monitor
{
    /** حداقل فاصلهٔ دو راند (ثانیه) — جلوی فشار بیش از حد به هدف‌ها */
    public const MIN_GAP = 10;

    /** حداکثر عمر تکرارِ هشدار قطعی (ثانیه) */
    public const REPEAT_ALERT = 21600; // 6h

    private static $lockFh = null;

    // ---------------------------------------------------------------- قفل

    private static function lock(string $name)
    {
        $dir = UPTIME_ROOT . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fh = @fopen($dir . '/' . $name . '.lock', 'c');
        if ($fh === false) return null;
        if (!@flock($fh, LOCK_EX | LOCK_NB)) {
            @fclose($fh);
            return false; // راند دیگری در حال اجراست
        }
        return $fh;
    }

    private static function unlock($fh): void
    {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    // ---------------------------------------------------------------- راند

    /**
     * یک راند کامل چک کردن.
     * @return array{skipped?:string, total?:int, up?:int, down?:int, alerts?:int, ms?:int}
     */
    public static function round(): array
    {
        $lock = self::lock('round');
        if ($lock === false) return ['skipped' => 'busy'];
        if ($lock === null) $lock = fopen('php://memory', 'r'); // بدون فایل سیستم هم ادامه بده

        try {
            if (Db::getBool('pause_all', false)) {
                return ['skipped' => 'paused'];
            }
            $interval = max(self::MIN_GAP, Db::getInt('check_interval', 20));
            // لحظهٔ شروع راند — مبنای موعدِ نوبت بعدی (توضیح کامل در record())
            $roundTs = date('Y-m-d H:i:s');
            $sites = Db::all(
                "SELECT s.*, u.notify AS user_notify, u.name AS user_name
                   FROM `site` s
                   JOIN `user` u ON u.id = s.user_id
                  WHERE s.paused = 0
                    AND u.paused = 0
                    AND u.is_blocked = 0
                    AND u.access = 1
                    AND (s.last_check_at IS NULL OR s.last_check_at <= DATE_SUB(NOW(), INTERVAL " . $interval . " SECOND))"
            );
            if (!$sites) {
                // راند واقعاً اجرا شد (با وجود نبودِ سایت)؛ آخرین راند را ثبت کن
                // تا پنل مدیریت کرون را «در حال اجرا» ببیند.
                Db::set('last_round_at', date('Y-m-d H:i:s'));
                self::maybePrune();
                return ['total' => 0, 'up' => 0, 'down' => 0, 'alerts' => 0];
            }

            $groups = ['http' => [], 'tcp' => [], 'ping' => []];
            foreach ($sites as $s) {
                $t = in_array($s['type'], ['http', 'tcp', 'ping'], true) ? $s['type'] : 'ping';
                $groups[$t][] = $s;
            }

            $results = [];
            if ($groups['http']) $results += self::probeHttpBatch($groups['http']);
            if ($groups['tcp']) $results += self::probeTcpBatch($groups['tcp']);
            if ($groups['ping']) $results += self::probePingBatch($groups['ping']);

            $stats = ['total' => count($sites), 'up' => 0, 'down' => 0, 'alerts' => 0, 'ms' => 0];
            foreach ($sites as $s) {
                $res = $results[(int)$s['id']] ?? ['ok' => false, 'ms' => 0, 'code' => 0, 'error' => 'timeout: بررسی تمام نشد'];
                self::record($s, $res, $stats, $roundTs);
            }

            Db::set('last_round_at', date('Y-m-d H:i:s'));
            self::maybePrune();
            return $stats;
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * اگر راندِ تازه‌ای نبوده (مثلاً کرون راه نیست) یک راند اجرا کن — برای صفحهٔ وب.
     * با فاصلهٔ ایمنی ۷۵ ثانیه تا همیشه با کرون جلو نیفتد.
     */
    public static function roundIfDue(): bool
    {
        $interval = max(self::MIN_GAP, Db::getInt('check_interval', 20));
        $last = Db::get('last_round_at');
        if ($last !== null && (time() - strtotime($last)) < max(75, $interval * 3)) return false;
        if (Db::getBool('pause_all', false)) return false;
        $r = self::round();
        return !isset($r['skipped']);
    }

    /**
     * چکِ فوریِ یک سایت (دکمهٔ «🔁 چک الآن»).
     */
    public static function checkSite(int $siteId): array
    {
        $site = Db::one('SELECT s.*, u.notify AS user_notify, u.name AS user_name FROM `site` s JOIN `user` u ON u.id = s.user_id WHERE s.id = ?', [$siteId]);
        if (!$site) return ['ok' => false, 'error' => 'سایت پیدا نشد'];
        $res = self::probeSite($site);
        $roundTs = date('Y-m-d H:i:s');
        $stats = ['total' => 1, 'up' => 0, 'down' => 0, 'alerts' => 0, 'ms' => 0];
        self::record($site, $res, $stats, $roundTs);
        $stats['result'] = $res;
        return $stats;
    }

    // ---------------------------------------------------------------- بررسی تکی

    /** بررسی تکی یک سایت (برای «چک الآن») */
    public static function probeSite(array $site): array
    {
        $type = $site['type'] ?? 'ping';
        if ($type === 'http') return self::probeHttpBatch([$site])[(int)$site['id']] ?? self::fail('نامشخص');
        if ($type === 'tcp') return self::probeTcpBatch([$site])[(int)$site['id']] ?? self::fail('نامشخص');
        $r = self::probePingBatch([$site], 4.5);
        return $r[(int)$site['id']] ?? self::fail('نامشخص');
    }

    private static function fail(string $err, int $code = 0): array
    {
        return ['ok' => false, 'ms' => 0, 'code' => $code, 'error' => $err];
    }

    // ---------------------------------------------------------------- HTTP

    /** @param array[] $sites @return array<int,array> */
    private static function probeHttpBatch(array $sites): array
    {
        $out = [];
        if (!function_exists('curl_multi_init')) {
            foreach ($sites as $s) $out[(int)$s['id']] = self::fail('curl در دسترس نیست');
            return $out;
        }
        $mh = curl_multi_init();
        $map = [];
        foreach ($sites as $s) {
            // ستون url در جدول نیست؛ هدفِ نوع http خودش یک URL کامل است
            $url = (string)($s['url'] ?? '');
            if ($url === '' && preg_match('#^https?://#i', (string)($s['target'] ?? ''))) {
                $url = (string)$s['target'];
            }
            if ($url === '') $url = 'http://' . $s['host'] . (!empty($s['port']) ? ':' . $s['port'] : '');
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT_MS => 5000,
                CURLOPT_TIMEOUT_MS => 9000,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'UptimeMonitor/1.0',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                // فقط سرآخواست را می‌خواهیم؛ اولین بایتِ بدنه → قطع اتصال
                CURLOPT_WRITEFUNCTION => function () { return 0; },
            ]);
            if (defined('CURLOPT_PROTOCOLS')) @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_multi_add_handle($mh, $ch);
            $map[spl_object_id($ch)] = ['ch' => $ch, 'site' => $s, 'start' => microtime(true)];
        }

        $running = null;
        $deadline = microtime(true) + 12;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running && microtime(true) < $deadline) curl_multi_select($mh, 0.2);
        } while ($running && $status === CURLM_OK && microtime(true) < $deadline);

        foreach ($map as $item) {
            $ch = $item['ch'];
            $s = $item['site'];
            $id = (int)$s['id'];
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $total = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $startT = (float)curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
            $err = (string)curl_error($ch);
            $ms = (int)round(max($total, $startT) * 1000);
            if ($total <= 0) $ms = (int)round((microtime(true) - $item['start']) * 1000);

            if ($code > 0) {
                // سرور جواب داده است؛ فقط خطای ۵xx یعنی سرویس از کار افتاده
                $ok = $code < 500;
                $out[$id] = [
                    'ok' => $ok,
                    'ms' => $ms,
                    'code' => $code,
                    'error' => $ok ? '' : ('پاسخ سرور: HTTP ' . $code),
                    'detail' => 'HTTP ' . $code,
                ];
            } else {
                $out[$id] = [
                    'ok' => false,
                    'ms' => $ms,
                    'code' => 0,
                    'error' => $err !== '' && stripos($err, 'write') === false ? $err : 'پاسخی دریافت نشد (Timeout یا بسته شدن اتصال)',
                    'detail' => 'no response',
                ];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    // ---------------------------------------------------------------- TCP

    /** اتصال غیرهمزمان به پورت‌ها با deadline مشترک */
    private static function probeTcpBatch(array $sites): array
    {
        $out = [];
        $pending = [];
        foreach ($sites as $s) {
            $id = (int)$s['id'];
            $host = $s['host'];
            $port = (int)$s['port'];
            if ($port <= 0) {
                $out[$id] = self::fail('پورتی تعیین نشده');
                continue;
            }
            if (filter_var($host, FILTER_VALIDATE_IP) === false && strpos($host, ':') !== false) $host = '[' . $host . ']';
            $errno = 0; $errstr = '';
            $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if (!is_resource($fp)) {
                $out[$id] = self::fail('اتصال رد شد: ' . ($errstr !== '' ? $errstr : 'خطای اتصال'));
                continue;
            }
            stream_set_blocking($fp, false);
            $pending[(int)$fp] = ['fp' => $fp, 'site' => $s, 'start' => microtime(true)];
        }
        if (!$pending) return $out;

        $deadline = microtime(true) + 5;
        while ($pending && microtime(true) < $deadline) {
            $write = [];
            foreach ($pending as $p) $write[] = $p['fp'];
            $read = [];
            $except = $write;
            $n = @stream_select($read, $write, $except, 0, 200000);
            if ($n === false) break;
            foreach ($write as $fp) {
                $id = (int)$fp;
                if (!isset($pending[$id])) continue;
                $ms = (int)round((microtime(true) - $pending[$id]['start']) * 1000);
                $out[(int)$pending[$id]['site']['id']] = ['ok' => true, 'ms' => max(1, $ms), 'code' => 0, 'error' => '', 'detail' => 'TCP باز'];
                @fclose($fp);
                unset($pending[$id]);
            }
            foreach ($except as $fp) {
                $id = (int)$fp;
                if (!isset($pending[$id])) continue;
                $out[(int)$pending[$id]['site']['id']] = self::fail('اتصال برقرار نشد (پورت بسته است)');
                @fclose($fp);
                unset($pending[$id]);
            }
        }
        // باقی‌مانده‌ها = timeout
        foreach ($pending as $p) {
            $out[(int)$p['site']['id']] = self::fail('عدم پاسخ پورت (Timeout)');
            @fclose($p['fp']);
        }
        return $out;
    }

    // ---------------------------------------------------------------- PING

    /**
     * اجرای موازی ping با proc_open و گرفتن کد خروج (بدون وابستگی به زبان خروجی).
     * اگر proc_open در دسترس نباشد، به TCP روی پورت ۴۴۳ برمی‌گردد.
     */
    private static function probePingBatch(array $sites, float $timeout = 4.5): array
    {
        $out = [];
        if (!function_exists('proc_open')) {
            foreach ($sites as $s) {
                $clone = $s;
                $clone['port'] = 443;
                $r = self::probeTcpBatch([$clone])[(int)$s['id']] ?? self::fail('proc_open در دسترس نیست');
                $r['detail'] = 'TCP (جایگزین پینگ)';
                $out[(int)$s['id']] = $r;
            }
            return $out;
        }

        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $procs = [];
        foreach ($sites as $s) {
            $host = $s['host'];
            if (!preg_match('/^[a-zA-Z0-9\.\-:\[\]]+$/', $host)) {
                $out[(int)$s['id']] = self::fail('هاست نامعتبر');
                continue;
            }
            $cmd = $isWin
                ? 'ping -n 1 -w 2000 ' . $host
                : 'ping -c 1 -W 2 ' . $host;
            $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $pipes = [];
            $proc = @proc_open($cmd, $spec, $pipes);
            if (!is_resource($proc)) {
                $out[(int)$s['id']] = self::fail('اجرای ping ممکن نشد');
                continue;
            }
            foreach ($pipes as $p) @stream_set_blocking($p, false);
            $procs[] = ['proc' => $proc, 'pipes' => $pipes, 'site' => $s, 'start' => microtime(true)];
        }
        if (!$procs) return $out;

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $running = false;
            foreach ($procs as $pr) {
                $st = @proc_get_status($pr['proc']);
                if (!empty($st['running'])) $running = true;
            }
            if (!$running) break;
            usleep(100000);
        }

        foreach ($procs as $pr) {
            $s = $pr['site'];
            $id = (int)$s['id'];
            $st = @proc_get_status($pr['proc']);
            if (is_array($st) && !empty($st['running'])) @proc_terminate($pr['proc']);
            $stdout = @stream_get_contents($pr['pipes'][1]);
            $stderr = @stream_get_contents($pr['pipes'][2]);
            foreach ($pr['pipes'] as $p) { if (is_resource($p)) @fclose($p); }
            $st = @proc_get_status($pr['proc']);
            $exit = is_array($st) && array_key_exists('exitcode', $st) && (int)$st['exitcode'] !== -1
                ? (int)$st['exitcode']
                : @proc_close($pr['proc']);
            $outText = $stdout . "\n" . $stderr;
            $elapsed = (int)round((microtime(true) - $pr['start']) * 1000);

            $ms = 0;
            if (preg_match('/time[=<]\s*([\d.,]+)\s*ms/i', $outText, $m)) {
                $ms = (int)round((float)str_replace(',', '.', $m[1]));
            } elseif (preg_match('/زمان[=\s]*([\d.,]+)/u', $outText, $m)) {
                $ms = (int)round((float)str_replace(',', '.', $m[1]));
            }
            if ($ms <= 0 && $exit === 0) $ms = max(1, min($elapsed, 2000));

            $ok = ($exit === 0);
            $out[$id] = [
                'ok' => $ok,
                'ms' => $ms,
                'code' => 0,
                'error' => $ok ? '' : 'پینگ بدون پاسخ (Timeout / 100% loss)',
                'detail' => 'ICMP',
            ];
            if (!is_resource($st['resource'] ?? null)) @proc_close($pr['proc']);
        }
        return $out;
    }

    // ---------------------------------------------------------------- ثبت نتیجه

    /**
     * ثبت نتیجهٔ یک چک در دیتابیس + تشخیص تغییر وضعیت + اطلاع‌رسانی.
     *
     * @param string $now لحظهٔ «شروعِ» راند (YYYY-MM-DD HH:MM:SS، به وقت UTC).
     *   ثبتِ زمانِ شروع باعث می‌شود فاصلهٔ واقعیِ دو چک دقیقاً check_interval
     *   شود؛ اگر لحظهٔ پایانِ راند ثبت شود، هر راند یک «چکِ خالیِ» اضافه می‌ساخت.
     */
    private static function record(array $site, array $res, array &$stats, string $now): void
    {
        $id = (int)$site['id'];
        $ok = !empty($res['ok']);
        $ms = max(0, (int)($res['ms'] ?? 0));
        $code = (int)($res['code'] ?? 0);
        $err = mb_substr((string)($res['error'] ?? ''), 0, 190);

        try {
            Db::q('INSERT INTO `check_log` (`site_id`,`ts`,`ok`,`ms`,`code`,`error`) VALUES (?,?,?,?,?,?)', [$id, $now, $ok ? 1 : 0, $ms, $code, $err]);
            // تجمیع ساعتی برای آپتایم ۷/۳۰ روزه
            Db::q(
                'INSERT INTO `uptime_hour` (`site_id`,`bucket`,`checks`,`ok`,`total_ms`)
                 VALUES (?, DATE_FORMAT(?, "%Y-%m-%d %H:00:00"), 1, ?, ?)
                 ON DUPLICATE KEY UPDATE `checks` = `checks` + 1, `ok` = `ok` + VALUES(`ok`), `total_ms` = `total_ms` + VALUES(`total_ms`)',
                [$id, $now, $ok ? 1 : 0, $ms]
            );
        } catch (Throwable $e) {
            // اگر جدول‌ها رفته‌اند، یک‌بار خودترمیم کن
            try { Db::migrate(); } catch (Throwable $e2) { /* ignore */ }
        }

        $prevStatus = $site['status'] ?? 'unknown';
        $fails = (int)($site['consecutive_fail'] ?? 0);
        $threshold = max(1, Db::getInt('fail_threshold', 2));

        $set = [
            'last_check_at' => $now,
            'last_ms' => $ms,
            'last_code' => $code,
            'last_error' => $err,
            'total_checks' => (int)$site['total_checks'] + 1,
            'total_fails' => (int)$site['total_fails'] + ($ok ? 0 : 1),
        ];

        $notifyKind = null;
        $outageSec = 0;

        if ($ok) {
            if ($prevStatus === 'down') {
                $downAt = $site['last_down_at'] ? strtotime($site['last_down_at']) : time();
                $outageSec = max(0, time() - $downAt);
                $notifyKind = 'up';
            }
            $set += [
                'status' => 'up',
                'consecutive_fail' => 0,
                'down_alerted' => 0,
                'last_up_at' => $now,
                'last_down_duration' => $outageSec ?: (int)$site['last_down_duration'],
            ];
        } else {
            $fails++;
            $set['consecutive_fail'] = $fails;
            if ($prevStatus !== 'down' && $fails >= $threshold) {
                $set['status'] = 'down';
                $set['last_down_at'] = $now;
                $set['down_alerted'] = 1;
                $set['last_alert_at'] = $now;
                $notifyKind = 'down';
            } elseif ($prevStatus === 'down') {
                // تکرار هشدار قطعی حداکثر هر ۶ ساعت
                $lastAlert = $site['last_alert_at'] ? strtotime($site['last_alert_at']) : 0;
                if ($lastAlert === 0 || (time() - $lastAlert) >= self::REPEAT_ALERT) {
                    $set['last_alert_at'] = $now;
                    $set['down_alerted'] = 1;
                    $notifyKind = 'down_repeat';
                }
            }
        }

        $sql = 'UPDATE `site` SET ' . implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($set))) . ' WHERE `id` = ?';
        try {
            Db::q($sql, array_merge(array_values($set), [$id]));
        } catch (Throwable $e) { /* لاگ در botapi انجام می‌شود */ }

        if ($ok) $stats['up']++; else $stats['down']++;
        $stats['ms'] += $ms;

        if ($notifyKind !== null) {
            $stats['alerts'] += self::notify($site, $notifyKind, $err, $outageSec, $ms) ? 1 : 0;
        }
    }

    // ---------------------------------------------------------------- اطلاع‌رسانی

    private static function notify(array $site, string $kind, string $err, int $outageSec = 0, int $ms = 0): bool
    {
        if (!(int)($site['user_notify'] ?? 1)) return false;
        if (!Db::getBool('notify', true)) return false;
        $uid = (int)$site['user_id'];

        $label = tgH($site['label'] ?: $site['target']);
        $tz = tzOffset();
        $nowFa = faNum(date('H:i:s', time() + (int)round($tz * 3600)));

        if ($kind === 'down' || $kind === 'down_repeat') {
            $text = "🔴 <b>هشدار قطعی سرویس</b>\n\n"
                . "🔗 سایت: <code>{$label}</code>\n"
                . "🧾 نوع: " . typeName($site['type']) . "\n"
                . "⏰ زمان: {$nowFa}\n"
                . "❌ دلیل: " . tgH($err !== '' ? $err : 'بدون پاسخ') . "\n"
                . "🔁 چک‌های ناموفق پیاپی: " . faNum((int)$site['consecutive_fail'] + 1) . "\n"
                . ($kind === 'down_repeat' ? "\n⏱ هشدار تکراری — سرویس همچنان قطع است." : '');
            Db::logEvent($uid, 'alert_down', $site['target']);
        } else {
            $avg = Stats::uptime($site, 1)['pct'] ?? null;
            $text = "🟢 <b>سرویس دوباره برقرار شد</b>\n\n"
                . "🔗 سایت: <code>{$label}</code>\n"
                . "⌛️ مدت قطعی: " . faDuration(max(1, $outageSec)) . "\n"
                . "⚡️ زمان پاسخ: " . faMs($ms) . "\n"
                . ($avg !== null ? "📈 آپتایم ۲۴ ساعت اخیر: " . faPct($avg) . "\n" : '')
                . "⏰ زمان: {$nowFa}";
            Db::logEvent($uid, 'alert_up', $site['target']);
        }

        $r = tgSend($uid, $text);
        return !empty($r['ok']);
    }

    // ---------------------------------------------------------------- پاک‌سازی

    private static function maybePrune(): void
    {
        $last = (int)Db::get('last_prune', '0');
        if (time() - $last < 600) return;
        Db::set('last_prune', (string)time());
        try {
            Db::exec("DELETE FROM `check_log` WHERE `ts` < DATE_SUB(NOW(), INTERVAL 1 DAY)");
            Db::exec("DELETE FROM `uptime_hour` WHERE `bucket` < DATE_SUB(NOW(), INTERVAL 120 DAY)");
            Db::exec("DELETE FROM `seen_update` WHERE `ts` < DATE_SUB(NOW(), INTERVAL 3 DAY)");
            Db::exec("DELETE FROM `events` WHERE `ts` < DATE_SUB(NOW(), INTERVAL 180 DAY)");
        } catch (Throwable $e) { /* پاک‌سازی هرگز نباید راند را بشکند */ }
    }
}
