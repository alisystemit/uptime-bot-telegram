<?php
/**
 * ===== API عمومی صفحهٔ وضعیت (JSON) =====
 *
 *   GET /api.php?u=<share_token>   همهٔ مانیتورهای یک کاربر
 *   GET /api.php?g=<share_token>   مانیتورهای یک گروه/کانال
 *   GET /api.php?s=<share_token>   فقط یک مانیتور
 *
 * فقط وضعیت همان دامنهٔ داده برمی‌گردد؛ هیچ اطلاعات خصوصی (نام، شماره،
 * توکن ربات و…) در پاسخ نیست. مصرف این endpoint توسط status.php برای
 * بروزرسانی زندهٔ صفحه انجام می‌شود.
 *
 * پارامتر full=1 نسخهٔ سنگین (نمودارها، رخدادها، SSL) را برمی‌گرداند.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/lib/bootstrap.php';

$out = ['ok' => false, 'error' => null];

$u = trim((string)($_GET['u'] ?? ''));
$g = trim((string)($_GET['g'] ?? ''));
$s = trim((string)($_GET['s'] ?? ''));
$full = isset($_GET['full']);

if ($u === '' && $g === '' && $s === '') {
    http_response_code(400);
    $out['error'] = 'missing_token';
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    appBoot();
} catch (Throwable $e) {
    uptimeLog('error', 'api boot failed: ' . $e->getMessage());
    http_response_code(503);
    $out['error'] = 'database';
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $ctx = Page::resolve($u, $g, $s);
    if (!$ctx) {
        http_response_code(404);
        $out['error'] = 'not_found';
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $out = Page::payload($ctx, !$full);
} catch (Throwable $e) {
    uptimeLog('error', 'api failed: ' . $e->getMessage());
    http_response_code(500);
    $out['error'] = 'internal';
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
