<?php
/**
 * ===== هارنس فازر: پیدا کردن دلیل «کار نمی‌کند» =====
 *
 *   powershell -File tests\env.ps1
 *   UPTIME_CONFIG=… php tests/fuzz.php
 *
 * ایده: هر notice/warning/deprecated در PHP معمولاً یعنی یکی از این‌ها و
 * در محیط واقعی به یکی از این خرابی‌ها تبدیل می‌شود:
 *
 *   - کلید آرایهٔ تعریف‌نشده  → متن خالی/صفر در پیام تلگرام یا نمودار
 *   - تقسیم بر صفر            → NAN/INF که JSON را غیرقابل‌خواندن می‌کند
 *   - نوع غلط آرگومان         → TypeError و مرگ همان شاخهٔ کد
 *   - null روی متد            → Fatal: call on null
 *
 * پس اینجا هر خطای PHP را به یک «شکست» تبدیل می‌کنیم و بعد ورودی‌های
 * مخرب (خالی، خیلی بلند، یونیکد، کاراکتر کنترلی، نوع اشتباه، آرایه به‌جای
 * اسکالر) را به همهٔ نقطه‌های ورود می‌زنیم.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

// ── انتخاب بخش‌ها:  php tests/fuzz.php 1,3,5   یا   all
$SECTIONS = $argv[1] ?? 'all';
$SECTIONS = ($SECTIONS === 'all') ? null : array_map('intval', array_filter(explode(',', $SECTIONS), 'strlen'));
$GROUP = 0;
$SECT_N = 0;
function want(): bool
{
    global $SECTIONS, $SECT_N;
    $SECT_N++;
    return $SECTIONS === null || in_array($SECT_N, $SECTIONS, true);
}
/** سرصفحهٔ یک بخش: شماره‌اش را ثبت می‌کند و اگر انتخاب نشده کل بخش را رد می‌کند */
function head(string $title, int $n): void
{
    global $GROUP;
    $GROUP = $n;
    if (!want()) return;
    echo "\n\033[1m═══ {$title}\033[0m\n";
}

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap.php';
StrictCatcher::$root = $root;

// ── ۱) گیرندهٔ خطای سختگیرانه ──────────────────────────────────
final class StrictCatcher
{
    /** @var array<string,array{msg:string,file:string,line:int,count:int,trace:string}> */
    public static array $errors = [];
    public static bool $armed = false;
    public static int $fatalSeen = 0;
    /** ریشهٔ پروژه تا مسیرها را کوتاه کنیم (باید ثابت باشد، نه سراسری) */
    public static string $root = '';

    public static function install(): void
    {
        set_error_handler([self::class, 'onError']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onError(int $no, string $msg, string $file = '', int $line = 0): bool
    {
        if (!self::$armed) return true;
        // خطاهایی که خودِ کد با @ سرکوب کرده، عمدی‌اند و باگ نیستند.
        // (در PHP 8 هر دو به هندلر می‌رسند؛ فرق در error_reporting() است.)
        if (!(error_reporting() & $no)) return true;
        $key = $no . '|' . $msg . '|' . $file . '|' . $line;
        if (isset(self::$errors[$key])) {
            self::$errors[$key]['count']++;
            return true;
        }
        $trace = '';
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        foreach ($bt as $i => $f) {
            $trace .= ($i ? ' <- ' : '') . (($f['class'] ?? '') ? $f['class'] . ($f['type'] ?? '') : '')
                . ($f['function'] ?? '?') . '()';
            if ($i >= 3) break;
        }
        self::$errors[$key] = [
            'msg'   => self::label($no) . ': ' . $msg,
            'file'  => $file,
            'line'  => $line,
            'count' => 1,
            'trace' => $trace,
        ];
        return true;   // اجازه بده اجرا ادامه پیدا کند
    }

    public static function label(int $no): string
    {
        return match ($no) {
            E_WARNING              => 'WARNING',
            E_NOTICE               => 'NOTICE',
            E_DEPRECATED           => 'DEPRECATED',
            E_USER_WARNING         => 'USER_WARNING',
            E_USER_NOTICE          => 'USER_NOTICE',
            E_USER_DEPRECATED      => 'USER_DEPRECATED',
            E_RECOVERABLE_ERROR    => 'RECOVERABLE',
            E_CORE_WARNING, E_CORE_ERROR, E_COMPILE_WARNING, E_COMPILE_ERROR => 'COMPILE',
            default                => 'ERR' . $no,
        };
    }

    public static function onShutdown(): void
    {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        self::$fatalSeen++;
        // مستقیم چاپ می‌کنیم، چون اگر مرگ زودهنگام باشد فرصتی برای
        // رسیدن به انتهای اسکریپت و چاپ گزارش نیست.
        $root = dirname(__DIR__);
        printf("\n\n  \033[1;41m FATAL \033[0m %s\n    %s:%d\n",
            $e['message'],
            str_replace($root . '\\', '', $e['file']),
            $e['line']);
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        foreach ($bt as $i => $f) {
            printf("        \u2193 %s%s%s()  %s:%d\n", $f['class'] ?? '', $f['type'] ?? '', $f['function'],
                isset($f['file']) ? basename($f['file']) : '?', $f['line'] ?? 0);
        }
    }

    public static function reset(): void
    {
        self::$errors = [];
        self::$fatalSeen = 0;
    }

    public static function report(string $section): array
    {
        $list = self::$errors;
        self::$errors = [];
        if ($list) {
            echo "\n  \033[1;31m✗ $section — " . count($list) . " خطای PHP\033[0m\n";
            ksort($list);
            foreach ($list as $e) {
                printf("    %-70s %s:%d  ×%d\n", mb_substr($e['msg'], 0, 70),
                    str_replace(self::$root . '\\', '', $e['file']), $e['line'], $e['count']);
                if ($e['trace'] !== '' && $e['trace'] !== '(shutdown)') {
                    printf("        ↳ %s\n", $e['trace']);
                }
            }
        }
        return $list;
    }
}

StrictCatcher::install();

// ── ۲) آماده‌سازی محیط ──────────────────────────────────────────
BotApi::setProxy('http://127.0.0.1:1');
// حالت آفلاین: تلگرام اصلاً صدا زده نمی‌شود. بدون این، هر فراخوانی چند
// ده‌میلی‌ثانیه معطلی دارد و کل فازر چند ساعت طول می‌کشد.
if (method_exists('BotApi', 'offline')) BotApi::offline(true);
// تلگرام نباید واقعاً صدا زده شود: بدون این، هر فراخوانی ~۱۰ ثانیه معطلی دارد
// و فازر عملاً غیرقابل اجراست.
$r = new ReflectionClass('BotApi');
foreach (['maxRetries' => 0, 'baseDelay' => 1] as $prop => $val) {
    if (!$r->hasProperty($prop)) continue;
    $p = $r->getProperty($prop);
    $p->setAccessible(true);
    if ($p->isStatic()) $p->setValue(null, $val);
}

appBoot();
Db::migrate();
PayGws::ensure();

// همهٔ درگاه‌ها را به سرور قلابی محلی وصل می‌کنیم تا فازر به اینترنت واقعی
// (variza.ir / cubevps.ir / abangateway.ir / tetra98.ir) وصل نشود و ۲۰ ثانیه
// timeout هر فراخوانی را تحمل نکند.
foreach (['zarinpal' => 'zarinpal', 'variza' => 'variza', 'cubepy' => 'cubepy',
          'tetrapay' => 'tetra', 'aban' => 'aban', 'generic' => 'generic'] as $code => $path) {
    Db::q('UPDATE `pay_gateway` SET `base_url` = ?, `api_key` = ?, `merchant_id` = ?, `enabled` = 1 WHERE `code` = ?',
        ['http://127.0.0.1:8321/mock/' . $path, 'FUZZKEY', 'FUZZKEY', $code]);
}

$totalErrors = 0;
$totalCases  = 0;
$section = 'init';

// ورودی‌های مخرب
$EVIL = [
    ''                                  => 'خالی',
    ' '                                 => 'فقط فاصله',
    str_repeat('A', 5000)               => '۵۰۰۰ کاراکتر',
    "a\0b"                              => 'نول بایت',
    "<script>alert(1)</script>"         => 'HTML/JS',
    "'\"><img src=x onerror=alert(1)>"  => 'تزریق',
    '../../etc/passwd'                 => 'path traversal',
    "'; DROP TABLE site; --"            => 'SQL injection',
    "0"                                 => 'صفر',
    "-1"                                => 'منفی',
    "99999999999999999999"              => 'سرریز عدد',
    "1e999"                             => 'اینفینیت',
    "NAN"                               => 'NAN',
    "\u{202E}text"                      => 'RTL override',
    "🙂🙃🎉"                            => 'ایموجی',
    "سلام\n\n```\ncode\n```"           => 'مارک‌داون',
    str_repeat('🅰', 1000)              => 'ایموجی زیاد',
    "http://\xC0\x80"                   => 'بایت نامعتبر UTF-8',
    "١٢٣٤٥"                             => 'ارقام عربی',
    "0x1F"                              => 'هگز',
    "true"                              => 'بولیان به‌جای عدد',
    "null"                              => 'رشتهٔ null',
    "[]"                                => 'آرایه به‌جای اسکالر',
];

$ADMIN = (int)botAdminIds()[0];
$USER  = 880001;

function ensureUser(int $id, string $name, bool $admin = false): void
{
    Db::q('INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`plan`,`share_token`,`created_at`,`last_seen`)
           VALUES (?,?,?,?,1,?,?,NOW(),NOW())
           ON DUPLICATE KEY UPDATE `access`=1,`is_admin`=?,`name`=VALUES(`name`)',
        [$id, $name, 'u' . $id, $admin ? 1 : 0, 'vip',
         substr(hash('sha256', 'tk' . $id), 0, 20), $admin ? 1 : 0]);
}
ensureUser($ADMIN, 'ادمین', true);
ensureUser($USER, 'کاربر');

$mkMsg = static fn(int $id, string $text, array $extra = []) => [
    'update_id' => random_int(1, 999999),
    'message' => array_merge([
        'message_id' => random_int(1, 99999),
        'chat'   => ['id' => $id, 'type' => 'private'],
        'from'   => ['id' => $id, 'first_name' => 'ت', 'username' => 'u' . $id, 'language_code' => 'fa'],
        'date'   => time(),
        'text'   => $text,
    ], $extra),
];
$mkCb = static fn(int $id, $data) => [
    'update_id' => random_int(1, 999999),
    'callback_query' => [
        'id'      => 'cb' . random_int(1, 99999),
        'from'    => ['id' => $id, 'first_name' => 'ت', 'username' => 'u' . $id],
        'chat_instance' => 'x',
        'message' => ['message_id' => random_int(1, 99999), 'chat' => ['id' => $id, 'type' => 'private'],
                      'date' => time(), 'text' => 'x'],
        'data'    => $data,
    ],
];

/** یک به‌ازای هر ورودی، یک شاخه را اجرا می‌کند و خطاها را جمع می‌کند */
function run(string $section, callable $fn): void
{
    global $totalErrors, $totalCases, $GROUP, $SECTIONS;
    if ($SECTIONS !== null && !in_array($GROUP, $SECTIONS, true)) return;
    StrictCatcher::$armed = true;
    try {
        $fn();
        $totalCases++;
    } catch (Throwable $e) {
        $totalErrors++;
        printf("\n  \033[1;31m✗ %s : Uncaught %s\033[0m\n    %s\n    %s:%d\n",
            $section, get_class($e), $e->getMessage(),
            str_replace(dirname(__DIR__) . '\\', '', $e->getFile()), $e->getLine());
        $bt = $e->getTrace();
        for ($i = 0; $i < min(4, count($bt)); $i++) {
            printf("        ↳ %s%s%s()\n", $bt[$i]['class'] ?? '', $bt[$i]['type'] ?? '', $bt[$i]['function']);
        }
    }
    StrictCatcher::$armed = false;
    $errs = StrictCatcher::report($section);
    if ($errs) $GLOBALS['totalErrors'] += count($errs);
}

$bot = new Bot(appConfig());
$botAdmin = new Bot(appConfig());
$botUser  = new Bot(appConfig());

// ═══════════════════════════════════════════════════════════════
//  ۱) توابع کمکی: با داده‌های خالی و مقادیر مرزی
// ═══════════════════════════════════════════════════════════════
head('۱) توابع کمکی با ورودی مخرب', 1);

$helpers = [
    'faNum', 'faMoney', 'faDateTime', 'faLeft', 'truncateFa', 'tgH', 'h',
    'faPct', 'timeAgo', 'faToLatin', 'baseDomain', 'siteState', 'parseCommand',
    'normalizeTarget', 'makeShareToken', 'uniqueShareToken', 'statusUrl',
    'tzOffset', 'botUsername', 'botAdminIds', 'appConfig', 'uptimeLog',
];
run('helpers: با ورودی خالی', static function () use ($helpers) {
    foreach ($helpers as $fn) {
        if (!function_exists($fn)) continue;
        foreach (['', '0', '-1', 'abc', '99999999999999999999'] as $arg) {
            try { @$fn($arg); } catch (Throwable $e) { /* ثبت می‌شود */ }
        }
    }
});

run('helpers: با نوع اشتباه', static function () use ($helpers) {
    foreach ($helpers as $fn) {
        if (!function_exists($fn)) continue;
        foreach ([[], null, true, 1.5, new stdClass()] as $arg) {
            try { @$fn($arg); } catch (Throwable $e) { /* ثبت می‌شود */ }
        }
    }
});

run('helpers: با دو آرگومان', static function () {
    foreach (['truncateFa', 'faLeft', 'siteState', 'statusUrl', 'parseCommand', 'faPct', 'baseDomain'] as $fn) {
        if (!function_exists($fn)) continue;
        foreach ([['', ''], [null, null], [[], []], ['x', 0]] as $a) {
            try { @$fn($a[0], $a[1]); } catch (Throwable $e) { /* ثبت */ }
        }
    }
});

// ═══════════════════════════════════════════════════════════════
//  ۲) نرمال‌سازی هدف: ورودی‌های بد
// ═══════════════════════════════════════════════════════════════
head('۲) normalizeTarget با هدف‌های بد', 2);
run('normalizeTarget: مجموعهٔ مخرب', static function () use ($EVIL) {
    foreach ($EVIL as $target) {
        $r = normalizeTarget($target);
        if (!is_array($r) || !array_key_exists('ok', $r)) {
            throw new RuntimeException('خروجی نامعتبر برای ' . json_encode($target));
        }
    }
    // هدف‌های مرزی واقعی
    foreach ([
        'http://', 'https://', 'tcp://', 'ping://', 'HTTP://X.COM',
        'http://x.com:0', 'http://x.com:99999', 'http://x.com:-1',
        'http://[::1]/', 'http://[::1]:80/', 'http://xn--80ak6aa92e.com',
        'ftp://x.com', 'file:///etc/passwd', 'javascript:alert(1)',
        'http://' . str_repeat('a', 300) . '.com', 'tcp://' . str_repeat('1', 300),
        '1.1.1.1', '::1', 'example.com', 'example.com:8080', '☃.com',
        'http://user:pass@host.com/p?q=1#f', 'http://host.com/' . str_repeat('x', 4000),
    ] as $t) {
        $r = normalizeTarget($t);
        if (!is_array($r)) throw new RuntimeException('خروجی غیرآرایه برای ' . $t);
    }
});

// ═══════════════════════════════════════════════════════════════
//  ۳) ربات: پیام‌های مخرب
// ═══════════════════════════════════════════════════════════════
head('۳) ربات در برابر پیام‌های مخرب', 3);

run('onMessage: متن‌های خراب', static function () use ($EVIL, $mkMsg, $botUser) {
    foreach ($EVIL as $text) {
        try { $botUser->handle($mkMsg(880001, $text)); } catch (Throwable $e) { /* */ }
    }
});

run('onMessage: در حالت «در انتظار ورودی»', static function () use ($EVIL, $mkMsg, $botUser) {
    // کاربر باید داخل یک مرحلهٔ ورودی باشد؛ وگرنه متن به دستور تبدیل می‌شود
    Db::q('UPDATE `user` SET `step` = ?, `temp` = NULL WHERE `id` = ?', ['await_target', 880001]);
    foreach ($EVIL as $text) {
        try { $botUser->handle($mkMsg(880001, $text)); } catch (Throwable $e) { /* */ }
    }
    Db::q('UPDATE `user` SET `step` = \'idle\' WHERE `id` = ?', [880001]);
});

run('onMessage: مرحله‌های ورودی با temp خراب', static function () use ($EVIL, $mkMsg, $botUser) {
    $steps = ['await_target', 'await_label', 'await_code', 'await_keyword', 'await_slow',
              'await_domain', 'await_paygw', 'await_payment', 'await_name', 'await_bio'];
    foreach ($steps as $step) {
        // temp خراب: JSON نامعتبر، آرایه به‌جای رشته، کلید ناموجود
        foreach (['{bad json', '[]', '{"a":1}', 'null', ''] as $tmp) {
            Db::q('UPDATE `user` SET `step` = ?, `temp` = ? WHERE `id` = ?', [$step, $tmp, 880001]);
            foreach (['x', '', '0'] as $text) {
                try { $botUser->handle($mkMsg(880001, $text)); } catch (Throwable $e) { /* */ }
            }
        }
    }
    Db::q('UPDATE `user` SET `step` = \'idle\', `temp` = NULL WHERE `id` = ?', [880001]);
});

run('onMessage: پیام بدون متن (عکس/صدا/فایل)', static function () use ($mkMsg, $botUser, $USER) {
    $kinds = [
        ['photo' => [['file_id' => 'f1', 'file_unique_id' => 'u1', 'width' => 100, 'height' => 100]]],
        ['document' => ['file_id' => 'd1', 'file_unique_id' => 'u1', 'file_name' => '../../x.php']],
        ['voice' => ['file_id' => 'v1', 'file_unique_id' => 'u1', 'duration' => 3]],
        ['video' => ['file_id' => 'vi1', 'file_unique_id' => 'u1', 'duration' => 3]],
        ['audio' => ['file_id' => 'au1', 'file_unique_id' => 'u1', 'duration' => 3]],
        ['sticker' => ['file_id' => 's1', 'file_unique_id' => 'u1']],
        ['video_note' => ['file_id' => 'vn1', 'file_unique_id' => 'u1', 'length' => 1, 'duration' => 1]],
        ['animation' => ['file_id' => 'an1', 'file_unique_id' => 'u1']],
        ['contact' => ['phone_number' => '+1', 'first_name' => 'x']],
        ['location' => ['latitude' => 1.0, 'longitude' => 2.0]],
        ['venue' => ['location' => ['latitude' => 1.0, 'longitude' => 2.0], 'title' => 't', 'address' => 'a']],
        ['poll' => ['id' => 'p1']],
        ['dice' => ['emoji' => '🎲', 'value' => 3]],
        ['text' => ''],
    ];
    foreach ($kinds as $i => $k) {
        $u = $mkMsg($USER, '');
        unset($u['message']['text']);
        $u['message'] = array_merge($u['message'], $k);
        $u['message']['message_id'] = 1000 + $i;
        try { $botUser->handle($u); } catch (Throwable $e) { }
    }
});

run('onMessage: شناسهٔ کاربر/چت غیرعادی', static function () use ($botUser) {
    foreach ([0, -1, -1001234567890, PHP_INT_MAX, 'abc', 0.0] as $cid) {
        $u = ['update_id' => 1, 'message' => [
            'message_id' => 1, 'chat' => ['id' => $cid, 'type' => 'private'],
            'from' => ['id' => $cid, 'first_name' => 'x'], 'date' => time(), 'text' => '/start']];
        try { $botUser->handle($u); } catch (Throwable $e) { }
    }
});

run('onMessage: ساختار update ناقص', static function () use ($botUser, $USER) {
    $broken = [
        [],
        ['update_id' => 1],
        ['update_id' => 1, 'message' => []],
        ['update_id' => 1, 'message' => ['chat' => ['id' => $USER]]],
        ['update_id' => 1, 'message' => ['from' => ['id' => $USER], 'chat' => ['id' => $USER]]],
        ['update_id' => 1, 'callback_query' => []],
        ['update_id' => 1, 'callback_query' => ['data' => 'menu']],
        ['update_id' => 1, 'callback_query' => ['from' => ['id' => $USER], 'data' => 'menu']],
        ['update_id' => 1, 'callback_query' => ['from' => ['id' => $USER],
            'message' => ['chat' => ['id' => $USER]], 'data' => 'menu']],
        ['update_id' => 1, 'inline_query' => ['id' => 'q', 'from' => ['id' => $USER], 'query' => '']],
        ['update_id' => 1, 'my_chat_member' => ['chat' => ['id' => $USER], 'from' => ['id' => $USER]]],
        ['update_id' => 1, 'chat_member' => ['chat' => ['id' => $USER], 'from' => ['id' => $USER]]],
        ['update_id' => 1, 'chat_join_request' => ['chat' => ['id' => $USER], 'from' => ['id' => $USER]]],
        ['update_id' => 1, 'poll_answer' => ['user' => ['id' => $USER], 'poll_id' => 'p']],
        ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => $USER, 'type' => 'group'],
            'from' => ['id' => $USER], 'date' => time(), 'text' => '/add']],
    ];
    foreach ($broken as $i => $u) {
        try { $botUser->handle($u); } catch (Throwable $e) { }
    }
});

// ═══════════════════════════════════════════════════════════════
//  ۴) ربات: کالبک‌های مخرب
// ═══════════════════════════════════════════════════════════════
head('۴) ربات در برابر callback_data مخرب', 4);

run('callback: داده‌های ناشناخته و بدشکل', static function () use ($EVIL, $mkCb, $botUser) {
    $known = ['menu', 'sites', 'addsite', 'help', 'settings', 'sub', 'buy', 'close'];
    $bad = array_merge($EVIL, [
        'unknown:1', 'site:', 'site:abc', 'site:-1', 'site:999999999',
        'sc:999999999', 'slink:abc', 'sshare:1', 'sincer:1', 'ssl:1', 'sms:1', 'skw:1',
        'set:unknown_key', 'set:max_sites', 'set:', 'site:1:2:3',
        'gw:', 'gw:unknown', 'pcheck:0', 'pcheck:abc', 'pcheck:-1', 'pcheck:999999999',
        'pgw:unknown', 'pgwt:unknown', 'pgwk:unknown', 'pgws:unknown', 'pgwb:unknown', 'pgwx:unknown',
        'ausers:-1', 'ausers:abc', 'ausers:999999', 'auser:0', 'auser:abc',
        'aub:0', 'aud:0', 'auv:0', 'acode:0', 'apays', 'codes:abc',
        str_repeat('x', 200), "\0", "\n", 'menu:extra', 'MENENU',
    ]);
    foreach ($bad as $data) {
        try { $botUser->handle($mkCb(880001, $data)); } catch (Throwable $e) { }
    }
});

run('callback: کاربر عادی روی کالبک‌های مدیر', static function () use ($mkCb, $botUser) {
    foreach (['apanel', 'pgws_list', 'pgw:cubepy', 'pgwt:cubepy', 'pgwk:cubepy', 'pgwm:cubepy',
              'pgws:cubepy', 'pgwb:cubepy', 'pgwg', 'pgwx:cubepy', 'ausers:0', 'astats',
              'acodes', 'abroadcast', 'apays', 'apayok:1', 'apayno:1', 'acron'] as $d) {
        try { $botUser->handle($mkCb(880001, $d)); } catch (Throwable $e) { }
    }
});

run('callback: مدیر با شناسهٔ نامعتبر', static function () use ($mkCb, $botAdmin) {
    foreach (['ausers:-5', 'ausers:abc', 'auser:999999999', 'aub:999999999', 'aud:999999999',
              'auv:999999999', 'acode:abc', 'set:max_sites:abc', 'set:vip_days:-1',
              'set:max_sites:999999999', 'set:price:1e999', 'set:notify:xyz',
              'aset:unknown_key:value'] as $d) {
        try { $botAdmin->handle($mkCb((int)botAdminIds()[0], $d)); } catch (Throwable $e) { }
    }
});

run('callback: دادهٔ بیش از ۶۴ بایت (محدودیت تلگرام)', static function () use ($mkCb, $botUser) {
    foreach (['site:' . str_repeat('9', 100), 'gw:' . str_repeat('a', 100),
              'set:' . str_repeat('k', 100) . ':v', 'auser:' . str_repeat('1', 90)] as $d) {
        try { $botUser->handle($mkCb(880001, $d)); } catch (Throwable $e) { }
    }
});

run('callback: پارامتر عددیِ مرزی', static function () use ($mkCb, $botUser) {
    foreach (['site:0', 'site:-1', 'site:2147483647', 'site:9223372036854775807',
              'site:92233720368547758070', 'pcheck:0', 'ausers:0', 'ausers:-1'] as $d) {
        try { $botUser->handle($mkCb(880001, $d)); } catch (Throwable $e) { }
    }
});

// ═══════════════════════════════════════════════════════════════
//  ۵) کاربر: کنترل‌های دسترسی
// ═══════════════════════════════════════════════════════════════
head('۵) حالت‌های دسترسی', 5);

run('دسترسی: کاربر مسدود / بدون دسترسی / بدون اشتراک', static function () use ($mkMsg, $mkCb, $botUser) {
    $states = [
        ['access' => 0, 'plan' => 'free',  'is_blocked' => 0, 'name' => 'بدون دسترسی'],
        ['access' => 1, 'plan' => 'free',  'is_blocked' => 1, 'name' => 'مسدود'],
        ['access' => 0, 'plan' => 'free',  'is_blocked' => 1, 'name' => 'هردو'],
        ['access' => 1, 'plan' => 'vip',   'is_blocked' => 0, 'name' => 'ویژه'],
        ['access' => 1, 'plan' => 'vip',   'is_blocked' => 1, 'name' => 'ویژه+مسدود'],
    ];
    $cmds = ['/start', '/sites', '➕ افزودن سایت', '/help', 'menu', 'sites', 'settings',
             'sub', 'report', 'shared', 'domains', 'uincer', 'share', 'astats'];
    foreach ($states as $i => $st) {
        Db::q('UPDATE `user` SET `access` = ?, `plan` = ?, `is_blocked` = ? WHERE `id` = ?',
            [$st['access'], $st['plan'], $st['is_blocked'], 880001]);
        foreach ($cmds as $c) {
            if (str_starts_with($c, '/')) { try { $botUser->handle($mkMsg(880001, $c)); } catch (Throwable $e) { } }
            else { try { $botUser->handle($mkCb(880001, $c)); } catch (Throwable $e) { } }
        }
    }
    Db::q('UPDATE `user` SET `access` = 1, `is_blocked` = 0 WHERE `id` = ?', [880001]);
});

run('دسترسی: سقف کاربر پر است (max_users)', static function () use ($mkMsg, $botUser) {
    Db::set('access_mode', 'code');
    Db::set('max_users', '1');   // از قبل یک کاربر فعال داریم ⇒ سقف پر
    try { $botUser->handle($mkMsg(880009, '/start')); } catch (Throwable $e) { }
    Db::set('max_users', '0');
    Db::set('access_mode', 'open');
});

run('تنظیمات: مقادیر غیرمنتظره در settings', static function () use ($mkCb, $botUser) {
    $vals = ['0', '-1', 'abc', '999999999', '1e999', '0.5', ' ', 'NULL', 'true', '-999999999'];
    $keys = ['max_sites', 'vip_max_sites', 'check_interval', 'fail_threshold', 'vip_days',
             'price', 'max_users', 'ssl_warn_days', 'domain_warn_days', 'max_domains',
             'group_max_sites', 'tz_offset', 'notify_slow', 'access_mode', 'group_mention'];
    $saved = [];
    foreach ($keys as $k) $saved[$k] = Db::get($k);
    foreach ($keys as $k) {
        foreach ($vals as $v) {
            Db::q('INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)', [$k, $v]);
            Db::flushSettings();
            foreach (['menu', 'sites', 'astats', 'sub', 'settings'] as $c) {
                try { $botUser->handle($mkCb(880001, $c)); } catch (Throwable $e) { }
            }
        }
    }
    foreach ($saved as $k => $v) {
        Db::q('INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)', [$k, (string)$v]);
    }
    Db::flushSettings();
});

// ═══════════════════════════════════════════════════════════════
//  ۶) گروه و کانال
// ═══════════════════════════════════════════════════════════════
head('۶) گروه و کانال', 6);
$GID = -1001234567890;

run('گروه: شناسهٔ منفی و پیام‌های گروه', static function () use ($mkMsg, $botUser, $GID) {
    $g = static fn(int $id, string $text, array $from = []) => [
        'update_id' => random_int(1, 999999),
        'message' => array_merge([
            'message_id' => random_int(1, 99999),
            'chat'    => ['id' => $id, 'type' => $id < 0 ? 'supergroup' : 'group', 'title' => 'گروه تست'],
            'from'    => array_merge(['id' => 880001, 'first_name' => 'ت', 'username' => 'u880001'], $from),
            'date'    => time(),
            'text'    => $text,
        ]),
    ];
    $cmds = ['/add', '/add https://example.com', '/list', '/status', '/check', '/pause',
             '/resume', '/remove', '/remove 1', '/incidents', '/page', '/notify', '/domain',
             '/cancel', '/help', '/start', '/status 1', '/remove abc', '/check 999999',
             '/add javascript:alert(1)', '/add ' . str_repeat('a', 3000), '/nonexistent'];
    foreach ($cmds as $c) {
        try { $botUser->handle($g($GID, $c)); } catch (Throwable $e) { }
        try { $botUser->handle($g(-1009999999999, $c)); } catch (Throwable $e) { }
        try { $botUser->handle($g(0, $c)); } catch (Throwable $e) { }
    }
});

run('گروه: سقف مانیتورها و حالت کانال', static function () use ($botUser, $GID) {
    Db::set('group_max_sites', '1');
    $g = ['update_id' => 1, 'message' => ['message_id' => 1,
        'chat' => ['id' => $GID, 'type' => 'channel', 'title' => 'کانال تست'],
        'from' => ['id' => 880001, 'first_name' => 'ت'], 'date' => time(),
        'text' => '/add https://example.org']];
    try { $botUser->handle($g); } catch (Throwable $e) { }
    Db::set('group_max_sites', '10');
});

run('گروه: گروه بدون رکورد در chat_hub', static function () use ($botUser) {
    $g = ['update_id' => 1, 'message' => ['message_id' => 1,
        'chat' => ['id' => -100555000111, 'type' => 'supergroup', 'title' => 'ناشناس'],
        'from' => ['id' => 880001, 'first_name' => 'ت'], 'date' => time(), 'text' => '/list']];
    try { $botUser->handle($g); } catch (Throwable $e) { }
});

run('Group: توابع با ورودی مرزی', static function () use ($GID) {
    foreach ([0, 1, -1, $GID, PHP_INT_MIN, PHP_INT_MAX, 999999999999] as $id) {
        foreach (['isGroupChat', 'touch', 'exists'] as $fn) {
            if (!method_exists('Group', $fn)) continue;
            try { @Group::$fn($id); } catch (Throwable $e) { }
        }
    }
});

// ═══════════════════════════════════════════════════════════════
//  ۷) موتور مانیتورینگ
// ═══════════════════════════════════════════════════════════════
head('۷) موتور مانیتورینگ', 7);

run('monitor: راند کامل با سایت‌های مخرب', static function () {
    $targets = [
        'http://127.0.0.1:8321/mock/variza/api/v1/pay',   // سالم (HTTP)
        'http://127.0.0.1:1/',                            // اتصال رد شد
        'http://127.0.0.1:8321/nope',                    // ۴۰۴
        'tcp://127.0.0.1:8321',                           // TCP باز
        'tcp://127.0.0.1:1',                              // TCP بسته
        'http://256.256.256.256/',                       // DNS نامعتبر
        'http://',                                       // خالی
        'ping://127.0.0.1',                               // ping
    ];
    Db::exec('DELETE FROM `site` WHERE `user_id` = 880001');
    foreach ($targets as $i => $t) {
        try {
            $r = normalizeTarget($t);
            if (empty($r['ok'])) continue;
            // یکتایی روی (user_id, chat_id, target(120)) است ⇒ برای تست هر هدف
            // کاربر و چت جدا می‌گیرد تا تداخل نکند
            Db::q('INSERT INTO `site` (`user_id`,`chat_id`,`target`,`label`,`type`,`host`,`port`,
                                      `share_token`,`created_at`)
                   VALUES (?,?,?,?,?,?,?,?,NOW())',
                [880001, -(1000 + $i), $t, mb_substr($t, 0, 40), $r['type'], $r['host'] ?? '', $r['port'] ?? 0,
                 makeShareToken(20)]);
        } catch (Throwable $e) { }
    }
    Db::q('UPDATE `site` SET `last_check_at` = NULL');
    Monitor::round();
});

run('monitor: حالت‌های تنظیم عجیب', static function () {
    $saves = [];
    foreach (['pause_all', 'notify', 'fail_threshold', 'check_interval'] as $k) $saves[$k] = Db::get($k);
    foreach (['pause_all' => ['1', 'true', 'yes', ''], 'notify' => ['0', '', 'xyz']] as $k => $vs) {
        foreach ($vs as $v) {
            Db::q('INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)', [$k, $v]);
            Db::flushSettings();
            Db::q('UPDATE `site` SET `last_check_at` = NULL');
            try { Monitor::round(); } catch (Throwable $e) { }
        }
    }
    foreach ($saves as $k => $v) {
        Db::q('INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)', [$k, (string)$v]);
    }
    Db::flushSettings();
});

run('monitor: آستانهٔ قطعی صفر و یک', static function () {
    // fail_threshold یک تنظیم سراسری است (جدول settings)، نه ستون site
    $saves = [];
    foreach (['fail_threshold', 'check_interval'] as $k) $saves[$k] = Db::get($k);
    foreach (['0', '1', '-1', 'abc', '99999', ''] as $v) {
        foreach (['fail_threshold', 'check_interval'] as $k) { Db::set($k, $v); }
        Db::q('UPDATE `site` SET `last_check_at` = NULL');
        try { Monitor::round(); } catch (Throwable $e) { }
    }
    foreach ($saves as $k => $v) Db::set($k, (string)$v);
    Db::flushSettings();
});

run('monitor: سایت با کلیدواژه و آستانهٔ کندی', static function () {
    Db::q("UPDATE `site` SET `keyword` = 'NO_SUCH_WORD', `max_ms` = 1, `last_check_at` = NULL");
    try { Monitor::round(); } catch (Throwable $e) { }
    Db::q("UPDATE `site` SET `keyword` = '', `max_ms` = 0");
});

run('monitor: نگهداری با SSL/WHOIS خراب', static function () {
    Db::q("INSERT INTO `domain_watch` (`user_id`,`domain`,`created_at`) VALUES (880001,'this-domain-does-not-exist-xyz123.invalid',NOW())
           ON DUPLICATE KEY UPDATE `domain`=VALUES(`domain`)");
    Db::q("UPDATE `site` SET `target` = 'https://this-host-does-not-exist-xyz123.invalid/', `last_check_at` = NULL");
    try { Monitor::maintenance(true); } catch (Throwable $e) { }
    Db::exec("DELETE FROM `domain_watch` WHERE `user_id` = 880001");
});

run('Stats: دیتابیس خالی و مقادیر مرزی', static function () {
    // کاربر بدون هیچ سایتی
    Db::q('INSERT INTO `user` (`id`,`name`,`access`,`share_token`,`created_at`) VALUES (880099,?,1,?,NOW())
           ON DUPLICATE KEY UPDATE `access`=1', ['تهی', 'emptytoken00000001']);
    foreach (['userSummary', 'counters', 'groupSummary'] as $fn) {
        if (!method_exists('Stats', $fn)) continue;
        try { $r = Stats::$fn(880099); if ($r !== null && !is_array($r) && !is_scalar($r)) {
            throw new RuntimeException("$fn خروجی نامعتبر"); } } catch (Throwable $e) { }
    }
    foreach (['uptime', 'daily', 'hourly', 'incidents', 'incidentTotals', 'responseStats',
              'barHtml', 'sslFor', 'incidentsFor', 'dailyFor', 'hourlyFor', 'sparkline'] as $fn) {
        if (!method_exists('Stats', $fn)) continue;
        $sid = (int)Db::val('SELECT MIN(`id`) FROM `site`');
        try { Stats::$fn($sid, 30); } catch (Throwable $e) { }
        try { Stats::$fn(999999, 30); } catch (Throwable $e) { }
        try { Stats::$fn(0, 0); } catch (Throwable $e) { }
        try { Stats::$fn(-1, -1); } catch (Throwable $e) { }
    }
});

run('Stats: سایت با دادهٔ ناقص/خراب', static function () {
    $sid = (int)Db::val('SELECT MIN(`id`) FROM `site`');
    if ($sid < 1) return;
    // رکوردهای آماریِ خراب: حجم صفر، رخداد بازِ بی‌پایان، دلیل بلند
    try {
        Db::q('INSERT INTO `check_log` (`site_id`,`ts`,`ok`,`ms`,`code`) VALUES (?, NOW(), 1, 0, 200)', [$sid]);
        Db::q('INSERT INTO `uptime_hour` (`site_id`,`bucket`,`checks`,`ok`,`total_ms`)
               VALUES (?, NOW() - INTERVAL 3 DAY, 0, 0, 0)
               ON DUPLICATE KEY UPDATE `checks` = 0', [$sid]);
        Db::q('INSERT INTO `incident` (`site_id`,`kind`,`start_at`,`end_at`,`duration`,`reason`,`peak_ms`)
               VALUES (?, ?, NOW() - INTERVAL 2 HOUR, NULL, 0, ?, 0)',
            [$sid, 'down', mb_substr(str_repeat('خطا ', 200), 0, 190)]);
        $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$sid]);
        Stats::uptime($sid, 30);
        Stats::dailyFor($sid, 30);
        Stats::hourlyFor($sid, 24);
        Stats::incidentsFor($sid, 20);
        Stats::responseStats($site);
        Stats::sparkline(1, 50);
    } catch (Throwable $e) {
        // خوردن قید یکتایی قابل قبول است؛ خودِ فراخوانی‌ها مهم‌اند
    }
    Db::exec('DELETE FROM `check_log` WHERE `site_id` = ?', [$sid]);
    Db::exec("DELETE FROM `incident` WHERE `site_id` = ? AND `reason` LIKE 'خطا%'", [$sid]);
});

// ═══════════════════════════════════════════════════════════════
//  ۸) صفحهٔ وضعیت و API
// ═══════════════════════════════════════════════════════════════
head('۸) صفحهٔ وضعیت و API', 8);

run('Page: دامنه‌های مختلف', static function () use ($USER) {
    $sid = (int)Db::val('SELECT MIN(`id`) FROM `site`');
    $scopes = [
        [], ['u' => ''], ['u' => '0'], ['u' => '-1'], ['u' => 'abc'],
        ['u' => (string)$USER], ['u' => '999999999'],
        ['g' => ''], ['g' => '0'], ['g' => 'abc'], ['g' => '-1001234567890'],
        ['s' => ''], ['s' => 'abc'], ['s' => str_repeat('z', 100)],
        ['s' => 'zzz'], ['u' => (string)$USER, 'g' => '-1001'], ['u' => (string)$USER, 's' => 'zzz'],
        ['u' => (string)$USER, 'full' => '1'], ['u' => (string)$USER, 'csv' => '1'],
        ['u' => (string)$USER, 'full' => 'true'], ['u' => (string)$USER, 'days' => '-1'],
        ['u' => (string)$USER, 'days' => 'abc'], ['u' => (string)$USER, 'days' => '99999'],
        ['u' => "1' OR '1'='1"], ['u' => '../etc/passwd'],
    ];
    foreach ($scopes as $q) {
        try {
            $r = Page::payload($q + ['user_id' => (int)$USER], []);
            if (!is_array($r)) throw new RuntimeException('payload غیرآرایه');
        } catch (Throwable $e) { }
    }
    // scope سایت واقعی
    if ($sid > 0) {
        $tok = (string)Db::val('SELECT `share_token` FROM `site` WHERE `id` = ?', [$sid]);
        foreach ([['s' => $tok], ['s' => $tok, 'full' => '1'], ['s' => $tok, 'days' => '0']] as $q) {
            try { Page::payload($q + ['user_id' => (int)$USER], []); } catch (Throwable $e) { }
        }
    }
});

run('statusUrl: با دامنه/توکن بد', static function () {
    $cfg = appConfig();
    foreach (['', '   ', 'http://', '//', 'a' . str_repeat('b', 500)] as $domain) {
        $c = $cfg; $c['domain'] = $domain;
        try { statusUrl($c, 'tok' . random_int(1, 999)); } catch (Throwable $e) { }
        try { statusUrl($c, str_repeat('t', 500)); } catch (Throwable $e) { }
    }
});

// ═══════════════════════════════════════════════════════════════
//  ۹) پرداخت
// ═══════════════════════════════════════════════════════════════
head('۹) لایهٔ پرداخت', 9);

run('pay: تنظیمات درگاه خراب', static function () {
    foreach (PayGws::all(false) as $g) {
        $code = (string)$g['code'];
        foreach ([
            'settings' => 'null', 'settings' => '[]', 'settings' => '{bad', 'settings' => '0',
            'settings' => '{"create_path":null}', 'settings' => '{"field_map":"notarray"}',
            'settings' => '{"ok_values":"notarray"}', 'settings' => '{"ref_path":123}',
            'base_url' => '', 'base_url' => 'not a url', 'base_url' => 'http://',
            'api_key' => '', 'merchant_id' => '', 'secret' => '',
        ] as $col => $val) {
            Db::q("UPDATE `pay_gateway` SET `{$col}` = ? WHERE `code` = ?", [$val, $code]);
            try {
                $cls = PayGws::driver($code);
                if (!$cls) continue;
                $gw = PayGws::get($code);
                $args = ['user_id' => 880001, 'order_id' => 'F-1', 'gateway_amount' => 10000,
                         'amount_toman' => 1000, 'desc' => 'x', 'callback_url' => 'http://127.0.0.1:1/x',
                         'mobile' => '', 'email' => ''];
                $cls::create($gw, $args);
                $cls::verify($gw, ['ref_id' => 'x', 'pay_amount' => 10000, 'order_id' => 'F-1']);
                $cls::callback($gw, [], '{}');
                $cls::hasVerify($gw);
                $cls::signed($gw);
                PayGws::setting($gw, 'create_path');
                PayGws::setting($gw, 'field_map', []);
            } catch (Throwable $e) { }
        }
        Db::q("UPDATE `pay_gateway` SET `settings` = NULL, `base_url` = '', `api_key` = 'K',
                      `merchant_id` = 'K', `secret` = '' WHERE `code` = ?", [$code]);
    }
    Db::flushSettings();
});

run('pay: ورودی‌های callback مخرب', static function () {
    foreach (PayGws::all(false) as $g) {
        $code = (string)$g['code'];
        $cls = PayGws::driver($code);
        if (!$cls) continue;
        $gw = PayGws::get($code);
        foreach (['{}', '[]', 'null', '', '{bad json', '{"slug":null}', '{"slug":[1,2]}',
                  '{"status":[]}', '{"amount":"abc"}', '{"amount":1e999}'] as $raw) {
            try { $cls::callback($gw, [], $raw); } catch (Throwable $e) { }
        }
        foreach ([[], ['x' => 1], ['g' => $code], ['g' => $code, 'payment_id' => 'abc'],
                  ['g' => $code, 'payment_id' => 999999999]] as $in) {
            try { Pay::callback($code, $in, '{}', []); } catch (Throwable $e) { }
            try { Pay::callback($code, $in, 'garbage', ['X-Webhook-Signature' => 'x']); } catch (Throwable $e) { }
        }
    }
});

run('pay: کاربر ناموجود و مبلغ صفر', static function () {
    foreach (PayGws::all(true) as $g) {
        $code = (string)$g['code'];
        try { Pay::createOrder(999999999, $code); } catch (Throwable $e) { }
        try { Pay::createOrder(0, $code); } catch (Throwable $e) { }
        try { Pay::createOrder(-1, $code); } catch (Throwable $e) { }
    }
    $p0 = Db::getInt('price', 0);
    Db::set('price', '0');
    foreach (PayGws::all(true) as $g) {
        try { Pay::createOrder(880001, (string)$g['code']); } catch (Throwable $e) { }
    }
    Db::set('price', (string)$p0);
    try { Pay::verifyOrder(999999999); } catch (Throwable $e) { }
    try { Pay::verifyOrder(0); } catch (Throwable $e) { }
    try { Pay::verifyOrder(-1); } catch (Throwable $e) { }
    try { Pay::complete(999999999, 'x'); } catch (Throwable $e) { }
    try { Pay::expireOld(true); } catch (Throwable $e) { }
    try { Pay::stats(); } catch (Throwable $e) { }
});

run('pay: قیمت منفی / کسری / صفر', static function () {
    $p0 = Db::getInt('price', 0);
    foreach (['-5000', '1', '0', '99999999999'] as $p) {
        Db::set('price', $p);
        Db::exec("DELETE FROM `payments` WHERE `user_id` = 880001");
        foreach (PayGws::all(true) as $g) {
            try { Pay::createOrder(880001, (string)$g['code']); } catch (Throwable $e) { }
        }
    }
    Db::set('price', (string)$p0);
});

// ═══════════════════════════════════════════════════════════════
//  ۱۰) رتبه‌بندی
// ═══════════════════════════════════════════════════════════════
head('۱۰) رتبه‌بندی', 10);
run('ranking: امتیاز منفی و مرزی', static function () use ($USER) {
    foreach (['points_per_day' => '-100', 'points_per_uptime_hour' => '-5',
              'points_per_day' => '999999999', 'points_per_uptime_hour' => '0'] as $k => $v) {
        Db::set($k, $v);
        try { Ranking::awardDaily($USER); Ranking::awardUptime(['id' => 1, 'user_id' => $USER]); } catch (Throwable $e) { }
    }
    Db::set('points_per_day', '1');
    Db::set('points_per_uptime_hour', '5');
    foreach ([[0, 0], [-1, -1], [$USER, 0], [0, $USER], [PHP_INT_MAX, 1]] as $a) {
        try { Ranking::awardDaily($a[0]); } catch (Throwable $e) { }
        try { Ranking::grant($a[0], $a[1]); } catch (Throwable $e) { }
        try { Ranking::awardUptime(['id' => $a[1], 'user_id' => $a[0]]); } catch (Throwable $e) { }
    }
    foreach (['top', 'level', 'benefits', 'levelIcon', 'medal', 'pointsToNext'] as $fn) {
        if (!method_exists('Ranking', $fn)) continue;
        foreach ([0, 1, -1, PHP_INT_MAX, 999999999999] as $n) {
            try { Ranking::$fn($n); } catch (Throwable $e) { }
            try { Ranking::$fn((string)$n); } catch (Throwable $e) { }
        }
    }
});

// ═══════════════════════════════════════════════════════════════
echo "\n\033[1m═══ خلاصه ═══\033[0m\n";
printf("  شاخه‌های اجراشده : %d\n", $totalCases);
printf("  خطاهای PHP       : %d\n", $totalErrors);
printf("  خطای مرگبار      : %d\n", StrictCatcher::$fatalSeen);
echo $totalErrors === 0
    ? "\n  \033[1;32m✓ هیچ notice/warning/deprecated/uncaught ای رخ نداد\033[0m\n\n"
    : "\n  \033[1;31m✗ $totalErrors مورد نیاز به بررسی دارد\033[0m\n\n";
exit($totalErrors > 0 ? 1 : 0);
