<?php
/**
 * ===== نقطهٔ بازگشت و وب‌هوک درگاه‌های پرداخت =====
 *
 *   GET  /pay.php?g=<code>&Authority=…&Status=OK      → بازگشت خریدار از درگاه
 *   POST /pay.php?g=<code>  {…}                        → وب‌هوک درگاه
 *
 * پاسخ همیشه HTTP 200 با بدنهٔ ساده است تا درگاه پیام خطا نبیند و بیخودی
 * تکرار نکند؛ نتیجهٔ واقعی از طریق تلگرام به خریبر/مدیر اطلاع داده می‌شود.
 *
 * امنیت:
 *  - اگر درگاه امضا داشته باشد (واریزا) و secret تنظیم شده باشد، بدون امضای
 *    درست هیچ اتفاقی نمی‌افتد.
 *  - حتی با وب‌هوک جعلی، فعال‌سازی اشتراک فقط از مسیر verify انجام می‌شود.
 *  - برای درایوهای مسیر امن، هیچ داده‌ای از این فایل به تلگرام لو نمی‌رود
 *  - از این فایل هیچ دادهٔ حساسی به تلگرام لو نمی‌رود؛ فقط متن ثابت خودمان.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
$raw = $isCli ? '' : (string)file_get_contents('php://input');
$input = $_REQUEST;
if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
    $j = json_decode($raw, true);
    if (is_array($j)) $input = array_merge($input, $j);
}
/**
 * خواندن هدرهای درخواست به‌صورت سازگار با همهٔ وب‌سرورها.
 * getallheaders() روی nginx وجود ندارد؛ پس به $_SERVER['HTTP_*'] هم نگاه می‌کنیم
 * (کلیدها همیشه با خط تیرهٔ کوچک برگردانده می‌شوند).
 */
function payHeaders(): array
{
    $out = [];
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) $out[strtolower((string)$k)] = (string)$v;
    }
    foreach ($_SERVER as $k => $v) {
        if (strncmp((string)$k, 'HTTP_', 5) === 0) {
            $out[strtolower(str_replace('_', '-', substr((string)$k, 5)))] = (string)$v;
        }
    }
    // بعضی درگاه‌ها امضا را در بدنهٔ فرم می‌فرستند
    if (!isset($out['x-webhook-signature']) && isset($_POST['signature'])) {
        $out['x-webhook-signature'] = (string)$_POST['signature'];
    }
    return $out;
}
$headers = payHeaders();

$code = (string)($input['g'] ?? $_GET['g'] ?? '');
$orderId = (int)($input['payment_id'] ?? 0);

/** صفحهٔ نتیجه برای خریدار */
function payPage(string $title, string $msg, string $btn = ''): void
{
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = nl2br(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
    $b = $btn !== '' ? '<p style="margin-top:22px"><a href="' . htmlspecialchars($btn, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="display:inline-block;background:linear-gradient(140deg,#1d4ed8,#0ea5e9);color:#fff;'
        . 'text-decoration:none;padding:11px 22px;border-radius:12px;font-weight:600">بازگشت به ربات</a></p>' : '';
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . $t . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:radial-gradient(1100px 500px at 50% -10%,#14305f 0%,#070b14 60%);color:#e7eefc;'
        . 'font-family:Vazirmatn,Segoe UI,Tahoma,sans-serif}'
        . '.c{background:#0f1626;border:1px solid #1e2a44;border-radius:20px;padding:36px;max-width:460px;'
        . 'text-align:center;box-shadow:0 20px 50px -30px #000}'
        . '.i{font-size:44px;line-height:1;margin-bottom:12px}'
        . 'h1{font-size:19px;margin:0 0 10px}p{color:#93a4c3;line-height:2;margin:0;font-size:14px}'
        . '</style></head><body><div class="c"><div class="i">✅</div><h1>' . $t . '</h1><p>' . $m . '</p>'
        . $b . '</div></body></html>';
}

if ($code === '') {
    http_response_code(400);
    payPage('درگاه نامعتبر', 'کد درگاه در آدرس مشخص نیست.');
    exit;
}

try {
    appBoot();
} catch (Throwable $e) {
    uptimeLog('error', 'pay.php boot failed: ' . $e->getMessage());
    http_response_code(503);
    payPage('اتصال برقرار نشد', 'در حال حاضر امکان بررسی پرداخت نیست. چند لحظه دیگر تلاش کنید.');
    exit;
}

$botLink = 'https://t.me/' . botUsername();

// اگر فقط «بازگشت کاربر» است و نه وب‌هوک، مستقیم به ربات هدایت می‌کنیم
$isWebhook = !$isCli && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if (!$isWebhook && $orderId === 0) {
    $gw = PayGws::get($code);
    // زرین‌پال/تترا کاربر را به ربات برمی‌گردانند و تأیید باید از سرور انجام شود
    if ($gw && PayGws::driver($code) && !PayGws::driver($code)::signed($gw)) {
        header('Location: ' . $botLink . '?start=paycheck');
        exit;
    }
}

$r = Pay::callback($code, $input, $raw, $headers);
// کد وضعیت واقعی برگردانده می‌شود (۴۰۰ برای امضای نامعتبر، ۴۰۴ برای درگاه
// ناشناخته)؛ فقط پردازش‌های موفق و «سفارش ناشناس» ۲۰۰ می‌گیرند تا درگاه
// بیخودی تلاش تکراری نکند.
http_response_code(max(200, min(599, (int)($r['status'] ?? 200))));

$msg = (string)($r['message'] ?? '');
$done = !empty($r['payment_id']) && str_contains($msg, 'فعال شد');

// اگر کاربر داخل تلگرام منتظر است، نتیجه را همان‌جا هم می‌گوییم
if (!empty($r['payment_id'])) {
    $p = Db::one('SELECT `user_id`,`status`,`gateway` FROM `payments` WHERE `id` = ?', [(int)$r['payment_id']]);
    if ($p) {
        $uid = (int)$p['user_id'];
        if ($done || (string)$p['status'] === 'approved') {
            tgSend($uid, "🎉 پرداخت شما با موفقیت تأیید شد و اشتراک ویژه فعال گردید.\n\n"
                . "اگر پیام جزئیات را ندیدید، از منوی «🛒 اشتراک ویژه» وضعیت را ببینید.");
        } elseif (str_contains($msg, 'مدیر')) {
            tgSend($uid, "⏳ پرداخت شما ثبت شد و در انتظار تأیید مدیر است.\nبه‌محض تأیید، اشتراک فعال می‌شود.");
        } elseif (str_contains($msg, 'معلق') || str_contains($msg, 'ثبت نشده') || str_contains($msg, 'انجام')) {
            tgSend($uid, "⏳ پرداخت شما هنوز از سمت درگاه تأیید نشده است.\n"
                . "اگر مطمئنید پرداخت انجام شده، از «🛒 اشتراک ویژه» گزینهٔ «🔄 بررسی پرداخت» را بزنید.");
        }
    }
}

if ($done) {
    payPage('پرداخت موفق', "اشتراک ویژهٔ شما فعال شد.\nنتیجه در چت ربات هم به شما اطلاع داده شد.", $botLink);
} else {
    payPage('در انتظار تأیید', $msg !== '' ? $msg : 'وضعیت پرداخت هنوز نهایی نشده است.', $botLink);
}
