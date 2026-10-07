/* ============================================================
   رندر نماها — همهٔ توابع خالص: (داده) ← HTML
   ============================================================ */
(function (NS) {
  'use strict';

  var fmt = NS.fmt, C = NS.charts, I = NS.icons;
  var esc = fmt.esc, fa = fmt.fa, faPct = fmt.faPct, faMs = fmt.faMs;
  var ch = fmt.countHtml;

  /* ---------- اسکلتون ---------- */
  function skel(rows, note) {
    var r = '';
    for (var i = 0; i < rows; i++) r += '<div class="card sk box"></div>';
    return '<div class="sec-title"><span class="n"><span class="dotpulse"></span></span> ' + (note || 'در حال بارگذاری…') + '</div>' + r;
  }

  /* ---------- کارت کاربر (هیرو) ---------- */
  function hero(u) {
    var av;
    var tgUser = NS.tg.user;
    if (tgUser && tgUser.photo_url) av = '<img src="' + esc(tgUser.photo_url) + '" alt="" loading="lazy">';
    else av = '<span class="ava-txt">' + esc((u.name || '؟').trim().charAt(0)) + '</span>';

    var ringPct = Math.max(0, Math.min(100, u.sites_used && u.max_sites ? (u.sites_used / u.max_sites) * 100 : 0));

    var badge = '';
    if (u.plan === 'vip') badge += '<span class="tag vip">💎 ویژه' + (u.plan_until ? ' تا ' + esc(u.plan_until) : '') + '</span>';
    else badge += '<span class="tag">رایگان</span>';
    badge += '<span class="tag tag-accent">سطح ' + ch(u.level) + '</span>';
    badge += '<span class="tag">⚡ ' + ch(u.points) + ' امتیاز</span>';
    if (u.paused) badge += '<span class="tag warn">⏸ موقتاً متوقف</span>';
    if (!u.allowed) badge += '<span class="tag block">⛔ دسترسی غیرفعال</span>';

    var meter = (u.sites_used >= u.max_sites)
      ? '<div class="meter full"><i style="width:100%"></i></div><div class="meter-cap">ظرفیت سایت‌ها تکمیل است</div>'
      : '<div class="meter"><i style="width:' + ringPct.toFixed(1) + '%"></i></div>' +
        '<div class="meter-cap"><span>' + ch(u.sites_used) + ' سایت فعال</span><span>حداکثر ' + ch(u.max_sites) + '</span></div>';

    return '<div class="hero">' +
      '<div class="avatar">' + av + '<span class="online"></span></div>' +
      '<div class="hinfo">' +
      '<div class="nm">' + esc(u.name) + '</div>' +
      '<div class="sub">' + (u.username ? '@' + esc(u.username) : '') + '</div>' +
      '<div class="badges">' + badge + '</div>' +
      '</div>' +
      '<div class="hfoot">' + meter + '</div>' +
      '</div>';
  }

  /* ---------- کلاس رنگی درصد ---------- */
  function uCls(p) {
    if (p === null || p === undefined || isNaN(p)) return 'na';
    return p >= 99.5 ? 'good' : (p >= 95 ? 'ok' : (p >= 80 ? 'warn' : 'bad'));
  }

  /* ---------- کارت سایت ---------- */
  function siteCard(s, opts) {
    opts = opts || {};
    var st = fmt.stMeta(s.state);
    var u1 = s.u1 && s.u1.pct, u7 = s.u7 && s.u7.pct, u30 = s.u30 && s.u30.pct;

    var meta = '<span><span class="mlbl">آخرین چک</span> <b>' + esc(s.ago) + '</b></span>';
    if (s.state !== 'paused') meta += '<span><span class="mlbl">پاسخ</span> <b>' + faMs(s.ms) + '</b></span>';
    if (s.code) meta += '<span><span class="mlbl">کد</span> ' + ch(s.code) + '</span>';
    if (s.total_fails) meta += '<span><span class="mlbl">ناموفق</span> <b class="bad-t">' + ch(s.total_fails) + '</b></span>';
    if (s.error) meta += '<span class="err-line">' + I.alert + ' ' + esc(s.error) + '</span>';

    var actions;
    if (!opts.viewer) {
      actions = '<div class="sact">' +
        '<button class="btn pri" data-act="open" data-id="' + s.id + '">جزئیات' + I.forward + '</button>' +
        '<button class="btn" data-act="check" data-id="' + s.id + '">' + I.refresh + ' چک الآن</button>' +
        '<button class="btn ' + (s.state === 'paused' ? 'go' : 'warn-b') + '" data-act="toggle" data-id="' + s.id + '">' +
        (s.state === 'paused' ? I.play + ' ادامه' : I.pause + ' توقف') + '</button>' +
        '</div>';
    } else {
      actions = '<div class="sact">' +
        (s.share_url ? '<button class="btn" data-act="share" data-id="' + s.id + '" data-url="' + esc(s.share_url) + '">' + I.link + ' صفحهٔ وضعیت</button>' : '') +
        (s.share_url ? '<button class="btn" data-act="copylink" data-id="' + s.id + '" data-url="' + esc(s.share_url) + '">' + I.copy + ' کپی</button>' : '') +
        '</div>';
    }

    return '<article class="site st-' + s.state + (opts.viewer ? ' viewer' : '') + '" data-id="' + s.id + '">' +
      '<span class="stripe"></span>' +
      '<header>' +
      '<div class="ico">' + st.em + '</div>' +
      '<div class="imin">' +
      '<h3>' + esc(s.label) + '</h3>' +
      '<div class="tg">' + esc(s.target) + '<em>' + fmt.typeName(s.type) + '</em>' +
      '<button class="mini" data-act="copytarget" data-text="' + esc(s.target) + '" title="کپی آدرس">' + I.copy + '</button>' +
      '</div>' +
      '</div>' +
      '<span class="sbadge ' + st.cls + '">' + st.label + '</span>' +
      '</header>' +
      (opts.viewer && s.owner ? '<div class="owner-line">' + I.users + ' ' + esc(s.owner) + ' • ' + esc(s.role_label) + '</div>' : '') +
      '<div class="uptabs">' +
      '<div class="upt ' + uCls(u1) + '">' + fmt.countHtml(u1, 2, '٪', uCls(u1)) + '<span>۲۴ ساعت</span></div>' +
      '<div class="upt ' + uCls(u7) + '">' + fmt.countHtml(u7, 2, '٪', uCls(u7)) + '<span>۷ روز</span></div>' +
      '<div class="upt ' + uCls(u30) + '">' + fmt.countHtml(u30, 2, '٪', uCls(u30)) + '<span>۳۰ روز</span></div>' +
      '</div>' +
      (opts.mini ? '' :
        '<div class="spark">' + C.sparkline(s.spark || [], 360, 34) + '</div>' +
        '<div class="barstrip" title="۶۰ چک آخر">' + C.barsHtml(s.bars) + '</div>') +
      '<div class="smeta">' + meta + '</div>' +
      actions +
      '</article>';
  }

  /* ---------- آیتم رخداد ---------- */
  function incItem(i, withSite) {
    var emo = i.kind === 'down' ? '🔴' : '🟠';
    var open = !!i.open;
    return '<div class="inc-item' + (open ? ' open' : '') + '">' +
      '<div class="inc-ico ' + (i.kind === 'down' ? 'down' : 'slow') + '">' + emo + '</div>' +
      '<div class="inc-body">' +
      '<b>' + (withSite ? esc(i.site) + ' <span class="sep">—</span> ' : '') + esc(i.title) + '</b>' +
      '<div class="when">' + esc(i.start) + ' • ' + esc(i.ago) + '</div>' +
      (i.reason ? '<div class="why">' + esc(i.reason) + '</div>' : '') +
      '</div>' +
      '<span class="pill-dur ' + (open ? 'open' : 'done') + '">' + (open ? '● ' : '') + esc(i.dur) + '</span>' +
      '</div>';
  }

  /* ---------- جزئیات سایت ---------- */
  function detail(s) {
    var st = fmt.stMeta(s.state);
    var u1 = s.u1 && s.u1.pct, u7 = s.u7 && s.u7.pct, u30 = s.u30 && s.u30.pct;

    var html = '<div class="detail-hero ' + st.cls + '">' +
      '<span class="shine"></span>' +
      '<div class="dst"><span class="emo">' + st.em + '</span><div class="dtxt">' +
      '<b>' + esc(s.label) + '</b>' +
      '<div class="dmeta">' + esc(s.target) + ' <em>' + fmt.typeName(s.type) + '</em></div>' +
      '</div><span class="sbadge ' + st.cls + '">' + st.label + '</span></div>' +
      '<div class="dquick">' +
      '<div><span>کد</span><b>' + (s.code ? ch(s.code) : '—') + '</b></div>' +
      '<div><span>پاسخ</span><b>' + faMs(s.ms) + '</b></div>' +
      '<div><span>چک‌ها</span><b>' + ch(s.total_checks) + '</b></div>' +
      '</div></div>';

    html += '<div class="card">' +
      '<div class="sec-title"><span class="n" style="background:linear-gradient(140deg,#059669,#34d399)">' + I.target + '</span> آپ‌تایم</div>' +
      '<div class="uptabs">' +
      '<div class="upt ' + uCls(u1) + '">' + fmt.countHtml(u1, 2, '٪', uCls(u1)) + '<span>۲۴ ساعت</span></div>' +
      '<div class="upt ' + uCls(u7) + '">' + fmt.countHtml(u7, 2, '٪', uCls(u7)) + '<span>۷ روز</span></div>' +
      '<div class="upt ' + uCls(u30) + '">' + fmt.countHtml(u30, 2, '٪', uCls(u30)) + '<span>۳۰ روز</span></div>' +
      '</div></div>';

    var r = s.resp || {};
    html += '<div class="perf">' +
      '<div class="pf"><b style="color:#4ade80">' + faMs(r.min || 0) + '</b><span>کمینه</span></div>' +
      '<div class="pf"><b style="color:#93c5fd">' + faMs(r.avg || 0) + '</b><span>میانگین</span></div>' +
      '<div class="pf"><b style="color:#fbbf24">' + faMs(r.p95 || 0) + '</b><span>صدک ۹۵</span></div>' +
      '<div class="pf"><b style="color:#f87171">' + faMs(r.max || 0) + '</b><span>بیشینه</span></div>' +
      '</div>';

    html += '<div class="dchart">' +
      '<div class="sec-title"><span class="n" style="background:linear-gradient(140deg,#7c3aed,#a855f7)">' + I.chart + '</span> آپ‌تایم روزانه — ۳۰ روز اخیر</div>' +
      C.dailyChart(s.daily || []) +
      '<div class="daxis"><span>۳۰ روز قبل</span><span>امروز</span></div></div>';

    var heat = '';
    (s.hourly || []).forEach(function (h) {
      var p = h.p;
      var has = (p !== null && p !== undefined);
      var c = !has ? '' : (p >= 95 ? 'g' : (p >= 80 ? 'm' : 'b'));
      heat += '<i' + (c ? ' class="' + c + '"' : '') + ' title="ساعت ' +
        (h.h < 10 ? '0' : '') + h.h + ':۰۰ — ' + (has ? faPct(p) : 'بدون داده') + '"></i>';
    });
    html += '<div class="dchart">' +
      '<div class="sec-title"><span class="n" style="background:linear-gradient(140deg,#ea580c,#f97316)">' + I.activity + '</span> گرمای ۲۴ ساعت اخیر</div>' +
      '<div class="heat">' + heat + '</div>' +
      '<div class="daxis"><span class="lg g">سالم</span><span class="lg m">کند</span><span class="lg b">قطع</span></div></div>';

    var kv = '';
    kv += row('🕐 آخرین چک', esc(s.ago), true);
    if (s.error) kv += row('⚠️ خطای آخر', esc(s.error), false, 'bad');
    kv += row('📊 کل چک‌ها', ch(s.total_checks));
    kv += row('❌ ناموفق', ch(s.total_fails));
    if ((s.max_ms || 0) > 0) kv += row('🎯 حد کندی', faMs(s.max_ms));
    if (s.keyword) kv += row('🔎 کلیدواژه', esc(s.keyword));
    if (s.share_url) kv += row('🔗 لینک وضعیت', '<button class="linkbtn" data-act="copylink" data-url="' + esc(s.share_url) + '">' + esc(s.share_url) + '</button>');
    html += '<div class="card"><div class="sec-title"><span class="n">' + I.list + '</span> مشخصات</div>' +
      '<div class="kvlist">' + kv + '</div></div>';

    var ssl = s.ssl || {};
    var seal, sealTxt, sealTitle = 'گواهی SSL';
    if (!ssl.checked) { seal = '<div class="ssl-seal">' + I.lock + '</div>'; sealTxt = 'گواهی هنوز بررسی نشده است.'; }
    else if (ssl.error) { seal = '<div class="ssl-seal bad">' + I.alert + '</div>'; sealTxt = 'خطا در بررسی گواهی: ' + esc(ssl.error); }
    else if (ssl.days < 0) { seal = '<div class="ssl-seal bad">' + I.alert + '</div>'; sealTxt = 'گواهی منقضی شده است.'; }
    else if (ssl.days <= 14) { seal = '<div class="ssl-seal warn">' + I.clock + '</div>'; sealTxt = 'تا ' + ch(ssl.days) + ' روز دیگر منقضی می‌شود (' + esc(ssl.expires || '—') + ').'; }
    else { seal = '<div class="ssl-seal ok">' + I.shield + '</div>'; sealTxt = 'معتبر • انقضا: <b>' + esc(ssl.expires || '—') + '</b>'; }

    var ring = (ssl.checked && !ssl.error && ssl.days !== null && ssl.days !== undefined)
      ? C.miniBar(Math.max(0, Math.min(100, (ssl.days / 365) * 100)), ssl.days < 0 ? '#ef4444' : (ssl.days <= 14 ? '#f59e0b' : '#22c55e'))
      : '';

    html += '<div class="card"><div class="ssl-info">' + seal +
      '<div class="ssl-txt"><b>' + sealTitle + '</b><div>' + sealTxt +
      (ssl.issuer ? '<br>صادرکننده: <b class="ltr">' + esc(ssl.issuer) + '</b>' : '') +
      (ssl.ago && ssl.ago !== '—' ? '<br>آخرین بررسی: ' + esc(ssl.ago) : '') +
      '</div>' + ring + '</div></div></div>';

    var inc = s.incidents || [];
    if (inc.length) {
      var inl = '';
      inc.forEach(function (i) { inl += incItem(i, false); });
      html += '<div class="card"><div class="sec-title"><span class="n" style="background:linear-gradient(140deg,#b91c1c,#ef4444)">' +
        I.incidents + '</span> آخرین رخدادها <small class="cnt">' + fa(inc.length) + '</small></div>' + inl + '</div>';
    }

    html += '<div class="sact sticky">' +
      '<button class="btn pri" data-act="dcheck" data-id="' + s.id + '">' + I.refresh + ' چک فوری</button>' +
      '<button class="btn ' + (s.state === 'paused' ? 'go' : 'warn-b') + '" data-act="dtoggle" data-id="' + s.id + '">' +
      (s.state === 'paused' ? I.play + ' ادامهٔ چک' : I.pause + ' توقف چک') + '</button>' +
      '</div>';
    html += '<div class="sact">' +
      (s.share_url ? '<button class="btn" data-act="copylink" data-id="' + s.id + '" data-url="' + esc(s.share_url) + '">' + I.copy + ' کپی لینک</button>' : '') +
      (s.daily && s.daily.length ? '<button class="btn" data-act="csv" data-id="' + s.id + '">' + I.download + ' CSV</button>' : '') +
      '<button class="btn dghost" data-act="ddelete" data-id="' + s.id + '">' + I.trash + ' حذف</button>' +
      '</div>';
    return html;
  }

  function row(k, v, isFa, cls) {
    return '<div class="kv"><span class="k">' + k + '</span><span class="v ' + (isFa ? 'fa ' : '') + (cls || '') + '">' + v + '</span></div>';
  }

  /* ---------- رخدادها ---------- */
  function incidents(d) {
    var items = (d && d.items) || [];
    if (!items.length) {
      return '<div class="empty card">' + I.shield + '<b>رخدادی ثبت نشده</b>' +
        '<span>هر قطعی یا کندیِ غیرمنتظره اینجا ثبت می‌شود.</span></div>';
    }
    var html = '<div class="stat-strip">' +
      '<div class="ss"><b class="bad-t">' + ch(items.filter(function (i) { return i.kind === 'down'; }).length) + '</b><span>قطعی</span></div>' +
      '<div class="ss"><b class="warn-t">' + ch(items.filter(function (i) { return i.kind === 'slow'; }).length) + '</b><span>کندی</span></div>' +
      '<div class="ss"><b class="good-t">' + ch(items.filter(function (i) { return i.open; }).length) + '</b><span>باز</span></div>' +
      '</div>';
    html += '<div class="card flat">';
    items.forEach(function (i) { html += incItem(i, true); });
    return html + '</div>';
  }

  /* ---------- دامنه‌ها ---------- */
  function domains(d) {
    var items = (d && d.items) || [];
    if (!items.length) {
      return '<div class="empty card">' + I.globe + '<b>دامنه‌ای پایش نمی‌شود</b>' +
        '<span>دامنه‌ها را از ربات اضافه کنید تا انقضایشان پیگیری شود.</span></div>';
    }
    var html = '<div class="stat-strip">' +
      '<div class="ss"><b class="good-t">' + ch(items.filter(function (x) { return x.state === 'ok'; }).length) + '</b><span>سالم</span></div>' +
      '<div class="ss"><b class="warn-t">' + ch(items.filter(function (x) { return x.state === 'warn'; }).length) + '</b><span>نزدیک انقضا</span></div>' +
      '<div class="ss"><b class="bad-t">' + ch(items.filter(function (x) { return x.state === 'expired'; }).length) + '</b><span>منقضی</span></div>' +
      '</div>';
    items.forEach(function (x) {
      var ic = { ok: '🟢', warn: '🟠', expired: '🔴', unknown: '⚪️' }[x.state] || '⚪️';
      var pctLeft = x.left_days !== null && x.left_days !== undefined
        ? Math.max(0, Math.min(100, (x.left_days / 365) * 100)) : null;
      html += '<div class="card">' +
        '<div class="dom-top"><div class="dom-ico">' + ic + '</div>' +
        '<div class="dom-nm"><b>' + esc(x.domain) + '</b>' +
        (x.registrar ? '<span>' + esc(x.registrar) + '</span>' : '') + '</div>' +
        (x.state === 'warn' || x.state === 'expired'
          ? '<span class="sbadge ' + (x.state === 'expired' ? 'down' : 'paused') + '">' + esc(x.left) + '</span>' : '') +
        '</div>' +
        (pctLeft !== null ? C.miniBar(pctLeft, x.state === 'expired' ? '#ef4444' : (x.state === 'warn' ? '#f59e0b' : '#22c55e')) : '') +
        '<div class="dom-meta">' +
        '<span>انقضا: <b>' + esc(x.expires) + '</b></span>' +
        (x.left_days !== null && x.left_days !== undefined
          ? '<span>مانده: <b>' + (x.left_days < 0 ? 'منقضی' : ch(x.left_days) + ' روز') + '</b></span>' : '') +
        '<span>آخرین بررسی: <b>' + esc(x.ago) + '</b></span>' +
        '</div>' +
        (x.error ? '<div class="dom-err">' + I.alert + ' ' + esc(x.error) + '</div>' : '') +
        '</div>';
    });
    return html + '<div class="foot-note">' + I.info + ' ' + fa(items.length) + ' دامنه زیر نظر است — مدیریت از ربات</div>';
  }

  /* ---------- رنکینگ ---------- */
  function rank(d) {
    d = d || {};
    var me = d.me || { points: 0, level: 1, to_next: 0, position: 0, per_day: 0, per_hour: 0 };
    var top = d.top || [];
    var need = me.to_next || 0;
    var lvPct = (need > 0 && (me.points + need) > 0)
      ? Math.round(me.points / (me.points + need) * 100)
      : 100;
    lvPct = Math.max(4, Math.min(100, lvPct));

    var html = '<div class="myrank">' +
      '<div class="pos">#' + fa(me.position) + '</div>' +
      '<div class="info">' +
      '<b>' + ch(me.points) + ' امتیاز</b>' +
      '<span>سطح ' + ch(me.level) + (need > 0 ? ' • ' + ch(need) + ' امتیاز تا سطح بعد' : ' • بالاترین سطح 🏆') + '</span>' +
      '<div class="lvbar"><i style="width:' + lvPct + '%"></i><span class="lvspark"></span></div>' +
      '<div class="lvcap"><span>⚡ ' + ch(me.per_day) + ' امتیاز در روز</span><span>⏱ ' + ch(me.per_hour) + ' امتیاز/ساعت آپ‌تایم</span></div>' +
      '</div></div>';

    html += '<div class="stat-strip">' +
      '<div class="ss">' + ch(me.position) + '<span>رتبهٔ من</span></div>' +
      '<div class="ss"><b class="accent">' + ch(me.level) + '</b><span>سطح</span></div>' +
      '<div class="ss"><b class="good-t">' + ch(me.uptime !== undefined ? me.uptime : 0) + '</b><span>ساعت آپ‌تایم</span></div>' +
      '</div>';

    if (!top.length) {
      return html + '<div class="empty card">' + I.star + '<b>هنوز رتبه‌ای ثبت نشده</b><span>با فعال‌کردن مانیتورها وارد جدول شو.</span></div>';
    }

    html += '<div class="card flat"><div class="sec-title"><span class="n" style="background:linear-gradient(140deg,#d97706,#fbbf24)">' +
      I.star + '</span> بیست نفر برتر</div>';
    top.forEach(function (rr) {
      html += '<div class="lrow' + (rr.me ? ' me' : '') + '">' +
        '<span class="lrank' + (rr.rank <= 3 ? ' m' + rr.rank : '') + '">' + (rr.medal || fa(rr.rank)) + '</span>' +
        '<span class="lname' + (rr.me ? ' me' : '') + '">' + esc(rr.name) + (rr.me ? ' <em>(شما)</em>' : '') + '</span>' +
        '<span class="lvl-tag">سطح ' + fa(rr.level) + '</span>' +
        '<span class="lpts">' + ch(rr.points) + '</span>' +
        '</div>';
    });
    return html + '</div>';
  }

  /* ---------- گزارش ---------- */
  function reportRow(s) {
    var st = fmt.stMeta(s.state);
    var u1 = s.u1 && s.u1.pct, u30 = s.u30 && s.u30.pct;
    return '<div class="rep-row">' +
      '<span class="rep-dot">' + st.em + '</span>' +
      '<div class="rep-name" title="' + esc(s.target) + '">' + esc(s.label) + '</div>' +
      '<div class="rep-cell">' + fmt.countHtml(u1, 2, '٪', uCls(u1)) + '<span>۲۴س</span></div>' +
      '<div class="rep-cell">' + fmt.countHtml(u30, 2, '٪', uCls(u30)) + '<span>۳۰روز</span></div>' +
      '<div class="rep-cell w"><span class="num ms">' + faMs(s.ms || 0) + '</span><span>پاسخ</span></div>' +
      '</div>';
  }

  function report(r) {
    r = r || {};
    var sum = r.summary || { total: 0, up: 0, down: 0, slow: 0, paused: 0, avg: 0 };
    var u = r.user || {}, inc = r.incidents || {};
    var avgColor = C.pctColor(sum.avg);

    var html =
      '<div class="rep-hero">' +
      '<div class="rep-hd">' +
      '<div class="ringwrap">' + C.ringChart(sum.avg, avgColor) +
      '<div class="rtxt">' + fmt.countHtml(sum.avg, 2, '٪') + '<span>میانگین ۲۴س</span></div></div>' +
      '<div class="rep-hinfo">' +
      '<div class="rh t">' + ch(sum.total) + '<span>کل</span></div>' +
      '<div class="rh up">' + ch(sum.up) + '<span>فعال</span></div>' +
      '<div class="rh down">' + ch(sum.down) + '<span>قطع</span></div>' +
      '<div class="rh slow">' + ch(sum.slow) + '<span>کند</span></div>' +
      '<div class="rh paused">' + ch(sum.paused) + '<span>متوقف</span></div>' +
      '</div></div>' +
      '<button class="btn pri rep-checkall" data-act="checkall">' + I.bolt + ' چک فوری همهٔ سایت‌ها</button>' +
      '</div>';

    html += '<div class="card">' +
      '<div class="sec-title"><span class="n" style="background:linear-gradient(140deg,#b91c1c,#ef4444)">' + I.incidents + '</span> رخدادها — از ابتدا</div>' +
      '<div class="rep-stats">' +
      '<div class="rs">' + ch(inc.count) + '<span>تعداد</span></div>' +
      '<div class="rs"><b class="bad-t">' + esc(inc.down || '—') + '</b><span>مجموع قطعی</span></div>' +
      '<div class="rs"><b class="slow-t">' + esc(inc.slow || '—') + '</b><span>مجموع کندی</span></div>' +
      '</div></div>';

    html += '<div class="card">' +
      '<div class="sec-title"><span class="n" style="background:linear-gradient(140deg,#f59e0b,#fbbf24)">' + I.timer + '</span> توقف‌های شما</div>' +
      '<div class="rep-stats">' +
      '<div class="rs">' + ch(u.pause_events) + '<span>بار توقف</span></div>' +
      '<div class="rs"><b class="warn-t">' + esc(u.paused_total || '—') + '</b><span>مجموع مدت</span></div>' +
      '<div class="rs"><b>' + (u.notify ? '🔔' : '🔕') + '</b><span>' + (u.notify ? 'اعلان روشن' : 'اعلان خاموش') + '</span></div>' +
      '</div></div>';

    var sites = r.sites || [];
    if (!sites.length) {
      return html + '<div class="empty card">' + I.report + '<b>هنوز سایتی ثبت نکرده‌اید</b>' +
        '<span>از دکمهٔ ➕ در صفحهٔ خانه اولین سایت را اضافه کنید.</span></div>';
    }

    var sorted = sites.slice().sort(function (a, b) {
      var pa = (a.u1 && a.u1.pct), pb = (b.u1 && b.u1.pct);
      if (pa === null || pa === undefined) pa = -1;
      if (pb === null || pb === undefined) pb = -1;
      return pb - pa;
    });

    html += '<div class="sec-title"><span class="n">' + I.list + '</span> مقایسهٔ سایت‌ها' +
      '<small class="cnt">' + fa(sorted.length) + ' سایت</small></div>' +
      '<div class="card flat rep-table">' +
      '<div class="rep-head"><span></span><span>سایت</span><span>۲۴ ساعت</span><span>۳۰ روز</span><span>پاسخ</span></div>';
    sorted.forEach(function (s) { html += reportRow(s); });
    return html + '</div>';
  }

  /* ---------- مانیتورهای مشترک ---------- */
  function shared(items) {
    if (!items || !items.length) return '';
    var html = '<div class="sec-title"><span class="n" style="background:linear-gradient(140deg,#7c3aed,#a855f7)">' +
      I.users + '</span> مانیتورهای مشترک با شما <small class="cnt">' + fa(items.length) + '</small></div>';
    items.forEach(function (s) { html += siteCard(s, { viewer: true, mini: true }); });
    return html;
  }

  /* ---------- تنظیمات ---------- */
  function swHtml(key, on, label, sub, icon) {
    return '<div class="set-row">' +
      '<span class="set-ico">' + (icon || I.bell) + '</span>' +
      '<div class="set-txt"><b>' + label + '</b>' + (sub ? '<span>' + sub + '</span>' : '') + '</div>' +
      '<button class="sw' + (on ? ' on' : '') + '" data-set="' + key + '" role="switch" aria-checked="' + (on ? 'true' : 'false') +
      '" aria-label="' + esc(label) + '"><i></i></button>' +
      '</div>';
  }

  function settings(s) {
    s = s || {};
    var u = s.user || {};
    var isVip = u.plan === 'vip';

    var plan;
    if (isVip) {
      plan = '<div class="plan plan-vip">' +
        '<div class="plan-ico">💎</div>' +
        '<div class="plan-txt"><b>اشتراک ویژه</b><span>اعتبار تا ' + esc(u.plan_until || '—') + '</span></div>' +
        '<span class="tag vip">فعال</span></div>';
    } else {
      plan = '<div class="plan">' +
        '<div class="plan-ico">🆓</div>' +
        '<div class="plan-txt"><b>حساب رایگان</b>' +
        '<span>' + ch(u.sites_used) + ' از ' + ch(u.max_sites) + ' سایت استفاده شده</span></div>' +
        (s.bot_username && NS.tg.hasTelegram
          ? '<button class="btn pri sm" data-act="openbot">' + I.bolt + ' ارتقا</button>'
          : '<span class="tag">رایگان</span>') + '</div>';
    }

    var html = plan;

    html += swHtml('notify', s.notify,
      'اعلان قطعی و برقراری',
      s.notify ? 'روشن — در تلگرام اعلان دریافت می‌کنید' : 'خاموش — بدون اعلان اینترنتی',
      s.notify ? I.bell : I.bellOff);

    html += swHtml('pause', s.paused,
      'توقف کل چک‌های من',
      s.paused
        ? 'از ' + esc(s.paused_at || 'همین حالا') + ' متوقف است • مجموع: ' + esc(s.paused_total)
        : 'همهٔ سایت‌ها بدون وقفه پایش می‌شوند',
      s.paused ? I.pause : I.play);

    html += '<div class="sec-title mini-t"><span class="n">' + I.info + '</span> اطلاعات</div>';

    html += '<div class="set-row"><span class="set-ico">' + I.shield + '</span>' +
      '<div class="set-txt"><b>وضعیت حساب</b><span>دسترسی: ' +
      (u.allowed ? '<b class="good-t">فعال</b>' : '<b class="bad-t">غیرفعال</b>') +
      ' • حالت ربات: ' + esc(s.access_label || '—') + '</span></div></div>';

    html += '<div class="set-row"><span class="set-ico">' + I.calendar + '</span>' +
      '<div class="set-txt"><b>سقف سایت‌ها</b><span>' + ch(u.sites_used) + ' از ' + ch(u.max_sites) +
      ' • فاصلهٔ چک: هر ' + ch(s.check_interval || 20) + ' ثانیه</span></div></div>';

    var eng = s.engine || {};
    var engTxt = eng.paused
      ? '⏸ چک‌ها سراسری متوقف است'
      : (eng.cron_healthy ? '🟢 موتور فعال — هر ' + fa(eng.interval || 20) + ' ثانیه' : '🟡 در انتظار کرون');
    html += '<div class="set-row"><span class="set-ico">' + I.server + '</span>' +
      '<div class="set-txt"><b>موتور چک</b><span>' + engTxt + '</span></div></div>';

    if (s.status_url) {
      html += '<div class="set-row"><span class="set-ico">' + I.globe + '</span>' +
        '<div class="set-txt"><b>صفحهٔ وضعیت عمومی</b><span class="url" title="' + esc(s.status_url) + '">' + esc(s.status_url) + '</span></div>' +
        '<button class="btn sm" data-act="copyshare">' + I.copy + ' کپی</button></div>';
    }

    if (s.bot_username) {
      html += '<div class="set-row"><span class="set-ico">' + I.users + '</span>' +
        '<div class="set-txt"><b>ربات</b><span>@' + esc(s.bot_username) + '</span></div>' +
        '<button class="btn sm" data-act="openbot">باز کردن</button></div>';
    }

    html += '<div class="about">' + I.sparkle + ' آپ‌تایم مانیتورینگ • نسخهٔ ۲' +
      '<br><span>تمام داده‌ها مستقیم از ربات اینسرور می‌شود.</span></div>';
    return html;
  }

  NS.views = {
    skel: skel, hero: hero, siteCard: siteCard,
    detail: detail, incItem: incItem,
    incidents: incidents, domains: domains,
    rank: rank, report: report, shared: shared, settings: settings
  };
})(window.NS = window.NS || {});