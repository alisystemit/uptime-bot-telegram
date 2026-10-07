/* ============================================================
   هستهٔ برنامه — حالت، ناوبری، تایمرها، رندر، رویدادها
   ============================================================ */
(function (NS) {
  'use strict';

  var fmt = NS.fmt, A = NS.api, V = NS.views, ACT = NS.actions, I = NS.icons, TG = NS.tg;
  var esc = fmt.esc, fa = fmt.fa, faPct = fmt.faPct;

  /* ============================================================
     حالت برنامه
     ============================================================ */
  var S = {
    view: 'dash',
    siteId: 0,
    data: {},
    overview: null,
    site: null,
    report: null,
    shared: null,
    settings: null,

    busy: false,
    setBusy: false,
    checkingAll: false,
    pendingDel: 0,

    filter: 'all',
    sort: 'uptime',
    search: '',

    sheet: null,          // 'add' | 'del' | 'gen' | null
    timers: {},
    seq: { view: 0, site: 0, shared: 0, settings: 0 },
    lastUpdate: 0,
    online: true,
    boot: false
  };

  var els = null;
  var REFRESH = { detail: 20000, dash: 30000, report: 30000, incidents: 60000, domains: 120000, rank: 60000 };

  /* ============================================================
     ابزار UI
     ============================================================ */
  function toast(msg, kind) {
    if (!els || !els.toasts) return;
    var t = document.createElement('div');
    t.className = 'toast ' + (kind || '');
    var ic = { ok: '✓', err: '✕', warn: '!' }[kind] || '';
    t.innerHTML = (ic ? '<span class="t-ic">' + ic + '</span>' : '') + '<span>' + esc(msg) + '</span>';
    els.toasts.appendChild(t);
    // حذف خودکار
    setTimeout(function () { t.classList.add('out'); }, 2400);
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 2800);
    // حداکثر ۴ توست همزمان
    while (els.toasts.children.length > 4) els.toasts.removeChild(els.toasts.firstChild);
  }

  function haptic(t) { TG.haptic(t); }
  function hapticImpact(s) { TG.hapticImpact(s); }

  /** نوار پیشرفت بالای صفحه */
  function setProgress(on, text) {
    if (!els || !els.progress) return;
    els.progress.classList.toggle('on', !!on);
    if (on) els.progressText.textContent = text || '';
  }

  /** صفحهٔ قفل (بیرون از تلگرام یا خطای دسترسی) */
  function locked(title, sub, icon) {
    try {
      stopTimers();
      var app = document.getElementById('app');
      if (app) app.style.display = 'none';
      document.body.innerHTML =
        '<div class="locked"><div class="card box">' +
        '<div class="lock-em">' + (icon || '🔐') + '</div>' +
        '<b>' + esc(title) + '</b>' +
        '<p>' + esc(sub) + '</p>' +
        '<div class="lock-foot">آپ‌تایم مانیتورینگ • وب‌اپ تلگرام</div>' +
        '</div></div>';
      // تضمین دیده‌شدن صفحه (body به‌صورت پیش‌فرض opacity:0 است)
      document.body.classList.add('ready');
      document.body.classList.remove('booting');
    } catch (e) { /* noop */ }
  }

  /* ============================================================
     کمکی: پیدا کردن سایت در حافظه
     ============================================================ */
  function findSite(id) {
    var pools = [
      (S.overview && S.overview.sites) || [],
      (S.report && S.report.sites) || [],
      (S.shared && S.shared.items) || [],
      S.site ? [S.site] : []
    ];
    for (var i = 0; i < pools.length; i++) {
      for (var j = 0; j < pools[i].length; j++) {
        if (pools[i][j].id === id) return pools[i][j];
      }
    }
    return null;
  }

  /* ============================================================
     ناوبری
     ============================================================ */
  var TITLES = {
    dash: 'آپ‌تایم', report: 'گزارش', incidents: 'رخدادها',
    domains: 'دامنه‌ها', rank: 'رنکینگ', detail: 'جزئیات مانیتور'
  };

  function setTab(tab) {
    if (S.view === tab && tab !== 'detail') { refreshView(); return; }
    S.view = tab;
    S.siteId = 0;
    S.site = null;

    document.querySelectorAll('.tab').forEach(function (t) {
      t.classList.toggle('on', t.dataset.tab === tab);
    });
    els.fabAdd.classList.toggle('hide', tab !== 'dash');
    els.btnBack.classList.add('hide');
    els.title.textContent = TITLES[tab] || 'آپ‌تایم';
    TG.hideBack();
    TG.hideMain();

    var cached = {
      report: S.report, incidents: S.data.incidents,
      domains: S.data.domains, rank: S.data.rank
    }[tab];

    if (!cached) els.view.innerHTML = V.skel(4, 'در حال بارگذاری…');
    else render();

    stopTimers();
    loadFor(tab);
    haptic();
    startTimer();
  }

  function openSite(id) {
    S.view = 'detail';
    S.siteId = id;
    document.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('on'); });
    els.fabAdd.classList.add('hide');
    els.btnBack.classList.remove('hide');
    els.title.textContent = TITLES.detail;
    els.view.innerHTML = V.skel(3);

    // BackButton بومی تلگرام
    TG.showBack(function () { backToDash(); });

    stopTimers();
    loadSite(id, false);
    haptic('light');
    startTimer();
  }

  function backToDash() {
    TG.hideBack();
    setTab('dash');
    haptic('light');
  }

  /* ============================================================
     رندر
     ============================================================ */
  function render() {
    if (S.view === 'detail') {
      if (S.site) { els.view.innerHTML = V.detail(S.site); afterRender(); }
      return;
    }

    var html = '';
    switch (S.view) {
      case 'dash':
        if (!S.overview) return;
        html = V.hero(S.overview.user) + dashSummary() + enginePill() + dashList() + V.shared(S.shared && S.shared.items);
        break;
      case 'report':
        if (!S.report) return;
        html = V.report(S.report);
        break;
      case 'incidents':
        html = V.incidents(S.data.incidents);
        break;
      case 'domains':
        html = V.domains(S.data.domains);
        break;
      case 'rank':
        html = V.rank(S.data.rank);
        break;
      default: return;
    }

    els.view.innerHTML = html;
    afterRender();
  }

  /** پس از رندر: بازگرداندن مقدار جستجو + فعال‌سازی شمارنده‌ها */
  function afterRender() {
    if (S.view === 'dash') {
      var si = els.view.querySelector('#dashSearch');
      if (si && si.value !== S.search) si.value = S.search;
    }
    countUp();
  }

  function countUp() {
    try {
      var nodes = els.view.querySelectorAll('[data-count]');
      if (!nodes.length) return;
      fmt.animateCounts(els.view);
      // تضمین: پس از ۱.۲ ثانیه مقدار نهایی دقیق (شامل پسوند) نوشته شود
      setTimeout(function () {
        for (var i = 0; i < nodes.length; i++) {
          var el = nodes[i];
          if (!el.isConnected) continue;
          var t = parseFloat(el.getAttribute('data-count'));
          if (isNaN(t)) continue;
          var dp = parseInt(el.getAttribute('data-dp') || '0', 10);
          var suffix = el.getAttribute('data-suffix') || '';
          el.textContent = fmt.fa(dp ? Math.round(t * 100) / 100 : Math.round(t)) + suffix;
        }
      }, 1200);
    } catch (e) { /* noop */ }
  }

  function dashSummary() {
    var sum = S.overview.summary || { avg: 0, up: 0, down: 0, slow: 0, paused: 0 };
    var color = NS.charts.pctColor(sum.avg);
    return '<div class="card sum-card">' +
      '<div class="ringwrap">' + NS.charts.ringChart(sum.avg, color) +
      '<div class="rtxt">' + fmt.countHtml(sum.avg, 2, '٪') + '<span>آپ‌تایم ۲۴س</span></div></div>' +
      '<div class="hstats">' +
      '<div class="hs up"><b>' + fmt.countHtml(sum.up) + '</b><span>فعال</span></div>' +
      '<div class="hs down"><b>' + fmt.countHtml(sum.down) + '</b><span>قطع</span></div>' +
      '<div class="hs slow"><b>' + fmt.countHtml(sum.slow) + '</b><span>کند</span></div>' +
      '<div class="hs paused"><b>' + fmt.countHtml(sum.paused) + '</b><span>متوقف</span></div>' +
      '</div></div>';
  }

  function enginePill() {
    var eng = S.overview.engine || {};
    var txt, cls;
    if (eng.paused) { txt = 'چک‌ها <b>سراسری متوقف</b> است'; cls = 'bad'; }
    else if (eng.cron_healthy) { txt = 'موتور چک <b>فعال</b> • هر ' + fa(eng.interval) + ' ثانیه'; cls = 'ok'; }
    else { txt = 'موتور چک <b>در انتظار کرون</b>' +
      (eng.stale_sec != null ? ' • آخرین راند ' + fa(Math.round(eng.stale_sec / 60)) + ' دقیقه پیش' : ''); cls = 'warn'; }
    return '<div class="engine ' + cls + '"><span class="dot"></span><span>' + txt + '</span>' +
      '<span class="eng-ts">' + (S.lastUpdate ? 'به‌روزرسانی ' + relTime(S.lastUpdate) : '') + '</span></div>';
  }

  function relTime(ts) {
    var d = Math.max(0, Math.round((Date.now() - ts) / 1000));
    if (d < 5) return 'همین حالا';
    if (d < 60) return fa(d) + ' ثانیه پیش';
    var m = Math.round(d / 60);
    if (m < 60) return fa(m) + ' دقیقه پیش';
    return fa(Math.round(m / 60)) + ' ساعت پیش';
  }

  /* ---------- لیست داشبورد با جستجو/فیلتر/مرتب‌سازی ---------- */
  var FILTERS = [
    { k: 'all', l: 'همه' }, { k: 'up', l: 'فعال' }, { k: 'down', l: 'قطع' },
    { k: 'slow', l: 'کند' }, { k: 'paused', l: 'متوقف' }
  ];
  var SORT_LABELS = ACT.SORTS;

  function dashList() {
    var all = S.overview.sites || [];

    var chips = FILTERS.map(function (c) {
      return '<button class="chip' + (S.filter === c.k ? ' on' : '') + '" data-filter="' + c.k + '">' + c.l + '</button>';
    }).join('');

    var sortBtn = '<button class="sortbtn" data-act="sortmenu">' + I.sort +
      '<span>' + esc((SORT_LABELS[S.sort] || SORT_LABELS.uptime).label) + '</span>' + I.chevronDown + '</button>';

    var q = (S.search || '').trim().toLowerCase();
    var list = all.filter(function (s) {
      if (S.filter !== 'all' && s.state !== S.filter) return false;
      if (q) {
        var hay = ((s.label || '') + ' ' + (s.target || '')).toLowerCase();
        if (hay.indexOf(q) === -1) return false;
      }
      return true;
    });
    list = ACT.sortSites(list, S.sort);

    var html = '<div class="toolbar">' +
      '<div class="search">' + I.search +
      '<input id="dashSearch" type="search" placeholder="جستجو در سایت‌ها…" autocomplete="off" autocapitalize="off" spellcheck="false">' +
      (S.search ? '<button class="clear" data-act="clearsearch" aria-label="پاک کردن">' + I.close + '</button>' : '') +
      '</div>' +
      '<div class="filterrow"><div class="chips">' + chips + '</div>' + sortBtn + '</div>' +
      '</div>';

    html += '<div class="sec-title"><span class="n">' + I.layers + '</span> مانیتورهای شما' +
      (list.length !== all.length
        ? '<small class="cnt">' + fa(list.length) + ' از ' + fa(all.length) + '</small>' : '') + '</div>';

    if (!all.length) {
      html += '<div class="empty card">' + I.plus + '<b>هنوز سایتی ثبت نکرده‌اید</b>' +
        '<span>از دکمهٔ ➕ پایین صفحه اولین سایت را اضافه کنید.</span>' +
        '<button class="btn pri" data-act="openadd">' + I.plus + ' افزودن اولین سایت</button></div>';
    } else if (!list.length) {
      html += '<div class="empty card">' + I.search + '<b>موردی پیدا نشد</b>' +
        '<span>فیلتر یا عبارت جستجو را تغییر دهید.</span>' +
        '<button class="btn" data-act="resetfilter">بازنشانی فیلتر</button></div>';
    } else {
      list.forEach(function (s) { html += V.siteCard(s); });
    }
    return html;
  }

  /* ============================================================
     بارگذاری داده
     ============================================================ */
  function bump(key) { S.seq[key] = (S.seq[key] || 0) + 1; return S.seq[key]; }

  function loadOverview(silent) {
    var seq = bump('view');
    A.call('overview', null, 'GET', true).then(function (r) {
      if (seq !== S.seq.view) return;
      if (r.status === 401 || r.status === 403) {
        locked('دسترسی ندارید', 'لطفاً اپ را از داخل ربات باز کنید یا دستور /start بفرستید.', '🔐');
        return;
      }
      if (!ok(r)) { handleApiError(r); return; }
      S.overview = r.json.data;
      S.lastUpdate = Date.now();
      S.online = true;
      if (S.view === 'dash') render();
      if (!silent) afterLoad();
    });
  }

  function loadSite(id, silent) {
    var seq = bump('site');
    A.call('site', { id: id }, 'GET', !silent).then(function (r) {
      if (seq !== S.seq.site) return;
      if (r.status === 404) { toast('این مانیتور دیگر وجود ندارد.', 'warn'); backToDash(); return; }
      if (!ok(r)) { handleApiError(r, true); return; }
      S.site = r.json.data;
      S.lastUpdate = Date.now();
      render();
      if (!silent) afterLoad();
    });
  }

  function loadReport(silent) {
    var seq = bump('view');
    A.call('report').then(function (r) {
      if (seq !== S.seq.view) return;
      if (!ok(r)) { handleApiError(r); return; }
      S.report = r.json.data;
      S.lastUpdate = Date.now();
      if (S.view === 'report') render();
      if (!silent) afterLoad();
    });
  }

  function loadSettings(silent) {
    var seq = bump('settings');
    A.call('settings').then(function (r) {
      if (seq !== S.seq.settings) return;
      if (!ok(r)) { handleApiError(r); return; }
      S.settings = r.json.data;
      S.data.settings = r.json.data;
      if (S.sheet === 'gen') renderSheetBody();
      if (!silent) afterLoad();
    });
  }

  function loadShared(silent) {
    var seq = bump('shared');
    A.call('shared').then(function (r) {
      if (seq !== S.seq.shared) return;
      if (!r.json.ok) return;                 // نبودِ اشتراک خطا نیست
      S.shared = r.json.data;
      if (S.view === 'dash' && S.overview) render();
    });
  }

  function loadIncidents(silent) {
    var seq = bump('view');
    A.call('incidents').then(function (r) {
      if (seq !== S.seq.view) return;
      if (!ok(r)) { handleApiError(r); return; }
      S.data.incidents = r.json.data;
      S.lastUpdate = Date.now();
      if (S.view === 'incidents') render();
      if (!silent) afterLoad();
    });
  }

  function loadDomains(silent) {
    var seq = bump('view');
    A.call('domains').then(function (r) {
      if (seq !== S.seq.view) return;
      if (!ok(r)) { handleApiError(r); return; }
      S.data.domains = r.json.data;
      S.lastUpdate = Date.now();
      if (S.view === 'domains') render();
      if (!silent) afterLoad();
    });
  }

  function loadRank(silent) {
    var seq = bump('view');
    A.call('rank').then(function (r) {
      if (seq !== S.seq.view) return;
      if (!ok(r)) { handleApiError(r); return; }
      S.data.rank = r.json.data;
      if (S.view === 'rank') render();
      if (!silent) afterLoad();
    });
  }

  function loadFor(tab) {
    switch (tab) {
      case 'dash': loadOverview(); loadShared(true); break;
      case 'report': loadReport(); break;
      case 'incidents': loadIncidents(); break;
      case 'domains': loadDomains(); break;
      case 'rank': loadRank(); break;
    }
  }

  function ok(r) { return !!(r && r.json && r.json.ok); }

  function handleApiError(r, soft) {
    var kind = (r && r.json && r.json.error) || '';
    if (kind === 'auth' || r.status === 401) {
      locked('احراز هویت انجام نشد', 'لطفاً اپ را از داخل ربات باز کنید.', '🔐');
      return;
    }
    if (kind === 'blocked') { locked('حساب مسدود است', 'با پشتیبانی ربات تماس بگیرید.', '🚫'); return; }
    if (kind === 'access') { locked('دسترسی غیرفعال', 'دستور /start را در ربات بفرستید.', '⚠️'); return; }
    if (kind === 'database') { locked('اختلال در سرویس', 'لطفاً چند دقیقه بعد دوباره تلاش کنید.', '🛠'); return; }

    if (!S.online || kind === 'network' || kind === 'timeout') {
      if (!S.online && S.lastUpdate) {
        toast('آفلاین — نمایش آخرین دادهٔ دریافتی.', 'warn');
      } else {
        toast(A.err(kind || 'internal'), 'err');
      }
      if (!soft) haptic('warning');
    } else {
      toast(A.err(kind || 'internal'), 'err');
      haptic('error');
    }
  }

  function refreshView() {
    if (S.sheet) return;                       // هنگام باز بودن شیت رفرش نکن
    switch (S.view) {
      case 'dash': if (S.overview) { loadOverview(true); loadShared(true); } break;
      case 'detail': if (S.siteId) loadSite(S.siteId, true); break;
      case 'report': loadReport(true); break;
      case 'incidents': loadIncidents(true); break;
      case 'domains': loadDomains(true); break;
      case 'rank': loadRank(true); break;
    }
  }

  function afterLoad() {
    var btn = els.btnRefresh;
    if (!btn) return;
    btn.classList.remove('spin-on');
    void btn.offsetWidth;                      // اجبار به ریست انیمیشن
    btn.classList.add('spin-on');
    setTimeout(function () { btn.classList.remove('spin-on'); }, 600);
  }

  /* ============================================================
     تایمر خودکار + وضعیت شبکه
     ============================================================ */
  function stopTimers() {
    Object.keys(S.timers).forEach(function (k) { clearInterval(S.timers[k]); delete S.timers[k]; });
  }

  function startTimer() {
    stopTimers();
    if (document.hidden) return;
    var ms = REFRESH[S.view] || 45000;
    S.timers.main = setInterval(function () {
      if (document.hidden || S.busy || S.checkingAll || S.sheet) return;
      if (!navigator.onLine) return;
      refreshView();
    }, ms);
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stopTimers(); return; }
    startTimer();
    refreshView();
  });

  window.addEventListener('online', function () {
    S.online = true;
    document.body.classList.remove('offline');
    toast('اتصال برقرار شد.', 'ok');
    A.bustAll();
    refreshView();
  });
  window.addEventListener('offline', function () {
    S.online = false;
    document.body.classList.add('offline');
    toast('اتصال اینترنت قطع شد.', 'warn');
  });

  /* ============================================================
     شیت‌ها
     ============================================================ */
  function openSheet(name) {
    S.sheet = name;
    if (name === 'add') {
      els.sheetAdd.classList.add('open');
      els.addTarget.value = '';
      setAddFmt(S.addFmt || 'http');
      setTimeout(function () { try { els.addTarget.focus(); } catch (e) {} }, 260);
    }
  }

  function closeAllSheets() {
    S.sheet = null;
    [els.sheetAdd, els.sheetDel, els.genSheet].forEach(function (s) { s.classList.remove('open'); });
  }
  function closeAdd() { els.sheetAdd.classList.remove('open'); if (S.sheet === 'add') S.sheet = null; }
  function closeDel() { els.sheetDel.classList.remove('open'); if (S.sheet === 'del') S.sheet = null; }
  function closeGen() { els.genSheet.classList.remove('open'); if (S.sheet === 'gen') S.sheet = null; }

  function setAddFmt(f) {
    S.addFmt = f;
    document.querySelectorAll('.fmt').forEach(function (c) {
      c.classList.toggle('on', c.dataset.fmt === f);
    });
    var ph = { http: 'https://example.com', tcp: 'example.com:8080', ping: 'example.com' }[f];
    els.addTarget.placeholder = ph || 'https://example.com';
  }

  function askDel(id) {
    S.pendingDel = id;
    var s = findSite(id);
    var name = s ? (s.label || s.target) : ('#' + id);
    els.delText.innerHTML = 'مانیتور <b class="bad-t">' + esc(name) + '</b> و تمام تاریخچهٔ چک‌ها و رخدادهایش برای همیشه حذف خواهد شد.';
    els.sheetDel.classList.add('open');
    S.sheet = 'del';
    TG.showBack(function () { closeDel(); });
    hapticImpact('medium');
  }

  function renderSheetBody() {
    var s = S.settings || S.data.settings;
    if (!s) return;
    els.genBody.innerHTML = V.settings(s);
    els.genSheet.classList.add('open');
    S.sheet = 'gen';
  }

  /** رندر مجدد شیت عمومی با دادهٔ فعلی حافظه (برای به‌روزرسانی خوشبینانه) */
  function renderSettingsSheet() {
    if (S.sheet === 'gen') renderSheetBody();
  }

  function openSettings() {
    haptic('light');
    els.genTitle.textContent = '⚙️ تنظیمات و حساب';
    els.genBody.innerHTML = V.skel(3, 'در حال بارگذاری تنظیمات…');
    els.genSheet.classList.add('open');
    S.sheet = 'gen';
    TG.showBack(function () { closeGen(); });
    loadSettings(true);
  }

  /* ---------- شیت مرتب‌سازی ---------- */
  function openSort() {
    var keys = Object.keys(ACT.SORTS);
    var html = keys.map(function (k) {
      var on = S.sort === k;
      return '<button class="sortitem' + (on ? ' on' : '') + '" data-sort="' + k + '">' +
        '<span>' + esc(ACT.SORTS[k].label) + '</span>' + (on ? '<span class="ck">✓</span>' : '') + '</button>';
    }).join('');
    els.genTitle.textContent = '↕️ مرتب‌سازی سایت‌ها';
    els.genBody.innerHTML = '<div class="sortlist">' + html + '</div>';
    els.genSheet.classList.add('open');
    S.sheet = 'gen';
    TG.showBack(function () { closeGen(); });
    haptic('light');
  }

  /* ============================================================
     رویدادها
     ============================================================ */
  function bindEvents() {
    /* تب‌ها */
    document.querySelectorAll('.tab').forEach(function (t) {
      t.addEventListener('click', function () { setTab(t.dataset.tab); });
    });

    els.btnBack.addEventListener('click', backToDash);
    els.btnRefresh.addEventListener('click', function () { refreshView(); haptic('light'); });
    els.btnSettings.addEventListener('click', openSettings);
    els.fabAdd.addEventListener('click', function () { openSheet('add'); });

    /* شیت افزودن */
    els.addCancel.addEventListener('click', closeAdd);
    els.addSubmit.addEventListener('click', ACT.submitAdd);
    els.sheetAdd.addEventListener('click', function (e) { if (e.target === this) closeAdd(); });
    els.addTarget.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); ACT.submitAdd(); }
    });
    document.querySelectorAll('.fmt').forEach(function (c) {
      c.addEventListener('click', function () { setAddFmt(c.dataset.fmt); });
    });

    /* شیت حذف */
    els.delCancel.addEventListener('click', closeDel);
    els.delOk.addEventListener('click', ACT.confirmDel);
    els.sheetDel.addEventListener('click', function (e) { if (e.target === this) closeDel(); });

    /* شیت عمومی */
    els.genSheet.addEventListener('click', function (e) { if (e.target === this) closeGen(); });

    /* کیبورد */
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (S.sheet) { closeAllSheets(); TG.hideBack(); haptic('light'); return; }
        if (S.view === 'detail') backToDash();
      }
      // میان‌بر: ۱..۵ برای جابه‌جایی بین تب‌ها
      if (!S.sheet && !/input|textarea/i.test((e.target.tagName || ''))) {
        var tabs = ['dash', 'report', 'incidents', 'domains', 'rank'];
        var n = parseInt(e.key, 10);
        if (n >= 1 && n <= tabs.length) { setTab(tabs[n - 1]); }
        if (e.key === 'r' || e.key === 'R') { refreshView(); }
      }
    });

    /* ---------- delegation ---------- */
    document.addEventListener('click', function (e) {
      var t = e.target;

      var chip = t.closest('[data-filter]');
      if (chip) {
        S.filter = chip.dataset.filter;
        render();
        haptic('light');
        return;
      }

      var sw = t.closest('[data-set]');
      if (sw) { ACT.toggleSetting(sw.dataset.set, sw); return; }

      var sortItem = t.closest('[data-sort]');
      if (sortItem) {
        S.sort = sortItem.dataset.sort;
        closeGen();
        TG.hideBack();
        render();
        haptic('light');
        return;
      }

      var btn = t.closest('[data-act]');
      if (!btn) return;
      var act = btn.dataset.act, id = +btn.dataset.id;

      switch (act) {
        case 'open': openSite(id); break;
        case 'check': ACT.doCheck(id, btn); break;
        case 'toggle': ACT.doToggle(id, btn); break;
        case 'dcheck': ACT.doCheck(id, btn); break;
        case 'dtoggle': ACT.doToggle(id, btn); break;
        case 'ddelete': askDel(id); break;
        case 'csv': ACT.exportCsv(S.site); break;
        case 'checkall': ACT.checkAll(btn); break;
        case 'openadd': openSheet('add'); break;
        case 'openbot': {
          var bu = (S.settings || S.data.settings || {}).bot_username;
          if (bu) TG.openTelegramLink('https://t.me/' + bu);
          break;
        }
        case 'sortmenu': openSort(); break;
        case 'resetfilter':
          S.filter = 'all'; S.search = '';
          render(); haptic('light');
          break;
        case 'clearsearch':
          S.search = '';
          render(); haptic('light');
          break;
        case 'copyshare':
          ACT.copyText((S.settings || S.data.settings || {}).status_url, 'لینک وضعیت کپی شد.');
          break;
        case 'copylink':
          ACT.copyText(btn.dataset.url, 'لینک کپی شد.');
          break;
        case 'sharelink':
          ACT.shareLink(btn.dataset.url);
          break;
        case 'copytarget':
          ACT.copyText(btn.dataset.text, 'آدرس کپی شد.');
          break;
        case 'share':
          ACT.shareLink(btn.dataset.url);
          break;
      }
    });

    /* کلیک روی بدنهٔ کارت (غیر از دکمه‌ها) = باز کردن جزئیات */
    document.addEventListener('click', function (e) {
      if (e.target.closest('button, a, input, [data-act], [data-set], [data-filter], [data-sort]')) return;
      var art = e.target.closest('article.site:not(.viewer)');
      if (!art) return;
      var id = +art.dataset.id;
      if (id > 0) openSite(id);
    });

    /* جستجوی زنده */
    document.addEventListener('input', function (e) {
      if (e.target && e.target.id === 'dashSearch') {
        S.search = e.target.value;
        render();
        // تمرکز را حفظ کن
        var si = els.view.querySelector('#dashSearch');
        if (si) { si.focus(); try { si.setSelectionRange(si.value.length, si.value.length); } catch (err) {} }
      }
    });

    bindPullToRefresh();
  }

  /* ---------- Pull-to-refresh ---------- */
  function bindPullToRefresh() {
    var scroller = els.scroller, startY = 0, pulling = false, dist = 0;

    scroller.addEventListener('touchstart', function (e) {
      if (scroller.scrollTop > 0) { startY = null; return; }
      startY = e.touches[0].clientY;
      pulling = true;
      dist = 0;
    }, { passive: true });

    scroller.addEventListener('touchmove', function (e) {
      if (!pulling || startY === null) return;
      var dy = e.touches[0].clientY - startY;
      if (dy > 0 && scroller.scrollTop <= 0) {
        dist = Math.min(110, dy);
        els.pull.style.transform = 'translateY(' + Math.max(0, dist - 46) + 'px)';
        els.pull.style.opacity = String(Math.min(1, dist / 60));
        els.pullTxt.textContent = dist > 70 ? 'رها کنید تا تازه شود' : '↓ برای تازه‌سازی بکشید';
        els.pull.classList.add('active');
        e.preventDefault();
      }
    }, { passive: false });

    function end() {
      if (!pulling) return;
      var trigger = dist > 70;
      pulling = false;
      startY = null;
      els.pull.style.transform = '';
      els.pull.style.opacity = '';
      els.pull.classList.remove('active');
      if (trigger) {
        haptic('success');
        refreshView();
      }
      dist = 0;
    }
    scroller.addEventListener('touchend', end, { passive: true });
    scroller.addEventListener('touchcancel', end, { passive: true });
  }

  /* ============================================================
     بوت
     ============================================================ */
  function collectEls() {
    els = {
      view: document.getElementById('view'),
      toasts: document.getElementById('toasts'),
      btnBack: document.getElementById('btnBack'),
      btnRefresh: document.getElementById('btnRefresh'),
      btnSettings: document.getElementById('btnSettings'),
      fabAdd: document.getElementById('fabAdd'),
      title: document.getElementById('title'),
      scroller: document.getElementById('scroller'),
      progress: document.getElementById('progress'),
      progressText: document.getElementById('progressText'),
      pull: document.getElementById('pull'),
      pullTxt: document.getElementById('pullTxt'),
      sheetAdd: document.getElementById('sheetAdd'),
      addTarget: document.getElementById('addTarget'),
      addCancel: document.getElementById('addCancel'),
      addSubmit: document.getElementById('addSubmit'),
      sheetDel: document.getElementById('sheetDel'),
      delText: document.getElementById('delText'),
      delCancel: document.getElementById('delCancel'),
      delOk: document.getElementById('delOk'),
      genSheet: document.getElementById('sheetGen'),
      genTitle: document.getElementById('genTitle'),
      genBody: document.getElementById('genBody')
    };
  }

  function boot() {
    // نمایش فوری صفحه — حتی اگر بعداً خطایی رخ دهد نباید سیاه بماند
    document.body.classList.remove('booting');
    document.body.classList.add('ready');

    // گیرندهٔ خطای سراسری: پیام خوانا به‌جای صفحهٔ سیاه/ناقص
    window.addEventListener('error', function (ev) {
      try {
        if (!S.boot) {
          var v = document.getElementById('view');
          if (v) {
            v.innerHTML = '<div class="empty card">⚠️<b>خطا در راه‌اندازی</b>' +
              '<span>' + esc(String(ev.message || 'خطای ناشناخته')) + '</span></div>';
          }
        }
        document.body.classList.add('ready');
      } catch (e) { /* noop */ }
    });

    try {
      collectEls();
    } catch (e) {
      locked('خطا در بارگذاری رابط', 'فایل‌های اپ کامل بارگذاری نشدند.', '⚠️');
      return;
    }

    if (!TG.hasTelegram || !TG.initData) {
      locked('این برنامه فقط داخل تلگرام کار می‌کند',
        'از ربات، دکمهٔ «📱 اپلیکیشن» (یا دستور /app) را بزنید.', '🔐');
      return;
    }

    try { TG.init(); } catch (e) { /* SDK اختیاری */ }
    try { TG.bindAll(); } catch (e) { /* SDK اختیاری */ }

    try {
      bindEvents();
    } catch (e) {
      if (els.view) {
        els.view.innerHTML = '<div class="empty card">⚠️<b>خطا در اتصال رویدادها</b>' +
          '<span>لطفاً صفحه را تازه کنید.</span></div>';
      }
      return;
    }

    els.view.innerHTML = V.skel(3);
    S.boot = true;
    S.online = navigator.onLine !== false;

    loadOverview();
    loadShared(true);
    startTimer();

    // نخستین تعامل: بازخورد لمسی
    document.body.addEventListener('touchstart', function once() {
      haptic('light');
      document.body.removeEventListener('touchstart', once);
    }, { passive: true, once: true });
  }

  /* ============================================================
     API عمومی هسته
     ============================================================ */
  NS.app = {
    state: S,
    get els() { return els; },
    toast: toast,
    haptic: haptic,
    hapticImpact: hapticImpact,
    setProgress: setProgress,
    render: render,
    countUp: countUp,
    findSite: findSite,
    setTab: setTab,
    openSite: openSite,
    backToDash: backToDash,
    refreshView: refreshView,
    loadOverview: loadOverview,
    loadSettings: loadSettings,
    renderSettingsSheet: renderSettingsSheet,
    openSettings: openSettings,
    closeGen: closeGen,
    askDel: askDel,
    openAdd: function () { openSheet('add'); },
    closeAdd: closeAdd,
    setAddFmt: setAddFmt,
    locked: locked
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})(window.NS = window.NS || {});