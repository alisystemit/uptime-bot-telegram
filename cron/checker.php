<?php
/**
 * ===== اسکریپت چک دوره‌ای (کرون) ربات مانیتورینگ =====
 *
 * سه روش اجرا:
 *
 *  ۱) کرون سیستمی (پیشنهادی) — هر دقیقه فراخوانی می‌شود و خودش ۳ نوبت با
 *     فاصلهٔ check_interval (پیش‌فرض ۲۰ ثانیه) چک می‌کند:
 *         * * * * * php /مسیر/ربات/cron/checker.php
 *
 *  ۲) کرون HTTP (هاست اشتراکی بدون دسترسی CLI):
 *         * * * * * curl -s "https://domain/bots/<slug>/cron/checker.php?secret=<SECRET>"
 *
 *  ۳) دیمون دائمی (بدون کرون، مثلاً روی سرور شخصی):
 *         php /مسیر/ربات/cron/checker.php --daemon
 *
 *  گزینه‌ها:
 *      --once       فقط یک راند چک و خروج
 *      --daemon     حلقهٔ بی‌نهایت با فاصلهٔ ثابت
 *      --rounds=N   تعداد نوبت‌ها (پیش‌فرض ۳)
 *      --json       خروجی JSON
 *      --quiet      بدون خروجی متنی
 *      --selftest   فقط سلامت سیستم (بدون چک سایت‌ها) — برای تست نصب
 *      --maintenance اجرای اجباری نگهداری (گواهی SSL، WHOIS دامنه، آمار پاسخ)
 */

// ---------- گارد وب (در CLI بی‌اثر است) ----------
require_once __DIR__ . '/_guard.php';

require_once dirname(__DIR__) . '/lib/bootstrap.php';

@set_time_limit(0);
@ini_set('memory_limit', '256M');
ignore_user_abort(true);
// منطقهٔ زمانی ذخیره‌سازی UTC است (bootstrap آن را تنظیم می‌کند)؛
// نمایش ساعت محلی با tz_offset کانفیگ انجام می‌شود.

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');

// ---------- پارامترها ----------
$argv = ($isCli && isset($_SERVER['argv'])) ? array_slice($_SERVER['argv'], 1) : [];
$optOnce    = false;
$optDaemon  = false;
$optJson    = false;
$optQuiet   = false;
$optSelf    = false;
$optMaint   = false;
$optRounds  = 0;
foreach ($argv as $a) {
    if ($a === '--once')          $optOnce = true;
    elseif ($a === '--daemon')    $optDaemon = true;
    elseif ($a === '--json')      $optJson = true;
    elseif ($a === '--quiet' || $a === '-q') $optQuiet = true;
    elseif ($a === '--selftest')  $optSelf = true;
    elseif ($a === '--maintenance' || $a === '--maint') $optMaint = true;
    elseif (preg_match('/^--rounds=(\d+)$/', $a, $m)) $optRounds = (int)$m[1];
    elseif ($a === '--help' || $a === '-h') {
        $txt = "Uptime checker\n"
            . "  --once        یک راند و خروج\n"
            . "  --daemon      حلقهٔ دائمی\n"
            . "  --rounds=N    تعداد نوبت (پیش‌فرض ۳)\n"
            . "  --json        خروجی JSON\n"
            . "  --maintenance نگهداری (SSL/WHOIS/آمار پاسخ)\n"
            . "  --selftest    بررسی سلامت بدون چک\n";
        echo $txt;
        exit(0);
    }
}

// ---------- قفل کل اجرا (جلوگیری از همپوشانی دو اجرای همزمان) ----------
$lockDir = UPTIME_ROOT . '/logs';
if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
$runFh = @fopen($lockDir . '/checker.lock', 'c');
if ($runFh === false) $runFh = null;
if ($runFh !== null && !@flock($runFh, LOCK_EX | LOCK_NB)) {
    @fclose($runFh);
    if ($optQuiet) exit(0);
    echo $optJson ? json_encode(['ok' => false, 'reason' => 'busy']) : 'BUSY' . "\n";
    exit(0);
}
if ($runFh !== null) {
    @ftruncate($runFh, 0);
    @fwrite($runFh, getmypid() . '|' . date('Y-m-d H:i:s'));
    @fflush($runFh);
    register_shutdown_function(static function () use ($runFh): void {
        @flock($runFh, LOCK_UN);
        @fclose($runFh);
    });
}

// ---------- خروجی ----------
$emit = static function (string $text) use ($optQuiet, $optJson): void {
    if ($optQuiet || $optJson) return;
    echo $text . "\n";
};

// ---------- دیتابیس ----------
try {
    appBoot();
} catch (Throwable $e) {
    uptimeLog('error', 'checker boot failed: ' . $e->getMessage());
    if ($optJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'database: ' . $e->getMessage()]);
    } else {
        if (!$isCli) http_response_code(503);
        echo 'DB ERROR: ' . $e->getMessage() . "\n";
    }
    exit(1);
}

if ($optSelf) {
    $e = Stats::engine();
    $out = [
        'ok' => true,
        'interval' => $e['interval'],
        'last_round' => $e['last_round'],
        'paused' => $e['paused'],
        'curl' => function_exists('curl_multi_init'),
        'openssl' => Ssl::supported(),
        'whois' => Domain::supported(),
    ];
    if ($optJson) echo json_encode($out, JSON_UNESCAPED_UNICODE);
    else $emit('OK interval=' . $e['interval'] . ' last=' . ($e['last_round'] ?? 'never') . ($e['paused'] ? ' PAUSED' : '')
        . ' curl=' . ($out['curl'] ? 'y' : 'n') . ' openssl=' . ($out['openssl'] ? 'y' : 'n') . ' whois=' . ($out['whois'] ? 'y' : 'n'));
    exit(0);
}

if ($optMaint) {
    $r = Monitor::maintenance(true);
    if ($optJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'ssl' => $r['ssl'], 'domains' => $r['domains'], 'resp' => $r['resp']], JSON_UNESCAPED_UNICODE);
    } else {
        $emit(sprintf('MAINT ssl=%d domains=%d resp=%d', $r['ssl'], $r['domains'], $r['resp']));
    }
    exit(0);
}

// ---------- زمان‌بندی ----------
// هر «راند» فقط سایت‌هایی را برمی‌دارد که از آخرین چک‌شان check_interval گذشته؛
// پس ۳ نوبتِ پشت‌سرهم = چک هر ~۲۰ ثانیه، بدون فشار مضاعف به هدف‌ها.
$roundsTarget = $optRounds > 0 ? min(60, $optRounds) : ($optDaemon ? PHP_INT_MAX : ($optOnce ? 1 : 3));

// کرون سیستمی باید قبل از شروعِ اجرای بعدی تمام شود
// (set_time_limit(0) بالای فایل اجرا شده؛ اینجا فقط بودجهٔ دیواری را می‌گذاریم)
$deadline = 0;
if (!$optDaemon) {
    $budget = $optOnce ? 30 : 58;
    $deadline = microtime(true) + $budget;
}

$started   = microtime(true);
$rounds    = 0;
$totTotal  = 0;
$totUp     = 0;
$totDown   = 0;
$totAlert  = 0;
$skipped   = null;
// برآورد مدت یک راند؛ بعد از هر راند از روی همان راند واقعی دقیق می‌شود
$roundWorst = 12.0;

$emit('uptime-checker start ' . date('Y-m-d H:i:s') . ' rounds=' . ($roundsTarget === PHP_INT_MAX ? 'inf' : $roundsTarget));

while ($rounds < $roundsTarget) {
    // قبل از شروعِ هر راند مطمئن شو فرصت تمام شدنش را داریم
    if ($deadline > 0 && (microtime(true) + $roundWorst) > $deadline) {
        $emit('  budget exhausted before round#' . ($rounds + 1));
        break;
    }

    $t0 = microtime(true);

    try {
        $stat = Monitor::round();
    } catch (Throwable $e) {
        uptimeLog('error', 'round failed: ' . $e->getMessage());
        $stat = ['skipped' => 'error: ' . $e->getMessage()];
    }

    $roundWorst = max(6.0, (microtime(true) - $t0) + 1.0);
    $rounds++;

    if (isset($stat['skipped'])) {
        $skipped = (string)$stat['skipped'];
        if ($skipped === 'paused' || strncmp($skipped, 'error', 5) === 0) {
            // موتور متوقف/خطادار است — منتظر بمان ولی بار اضافه نساز
            $emit('  round#' . $rounds . ' skipped=' . $skipped);
            if ($optOnce) break;
            if ($deadline > 0 && microtime(true) >= $deadline) break;
            sleep(10);
            continue;
        }
        // busy یعنی یک اجرای دیگر همین کار را می‌کند؛ خارج شو
        $emit('  round#' . $rounds . ' skipped=busy');
        break;
    }

    $totTotal += (int)($stat['total'] ?? 0);
    $totUp    += (int)($stat['up'] ?? 0);
    $totDown  += (int)($stat['down'] ?? 0);
    $totAlert += (int)($stat['alerts'] ?? 0);
    $emit(sprintf(
        '  round#%d  sites=%d up=%d down=%d alerts=%d  (%.1fs)',
        $rounds,
        (int)($stat['total'] ?? 0),
        (int)($stat['up'] ?? 0),
        (int)($stat['down'] ?? 0),
        (int)($stat['alerts'] ?? 0),
        microtime(true) - $t0
    ));

    // ---- آیا نوبت بعدی لازم است؟ ----
    if ($rounds >= $roundsTarget) break;
    if ($deadline > 0 && microtime(true) >= $deadline) {
        $emit('  budget exhausted');
        break;
    }

    // نوبت بعدی باید دقیقاً وقتی سایت‌ها «موعد» شده باشند بیاید؛ مبنای درست
    // همان لحظهٔ واقعیِ آخرین چک (MAX(last_check_at)) است، نه شروع یا پایان راند.
    // (همه‌جا UTC است؛ پس time() و رشته‌های دیتابیس قابل مقایسه‌اند)
    $interval = max(Monitor::MIN_GAP, Db::getInt('check_interval', 20));
    $lastCheck = Db::val('SELECT MAX(`last_check_at`) FROM `site` WHERE `paused` = 0');
    $lastTs = $lastCheck ? (int)strtotime((string)$lastCheck) : 0;
    $wait = $lastTs > 0 ? (int)ceil($interval - (time() - $lastTs)) : (int)$interval;
    if ($wait < 1) $wait = 1;
    if ($deadline > 0 && (microtime(true) + $wait + $roundWorst) > $deadline) {
        $emit('  budget exhausted');
        break;
    }
    sleep($wait);
}

$took = round(microtime(true) - $started, 1);
$lastRound = Db::get('last_round_at');

if ($optJson) {
    if (!$isCli) header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'        => true,
        'rounds'    => $rounds,
        'skipped'   => $skipped,
        'total'     => $totTotal,
        'up'        => $totUp,
        'down'      => $totDown,
        'alerts'    => $totAlert,
        'took_sec'  => $took,
        'last_round'=> $lastRound,
        'engine'    => Stats::engine(),
    ], JSON_UNESCAPED_UNICODE);
} elseif (!$optQuiet) {
    if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
    echo sprintf(
        "DONE rounds=%d checked=%d up=%d down=%d alerts=%d took=%ss last_round=%s\n",
        $rounds,
        $totTotal,
        $totUp,
        $totDown,
        $totAlert,
        $took,
        $lastRound ?? 'never'
    );
}

exit(0);
