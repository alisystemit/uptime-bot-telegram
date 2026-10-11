<?php
/**
 * ===== ابزار تشخیص نهایی: چرا hash مطابقت ندارد؟ =====
 *
 * این ابزار ۴ علت ممکن را هم‌زمان بررسی می‌کند و دقیقاً می‌گوید
 * کدام‌یک رخ داده — به‌جای حدس‌زدن.
 *
 * CLI:
 *   php tools/hash_debug.php -f initdata.txt
 *   cat initdata.txt | php tools/hash_debug.php
 *   php tools/hash_debug.php '<initData>'
 *
 * وب (با secret):
 *   tools/hash_debug.php?secret=<SECRET>&initData=<...>
 */

if (PHP_SAPI !== 'cli') {
    require_once dirname(__DIR__) . '/lib/bootstrap.php';
    $cfg = appConfig();
    $expected = hash('sha256', (string)($cfg['bot_token'] ?? '') . '_uptime_webhook_secret');
    $given = (string)($_GET['secret'] ?? ($_SERVER['HTTP_X_SECRET'] ?? ''));
    if ($given === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$initData = (string)($_GET['initData'] ?? '');

if ($initData === '' && PHP_SAPI === 'cli') {
    $args = $argv ?? [];
    for ($i = 1; $i < count($args); $i++) {
        if (($args[$i] === '-f' || $args[$i] === '--file') && isset($args[$i + 1])) {
            $initData = (string)@file_get_contents($args[$i + 1]);
            break;
        }
    }
}
if ($initData === '') {
    $s = @file_get_contents('php://stdin');
    if (is_string($s) && trim($s) !== '') $initData = trim($s);
}
if ($initData === '') {
    echo "usage:\n  php tools/hash_debug.php -f initdata.txt\n"
       . "  cat initdata.txt | php tools/hash_debug.php\n"
       . "  php tools/hash_debug.php '<initData>'\n";
    exit(1);
}

$cfg = appConfig();
$token = (string)($cfg['bot_token'] ?? '');

function bold2(string $s): string { return "\033[1m" . $s . "\033[0m"; }
function ok2(string $m): void { echo "   \033[32m✓\033[0m $m\n"; }
function no2(string $m): void { echo "   \033[31m✗\033[0m $m\n"; }
function wa(string $m): void { echo "   \033[33m!\033[0m $m\n"; }

echo "\n";
echo "════════════════════════════════════════════════════════════\n";
echo "  hash_debug — چرا hash مطابقت ندارد؟\n";
echo "════════════════════════════════════════════════════════════\n\n";

// ─────────────────────────────────────────────────────────────
echo "STEP 1 — bot_token\n";
echo "─────────────────────────────────────────────────────────────\n";

$tokenIssues = [];
if ($token === '') {
    no2('bot_token خالی است!');
    $tokenIssues[] = 'empty';
} else {
    ok2('طول: ' . strlen($token) . ' (انتظار ~46)');
    $parts = explode(':', $token);
    if (count($parts) !== 2) {
        no2('توکن باید دقیقاً یک «:» داشته باشد (دارد: ' . count($parts) . ')');
        $tokenIssues[] = 'format';
    } else {
        ok2('ساختار id:secret ✓');
        ok2('bot id = ' . $parts[0]);
        ok2('secret length = ' . strlen($parts[1]));
    }
    if (!ctype_digit($parts[0] ?? '')) { no2('bot id باید عدد باشد'); $tokenIssues[] = 'format'; }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $parts[1] ?? '')) { no2('secret نویسهٔ غیرمجاز دارد'); $tokenIssues[] = 'format'; }
}
if (preg_match('/\s/', $token)) { no2('توکن فاصله/خط‌جدید دارد!'); $tokenIssues[] = 'whitespace'; }
if (strncmp($token, "\xEF\xBB\xBF", 3) === 0) { no2('BOM در ابتدای توکن!'); $tokenIssues[] = 'bom'; }
if (str_contains($token, "'") || str_contains($token, '"')) { no2('توکن کوتیشن دارد!'); $tokenIssues[] = 'quotes'; }

echo "   sha256(token) = " . substr(hash('sha256', $token), 0, 32) . "...\n";
echo "   (این مقدار را با سرور مقایسه کنید تا مطمئن شوید یکی هستند)\n\n";

// ─────────────────────────────────────────────────────────────
echo "STEP 2 — تجزیهٔ initData\n";
echo "─────────────────────────────────────────────────────────────\n";

$initData = trim($initData);
$rawLenBefore = strlen($initData);

$fields = [];
foreach (explode('&', $initData) as $chunk) {
    if ($chunk === '') continue;
    $eq = strpos($chunk, '=');
    if ($eq === false) continue;
    $fields[rawurldecode(substr($chunk, 0, $eq))] = rawurldecode(substr($chunk, $eq + 1));
}

$keys = array_keys($fields);
sort($keys);
foreach ($keys as $k) {
    $v = (string)$fields[$k];
    $preview = in_array($k, ['signature', 'hash'], true) ? substr($v, 0, 20) . '…' : $v;
    echo "   " . str_pad($k, 15) . " = " . $preview . "\n";
}
echo "\n";

$dataIssues = [];
$authDateAge = null;
if (!isset($fields['hash'])) { no2("کلید 'hash' وجود ندارد!"); $dataIssues[] = 'no-hash'; }
else {
    $h = (string)$fields['hash'];
    ok2('hash طول = ' . strlen($h) . ' (انتظار 64)');
    if (strlen($h) !== 64) {
        no2('hash باید دقیقاً ۶۴ رقم hex باشد ولی ' . strlen($h) . ' است');
        $dataIssues[] = 'hash-length';
    }
    if (!ctype_xdigit($h)) { no2('hash شامل نویسهٔ غیر-hex است'); $dataIssues[] = 'hash-charset'; }
}

// نویسه‌های نامرئی در کلیدها
foreach ($keys as $k) {
    if ($k !== trim($k) || preg_match('/[\x{00A0}\x{FEFF}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', $k)) {
        no2("کلید '$k' نویسهٔ نامرئی دارد — نام واقعی: '" . trim($k) . "'");
        $dataIssues[] = 'invisible-key';
    }
}
if (preg_match('/[\x{00A0}\x{FEFF}\x{200B}]/u', $initData)) {
    wa('نویسهٔ نامرئی داخل initData دیده می‌شود (می‌تواند هش را خراب کند)');
    $dataIssues[] = 'invisible-value';
}

// سن initData — برای تشخیص payload کهنه
if (isset($fields['auth_date']) && ctype_digit((string)$fields['auth_date'])) {
    $authDateAge = time() - (int)$fields['auth_date'];
    $ageTxt = $authDateAge < 0
        ? abs($authDateAge) . ' ثانیه در آینده (اختلاف ساعت سرور؟)'
        : ($authDateAge < 3600
            ? round($authDateAge / 60) . ' دقیقه'
            : round($authDateAge / 3600, 1) . ' ساعت');
    echo "   auth_date    = $ageTxt پیش\n";
    if ($authDateAge < -300) { no2('auth_date در آینده است — ساعت سرور اشتباه است'); $dataIssues[] = 'future'; }
    elseif ($authDateAge > 604800) { no2('auth_date بیش از ۷ روز قدیمی است'); $dataIssues[] = 'stale'; }
    else { ok2('auth_date در بازهٔ معتبر (۷ روز)'); }
}

// ─────────────────────────────────────────────────────────────
echo "\nSTEP 3 — محاسبهٔ هش در همهٔ حالت‌ها\n";
echo "─────────────────────────────────────────────────────────────\n";

if (!isset($fields['hash'])) {
    echo "   بدون hash نمی‌توان مقایسه کرد.\n\n";
} else {
    $received = (string)$fields['hash'];
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    $base = $fields;
    unset($base['hash']);

    $variants = [
        'A: فقط hash حذف شود  (قاعدهٔ رسمی HMAC)' => static function (array $f) {
            return $f;
        },
        'B: hash و signature حذف شوند (قاعدهٔ Ed25519)' => static function (array $f) {
            unset($f['signature']);
            return $f;
        },
        'C: hash حذف، بدون auth_date' => static function (array $f) {
            unset($f['auth_date']);
            return $f;
        },
        'D: hash و signature و auth_date حذف' => static function (array $f) {
            unset($f['signature'], $f['auth_date']);
            return $f;
        },
        'E: hash حذف، بدون query_id' => static function (array $f) {
            unset($f['query_id']);
            return $f;
        },
        'F: hash حذف، فقط user' => static function (array $f) {
            return array_intersect_key($f, ['user' => 1]);
        },
    ];

    $matched = null;
    foreach ($variants as $label => $fn) {
        $set = $fn($base);
        ksort($set, SORT_STRING);
        $pairs = [];
        foreach ($set as $k => $v) $pairs[] = $k . '=' . $v;
        $calc = hash_hmac('sha256', implode("\n", $pairs), $secret);
        $isMatch = hash_equals($calc, $received);
        if ($isMatch) $matched = $label;
        echo "   " . ($isMatch ? "\033[32m✓ MATCH\033[0m" : '  no     ') . "  $label\n";
        if ($isMatch) echo "             $calc\n";
    }

    // نمایش کامل هش برای مقایسهٔ دستی
    echo "\n   received (کامل): $received\n";
    $set = $base;
    ksort($set, SORT_STRING);
    $pairs = [];
    foreach ($set as $k => $v) $pairs[] = $k . '=' . $v;
    echo "   computed (کامل): " . hash_hmac('sha256', implode("\n", $pairs), $secret) . "\n";
    echo "\n";

    if ($matched !== null) {
        echo "   \033[32m✔ یک حالت مطابقت داد: $matched\033[0m\n";
    } else {
        echo "   \033[31m✗ هیچ حالتی مطابقت نداد.\033[0m\n";
    }
}

// ─────────────────────────────────────────────────────────────
echo "\nSTEP 4 — نتیجه‌گیری\n";
echo "─────────────────────────────────────────────────────────────\n";

$user = MiniApp::validateInitData($initData, $token);
if ($user) {
    ok2('validateInitData موفق — user id = ' . (int)$user['id']);
} else {
    no2('validateInitData رد کرد');
}

// آیا توکن واقعاً با این payload جور است؟
// (مستقل از validateInitData: فقط HMAC خام را می‌سنجیم)
$rawTokenMatches = false;
if (isset($fields['hash']) && $token !== '' && !in_array('empty', $tokenIssues, true)) {
    $set = $fields;
    unset($set['hash']);
    ksort($set, SORT_STRING);
    $pairs = [];
    foreach ($set as $k => $v) $pairs[] = $k . '=' . $v;
    $rawTokenMatches = hash_equals(
        hash_hmac('sha256', implode("\n", $pairs), hash_hmac('sha256', $token, 'WebAppData', true)),
        (string)$fields['hash']
    );
}

echo "\n";

if ($rawTokenMatches) {
    echo "\033[32m⇒ توکن و initData هر دو سالم‌اند.\033[0m\n";
    echo "  هش درست محاسبه می‌شود ⇒ اگر باز هم خطا می‌گیرید، مشکل جای دیگری است\n";
    echo "  (مثلاً کد قدیمی روی سرور، یا proxy که initData را تغییر می‌دهد).\n";
} elseif ($dataIssues && !$tokenIssues) {
    echo "\033[33m⇒ مشکل از initData است، نه توکن.\033[0m\n";
    foreach ($dataIssues as $d) {
        $msg = [
            'no-hash' => "کلید hash وجود ندارد (اپ بیرون از تلگرام باز شده؟)",
            'hash-length' => 'hash ناقص یا کوتاه است',
            'hash-charset' => 'hash شامل نویسهٔ غیر-hex است',
            'invisible-key' => 'نام کلیدها نویسهٔ نامرئی دارد (BOM/nbsp)',
            'invisible-value' => 'داخل initData نویسهٔ نامرئی هست',
        ][$d] ?? $d;
        echo "    • $msg\n";
    }
    echo "  راه‌حل: اپ را ببندید و از دستور /app در ربات دوباره باز کنید.\n";
} elseif ($tokenIssues && !$dataIssues) {
    echo "\033[33m⇒ مشکل از bot_token در config.php است.\033[0m\n";
    foreach ($tokenIssues as $t) {
        $msg = [
            'empty' => 'توکن خالی است',
            'format' => 'قالب توکن نادرست است',
            'whitespace' => 'توکن فاصله یا خط‌جدید دارد',
            'bom' => 'BOM در ابتدای توکن',
            'quotes' => 'کوتیشن در توکن',
        ][$t] ?? $t;
        echo "    • $msg\n";
    }
    echo "  راه‌حل: توکن تازه از @BotFather بگیرید و در config.php بگذارید.\n";
} elseif ($tokenIssues && $dataIssues) {
    echo "\033[31m⇒ هم توکن و هم initData ایراد دارند.\033[0m\n";
} else {
    echo "\033[33m⇒ ساختار توکن و initData سالم است، ولی هش نخواند.\033[0m\n";
    echo "  یعنی این payload با این توکن امضا نشده است. دو حالت محتمل:\n\n";

    if ($authDateAge > 3600) {
        echo "  \033[33m• محتمل‌تر: initData قدیمی/کهنه است\033[0m\n";
        echo "    auth_date " . round($authDateAge / 60) . " دقیقه پیش است.\n";
        echo "    تلگرام initData را فقط هر بار که اپ باز می‌شود تازه می‌کند.\n";
        echo "    اگر این رشته را از یک لاگ قدیمی کپی کرده‌اید، نتیجه همین است.\n";
        echo "    راه‌حل: اپ را ببندید و از دستور /app در ربات دوباره باز کنید\n";
        echo "    و همان لحظه رشتهٔ تازه را بردارید.\n";
    }

    if ($authDateAge >= -300 && $authDateAge <= 3600) {
        echo "  \033[33m• محتمل‌تر: این initData متعلق به ربات دیگری است\033[0m\n";
        echo "    auth_date تازه است، پس داده هست ولی با توکن این ربات\n";
        echo "    امضا نشده. آدرس Web App در BotFather را بررسی کنید:\n";
        echo "    آیا به همین ربات اشاره می‌کند؟\n";
    }

    echo "\n  اگر مطمئنید initData تازه و از همین ربات است:\n";
    echo "  • کد روی سرور را با این نسخه هم‌تراز کنید (lib/miniapp.php)\n";
    echo "  • اگر proxy یا CDN دارید، ممکن است initData را تغییر داده باشد\n";
    echo "  • خروجی hash_debug را با initdata_debug.php روی سرور مقایسه کنید\n";
}

if (!$rawTokenMatches && !isset($fields['hash'])) {
    echo "\n  بدون کلید hash، مقایسهٔ مستقیم ممکن نیست.\n";
}

echo "\n" . str_repeat('═', 60) . "\n";