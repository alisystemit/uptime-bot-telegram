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
                    AND (s.chat_id <> 0 OR (u.paused = 0 AND u.is_blocked = 0 AND u.access = 1))
                    AND (s.last_check_at IS NULL OR s.last_check_at <= DATE_SUB(NOW(), INTERVAL " . $interval . " SECOND))"
            );
            if (!$sites) {
                // راند واقعاً اجرا شد (با وجود نبودِ سایت)؛ آخرین راند را ثبت کن
                // تا پنل مدیریت کرون را «در حال اجرا» ببیند.
                Db::set('last_round_at', date('Y-m-d H:i:s'));
                self::maybePrune();
                self::maybeMaintenance();
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
                $res = $results[(int)$s['id']] ?? ['ok' => false, 'ms' => 0, 'code' => 0, 'error' => 'بررسی تمام نشد (Timeout)'];
                self::record($s, $res, $stats, $roundTs);
            }

            Db::set('last_round_at', date('Y-m-d H:i:s'));
            self::maybePrune();
            self::maybeMaintenance();
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
    
    /** پیام خطا را بهتر و واضح‌تر کن */
    private static function friendlyError(string $err, int $code = 0): string
    {
        // خطاهای curl را به فارسی تبدیل کن
        $curlErrors = [
            'Operation timed out' => 'سایت بسیار کند پاسخ می‌دهد (تجاوز ۹ ثانیه)',
            'Connection timed out' => 'ارتباط برقرار نشد — سرویس شاید خاموش است',
            'Connection refused' => 'سرویس درخواست‌ها را قبول نمی‌کند',
            'Temporary failure' => 'مشکل DNS — دامنه حل نشد',
            'Name or service not known' => 'دامنه یافت نشد',
            'Temporary failure in name resolution' => 'مشکل DNS موقتی',
        ];
        
        foreach ($curlErrors as $en => $fa) {
            if (stripos($err, $en) !== false) return $fa;
        }
        
        if ($code === 0 && stripos($err, 'write') === false) {
            return 'هیچ پاسخی دریافت نشد — سایت شاید خاموش یا بسیار کند است';
        }
        
        return $err;
    }

    // ---------------------------------------------------------------- HTTP

    /** حداکثر بایتی که برای بررسی «کلیدواژه» خوانده می‌شود */
    private const BODY_LIMIT = 262144;

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
            $needBody = trim((string)($s['keyword'] ?? '')) !== '';
            // شیء به‌جای متغیر: تا هر handle بدنهٔ مستقل خودش را داشته باشد
            $buf = new stdClass();
            $buf->body = '';
            $buf->trunc = false;
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
                // بدنه فقط وقتی لازم است که کاربر «کلیدواژه» تعیین کرده باشد
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($buf, $needBody): int {
                    $n = strlen($chunk);
                    if (!$needBody) return $n;               // فقط سرآخواست لازم است؛ بدنه را دور بریز ولی به curl بگو همه بایت‌ها alındı
                    if (strlen($buf->body) + $n > self::BODY_LIMIT) {
                        $buf->body .= substr($chunk, 0, max(0, self::BODY_LIMIT - strlen($buf->body)));
                        $buf->trunc = true;
                        return 0;                          // بقیه را نگیر
                    }
                    $buf->body .= $chunk;
                    return $n;
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS')) @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_multi_add_handle($mh, $ch);
            $map[spl_object_id($ch)] = ['ch' => $ch, 'site' => $s, 'start' => microtime(true), 'buf' => $buf];
        }

        $running = null;
        $deadline = microtime(true) + 12;
        try {
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
                $body = (string)$item['buf']->body;
                $truncated = (bool)$item['buf']->trunc;

                if ($code > 0) {
                    // سرور جواب داده است؛ فقط خطای سری ۵۰۰ یعنی سرویس از کار افتاده
                    $ok = $code < 500;
                    $res = [
                        'ok' => $ok,
                        'ms' => $ms,
                        'code' => $code,
                        'error' => $ok ? '' : ('پاسخ سرور: HTTP ' . $code),
                        'detail' => 'HTTP ' . $code,
                    ];
                    // ---- بررسی کلیدواژه (اختیاری) ----
                    $kw = trim((string)($s['keyword'] ?? ''));
                    if ($ok && $kw !== '') {
                        if (!$truncated && mb_stripos($body, $kw) === false) {
                            $res['ok'] = false;
                            $res['error'] = 'کلیدواژهٔ «' . mb_substr($kw, 0, 40) . '» در پاسخ پیدا نشد';
                        } elseif ($truncated) {
                            $res['detail'] .= ' • کلیدواژه ناقص';
                        }
                    }
                    $out[$id] = $res;
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
        } finally {
            // جلوگیری از تسریب حافظه حتی اگر استثنا رخ داده باشد
            curl_multi_close($mh);
        }
        return $out;
    }

    // ---------------------------------------------------------------- TCP

    /** اتصال غیرهمزمان به پورت‌ها با deadline مشترک */
    private static function probeTcpBatch(array $sites): array
    {
        $out = [];
        $pending = [];
        foreach ($sites as $s) {
            if (!is_array($s) || !isset($s['id'])) continue;
            $id = (int)$s['id'];
            if ($id <= 0) continue;
            $host = (string)($s['host'] ?? '');
            if ($host === '') { $out[$id] = self::fail('هاست نامعتبر'); continue; }
            $port = (int)($s['port'] ?? 0);
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
            if (!is_array($s) || !isset($s['id'])) continue;
            $host = (string)($s['host'] ?? '');
            if ($host === '') { $out[(int)$s['id']] = self::fail('هاست نامعتبر'); continue; }
            if (!preg_match('/^[a-zA-Z0-9\.\-:\[\]]+$/', $host)) {
                $out[(int)$s['id']] = self::fail('هاست نامعتبر');
                continue;
            }
            $cmd = $isWin
                ? 'ping -n 1 -w 2000 ' . escapeshellarg($host)
                : 'ping -c 1 -W 2 ' . escapeshellarg($host);
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
            $closed = false;
            if (is_array($st) && array_key_exists('exitcode', $st) && (int)$st['exitcode'] !== -1) {
                // کد خروج قبلاً توسط proc_get_status گرفته شده
                $exit = (int)$st['exitcode'];
            } else {
                // proc_get_status آخرین بار کد خروج را -1 داد → فقط proc_close آن را می‌دهد
                $exit = (int)@proc_close($pr['proc']);
                $closed = true;
            }
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
            if (!$closed) @proc_close($pr['proc']);
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
        if (!isset($site['id']) || (int)$site['id'] <= 0) return;
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

        // ---- آستانهٔ کندی (اختیاری) ----
        $maxMs = (int)($site['max_ms'] ?? 0);
        $isSlow = $ok && $maxMs > 0 && $ms > $maxMs;
        $wasSlow = (int)($site['slow'] ?? 0) === 1;

        $set = [
            'last_check_at' => $now,
            'last_ms' => $ms,
            'last_code' => $code,
            'last_error' => $err,
            'total_checks' => (int)($site['total_checks'] ?? 0) + 1,
            'total_fails' => (int)($site['total_fails'] ?? 0) + ($ok ? 0 : 1),
            'slow' => $isSlow ? 1 : 0,
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

        // ---- رخدادها: تاریخچهٔ کامل قطعی/کندی با علت و مدت ----
        $newStatus = (string)($set['status'] ?? $prevStatus);
        try {
            if ($newStatus === 'down' && $prevStatus !== 'down') {
                Stats::openIncident($id, 'down', $err);
            } elseif ($newStatus !== 'down' && $prevStatus === 'down') {
                Stats::closeIncident($id, 'down', $ms);
            } elseif ($newStatus === 'down') {
                Stats::bumpIncident($id, 'down', $ms);
            }
            if ($isSlow && !$wasSlow) {
                Stats::openIncident($id, 'slow', 'پاسخ ' . $ms . ' میلی‌ثانیه (حد ' . $maxMs . ')');
            } elseif (!$isSlow && $wasSlow) {
                Stats::closeIncident($id, 'slow', $ms);
            } elseif ($isSlow) {
                Stats::bumpIncident($id, 'slow', $ms);
            }
        } catch (Throwable $e) {
            // رخدادها نباید راند را بشکنند
        }

        $sql = 'UPDATE `site` SET ' . implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($set))) . ' WHERE `id` = ?';
        try {
            Db::q($sql, array_merge(array_values($set), [$id]));
        } catch (Throwable $e) { /* لاگ در botapi انجام می‌شود */ }

        // ---- سیستم امتیازدهی ----
        // منطق کامل به ماژول Ranking (lib/ranking.php) منتقل شد.
        // قبلاً اینجا پاداش آپتایم از بازهٔ ۲۴ ساعتهٔ اخیر محاسبه می‌شد؛ اگر
        // گارد ساعتی نمی‌بود هر راند دوباره پرداخت می‌شد و اگر بود، هر ساعت
        // کلِ ۲۴ ساعتِ اخیر را یک‌جا می‌پرداخت. حالا هر جایزه با کلیدِ
        // تاریخ/ساعتِ خودش دقیقاً یک‌بار و به‌صورت اتمیک اعطا می‌شود.
        if ($ok) Ranking::onCheck($site, true);

        if ($ok) $stats['up']++; else $stats['down']++;
        $stats['ms'] += $ms;

        if ($notifyKind !== null) {
            $stats['alerts'] += self::notify($site, $notifyKind, $err, $outageSec, $ms) ? 1 : 0;
        } elseif ($isSlow !== $wasSlow) {
            $stats['alerts'] += self::notify($site, $isSlow ? 'slow' : 'slow_ok', $err, 0, $ms) ? 1 : 0;
        }
    }

    // ---------------------------------------------------------------- اطلاع‌رسانی

    /**
     * گیرنده‌های اعلان یک سایت.
     *  - مانیتور گروهی → اعلان داخل خود گروه/کانال (نه پیام خصوصیِ سازنده)
     *  - مانیتور خصوصی → پیام خصوصی صاحب سایت
     *  - هر دو → همهٔ کسانی که آن مانیتور برایشان به‌اشتراک گذاشته شده
     *
     * @return int[] فهرست chat_id مقصدها
     */
    public static function notifyTargets(array $site): array
    {
        $ids = [];
        $chatId = (int)($site['chat_id'] ?? 0);
        if (Group::isGroupChat($chatId)) {
            if ((int)($site['notify_chat'] ?? 1) !== 1) return $ids;
            $hub = Group::get($chatId);
            if ($hub && !Group::notifyOn($hub)) return $ids;
            $ids[] = $chatId;
        } elseif ((int)($site['user_notify'] ?? 1) === 1) {
            $ids[] = (int)$site['user_id'];
        }
        try {
            foreach (Db::all('SELECT `user_id` FROM `site_share` WHERE `site_id` = ? AND `notify` = 1', [(int)$site['id']]) as $sh) {
                $ids[] = (int)$sh['user_id'];
            }
        } catch (Throwable $e) {
            // بی‌اهمیت
        }
        return array_values(array_unique(array_filter($ids, static fn($v) => (int)$v !== 0)));
    }

    private static function notify(array $site, string $kind, string $err, int $outageSec = 0, int $ms = 0): bool
    {
        if (!Db::getBool('notify', true)) return false;
        $targets = self::notifyTargets($site);
        if (!$targets) return false;
        $uid = (int)($site['user_id'] ?? 0);

        $label = tgH((string)(($site['label'] ?? '') !== '' ? $site['label'] : ($site['target'] ?? 'سایت')));
        $tz = tzOffset();
        $nowFa = faNum(date('H:i:s', time() + (int)round($tz * 3600)));
        $chatId = (int)($site['chat_id'] ?? 0);
        $isGroup = Group::isGroupChat($chatId);
        $hub = null;
        if ($isGroup) { try { $hub = Group::get($chatId); } catch (Throwable $e) { $hub = null; } }
        $isChannel = $hub && (string)$hub['chat_type'] === 'channel';

        $header = '';
        if ($isGroup && !$isChannel) {
            $title = $hub ? (string)$hub['title'] : 'گروه';
            $header = '📢 <b>' . tgH(truncateFa($title, 40)) . "</b>\n";
        }

        if ($kind === 'down' || $kind === 'down_repeat') {
            $typeName = typeName((string)($site['type'] ?? 'http'));
            $text = "🔴 <b>هشدار قطعی سرویس</b>\n\n"
                . "🔗 سایت: <code>{$label}</code>\n"
                . "🧾 نوع: " . $typeName . "\n"
                . "⏰ زمان: {$nowFa}\n"
                . "❌ دلیل: " . tgH($err !== '' ? $err : 'بدون پاسخ') . "\n"
                . "🔁 چک‌های ناموفق پیاپی: " . faNum((int)($site['consecutive_fail'] ?? 0) + 1) . "\n"
                . ($kind === 'down_repeat' ? "\n⏱ هشدار تکراری — سرویس همچنان قطع است." : '');
            if ($isGroup && !$isChannel) {
                $m = Group::mentionAdmins($chatId, (string)($hub['mention'] ?? ''));
                if ($m !== '') $text = $m . "\n" . $text;
            }
            Db::logEvent($uid, 'alert_down', $site['target']);
        } elseif ($kind === 'slow' || $kind === 'slow_ok') {
            if (!Db::getBool('notify_slow', true)) return false;
            if ($kind === 'slow') {
                $text = "🟠 <b>هشدار کندی پاسخ</b>\n\n"
                    . "🔗 سایت: <code>{$label}</code>\n"
                    . "⚡️ زمان پاسخ: " . faMs($ms) . "\n"
                    . "🎯 حد مجاز: " . faMs((int)$site['max_ms']) . "\n"
                    . "⏰ زمان: {$nowFa}";
                Db::logEvent($uid, 'alert_slow', $site['target']);
            } else {
                $text = "✅ <b>پاسخ به حالت عادی برگشت</b>\n\n"
                    . "🔗 سایت: <code>{$label}</code>\n"
                    . "⚡️ زمان پاسخ: " . faMs($ms) . "\n"
                    . "⏰ زمان: {$nowFa}";
            }
        } else {
            $avg = null;
            try { $avg = Stats::uptime($site, 1)['pct'] ?? null; } catch (Throwable $e) { $avg = null; }
            $text = "🟢 <b>سرویس دوباره برقرار شد</b>\n\n"
                . "🔗 سایت: <code>{$label}</code>\n"
                . "⌛️ مدت قطعی: " . faDuration(max(1, $outageSec)) . "\n"
                . "⚡️ زمان پاسخ: " . faMs($ms) . "\n"
                . ($avg !== null ? "📈 آپتایم ۲۴ ساعت اخیر: " . faPct($avg) . "\n" : '')
                . "⏰ زمان: {$nowFa}";
            Db::logEvent($uid, 'alert_up', $site['target']);
        }

        $text = $header . $text;
        $ok = false;
        foreach ($targets as $cid) {
            // شناسهٔ گروه/کانال منفی است؛ فقط صفر معتبر نیست
            if ((int)$cid === 0) continue;
            $r = tgSend($cid, $text);
            if (!empty($r['ok'])) $ok = true;
        }
        return $ok;
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
            Db::exec("DELETE FROM `incident` WHERE `end_at` IS NOT NULL AND `end_at` < DATE_SUB(NOW(), INTERVAL 180 DAY)");
            Db::exec("DELETE FROM `chat_admin` WHERE `checked_at` < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        } catch (Throwable $e) { /* پاک‌سازی هرگز نباید راند را بشکند */ }
    }

    // ---------------------------------------------------------------- نگهداری

    /** فاصلهٔ دو اجرای نگهداری (ثانیه) */
    public const MAINT_INTERVAL = 1800;

    private static function maybeMaintenance(): void
    {
        $gap = max(300, Db::getInt('maint_interval', (string)self::MAINT_INTERVAL));
        $last = (int)Db::get('last_maint', '0');
        if ($last > 0 && (time() - $last) < $gap) return;
        self::maintenance();
    }

    /**
     * کارهای سبک و کم‌تکرار (هیچ‌کدام نباید راند چک را کند کنند):
     *   ۱) تازه‌سازی گواهی SSL سایت‌های HTTPS
     *   ۲) استعلام WHOIS دامنه‌های پایش‌شده
     *   ۳) آمار زمان پاسخ ۲۴ ساعت (کمینه/میانگین/صدک۹۵/بیشینه)
     *   ۴) علامت‌زدن فاکتورهای پرداخت منقضی‌شده
     *
     * @param bool $force اجرای اجباری (حالت CLI)
     * @return array{ssl:int, domains:int, resp:int, payments?:int}
     */
    public static function maintenance(bool $force = false): array
    {
        $out = ['ssl' => 0, 'domains' => 0, 'resp' => 0];
        if ($force) Db::set('last_maint', (string)time());
        if (!$force) {
            $last = (int)Db::get('last_maint', '0');
            $gap = max(300, Db::getInt('maint_interval', (string)self::MAINT_INTERVAL));
            if ($last > 0 && (time() - $last) < $gap) return $out;
            Db::set('last_maint', (string)time());
        }

        // ---------- ۱) گواهی SSL ----------
        $sslAge = max(600, Db::getInt('ssl_interval', '3600'));
        try {
            // JOIN با user لازم است تا user_notify بیاید؛ بدون آن، notifyTargets
            // با مقدار پیش‌فرضِ ۱ همهٔ پیام‌ها را می‌فرستاد و خاموش‌کردنِ
            // اعلان خصوصی توسط کاربر برای هشدار SSL نادیده گرفته می‌شد.
            $https = Db::all(
                "SELECT s.*, u.notify AS user_notify
                   FROM `site` s
                   JOIN `user` u ON u.id = s.user_id
                  WHERE s.type = 'http' AND s.target LIKE 'https://%' AND s.paused = 0
                    AND (s.ssl_check_at IS NULL OR s.ssl_check_at <= DATE_SUB(NOW(), INTERVAL " . $sslAge . " SECOND))
                  LIMIT 40"
            );
        } catch (Throwable $e) {
            $https = [];
        }
        $tz = tzOffset();
        foreach ($https as $s) {
            $host = (string)$s['host'];
            if ($host === '') continue;
            $info = Ssl::check($host, (int)($s['port'] ?: 443));
            $warn = max(1, (int)($s['ssl_warn_days'] ?: Db::getInt('ssl_warn_days', 14)));
            try {
                Db::q(
                    'UPDATE `site` SET ssl_check_at = NOW(), ssl_days = ?, ssl_expires_at = ?, ssl_issuer = ?, ssl_error = ? WHERE id = ?',
                    [
                        (int)$info['days'],
                        $info['expires'],
                        mb_substr((string)$info['issuer'], 0, 160),
                        mb_substr((string)$info['error'], 0, 190),
                        (int)$s['id'],
                    ]
                );
            } catch (Throwable $e) {
                continue;
            }
            $out['ssl']++;
            if (empty($info['ok'])) continue;
            $days = (int)$info['days'];
            if ($days > $warn) continue;

            // فقط یک‌بار برای هر تاریخ انقضا هشدار بده
            $stamp = (string)($info['expires'] ?? '');
            if ((string)$s['ssl_notified'] === $stamp) continue;
            // کلید سراسریِ خاموشی اعلان‌ها باید اینجا هم رعایت شود
            if (!Db::getBool('notify', true)) continue;
            Db::q('UPDATE `site` SET ssl_notified = ? WHERE id = ?', [mb_substr($stamp, 0, 40), (int)$s['id']]);
            $text = ($days <= 0 ? "🔴 <b>گواهی SSL منقضی شده</b>" : "🟠 <b>هشدار انقضای گواهی SSL</b>") . "\n\n"
                . "🔗 سایت: <code>" . tgH($s['label'] ?: $s['target']) . "</code>\n"
                . "🗓 انقضا: " . faDay($info['expires'], $tz) . " — " . ($days < 0 ? 'منقضی شده' : faLeft($days * 86400) . ' دیگر') . "\n"
                . (!empty($info['issuer']) ? "🏛 صادرکننده: " . tgH($info['issuer']) . "\n" : '')
                . "🎯 آستانهٔ هشدار: " . faNum($warn) . " روز\n\n"
                . "برای تمدید اقدام کنید وگرنه مرورگرها خطای ناامنی نشان می‌دهند.";
            foreach (self::notifyTargets($s) as $cid) {
                tgSend($cid, $text);
            }
            Db::logEvent((int)$s['user_id'], 'alert_ssl', (string)$s['target']);
        }

        // ---------- ۲) انقضای دامنه (WHOIS) ----------
        $whoisAge = max(1800, Db::getInt('whois_interval', '21600'));
        try {
            $domains = Db::all(
                'SELECT * FROM `domain_watch` WHERE last_check IS NULL OR last_check <= DATE_SUB(NOW(), INTERVAL ' . $whoisAge . ' SECOND) LIMIT 15'
            );
        } catch (Throwable $e) {
            $domains = [];
        }
        foreach ($domains as $d) {
            $info = Domain::whois((string)$d['domain']);
            $out['domains']++;
            $status = 'unknown';
            if (!empty($info['ok'])) $status = $info['expires'] && strtotime((string)$info['expires']) < time() ? 'expired' : 'ok';
            try {
                Db::q(
                    'UPDATE `domain_watch` SET expires_at = ?, registrar = ?, status = ?, last_check = NOW(), last_error = ? WHERE id = ?',
                    [
                        $info['expires'],
                        mb_substr((string)$info['registrar'], 0, 160),
                        $status,
                        mb_substr((string)$info['error'], 0, 190),
                        (int)$d['id'],
                    ]
                );
            } catch (Throwable $e) {
                continue;
            }
            if (empty($info['ok'])) continue;
            $exp = strtotime((string)$info['expires']);
            if ($exp === false) continue;
            $leftDays = (int)floor(($exp - time()) / 86400);
            $warn = max(1, (int)$d['warn_days']);
            if ($leftDays > $warn) {
                // از هشدار قبلی پاک شد — فقط اگر چیزی برای پاک‌کردن باشد
                // (قبلاً هر ۳۰ دقیقه برای هر دامنهٔ سالم یک UPDATE بیهوده می‌زد)
                if (!empty($d['notified_at']) || !empty($d['notified_exp'])) {
                    Db::q('UPDATE `domain_watch` SET notified_at = NULL, notified_exp = NULL WHERE id = ?', [(int)$d['id']]);
                }
                continue;
            }
            if (!empty($d['notified_at']) && (string)$d['notified_exp'] === (string)$info['expires']) continue;
            if (!Db::getBool('notify', true)) continue;
            Db::q('UPDATE `domain_watch` SET notified_at = NOW(), notified_exp = ? WHERE id = ?', [(string)$info['expires'], (int)$d['id']]);
            $text = ($leftDays < 0 ? "🔴 <b>دامنه منقضی شده</b>" : "🟠 <b>هشدار انقضای دامنه</b>") . "\n\n"
                . "🌐 دامنه: <code>" . tgH((string)$d['domain']) . "</code>\n"
                . "🗓 انقضا: " . faDay($info['expires'], $tz) . " — " . ($leftDays < 0 ? 'منقضی شده' : faLeft($leftDays * 86400) . ' دیگر') . "\n"
                . (!empty($info['registrar']) ? "🏛 ثبت‌کننده: " . tgH($info['registrar']) . "\n" : '')
                . "⚠️ تمدید را فراموش نکنید؛ با انقضای دامنه سایت از دسترس خارج می‌شود.";
            $targets = Group::isGroupChat((int)$d['chat_id']) ? [(int)$d['chat_id']] : [(int)$d['user_id']];
            foreach (array_filter(array_unique($targets)) as $cid) tgSend($cid, $text);
            Db::logEvent((int)$d['user_id'], 'alert_domain', (string)$d['domain']);
        }

        // ---------- ۳) آمار زمان پاسخ (کش‌شده، برای نمایش سریع) ----------
        $respAge = max(1800, Db::getInt('resp_interval', '3600'));
        try {
            $rows = Db::all(
                'SELECT * FROM `site` WHERE `paused` = 0
                   AND (last_check_at IS NOT NULL AND last_check_at <= DATE_SUB(NOW(), INTERVAL ' . $respAge . ' SECOND))
                  LIMIT 40'
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $s) {
            $st = Stats::responseStats($s);
            if ($st['n'] < 1) continue;
            try {
                Db::q('UPDATE `site` SET resp_avg = ?, resp_max = ?, resp_p95 = ? WHERE id = ?', [$st['avg'], $st['max'], $st['p95'], (int)$s['id']]);
                $out['resp']++;
            } catch (Throwable $e) {
                // بی‌اهمیت
            }
        }

        // ---------- ۴) فاکتورهای پرداخت منقضی ----------
        try {
            $out['payments'] = Pay::expireOld(false);
        } catch (Throwable $e) {
            $out['payments'] = 0;
        }

        // ---------- ۵) پاک‌سازی کلیدهای نگه‌بانِ امتیاز ----------
        // بدون این، جدول settings برای هر سایت/روز یک سطرِ زائد تا ابد نگه می‌دارد.
        // کلیدها دو شکل‌اند؛ پس تاریخ را از «پس از آخرین _» می‌خوانیم:
        //   rank_day_<uid>_<YYYY-MM-DD>          rank_up_<site>_<YYYY-MM-DDTHH>
        //   last_points_<uid>_<YYYY-MM-DD>       points_bonus_<site>_<YYYYMMDDHH>
        // نکته: مقدارِ این کلیدها «تعداد امتیاز» است نه تاریخ؛ پس نمی‌شود با
        // مقایسهٔ مقدار سن‌شان را سنجید و باید از خودِ کلید خوانده شود.
        try {
            Db::exec("DELETE FROM `settings`
                       WHERE (`k` LIKE 'rank\\_day\\_%' OR `k` LIKE 'rank\\_up\\_%'
                              OR `k` LIKE 'last\\_points\\_%' OR `k` LIKE 'points\\_bonus\\_%')
                         AND (
                              (SUBSTRING_INDEX(`k`, '_', -1) LIKE '%-%'
                               AND LEFT(SUBSTRING_INDEX(`k`, '_', -1), 10)
                                   < DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 2 DAY), '%Y-%m-%d'))
                           OR (SUBSTRING_INDEX(`k`, '_', -1) NOT LIKE '%-%'
                               AND SUBSTRING_INDEX(`k`, '_', -1)
                                   < DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 2 DAY), '%Y%m%d%H'))
                         )");
        } catch (Throwable $e) {
            // بی‌اهمیت
        }

        return $out;
    }
}
