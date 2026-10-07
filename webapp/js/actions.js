/* ============================================================
   اکشن‌ها — چک/توقف/حذف/افزودن/تنظیمات/CSV/کپی و…
   وابسته به NS.app (هسته) که در app.js تعریف می‌شود.
   ============================================================ */
(function (NS) {
  'use strict';

  var A = NS.api, fmt = NS.fmt, tg = NS.tg;
  var fa = fmt.fa, faMs = fmt.faMs, esc = fmt.esc;

  /* ---------- اسپینر روی دکمه ---------- */
  function busy(btnEl, on) {
    if (!btnEl) return;
    if (on) {
      if (btnEl._busy) return;
      btnEl._busy = true;
      btnEl._html = btnEl.innerHTML;
      btnEl.classList.add('loading');
      btnEl.disabled = true;
      btnEl.insertAdjacentHTML('afterbegin', '<span class="spin"></span>');
    } else if (btnEl._busy) {
      btnEl._busy = false;
      btnEl.disabled = false;
      btnEl.classList.remove('loading');
      btnEl.innerHTML = btnEl._html;
      btnEl._html = null;
    }
  }

  function ok(r) { return !!(r && r.json && r.json.ok); }
  function data(r) { return (r && r.json && r.json.data) || null; }
  function app() { return NS.app; }

  /** خطای شبکه/تایم‌اوت را با دقت بیشتری نمایش می‌دهد */
  function fail(r, fallbackMsg) {
    var a = app();
    var kind = (r && r.json && r.json.error) || '';
    var msg = A.err(kind);
    if (kind === 'timeout' || kind === 'network') {
      msg = '📡 ارتباط برقرار نشد — اینترنت خود را بررسی کنید.';
    } else if (!kind && fallbackMsg) {
      msg = fallbackMsg;
    }
    a.toast(msg, 'err');
    tg.haptic('error');
  }

  /* ============================================================
     چک فوری
     ============================================================ */
  function doCheck(id, btnEl) {
    var a = app();
    if (a.state.busy) return;
    a.state.busy = true;
    busy(btnEl, true);

    A.call('check', { id: id }, 'POST').then(function (r) {
      a.state.busy = false;
      busy(btnEl, false);
      if (!ok(r)) { fail(r, 'چک ناموفق بود.'); return; }

      var j = data(r);
      if (j && j.up) {
        a.toast('سایت بالاست — ' + faMs(j.ms), 'ok');
        tg.haptic('success');
      } else {
        a.toast('سایت در دسترس نیست' + (j && j.error ? ' — ' + j.error : ''), 'err');
        tg.haptic('error');
      }
      A.bust('overview');
      if (a.state.view === 'detail' && j && j.detail) {
        a.state.site = j.detail;
        a.render();
        a.countUp();
      } else {
        a.refreshView();
      }
    }).catch(function () {
      a.state.busy = false;
      busy(btnEl, false);
      fail(null, 'خطا در ارتباط با سرور.');
    });
  }

  /* ============================================================
     توقف / ادامهٔ یک سایت
     ============================================================ */
  function doToggle(id, btnEl) {
    var a = app();
    if (a.state.busy) return;
    a.state.busy = true;
    busy(btnEl, true);

    var isPaused = a.findSite(id) ? a.findSite(id).state === 'paused' : false;
    A.call(isPaused ? 'resume' : 'pause', { id: id }, 'POST').then(function (r) {
      a.state.busy = false;
      busy(btnEl, false);
      if (!ok(r)) { fail(r, 'تغییر وضعیت ناموفق بود.'); return; }
      a.toast(isPaused ? 'چک‌ها ادامه یافت' : 'چک متوقف شد', isPaused ? 'ok' : 'warn');
      tg.haptic('success');
      A.bust('overview');
      a.refreshView();
    }).catch(function () {
      a.state.busy = false;
      busy(btnEl, false);
      fail(null, 'خطا در ارتباط با سرور.');
    });
  }

  /* ============================================================
     چک همه (گزارش) — با محدودیت همزمانی
     ============================================================ */
  function checkAll(btnEl) {
    var a = app();
    if (a.state.busy || a.state.checkingAll) return;

    var all = ((a.state.report && a.state.report.sites) || []);
    var sites = all.filter(function (s) { return s.state !== 'paused'; });
    if (!sites.length) {
      a.toast('هیچ سایت فعالی برای چک نیست.', 'warn');
      tg.haptic('warning');
      return;
    }

    a.state.checkingAll = true;
    busy(btnEl, true);
    a.setProgress(true, 'در حال چک ' + fa(sites.length) + ' سایت…');

    var ups = 0, failed = 0, idx = 0;
    var LIMIT = 3;                              // حداکثر ۳ درخواست همزمان
    var total = sites.length;

    function next() {
      if (idx >= total) return Promise.resolve();
      var s = sites[idx++];
      return A.call('check', { id: s.id }, 'POST').then(function (r) {
        if (ok(r) && data(r) && data(r).up) ups++; else failed++;
      }).catch(function () { failed++; })
        .then(next);
    }

    var workers = [];
    for (var w = 0; w < Math.min(LIMIT, total); w++) workers.push(next());

    Promise.all(workers).then(function () {
      a.state.checkingAll = false;
      busy(btnEl, false);
      a.setProgress(false);
      tg.haptic(failed > 0 ? 'warning' : 'success');

      var msg = 'چک کامل شد — ' + fa(ups) + ' از ' + fa(total) + ' سالم';
      a.toast(msg, failed > 0 ? 'warn' : 'ok');

      A.bust('overview');
      a.refreshView();
    });
  }

  /* ============================================================
     افزودن سایت
     ============================================================ */
  function submitAdd() {
    var a = app(), el = a.els;
    var t = el.addTarget.value.trim();
    if (!t) {
      a.toast('آدرس را وارد کنید.', 'warn');
      tg.haptic('warning');
      el.addTarget.focus();
      return;
    }

    busy(el.addSubmit, true);
    A.call('add', { target: t }, 'POST').then(function (r) {
      busy(el.addSubmit, false);
      if (!ok(r)) { fail(r, 'ثبت سایت ناموفق بود.'); return; }

      var j = data(r) || {};
      a.closeAdd();
      tg.haptic('success');

      if (j.state === 'up') a.toast('سایت ثبت شد و چک اول موفق بود — ' + faMs(j.ms), 'ok');
      else if (j.state === 'down') a.toast('سایت ثبت شد ولی پاسخ نداد' + (j.error ? ' — ' + j.error : ''), 'warn');
      else a.toast('سایت با موفقیت ثبت شد', 'ok');

      A.bust('overview');
      a.loadOverview();
      if (j.site_id) setTimeout(function () { a.openSite(j.site_id); }, 220);
    }).catch(function () {
      busy(el.addSubmit, false);
      fail(null, 'خطا در ارتباط با سرور.');
    });
  }

  /* ============================================================
     حذف
     ============================================================ */
  function confirmDel() {
    var a = app();
    var id = a.state.pendingDel;
    a.closeDel();
    busy(a.els.delOk, true);

    A.call('delete', { id: id }, 'POST').then(function (r) {
      busy(a.els.delOk, false);
      if (ok(r)) {
        a.toast('مانیتور حذف شد.', 'ok');
        tg.haptic('success');
      } else {
        fail(r, 'حذف ناموفق بود.');
      }
      A.bust('overview');
      if (a.state.view === 'detail') a.backToDash();
      else a.refreshView();
    }).catch(function () {
      busy(a.els.delOk, false);
      fail(null, 'خطا در ارتباط با سرور.');
    });
  }

  /* ============================================================
     کپی در حافظه (با fallback برای مرورگرهای قدیمی)
     ============================================================ */
  function copyText(text, okMsg) {
    var a = app();
    text = String(text || '');
    if (!text) { a.toast('موردی برای کپی نیست.', 'warn'); return; }

    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
      document.body.appendChild(ta);
      ta.select();
      ta.setSelectionRange(0, ta.value.length);
      var done = false;
      try { done = document.execCommand('copy'); } catch (e) { done = false; }
      document.body.removeChild(ta);
      if (done) { a.toast(okMsg || 'کپی شد.', 'ok'); tg.haptic('success'); }
      else { a.toast('کپی ناموفق بود — دستی انتخاب کنید.', 'err'); tg.haptic('error'); }
    }

    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () {
        a.toast(okMsg || 'کپی شد.', 'ok');
        tg.haptic('success');
      }).catch(fallback);
    } else fallback();
  }

  /* ============================================================
     تنظیمات (notify / pause)
     ============================================================ */
  function toggleSetting(key, btnEl) {
    var a = app();
    if (a.state.setBusy) return;
    a.state.setBusy = true;

    var on = btnEl.classList.contains('on');
    var val = on ? '0' : '1';

    // بازخورد خوشبینانه: سوییچ بلافاصله تغییر می‌کند
    btnEl.classList.toggle('on', !on);
    btnEl.setAttribute('aria-checked', !on ? 'true' : 'false');
    btnEl.classList.add('pending');

    A.call('settings', { key: key, value: val }, 'POST').then(function (r) {
      a.state.setBusy = false;
      if (!ok(r)) {
        // بازگرداندن حالت قبلی در صورت خطا
        if (btnEl && btnEl.isConnected) {
          btnEl.classList.toggle('on', on);
          btnEl.setAttribute('aria-checked', on ? 'true' : 'false');
          btnEl.classList.remove('pending');
        }
        fail(r, 'ذخیرهٔ تنظیمات ناموفق بود.');
        return;
      }
      if (btnEl && btnEl.isConnected) btnEl.classList.remove('pending');

      var j = data(r) || {};
      tg.haptic('success');

      if (key === 'notify') {
        a.toast(j.notify ? '🔔 اعلان‌ها روشن شد' : '🔕 اعلان‌ها خاموش شد', j.notify ? 'ok' : 'warn');
      } else {
        a.toast(j.paused ? '⏸ تمام چک‌های شما متوقف شد' : '▶️ چک‌ها ادامه یافت', j.paused ? 'warn' : 'ok');
      }

      // به‌روزرسانی خوشبینانهٔ حافظهٔ محلی و رندر فوری شیت
      var cur = a.state.settings || a.state.data.settings;
      if (cur) {
        if (key === 'notify') { cur.notify = !!j.notify; }
        else { cur.paused = !!j.paused; cur.paused_at = j.paused ? 'همین حالا' : null; }
        a.state.settings = cur;
        a.state.data.settings = cur;
      }
      a.renderSettingsSheet();

      A.bust('overview');
      if (a.state.view === 'dash' || a.state.view === 'report') a.refreshView();
    }).catch(function () {
      a.state.setBusy = false;
      if (btnEl) btnEl.classList.remove('pending');
      fail(null, 'خطا در ارتباط با سرور.');
    });
  }

  /* ============================================================
     خروجی CSV (روزانه + ساعتی)
     ============================================================ */
  function csvCell(v) {
    var s = (v === null || v === undefined) ? '' : String(v);
    return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }

  function exportCsv(site) {
    if (!site) return;
    var lines = [];
    var head = ['تاریخ', 'آپ‌تایم ٪', 'تعداد چک', 'میانگین ms'];
    lines.push(head.join(','));
    (site.daily || []).forEach(function (d) {
      lines.push([d.d, d.p === null || d.p === undefined ? '' : d.p, d.c, d.m].map(csvCell).join(','));
    });

    if (site.hourly && site.hourly.length) {
      lines.push('');
      lines.push(['ساعت', 'آپ‌تایم ٪', 'تعداد چک', 'میانگین ms'].join(','));
      site.hourly.forEach(function (h) {
        lines.push([String(h.h).padStart(2, '0') + ':00',
          h.p === null || h.p === undefined ? '' : h.p, h.c, h.m].map(csvCell).join(','));
      });
    }

    var title = ['# ' + (site.label || 'site'), '# ' + (site.target || ''), ''];
    var csv = '\uFEFF' + title.concat(lines).join('\r\n');

    var slug = String(site.label || site.target || 'site')
      .replace(/[^\w\u0600-\u06FF-]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40) || 'site';

    var stamp = new Date();
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var fname = 'uptime-' + slug + '-' + stamp.getFullYear() + pad(stamp.getMonth() + 1) + pad(stamp.getDate()) + '.csv';

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = fname;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    setTimeout(function () { document.body.removeChild(link); URL.revokeObjectURL(url); }, 800);

    app().toast('📊 فایل CSV آماده شد.', 'ok');
    tg.haptic('success');
  }

  /* ============================================================
     اشتراک‌گذاری لینک وضعیت
     ============================================================ */
  function shareLink(url) {
    var a = app();
    if (!url) return;
    var shared = tg.share(url, 'وضعیت زندهٔ سایت‌های من 👇');
    if (!shared) copyText(url, '🔗 لینک کپی شد.');
    else tg.haptic('success');
  }

  /* ---------- مرتب‌سازی لیست داشبورد ---------- */
  var SORTS = {
    name:    { label: 'نام', cmp: function (a, b) { return (a.label || '').localeCompare(b.label || '', 'fa'); } },
    uptime:  { label: 'آپ‌تایم', cmp: function (a, b) {
      var pa = a.u1 && a.u1.pct, pb = b.u1 && b.u1.pct;
      if (pa === null || pa === undefined) pa = -1;
      if (pb === null || pb === undefined) pb = -1;
      return pb - pa;
    } },
    ms:      { label: 'سرعت', cmp: function (a, b) { return (a.ms || 0) - (b.ms || 0); } },
    down:    { label: 'خرابی', cmp: function (a, b) { return (b.total_fails || 0) - (a.total_fails || 0); } }
  };

  function sortSites(list, key) {
    var f = SORTS[key] || SORTS.uptime;
    return list.slice().sort(f.cmp);
  }

  NS.actions = {
    busy: busy,
    doCheck: doCheck,
    doToggle: doToggle,
    checkAll: checkAll,
    submitAdd: submitAdd,
    confirmDel: confirmDel,
    copyText: copyText,
    toggleSetting: toggleSetting,
    exportCsv: exportCsv,
    shareLink: shareLink,
    sortSites: sortSites,
    SORTS: SORTS
  };
})(window.NS = window.NS || {});