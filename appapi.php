<?php
/**
 * ===== API مینی‌اپ تلگرام (JSON) =====
 *
 * همهٔ درخواست‌ها باید initData معتبر تلگرام داشته باشند:
 *   - هدر:   X-Telegram-Init-Data
 *   - یا فیلد POST/GET: initData
 *
 * نقطه‌های ورود (GET):
 *   appapi.php?a=overview        داشبورد (کاربر + خلاصه + موتور + سایت‌ها)
 *   appapi.php?a=site&id=N       جزئیات کامل یک مانیتور
 *   appapi.php?a=incidents       آخرین رخدادها
 *   appapi.php?a=domains         دامنه‌های پایش‌شده
 *   appapi.php?a=rank            رنکینگ و جدول برترین‌ها
 *   appapi.php?a=report          گزارش تلفیقی آپ‌تایم
 *   appapi.php?a=settings        تنظیمات و حساب کاربر
 *   appapi.php?a=shared          مانیتورهای مشترک با کاربر
 *
 * عملیات (POST):
 *   appapi.php?a=check&id=N      چک فوری
 *   appapi.php?a=pause&id=N      توقف موقت
 *   appapi.php?a=resume&id=N     ادامه
 *   appapi.php?a=delete&id=N     حذف مانیتور
 *   appapi.php?a=add             افزودن مانیتور (فیلد target)
 *   appapi.php?a=settings        تغییر تنظیم (key: notify|pause ، value: 0|1)
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/lib/bootstrap.php';

$respond = static function (int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

// ---------- دیتابیس ----------
try {
    appBoot();
} catch (Throwable $e) {
    uptimeLog('error', 'appapi boot failed: ' . $e->getMessage());
    $respond(503, ['ok' => false, 'error' => 'database']);
}

// ---------- احراز هویت initData ----------
// کلاینت initData را در هر سه مسیر می‌فرستد (هدر، query string، POST body)
// تا اگر پروکسی یا WAF هدرهای سفارشی را حذف کرد، درخواست از کار نیفتد.
// هرکدام که معتبر باشد کافی است — اولویت با هدر است چون کم‌هزینه‌تر است.
$initDataCandidates = [
    'header' => (string)($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? ''),
    'get'    => (string)($_GET['initData'] ?? ''),
    'post'   => (string)($_POST['initData'] ?? ''),
];

$initData = '';
$initDataSource = '';
$tgUser = null;
foreach ($initDataCandidates as $src => $candidate) {
    if ($candidate === '') continue;
    $u = MiniApp::validateInitData($candidate, botToken());
    if ($u) {
        $initData = $candidate;
        $initDataSource = $src;
        $tgUser = $u;
        break;
    }
    // اولین نامزد نامعتبر بود؛ نامزدهای دیگر را هم امتحان می‌کنیم
    if ($initData === '') $initData = $candidate;
}

if (!$tgUser) {
    // لاگ مختصر برای تشخیص از راه دور — initData خودش لاگ نمی‌شود (حجیم و حساس)
    uptimeLog('appapi_auth_fail', 'no valid initData; present: '
        . implode(',', array_keys(array_filter($initDataCandidates, 'strlen')))
        . '; len=' . strlen($initData));
    $respond(401, ['ok' => false, 'error' => 'auth']);
}

$user = MiniApp::ensureUser($tgUser);
if (!$user) {
    $respond(403, ['ok' => false, 'error' => 'capacity']);
}
if ((int)($user['is_blocked'] ?? 0) === 1) {
    $respond(403, ['ok' => false, 'error' => 'blocked']);
}

$action = trim((string)($_GET['a'] ?? 'overview'));
// id هم از query string و هم از بدنهٔ POST خوانده می‌شود تا در هر دو حالت
// (کلاینت‌های قدیمی id را در body می‌فرستند) درست کار کند.
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// عملیات نوشتنی فقط با POST و فقط برای کاربرانِ دارای دسترسی
$writes = ['check', 'pause', 'resume', 'delete', 'add'];
if (in_array($action, $writes, true)) {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        $respond(405, ['ok' => false, 'error' => 'method']);
    }
    if (!MiniApp::allowed($user)) {
        $respond(403, ['ok' => false, 'error' => 'access']);
    }
}

try {
    switch ($action) {
        case 'overview':
            $out = MiniApp::overview($user);
            break;

        case 'site':
            $out = MiniApp::siteDetail($user, $id);
            if (!$out) $respond(404, ['ok' => false, 'error' => 'not_found']);
            break;

        case 'incidents':
            $out = MiniApp::incidents($user);
            break;

        case 'domains':
            $out = MiniApp::domains($user);
            break;

        case 'rank':
            $out = MiniApp::rank($user);
            break;

        case 'report':
            $out = MiniApp::report($user);
            break;

        case 'shared':
            $out = MiniApp::shared($user);
            break;

        case 'settings':
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
                if (!MiniApp::allowed($user)) {
                    $respond(403, ['ok' => false, 'error' => 'access']);
                }
                $key = trim((string)($_POST['key'] ?? ''));
                $val = trim((string)($_POST['value'] ?? ''));
                $out = MiniApp::setSetting($user, $key, $val);
                if (empty($out['ok'])) $respond(422, ['ok' => false, 'error' => (string)($out['error'] ?? 'failed')]);
            } else {
                $out = MiniApp::settings($user);
            }
            break;

        case 'check':
            $out = MiniApp::checkNow($user, $id);
            if (empty($out['ok'])) $respond(404, ['ok' => false, 'error' => (string)($out['error'] ?? 'failed')]);
            break;

        case 'pause':
            $out = MiniApp::setPaused($user, $id, true);
            if (empty($out['ok'])) $respond(404, ['ok' => false, 'error' => (string)($out['error'] ?? 'failed')]);
            break;

        case 'resume':
            $out = MiniApp::setPaused($user, $id, false);
            if (empty($out['ok'])) $respond(404, ['ok' => false, 'error' => (string)($out['error'] ?? 'failed')]);
            break;

        case 'delete':
            $out = MiniApp::deleteSite($user, $id);
            if (empty($out['ok'])) $respond(404, ['ok' => false, 'error' => (string)($out['error'] ?? 'failed')]);
            break;

        case 'add':
            $target = (string)($_POST['target'] ?? '');
            $out = MiniApp::addSite($user, $target);
            if (empty($out['ok'])) $respond(422, ['ok' => false, 'error' => (string)($out['error'] ?? 'failed')]);
            break;

        default:
            $respond(404, ['ok' => false, 'error' => 'unknown_action']);
    }
} catch (Throwable $e) {
    uptimeLog('error', 'appapi failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $respond(500, ['ok' => false, 'error' => 'internal']);
}

$respond(200, ['ok' => true, 'tz' => tzOffset(), 'now' => time(), 'data' => $out]);
