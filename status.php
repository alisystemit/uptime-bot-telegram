<?php
/**
 * ===== صفحهٔ وضعیت عمومی (قابل اشتراک‌گذاری) =====
 *
 * آدرس:  https://domain/bots/<slug>/status.php?u=<share_token>
 * توکن را ربات در منوی «🔗 صفحهٔ وضعیت من» نشان می‌دهد.
 *
 * بدون نیاز به لاگین؛ فقط وضعیت سایت‌های همان کاربر را نشان می‌دهد.
 * داده از api.php به‌صورت دوره‌ای تازه می‌شود (بدون شارژ مجدد کامل صفحه).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require_once __DIR__ . '/lib/bootstrap.php';

$cfg   = appConfig();
$token = trim((string)($_GET['u'] ?? ''));

$tz    = tzOffset();
$base  = rtrim((string)($cfg['base_url'] ?? ''), '/');
if ($base === '' && !empty($cfg['domain'])) $base = 'https://' . $cfg['domain'];
$self  = $base . '/status.php';

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

if ($token === '') {
    $fail('📊 صفحهٔ وضعیت', 'لینک نامعتبر است. توکن صفحهٔ وضعیت خود را از داخل ربات بگیرید (منوی «🔗 صفحهٔ وضعیت من»).', 404);
}

try {
    appBoot();
} catch (Throwable $e) {
    uptimeLog('error', 'status boot failed: ' . $e->getMessage());
    $fail('اتصال برقرار نشد', 'در حال حاضر امکان نمایش وضعیت نیست. لطفاً چند لحظه دیگر دوباره تلاش کنید.', 503);
}

$user = Db::one('SELECT `id`,`name`,`username`,`created_at` FROM `user` WHERE `share_token` = ?', [$token]);
if (!$user) {
    $fail('لینک پیدا نشد', 'این لینک دیگر معتبر نیست. از داخل ربات یک لینک تازه بسازید.', 404);
}

$uid = (int)$user['id'];

try {
    $summary = Stats::userSummary($uid);
    $engine  = Stats::engine();
} catch (Throwable $e) {
    $fail('خطای دیتابیس', 'خواندن اطلاعات ممکن نشد. ' . $e->getMessage(), 503);
}

$sites = $summary['sites'];
$pageTitle = trim(($user['name'] !== '' ? (string)$user['name'] : 'کاربر')) . ' — وضعیت سرورها';
$owner = $user['username'] ? '@' . $user['username'] : 'ربات مانیتورینگ';

/** نوار ۷ روزهٔ اسپارک‌لاین + آپتایم هر بازه */
function upBox(array $site, int $days, string $label, float $tz): string
{
    $u = Stats::uptime($site, $days);
    $cls = $u['pct'] === null ? 'na' : ($u['pct'] >= 99.5 ? 'great' : ($u['pct'] >= 95 ? 'good' : ($u['pct'] >= 80 ? 'warn' : 'bad')));
    return '<div class="up ' . $cls . '">'
        . '<b>' . faPct($u['pct']) . '</b>'
        . '<span>' . $label . '</span>'
        . '<i>' . ($u['checks'] > 0 ? faNum($u['checks']) . ' چک' : 'بدون داده') . '</i>'
        . '</div>';
}

/** ردیف یک سایت */
function siteCard(array $s, float $tz): string
{
    $paused = (int)$s['paused'] === 1;
    $st = $paused ? 'paused' : (string)$s['status'];
    $cls = ['up' => 'up', 'down' => 'down', 'paused' => 'paused'][$st] ?? 'unknown';
    $emoji = ['up' => '🟢', 'down' => '🔴', 'paused' => '⏸', 'unknown' => '🟡'][$st] ?? '⚪️';
    $name = ['up' => 'فعال', 'down' => 'قطع', 'paused' => 'متوقف', 'unknown' => 'نامشخص'][$st] ?? $st;

    $recent = Stats::recent($s, 60);
    $day    = Stats::uptime($s, 1);
    $wk     = Stats::uptime($s, 7);
    $mo     = Stats::uptime($s, 30);
    $cnt    = Stats::counters($s);

    $err = trim((string)$s['last_error']);
    $downNote = '';
    if ($st === 'down' && $err !== '') {
        $downNote = '<div class="errline">⚠️ ' . h($err) . '</div>';
    } elseif ($st === 'up' && (int)$s['last_code'] > 0) {
        $downNote = '<div class="okline">کد پاسخ: ' . faNum((int)$s['last_code']) . ' • زمان پاسخ: ' . faMs((int)$s['last_ms']) . '</div>';
    } elseif ($st === 'paused') {
        $downNote = '<div class="pauseline">این سایت موقتاً از چک خارج شده است.</div>';
    }

    $since = '';
    if ((int)$s['last_down_duration'] > 0 && $st !== 'down') {
        $since = '<span>آخرین توقف: ' . faDuration((int)$s['last_down_duration']) . '</span>';
    }

    return '<article class="card ' . $cls . '" data-site="' . (int)$s['id'] . '">'
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

        . '<div class="hist">' . Stats::barHtml($recent) . '</div>'
        . '<div class="meta">'
        . '<span>آخرین چک: <b>' . timeAgo($s['last_check_at'], $tz) . '</b></span>'
        . $since
        . '<span>کل چک‌ها: <b>' . faNum((int)$cnt['checks']) . '</b></span>'
        . '<span>ناموفق: <b>' . faNum((int)$cnt['fails']) . '</b></span>'
        . '</div>'
        . $downNote
        . '</article>';
}

$htmlCards = '';
foreach ($sites as $s) $htmlCards .= siteCard($s, $tz);

$empty = $sites ? '' : '<div class="card empty"><b>هنوز سایتی ثبت نشده است.</b><p>از داخل ربات می‌توانید سایت اضافه کنید.</p></div>';

$avgUp = $summary['uptime24'];
$engineOk = (bool)$engine['cron_healthy'];
$engineTxt = $engine['paused'] ? 'چک‌ها متوقف است' : ($engineOk ? 'در حال چک خودکار' : 'چک خودکار هنوز شروع نشده');

$updated = date('Y/m/d H:i:s', time() + (int)round($tz * 3600));
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= h($pageTitle) ?></title>
<meta name="description" content="صفحهٔ وضعیت سرورها — آپتایم لحظه‌ای">
<style>
:root{
  --bg:#070b14; --panel:#0e1626; --panel2:#111c31; --line:#1e2b47;
  --txt:#e7eefc; --muted:#8ea1c4; --dim:#64769b;
  --ok:#22c55e; --bad:#ef4444; --warn:#f59e0b; --idle:#64748b;
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
.wrap{max-width:920px;margin:0 auto;padding:26px 16px 60px}

/* ===== هدر ===== */
.hero{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;
  background:linear-gradient(180deg,rgba(30,45,80,.55),rgba(14,22,38,.9));
  border:1px solid var(--line);border-radius:20px;padding:20px 22px;box-shadow:0 18px 40px -28px #000}
.brand{display:flex;align-items:center;gap:14px}
.logo{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-size:22px;
  background:linear-gradient(140deg,#1d4ed8,#0ea5e9);box-shadow:0 8px 22px -10px #0ea5e9}
.brand h1{margin:0;font-size:19px;letter-spacing:-.2px}
.brand p{margin:4px 0 0;color:var(--muted);font-size:13px}
.pill{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;
  background:#0b1324;border:1px solid var(--line);font-size:13px;color:var(--muted)}
.pill i{width:9px;height:9px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 4px rgba(34,197,94,.15);animation:pulse 2s infinite}
.pill.warn i{background:var(--warn);box-shadow:0 0 0 4px rgba(245,158,11,.15)}
.pill.bad i{background:var(--bad);box-shadow:0 0 0 4px rgba(239,68,68,.15)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.45}}

/* ===== نوار خلاصه ===== */
.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0 22px}
.s\boxed{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:14px 16px}
.sbox b{display:block;font-size:22px;font-weight:700;letter-spacing:-.5px}
.sbox span{display:block;color:var(--dim);font-size:12px;margin-top:5px}
.sbox.g b{color:var(--ok)} .sbox.r b{color:var(--bad)} .sbox.y b{color:var(--warn)}

/* ===== کارت سایت ===== */
.card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:18px;margin-bottom:14px;
  transition:border-color .25s, transform .25s}
.card:hover{border-color:#2c3f68;transform:translateY(-1px)}
.card.down{border-color:rgba(239,68,68,.45);box-shadow:0 0 0 1px rgba(239,68,68,.12) inset}
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
.card.paused .badge{color:#fcd34d;border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08)}

.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:16px}
.up{background:var(--panel2);border:1px solid var(--line);border-radius:14px;padding:12px 14px;text-align:center}
.up b{display:block;font-size:20px;font-weight:800;letter-spacing:-.5px}
.up span{display:block;font-size:11.5px;color:var(--dim);margin-top:4px}
.up i{display:block;font-style:normal;font-size:11px;color:#4f608a;margin-top:3px}
.up.great b{color:var(--ok)} .up.good b{color:#86efac}
.up.warn b{color:var(--warn)} .up.bad b{color:var(--bad)} .up.na b{color:#475569}

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
.okline{margin-top:12px;font-size:12.5px;color:#93c5fd;direction:ltr;text-align:right}
.pauseline{margin-top:12px;font-size:12.5px;color:#fcd34d}
.empty{text-align:center;color:var(--muted);padding:34px 20px}
.empty b{color:var(--txt);font-size:16px}

/* ===== فوتر ===== */
.foot{margin-top:26px;padding-top:16px;border-top:1px solid var(--line);color:var(--dim);
  font-size:12.5px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.foot .live{display:inline-flex;align-items:center;gap:7px}
.foot .live i{width:7px;height:7px;border-radius:50%;background:#22c55e;animation:pulse 1.6s infinite}

@media (max-width:640px){
  .summary{grid-template-columns:repeat(2,1fr)}
  .ttl h2,.target{max-width:56vw}
  .stats{grid-template-columns:repeat(3,1fr);gap:8px}
  .up b{font-size:17px}
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
        <p><?= h($owner) ?> • آپتایم لحظه‌ای سرورها</p>
      </div>
    </div>
    <div class="pill <?= $engine['paused'] ? 'warn' : ($engineOk ? '' : 'warn') ?>" id="enginePill">
      <i></i><span id="engineTxt"><?= h($engineTxt) ?></span>
    </div>
  </div>

  <div class="summary">
    <div class="sbox"><b id="sTotal"><?= faNum(count($sites)) ?></b><span>کل سایت‌ها</span></div>
    <div class="sbox g"><b id="sUp"><?= faNum($summary['up']) ?></b><span>فعال 🟢</span></div>
    <div class="sbox r"><b id="sDown"><?= faNum($summary['down']) ?></b><span>قطع 🔴</span></div>
    <div class="sbox y"><b id="sAvg"><?= faPct($avgUp) ?></b><span>آپتایم میانگین ۲۴س</span></div>
  </div>

  <div id="cards">
    <?= $htmlCards ?>
    <?= $empty ?>
  </div>

  <div class="foot">
    <span class="live"><i></i> بروزرسانی خودکار هر <?= faNum(25) ?> ثانیه</span>
    <span>آخرین به‌روزرسانی: <b id="updated"><?= h($updated) ?></b></span>
    <span>قدرت‌گرفته از ربات مانیتورینگ 🤖</span>
  </div>

</div>

<script>
(function () {
  var TOKEN = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var API   = <?= json_encode($base . '/api.php', JSON_UNESCAPED_SLASHES) ?>;
  var TICK  = 25000;      // هر ۲۵ ثانیه داده تازه می‌شود
  var RELOAD_EVERY = 6;   // هر ۲٫۵ دقیقه شارژ کامل (برای افزودن/حذف سایت)
  var n = 0;

  function fa(s) {
    return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
  }

  setInterval(function () {
    n++;
    if (n % RELOAD_EVERY === 0) { location.reload(); return; }
    fetch(API + '?u=' + encodeURIComponent(TOKEN) + '&_=' + Date.now(), { credentials: 'omit' })
      .then(function (r) { if (!r.ok) throw new Error('http'); return r.json(); })
      .then(function (d) { if (d && d.ok) paint(d); else throw new Error('bad'); })
      .catch(function () { /* یک تلاش دیگر در تیک بعدی */ });
  }, TICK);

  function paint(d) {
    var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
    set('sTotal', fa(d.summary.total));
    set('sUp',    fa(d.summary.up));
    set('sDown',  fa(d.summary.down));
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
      var ups = card.querySelectorAll('.up');
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

      var line = card.querySelector('.errline, .okline, .pauseline');
      if (line && s.note_html) line.outerHTML = s.note_html;
      else if (!line && s.note_html) card.insertAdjacentHTML('beforeend', s.note_html);
    });
  }
})();
</script>
</body>
</html>
