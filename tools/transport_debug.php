<?php
/**
 * تست قطعی: آیا هدر واقعاً initData را سالم می‌رساند؟
 * این اسکریپت را روی سرور بگذارید و از بیرون صدا بزنید.
 */

require_once __DIR__ . '/../lib/bootstrap.php';

$path = $_SERVER['REQUEST_URI'] ?? '?';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$hdr = (string)($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '');
$get = (string)($_GET['initData'] ?? '');
$post = (string)($_POST['initData'] ?? '');

function info(string $label, string $v): void
{
    $len = strlen($v);
    $keys = [];
    if ($len) {
        foreach (explode('&', $v) as $c) {
            $e = strpos($c, '=');
            if ($e !== false) $keys[] = rawurldecode(substr($c, 0, $e));
        }
    }
    echo "$label\n";
    echo "   length : " . ($len ?: '(empty)') . "\n";
    echo "   keys   : " . ($keys ? implode(', ', $keys) : '(none)') . "\n";
    echo "   sha256 : " . ($len ? substr(hash('sha256', $v), 0, 32) . '…' : '-') . "\n";
    echo "   BOM    : " . (strncmp($v, "\xEF\xBB\xBF", 3) === 0 ? "YES ⚠" : 'no') . "\n";
    echo "   spaces : " . substr_count($v, ' ') . "\n";
    echo "   plus   : " . substr_count($v, '+') . "\n\n";
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== initData transport diagnostic ===\n\n";
echo "REQUEST_URI : $path\n";
echo "METHOD      : $method\n";
echo "REMOTE_ADDR : " . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\n";
echo "SERVER      : " . ($_SERVER['SERVER_SOFTWARE'] ?? '-') . "\n\n";

echo "── 1. header X-Telegram-Init-Data ──\n\n";
info('  $HTTP_X_TELEGRAM_INIT_DATA', $hdr);

echo "── 2. query string ?initData= ──\n\n";
info('  $_GET[initData]', $get);

echo "── 3. POST body initData ──\n\n";
info('  $_POST[initData]', $post);

echo "── 4. verdict ──\n\n";
$best = $hdr ?: ($get ?: $post);
if ($best === '') {
    echo "  ✗ هیچ initDataای نرسید.\n";
    echo "    ⇒ یا اپ بیرون از تلگرام باز شده، یا پروکسی همه را حذف کرده.\n\n";
} else {
    $src = $hdr !== '' ? 'header' : ($get !== '' ? 'query string' : 'POST body');
    echo "  source used: $src\n\n";
    $user = MiniApp::validateInitData($best, botToken());
    if ($user) {
        echo "  ✓ اعتبارسنجی موفق — user id " . (int)$user['id'] . "\n";
    } else {
        echo "  ✗ اعتبارسنجی رد شد.\n\n";
        echo "  اگر keys درست و hash موجود است ولی رد می‌شود:\n";
        echo "    ⇒ initData دست‌نخورده نیست (proxy/WAF آن را تغییر داده)\n";
        echo "    ⇒ یا initData کهنه/foreign است\n\n";
        foreach (explode('&', $best) as $c) {
            $e = strpos($c, '=');
            if ($e === false) continue;
            $k = rawurldecode(substr($c, 0, $e));
            if ($k === 'hash') echo "  hash (کامل): " . rawurldecode(substr($c, $e + 1)) . "\n";
        }
    }
}

echo "\n=== end ===\n";