<?php
/**
 * ===== نصب/به‌روزرسانی جدول‌های ربات مانیتورینگ =====
 *
 * همهٔ جدول‌ها با CREATE TABLE IF NOT EXISTS ساخته می‌شوند؛ اجرای چندباره مجاز است.
 *
 * دسترسی:
 *   - CLI:  php table.php
 *   - HTTP: https://domain/bots/<slug>/table.php?secret=sha256(token + "_uptime_table_secret")
 *
 * خروجی پیش‌فرض HTML ساده است؛ با ?json خروجی JSON می‌گیرید.
 */

// ---------- گارد دسترسی table.php ----------
// فراخوانی مستقیم از HTTP فقط با secret مجاز است؛ اجرای CLI و include داخلی آزاد می‌مانند.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    $upTableScript = isset($_SERVER['SCRIPT_FILENAME']) ? @realpath($_SERVER['SCRIPT_FILENAME']) : false;
    if ($upTableScript !== false && $upTableScript === @realpath(__FILE__)) {
        $upTableCfgRaw = is_readable(__DIR__ . '/config.php') ? (string)@file_get_contents(__DIR__ . '/config.php') : '';
        $upTableToken  = '';
        if (preg_match('/[\'"]bot_token[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $upTableCfgRaw, $upTableM)) {
            $upTableToken = (string)$upTableM[1];
        }
        $upTableSecret = ($upTableToken !== '' && $upTableToken !== '{BOT_TOKEN}')
            ? hash('sha256', $upTableToken . '_uptime_table_secret')
            : '';
        $upTableProvided = isset($_GET['secret']) && is_string($_GET['secret']) ? $_GET['secret'] : '';
        if ($upTableSecret === '' || $upTableProvided === '' || !hash_equals($upTableSecret, $upTableProvided)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
    unset($upTableScript, $upTableCfgRaw, $upTableToken, $upTableM, $upTableSecret, $upTableProvided);
}

@set_time_limit(120);

require_once __DIR__ . '/lib/bootstrap.php';

$wantJson = isset($_GET['json']);
$result   = ['ok' => false, 'created' => [], 'tables' => [], 'error' => null, 'url' => null];

try {
    $cfg = appConfig();
    Db::setConfig($cfg);

    // قبل از migrate فهرست جدول‌های موجود را بگیر تا بگوییم چه چیزی ساخته شد
    $before = [];
    try {
        foreach (Db::q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $before[] = (string)$t;
    } catch (Throwable $e) { /* دیتابیس هنوز وصل نیست */ }

    Db::migrate();

    $after = [];
    foreach (Db::q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $after[] = (string)$t;

    $result['ok']      = true;
    $result['tables']  = $after;
    $result['created'] = array_values(array_diff($after, $before));

    // راهنمای کرون + نسخهٔ وب
    $secret = hash('sha256', (string)$cfg['bot_token'] . '_uptime_cron_secret');
    $base   = rtrim((string)($cfg['base_url'] ?? ''), '/');
    if ($base === '' && !empty($cfg['domain'])) $base = 'https://' . $cfg['domain'];
    $result['url'] = $base;
    $result['cron'] = [
        'cli'    => 'php ' . __DIR__ . '/cron/checker.php',
        'http'   => $base . '/cron/checker.php?secret=' . $secret,
        'status' => $base . '/status.php',
    ];
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    uptimeLog('error', 'table.php failed: ' . $e->getMessage());
}

if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
if ($result['ok']) {
    $li = '';
    foreach ($result['tables'] as $t) {
        $isNew = in_array($t, $result['created'], true);
        $li .= '<li><code>' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</code>'
            . ($isNew ? ' <span class="new">جدید</span>' : '') . '</li>';
    }
    $cron = $result['cron'];
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Uptime — نصب جدول‌ها</title><style>'
        . 'body{font-family:Segoe UI,Tahoma,sans-serif;background:#0b1220;color:#dbe7ff;margin:0;padding:32px}'
        . '.card{max-width:760px;margin:auto;background:#121c31;border:1px solid #22314f;border-radius:16px;padding:24px}'
        . 'h1{font-size:20px;margin:0 0 16px}code{background:#0b1220;padding:2px 6px;border-radius:6px;color:#7dd3fc}'
        . 'li{margin:6px 0}.new{color:#34d399;font-size:12px}a{color:#38bdf8}'
        . '.ok{color:#34d399}.err{color:#f87171}ul{padding-inline-start:18px}'
        . '</style></head><body><div class="card">';
    echo '<h1 class="ok">✅ جدول‌های ربات مانیتورینگ آماده شد</h1>';
    echo '<p>این صفحه قابل اشتراک‌گذاری نیست؛ فقط با secret باز می‌شود.</p><ul>' . $li . '</ul>';
    echo '<p><b>کرون را تنظیم کنید (یکی از این‌ها):</b></p>';
    echo '<p>۱) <code>' . htmlspecialchars($cron['cli'], ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p>۲) <code>* * * * * curl -s "' . htmlspecialchars($cron['http'], ENT_QUOTES, 'UTF-8') . '"</code></p>';
    echo '<p>۳) دیمون: <code>php ' . htmlspecialchars(__DIR__ . '/cron/checker.php --daemon', ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '<p>🧪 <a href="' . htmlspecialchars($cron['status'], ENT_QUOTES, 'UTF-8') . '">صفحهٔ وضعیت عمومی</a></p>';
    echo '</div></body></html>';
} else {
    http_response_code(500);
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>خطا</title>'
        . '<body style="font-family:Tahoma;background:#0b1220;color:#f87171;padding:32px">'
        . '<h1>❌ خطا در ساخت جدول‌ها</h1><p>'
        . htmlspecialchars((string)$result['error'], ENT_QUOTES, 'UTF-8')
        . '</p><p style="color:#dbe7ff">اطلاعات اتصال دیتابیس را در config.php بررسی کنید.</p></body></html>';
}
