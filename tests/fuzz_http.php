<?php

/**

 * ===== فازر نقطه‌های ورود HTTP =====

 *

 *   powershell -File tests\env.ps1

 *   UPTIME_CONFIG=… php tests/fuzz_http.php

 *

 * هدف: پیدا کردن ۵۰۰ / صفحهٔ خالی / JSON نامعتبر.

 * کاربر وقتی می‌گوید «کد کار نمی‌کند»، معمولاً یکی از این نقطه‌های ورود

 * ۵۰۰ می‌دهد. اینجا همهٔ پارامترهای ورودی را با ورودی‌های مخرب می‌زنیم.

 */

error_reporting(E_ALL);

// خطای مرگبار باید دیده شود وگرنه فازر بی‌صدا وسط کار می‌میرد

ini_set('display_errors', 'stderr');

ini_set('log_errors', '0');

register_shutdown_function(static function (): void {

    $e = error_get_last();

    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {

        fwrite(STDERR, "\nFATAL[{$e['type']}]: {$e['message']}\n  at {$e['file']}:{$e['line']}\n");

    }

});



$web = getenv('UPTIME_WEB') ?: 'http://127.0.0.1:8199';

$fail = 0;

$total = 0;

$problems = [];



function httpCall(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 20): array

{

    $ch = curl_init($url);

    $h = [];

    foreach ($headers as $k => $v) $h[] = is_int($k) ? (string)$v : $k . ': ' . $v;

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,

        CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,

        CURLOPT_HTTPHEADER => $h,

    ]);

    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $out = curl_exec($ch);

    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $err = (string)curl_error($ch);

    curl_close($ch);

    return ['code' => $code, 'body' => (string)$out, 'err' => $err];

}



function bad(string $label, $code, string $body, array $expect = [200, 302, 400, 401, 403, 404]): void

{

    global $problems, $total;

    $total++;

    $code = (int)$code;

    $why = '';

    $isHead = str_contains($label, 'HEAD');

    if ($code >= 500) $why = 'خطای سرور ۵xx';

    elseif (!in_array($code, $expect, true)) $why = "کد غیرمنتظره (انتظار: " . implode('/', $expect) . ')';

    // HEAD طبق RFC بدنه ندارد و ۳xx هم بدنه ندارد ⇒ خالی بودن ایراد نیست

    elseif (trim($body) === '' && !$isHead && ($code < 300 || $code >= 400)) $why = 'پاسخ خالی';

    elseif (stripos($body, 'Fatal error') !== false) $why = 'Fatal error در خروجی';

    elseif (stripos($body, 'Parse error') !== false) $why = 'Parse error در خروجی';

    elseif (stripos($body, 'Uncaught') !== false) $why = 'Uncaught در خروجی';

    elseif (stripos($body, 'Warning:') !== false || stripos($body, 'Notice:') !== false

            || stripos($body, 'Deprecated:') !== false) $why = 'هشدار PHP در خروجی';

    elseif (stripos($body, 'SQLSTATE') !== false) $why = 'خطای دیتابیس به کاربر نشت کرده';

    elseif (stripos($body, 'PDOException') !== false) $why = 'جزئیات دیتابیس نشت کرده';

    if ($why !== '') {

        $problems[] = sprintf("%-52s %-6s %s", $label, $code, $why);

        printf("  \033[1;31m✗\033[0m %-52s %-6s %s\n", $label, $code, $why);

        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($body)));

        if ($snippet !== '') printf("      %s\n", mb_substr($snippet, 0, 130));

    }

}



echo "\n\033[1m═══ نقطه‌های ورود HTTP ═══\033[0m\n";



// ── دادهٔ آزمون (اگر دیتابیس تازه ساخته شده، اینجا خودمان می‌سازیم) ──

$shareToken = 'fuzztoken0000000001';

$cfgFile2 = getenv('UPTIME_CONFIG');

if ($cfgFile2 && is_file($cfgFile2)) {

    try {

        require_once dirname(__DIR__) . '/lib/bootstrap.php';

        appBoot();

        Db::q('INSERT INTO `user` (`id`,`name`,`username`,`access`,`share_token`,`created_at`)

               VALUES (900001,?,?,1,?,NOW())

               ON DUPLICATE KEY UPDATE `share_token` = VALUES(`share_token`), `access` = 1',

            ['Fuzz', 'fuzz1', $shareToken]);

        Db::q('INSERT INTO `site` (`user_id`,`chat_id`,`target`,`label`,`type`,`host`,`port`,`share_token`,`created_at`)

               VALUES (900001,0,?,?,?,?,?,?,NOW())

               ON DUPLICATE KEY UPDATE `target` = VALUES(`target`)',

            ['http://127.0.0.1:8321/mock/variza/api/v1/pay', 'fuzz', 'http', '127.0.0.1', 8321, 'sitefuzztoken00001']);

    } catch (Throwable $e) {

        fwrite(STDERR, 'seed failed: ' . $e->getMessage() . "\n");

    }

}



// دادهٔ مخرب برای پارامترهای GET

$EVIL = [

    ''          => 'خالی',

    '0'         => 'صفر',

    '-1'        => 'منفی',

    '999999999999' => 'بزرگ',

    'abc'       => 'متن',

    '../../etc/passwd' => 'مسیر',

    'null'      => 'نال',

    '%00'       => 'نول',

    '1 OR 1=1'  => 'SQLi',

    "' OR '1'='1" => 'SQLi2',

    '<script>alert(1)</script>' => 'XSS',

    '[]'        => 'آرایه',

    '{}'        => 'شیء',

    str_repeat('A', 3000) => 'خیلی بلند',

    '🙂'        => 'ایموجی',

    '1e999'     => 'بی‌نهایت',

    'NaN'       => 'NaN',

    'true'      => 'بولی',

    '2026-01-01' => 'تاریخ',

    '٣٤'        => 'عربی',

];



// توکن‌های آزمون (از config.test.php)

$cfgFile = getenv('UPTIME_CONFIG');

$cfg = $cfgFile && is_file($cfgFile) ? (include $cfgFile) : (include dirname(__DIR__) . '/config.php');

$adminId = (string)($cfg['admin_id'][0] ?? '');

$token = (string)($cfg['bot_token'] ?? '');

$webhookSecret = hash('sha256', $token . '_uptime_webhook_secret');

$tableSecret = hash('sha256', $token . '_uptime_table_secret');

$cronSecret = hash('sha256', $token . '_uptime_cron_secret');



$uid = 900001;



// ── ۱) status.php ─────────────────────────────────────────────

echo "\n\033[1m[۱] status.php\033[0m\n";

$statusScopes = ['u', 'g', 's', 'days', 'full', 'csv', 'page', 'limit'];

foreach ($statusScopes as $p) {

    foreach ($EVIL as $v => $label) {

        bad("status.php?$p=$v", ...hCall('GET', "$web/status.php?$p=" . rawurlencode($v)));

    }

}

foreach ([

    '?u=' . $shareToken, '?u=' . $shareToken . '&full=1', '?u=' . $shareToken . '&csv=1',

    '?u=' . $shareToken . '&days=0', '?u=' . $shareToken . '&days=-5', '?u=' . $shareToken . '&days=abc',

    '?u=' . $shareToken . '&u=' . $shareToken, '?u=' . $shareToken . '&s=zzz',

    '?g=-1001234567890', '?s=zzzzzzzz', '?', '?a=1&b=2&c=3',

] as $q) {

    bad("status.php$q", ...hCall('GET', "$web/status.php$q"));

}

// HEAD و روش‌های نامعتبر

// HEAD روی وب‌سرور داخلی ۴۰۴ می‌دهد؛ مهم این است که ۵۰۰ ندهد

bad('status.php HEAD', ...hCall('HEAD', "$web/status.php?u=$shareToken"), expect: [200, 404, 405]);

bad('status.php POST', ...hCall('POST', "$web/status.php?u=$shareToken", 'x=1'), expect: [200, 405, 404]);

bad('status.php DELETE', ...hCall('DELETE', "$web/status.php?u=$shareToken"), expect: [200, 405, 404]);

// ── ۲) api.php ────────────────────────────────────────────────

echo "\n\033[1m[۲] api.php\033[0m\n";

foreach (['u', 'g', 's', 'full', 'days'] as $p) {

    foreach ($EVIL as $v => $label) {

        bad("api.php?$p=$v", ...hCall('GET', "$web/api.php?$p=" . rawurlencode($v)));

    }

}

foreach (['?u=' . $shareToken, '?u=' . $shareToken . '&full=1', '?u=' . $shareToken . '&full=true',

          '?u=' . $shareToken . '&full=yes', '?u=' . $shareToken . '&full[]=1', '?g=0', '?s='] as $q) {

    bad("api.php$q", ...hCall('GET', "$web/api.php$q"));

}



// ── ۳) index.php (وبهوک تلگرام) ────────────────────────────────

echo "\n\033[1m[۳] index.php — وبهوک\033[0m\n";

$updates = [

    '{}' => 'خالی', '[]' => 'آرایه', 'null' => 'نال', 'not json' => 'متن',

    '{"update_id":1}' => 'فقط id',

    '{"update_id":1,"message":{"message_id":1,"date":1,"chat":{"id":1,"type":"private"},"from":{"id":1},"text":"/start"}}' => 'پیام ساده',

    '{"update_id":1,"callback_query":{"id":"x","from":{"id":1},"data":"menu"}}' => 'کالبک ناقص',

    '{"update_id":"1","message":{}}' => 'نوع اشتباه',

    '{"update_id":1,"message":{"chat":{"id":"abc"},"from":{"id":1},"text":"/start"}}' => 'chat غیرعددی',

    '{"update_id":1,"pre_checkout_query":{"id":"1","from":{"id":1},"currency":"IRR","total_amount":1}}' => 'pre_checkout',

    str_repeat('{"update_id":1,"message":{"chat":{"id":1},"from":{"id":1},"text":"x"}}', 2000) => 'خیلی بزرگ',

];

foreach ($updates as $body => $label) {

    bad("index.php بدون secret: $label",

        ...hCall('POST', "$web/index.php", $body, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong']));

    bad("index.php با secret درست: $label",

        ...hCall('POST', "$web/index.php", $body, ['X-Telegram-Bot-Api-Secret-Token' => $webhookSecret]), expect: [200]);}

// روش‌های نادرست

bad('index.php GET', ...hCall('GET', "$web/index.php", null, ['X-Telegram-Bot-Api-Secret-Token' => $webhookSecret]), expect: [200, 405, 404]);

// ── ۴) table.php ──────────────────────────────────────────────

echo "\n\033[1m[۴] table.php\033[0m\n";

foreach (['x', '', $tableSecret, $tableSecret . 'x', str_repeat('0', 64)] as $sec) {

    bad('table.php secret=' . mb_substr($sec, 0, 8), ...hCall('GET', "$web/table.php?secret=" . rawurlencode($sec), null, [], 60), expect: [200, 401, 403, 404]);}



// ── ۵) cron/checker.php ───────────────────────────────────────

echo "\n\033[1m[۵] cron/checker.php\033[0m\n";

foreach (['x', '', $cronSecret] as $sec) {

    foreach (['', '?selftest', '?once', '?maintenance', '?d=abc', '?d=-1', '?d=99999', '?n=abc', '?max=0'] as $q) {

        bad('checker.php' . $q . ' secret=' . mb_substr($sec, 0, 6),

            ...hCall('GET', "$web/cron/checker.php?secret=" . rawurlencode($sec) . $q, null, [], 90), expect: [200, 401, 403, 404]);    }

}

bad('checker.php POST', ...hCall('POST', "$web/cron/checker.php?secret=$cronSecret", 'x=1', [], 90), expect: [200, 403, 405, 404]);

// ── ۶) pay.php ────────────────────────────────────────────────

echo "\n\033[1m[۶] pay.php\033[0m\n";

foreach (['zarinpal', 'variza', 'cubepy', 'tetrapay', 'aban', 'generic', 'nosuch', '', '../../etc'] as $g) {

    bad("pay.php?g=$g", ...hCall('GET', "$web/pay.php?g=" . rawurlencode($g)), expect: [200, 302, 400, 404]);    bad("pay.php POST?g=$g", ...hCall('POST', "$web/pay.php?g=" . rawurlencode($g), '{}', ['Content-Type: application/json']), expect: [200, 400, 404]);}

$bodies = [

    '{}' => 'خالی', '[]' => 'آرایه', 'null' => 'نال', '{bad' => 'JSON خراب',

    '{"slug":{"a":1},"status":["x"]}' => 'آرایه در فیلدها',

    '{"event":"payment.paid","slug":1e999,"amount":-1}' => 'اعداد عجیب',

    str_repeat('{"slug":"x"}', 5000) => 'خیلی بزرگ',

];

foreach ($bodies as $b => $label) {

    bad("pay.php POST variza: $label",

        ...hCall('POST', "$web/pay.php?g=variza", $b, ['Content-Type: application/json']), expect: [200, 400, 404]);}

// امضای جعلی با بدنه‌های مختلف

foreach ($bodies as $b => $label) {

    bad("pay.php امضای جعلی: $label",

        ...hCall('POST', "$web/pay.php?g=variza", $b, [

            'Content-Type: application/json', 'X-Webhook-Signature: sha256=deadbeef', 'X-Event: payment.paid',

        ]), expect: [200, 400, 404]);

}



// ── ۷) هدرهای غیرعادی و بزرگ ──────────────────────────────────

echo "\n\033[1m[۷] هدرهای غیرعادی\033[0m\n";

bad('هدر User-Agent خیلی بلند', ...hCall('GET', "$web/status.php?u=$shareToken", null, [

    'User-Agent' => str_repeat('x', 4000)]), expect: [200, 400]);

bad('هدر Accept-Encoding نامعتبر', ...hCall('GET', "$web/status.php?u=$shareToken", null, [

    'Accept-Encoding' => 'gzip, made-up']), expect: [200, 400]);

bad('Content-Type نامعتبر در POST', ...hCall('POST', "$web/index.php", 'x=1', [

    'X-Telegram-Bot-Api-Secret-Token' => $webhookSecret, 'Content-Type' => 'text/plain']), expect: [200, 400]);



// ── ۸) مسیرهای حساس ───────────────────────────────────────────
// نکتهٔ کلیدی: وب‌سرور داخلی PHP (پورت ۸۱۹۹) اصلاً .htaccess را نمی‌خواند،
// پس مسدودسازی را نمی‌توان با آن سنجید. اگر Apache روی پورت ۸۰ بالا باشد،
// همان بررسی را روی آن هم انجام می‌دهیم تا واقعاً تأیید شود.
echo "\n\033[1m[۸] مسیرهای حساس (نباید محتوایشان نشت کند)\033[0m\n";
$SENSITIVE = [
    '/.git/config'        => 'آدرس ریموت گیت',
    '/.git/HEAD'          => 'شاخهٔ جاری',
    '/.gitignore'         => 'الگوی نادیده‌گرفتنی‌ها',
    '/.htaccess'          => 'قوانین وب‌سرور',
    '/config.php'         => 'توکن و رمز دیتابیس',
    '/lib/db.php'         => 'اسکیما و کلیدها',
    '/lib/util.php'       => 'توابع داخلی',
    '/lib/bot.php'        => 'منطق ربات',
    '/lib/pay.php'        => 'منطق پرداخت',
    '/vendor/autoload.php' => 'استاب',
    '/tools/analyze.php'  => 'ابزار داخلی',
    '/tests/mock_gw.php'  => 'سرور قلابی تست',
];

/**
 * بررسی نشت روی یک مبدأ.
 * $strict=true یعنی ۲۰۰ هم خطاست (چون انتظار داریم .htaccess مسدود کند).
 * @return array{0:array<int,string>,1:int} [فهرست مشکلات، تعداد بررسی]
 */
function scanPaths(string $base, array $paths, bool $strict, string $tag): array
{
    $found = [];
    $count = 0;
    foreach ($paths as $path => $what) {
        [$code, $body] = hCall('GET', $base . $path);
        $leak = '';
        foreach (['<?php', 'bot_token', "'pass'", 'repositoryformatversion', 'CREATE TABLE',
                  'api.telegram.org/bot', 'PDO::', 'remote "origin"'] as $needle) {
            if (str_contains($body, $needle)) { $leak = $needle; break; }
        }
        if ($code >= 500) $leak = 'khata-500';
        $ok = in_array($code, [403, 404, 405], true) || ($leak === '' && !$strict && $code === 200);
        printf("  %s %-9s %-24s %-4s %-16s %s\n",
            $ok ? "\033[1;32m+\033[0m" : ($strict ? "\033[1;31mx\033[0m" : "\033[1;33m~\033[0m"),
            $tag, $path, $code, $what, $leak);
        if (!$ok) $found[] = "$tag $path ($code) $leak";
        $count++;
    }
    return [$found, $count];
}

[$f1, $n1] = scanPaths($web, $SENSITIVE, false, 'built-in');
// وب‌سرور داخلی PHP قوانین .htaccess را اجرا نمی‌کند، پس نشتِ آنجا ایرادِ
// محصول نیست؛ فقط یادآوری است. مرجع نهایی، بررسی سخت‌گیرانهٔ Apache پایین است.
$total += $n1;
$info = $f1;

// اگر Apache روی پورت ۸۰ بالا بود، همان بررسی را سخت‌گیرانه تکرار کن
$apacheBase = 'http://127.0.0.1/uptime-bot-telegram';
[$ac] = hCall('GET', $apacheBase . '/status.php');
if ($ac === 404 || $ac === 200) {
    echo "  \n  --- Apache واقعی روی پورت 80 (سخت‌گیرانه) ---\n";
    [$f2, $n2] = scanPaths($apacheBase, $SENSITIVE, true, 'apache');
    $problems = array_merge($problems, $f2);
    $total += $n2;
} else {
    echo "  \n  \033[1;33m~\033[0m Apache روی پورت 80 در دسترس نیست؛ بررسی سخت‌گیرانه رد شد.\n";
    echo "     برای تست کامل:  laragon start  و بعد  php tests/fuzz_http.php\n";
}
echo "\n\033[1m[۹] اعتبار JSON خروجی api.php\033[0m\n";

foreach (['', '&full=1', '&days=3', '&days=abc', '&days=1e999', '&full=true', '&full[]=1'] as $q) {

    [$code, $body] = hCall('GET', "$web/api.php?u=$shareToken$q");

    if ($code !== 200) continue;

    $total++;

    $j = json_decode($body, true);

    if (!is_array($j)) {

        $problems[] = "api.php$q خروجی JSON معتبر نیست";

        printf("  \033[1;31m✗\033[0m api.php%s → JSON نامعتبر (%s)\n", $q, mb_substr(trim($body), 0, 80));

    } elseif (str_contains($body, 'NAN') || str_contains($body, 'INF')

              || str_contains($body, '-INF')) {

        $problems[] = "api.php$q مقدار NAN/INF در JSON";

        printf("  \033[1;31m✗\033[0m api.php%s → NAN/INF در خروجی\n", $q);

    }

}



// ═══════════════════════════════════════════════════════════════

echo "\n\033[1m═══ خلاصه ═══\033[0m\n";

printf("  بررسی‌ها: %d\n", $total);

printf("  مشکلات : %d\n", count($problems));

if ($info) {

    echo "\n  \033[1;33mیادآوری\033[0m (وب‌سرور داخلی PHP، فایل .htaccess را نمی‌خواند):\n";

    foreach ($info as $p) echo "    - $p\n";

}

if ($problems) {

    echo "\n  \033[1;31m✗ فهرست مشکلات:\033[0m\n";

    foreach ($problems as $p) echo "    - $p\n";

    echo "\n";

    exit(1);

}

echo "\n  \033[1;32m✓ همهٔ نقطه‌های ورود سالم\033[0m\n\n";

exit(0);



// ── کمکی ─────────────────────────────────────────────────────

/** دو عنصر برمی‌گرداند: [کد, بدنه] تا با spread به bad() بخورد */

function hCall(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 20): array

{

    $ch = curl_init($url);

    $h = [];

    foreach ($headers as $k => $v) $h[] = is_int($k) ? (string)$v : $k . ': ' . $v;

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,

        CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $h,

    ]);

    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $out = curl_exec($ch);

    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [$code, (string)$out];

}
