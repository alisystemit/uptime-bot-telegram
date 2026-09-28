<?php
/**
 * ===== صفحهٔ وضعیت عمومی (قابل اشتراک‌گذاری) =====
 *
 * آدرس‌ها:
 *   https://domain/.../status.php?u=<share_token>   همهٔ مانیتورهای یک کاربر
 *   https://domain/.../status.php?g=<share_token>   مانیتورهای یک گروه/کانال
 *   https://domain/.../status.php?s=<share_token>   فقط یک مانیتور
 *   + &csv=1  → خروجی CSV (آپتایم ساعتی ۳۰ روز اخیر)
 *
 * بدون نیاز به لاگین؛ هیچ اطلاعات خصوصی نشان داده نمی‌شود و داده از api.php
 * به‌صورت دوره‌ای تازه می‌شود (بدون شارژ مجدد کامل صفحه).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require_once __DIR__ . '/lib/bootstrap.php';

$cfg = appConfig();
$tokU = trim((string)($_GET['u'] ?? ''));
$tokG = trim((string)($_GET['g'] ?? ''));
$tokS = trim((string)($_GET['s'] ?? ''));
$tz   = tzOffset();
$base = rtrim((string)($cfg['base_url'] ?? ''), '/');
if ($base === '' && !empty($cfg['domain'])) $base = 'https://' . $cfg['domain'];

$fail = static function (string $title, string $msg, int $code = 500) {
    http_response_code($code);
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:radial-gradient(1200px 600px at 50% -10%,#123 0%,#070b14 60%);color:#e6edf7;font-family:Vazirmatn,Segoe UI,Tahoma,sans-serif}'
        . '.c{background:#0f1626;border:1px solid #1e2a44;border-radius:18px;padding:34px;max-width:520px;text-align:center}'
        . 'h1{font-size:22px;margin:0 0 10px}p{color:#93a4c3;line-height:1.9;margin:0}</style></head>'
        . '<body><div class="c"><h1>' . $t . '</h1><p>' . $m . '</p></div></body></html>';
    exit;
};

if ($tokU === '' && $tokG === '' && $tokS === '') {
    $fail('📊 صفحهٔ وضعیت', 'لینک نامعتبر است. آدرس صفحهٔ وضعیت را از داخل ربات بگیرید (منوی «🔗 صفحهٔ وضعیت من»).', 404);
}

try {
    appBoot();
} catch (Throwable $e) {
    uptimeLog('error', 'status boot failed: ' . $e->getMessage());
    $fail('اتصال برقرار نشد', 'در حال حاضر امکان نمایش وضعیت نیست. لطفاً چند لحظهٔ دیگر دوباره تلاش کنید.', 503);
}

$ctx = Page::resolve($tokU, $tokG, $tokS);
if (!$ctx) {
    $fail('لینک پیدا نشد', 'این لینک دیگر معتبر نیست. از داخل ربات یک لینک تازه بسازید.', 404);
}

$sites  = $ctx['sites'];
$tz     = tzOffset();
$summary = Stats::summaryOf($sites);
$engine = Stats::engine();
$scopeQ = $ctx['kind'] === Page::KIND_SITE ? 's=' . rawurlencode($tokS)
    : ($ctx['kind'] === Page::KIND_GROUP ? 'g=' . rawurlencode($tokG) : 'u=' . rawurlencode($tokU));

// ------------------------------------------------------------------ خروجی CSV
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uptime-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM برای اکسل
    $out = fopen('php://output', 'w');
    fputcsv($out, ['سایت', 'هدف', 'تاریخ', 'ساعت', 'تعداد چک', 'موفق', 'آپتایم٪', 'میانگین پاسخ (ms)']);
    foreach ($sites as $s) {
        foreach (Stats::hourly($s, 24 * 30) as $h) {
            if ($h['checks'] <= 0) continue;
            fputcsv($out, [
                $s['label'] !== '' ? $s['label'] : $s['target'],
                $s['target'],
                date('Y-m-d', time() + (int)round($tz * 3600) - 0), // تاریخ همان ساعت است
                $h['hour'],
                $h['checks'],
                (int)round($h['pct'] / 100 * $h['checks']),
                $h['pct'],
                $h['avg_ms'],
            ]);
        }
    }
    fclose($out);
    exit;
}

// ------------------------------------------------------------------ کمکی‌های نمایش

/** رنگ درصد آپتایم */
function pctCls(?float $p): string
{
    if ($p === null) return 'na';
    return $p >= 99.5 ? 'great' : ($p >= 95 ? 'good' : ($p >= 80 ? 'warn' : 'bad'));
}

function upBox(array $site, int $days, string $label, float $tz): string
{
    $u = Stats::uptime($site, $days);
    return '<div class="up ' . pctCls($u['pct']) . '">'
        . '<b>' . faPct($u['pct']) . '</b>'
        . '<span>' . $label . '</span>'
        . '<i>' . ($u['checks'] > 0 ? faNum($u['checks']) . ' چک' : 'بدون داده') . '</i>'
        . '</div>';
}

/** نمودار میله‌ای ۳۰ روز اخیر */
function dailyChart(array $site): string
{
    $rows = Stats::daily($site, 30);
    $bars = '';
    foreach ($rows as $d) {
        $cls = pctCls($d['pct']);
        $title = faDay($d['date'] . ' 00:00:00', tzOffset()) . ' — '
            . ($d['pct'] === null ? 'بدون داده' : faPct($d['pct']) . ' (' . faNum($d['checks']) . ' چک)');
        $h = $d['pct'] === null ? 6 : max(8, (int)round($d['pct'] * 0.42) + 8);
        $bars .= '<i class="' . $cls . '" style="height:' . $h . 'px" title="' . h($title) . '"></i>';
    }
    return '<div class="chart"><div class="dlabel">آپتایم ۳۰ روز اخیر</div><div class="dbars">' . $bars . '</div>'
        . '<div class="daxis"><span>' . faDay($rows[0]['date'] . ' 00:00:00', tzOffset()) . '</span>'
        . '<span>امروز</span></div></div>';
}

/** نوار ۲۴ ساعت اخیر */
function heatStrip(array $site): string
{
    $rows = Stats::hourly($site, 24);
    $bars = '';
    foreach ($rows as $d) {
        $cls = pctCls($d['pct']);
        $title = faNum((string)$d['hour']) . ':۰۰ — ' . ($d['pct'] === null ? 'بدون داده' : faPct($d['pct']))
            . ' • میانگین پاسخ ' . faMs((int)$d['avg_ms']);
        $bars .= '<i class="' . $cls . '" title="' . h($title) . '"></i>';
    }
    return '<div class="chart"><div class="dlabel">۲۴ ساعت اخیر (هر ستون یک ساعت)</div>'
        . '<div class="hbars">' . $bars . '</div>'
        . '<div class="daxis"><span>۰۰</span><span>۰۶</span><span>۱۲</span><span>۱۸</span><span>۲۳</span></div></div>';
}

function incidentList(array $site): string
{
    $rows = Stats::incidents((int)$site['id'], 4);
    if (!$rows) return '<div class="sec"><div class="dlabel">📜 رخدادهای اخیر</div><div class="norc">رخدادی ثبت نشده — عالی است 🎉</div></div>';
    $out = '<div class="sec"><div class="dlabel">📜 رخدادهای اخیر</div><ul class="inc">';
    foreach ($rows as $i) {
        $open = empty($i['end_at']);
        $dur = $open ? max(0, time() - (int)strtotime((string)$i['start_at'])) : (int)$i['duration'];
        $out .= '<li class="' . (string)$i['kind'] . '">'
            . '<span class="badge2">' . ((string)$i['kind'] === 'down' ? 'قطعی' : 'کندی') . '</span>'
            . '<span class="dur">' . faDuration($dur) . ($open ? ' (ادامه دارد)' : '') . '</span>'
            . '<span class="when">' . faDateTime((string)$i['start_at'], tzOffset()) . ' — ' . timeAgo($i['start_at'], tzOffset()) . '</span>'
            . (trim((string)$i['reason']) !== '' ? '<span class="why">' . h((string)$i['reason']) . '</span>' : '')
            . '</li>';
    }
    return $out . '</ul></div>';
}

function sslBlock(array $s): string
{
    if (stripos((string)$s['target'], 'https://') !== 0) return '';
    if ((string)$s['ssl_check_at'] === '') {
        return '<div class="sec"><div class="dlabel">🔐 گواهی SSL</div><div class="norc">هنوز بررسی نشده است</div></div>';
    }
    $d = (int)$s['ssl_days'];
    $cls = $d <= 0 ? 'bad' : ($d <= (int)$s['ssl_warn_days'] ? 'warn' : 'great');
    $out = '<div class="sec"><div class="dlabel">🔐 گواهی SSL</div>'
        . '<div class="up ' . $cls . '"><b>' . ($d < 0 ? '—' : faLeft($d * 86400)) . '</b><span>تا انقضا</span>'
        . '<i>' . faDay($s['ssl_expires_at'], tzOffset()) . '</i></div>';
    if (trim((string)$s['ssl_issuer']) !== '') $out .= '<div class="okline">🏛 ' . h((string)$s['ssl_issuer']) . '</div>';
    if (trim((string)$s['ssl_error']) !== '') $out .= '<div class="errline">⚠️ ' . h((string)$s['ssl_error']) . '</div>';
    $out .= '<div class="meta"><span>آخرین بررسی: <b>' . timeAgo($s['ssl_check_at'], tzOffset()) . '</b></span></div></div>';
    return $out;
}

function siteCard(array $s, float $tz): string
{
    $state = siteState($s);
    $st = $state === 'paused' ? 'paused' : (string)$s['status'];
    $emoji = ['up' => '🟢', 'down' => '🔴', 'slow' => '🟠', 'paused' => '⏸', 'unknown' => '🟡'][$state] ?? '⚪️';
    $name = ['up' => 'فعال', 'down' => 'قطع', 'slow' => 'کند', 'paused' => 'متوقف', 'unknown' => 'نامشخص'][$state] ?? $state;

    $recent = Stats::recent($s, 60);
    $cnt = Stats::counters($s);

    $note = '';
    $err = trim((string)$s['last_error']);
    if ($st === 'down' && $err !== '') {
        $note = '<div class="errline">⚠️ ' . h($err) . '</div>';
    } elseif ($state === 'slow') {
        $note = '<div class="slowline">🟠 پاسخ کند: ' . faMs((int)$s['last_ms']) . ' (حد مجاز ' . faMs((int)$s['max_ms']) . ')</div>';
    } elseif ($st === 'up' && (int)$s['last_code'] > 0) {
        $note = '<div class="okline">کد پاسخ: ' . faNum((int)$s['last_code']) . ' • زمان پاسخ: ' . faMs((int)$s['last_ms']) . '</div>';
    } elseif ($st === 'paused') {
        $note = '<div class="pauseline">این سایت موقتاً از چک خارج شده است.</div>';
    }

    $thresholds = [];
    if ((int)$s['max_ms'] > 0) $thresholds[] = '🎯 حد کندی: ' . faMs((int)$s['max_ms']);
    if (trim((string)$s['keyword']) !== '') $thresholds[] = '🔎 کلیدواژه: ' . truncateFa((string)$s['keyword'], 40);
    $thresholdsHtml = $thresholds ? '<div class="meta"><span>' . implode('</span><span>', array_map('h', $thresholds)) . '</span></div>' : '';

    $since = '';
    if ((int)$s['last_down_duration'] > 0 && $state !== 'down') {
        $since = '<span>آخرین توقف: ' . faDuration((int)$s['last_down_duration']) . '</span>';
    }

    $perf = '';
    if ((int)$s['resp_avg'] > 0) {
        $perf = '<div class="perf">'
            . '<div><b>' . faMs((int)Db::val('SELECT MIN(`ms`) FROM `check_log` WHERE `site_id` = ? AND `ok` = 1 AND `ms` > 0 AND `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)', [(int)$s['id']]) ?: 0) . '</b><span>کمینه</span></div>'
            . '<div><b>' . faMs((int)$s['resp_avg']) . '</b><span>میانگین</span></div>'
            . '<div><b>' . faMs((int)$s['resp_p95']) . '</b><span>صدک ۹۵</span></div>'
            . '<div><b>' . faMs((int)$s['resp_max']) . '</b><span>بیشینه</span></div>'
            . '</div>';
    }

    return '<article class="card ' . stateClass($state) . '" data-site="' . (int)$s['id'] . '">'
        . '<header>'
        . '<div class="ttl"><span class="dot">' . $emoji . '</span>'
        . '<div><h2>' . h($s['label'] !== '' ? (string)$s['label'] : (string)$s['target']) . '</h2>'
        . '<p class="target">' . h((string)$s['target']) . ' <em>' . typeName((string)$s['type']) . '</em></p></div></div>'
        . '<div class="badge">' . h($name) . '</div>'
        . '</header>'

        . '<div class="stats">'
        . upBox($s, 1, '۲۴ ساعت', $tz)
        . upBox($s, 7, '۷ روز', $tz)
        . upBox($s, 30, '۳۰ روز', $tz)
        . '</div>'

        . dailyChart($s)
        . heatStrip($s)
        . $perf

        . '<div class="hist">' . Stats::barHtml($recent) . '</div>'
        . '<div class="meta">'
        . '<span>آخرین چک: <b>' . timeAgo($s['last_check_at'], $tz) . '</b></span>'
        . $since
        . '<span>کل چک‌ها: <b>' . faNum((int)$cnt['checks']) . '</b></span>'
        . '<span>ناموفق: <b>' . faNum((int)$cnt['fails']) . '</b></span>'
        . '</div>'
        . $thresholdsHtml
        . $note
        . sslBlock($s)
        . incidentList($s)
        . '</article>';
}

$htmlCards = '';
foreach ($sites as $s) $htmlCards .= siteCard($s, $tz);

$empty = $sites ? '' : '<div class="card empty"><b>هنوز سایتی ثبت نشده است.</b><p>از داخل ربات می‌توانید سایت اضافه کنید.</p></div>';

$kindLabel = [
    Page::KIND_USER  => 'مانیتورینگ شخصی',
    Page::KIND_GROUP => 'مانیتورینگ گروه',
    Page::KIND_SITE  => 'مانیتور یک سایت',
][$ctx['kind']] ?? 'مانیتورینگ';

$pageTitle = $ctx['kind'] === Page::KIND_SITE
    ? $ctx['title']
    : ($ctx['title'] . ' — وضعیت سرورها');
$owner = $ctx['owner'];

$avgUp = $summary['uptime24'];
$engineOk = (bool)$engine['cron_healthy'];
$engineTxt = $engine['paused'] ? 'چک‌ها متوقف است' : ($engineOk ? 'در حال چک خودکار' : 'چک خودکار هنوز شروع نشده');
$updated = date('Y/m/d H:i:s', time() + (int)round($tz * 3600));
$tpl = [];
if ($ctx['kind'] === Page::KIND_USER) $tpl = Stats::incidentTotals((int)($ctx['extra']['user_id'] ?? 0), 0);
elseif ($ctx['kind'] === Page::KIND_GROUP) $tpl = Stats::incidentTotals(0, (int)($ctx['extra']['chat_id'] ?? 0));
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= h($pageTitle) ?></title>
<meta name="description" content="وضعیت لحظه‌ای سرورها — آپتایم، زمان پاسخ و تاریخچهٔ قطعی">
<style>
:root{
  --bg:#070b14; --panel:#0e1626; --panel2:#111c31; --line:#1e2b47;
  --txt:#e7eefc; --muted:#8ea1c4; --dim:#64769b;
  --ok:#22c55e; --bad:#ef4444; --warn:#f59e0b; --slow:#f97316; --idle:#64748b;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  background:
    radial-gradient(1100px 520px at 50% -12%, #173066 0%, rgba(10,16,30,0) 62%),
    var(--bg);
  color:var(--txt);
  font-family:Vazirmatn,"Segoe UI",Tahoma,system-ui,sans-serif;
  -webkit-font-smoothing:antialiased;
  min-height:100vh;
}
a{color:#7dd3fc;text-decoration:none}
.wrap{max-width:960px;margin:0 auto;padding:26px 16px 60px}

/* ===== هدر ===== */
.hero{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;
  background:linear-gradient(180deg,rgba(30,45,80,.55),rgba(14,22,38,.9));
  border:1px solid var(--line);border-radius:20px;padding:20px 22px;box-shadow:0 18px 40px -28px #000}
.brand{display:flex;align-items:center;gap:14px;min-width:0}
.logo{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-size:22px;flex:none;
  background:linear-gradient(140deg,#1d4ed8,#0ea5e9);box-shadow:0 8px 22px -10px #0ea5e9}
.brand h1{margin:0;font-size:19px;letter-spacing:-.2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:52vw}
.brand p{margin:4px 0 0;color:var(--muted);font-size:13px}
.pill{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;
  background:#0b1324;border:1px solid var(--line);font-size:13px;color:var(--muted);white-space:nowrap}
.pill i{width:9px;height:9px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 4px rgba(34,197,94,.15);animation:pulse 2s infinite}
.pill.warn i{background:var(--warn);box-shadow:0 0 0 4px rgba(245,158,11,.15)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.45}}

/* ===== نوار خلاصه ===== */
.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:18px 0 22px}
.sbox{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:14px 16px}
.sbox b{display:block;font-size:22px;font-weight:700;letter-spacing:-.5px}
.sbox span{display:block;color:var(--dim);font-size:12px;margin-top:5px}
.sbox.g b{color:var(--ok)} .sbox.r b{color:var(--bad)} .sbox.y b{color:var(--warn)} .sbox.o b{color:var(--slow)}

/* ===== کارت سایت ===== */
.card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:18px;margin-bottom:14px;
  transition:border-color .25s, transform .25s}
.card:hover{border-color:#2c3f68;transform:translateY(-1px)}
.card.down{border-color:rgba(239,68,68,.45);box-shadow:0 0 0 1px rgba(239,68,68,.12) inset}
.card.slow{border-color:rgba(249,115,22,.4)}
.card.paused{opacity:.72}
.card header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.ttl{display:flex;gap:12px;align-items:center;min-width:0}
.dot{font-size:22px;line-height:1}
.ttl h2{margin:0;font-size:16.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:46vw}
.target{margin:4px 0 0;color:var(--dim);font-size:12.5px;direction:ltr;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:52vw}
.target em{font-style:normal;color:#5b6d93;margin-inline-start:6px}
.badge{font-size:12px;padding:6px 12px;border-radius:999px;border:1px solid var(--line);background:#0b1324;white-space:nowrap}
.card.up .badge{color:#7ee2a8;border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.08)}
.card.down .badge{color:#fca5a5;border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.09)}
.card.slow .badge{color:#fdba74;border-color:rgba(249,115,22,.4);background:rgba(249,115,22,.09)}
.card.paused .badge{color:#fcd34d;border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08)}

.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:16px}
.up{background:var(--panel2);border:1px solid var(--line);border-radius:14px;padding:12px 14px;text-align:center}
.up b{display:block;font-size:20px;font-weight:800;letter-spacing:-.5px}
.up span{display:block;font-size:11.5px;color:var(--dim);margin-top:4px}
.up i{display:block;font-style:normal;font-size:11px;color:#4f608a;margin-top:3px}
.up.great b{color:var(--ok)} .up.good b{color:#86efac}
.up.warn b{color:var(--warn)} .up.bad b{color:var(--bad)} .up.na b{color:#475569}

/* ===== نمودارها ===== */
.chart{margin-top:16px;background:#0a1120;border:1px solid #17233c;border-radius:14px;padding:12px 14px}
.dlabel{font-size:12px;color:var(--dim);margin-bottom:10px}
.dbars{display:flex;align-items:flex-end;gap:3px;height:64px}
.dbars i{flex:1 1 0;min-width:3px;border-radius:3px 3px 0 0;background:#1e293b}
.dbars i.great{background:linear-gradient(180deg,#34d399,#059669)}
.dbars i.good{background:linear-gradient(180deg,#86efac,#16a34a)}
.dbars i.warn{background:linear-gradient(180deg,#fbbf24,#d97706)}
.dbars i.bad{background:linear-gradient(180deg,#f87171,#dc2626)}
.dbars i.na{background:#1e293b}
.hbars{display:flex;gap:3px;height:34px;direction:ltr}
.hbars i{flex:1 1 0;min-width:3px;border-radius:3px;background:#1e293b}
.hbars i.great{background:linear-gradient(180deg,#34d399,#059669)}
.hbars i.good{background:linear-gradient(180deg,#86efac,#16a34a)}
.hbars i.warn{background:linear-gradient(180deg,#fbbf24,#d97706)}
.hbars i.bad{background:linear-gradient(180deg,#f87171,#dc2626)}
.daxis{display:flex;justify-content:space-between;font-size:10.5px;color:#4f608a;margin-top:6px;direction:ltr}
.chart .daxis span:last-child{color:#5b6d93}

.perf{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px}
.perf div{background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:9px 10px;text-align:center}
.perf b{display:block;font-size:15px;font-weight:700;color:#c7d5f0}
.perf span{display:block;font-size:11px;color:var(--dim);margin-top:3px}

.hist{margin-top:14px;background:#0a1120;border:1px solid #17233c;border-radius:12px;padding:10px 12px;overflow:hidden}
.bar{display:flex;gap:2px;height:22px;align-items:stretch;direction:ltr}
.bar i{flex:1 1 0;min-width:3px;border-radius:2px;background:#1e293b}
.bar i.ok{background:linear-gradient(180deg,#34d399,#059669)}
.bar i.bad{background:linear-gradient(180deg,#f87171,#dc2626)}
.bar span.muted{color:var(--dim);font-size:12px;direction:rtl}

.meta{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:12px;font-size:12.5px;color:var(--muted)}
.meta b{color:#c7d5f0;font-weight:600}
.errline{margin-top:12px;font-size:13px;color:#fca5a5;background:rgba(239,68,68,.08);
  border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:9px 12px;direction:ltr;text-align:right}
.slowline{margin-top:12px;font-size:13px;color:#fdba74;background:rgba(249,115,22,.08);
  border:1px solid rgba(249,115,22,.3);border-radius:10px;padding:9px 12px}
.okline{margin-top:12px;font-size:12.5px;color:#93c5fd;direction:ltr;text-align:right}
.pauseline{margin-top:12px;font-size:12.5px;color:#fcd34d}
.empty{text-align:center;color:var(--muted);padding:34px 20px}
.empty b{color:var(--txt);font-size:16px}

/* ===== رخدادها ===== */
.sec{margin-top:14px;background:#0a1120;border:1px solid #17233c;border-radius:14px;padding:12px 14px}
.norc{color:var(--dim);font-size:12.5px}
ul.inc{list-style:none;margin:0;padding:0}
ul.inc li{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;padding:8px 0;border-top:1px solid #16223a;font-size:12.5px}
ul.inc li:first-child{border-top:0}
.badge2{font-size:11px;padding:3px 9px;border-radius:999px;background:rgba(239,68,68,.14);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
ul.inc li.slow .badge2{background:rgba(249,115,22,.14);color:#fdba74;border-color:rgba(249,115,22,.3)}
ul.inc .dur{color:#c7d5f0;font-weight:600}
ul.inc .when{color:var(--dim)}
ul.inc .why{width:100%;color:#8ea1c4;direction:ltr;text-align:right;font-size:12px}

/* ===== فوتر ===== */
.foot{margin-top:26px;padding-top:16px;border-top:1px solid var(--line);color:var(--dim);
  font-size:12.5px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.foot .live{display:inline-flex;align-items:center;gap:7px}
.foot .live i{width:7px;height:7px;border-radius:50%;background:#22c55e;animation:pulse 1.6s infinite}

@media (max-width:680px){
  .summary{grid-template-columns:repeat(2,1fr)}
  .ttl h2,.target{max-width:56vw}
  .up b{font-size:17px}
  .perf{grid-template-columns:repeat(2,1fr)}
  .brand h1{max-width:64vw}
}
</style>
</head>
<body>
<div class="wrap">

  <div class="hero">
    <div class="brand">
      <div class="logo">📊</div>
      <div>
        <h1><?= h($pageTitle) ?></h1>
        <p><?= h($owner) ?> • <?= h($kindLabel) ?></p>
      </div>
    </div>
    <div class="pill <?= $engine['paused'] ? 'warn' : ($engineOk ? '' : 'warn') ?>" id="enginePill">
      <i></i><span id="engineTxt"><?= h($engineTxt) ?></span>
    </div>
  </div>

  <div class="summary">
    <div class="sbox"><b id="sTotal"><?= faNum($summary['total']) ?></b><span>کل مانیتورها</span></div>
    <div class="sbox g"><b id="sUp"><?= faNum($summary['up']) ?></b><span>فعال 🟢</span></div>
    <div class="sbox r"><b id="sDown"><?= faNum($summary['down']) ?></b><span>قطع 🔴</span></div>
    <div class="sbox o"><b id="sSlow"><?= faNum($summary['slow']) ?></b><span>کند 🟠</span></div>
    <div class="sbox y"><b id="sAvg"><?= faPct($avgUp) ?></b><span>آپتایم میانگین ۲۴س</span></div>
  </div>

  <?php if ($tpl && (int)$tpl['count'] > 0) { ?>
  <div class="chart" style="margin-bottom:16px">
    <div class="dlabel">📊 خلاصهٔ رخدادهای نگهداری‌شده</div>
    <div class="perf">
      <div><b><?= faNum((int)$tpl['count']) ?></b><span>تعداد رخداد</span></div>
      <div><b><?= faDuration((int)$tpl['down']) ?></b><span>مجموع قطعی</span></div>
      <div><b><?= faDuration((int)$tpl['slow']) ?></b><span>مجموع کندی</span></div>
      <div><b><?= faPct($summary['uptime24']) ?></b><span>آپتایم ۲۴س</span></div>
    </div>
  </div>
  <?php } ?>

  <div id="cards">
    <?= $htmlCards ?>
    <?= $empty ?>
  </div>

  <div class="foot">
    <span class="live"><i></i> به‌روزرسانی خودکار هر ۲۵ ثانیه</span>
    <span>آخرین به‌روزرسانی: <b id="updated"><?= h($updated) ?></b></span>
    <a href="<?= h($base . '/status.php?' . $scopeQ . '&csv=1') ?>">⬇️ خروجی CSV</a>
    <span>قدرت‌گرفته از ربات مانیتورینگ 🤖</span>
  </div>

</div>

<script>
(function () {
  var SCOPE = <?= json_encode($scopeQ, JSON_UNESCAPED_SLASHES) ?>;
  // آدرس نسبی: اگر صفحه با IP یا دامنهٔ دیگری باز شود هم به‌روزرسانی زنده کار کند
  // و مشکل CORS پیش نیاید.
  var API   = 'api.php';
  var TICK  = 25000;      // هر ۲۵ ثانیه داده تازه می‌شود
  var RELOAD_EVERY = 6;   // هر ۲٫۵ دقیقه شارژ کامل (نمودارها و مانیتورهای تازه)
  var n = 0;

  function fa(s) {
    return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
  }

  setInterval(function () {
    n++;
    if (n % RELOAD_EVERY === 0) { location.reload(); return; }
    fetch(API + '?' + SCOPE + '&_=' + Date.now(), { credentials: 'omit' })
      .then(function (r) { if (!r.ok) throw new Error('http'); return r.json(); })
      .then(function (d) { if (d && d.ok) paint(d); else throw new Error('bad'); })
      .catch(function () { /* یک تلاش دیگر در تیک بعدی */ });
  }, TICK);

  function paint(d) {
    var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
    set('sTotal', fa(d.summary.total));
    set('sUp',    fa(d.summary.up));
    set('sDown',  fa(d.summary.down));
    set('sSlow',  fa(d.summary.slow || 0));
    set('sAvg',   d.summary.avg == null ? '—' : fa(d.summary.avg) + '٪');
    set('updated', d.updated);

    var pill = document.getElementById('enginePill');
    if (pill) {
      pill.className = 'pill' + (d.engine.paused ? ' warn' : (d.engine.cron_healthy ? '' : ' warn'));
      set('engineTxt', d.engine.paused ? 'چک‌ها متوقف است' : (d.engine.cron_healthy ? 'در حال چک خودکار' : 'چک خودکار هنوز شروع نشده'));
    }

    d.sites.forEach(function (s) {
      var card = document.querySelector('.card[data-site="' + s.id + '"]');
      if (!card) return;
      card.className = 'card ' + s.cls;
      var badge = card.querySelector('.badge');
      if (badge) badge.textContent = s.status_label;
      var ups = card.querySelectorAll('.stats .up');
      [s.u1, s.u7, s.u30].forEach(function (u, i) {
        if (!ups[i]) return;
        ups[i].className = 'up ' + u.cls;
        ups[i].querySelector('b').textContent = u.pct == null ? '—' : fa(u.pct) + '٪';
        var it = ups[i].querySelector('i');
        if (it) it.textContent = u.checks > 0 ? fa(u.checks) + ' چک' : 'بدون داده';
      });
      var meta = card.querySelectorAll('.meta span b');
      if (meta[0]) meta[0].textContent = s.last_check_ago;
      if (meta[1]) meta[1].textContent = fa(s.total_checks);
      if (meta[2]) meta[2].textContent = fa(s.total_fails);

      var hist = card.querySelector('.hist');
      if (hist && s.bar_html) hist.innerHTML = s.bar_html;

      var line = card.querySelector('.errline, .okline, .pauseline, .slowline');
      if (line && s.note_html) line.outerHTML = s.note_html;
      else if (!line && s.note_html) card.insertAdjacentHTML('beforeend', s.note_html);
    });
  }
})();
</script>
</body>
</html>
