<?php
/**
 * ===== تشخیص initData: چرا اعتبارسنجی رد شد؟ =====
 *
 * CLI:
 *   php tools/initdata_debug.php '<رشتهٔ initData>'
 *   php tools/initdata_debug.php -f path/to/initdata.txt
 *   یا initData را روی stdin بدهید:
 *   cat initdata.txt | php tools/initdata_debug.php
 *
 * وب (فقط با secret):
 *   tools/initdata_debug.php?initData=...&secret=<cron secret>
 *
 * خروجی دقیقاً می‌گوید کدام مرحله رد کرده، بدون حدس‌زدن.
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
    // -f <file>
    $args = $argv ?? [];
    for ($i = 1; $i < count($args); $i++) {
        if (($args[$i] === '-f' || $args[$i] === '--file') && isset($args[$i + 1])) {
            $initData = (string)@file_get_contents($args[$i + 1]);
            break;
        }
    }
}

if ($initData === '' && PHP_SAPI === 'cli') {
    $stdin = @file_get_contents('php://stdin');
    if (is_string($stdin) && trim($stdin) !== '') $initData = trim($stdin);
}

if ($initData === '') {
    echo "usage:\n";
    echo "  php tools/initdata_debug.php '<initData>'\n";
    echo "  php tools/initdata_debug.php -f initdata.txt\n";
    echo "  cat initdata.txt | php tools/initdata_debug.php\n";
    exit(1);
}

$token = botToken();

echo "══════════════════════════════════════════════════════\n";
echo " diagnosing initData\n";
echo "══════════════════════════════════════════════════════\n\n";

// ── نویسه‌های نامرئی که گاهی از کپی/هدر/JSON می‌آیند ──
// U+FEFF (BOM) و U+00A0 (nbsp) با چشم دیده نمی‌شوند ولی کلیدها را
// خراب می‌کنند: مثلاً «﻿auth_date» به‌جای «auth_date».
$rawHex = substr(bin2hex($initData), 0, 12);
$bomAtStart = strncmp($initData, "\xEF\xBB\xBF", 3) === 0;
$cleaned = trim($initData);
$hadNbsp = str_contains($cleaned, "\xC2\xA0");
$cleaned = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"], ' ', $cleaned);
$cleaned = trim($cleaned);

$dirty = ($cleaned !== $initData);
if ($dirty) {
    echo "⚠ INVISIBLE CHARACTERS DETECTED — cleaning before parsing\n";
    if ($bomAtStart) echo "   • U+FEFF BOM at the start (common when copying from a file)\n";
    if ($hadNbsp)     echo "   • U+00A0 non-breaking space present\n";
    echo "   first bytes: $rawHex\n\n";
    $initData = $cleaned;
}

echo "bot            : @" . botUsername() . "\n";
echo "bot id         : " . explode(':', (string)$token)[0] . "\n";
echo "token length   : " . strlen($token) . "\n";
echo "initData length: " . strlen($initData) . "\n\n";

// ---------- ۱) تجزیه دستی (نه parse_str) ----------
$fields = [];
foreach (explode('&', $initData) as $chunk) {
    if ($chunk === '') continue;
    $eq = strpos($chunk, '=');
    if ($eq === false) { $fields['__malformed'] = $chunk; continue; }
    $fields[rawurldecode(substr($chunk, 0, $eq))] = rawurldecode(substr($chunk, $eq + 1));
}

echo "── 1. parsed keys ──\n";
$keys = array_keys($fields);
sort($keys);
foreach ($keys as $k) {
    $v = (string)$fields[$k];
    echo "   " . str_pad($k, 16) . " len=" . str_pad((string)strlen($v), 6);
    if (in_array($k, ['signature', 'hash'], true)) {
        echo " " . substr($v, 0, 40) . "…";
    }
    echo "\n";
}
echo "\n";

echo "   has hash field     : " . (isset($fields['hash']) ? 'YES' : 'NO') . "\n";
echo "   has signature field: " . (isset($fields['signature']) ? 'YES' : 'NO') . "\n\n";

// ---------- ۲) بررسی خطاهای رایج ----------
echo "── 2. common problems ──\n";
$problems = 0;

if (!isset($fields['hash'])) {
    echo "   ✗ no 'hash' field — validator cannot verify anything\n";
    echo "     Usually means: the app was opened outside Telegram, or the\n";
    echo "     header was empty. Check: Telegram.WebApp.initData.length\n";
    $problems++;
} else {
    $h = (string)$fields['hash'];
    if (!ctype_xdigit($h)) {
        echo "   ✗ 'hash' is not hex — payload is malformed\n";
        $problems++;
    } elseif (strlen($h) !== 64) {
        echo "   ✗ 'hash' length is " . strlen($h) . " (expected 64 for SHA-256)\n";
        $problems++;
    } else {
        echo "   ✓ hash looks well-formed (64 hex chars)\n";
    }
}

if (isset($fields['signature'])) {
    $s = (string)$fields['signature'];
    echo "   i signature present (" . strlen($s) . " chars, base64 Ed25519)\n";
    echo "     → for HMAC validation the signature STAYS in the data-check-string\n";
    echo "     → only the Ed25519 third-party check excludes it\n";
}

if (isset($fields['auth_date'])) {
    $ad = (int)$fields['auth_date'];
    $age = time() - $ad;
    if ($age < 0) {
        echo "   ✗ auth_date is " . abs($age) . "s in the FUTURE — check server clock\n";
        $problems++;
    } elseif ($age > 604800) {
        echo "   ✗ auth_date is " . round($age / 86400, 1) . " days old (max 7)\n";
        $problems++;
    } else {
        echo "   ✓ auth_date is " . round($age / 60) . " min old\n";
    }
} else {
    echo "   ✗ no auth_date field\n";
    $problems++;
}

if (isset($fields['user'])) {
    $u = json_decode((string)$fields['user'], true);
    if (!is_array($u)) {
        echo "   ✗ user field is not valid JSON\n";
        $problems++;
    } elseif ((int)($u['id'] ?? 0) <= 0) {
        echo "   ✗ user.id missing or zero\n";
        $problems++;
    } else {
        echo "   ✓ user.id = " . (int)$u['id'] . "\n";
    }
} else {
    echo "   ✗ no user field\n";
    $problems++;
}
echo "\n";

// ---------- ۳) محاسبهٔ هش در دو حالت ----------
echo "── 3. hash verification ──\n";
if (isset($fields['hash'])) {
    $base = $fields;
    unset($base['hash']);
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);

    foreach ([
        'signature INCLUDED (correct per official docs for HMAC)' => true,
        'signature EXCLUDED (Ed25519 third-party rule — do NOT use)' => false,
    ] as $label => $dropSig) {
        $set = $base;
        if ($dropSig) unset($set['signature']);
        ksort($set, SORT_STRING);
        $pairs = [];
        foreach ($set as $k => $v) $pairs[] = $k . '=' . $v;
        $calc = hash_hmac('sha256', implode("\n", $pairs), $secret);
        $match = hash_equals($calc, (string)$fields['hash']);
        echo "   " . ($match ? '✓ MATCH' : '✗ no  ') . "  " . $label . "\n";
        if ($match) echo "      computed: $calc\n";
    }
    echo "\n";
}

// ---------- ۴) نتیجه نهایی با کد واقعی ----------
echo "── 4. MiniApp::validateInitData ──\n";
$u = MiniApp::validateInitData($initData, $token);
if ($u) {
    echo "   ✓ VALID — user id " . (int)$u['id'] . " (" . ($u['username'] ?? '-') . ")\n";
    echo "\n   ⇒ the server WOULD accept this payload.\n";
    if ($problems > 0) {
        echo "   ⚠ but see the problems listed above.\n";
    }
} else {
    echo "   ✗ REJECTED\n";
    echo "\n   The bot will answer 401 auth and the app shows the lock screen.\n";
}
echo "\n";
echo str_repeat('═', 55) . "\n";